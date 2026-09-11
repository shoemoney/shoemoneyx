<?php

declare(strict_types=1);

namespace App\Hub;

use App\Models\Backtest;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;

/**
 * Single place the "first publish creates the strategy, later publishes push a
 * version" logic lives. Both StrategyPluginController::publish() and
 * PublishStrategyTool call this instead of duplicating it.
 */
final class Publisher
{
    public function __construct(private readonly HubClient $client) {}

    public function publish(StrategyPlugin $plugin, ?string $version = null, ?string $changelog = null, ?int $backtestId = null): array
    {
        if (! $this->client->connected()) {
            throw new HubException('unauthenticated', 'Connect to the hub first.');
        }

        $version ??= $plugin->current_version;

        $versionRow = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
            ->where('version', $version)
            ->first();

        if (! $versionRow) {
            throw new \InvalidArgumentException("no such version: {$version}");
        }

        $definition = $plugin->definition ?? [];
        $meta = $definition['meta'] ?? [];

        $payload = [
            'slug' => $plugin->hub_slug ?? $plugin->key,
            'name' => $meta['name'] ?? $plugin->name,
            'description' => $meta['description'] ?? $plugin->description,
            'tags' => $meta['tags'] ?? [],
            'version' => $version,
            'changelog' => $changelog,
            'definition' => $versionRow->definition,
        ];

        if ($backtestId !== null) {
            $backtest = self::buildBacktestPayload($backtestId, $plugin);
            if ($backtest !== null) {
                $payload['backtest'] = $backtest;
            }
        }

        if ($plugin->hub_slug === null) {
            $response = $this->client->publish($payload);
            $slug = $response['strategy']['slug'] ?? $payload['slug'];
            $plugin->hub_slug = $slug;
            $plugin->save();
        } else {
            $versionPayload = collect($payload)->only(['version', 'changelog', 'definition', 'backtest'])->all();
            $response = $this->client->publishVersion($plugin->hub_slug, $versionPayload);
        }

        $versionRow->published_at = now();
        $versionRow->save();

        return [
            'slug' => $plugin->hub_slug,
            'version' => $version,
            'hub_url' => config('hub.url'),
            'response' => $response,
        ];
    }

    /** Best-effort backtest payload for the hub's optional `backtest` field. Omits what it can't honestly derive. */
    private static function buildBacktestPayload(int $backtestId, StrategyPlugin $plugin): ?array
    {
        $backtest = Backtest::find($backtestId);
        if (! $backtest) {
            return null;
        }

        $stats = $backtest->stats ?? [];
        $out = [];

        if (isset($stats['total_return_pct'])) {
            $out['return_pct'] = $stats['total_return_pct'];
        }
        if (isset($stats['win_rate'])) {
            $out['win_rate'] = $stats['win_rate'];
        }
        if (isset($stats['trades'])) {
            $out['trades'] = $stats['trades'];
        }
        if (isset($stats['max_drawdown_pct'])) {
            $out['max_drawdown_pct'] = $stats['max_drawdown_pct'];
        }
        if (isset($stats['profit_factor'])) {
            $out['profit_factor'] = $stats['profit_factor'];
        }

        if ($backtest->from && $backtest->to) {
            $out['days'] = $backtest->from->diffInDays($backtest->to);
        }

        $exchange = config('exchanges.active');
        if ($exchange) {
            $out['exchange'] = $exchange;
        }

        $timeframe = $plugin->definition['meta']['timeframe'] ?? null;
        if ($timeframe) {
            $out['timeframe'] = $timeframe;
        }

        if ($backtest->products) {
            $out['assets'] = $backtest->products;
        }

        return $out;
    }
}
