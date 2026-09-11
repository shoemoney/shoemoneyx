<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs the real "mr" strategy through the Backtester on hand-built 1-minute candles.
 *
 * Both scenarios rely on one fact about a rolling population z-score: a window of (n-1)
 * identical baseline closes plus one differing "current" close has |z| = sqrt(n-1) for that
 * bar, regardless of how big the difference is (see MeanReversionSignalTest). That makes the
 * fixtures exact instead of approximate — verified against MeanReversionStrategy::zseries()
 * before being encoded here as fixed candle closes.
 *
 * mr's snapshot() only ever reads the last CLOSED bar (its own closed_bars_only
 * convention, to avoid signals peeking at a still-forming bar), so every signal computed off
 * bar N is acted on one step later by the Backtester: SCAN sees it at bar N+1 and the pending
 * order fills at bar N+2's open. Each scenario's comment walks the resulting timeline.
 *
 * The generic base vet chain (data quality, participation, fee viability, ...) that
 * MeanReversionStrategy::vet() delegates to is neutralised via overrides below; this test is
 * only exercising mr's own signal, sizing and exit rules.
 */
class MeanReversionBacktestTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRAL_OVERRIDES = [
        'mr.timeframe' => '1m',
        'mr.allow_shorts' => false,
        'mr.tsl_pct' => 0,
        'mr.max_hold_bars' => 0,
        'mr.cooldown_bars' => 5,
        'paper.slippage_bps' => 0,
        'fees.taker_rate' => 0.0001,
        'fees.maker_rate' => 0.0001,
        'fees.floor_usd' => 0,
        'fees.funding_hourly_pct' => 0,
        'fees.per_contract_usd' => 0,
        'fees.contract_usd' => 0,
        'perps.whole_contracts' => false,
        // Neutralise the generic base vet chain (mr.vet() falls through to it) — not under test here.
        'vet.min_candles_h1' => 0,
        'vet.max_pct_of_volume_24h' => 1000000,
        'vet.min_book_depth_multiple' => 0,
        'vet.max_breakeven_move_pct' => 1000,
        'scan.one_buyer_price_pct' => 1000000,
        'scan.min_buy_share' => 0,
        'scan.min_age_hours' => 0,
    ];

    /** @param array<int, float> $closes oldest -> newest; open[i] = close[i-1] (open[0] = closes[0]) */
    private function seedMinuteCandles(Carbon $from, array $closes): void
    {
        $prevClose = $closes[0];
        foreach ($closes as $i => $close) {
            $open = $i === 0 ? $close : $prevClose;
            Candle::create([
                'product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from->copy()->addMinutes($i),
                'open' => $open, 'high' => max($open, $close), 'low' => min($open, $close), 'close' => $close, 'volume' => 10,
            ]);
            $prevClose = $close;
        }
    }

    private function seedHourlyCandle(Carbon $from): void
    {
        // Closed a full hour before the window starts: the causality fix (Backtester never lets a
        // sub-hour step see the hourly bar it is still standing inside — see BacktesterCausalityTest)
        // means an hourly candle starting AT $from would never be visible to the generic base vet
        // chain's liquidity check within this test's short (well under one hour) window, and every
        // candidate would be rejected on volumeH24Usd<=0 before mr's own signal ever mattered.
        Candle::create([
            'product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->subHour(),
            'open' => 100, 'high' => 100, 'low' => 90, 'close' => 100, 'volume' => 100000,
        ]);
    }

    private function setUpMarket(Carbon $from, array $closes): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $this->seedHourlyCandle($from);
        $this->seedMinuteCandles($from, $closes);
    }

    public function test_a_spike_that_reverts_closes_on_mr_exit_with_positive_pnl(): void
    {
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');

        // lookback 5. mr's snapshot only ever reads the last CLOSED bar (its own "closed_bars_only"
        // convention), so a signal computed off bar N is acted on one step later: SCAN
        // sees the dip a bar after it prints and the fill lands a bar after that. 10 flat bars,
        // a dip to 80 that holds for 3 bars (z=-2.0 -> SCAN fires, then -1.22/-0.82 while the
        // fill sits underwater -> HOLD), then back to 100 for 2 bars (z=+1.22 -> past
        // mr.exit_z=0.3 -> mr_exit). Verified against zseries() before encoding here.
        $closes = [100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 80, 80, 80, 100, 100, 100];
        $this->setUpMarket($from, $closes);

        $bt = app(Backtester::class)->run('mr', ['BTC-USD'], $from, $from->copy()->addMinutes(count($closes)), 10000.0, self::NEUTRAL_OVERRIDES + [
            'mr.lookback' => 5,
        ]);

        $this->assertNull($bt->error);
        $this->assertCount(1, $bt->trades);
        $this->assertSame('mr_exit', $bt->trades[0]['rule']);
        $this->assertEqualsWithDelta(80.0, $bt->trades[0]['entry'], 1e-9);
        $this->assertGreaterThan(0.0, $bt->trades[0]['pnl_usd'], 'bought the dip at 80, sold the reversion at 100');
        $this->assertGreaterThan(10000.0, (float) $bt->ending_equity, 'a winning round trip grows equity');
    }

    public function test_a_spike_that_keeps_going_hits_mr_stop(): void
    {
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');

        // lookback 17: 16 flat bars, a dip to 99 (z=-4.0 -> SCAN fires a bar later), a mild bar
        // at 99.5 (fill lands here; z=-1.61 the bar after — past entry but short of
        // mr.stop_z=3.5, so it HOLDs), then a second leg down to 94 (z=-3.93 the bar after that
        // -> past stop) -> mr_stop. The moves stay small in real dollar terms (about -5.5% peak
        // to trough) on purpose, so risk.hard_stop_pct (-12%, kept active as a backstop the same
        // way a strategy's own backstop would) never races mr_stop to the close. Verified against
        // zseries() before encoding here.
        $closes = array_merge(array_fill(0, 16, 100.0), [99, 99.5, 94, 94]);
        $this->setUpMarket($from, $closes);

        $bt = app(Backtester::class)->run('mr', ['BTC-USD'], $from, $from->copy()->addMinutes(count($closes)), 10000.0, self::NEUTRAL_OVERRIDES + [
            'mr.lookback' => 17,
        ]);

        $this->assertNull($bt->error);
        $this->assertCount(1, $bt->trades);
        $this->assertSame('mr_stop', $bt->trades[0]['rule']);
        $this->assertEqualsWithDelta(99.5, $bt->trades[0]['entry'], 1e-9);
        $this->assertLessThan(0.0, $bt->trades[0]['pnl_usd'], 'the move never reverted — this trade is a loser by design');
    }
}
