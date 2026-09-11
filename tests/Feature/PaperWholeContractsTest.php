<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Execution\PaperExecutor;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\PaperLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperWholeContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This class predates margin paper accounting; its assertions are calibrated to the old cash-notional /
        // 1x-collateral paths. PaperMarginTest covers the paper_margin-on paths.
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true, 'desk.perps.paper_margin' => false, 'desk.paper.starting_cash' => 10000]);

        // PaperExecutor reads ticker() straight through; stub it so the fill price is fixed regardless
        // of whatever the live feeder currently has in Redis for BTC-USD.
        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }
        });

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0002);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_buy_fills_whole_contracts_and_debits_notional_plus_fee(): void
    {
        $ex = app(PaperExecutor::class);
        $start = $ex->cash();

        $order = $ex->buy('BTC-USD', 2500, 80000);

        $this->assertTrue($order->ok());
        $this->assertEqualsWithDelta(0.03, $order->filledQty, 1e-12);       // floor(2500 / (0.01 * 80000)) = 3 contracts
        $this->assertEqualsWithDelta(2400.0, $order->filledUsd, 1e-9);      // 3 contracts * 0.01 BTC * $80,000
        $this->assertEqualsWithDelta(0.48, $order->feeUsd, 1e-9);           // 2400 * 0.02%

        $this->assertEqualsWithDelta($start - ($order->filledUsd + $order->feeUsd), $ex->cash(), 1e-9);
    }

    public function test_buy_under_one_contract_rejects_without_touching_the_ledger(): void
    {
        $ex = app(PaperExecutor::class);
        $ex->cash();
        $before = PaperLedger::count();

        $order = $ex->buy('BTC-USD', 500, 80000);

        $this->assertFalse($order->ok());
        $this->assertSame('rejected', $order->status);
        $this->assertSame('under one contract', $order->note);
        $this->assertSame($before, PaperLedger::count());
    }

    public function test_whole_contracts_off_restores_the_fractional_fill(): void
    {
        config(['desk.perps.whole_contracts' => false]);
        $ex = app(PaperExecutor::class);
        $start = $ex->cash();

        $order = $ex->buy('BTC-USD', 2500, 80000);

        $this->assertTrue($order->ok());
        $this->assertEqualsWithDelta(2500.0, $order->filledUsd, 1e-9);
        $fee = 2500 * 0.0002;
        $this->assertEqualsWithDelta((2500 - $fee) / 80000, $order->filledQty, 1e-9);
        $this->assertEqualsWithDelta($start - 2500.0, $ex->cash(), 1e-9);
    }

    public function test_open_short_sizes_whole_contracts_and_round_trip_nets_to_true_pnl(): void
    {
        $ex = app(PaperExecutor::class);
        $start = $ex->cash();

        $open = $ex->openShort('BTC-USD', 2500, 80000);
        $this->assertTrue($open->ok());
        $this->assertEqualsWithDelta(0.03, $open->filledQty, 1e-12);
        $this->assertEqualsWithDelta(2400.0, $open->filledUsd, 1e-9);
        $this->assertEqualsWithDelta(0.48, $open->feeUsd, 1e-9);

        $cover = $ex->coverShort('BTC-USD', $open->filledQty, 80000, $open->filledUsd);
        $this->assertTrue($cover->ok());
        // coverShort() defers its ledger write so Desk::close()/trim() can run it inside their own DB
        // transaction (execution-boundary finding 3); a direct caller runs it explicitly.
        $cover->writeLedger();

        $truePnl = $open->filledUsd - $open->feeUsd - $cover->filledUsd;
        $this->assertEqualsWithDelta($start + $truePnl, $ex->cash(), 1e-9);
    }
}
