<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\StrategyPluginVersion;
use App\Support\JsonDiff;

/** Mirrors StrategyPluginVersionController::diff() but addresses versions by plugin_id + version string. */
final class CompareVersionsTool implements Tool
{
    public function name(): string
    {
        return 'compare_versions';
    }

    public function description(): string
    {
        return 'Structurally diff two saved versions of the same strategy plugin.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_id' => ['type' => 'integer'],
                'from' => ['type' => 'string', 'description' => 'Version string, e.g. "1.0.0".'],
                'to' => ['type' => 'string', 'description' => 'Version string, e.g. "1.1.0".'],
            ],
            'required' => ['plugin_id', 'from', 'to'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $pluginId = $args['plugin_id'] ?? null;
        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;

        $va = StrategyPluginVersion::where('strategy_plugin_id', $pluginId)->where('version', $from)->first();
        $vb = StrategyPluginVersion::where('strategy_plugin_id', $pluginId)->where('version', $to)->first();

        if (! $va || ! $vb) {
            return ['error' => 'one or both versions not found'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'diff' => JsonDiff::diff($va->definition, $vb->definition),
        ];
    }
}
