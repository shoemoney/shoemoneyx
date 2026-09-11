<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\TrimAndFundStrategy;
use Tests\TestCase;

/**
 * Review finding 11 (the Backtester side): Lot::forQty()'s $legacyFractional flag (landed
 * separately) defaults to false, so a non-integral trim request against a whole-contract holding
 * floors to whole contracts instead of falling back to a fractional "legacy" fill. The
 * backtester's four call sites (Backtester.php:335, 374, 441, 477) keep that default.
 */
class BacktestLotSizingTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProduct(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    public function test_a_half_trim_of_three_contracts_sells_exactly_one_whole_contract(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(3);

        // bar1 (entry): flat at $80,000 -- 3 whole nano contracts (0.03 BTC) for $2,400. bar2: $90,000,
        // where the strategy trims exactly half (a fractional 1.5-contract request).
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 80000, 'high' => 90000, 'low' => 80000, 'close' => 90000, 'volume' => 1]);

        config(['desk.strategies.trim_fund_test' => TrimAndFundStrategy::class]);
        $this->app->instance(TrimAndFundStrategy::class, new TrimAndFundStrategy(2400.0, 'BTC-USD', 'long', trimOnCall: 2, trimFraction: 0.5, trimLimitPrice: null));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.maker_rate' => 0.0002,
            'fees.per_contract_usd' => 0.15, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => true, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('trim_fund_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        $this->assertSame(1, $bt->stats['trims'], 'the half-trim executed rather than being skipped as sub-contract');
        $this->assertSame(0, $bt->stats['sub_contract_trims']);

        // Entry: 3 contracts @ $80,000 = $2,400, fee = max(2400*0.0002, 3*0.15) = $0.48.
        // Trim (1.5 contracts requested, floors to 1): sold 1 contract @ $90,000 = $900, cost sold =
        // $2,400 * (0.01/0.03) = $800, fee = max(900*0.0002, 1*0.15) = $0.18. pnl = (900-800)-0.18 = 99.82.
        // Remaining 2 contracts (not 1.5!) close at end-of-test, still $90,000: gross = $1,800, cost =
        // $1,600, fee = max(1800*0.0002, 2*0.15) = $0.36. pnl = (1800-1600)-0.36 = 199.64.
        // ending_equity = 10,000 - 2,400.48 + (800+99.82) + (1,600+199.64) = 10,298.98.
        $this->assertEqualsWithDelta(10298.98, (float) $bt->ending_equity, 1e-2);
    }

    public function test_a_trim_request_under_one_contract_is_skipped_with_a_named_reason_not_silently(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(3);

        // A single contract (0.01 BTC, $800). Trimming 10% of it requests 0.001 BTC -- under one
        // contract -- which cannot fill as a whole-contract rung.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1]);

        config(['desk.strategies.trim_fund_test' => TrimAndFundStrategy::class]);
        $this->app->instance(TrimAndFundStrategy::class, new TrimAndFundStrategy(800.0, 'BTC-USD', 'long', trimOnCall: 2, trimFraction: 0.1, trimLimitPrice: null));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.maker_rate' => 0.0002,
            'fees.per_contract_usd' => 0.15, 'fees.contract_usd' => 0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => true, 'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('trim_fund_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // The rung is explicitly counted and skipped, not silently dropped or filled fractionally.
        $this->assertSame(0, $bt->stats['trims']);
        $this->assertSame(1, $bt->stats['sub_contract_trims']);

        // The full 1 contract survives untouched to end-of-test, at an unchanged price -- the only
        // cost is the entry fee (excluded from entry_usd, so it's in the basis) plus the exit fee, both
        // max(800*0.0002, 1*0.15) = $0.16. Nothing was skimmed off by the skipped rung along the way.
        $this->assertCount(1, $bt->trades);
        $this->assertEqualsWithDelta(800.16, $bt->trades[0]['usd'], 1e-9);
        $this->assertEqualsWithDelta(-0.32, $bt->trades[0]['pnl_usd'], 1e-9);
    }
}
