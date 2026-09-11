<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Contracts\ChatClient;
use App\Ai\CurrentUser;
use App\Ai\Exceptions\AiGateException;
use App\Ai\Gate;
use App\Desk\Backtester;
use App\Desk\Strategies\BacktestVersionPin;
use App\Http\Controllers\Api\BacktestController;
use App\Models\Backtest;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use App\Models\StrategyReview;
use App\Services\Market\CandleStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One tick of the 24/7 loop for one strategy: run a real backtest of the plugin's
 * current version, then have the agent read the result and file a verdict.
 */
class AgentBacktestLoop implements ShouldQueue
{
    use Queueable;

    private const VERDICTS = ['keep', 'tweak', 'abandon'];

    private const SYSTEM_PROMPT = <<<'TXT'
    You review crypto trading backtests for a research desk. You are blunt and brief.
    Reply with nothing but one JSON object, exactly this shape:
    {"verdict": "keep" | "tweak" | "abandon", "notes": "at most three sentences", "suggested_param_changes": {}}
    "keep" means the edge held. "tweak" means the shape is right but the parameters are not.
    "abandon" means the results do not support the idea. Put concrete parameter values in
    suggested_param_changes when you say tweak, and leave it empty otherwise.
    TXT;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $pluginId) {}

    public function handle(Backtester $bt, CandleStore $store, ChatClient $client, Gate $gate): void
    {
        $plugin = StrategyPlugin::find($this->pluginId);
        if (! $plugin?->auto_backtest) {
            return;
        }

        $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
            ->where('version', $plugin->current_version)
            ->first();
        if ($version === null) {
            return;
        }

        // One run per interval per version: a tick that lands early just steps aside.
        $since = now()->subMinutes((int) config('ai.loop.interval_minutes', 60));
        if (Backtest::where('strategy_plugin_version_id', $version->id)->where('created_at', '>=', $since)->exists()) {
            return;
        }

        $this->review($plugin, $version, $this->runBacktest($plugin, $bt, $store), $client, $gate);
    }

    /** Built exactly the way StrategyPluginController::backtest() builds it, then run inline. */
    private function runBacktest(StrategyPlugin $plugin, Backtester $bt, CandleStore $store): Backtest
    {
        $suggest = $plugin->definition['suggest'] ?? [];
        $products = $suggest['products'] ?? Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = now()->startOfHour();
        $from = $to->copy()->subDays((int) ($suggest['days'] ?? config('ai.loop.days', 7)));

        $rawParams = isset($plugin->definition['schema_version']) ? [] : ($plugin->definition['params'] ?? []);
        $overrides = array_replace_recursive($rawParams, ['json' => ['plugin_key' => $plugin->key]]);
        [$versionId, $params] = BacktestVersionPin::resolve('json', BacktestController::normalizeOverrides($overrides));

        $row = Backtest::create([
            'strategy' => 'json',
            'strategy_plugin_version_id' => $versionId,
            'products' => array_map('strtoupper', $products),
            'from' => $from,
            'to' => $to,
            'starting_cash' => $suggest['cash'] ?? 1000,
            'params' => $params,
            'status' => 'queued',
        ]);

        foreach ($row->products as $pid) {
            if ($store->fresh($pid, '1H', 300)) {
                continue;
            }
            try {
                $store->sync($pid, '1H', $row->from->getTimestamp() - 50 * 3600);
            } catch (\Throwable) {
                // missing history for one product is not fatal
            }
        }
        $bt->runInto($row);

        return $row->fresh() ?? $row;
    }

    private function review(StrategyPlugin $plugin, StrategyPluginVersion $version, Backtest $row, ChatClient $client, Gate $gate): void
    {
        try {
            $reply = $client->chat([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => self::brief($plugin, $row)],
            ]);
        } catch (AiGateException) {
            return;   // over budget or suspended — this tick is done, the next one retries
        }

        $verdict = self::parseVerdict($reply->content);
        if ($verdict === null) {
            // A model that cannot produce the one shape it was asked for is worth auditing:
            // enough of these and the gate suspends the key on its own.
            $gate->record(CurrentUser::key(), $reply->model, 'error', 'review reply was not usable JSON', 0);
            $verdict = ['verdict' => 'tweak', 'notes' => '', 'suggestions' => []];
        }

        StrategyReview::create([
            'plugin_id' => $plugin->id,
            'version_id' => $version->id,
            'backtest_id' => $row->id,
            'model' => $reply->model,
            'verdict' => $verdict['verdict'],
            'notes' => $verdict['notes'],
            'suggestions' => $verdict['suggestions'],
            'created_at' => now(),
        ]);
    }

    private static function brief(StrategyPlugin $plugin, Backtest $row): string
    {
        $trades = is_array($row->trades) ? $row->trades : [];

        return implode("\n", [
            'Strategy: '.$plugin->key.' v'.$plugin->current_version,
            'Window: '.$row->from?->toDateString().' to '.$row->to?->toDateString(),
            'Products: '.implode(', ', $row->products ?? []),
            'Starting cash: '.$row->starting_cash.'; ending equity: '.($row->ending_equity ?? 'unknown'),
            'Stats: '.json_encode($row->stats ?? []),
            'Trades: '.count($trades).'; first ten: '.json_encode(array_slice($trades, 0, 10)),
        ]);
    }

    /**
     * Models wrap JSON in fences, prose, or nothing at all, and sometimes invent a
     * verdict word. Anything unreadable returns null so the caller can audit it.
     *
     * @return array{verdict: string, notes: string, suggestions: array}|null
     */
    private static function parseVerdict(?string $content): ?array
    {
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m) === 1) {
            $content = $m[1];
        }

        $data = json_decode(trim($content), true);
        if (! is_array($data)) {
            return null;
        }

        return [
            'verdict' => in_array($data['verdict'] ?? null, self::VERDICTS, true) ? $data['verdict'] : 'tweak',
            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : '',
            'suggestions' => is_array($data['suggested_param_changes'] ?? null) ? $data['suggested_param_changes'] : [],
        ];
    }
}
