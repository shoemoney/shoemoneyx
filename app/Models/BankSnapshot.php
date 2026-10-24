<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankSnapshot extends Model
{
    protected $guarded = [];

    /** Null scopes to the main desk's own account (arena_seat_id IS NULL); an id scopes to that seat only. */
    public function scopeSeat(Builder $q, ?int $seatId): Builder
    {
        return $seatId === null ? $q->whereNull('arena_seat_id') : $q->where('arena_seat_id', $seatId);
    }

    protected function casts(): array
    {
        return [
            'cash' => 'float', 'positions_value' => 'float', 'equity' => 'float', 'locked' => 'float',
            'free_cash' => 'float', 'realised_pnl_today' => 'float', 'taken_at' => 'datetime',
            'collateral' => 'float', 'unrealised_pnl' => 'float', 'exposure' => 'float', 'buying_power' => 'float',
        ];
    }
}
