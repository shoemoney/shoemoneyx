<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Models\Backtest;

final class GetBacktestTool implements Tool
{
    public function name(): string
    {
        return 'get_backtest';
    }

    public function description(): string
    {
        return 'Look up a previously run backtest by id and return its status, stats and ending equity.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Backtest id.'],
            ],
            'required' => ['id'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $bt = Backtest::find($args['id'] ?? null);
        if (! $bt) {
            return ['error' => 'no such backtest'];
        }

        return [
            'id' => $bt->id,
            'status' => $bt->status,
            'error' => $bt->error,
            'stats' => $bt->stats,
            'ending_equity' => $bt->ending_equity,
            'products' => $bt->products,
            'from' => $bt->from,
            'to' => $bt->to,
        ];
    }
}
