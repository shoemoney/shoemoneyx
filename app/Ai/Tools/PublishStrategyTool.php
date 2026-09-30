<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\StrategyPlugin;

final class PublishStrategyTool implements Tool
{
    public function name(): string
    {
        return 'publish_strategy';
    }

    public function description(): string
    {
        return 'Request publishing a saved plugin version to the community hub archive. Never publishes by itself: returns needs_confirmation and the user must confirm in the UI.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plugin_id' => ['type' => 'integer', 'description' => 'Defaults to the plugin just saved this turn.'],
                'version' => ['type' => 'string', 'description' => 'Defaults to the plugin\'s current version.'],
                'changelog' => ['type' => 'string', 'description' => 'Optional changelog for this version.'],
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
            return ['error' => 'no such plugin'];
        }

        $version = $args['version'] ?? $plugin->current_version;

        // Publishing makes a private strategy public; only the user's own click may do that.
        return [
            'needs_confirmation' => true,
            'action' => 'publish',
            'plugin_id' => $plugin->id,
            'version' => $version,
            'endpoint' => "POST /api/strategy-plugins/{$plugin->id}/publish",
            'message' => "Not published. Ask the user to press the Publish button (call suggest_share to show it) to publish v{$version}.",
        ];
    }
}
