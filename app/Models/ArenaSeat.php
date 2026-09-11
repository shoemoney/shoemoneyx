<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArenaSeat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'starting_cash' => 'float',
            'is_champion' => 'boolean',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StrategyPluginVersion::class, 'strategy_plugin_version_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'arena_seat_id');
    }

    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class, 'arena_seat_id');
    }

    public function bankSnapshots(): HasMany
    {
        return $this->hasMany(BankSnapshot::class, 'arena_seat_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeChampion(Builder $q): Builder
    {
        return $q->where('is_champion', true);
    }

    /** "json v1.2.0" for a plugin-version seat, the bare key ("mr") for a built-in one. */
    public function strategyLabel(): string
    {
        if ($this->strategy_plugin_version_id) {
            $version = $this->version;

            return $version ? sprintf('%s v%s', $version->plugin?->name ?? $version->plugin?->key ?? 'json', $version->version) : 'json';
        }

        return (string) $this->strategy_key;
    }
}
