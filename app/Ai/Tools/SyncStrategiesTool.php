<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Strategies\Sync\StrategySync;

final class SyncStrategiesTool implements Tool
{
    public function name(): string
    {
        return 'sync_strategies';
    }

    public function description(): string
    {
        return 'Check the community strategies repo for new or updated strategies, read-only. Importing needs the user confirmation in the UI.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'import' => ['type' => 'boolean', 'description' => 'Import every newly-found strategy. Default false (check only).'],
            ],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $sync = app(StrategySync::class);

        try {
            $result = $sync->check();
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($args['import'] ?? false) {
            // Imports pull in untrusted community strategies; only the user's own action may do that.
            $result['needs_confirmation'] = true;
            $result['action'] = 'import';
            $result['endpoint'] = 'POST /api/strategies/sync/import (one id) or /api/strategies/sync/import-all';
            $result['message'] = 'Nothing was imported. Ask the user to import from the strategy sync panel.';
        }

        return $result;
    }
}
