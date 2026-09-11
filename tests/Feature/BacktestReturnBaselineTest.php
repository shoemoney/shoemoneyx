<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\InstantCloseStrategy;
use Tests\TestCase;

/**
 * Review finding 9: the first equity_curve point used to be recorded several steps into a run
 * (the periodic sampler fires every 4 hourly bars), yet it was passed to stats() as startEq. A
 * short run whose only sample landed on its own terminal equity reported a 0% return no matter
 * what the starting cash actually became. Fixed by preserving the true starting cash separately
 * and sampling an explicit point before execution and after final liquidation.
 */
class BacktestReturnBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_bar_round_trip_reports_its_true_return_not_zero(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // One hourly bar only: the entry fills at its $1,000 open and the fixture closes on the very
        // same step's RISK call at its $1,080 close -- an 8% gross move with every cost zeroed out.
        // Before finding 9's fix, the periodic sampler's only chance to fire in a run this short was
        // the trailing "no bar here" iteration after the window closed, which recorded the ALREADY-
        // final equity as the one and only curve point -- stats() then divided that point by itself
        // and reported 0%, no matter what the round trip actually made.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1080, 'low' => 1000, 'close' => 1080, 'volume' => 1]);

        config(['desk.strategies.instant_close_test' => InstantCloseStrategy::class]);
        $this->app->instance(InstantCloseStrategy::class, new InstantCloseStrategy(10000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('instant_close_test', ['BTC-USD'], $from, $from->copy()->addHours(2), 10000.0, $overrides);

        // qty = 10000/1000 = 10. gross = 10*1080 = 10800. pnl = 800. cash = 0 + 10000 + 800 = 10800.
        $this->assertEqualsWithDelta(10800.0, (float) $bt->ending_equity, 1e-6);
        $this->assertEqualsWithDelta(8.0, $bt->stats['total_return_pct'], 1e-6);

        // The curve must carry the true starting cash as its first point, before any execution --
        // not a value already several steps into the run.
        $this->assertEqualsWithDelta(10000.0, (float) $bt->equity_curve[0][1], 1e-6);
        $this->assertSame($from->getTimestamp(), $bt->equity_curve[0][0]);

        // ...and the final, post-liquidation equity as its last point.
        $last = $bt->equity_curve[count($bt->equity_curve) - 1];
        $this->assertEqualsWithDelta(10800.0, (float) $last[1], 1e-6);
    }

    public function test_a_loss_before_the_first_sampling_interval_still_counts(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 920, 'close' => 920, 'volume' => 1]);

        config(['desk.strategies.instant_close_test' => InstantCloseStrategy::class]);
        $this->app->instance(InstantCloseStrategy::class, new InstantCloseStrategy(10000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('instant_close_test', ['BTC-USD'], $from, $from->copy()->addHours(2), 10000.0, $overrides);

        // qty = 10, gross = 9200, pnl = -800 -> a -8% loss taken entirely inside the first hour, well
        // before the periodic sampler's first 4-hour tick.
        $this->assertEqualsWithDelta(9200.0, (float) $bt->ending_equity, 1e-6);
        $this->assertEqualsWithDelta(-8.0, $bt->stats['total_return_pct'], 1e-6);
        $this->assertGreaterThan(0.0, $bt->stats['max_drawdown_pct'], 'the drawdown the loss caused must show up too');
    }
}
