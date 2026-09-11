<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\MeanReversionStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * mr's z-score math and scan() gating, on hand-built bar series.
 *
 * A window of (n-1) identical baseline values plus one differing "current bar" value has a
 * population z-score for that current bar of exactly sign(diff) * sqrt(n-1), independent of how
 * big the difference is (only two distinct values exist in the window, so the shape is fixed).
 * That fact is what makes fixtures below exact instead of approximate — the lookback-5 fixtures
 * additionally use baseline/outlier pairs whose mean and variance land on exact binary fractions
 * (100/100/100/100/20 -> mean 84, variance 1024, std 32) so the boundary assertions (z <= -entry_z
 * at z == -2.0 exactly) don't trip on floating-point noise from a merely-close mean like 9.2:
 *   lookback 5  -> |z| = sqrt(4)  = 2.0
 *   lookback 10 -> |z| = sqrt(9)  = 3.0
 *   lookback 17 -> |z| = sqrt(16) = 4.0
 */
class MeanReversionSignalTest extends TestCase
{
    use RefreshDatabase;

    /** One leading throwaway bar (dropped by the lookback window) plus the given window closes. */
    private function barsWindow(array $windowCloses, int $dur = 60): array
    {
        $closes = array_merge([999.0], $windowCloses);
        $bars = [];
        $start = 0;
        foreach ($closes as $c) {
            $bars[] = ['start' => $start, 'open' => $c, 'high' => $c, 'low' => $c, 'close' => $c];
            $start += $dur;
        }

        return $bars;
    }

    private function ctxFor(MeanReversionStrategy $strategy, array $mr, array $bars, ?\DateTimeImmutable $now = null): DeskContext
    {
        $params = array_replace_recursive($strategy->defaults(), ['mr' => $mr]);

        return new DeskContext($params, 'paper', false, [], $now ?? new \DateTimeImmutable('2026-01-01 00:00:00'), false, fn () => $bars);
    }

    private function stats(string $pid = 'BTC-USD'): ProductStats
    {
        return ProductStats::fromArray(['product_id' => $pid, 'price' => 10.0, 'volume_h24_usd' => 1000000]);
    }

    public function test_zscore_math_on_a_hand_built_series(): void
    {
        $series = MeanReversionStrategy::zseries([100, 100, 100, 100, 20], 5);

        $this->assertNull($series[0]);
        $this->assertNull($series[1]);
        $this->assertNull($series[2]);
        $this->assertNull($series[3]);
        $this->assertNotNull($series[4]);
        $this->assertEqualsWithDelta(84.0, $series[4]['mean'], 1e-9);
        $this->assertEqualsWithDelta(32.0, $series[4]['std'], 1e-9);
        $this->assertEqualsWithDelta(-2.0, $series[4]['z'], 1e-9);
    }

    public function test_long_signal_fires_at_z_at_or_below_negative_entry_z(): void
    {
        $strategy = new MeanReversionStrategy;
        $bars = $this->barsWindow([100, 100, 100, 100, 20]);   // z = -2.0
        $ctx = $this->ctxFor($strategy, ['lookback' => 5, 'entry_z' => 2.0], $bars);

        $out = $strategy->scan([$this->stats()], $ctx);

        $this->assertCount(1, $out);
        $this->assertSame('long', $out[0]->side);
        $this->assertEqualsWithDelta(-2.0, $out[0]->stats->extra['mr']['z'], 1e-9);
    }

    public function test_short_signal_only_when_allowed(): void
    {
        $strategy = new MeanReversionStrategy;
        $bars = $this->barsWindow([100, 100, 100, 100, 180]);   // z = +2.0

        $disallowed = $this->ctxFor($strategy, ['lookback' => 5, 'entry_z' => 2.0, 'allow_shorts' => false], $bars);
        $this->assertSame([], $strategy->scan([$this->stats()], $disallowed), 'no short candidates while mr.allow_shorts is false');

        $allowed = $this->ctxFor($strategy, ['lookback' => 5, 'entry_z' => 2.0, 'allow_shorts' => true], $bars);
        $out = $strategy->scan([$this->stats()], $allowed);
        $this->assertCount(1, $out);
        $this->assertSame('short', $out[0]->side);
    }

    public function test_short_entry_z_overrides_from_mr_short(): void
    {
        $strategy = new MeanReversionStrategy;

        // lookback 10: 9 baseline bars + 1 outlier -> |z| = sqrt(9) = 3.0, under the 3.5 override.
        $under = $this->barsWindow([...array_fill(0, 9, 10), 14]);
        $ctxUnder = $this->ctxFor($strategy, [
            'lookback' => 10, 'allow_shorts' => true, 'entry_z' => 2.0, 'short' => ['entry_z' => 3.5],
        ], $under);
        $this->assertSame([], $strategy->scan([$this->stats()], $ctxUnder), 'z=3.0 is under the mr.short.entry_z override of 3.5');

        // lookback 17: 16 baseline bars + 1 outlier -> |z| = sqrt(16) = 4.0, clears the override.
        $over = $this->barsWindow([...array_fill(0, 16, 10), 14]);
        $ctxOver = $this->ctxFor($strategy, [
            'lookback' => 17, 'allow_shorts' => true, 'entry_z' => 2.0, 'short' => ['entry_z' => 3.5],
        ], $over);
        $out = $strategy->scan([$this->stats()], $ctxOver);
        $this->assertCount(1, $out);
        $this->assertSame('short', $out[0]->side);
    }

