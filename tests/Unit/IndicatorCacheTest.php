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
    public function the_memo_computes_once_per_bar_across_every_step_inside_it(): void
    {
        $base = strtotime('2026-01-01T00:00:00Z');
        $bars = [];
        for ($i = 0; $i < 500; $i++) {
            $close = 100.0 + $i * 0.01;
            $bars[] = ['start' => $base + $i * 3600, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close, 'volume' => 10.0];
        }
        $calls = 0;
        $provider = function (string $pid, string $tf, int $from, int $to) use ($bars, &$calls): array {
            $calls++;

            return $bars;
        };
        $field = IndicatorField::parse('ind.sma(3)');
        $s = $this->stats();

        // 60 one-minute steps landing inside the same closed 1H bar -- keying the memo on $now
        // instead of the bar bucket would recompute (and re-fetch bars) on every single one.
        $hourStart = $base + 500 * 3600;
        for ($minute = 0; $minute < 60; $minute++) {
            $now = $hourStart + $minute * 60;
            $ctx = new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.$now), true, $provider);
            IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);
        }

        $this->assertSame(1, $calls, 'one bar bucket must be computed once and reused for every step inside it');
        $this->assertLessThanOrEqual(2, $this->cacheSize());
    }

    #[Test]
    public function previous_is_never_computed_unless_a_caller_actually_asks_for_it(): void
    {
        $base = strtotime('2026-01-01T00:00:00Z');
        $bars = [];
        for ($i = 0; $i < 500; $i++) {
            $close = 100.0 + $i * 0.01;
            $bars[] = ['start' => $base + $i * 3600, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close, 'volume' => 10.0];
        }
        $calls = 0;
        $provider = function (string $pid, string $tf, int $from, int $to) use ($bars, &$calls): array {
            $calls++;

            return $bars;
        };
        $field = IndicatorField::parse('ind.sma(3)');
        $s = $this->stats();
        $ctx = new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.($base + 500 * 3600)), true, $provider);

        IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);
        IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);

        $this->assertSame(1, $calls, 'a literal/field-ref rule never triggers the previous-bar computation');

        IndicatorCache::previous($ctx, $s->productId, '1h', $field, $s->price);

        $this->assertSame(2, $calls, 'a crosses_* rule pays for the previous bar exactly once, on first ask');
    }
}
