<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;

/**
 * A backtest that runs a JSON plugin must never read the mutable plugin row
 * at simulate time — it pins the exact version at creation, so editing the
 * plugin afterward can't change what an already-queued or completed backtest
 * ran. Shared by StrategyPluginController::backtest() (plugin-specific
 * trigger) and BacktestController::store() (the generic endpoint, when a
 * caller posts `json.plugin_key` directly).
 */
final class BacktestVersionPin
{
    /**
     * @param  array<string, mixed>  $params  normalized, dotted backtest override params
     * @return array{0: int|null, 1: array<string, mixed>} [pinned version id (or null), params with json.plugin_version_id set when pinned]
     */
    public static function resolve(string $strategy, array $params): array
    {
        if ($strategy !== 'json') {
            return [null, $params];
        }

        $key = $params['json.plugin_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return [null, $params];
        }

        $plugin = StrategyPlugin::where('key', $key)->first();
        if ($plugin === null || $plugin->current_version === null) {
            return [null, $params];
        }

        $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
            ->where('version', $plugin->current_version)
            ->first();
        if ($version === null) {
            return [null, $params];
        }

        $params['json.plugin_version_id'] = $version->id;

        return [$version->id, $params];
    }
}
