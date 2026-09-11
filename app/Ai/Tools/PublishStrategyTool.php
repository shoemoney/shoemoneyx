<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Hub\HubException;
use App\Hub\Publisher;
use App\Models\StrategyPlugin;

final class PublishStrategyTool implements Tool
{
    public function name(): string
    {
        return 'publish_strategy';
    }

    public function description(): string
    {
        return 'Push a saved plugin version to the community hub archive.';
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

        try {
            $result = app(Publisher::class)->publish($plugin, $args['version'] ?? null, $args['changelog'] ?? null);
        } catch (\InvalidArgumentException) {
            return ['error' => 'no such version'];
        } catch (HubException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'slug' => $result['slug'],
            'version' => $result['version'],
            'hub_url' => $result['hub_url'],
        ];
    }
}
