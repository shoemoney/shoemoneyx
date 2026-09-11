<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Execution\PaperExecutor;
use App\Desk\Execution\Perps;
use App\Models\PaperLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerpsShortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This class predates whole-contract paper sizing; its round-trip assertions are calibrated to
        // fractional fills. PaperWholeContractsTest covers the whole-contracts-on paths.
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true, 'desk.perps.whole_contracts' => false, 'desk.perps.paper_margin' => false, 'desk.paper.starting_cash' => 10000]);
        Http::fake([
            'api.coinbase.com/api/v3/brokerage/market/products/*/ticker*' => Http::response(['best_bid' => '79990', 'best_ask' => '80010', 'trades' => [
                ['trade_id' => '1', 'price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => now()->toIso8601String()],
            ]]),
            'api.coinbase.com/*' => Http::response([]),
        ]);
    }

    public function test_contract_sizing(): void
    {
        $this->assertSame(0, Perps::contractsFor('BTC-USD', 500, 80000));      // $500 < one $800 contract → floors to 0
        $this->assertSame(3, Perps::contractsFor('BTC-USD', 2500, 80000));     // floor(2500/800)
        $this->assertSame(2, Perps::contractsForQty('BTC-USD', 0.02));
        $this->assertEqualsWithDelta(0.03, Perps::qtyFor('BTC-USD', 3), 1e-12);
        $this->assertNull(Perps::spec('NOPE-USD'));
    }

    public function test_paper_short_round_trip_nets_to_true_pnl(): void
    {
        $ex = app(PaperExecutor::class);
        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0002);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
        $start = $ex->cash();
        $this->assertEqualsWithDelta(10000.0, $start, 0.01);

        // short $1000 notional at the bid (79,990)
        $open = $ex->openShort('BTC-USD', 1000, 80000);
        $this->assertTrue($open->ok());
        $this->assertEqualsWithDelta(1000.0, $open->filledUsd, 0.01);           // gross proceeds = entry_usd
        $this->assertEqualsWithDelta(0.2, $open->feeUsd, 0.001);
        $qty = $open->filledQty;
        $this->assertEqualsWithDelta(1000 / $open->fillPrice, $qty, 1e-9);   // fill = whatever bid the feed served

        // cover at the ask: whatever the feed serves; the accounting identity must hold regardless of price
        $cover = $ex->coverShort('BTC-USD', $qty, 80000, $open->filledUsd);
        $this->assertTrue($cover->ok());
        // coverShort() defers its ledger write so Desk::close()/trim() can run it inside their own DB
        // transaction (execution-boundary finding 3); a direct caller runs it explicitly.
        $cover->writeLedger();
        $expectedCost = $qty * $cover->fillPrice;
        $this->assertEqualsWithDelta($expectedCost * (1 + 0.0002), $cover->filledUsd, 0.001);   // cost incl. fee

        $truePnl = 1000 - $open->feeUsd - $cover->filledUsd;
        $this->assertEqualsWithDelta($start + $truePnl, $ex->cash(), 0.001);
        $this->assertEqualsWithDelta($truePnl, 1000 - $open->feeUsd - $qty * $cover->fillPrice * 1.0002, 0.001);
        $this->assertSame(3, PaperLedger::count());   // deposit, short, cover
    }

    public function test_spot_paper_rejects_short_when_perps_disabled(): void
    {
        config(['desk.perps.enabled' => false]);
        $r = app(PaperExecutor::class)->openShort('BTC-USD', 100, 80000);
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('perps', (string) $r->note);
    }
}
