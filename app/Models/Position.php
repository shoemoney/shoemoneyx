<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float', 'entry_price' => 'float', 'entry_usd' => 'float', 'fees_usd' => 'float',
            'exit_price' => 'float', 'exit_usd' => 'float', 'pnl_usd' => 'float', 'pnl_pct' => 'float', 'realised_usd' => 'float',
            'peak_price' => 'float', 'last_price' => 'float',
            'meta' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }

    public function riskChecks(): HasMany
    {
        return $this->hasMany(RiskCheck::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeMode(Builder $q, string $mode): Builder
    {
        return $q->where('mode', $mode);
    }

    /** Null scopes to the main desk's own account (arena_seat_id IS NULL); an id scopes to that seat only. */
    public function scopeSeat(Builder $q, ?int $seatId): Builder
    {
        return $seatId === null ? $q->whereNull('arena_seat_id') : $q->where('arena_seat_id', $seatId);
    }

    public function isShort(): bool
    {
        return ($this->side ?? 'long') === 'short';
    }

    /** +1 for long, -1 for short — multiplies price moves into PnL. */
    public function dir(): int
    {
        return $this->isShort() ? -1 : 1;
    }

    /** Open PnL on what is still held (partial take-profits already banked live in realised_usd). */
    public function unrealisedPnl(float $price): float
    {
        return $this->dir() * ($this->quantity * $price - $this->entry_usd);
    }

    /** Mark-to-market value of the position in the book (collateral + open PnL for shorts). */
    public function marketValue(float $price): float
    {
        return $this->isShort() ? $this->entry_usd + $this->unrealisedPnl($price) : $this->quantity * $price;
    }

    /** True when the entry posted margin (perps.paper_margin) instead of paying the full notional. */
    public function isMarginFunded(): bool
    {
        return array_key_exists('margin_usd', $this->meta ?? []);
    }

    /** Margin actually posted for this position; 0 for a position that isn't margin-funded. */
    public function collateral(): float
    {
        return (float) ($this->meta['margin_usd'] ?? 0.0);
    }

    /** Current notional exposure at the mark, regardless of side or funding model. */
    public function notional(float $price): float
    {
        return $this->quantity * $price;
    }

    /**
     * What this position is worth to the book's equity: posted collateral plus unrealised PnL for a
     * margin-funded position (the same contract MarginBook::valuation() uses), market value otherwise.
     * A margin position's full notional is not equity -- only the collateral behind it is.
     */
    public function equityValue(float $price): float
    {
        return $this->isMarginFunded() ? $this->collateral() + $this->unrealisedPnl($price) : $this->marketValue($price);
    }

    /** Open PnL plus everything banked by trims. */
    public function totalPnl(float $price): float
    {
        return $this->unrealisedPnl($price) + (float) ($this->realised_usd ?? 0);
    }

    public function unrealisedPnlPct(float $price): float
    {
        return $this->entry_usd > 0 ? ($this->unrealisedPnl($price) / $this->entry_usd) * 100 : 0.0;
    }

    public function heldMinutes(): int
    {
        return (int) $this->opened_at->diffInMinutes($this->closed_at ?? now());
    }

    /** Mark a fresh price: updates last_price and extends peak_price to the favourable extreme (max for longs, min for shorts). */
    public function markPrice(float $price): void
    {
        $this->last_price = $price;
        $peak = (float) ($this->peak_price ?? 0);
        $this->peak_price = $peak <= 0 ? $price : ($this->isShort() ? min($peak, $price) : max($peak, $price));
    }
}
