<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\StrategyPlugin;

final class ListVersionsTool implements Tool
{
    public function name(): string
    {
        return 'list_versions';
    }

    public function description(): string
    {
        return 'List every saved version of a strategy plugin, newest first.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_id' => ['type' => 'integer'],
            ],
            'required' => ['plugin_id'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $plugin = StrategyPlugin::find($args['plugin_id'] ?? null);
        if (! $plugin) {
            return ['error' => 'no such plugin'];
        }

        $versions = $plugin->versions()->orderByDesc('id')->get(['id', 'version', 'changelog', 'created_by', 'created_at']);

        return ['versions' => $versions->toArray()];
    }
}
