<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fill extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_usd' => 'float', 'filled_usd' => 'float', 'filled_qty' => 'float',
            'decision_price' => 'float', 'fill_price' => 'float', 'slippage_bps' => 'float',
            'fee_usd' => 'float', 'fee_pct' => 'float', 'partial' => 'boolean', 'whale_hold' => 'boolean',
            'raw' => 'array',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeSeat(Builder $q, ?int $seatId): Builder
    {
        return $seatId === null ? $q->whereNull('arena_seat_id') : $q->where('arena_seat_id', $seatId);
    }
}
