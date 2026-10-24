<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TruncatesError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeskRun extends Model
{
    use TruncatesError;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'degraded' => 'boolean',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('rank');
    }

    /** Null means the main desk; arena runs remain attached to their own paper account. */
    public function scopeSeat(Builder $query, ?int $seatId): Builder
    {
        return $seatId === null ? $query->whereNull('arena_seat_id') : $query->where('arena_seat_id', $seatId);
    }

    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeskEvent::class);
    }
}
