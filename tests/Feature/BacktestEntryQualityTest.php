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
 * Entry review 10: every trade row carries mae_pct/mfe_pct (the worst/best price the position ever
 * saw, relative to its original entry) and fwd_1/3/5/10 (a naive hold's net return N strategy bars
 * after entry, after an approximate round-trip cost, null once the run ends before that bar).
 */
class BacktestEntryQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_trade_row_carries_mae_mfe_and_forward_returns(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // bar1 (entry, fills at its $1,000 open): flat. bar2: dips to $900 (the adverse excursion).
        // bar3: recovers to $1,200 (the favourable excursion), where the strategy closes on RISK call 3.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 1000, 'high' => 1000, 'low' => 900, 'close' => 900, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(3), 'open' => 900, 'high' => 1200, 'low' => 900, 'close' => 1200, 'volume' => 1]);

        config(['desk.strategies.close_on_call_test' => CloseOnCallStrategy::class]);
        $this->app->instance(CloseOnCallStrategy::class, new CloseOnCallStrategy(1000.0, closeOnCall: 3, closeRule: 'test_exit'));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('close_on_call_test', ['BTC-USD'], $from, $from->copy()->addHours(4), 10000.0, $overrides);

        $this->assertCount(1, $bt->trades);
        $trade = $bt->trades[0];

        $this->assertEqualsWithDelta(-10.0, $trade['mae_pct'], 1e-6, 'the $900 dip is a 10% adverse move from the $1,000 entry');
        $this->assertEqualsWithDelta(20.0, $trade['mfe_pct'], 1e-6, 'the $1,200 close is a 20% favourable move from the $1,000 entry');

        // fwd_1: one strategy bar (1h) after entry is bar2's close, $900 -> -10%.
        $this->assertEqualsWithDelta(-10.0, $trade['fwd_1'], 1e-6);
        // fwd_3/5/10: no bar exists that far past entry within this short window -- null, not a guess.
        $this->assertNull($trade['fwd_3']);
        $this->assertNull($trade['fwd_5']);
        $this->assertNull($trade['fwd_10']);

        $this->assertEqualsWithDelta(-10.0, $bt->stats['avg_mae_pct'], 1e-6);
        $this->assertEqualsWithDelta(20.0, $bt->stats['avg_mfe_pct'], 1e-6);
        $this->assertNull($bt->stats['avg_fwd_5'], 'no trade ever reached a fwd_5 bar, so the average has nothing to average');
        $this->assertSame(1, $bt->stats['entry_count']);
        $this->assertSame(0, $bt->stats['add_count']);
    }
}
