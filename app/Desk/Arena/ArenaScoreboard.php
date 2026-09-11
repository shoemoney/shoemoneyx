<?php

declare(strict_types=1);

namespace App\Desk\Arena;

use App\Desk\Execution\PaperExecutor;
use App\Desk\StrategyRegistry;
use App\Exchange\Contracts\MarketData;
use App\Models\ArenaSeat;
use App\Models\BankSnapshot;
use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Builds the live arena scoreboard: every seat's PnL, drawdown, win rate, trade count and open
 * positions, plus its delta against the current champion. Shared by ArenaSeatController::index()
 * (the API) and the arena_scoreboard agent tool, so both read the exact same numbers.
 */
class ArenaScoreboard
{
    public function __construct(
        private MarketData $market,
        private StrategyRegistry $strategies,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $rows = ArenaSeat::orderByDesc('is_champion')->orderBy('id')->get()
            ->map(fn (ArenaSeat $seat) => $this->row($seat));

        $championPnlPct = $rows->firstWhere('is_champion', true)['pnl_pct'] ?? null;

        return $rows->map(function (array $row) use ($championPnlPct) {
            $row['vs_champion_pct'] = ($championPnlPct === null || $row['is_champion'])
                ? null
                : round($row['pnl_pct'] - $championPnlPct, 4);

            return $row;
        })->values();
    }

    /** @return array<int, string> built-in strategy keys a seat can run directly (json is plugin-version-backed, not picked here). */
    public function builtinStrategies(): array
    {
        return array_values(array_diff(array_keys($this->strategies->all()), ['json']));
    }

    private function row(ArenaSeat $seat): array
    {
        $executor = app(PaperExecutor::class)->withSeat($seat->id);
        $open = Position::open()->mode('paper')->seat($seat->id)->get();
        $priceOf = fn (Position $p) => (float) ($this->market->price($p->product_id) ?? $p->last_price ?? $p->entry_price ?? 0.0);

        $cash = $executor->cash();
        $positionsValue = $open->sum(fn (Position $p) => $p->marketValue($priceOf($p)));
        $equity = $cash + $positionsValue;
        $startingCash = (float) $seat->starting_cash;
        $pnlUsd = $equity - $startingCash;
        $pnlPct = $startingCash > 0 ? $pnlUsd / $startingCash * 100 : 0.0;

        $closed = Position::mode('paper')->seat($seat->id)->where('status', 'closed');
        $closedCount = (clone $closed)->count();
        $wins = (clone $closed)->where('pnl_usd', '>', 0)->count();

        $equitySeries = BankSnapshot::where('mode', 'paper')->where('arena_seat_id', $seat->id)->orderBy('taken_at')->pluck('equity')->all();

        return [
            'id' => $seat->id,
            'label' => $seat->label,
            'strategy_label' => $seat->strategyLabel(),
            'strategy_plugin_version_id' => $seat->strategy_plugin_version_id,
            'strategy_key' => $seat->strategy_key,
            'status' => $seat->status,
            'is_champion' => $seat->is_champion,
            'starting_cash' => round($startingCash, 4),
            'cash' => round($cash, 4),
            'positions_value' => round($positionsValue, 4),
            'equity' => round($equity, 4),
            'pnl_usd' => round($pnlUsd, 4),
            'pnl_pct' => round($pnlPct, 4),
            'drawdown_pct' => round(self::drawdownPct($equitySeries), 4),
            'win_rate' => $closedCount > 0 ? round($wins / $closedCount * 100, 2) : null,
            'trade_count' => $closedCount,
            'open_positions' => $open->count(),
            'started_at' => $seat->started_at?->toIso8601String(),
            'stopped_at' => $seat->stopped_at?->toIso8601String(),
        ];
    }

    /** Max peak-to-trough decline, as a percent of the peak, over an equity series in chronological order. */
    public static function drawdownPct(array $equitySeries): float
    {
        $peak = null;
        $maxDrawdown = 0.0;
        foreach ($equitySeries as $equity) {
            $equity = (float) $equity;
            $peak = $peak === null ? $equity : max($peak, $equity);
            if ($peak > 0) {
                $maxDrawdown = max($maxDrawdown, ($peak - $equity) / $peak * 100);
            }
        }

        return $maxDrawdown;
    }
}
