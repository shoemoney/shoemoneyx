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
        return 'Check the community strategies repo for new or updated strategies, optionally importing the new ones as plugins.';
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
            $result['imported'] = array_map(fn (array $entry) => $sync->import($entry['id']), $result['new']);
        }

        return $result;
    }
}
