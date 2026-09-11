<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\LegacyFractionalHoldingStrategy;
use Tests\TestCase;

/**
 * Review fix: Lot::forQty now returns a ZERO lot (contracts 0, qty 0, gross 0) for a sub-contract
 * exit request instead of a fractional fallback. The trim path already guards this (a rung that
 * floors to zero contracts is skipped, not filled) but CLOSE, LIQUIDATION and END-OF-TEST used the
 * zero lot as-is: gross = 0 made pnlPart = -entry_usd, booking a phantom 100% loss on a position
 * that was never actually sold.
 *
 * This test opens a 0.0015 BTC position while BTC-USD's contract_size is small enough for that to
 * be a whole number of contracts, then -- via the fixture strategy -- widens contract_size to the
 * real 0.01 nano-contract before the position closes, so the 0.0015 BTC holding becomes a
 * sub-contract quantity under the new spec. That is exactly the "legacy holding" shape Lot::forQty's
 * own docblock describes: a genuinely fractional quantity that is not a whole number of contracts
 * under the currently-configured size. The end-of-test exit must fall back to a fractional close of
 * the whole remaining holding (nothing stranded) and count it in fractional_exits, rather than
 * booking a bogus full loss.
 */
class BacktestSubContractExitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sub_contract_holding_closes_for_its_real_pnl_not_a_100_percent_loss(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        Candle::create([
            'product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(),
            'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1,
        ]);

        // Entry contract_size (0.0001 BTC) makes a $120 ticket at $80,000 fill exactly 15 contracts
        // = 0.0015 BTC. The fixture then widens contract_size to the real 0.01 BTC nano-contract on
        // the first RISK call for the position, so 0.0015 BTC (0.15 of a real contract) is stuck
        // below one whole contract for every exit check from then on.
        config(['desk.perps.map.BTC-USD' => ['product_id' => 'BIP-20DEC30-CDE', 'contract_size' => 0.0001]]);

        config(['desk.strategies.legacy_fractional_holding_test' => LegacyFractionalHoldingStrategy::class]);
        $this->app->instance(LegacyFractionalHoldingStrategy::class, new LegacyFractionalHoldingStrategy(120.0, 'BTC-USD', 0.01));

        $overrides = [
            'paper.slippage_bps' => 0,
            'fees.taker_rate' => 0.0002,
            'fees.per_contract_usd' => 0.0,
            'fees.contract_usd' => 0,
            'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => true,
        ];

        /** @var Backtest $bt */
        $bt = app(Backtester::class)->run('legacy_fractional_holding_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        $this->assertCount(1, $bt->trades);
        $trade = $bt->trades[0];

        // Entry: 15 contracts * 0.0001 BTC = 0.0015 BTC @ $80,000 = $120 notional, plus the $0.024
        // entry fee folded into the trade row's cost basis (whole-contract entries pay it separately
        // from entryUsd -- same convention as BacktestWholeContractsTest).
        $this->assertEqualsWithDelta(120.02, $trade['usd'], 1e-9);
        $this->assertSame('end_of_test', $trade['rule']);

        $this->assertSame(1, $bt->stats['fractional_exits']);

        // The bug lived in the equity/cash bookkeeping, not the trade row's own pnl (which is
        // computed from the position's true quantity regardless of the lot): a zero lot's gross = 0
        // meant the $120 sunk into the position was never credited back on exit, so ending equity
        // silently ate the whole position (10,000 - 120 - fees ≈ 9,879.95) instead of just the
        // $0.048 in round-trip fees. Pin ending equity directly against both outcomes.
        $this->assertEqualsWithDelta(9999.95, (float) $bt->ending_equity, 1e-6);
        $this->assertNotEqualsWithDelta(9879.95, (float) $bt->ending_equity, 1.0, 'must not book a phantom 100% loss on a position that was never sold');
    }
}
