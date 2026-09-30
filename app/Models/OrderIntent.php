<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A live order the desk committed to sending; see the create_order_intents migration. */
class OrderIntent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_usd' => 'float', 'decision_price' => 'float',
            'context' => 'array', 'resolved_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }

    public function scopeMode(Builder $q, string $mode): Builder
    {
        return $q->where('mode', $mode);
    }

    public function resolve(string $outcome, ?string $note = null): void
    {
        $this->forceFill([
            'status' => 'resolved', 'outcome' => $outcome, 'resolved_at' => now(),
            'note' => $note ?? $this->note,
        ])->save();
    }

    public function scopeVenue(Builder $q, string $venue): Builder
    {
        return $q->where('venue', $venue);
    }

    /** Seconds since a send was last attempted (creation counts as the first attempt). */
    public function secondsSinceSent(): int
    {
        return (int) max(0, now()->getTimestamp() - ($this->sent_at ?? $this->created_at)->getTimestamp());
    }

    public function ageSeconds(): int
    {
        return (int) max(0, now()->getTimestamp() - $this->created_at->getTimestamp());
    }
}
