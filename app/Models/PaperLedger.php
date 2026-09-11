<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaperLedger extends Model
{
    protected $table = 'paper_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function scopeSeat(Builder $q, ?int $seatId): Builder
    {
        return $seatId === null ? $q->whereNull('arena_seat_id') : $q->where('arena_seat_id', $seatId);
    }
}
