<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Ai\Contracts\ChatClient;
use App\Ai\Exceptions\AiGateException;
use App\Ai\Exceptions\AiNoConnectionException;
use App\Desk\Assist\SmxPrompt;
use App\Desk\Strategies\BacktestVersionPin;
use App\Desk\Strategies\JsonPluginValidator;
use App\Desk\Strategies\PluginMarkdownExporter;
use App\Desk\Strategies\PluginPineExporter;
use App\Desk\Strategies\SchemaMigrator;
use App\Desk\Strategies\StrategySchemaValidator;
use App\Hub\HubException;
use App\Hub\Publisher;
use App\Http\Controllers\Controller;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyReview;
use App\Support\SemVer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class StrategyPluginController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => StrategyPlugin::query()->orderByDesc('id')->get(['id', 'key', 'name', 'description', 'updated_at']),
        ]);
    }

    public function show(StrategyPlugin $plugin): JsonResponse
    {
        return response()->json($plugin);
    }

    public function validate(Request $request): JsonResponse
    {
        $data = $request->validate(['definition' => 'required|array']);

        return response()->json(self::runValidator($data['definition']));
    }

    /**
     * Every save is a new immutable version: `bump` (default patch) sets how far
     * the semver moves from the plugin's current_version, `changelog` is free text
     * on that version row. A brand-new plugin always starts at 1.0.0 regardless
     * of `bump`.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'definition' => 'required|array',
            'bump' => 'nullable|string|in:major,minor,patch',
            'changelog' => 'nullable|string|max:2000',
        ]);

        $def = $data['definition'];
        $result = self::runValidator($def);
        if (! $result['valid']) {
            return response()->json($result, 422);
        }

        $name = $def['meta']['name'] ?? $def['name'] ?? null;
        $description = $def['meta']['description'] ?? $def['description'] ?? null;

        $plugin = DB::transaction(function () use ($def, $name, $description, $data, $request) {
            $existing = StrategyPlugin::where('key', $def['key'])->first();
            $nextVersion = $existing?->current_version ? SemVer::bump($existing->current_version, $data['bump'] ?? 'patch') : '1.0.0';

            $plugin = StrategyPlugin::updateOrCreate(
                ['key' => $def['key']],
                ['name' => $name, 'description' => $description, 'definition' => $def, 'current_version' => $nextVersion],
            );

            $plugin->versions()->create([
                'version' => $nextVersion,
                'definition' => $def,
                'changelog' => $data['changelog'] ?? null,
                'created_by' => $request->user()?->email,
            ]);

            return $plugin;
        });

        return response()->json(['valid' => true, 'errors' => [], 'plugin' => $plugin], 201);
    }

    /** @return array{valid: bool, errors: array<int, mixed>} */
    private static function runValidator(array $definition): array
    {
        return isset($definition['schema_version'])
            ? StrategySchemaValidator::validate($definition)
            : JsonPluginValidator::validate($definition);
    }

    /**
     * Live free-model list from OpenRouter's public /models endpoint
     * (pricing per model — free means $0 prompt AND $0 completion).
     * Cached an hour; the builder renders it as the model picker.
     */
    public function models(): JsonResponse
    {
        $models = Cache::remember('openrouter.free_models', 3600, function () {
            $response = Http::timeout(20)->get('https://openrouter.ai/api/v1/models');
            if (! $response->successful()) {
                return null;
            }
            $free = [];
            foreach ($response->json('data', []) as $m) {
                $pricing = $m['pricing'] ?? [];
                if (($pricing['prompt'] ?? null) === '0' && ($pricing['completion'] ?? null) === '0') {
                    $free[] = ['id' => $m['id'], 'name' => $m['name'] ?? $m['id']];
                }
            }
            usort($free, fn ($a, $b) => strcmp($a['id'], $b['id']));

            return $free;
        });

        if ($models === null) {
            return response()->json(['error' => 'OpenRouter unreachable — paste any model id manually.'], 502);
        }

        return response()->json(['data' => $models]);
    }

    /** Download a plugin as .json, .pine (TradingView), or .md (brief). */
    public function export(StrategyPlugin $plugin, string $format): Response|JsonResponse
    {
        $def = $format === 'json' ? $plugin->definition : SchemaMigrator::toLegacyView($plugin->definition);
        if ($format === 'pine') {
            return response(PluginPineExporter::export($def), 200, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => "attachment; filename=\"{$plugin->key}.pine\"",
            ]);
        }
        if ($format === 'md') {
            return response(PluginMarkdownExporter::export($def), 200, [
                'Content-Type' => 'text/markdown',
                'Content-Disposition' => "attachment; filename=\"{$plugin->key}.md\"",
            ]);
        }
        if ($format === 'json') {
            return response(json_encode($def, JSON_PRETTY_PRINT), 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"{$plugin->key}.strategy.json\"",
            ]);
        }

        return response()->json(['error' => 'unknown format (json, pine, md)'], 404);
    }

    /**
     * Queue a backtest that REALLY runs the plugin: strategy=json with the
     * plugin key + its params folded into the overrides. Fills land on the
     * backtest page and as marks on the TradingView chart.
     */
    public function backtest(Request $request, StrategyPlugin $plugin): JsonResponse
    {
        $data = $request->validate([
            'products' => 'nullable|array',
            'products.*' => 'string',
            'days' => 'nullable|integer|min:1|max:365',
            'cash' => 'nullable|numeric|min:1',
        ]);

        $suggest = $plugin->definition['suggest'] ?? [];
        $products = $data['products'] ?? $suggest['products'] ?? Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = now()->startOfHour();
        $from = $to->copy()->subDays($data['days'] ?? $suggest['days'] ?? 30);

        // Legacy 'params' is a flat map of desk override keys (e.g. "scan.max_candidates");
        // the new schema's 'params' is {key: {type, default, ...}} tunable metadata, not
        // overrides — only fold the legacy shape into the desk-config override tree.
        $rawParams = isset($plugin->definition['schema_version']) ? [] : ($plugin->definition['params'] ?? []);
        $overrides = array_replace_recursive(
            $rawParams,
            ['json' => ['plugin_key' => $plugin->key]],
        );
        [$versionId, $normalizedParams] = BacktestVersionPin::resolve('json', BacktestController::normalizeOverrides($overrides));

        $bt = Backtest::create([
            'strategy' => 'json',
            'strategy_plugin_version_id' => $versionId,
            'products' => array_map('strtoupper', $products),
            'from' => $from,
            'to' => $to,
            'starting_cash' => $data['cash'] ?? $suggest['cash'] ?? 1000,
            'params' => $normalizedParams,
            'status' => 'queued',
        ]);
        RunBacktest::dispatch($bt->id);

        return response()->json($bt, 202);
    }

    /**
     * Hand a strategy to the 24/7 worker (or take it back). Omit `enabled` to flip
     * whatever it is now.
     */
    public function toggleAutoBacktest(Request $request, StrategyPlugin $plugin): JsonResponse
    {
        $data = $request->validate(['enabled' => 'nullable|boolean']);
        $plugin->update(['auto_backtest' => $data['enabled'] ?? ! $plugin->auto_backtest]);

        return response()->json(['auto_backtest' => (bool) $plugin->auto_backtest]);
    }

    /** Push a saved plugin version to the community hub archive. */
    public function publish(Request $request, StrategyPlugin $plugin): JsonResponse
    {
        $data = $request->validate([
            'version' => 'nullable|string',
            'changelog' => 'nullable|string|max:2000',
            'include_backtest' => 'nullable|integer',
        ]);

        try {
            $result = app(Publisher::class)->publish(
                $plugin,
                $data['version'] ?? null,
                $data['changelog'] ?? null,
                $data['include_backtest'] ?? null,
            );
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'no such version'], 404);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage()], $e->code === 'unauthenticated' ? 401 : 502);
        }

        return response()->json($result, 201);
    }

    /** Agent verdicts on this plugin's unattended backtests, newest first. */
    public function reviews(StrategyPlugin $plugin): JsonResponse
    {
        return response()->json([
            'data' => StrategyReview::where('plugin_id', $plugin->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /**
     * SMX AI assist. The key comes from the operator's connected OpenRouter
     * account (or the self-host env fallback) through App\Ai\Gate — nothing is
     * pasted in the browser and no message content is ever logged.
     */
    public function assist(Request $request, ChatClient $client): JsonResponse
    {
        set_time_limit(300);

        $data = $request->validate([
            'messages' => 'required|array|min:1|max:50',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|max:8000',
            'model' => 'nullable|string|max:120',
        ]);

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ...array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $data['messages']),
        ];

        try {
            $reply = $client->chat($messages, [], $data['model'] ?? null);
        } catch (AiNoConnectionException) {
            return response()->json(['error' => 'No OpenRouter account connected. Connect one from the builder.'], 401);
        } catch (AiGateException $e) {
            return response()->json(['error' => $e->getMessage()], $e->reason === AiGateException::RATE_LIMITED ? 429 : 403);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'OpenRouter error: '.$e->getMessage()], 502);
        }

        return response()->json([
            'content' => $reply->content ?? '',
            'model' => $reply->model,
        ]);
    }

    /** Full SMX system prompt lives in App\Desk\Assist\SmxPrompt. */
    public static function systemPrompt(): string
    {
        return SmxPrompt::system();
    }
}