    /** Backtest: a single strategy instance is reused for the whole run, so armCooldown()'s
     *  in-process $cooldownUntil (unchanged by finding 21's fix) is exactly what backtests rely on. */
    public function test_backtest_cooldown_blocks_reentry(): void
    {
        $strategy = new MeanReversionStrategy;
        $bars = $this->barsWindow([100, 100, 100, 100, 20]);   // z = -2.0, would otherwise re-signal long
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $params = array_replace_recursive($strategy->defaults(), ['mr' => ['lookback' => 5, 'entry_z' => 2.0, 'cooldown_bars' => 5, 'timeframe' => '1m']]);
        $ctx = new DeskContext($params, 'backtest', false, [], $now, true, fn () => $bars);

        $arm = new \ReflectionMethod($strategy, 'armCooldown');
        $arm->setAccessible(true);
        $arm->invoke($strategy, 'BTC-USD', $ctx, false);

        $this->assertSame([], $strategy->scan([$this->stats()], $ctx), 'cooldown blocks the same-bar re-entry');

        // cooldown_bars(5) * 1m = 300s; 301s later it has elapsed.
        $ctxLater = new DeskContext($ctx->params(), 'backtest', false, [], $now->modify('+301 seconds'), true, fn () => $bars);
        $out = $strategy->scan([$this->stats()], $ctxLater);
        $this->assertCount(1, $out, 'cooldown has elapsed');
    }

    /**
     * Live/paper: finding 21's actual bug. Risk and scan each resolve a fresh strategy instance
     * through the container, so an in-process field armed by one is invisible to the other; the
     * cooldown must instead be derived from the confirmed close recorded on `positions` (mode,
     * strategy, product_id, status=closed) -- exactly what Desk::close() writes after a real fill.
     */
    public function test_live_cooldown_is_shared_across_fresh_instances_via_the_confirmed_close(): void
    {
        $bars = $this->barsWindow([100, 100, 100, 100, 20]);   // z = -2.0, would otherwise re-signal long
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $riskInstance = new MeanReversionStrategy;
        $scanInstance = new MeanReversionStrategy;   // a different instance -- simulates a fresh container resolution
        $params = array_replace_recursive($riskInstance->defaults(), ['mr' => ['lookback' => 5, 'entry_z' => 2.0, 'cooldown_bars' => 5, 'timeframe' => '1m']]);
        $ctx = new DeskContext($params, 'paper', false, [], $now, false, fn () => $bars);

        // Simulate Desk::close(): a confirmed exit persists a closed position row.
        \App\Models\Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'closed',
            'opened_at' => $now->modify('-10 minutes'), 'closed_at' => $now,
        ]);

        $this->assertSame([], $scanInstance->scan([$this->stats()], $ctx), 'a DIFFERENT instance must see the cooldown armed by the confirmed close');

        // cooldown_bars(5) * 1m = 300s; 301s later it has elapsed -- a THIRD fresh instance too
        // (simulating a process restart: no instance shares memory with the one that closed it).
        $ctxLater = new DeskContext($ctx->params(), 'paper', false, [], $now->modify('+301 seconds'), false, fn () => $bars);
        $thirdInstance = new MeanReversionStrategy;
        $out = $thirdInstance->scan([$this->stats()], $ctxLater);
        $this->assertCount(1, $out, 'cooldown has elapsed');

        unset($riskInstance);
    }

    /** A proposed close (risk() returning a decision) must not itself arm the live cooldown --
     *  only Desk::close() recording a CONFIRMED, persisted close does. */
    public function test_live_scan_ignores_a_merely_proposed_close(): void
    {
        $strategy = new MeanReversionStrategy;
        $bars = $this->barsWindow([100, 100, 100, 100, 20]);
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $params = array_replace_recursive($strategy->defaults(), ['mr' => ['lookback' => 5, 'entry_z' => 2.0, 'cooldown_bars' => 5, 'timeframe' => '1m']]);
        $ctx = new DeskContext($params, 'paper', false, [], $now, false, fn () => $bars);

        // armCooldown() is invoked the way risk() invokes it on a proposed decision -- no confirmed
        // Position row exists, so live/paper must not treat the pair as cooling down at all.
        $arm = new \ReflectionMethod($strategy, 'armCooldown');
        $arm->setAccessible(true);
        $arm->invoke($strategy, 'BTC-USD', $ctx, false);

        $out = $strategy->scan([$this->stats()], $ctx);
        $this->assertCount(1, $out, 'a proposed decision alone must not arm the live cooldown');
    }
}
