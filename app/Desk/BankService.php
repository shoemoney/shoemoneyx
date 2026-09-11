<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Data\Bank;
use App\Desk\Execution\Executor;
use App\Desk\Execution\MarginBook;
use App\Exchange\Contracts\MarketData;
use App\Models\BankSnapshot;
use App\Models\Position;

/**
 * Marks the book. Free cash = equity minus the locked bag minus what is already working.
 *
 * Finding 6 (2026-09-05 review): a margin-funded position's full notional is not equity -- only
 * the collateral posted for it (plus its unrealised PnL) is. MarginBook::valuation() is the single
 * contract both the margin session and this snapshot use, so the two views can't disagree again.
 */
class BankService
{
    public function __construct(
        private MarketData $market,
        private Settings $settings,
    ) {}

    /** @param array<int, Position> $open */
    public function current(Executor $executor, array $open, bool $snapshot = false, ?int $arenaSeatId = null): Bank
    {
        $priceOf = fn (Position $p): float => (float) ($this->market->price($p->product_id) ?? $p->last_price ?? $p->entry_price ?? 0.0);

        // Capability check, not instanceof against a concrete exchange's executor class: any
        // Executor that exposes session() is a margin-session venue (today, only the live perps
        // executor) — session() is deliberately not on the Executor interface (only some venues
        // have one), so this is the one honest way to ask "does this executor have a session?"
        // without BankService importing any single exchange's executor class.
        $isLivePerps = method_exists($executor, 'session');

        $spotValue = 0.0;
        $marginPositions = [];
        foreach ($open as $p) {
            // Live futures positions are always margin-funded even though they never carry the
            // paper-only meta['margin_usd'] flag; everything else falls back to Position's own flag.
            if ($isLivePerps || $p->isMarginFunded()) {
                $marginPositions[] = $p;
            } else {
                $spotValue += $p->marketValue($priceOf($p));
            }
        }

        $valuation = MarginBook::valuation($marginPositions, $priceOf);
        $collateral = $valuation['collateral'];
        $unrealisedPnl = $valuation['unrealised'];
        $exposure = $valuation['notional'] + $spotValue;

        $cash = $executor->cash();
        $buyingPower = null;

        if ($isLivePerps) {
            // cash() on the live executor is CFM's futures_buying_power (leveraged headroom), not
            // settled cash -- keep it under its own label rather than feeding it into equity.
            // Equity's cash leg is the session's availableMargin (unencumbered collateral); CFM
            // tracks the real initial margin server-side too, so trust that figure over our local
            // sum (paper-only meta never gets set on live fills).
            $buyingPower = $cash;
            $collateral = $executor->session()->initialMargin;
            $cash = $executor->session()->availableMargin;
        }

        $positionsValue = $collateral + $unrealisedPnl + $spotValue;

        $realisedToday = (float) Position::mode($executor->mode())->where('status', 'closed')
            ->where('closed_at', '>=', now()->startOfDay())->sum('pnl_usd');

        $bank = new Bank(
            cash: $cash,
            positionsValue: $positionsValue,
            lockedPct: (float) $this->settings->get('bank.locked_pct', 0.2),
            reserveUsd: (float) $this->settings->get('bank.reserve_usd', 0),
            openPositions: count($open),
            realisedPnlToday: $realisedToday,
            collateral: $collateral,
            unrealisedPnl: $unrealisedPnl,
            exposure: $exposure,
            buyingPower: $buyingPower,
        );

        if ($snapshot) {
            BankSnapshot::create($bank->toArray() + ['mode' => $executor->mode(), 'arena_seat_id' => $arenaSeatId, 'taken_at' => now()]);
        }

        return $bank;
    }
}
