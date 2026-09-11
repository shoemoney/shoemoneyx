<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\CloseOnCallStrategy;
use Tests\TestCase;

/**
 * Strategy review 10: exit_touch_policy ('optimistic', default, vs 'conservative') decides which
 * side wins when one bar's range reaches both a stop (risk() stashes it in meta['stop_price']) and
 * a trim's target (limitPrice). Conservative processes the stop first -- closing the whole position
 * there -- instead of the target risk() itself asked for.
 */
class BacktestTouchPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\Backtest,1:int} [$bt, $bothTouchedBars] for a given policy */
    private function runOneBar(string $policy): array
    {
        config(['cache.default' => 'array']);
        Product::query()->firstOrCreate(['product_id' => 'BTC-USD'], ['base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Candle::query()->where('product_id', 'BTC-USD')->delete();

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // The hand-built bar: entry fills at its $1,000 open; its own $900-$1,100 range reaches both
        // the $1,100 target and the $900 stop in the same minute.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from, 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from->copy()->addMinute(), 'open' => 1000, 'high' => 1100, 'low' => 900, 'close' => 1000, 'volume' => 1]);

        config(['desk.strategies.close_on_call_test' => CloseOnCallStrategy::class]);
        $this->app->instance(CloseOnCallStrategy::class, new CloseOnCallStrategy(
            1000.0, closeOnCall: 999, trimOnCall: 1, trimFraction: 1.0, trimLimitPrice: 1100.0, stopPrice: 900.0,
        ));

        $overrides = [
            'backtest.step' => '1m',
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
            'mr.exit_touch_policy' => $policy,
        ];

        $bt = app(Backtester::class)->run('close_on_call_test', ['BTC-USD'], $from, $from->copy()->addMinutes(2), 10000.0, $overrides);

        return [$bt, $bt->stats['both_touched_bars']];
    }

    public function test_conservative_policy_processes_the_stop_first(): void
    {
        [$bt, $bothTouched] = $this->runOneBar('conservative');

        $this->assertSame(1, $bothTouched, 'the bar touched both the target and the stop');
        $this->assertCount(1, $bt->trades);
        $this->assertSame('stop_before_target', $bt->trades[0]['rule']);
        $this->assertEqualsWithDelta(900.0, $bt->trades[0]['exit'], 1e-9, 'conservative closes at the stop, not the target');
        // qty = 1000/1000 = 1. gross = 1*900 = 900. pnl = 900 - 1000 = -100. equity = 10,000 - 100.
        $this->assertEqualsWithDelta(9900.0, (float) $bt->ending_equity, 1e-6);
    }

    public function test_optimistic_policy_keeps_todays_behaviour(): void
    {
        [$bt, $bothTouched] = $this->runOneBar('optimistic');

        $this->assertSame(1, $bothTouched, 'both_touched_bars is recorded regardless of which policy is active');
        $this->assertCount(1, $bt->trades);
        $this->assertSame('tp', $bt->trades[0]['rule']);
        $this->assertEqualsWithDelta(1100.0, $bt->trades[0]['exit'], 1e-9, 'optimistic (default) fills the target risk() asked for');
        // gross = 1*1100 = 1,100. pnl = 1,100 - 1,000 = 100. equity = 10,000 + 100.
        $this->assertEqualsWithDelta(10100.0, (float) $bt->ending_equity, 1e-6);
    }

    public function test_default_policy_is_optimistic_when_unset(): void
    {
        config(['cache.default' => 'array']);
        Product::query()->firstOrCreate(['product_id' => 'BTC-USD'], ['base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Candle::query()->where('product_id', 'BTC-USD')->delete();

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from, 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from->copy()->addMinute(), 'open' => 1000, 'high' => 1100, 'low' => 900, 'close' => 1000, 'volume' => 1]);

        config(['desk.strategies.close_on_call_test' => CloseOnCallStrategy::class]);
        $this->app->instance(CloseOnCallStrategy::class, new CloseOnCallStrategy(
            1000.0, closeOnCall: 999, trimOnCall: 1, trimFraction: 1.0, trimLimitPrice: 1100.0, stopPrice: 900.0,
        ));

        $overrides = [
            'backtest.step' => '1m',
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
            // mr.exit_touch_policy intentionally not set.
        ];

        $bt = app(Backtester::class)->run('close_on_call_test', ['BTC-USD'], $from, $from->copy()->addMinutes(2), 10000.0, $overrides);

        $this->assertSame('tp', $bt->trades[0]['rule'], 'unset defaults to optimistic, unchanged from before this feature');
    }
}
