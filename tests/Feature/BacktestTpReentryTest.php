<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\ReentryFixtureStrategy;
use Tests\TestCase;

/**
 * mr.tp_reentry (strategy review: take-profit ladder shape): a RiskDecision::ADD from RISK books
 * through the exact same fee/quantity/average path an ordinary SCAN-driven add uses ($bookAdd in
 * Backtester). Reuses BacktestFeesTest's scaffolding, with an oscillating price: entry, a 50% rung
 * trim on the way up, then a re-entry buy on the way back down, each leg paying the taker rate.
 */
class BacktestTpReentryTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProduct(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    public function test_a_risk_driven_reentry_add_books_fees_and_grows_the_position_like_an_ordinary_add(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // +4h, not +3h: 3 RISK bars (entry hold, trim, reentry add) need `to` past the last one (half-open [from, to)).
        $to = $from->copy()->addHours(4);

        // bar1 (entry fills here): flat at $80,000. bar2: closes at $82,000 -- the TP rung fires there
        // (50%). bar3: price falls back to $81,000, still above the $80,000 average -- the fixture
        // fires a $1,000 tp_reentry add there.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 80000, 'high' => 85000, 'low' => 80000, 'close' => 82000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(3), 'open' => 82000, 'high' => 82000, 'low' => 80500, 'close' => 81000, 'volume' => 1]);

        config(['desk.strategies.reentry_test' => ReentryFixtureStrategy::class]);
        $this->app->instance(ReentryFixtureStrategy::class, new ReentryFixtureStrategy(
            8000.0, 'BTC-USD', 'long', trimOnCall: 2, trimFraction: 0.5, addOnCall: 3, addDollars: 1000.0,
        ));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.maker_rate' => 0.001,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false,
        ];

        $bt = app(Backtester::class)->run('reentry_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // Every leg (entry, trim, reentry add, and the end-of-test close on what's left) pays the
        // taker rate -- nothing here rests at a limit price the bar has to trade through.
        $this->assertSame(4, $bt->stats['taker_fills']);
        $this->assertSame(0, $bt->stats['maker_fills']);
        $this->assertSame(1, $bt->stats['trims']);

        $trade = $bt->trades[0];
        $this->assertSame(1, $trade['adds'], 'the reentry add incremented adds_count exactly like an ordinary add');
        $this->assertSame(1, $trade['trims']);

        // qty = (8000 - 1.6) / 80000 = 0.09998. Trim 50% at $82,000 (market, no rung limit set):
        // gross = 0.04999 * 82000 = 4099.18, fee = 4099.18 * 0.0002 = 0.819836, cost sold = 4000.
        // pnl = (4099.18 - 4000) - 0.819836 = 98.360164. cash = (10000 - 8000) + 4000 + 98.360164 = 6098.360164.
        // Reentry buys $1,000 at $81,000: fee = 0.2, qty += (1000 - 0.2) / 81000 = 0.012338...,
        // entry_usd = 4000 + 1000 = 5000, cash -= 1000 = 5098.360164.
        // End of test liquidates the remainder at the frozen last price ($81,000):
        // qty = 0.062333..., gross = 5048.99, fee = 1.009798, pnl = 47.980202.
        // ending_equity = 5098.360164 + 5000 + 47.980202 = 10146.340366 -> 10146.34.
        $this->assertEqualsWithDelta(10146.34, (float) $bt->ending_equity, 1e-2);
    }
}
