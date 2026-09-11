<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\StrategyPlugin;

/** Pure UI marker — no writes. Resolves a plugin/version and hands back a share-prompt message. */
final class SuggestShareTool implements Tool
{
    public function name(): string
    {
        return 'suggest_share';
    }

    public function description(): string
    {
        return 'Surface a one-time "publish to the community archive?" suggestion in the UI for a saved plugin version.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_id' => ['type' => 'integer', 'description' => 'Defaults to the plugin just saved this turn.'],
                'version' => ['type' => 'string', 'description' => 'Defaults to the plugin\'s current version.'],
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

        $version = $args['version'] ?? $plugin->current_version;

        return [
            'type' => 'suggest_share',
            'plugin_id' => $plugin->id,
            'version' => $version,
            'message' => "Want to publish v{$version} to the community archive?",
            'action' => 'publish',
        ];
    }
}
