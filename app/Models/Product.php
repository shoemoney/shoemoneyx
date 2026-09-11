<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_tracked' => 'boolean',
            'trading_disabled' => 'boolean',
            'listed_at' => 'datetime',
            'synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function scopeTradeable(Builder $q): Builder
    {
        return $q->where('status', 'online')->where('trading_disabled', false);
    }

    public function scopeTracked(Builder $q): Builder
    {
        return $q->where('is_tracked', true);
    }

    public function ageHours(): ?float
    {
        return $this->listed_at ? $this->listed_at->diffInMinutes(now()) / 60 : null;
    }
}
