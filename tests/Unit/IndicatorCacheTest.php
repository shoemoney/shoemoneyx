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
    public function the_computed_series_is_reused_across_every_step_inside_one_bar_when_the_bars_dont_change(): void
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

        // 60 one-minute steps landing inside the same closed 1H bar with a bar set that never
        // changes (round-4 review: the raw fetch now runs every read — see barFingerprint() — so
        // staleness elsewhere, e.g. CandleStore's own freshness-aware cache, is what bounds the
        // real fetch cost; this memo's own job is only to reuse the computed SERIES).
        $hourStart = $base + 500 * 3600;
        for ($minute = 0; $minute < 60; $minute++) {
            $now = $hourStart + $minute * 60;
            $ctx = new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.$now), true, $provider);
            IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);
        }

        $this->assertSame(60, $calls, 'the raw bar fetch runs on every read now — that staleness fix is the whole point of folding the bar fingerprint into the key');
        $this->assertLessThanOrEqual(2, $this->cacheSize(), 'unchanged bars across the bucket must still collapse to one computed cache entry');
    }

    #[Test]
    public function previous_cache_is_never_populated_unless_a_caller_actually_asks_for_it(): void
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
        $ctx = new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.($base + 500 * 3600)), true, $provider);

        IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);
        IndicatorCache::current($ctx, $s->productId, '1h', $field, $s->price);

        $prevProp = new \ReflectionProperty(IndicatorCache::class, 'previousCache');
        $prevProp->setAccessible(true);
        $this->assertSame([], $prevProp->getValue(), 'a literal/field-ref rule never triggers the previous-bar computation');

        IndicatorCache::previous($ctx, $s->productId, '1h', $field, $s->price);

        $this->assertNotSame([], $prevProp->getValue(), 'a crosses_* rule computes and caches the previous bar on first ask');
    }

    /**
     * Round-4 review, finding 2 (BLOCKER): the memo used to key only on (product, timeframe,
     * indicator, bucket) — no fingerprint of the bars themselves — so live's independent candle
     * sync and risk timers could see a partial or still-missing just-closed bar on the FIRST read
     * of an hour and serve that stale value for the whole bucket. cache_probe.php's scenario 3,
     * turned into a regression test: a missing bar landing later, and a partial bar's close
     * finalising later, must each recompute instead of reusing the first read.
     */
    #[Test]
    public function a_bar_that_lands_or_finalises_after_the_first_read_recomputes_instead_of_staying_pinned(): void
    {
        $mk = function (float $base, int $dur): \Closure {
            return function (int $now) use ($base, $dur): array {
                $bars = [];
                for ($i = 20; $i >= 0; $i--) {
                    $s = $now - $i * $dur;
                    $c = $base + (20 - $i) * 0.7; // monotonic ramp: dropping/mutating the newest bar visibly moves the SMA
                    $bars[] = ['start' => $s, 'open' => $c, 'high' => $c + 1, 'low' => $c - 1, 'close' => $c, 'volume' => 10.0];
                }

                return $bars;
            };
        };
        $data = $mk(500.0, 3600);
        $field = IndicatorField::parse('ind.sma(5)');
        $now = 1_700_000_000 - (1_700_000_000 % 3600) + 5; // 5s into the hour

        $ctxAt = function (int $ts, array $mutateClosed = [], bool $dropClosed = false) use ($data): DeskContext {
            $provider = function (string $pid, string $tf, int $from, int $to) use ($data, $mutateClosed, $dropClosed): array {
                $bars = $data($to);
                $n = count($bars);
                if ($dropClosed) {
                    array_splice($bars, $n - 2, 1); // the just-closed bar has not landed yet
                }
                foreach ($mutateClosed as $k => $v) {
                    $bars[$n - 2][$k] = $v; // or it landed partial and gets its final close later
                }

                return $bars;
            };

            return new DeskContext([], 'live', false, [], new \DateTimeImmutable('@'.$ts), false, $provider);
        };

        // Scenario A: the just-closed bar hasn't landed at all on the first read.
        $missing = IndicatorCache::current($ctxAt($now, dropClosed: true), 'SOL-USD', '1H', $field)['value'];
        $laterSameBucket = IndicatorCache::current($ctxAt($now + 1800), 'SOL-USD', '1H', $field)['value'];
        IndicatorCache::forgetAll();
        $truth = IndicatorCache::current($ctxAt($now + 1800), 'SOL-USD', '1H', $field)['value'];
        $this->assertNotEqualsWithDelta($truth, $missing, 0.001, 'the missing-bar read must differ from truth, or this test proves nothing');
        $this->assertEqualsWithDelta($truth, $laterSameBucket, 1e-9, 'once the bar lands, the SAME bucket must recompute instead of reusing the missing-bar read');

        // Scenario B: the just-closed bar is present but still partial (its close finalises later).
        IndicatorCache::forgetAll();
        $partial = IndicatorCache::current($ctxAt($now, ['close' => 100.0]), 'SOL-USD', '1H', $field)['value'];
        $final = IndicatorCache::current($ctxAt($now + 1800), 'SOL-USD', '1H', $field)['value'];
        $this->assertNotEqualsWithDelta($truth, $partial, 0.001, 'the partial-bar read must differ from truth, or this test proves nothing');
        $this->assertEqualsWithDelta($truth, $final, 1e-9, 'once the bar finalises, the SAME bucket must recompute instead of reusing the partial read');

        // Identical bars across two reads of the same bucket must still hit the same cache entry.
        IndicatorCache::forgetAll();
        $first = IndicatorCache::current($ctxAt($now + 1800), 'SOL-USD', '1H', $field)['value'];
        $second = IndicatorCache::current($ctxAt($now + 1800 + 5), 'SOL-USD', '1H', $field)['value'];
        $this->assertSame($first, $second, 'identical bars in the same bucket must hit, not recompute');
    }

    /**
     * Round-5 review, IMPORTANT 3: the fingerprint added in round 4 covered only
     * start|close|volume — atr/adx read high and low directly (and smx folds them into its own
     * series), so a correction that touches ONLY high/low left those indicators pinned to the
     * bucket's first read (reproduced: ATR pinned at 2.2079 vs a true 680.6 after a high
     * correction). Now start|open|high|low|close|volume.
     */
    #[Test]
    public function a_high_low_only_correction_recomputes_atr_instead_of_staying_pinned(): void
    {
        $mk = function (float $base, int $dur): \Closure {
            return function (int $now) use ($base, $dur): array {
                $bars = [];
                for ($i = 450; $i >= 0; $i--) {
                    $s = $now - $i * $dur;
                    $c = $base + ($i % 7) * 0.5;
                    $bars[] = ['start' => $s, 'open' => $c, 'high' => $c + 1, 'low' => $c - 1, 'close' => $c, 'volume' => 10.0];
                }

                return $bars;
            };
        };
        $data = $mk(500.0, 3600);
        $atr = IndicatorField::parse('ind.atr(14)');
        $now = 1_700_000_000 - (1_700_000_000 % 3600) + 5;

        $ctxAt = function (int $ts, array $mutateClosed = []) use ($data): DeskContext {
            $provider = function (string $pid, string $tf, int $from, int $to) use ($data, $mutateClosed): array {
                $bars = $data($to);
                $n = count($bars);
                foreach ($mutateClosed as $k => $v) {
                    $bars[$n - 2][$k] = $v; // the last CLOSED bar; the very last is still in-progress and excluded by fetchBars()
                }

                return $bars;
            };

            return new DeskContext([], 'live', false, [], new \DateTimeImmutable('@'.$ts), false, $provider);
        };

        IndicatorCache::forgetAll();
        $first = IndicatorCache::current($ctxAt($now, ['high' => 501.0]), 'SOL-USD', '1H', $atr)['value'];
        $sameBucketAfterCorrection = IndicatorCache::current($ctxAt($now + 1800, ['high' => 9999.0]), 'SOL-USD', '1H', $atr)['value'];
        IndicatorCache::forgetAll();
        $truth = IndicatorCache::current($ctxAt($now + 1800, ['high' => 9999.0]), 'SOL-USD', '1H', $atr)['value'];

        $this->assertNotEqualsWithDelta($truth, $first, 0.01, 'the low-high read must differ from truth, or this test proves nothing');
        $this->assertEqualsWithDelta($truth, $sameBucketAfterCorrection, 1e-9, 'a high-only correction in the same bucket must recompute, not stay pinned to the first read');
    }

    /**
     * Round-6 review, MAJOR 1: the round-5 fingerprint covered only the LAST bar, so a backfill
     * of a bar further back in the window (CandleStore::upsert() overwrites OHLCV on conflict —
     * its own docblock names this case) left the memo pinned even though the bar SET changed —
     * proven with a mid-series gap backfill leaving SMA unchanged. count($bars) and the first
     * bar's start are now folded in alongside the last bar's own fields: this scenario changes
     * count() (one more bar survives after the backfill) while the LAST bar itself is untouched,
     * isolating exactly what this fix adds over round 5's last-bar-only fingerprint.
     */
    #[Test]
    public function a_mid_series_gap_backfill_recomputes_even_though_the_last_bar_is_unchanged(): void
    {
        $mk = function (float $base, int $dur): \Closure {
            return function (int $now) use ($base, $dur): array {
                $bars = [];
                for ($i = 60; $i >= 0; $i--) {
                    $s = $now - $i * $dur;
                    $c = $base + (60 - $i) * 0.7; // monotonic ramp so any bar's presence/absence moves the SMA
                    $bars[] = ['start' => $s, 'open' => $c, 'high' => $c + 1, 'low' => $c - 1, 'close' => $c, 'volume' => 10.0];
                }

                return $bars;
            };
        };
        $data = $mk(500.0, 3600);
        $sma = IndicatorField::parse('ind.sma(10)');
        $now = 1_700_000_000 - (1_700_000_000 % 3600) + 5;

        $ctxAt = function (int $ts, bool $withGap) use ($data): DeskContext {
            $provider = function (string $pid, string $tf, int $from, int $to) use ($data, $withGap): array {
                $bars = $data($to);
                if ($withGap) {
                    // A bar 6 closed-bars back from "now" (well within the 10-bar SMA window, and
                    // nowhere near the very last closed bar itself) is missing — the exact shape of
                    // a mid-series gap, not the round-5 "just-closed bar" case.
                    array_splice($bars, count($bars) - 7, 1);
                }

                return $bars;
            };

            return new DeskContext([], 'live', false, [], new \DateTimeImmutable('@'.$ts), false, $provider);
        };

        IndicatorCache::forgetAll();
        $withGap = IndicatorCache::current($ctxAt($now, withGap: true), 'SOL-USD', '1H', $sma)['value'];
        $afterBackfill = IndicatorCache::current($ctxAt($now + 1800, withGap: false), 'SOL-USD', '1H', $sma)['value'];
        IndicatorCache::forgetAll();
        $truth = IndicatorCache::current($ctxAt($now + 1800, withGap: false), 'SOL-USD', '1H', $sma)['value'];

        $this->assertNotEqualsWithDelta($truth, $withGap, 0.001, 'the gapped read must differ from truth, or this test proves nothing');
        $this->assertEqualsWithDelta($truth, $afterBackfill, 1e-9, 'a mid-series gap backfill must recompute even though the last bar itself never changed');
    }
}
