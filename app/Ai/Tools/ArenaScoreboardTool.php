<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Desk\Arena\ArenaScoreboard;

/** Reads the live arena scoreboard: every seat's PnL, drawdown, win rate and trade count against the current champion. */
final class ArenaScoreboardTool implements Tool
{
    public function name(): string
    {
        return 'arena_scoreboard';
    }

    public function description(): string
    {
        return 'Get the current live arena scoreboard: every seat\'s equity, PnL, drawdown, win rate, trade count and delta against the champion.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function run(array $args, AgentContext $context): array
    {
        return ['seats' => app(ArenaScoreboard::class)->rows()->all()];
    }
}
