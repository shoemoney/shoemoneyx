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
use Tests\TestCase;

/**
 * Backtester entry/exit fills for a perps-mapped product, routed through Lot when
 * perps.whole_contracts is on. BTC-USD's nano contract is 0.01 BTC (config/desk.php
 * perps.map), matching LotTest and PaperWholeContractsTest's own $80,000 fixtures.
 *
 * The fixture strategy (Tests\Feature\Fixtures\FixedTicketStrategy) proposes one ticket
 * on the first SCAN and holds forever, so the position survives to end-of-test
 * liquidation at the unchanged entry price (slippage is zeroed) -- this pins down a
 * closed-form ending_equity for every case below, since the trade row itself carries
 * no raw quantity field.
 */
class BacktestWholeContractsTest extends TestCase
{
    use RefreshDatabase;

    private function runBacktest(float $ticketUsd, bool $wholeContracts): Backtest
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        // Only the bar the entry fills against needs to exist: the fixture strategy never reads
        // ProductStats, so the builder finding no candles (price 0) for every other hour is fine.
        Candle::create([
            'product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(),
            'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1,
        ]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy($ticketUsd));

        $overrides = [
            'paper.slippage_bps' => 0,
            'fees.taker_rate' => 0.0002,
            'fees.per_contract_usd' => 0.15,
            // Zeroed so the legacy path's per-contract-floor proxy (ceil(notional / contract_usd) *
            // per_contract_usd) doesn't kick in on top of the taker rate; .env defaults it to $800.
            'fees.contract_usd' => 0,
            // Zeroed so hourly funding (see BacktestFeesTest) doesn't perturb these exact-equity assertions.
            'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => $wholeContracts,
        ];

        return app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);
    }

    public function test_whole_contract_entry_floors_to_the_nano_lot(): void
    {
        $bt = $this->runBacktest(2500.0, true);

        $this->assertCount(1, $bt->trades);
        $trade = $bt->trades[0];
        // floor(2500 / (0.01 BTC * $80,000)) = 3 contracts = $2,400 notional. A whole-contract entry's
        // fee is paid separately from that notional (never out of qty), so it is not in entry_usd --
        // finding 7's fix folds it into the trade row's cost basis anyway: $2,400 + entry fee ($0.48,
        // max(2400*0.0002, 3*0.15)) = $2,400.48.
        $this->assertEqualsWithDelta(2400.48, $trade['usd'], 1e-9);
        $this->assertEqualsWithDelta(80000.0, $trade['entry'], 1e-9);
        $this->assertSame(0, $bt->stats['under_one_contract']);
        // Round trip at an unchanged price still pays entry + exit fees: 10000 - 0.48 - 0.48.
        $this->assertEqualsWithDelta(9999.04, (float) $bt->ending_equity, 1e-6);
        // Net trade pnl (finding 7): price didn't move, so the only cost is the entry fee (excluded from
        // entry_usd above, so tradeRow subtracts it explicitly) and the exit fee (passed in explicitly).
        $this->assertEqualsWithDelta(-0.96, $trade['pnl_usd'], 1e-9);
    }

    public function test_ticket_under_one_contract_is_skipped_not_shrunk(): void
    {
        $bt = $this->runBacktest(500.0, true);

        $this->assertCount(0, $bt->trades);
        $this->assertSame(1, $bt->stats['under_one_contract']);
        $this->assertEqualsWithDelta(10000.0, (float) $bt->ending_equity, 1e-9, 'no position opened, no cash moved');
    }

    public function test_whole_contracts_off_keeps_the_fractional_fill(): void
    {
        $bt = $this->runBacktest(2500.0, false);

        $this->assertCount(1, $bt->trades);
        $trade = $bt->trades[0];
        // Legacy path spends the full ticket (fee comes out of quantity, not notional) -- $2,500,
        // not the $2,400 floored notional from the whole-contract case above.
        $this->assertEqualsWithDelta(2500.0, $trade['usd'], 1e-9);
        $this->assertEqualsWithDelta(80000.0, $trade['entry'], 1e-9);
        $this->assertSame(0, $bt->stats['under_one_contract']);
        // qty = (2500 - 0.5) / 80000; round trip at the unchanged price: 10000 - 0.5 - 0.4999.
        $this->assertEqualsWithDelta(9999.0, (float) $bt->ending_equity, 1e-6);
    }
}
