<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Desk\Strategies\SchemaMigrator;
use App\Models\StrategyPlugin;
use App\Support\SemVer;
use Illuminate\Support\Facades\DB;

/**
 * Validates and saves a strategy definition, exactly like
 * StrategyPluginController::store() — duplicated here rather than reused
 * because the controller is off-limits to touch or call directly from a tool.
 */
final class StrategyJsonTool implements Tool
{
    public function name(): string
    {
        return 'strategy_json';
    }

    public function description(): string
    {
        return 'Validate and save a strategy definition (legacy, schema_version:1, or schema_version:2 shape) as a new plugin version.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'definition' => ['type' => 'object', 'description' => 'The full strategy definition JSON.'],
                'bump' => ['type' => 'string', 'enum' => ['major', 'minor', 'patch'], 'description' => 'Semver bump level, default patch.'],
                'changelog' => ['type' => 'string', 'description' => 'Free-text changelog for this version.'],
            ],
            'required' => ['definition'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $def = $args['definition'] ?? null;
        if (! is_array($def)) {
            return ['valid' => false, 'errors' => ['definition must be an object']];
        }

        $result = SchemaMigrator::validateForSave($def);

        if (! $result['valid']) {
            return ['valid' => false, 'errors' => $result['errors']];
        }

        $bump = $args['bump'] ?? 'patch';
        $name = $def['meta']['name'] ?? $def['name'] ?? null;
        $description = $def['meta']['description'] ?? $def['description'] ?? null;

        $plugin = DB::transaction(function () use ($def, $name, $description, $bump, $args, $context) {
            $existing = StrategyPlugin::where('key', $def['key'])->first();
            $nextVersion = $existing?->current_version ? SemVer::bump($existing->current_version, $bump) : '1.0.0';

            $plugin = StrategyPlugin::updateOrCreate(
                ['key' => $def['key']],
                ['name' => $name, 'description' => $description, 'definition' => $def, 'current_version' => $nextVersion],
            );

            $plugin->versions()->create([
                'version' => $nextVersion,
                'definition' => $def,
                'changelog' => $args['changelog'] ?? null,
                'created_by' => $context->userEmail,
            ]);

            return $plugin;
        });

        $context->pluginId = $plugin->id;

        return [
            'valid' => true,
            'errors' => [],
            'plugin_id' => $plugin->id,
            'key' => $plugin->key,
            'version' => $plugin->current_version,
        ];
    }
}
