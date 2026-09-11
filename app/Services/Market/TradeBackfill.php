<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Exchange\Contracts\MarketData;
use App\Models\Candle;

/**
 * Rebuilds sub-minute candles (Candle::FROM_TRADES) from the exchange's historical trade tape.
 * Walks backwards in time, 1000 trades per call, and upserts every bucket touched.
 */
class TradeBackfill
{
    public function __construct(private MarketData $market, private CandleStore $store) {}

    /**
     * @param  array<int, string>  $timeframes  subset of Candle::FROM_TRADES
     * @return array{trades:int,candles:int,calls:int,earliest:?int}
     */
    public function run(string $productId, int $fromUnix, int $toUnix, array $timeframes = ['15s', '30s', '45s'], ?callable $progress = null): array
    {
        $bars = [];   // tf => [bucket => bar]
        foreach ($timeframes as $tf) {
            $bars[$tf] = [];
        }
        $calls = 0;
        $trades = 0;
        $earliest = null;   // oldest trade timestamp actually seen
        $end = $toUnix;
        $seen = null;
        $flushEvery = 20;

        while ($end > $fromUnix) {
            $batch = $this->market->trades($productId, $fromUnix, $end, 1000);
            $calls++;
            if ($batch === []) {
                break;
            }
            $oldest = PHP_INT_MAX;
            foreach ($batch as $t) {
                if ($t['time'] < $fromUnix) {
                    continue;
                }
                if ($seen !== null && $t['trade_id'] === $seen) {
                    continue;
                }
                $oldest = min($oldest, $t['time']);
                $earliest = $earliest === null ? $t['time'] : min($earliest, $t['time']);
                $trades++;
                foreach ($timeframes as $tf) {
                    $dur = Candle::DURATIONS[$tf];
                    $k = $t['time'] - $t['time'] % $dur;
                    $b = &$bars[$tf];
                    if (! isset($b[$k])) {
                        // newest first: the first print we see for a bucket is its CLOSE
                        $b[$k] = ['start' => $k, 'open' => $t['price'], 'high' => $t['price'], 'low' => $t['price'], 'close' => $t['price'], 'volume' => 0.0];
                    }
                    $b[$k]['open'] = $t['price'];              // keeps moving back to the earliest print
                    $b[$k]['high'] = max($b[$k]['high'], $t['price']);
                    $b[$k]['low'] = min($b[$k]['low'], $t['price']);
                    $b[$k]['volume'] += $t['size'];
                    unset($b);
                }
            }
            $seen = end($batch)['trade_id'];
            // next window ends where this one began (same-second overlap is de-duplicated by trade_id above)
            $newEnd = $oldest === PHP_INT_MAX ? $fromUnix : $oldest;
            if ($newEnd >= $end) {
                $newEnd = $end - 1;
            }
            $end = $newEnd;

            if ($calls % $flushEvery === 0) {
                $this->flush($productId, $bars, keepNewest: 2);
                if ($progress) {
                    $progress($calls, $trades, $end);
                }
            }
        }
        $candles = $this->flush($productId, $bars, keepNewest: 0);

        return ['trades' => $trades, 'candles' => $candles, 'calls' => $calls, 'earliest' => $earliest];
    }

    /** Upsert complete buckets; keep the $keepNewest oldest (still-filling) buckets in memory. */
    private function flush(string $productId, array &$bars, int $keepNewest): int
    {
        $n = 0;
        foreach ($bars as $tf => &$buckets) {
            if ($buckets === []) {
                continue;
            }
            ksort($buckets);
            $rows = array_values($buckets);
            // walking backwards: the OLDEST buckets are the ones still receiving trades
            $done = $keepNewest > 0 ? array_slice($rows, $keepNewest) : $rows;
            $keep = $keepNewest > 0 ? array_slice($rows, 0, $keepNewest) : [];
            if ($done !== []) {
                $n += $this->store->upsert($productId, $tf, $done);
            }
            $buckets = [];
            foreach ($keep as $b) {
                $buckets[$b['start']] = $b;
            }
        }

        return $n;
    }
}
