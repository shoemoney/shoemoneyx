<?php

declare(strict_types=1);

namespace App\Desk\Arena;

use App\Desk\Contracts\Strategy;
use App\Desk\Desk;
use App\Desk\StrategyRegistry;
use App\Models\ArenaSeat;
use Illuminate\Support\Arr;

/**
 * Runs every active arena seat's own strategy through the exact same pipeline the main desk
 * uses: Desk::runCycle() (SCAN -> VET -> SIZE -> FILLS) and Desk::runRiskSweep() (RISK), one call
 * of each per seat, all seats sharing the one ProductStats snapshot built here once per cycle.
 * Desk's own logic is never forked — only cloned per seat (Desk::forSeat()) so each seat's reads
 * and writes land against its own account (arena_seat_id) instead of the main desk's (null).
 */
class ArenaRunner
{
    public function __construct(
        private Desk $desk,
        private StrategyRegistry $strategies,
    ) {}

    /** @return array<int, array{seat_id:int, run_id:int, status:string}> */
    public function cycle(): array
    {
        $seats = ArenaSeat::active()->get();
        if ($seats->isEmpty()) {
            return [];
        }

        // Built once, off the main desk's own universe() — every seat's SCAN runs against this
        // same snapshot instead of each seat re-fetching live stats for itself.
        $universe = $this->desk->universe();

        $out = [];
        foreach ($seats as $seat) {
            [$strategy, $overrides] = $this->strategyFor($seat);
            $seatDesk = $this->desk->forSeat($seat->id, $overrides);
            $executor = $seatDesk->executorForMode('paper');

            $run = $seatDesk->runCycle($strategy, $executor, $universe);
            $seatDesk->runRiskSweep($strategy, $executor);
            $seatDesk->bank($executor, true); // equity snapshot for the scoreboard's drawdown

            $out[] = ['seat_id' => $seat->id, 'run_id' => $run->id, 'status' => $run->status];
        }

        return $out;
    }

    /** @return array{0: Strategy, 1: array<string, mixed>} the strategy and its DeskContext param overrides (nested, ready for DeskContext::withParams()) */
    private function strategyFor(ArenaSeat $seat): array
    {
        if ($seat->strategy_plugin_version_id !== null) {
            return [$this->strategies->make('json'), ['json' => ['plugin_version_id' => $seat->strategy_plugin_version_id]]];
        }

        // seat.params is stored as a flat dotted, typed map — the same shape Backtest::params
        // uses (see BacktestController::normalizeOverrides) — undotted here into the nested tree
        // DeskContext::withParams() expects.
        return [$this->strategies->make($seat->strategy_key), Arr::undot((array) ($seat->params ?? []))];
    }
}
