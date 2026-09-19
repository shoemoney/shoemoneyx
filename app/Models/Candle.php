<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    protected $guarded = [];

    public const DURATIONS = [
        '13s' => 13, '15s' => 15, '20s' => 20, '25s' => 25, '30s' => 30, '33s' => 33, '41s' => 41, '45s' => 45, '49s' => 49, '90s' => 90,
        '1m' => 60, '2m' => 120, '3m' => 180, '4m' => 240, '5m' => 300, '10m' => 600, '15m' => 900, '30m' => 1800, '1H' => 3600, '6H' => 21600, '1D' => 86400,
    ];

    /** Timeframes Coinbase does not serve; built by resampling the 1m store. */
    public const DERIVED = ['90s' => '30s', '2m' => '1m', '3m' => '1m', '4m' => '1m', '10m' => '5m'];

    /**
     * Case-insensitive match against DURATIONS's own keys ("1h" in a strategy's JSON is "1H" in
     * the candle store). Null when $tf names no known timeframe at all.
     */
    public static function canonicalTimeframe(string $tf): ?string
    {
        foreach (array_keys(self::DURATIONS) as $canonical) {
            if (strcasecmp($canonical, $tf) === 0) {
                return $canonical;
            }
        }

        return null;
    }

    /** Built from the raw trade tape (feeder live, market:backfill-trades for history). */
    public const FROM_TRADES = ['13s', '15s', '20s', '25s', '30s', '33s', '41s', '45s', '49s'];

    protected function casts(): array
    {
        return [
            'candle_start' => 'datetime',
            'open' => 'float', 'high' => 'float', 'low' => 'float', 'close' => 'float', 'volume' => 'float',
        ];
    }

    public function scopeFor(Builder $q, string $productId, string $timeframe): Builder
    {
        return $q->where('product_id', strtoupper($productId))->where('timeframe', $timeframe);
    }
}
