<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Models\Candle;

/** Bounded, read-only chart snapshots. Feeders and backfill commands own ingestion. */
class ChartCandles
{
    public const MAX_BARS = 2000;

    public function sourceTimeframe(string $timeframe): string
    {
        // The live desk maintains 1m candles; derive 5m without waiting for another feed.
        return $timeframe === '5m' ? '1m' : (Candle::DERIVED[$timeframe] ?? $timeframe);
    }

    public function bars(string $product, string $timeframe, int $from, int $to): array
    {
        $duration = Candle::DURATIONS[$timeframe];
        $source = $this->sourceTimeframe($timeframe);
        $sourceDuration = Candle::DURATIONS[$source];
        $from = max($from, $to - self::MAX_BARS * $duration);
        $from -= $from % $duration;
        $limit = (self::MAX_BARS + 1) * (int) ceil($duration / $sourceDuration);
        $rows = Candle::for($product, $source)
            ->whereBetween('candle_start', [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)])
            ->orderByDesc('candle_start')->limit($limit)
            ->toBase()->get(['candle_start', 'open', 'high', 'low', 'close', 'volume']);
        $bars = $rows->reverse()->map(fn ($row) => [
            'start' => strtotime($row->candle_start.' UTC'),
            'open' => (float) $row->open, 'high' => (float) $row->high,
            'low' => (float) $row->low, 'close' => (float) $row->close,
            'volume' => (float) $row->volume,
        ])->values()->all();

        if ($source !== $timeframe) {
            $bars = CandleStore::resample($bars, $duration);
        }

        return array_slice($bars, -self::MAX_BARS);
    }
}
