<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Starts a live arena seat: a saved strategy plugin version running in paper, on its own account, against the current champion. */
final class StartArenaSeatTool implements Tool
{
    public function name(): string
    {
        return 'start_arena_seat';
    }

    public function description(): string
    {
        return 'Start a live arena seat running a saved strategy plugin version in paper, on its own account, against the current champion.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'version_id' => ['type' => 'integer', 'description' => 'Strategy plugin version id to run (defaults to the plugin just saved this turn, at its current version).'],
                'label' => ['type' => 'string', 'description' => 'Seat label. Defaults to "<plugin> v<version>".'],
                'cash' => ['type' => 'number', 'description' => 'Starting paper cash for this seat. Defaults to 1000.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        // Same rules as ArenaSeatController::store.
        $validator = Validator::make($args, [
            'label' => 'nullable|string|max:64',
            'cash' => 'nullable|numeric|min:1',
            'version_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $versionId = $args['version_id'] ?? null;

        if ($versionId === null) {
            $pluginId = $context->pluginId;
            if (! $pluginId) {
                return ['error' => 'no version_id given and no plugin saved yet this conversation'];
            }
            $plugin = StrategyPlugin::find($pluginId);
            if (! $plugin || $plugin->current_version === null) {
                return ['error' => 'plugin has no saved version yet'];
            }
            $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
                ->where('version', $plugin->current_version)->first();
            if (! $version) {
                return ['error' => 'current version not found'];
            }
            $versionId = $version->id;
        }

        $version = StrategyPluginVersion::find($versionId);
        if (! $version) {
            return ['error' => "no such strategy plugin version: {$versionId}"];
        }

        $seat = ArenaSeat::create([
            'label' => ($args['label'] ?? '') !== '' ? $args['label'] : Str::limit(sprintf('%s v%s', $version->plugin?->key ?? 'json', $version->version), 64, ''),
            'strategy_plugin_version_id' => $version->id,
            'starting_cash' => $args['cash'] ?? 1000,
            'status' => 'active',
            'started_at' => now(),
        ]);

        return [
            'id' => $seat->id,
            'label' => $seat->label,
            'strategy_label' => $seat->strategyLabel(),
            'starting_cash' => $seat->starting_cash,
            'status' => $seat->status,
        ];
    }
}
