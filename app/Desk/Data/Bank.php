<?php

declare(strict_types=1);

namespace App\Desk\Data;

/** Snapshot of the book: what is working and what is locked. */
final class Bank
{
    public function __construct(
        public readonly float $cash,            // settled quote currency (live: see $buyingPower note below)
        public readonly float $positionsValue,  // margin positions: collateral + unrealised PnL; spot: market value
        public readonly float $lockedPct,
        public readonly float $reserveUsd,
        public readonly int $openPositions,
        public readonly float $realisedPnlToday = 0.0,
        public readonly float $collateral = 0.0,     // margin actually posted across open margin positions
        public readonly float $unrealisedPnl = 0.0,  // open PnL on margin positions (already folded into positionsValue)
        public readonly float $exposure = 0.0,       // total notional at risk, margin leverage included
        public readonly ?float $buyingPower = null,  // live: CFM futures_buying_power -- leveraged headroom, NOT cash
    ) {}

    public function equity(): float
    {
        return $this->cash + $this->positionsValue;
    }

    /** "The locked bag never moves." */
    public function locked(): float
    {
        return $this->equity() * $this->lockedPct + $this->reserveUsd;
    }

    /** Cash that may be allocated right now. */
    public function freeCash(): float
    {
        return max(0.0, min($this->cash, $this->equity() - $this->locked() - $this->positionsValue));
    }

    public function toArray(): array
    {
        return [
            'cash' => round($this->cash, 4),
            'positions_value' => round($this->positionsValue, 4),
            'equity' => round($this->equity(), 4),
            'locked' => round($this->locked(), 4),
            'free_cash' => round($this->freeCash(), 4),
            'open_positions' => $this->openPositions,
            'realised_pnl_today' => round($this->realisedPnlToday, 4),
            'collateral' => round($this->collateral, 4),
            'unrealised_pnl' => round($this->unrealisedPnl, 4),
            'exposure' => round($this->exposure, 4),
            'buying_power' => $this->buyingPower === null ? null : round($this->buyingPower, 4),
        ];
    }
}
