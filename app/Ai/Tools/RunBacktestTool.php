<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Desk\Strategies\BacktestVersionPin;
use App\Http\Controllers\Api\BacktestController;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;

/** Mirrors StrategyPluginController::backtest(), but runs the job synchronously in-process. */
final class RunBacktestTool implements Tool
{
    public function name(): string
    {
        return 'run_backtest';
    }

    public function description(): string
    {
        return 'Run a backtest for a saved strategy plugin (optionally pinned to a specific version) and return the result.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_id' => ['type' => 'integer', 'description' => 'Strategy plugin id (defaults to the plugin just saved this turn).'],
                'version' => ['type' => 'string', 'description' => 'Exact version string (e.g. "1.0.0") to pin, default is the current version.'],
                'days' => ['type' => 'integer', 'description' => 'Lookback window in days.'],
                'cash' => ['type' => 'number', 'description' => 'Starting cash.'],
                'products' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Product ids to test, e.g. ["BTC-USD"].'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $pluginId = $args['plugin_id'] ?? $context->pluginId;
        if (! $pluginId) {
            return ['error' => 'no plugin_id given and no plugin saved yet this conversation'];
        }

        $plugin = StrategyPlugin::find($pluginId);
        if (! $plugin) {
            return ['error' => "no such plugin: {$pluginId}"];
        }

        $suggest = $plugin->definition['suggest'] ?? [];
        $products = $args['products'] ?? $suggest['products'] ?? Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = now()->startOfHour();
        $from = $to->copy()->subDays($args['days'] ?? $suggest['days'] ?? 30);

        $rawParams = isset($plugin->definition['schema_version']) ? [] : ($plugin->definition['params'] ?? []);
        $overrides = array_replace_recursive(
            $rawParams,
            ['json' => ['plugin_key' => $plugin->key]],
        );
        $normalized = BacktestController::normalizeOverrides($overrides);

        if (isset($args['version'])) {
            $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
                ->where('version', $args['version'])
                ->first();
            if (! $version) {
                return ['error' => "no such version: {$args['version']}"];
            }
            $versionId = $version->id;
            $normalized['json.plugin_version_id'] = $versionId;
        } else {
            [$versionId, $normalized] = BacktestVersionPin::resolve('json', $normalized);
        }

        $bt = Backtest::create([
            'strategy' => 'json',
            'strategy_plugin_version_id' => $versionId,
            'products' => array_map('strtoupper', $products),
            'from' => $from,
            'to' => $to,
            'starting_cash' => $args['cash'] ?? $suggest['cash'] ?? 1000,
            'params' => $normalized,
            'status' => 'queued',
        ]);

        RunBacktest::dispatchSync($bt->id);
        $bt->refresh();

        return [
            'id' => $bt->id,
            'status' => $bt->status,
            'error' => $bt->error,
            'stats' => $bt->stats,
            'ending_equity' => $bt->ending_equity,
        ];
    }
}
