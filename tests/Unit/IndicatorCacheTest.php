<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\IndicatorField;
use App\Services\Indicators\IndicatorCache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * IndicatorCache's memo must be bounded per bar (products x timeframes x indicators), not grow
 * one entry per (product, timeframe, indicator, step) for the life of a backtest or a long-lived
 * live/paper process (docs/STRATEGY_SCHEMA_V2.md, phase A).
 */
class IndicatorCacheTest extends TestCase
{
    private function stats(): ProductStats
    {
        return new ProductStats(
            productId: 'BTC-USD',
            price: 100.0,
            bestBid: 99.9,
            bestAsk: 100.1,
            ageHours: 100.0,
            volumeM5Usd: 1000.0,
            volumeH1Usd: 12000.0,
            volumeH6Usd: 60000.0,
            volumeH24Usd: 240000.0,
            volumePrevH24Usd: 200000.0,
            priceChangeM5Pct: 0.1,
            priceChangeH1Pct: 0.5,
            priceChangeH6Pct: 1.0,
            priceChangeH24Pct: 2.0,
            buysH1: 60,
            sellsH1: 40,
            buysM5: 6,
            sellsM5: 4,
            buyVolumeH1Usd: 7000.0,
            sellVolumeH1Usd: 5000.0,
            spreadBps: 10.0,
            bookDepthUsd: 50000.0,
            candlesH1Count: 100,
        );
    }

    private function cacheSize(): int
    {
        $prop = new \ReflectionProperty(IndicatorCache::class, 'cache');
        $prop->setAccessible(true);

        return count($prop->getValue());
    }

    #[Test]
    public function the_memo_does_not_grow_across_backtest_steps(): void
    {
        $base = strtotime('2026-01-01T00:00:00Z');
        $bars = [];
        for ($i = 0; $i < 500; $i++) {
            $close = 100.0 + $i * 0.01;
            $bars[] = ['start' => $base + $i * 3600, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close, 'volume' => 10.0];
        }
        $provider = fn (string $pid, string $tf, int $from, int $to): array => $bars;
        $field = IndicatorField::parse('ind.sma(3)');
        $s = $this->stats();

        for ($step = 0; $step < 1000; $step++) {
            $now = $base + 500 * 3600 + $step * 60;
            $ctx = new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.$now), true, $provider);
            IndicatorCache::snapshot($ctx, $s->productId, '1h', $field, $s->price);
        }

        // One product, one timeframe, one indicator -- the memo for the CURRENT bar holds one
        // entry, however many of the 1000 steps landed on it. It must never reach anywhere near
        // 1000.
        $this->assertLessThanOrEqual(2, $this->cacheSize());
    }
}
