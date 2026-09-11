<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\MarginBook;
use App\Desk\Execution\PaperExecutor;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Driving the full riskSweep() needs stubbing ProductStatsBuilder's live stats retries for every
 * open position, which no existing test does. Per the brief's own fallback, this instead drives the
 * two seams riskSweep uses directly: Desk::paperSession() + MarginBook::liquidated() for detection,
 * and Desk::close() for the settlement a liquidation triggers.
 */
class DeskPaperLiquidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true, 'desk.perps.paper_margin' => true]);

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 60.0, 'best_ask' => 60.0, 'trades' => []];
            }
        });
        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0002);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);

        // cash = 0 exactly (a deposit row of 0 avoids PaperExecutor::cash()'s auto-topup on an empty ledger).
        PaperLedger::create(['kind' => 'deposit', 'amount' => 0.0, 'note' => 'test seed']);
    }

    private function seedPosition(): Position
    {
        return Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 30.0, 'entry_price' => 100.0, 'entry_usd' => 3000.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 60.0, 'opened_at' => now(), 'meta' => ['margin_usd' => 1000.0],
        ]);
    }

    public function test_paper_session_reports_liquidated_below_maintenance(): void
    {
        $this->seedPosition();
        $desk = app(Desk::class);

        $session = $desk->paperSession();

        // notional_now = 30*60 = 1800. unrealised = 1800-3000 = -1200. equity = 0+1000-1200 = -200.
        // maintenance = 1800*0.25 = 450. buffer = (-200-450)/450*100 = -144.4444...%.
        $this->assertEqualsWithDelta(-144.44444444444, $session->liquidationBufferPct, 1e-6);
        $this->assertTrue(MarginBook::liquidated($session));
    }

    public function test_close_settles_a_liquidated_position_at_true_pnl(): void
    {
        $position = $this->seedPosition();
        $desk = app(Desk::class);
        $executor = app(PaperExecutor::class);

        $fill = $desk->close($position, 'liquidation', 60.0, $executor);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $this->assertSame(1, Fill::where('kind', 'exit')->count());

        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertSame('liquidation', $position->close_rule);
        // gross = 1*(30*60 - 3000) = -1200. Net PnL also subtracts the exit fee (finding 7): the seed
        // position carries no tracked entry fee (fees_usd 0, no meta['entry_fees_usd']) or funding, so
        // the only cost is this close's own fee: 1800 notional * 0.0002 = 0.36. Net = -1200 - 0.36.
        $this->assertEqualsWithDelta(-1200.36, $position->pnl_usd, 1e-6);

        // gross = 30*60 = 1800, fee = 1800*0.0002 = 0.36. margin back = 3000*35% = 1050.
        // net = 1050 + (1800-3000) - 0.36 = -150.36. cash after = 0 + (-150.36) = -150.36.
        $this->assertEqualsWithDelta(-150.36, $executor->cash(), 1e-6);
    }
}
