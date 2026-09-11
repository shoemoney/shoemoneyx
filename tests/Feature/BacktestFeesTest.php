<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\Feature\Fixtures\TrimAndFundStrategy;
use Tests\TestCase;

/**
 * Cost realism: a TP rung that fills at its resting limit price is charged the maker rate instead of
 * the taker rate every other fill uses, and every open position accrues hourly funding on its
 * notional (longs pay, shorts receive). Reuses BacktestWholeContractsTest's scaffolding.
 */
class BacktestFeesTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProduct(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    public function test_a_trim_that_fills_at_the_rung_is_charged_the_maker_rate(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(3);

        // bar1 (entry fills here): flat at $80,000. bar2: closes at $82,000 -- the TP rung rests there
        // and the bar trades through it, so the trim fills at the rung, not at a slipped market price.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 80000, 'high' => 85000, 'low' => 80000, 'close' => 82000, 'volume' => 1]);

        config(['desk.strategies.trim_fund_test' => TrimAndFundStrategy::class]);
        // RISK call 1 is the entry bar (hold); call 2 is bar2, where the strategy trims 50% at the $82,000 rung.
        $this->app->instance(TrimAndFundStrategy::class, new TrimAndFundStrategy(8000.0, 'BTC-USD', 'long', trimOnCall: 2, trimFraction: 0.5, trimLimitPrice: 82000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.maker_rate' => 0.001,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false,
        ];

        $bt = app(Backtester::class)->run('trim_fund_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        $this->assertSame(1, $bt->stats['maker_fills'], 'the $82,000 rung trim');
        $this->assertSame(2, $bt->stats['taker_fills'], 'the entry and the end-of-test close on the remainder');

        // qty = (8000 - 8000*0.0002) / 80000 = 0.09998. Trim sells half at the $82,000 rung:
        // gross = 0.04999 * 82000 = 4099.18, maker fee = 4099.18 * 0.001 = 4.09918, cost sold = 4000.
        // pnl = (4099.18 - 4000) - 4.09918 = 95.08082. cash = (10000 - 8000) + 4000 + 95.08082 = 6095.08082.
        // Remainder (0.04999 @ $82,000, entry_usd 4000) liquidates end-of-test at the taker rate:
        // gross = 4099.18, fee = 4099.18 * 0.0002 = 0.819836, pnl = 98.360164.
        // ending_equity = 6095.08082 + 4000 + 98.360164 = 10193.440984.
        $this->assertEqualsWithDelta(10193.44, (float) $bt->ending_equity, 1e-6);
    }

    public function test_an_open_long_accrues_hourly_funding_on_its_notional(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // +4h, not +3h: the simulation window is half-open [from, to) (finding 8), so 3 RISK bars
        // (at from+1h, +2h, +3h) need `to` past the last one to all run.
        $to = $from->copy()->addHours(4);
        // Only the entry bar needs a candle: the builder freezes price at its last close for every
        // later hour (see BacktestWholeContractsTest), so notional stays constant across the 3 RISK bars.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(8000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0,
            'fees.funding_hourly_pct' => 0.01, 'perps.whole_contracts' => false,
        ];

        $bt = app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // qty = (8000 - 1.6) / 80000 = 0.09998; notional = 0.09998 * 80000 = 7998.4, held open for 3 hourly bars.
        // funding/hour = 7998.4 * 0.01% = 0.79984; over 3 hours a long pays 3 * 0.79984 = 2.39952 -> 2.40.
        $this->assertEqualsWithDelta(2.4, $bt->stats['funding_usd'], 1e-9);
    }

    public function test_an_open_short_receives_funding_when_the_rate_is_positive(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // +4h, not +3h: see the long test above -- the window is half-open [from, to).
        $to = $from->copy()->addHours(4);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(8000.0, 'BTC-USD', 'short'));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0,
            'fees.funding_hourly_pct' => 0.01, 'perps.whole_contracts' => false,
        ];

        $bt = app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // Same magnitude as the long case, opposite sign: a short is paid funding when the rate is positive.
        $this->assertEqualsWithDelta(-2.4, $bt->stats['funding_usd'], 1e-9);
    }
}
