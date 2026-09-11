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
 * Review finding 7: tradeRow() reconstructed PnL independently of the cash ledger and omitted the
 * exit fee and accumulated funding; whole-contract/margin entry fees were also outside its cost
 * basis. Fixed so a trade's net pnl_usd matches exactly what the cash ledger actually paid for it.
 */
class BacktestNetPnlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tiny_gross_win_that_fees_turn_into_a_net_loss_is_recorded_as_a_loss(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // A whisper-thin 0.05% gross gain (1000 -> 1000.50) against a 0.6% round-trip taker fee (the
        // desk's own default rate) is a gross win and a net loss.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000.5, 'low' => 1000, 'close' => 1000.5, 'volume' => 1]);

        config(['desk.strategies.instant_close_test' => InstantCloseStrategy::class]);
        $this->app->instance(InstantCloseStrategy::class, new InstantCloseStrategy(10000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.006, 'fees.maker_rate' => 0.006,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('instant_close_test', ['BTC-USD'], $from, $from->copy()->addHours(2), 10000.0, $overrides);

        $this->assertCount(1, $bt->trades);
        $trade = $bt->trades[0];

        // Gross: qty = (10000 - 60)/1000 = 9.94; gross proceeds = 9.94 * 1000.5 = 9944.97 -> a $4.97
        // gross gain on the entry's post-fee notional. Net of the exit's own 0.6% fee (~$59.67) it is
        // solidly negative -- a gross winner recorded as a net loser.
        $this->assertLessThan(0.0, $trade['pnl_usd'], 'fees turned this gross win into a net loss');
        $this->assertSame(0, $bt->stats['wins']);
        $this->assertSame(1, $bt->stats['losses']);

        // The trade's net pnl reconciles exactly to how much the cash ledger actually moved: starting
        // cash 10,000 minus ending equity is this trade's full lifecycle cost (there is nothing else
        // open and nothing else happened).
        $this->assertEqualsWithDelta((float) $bt->ending_equity - 10000.0, $trade['pnl_usd'], 1e-6);
    }

    public function test_net_trade_pnl_sums_reconcile_to_the_equity_change(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1100, 'low' => 1000, 'close' => 1100, 'volume' => 1]);

        config(['desk.strategies.instant_close_test' => InstantCloseStrategy::class]);
        $this->app->instance(InstantCloseStrategy::class, new InstantCloseStrategy(5000.0));

        $overrides = [
            'paper.slippage_bps' => 10, 'fees.taker_rate' => 0.004, 'fees.maker_rate' => 0.004,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('instant_close_test', ['BTC-USD'], $from, $from->copy()->addHours(2), 10000.0, $overrides);

        $netTradePnl = array_sum(array_column($bt->trades, 'pnl_usd'));
        // No open balance left unaccounted for (the fixture always closes what it opens), so summed
        // net trade pnl must equal the full cash change end to end.
        $this->assertEqualsWithDelta((float) $bt->ending_equity - 10000.0, $netTradePnl, 1e-6);
    }
}
