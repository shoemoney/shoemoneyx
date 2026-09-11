<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\PaperExecutor;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\PaperLedger;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * paper_margin on (the default): PaperExecutor posts initial margin instead of the full cash-notional
 * / 1x-collateral paths PaperWholeContractsTest and PerpsShortTest cover. BTC-USD's nano contract is
 * 0.01 BTC (config/desk.php perps.map) so $80,000/BTC floors a $2,400 ticket to exactly 3 contracts
 * with no rounding loss, matching PaperWholeContractsTest's own fixture.
 */
class PaperMarginTest extends TestCase
{
    use RefreshDatabase;

    private object $market;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true]);

        $this->market = new class extends CoinbaseMarketData
        {
            public float $price = 80000.0;

            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => $this->price, 'best_ask' => $this->price, 'trades' => []];
            }
        };
        $this->app->instance(MarketData::class, $this->market);

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0002);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_buy_posts_margin_and_sell_at_plus_five_percent_nets_to_true_pnl(): void
    {
        config(['desk.paper.starting_cash' => 1000]);
        $ex = app(PaperExecutor::class);
        $start = $ex->cash();
        $this->assertEqualsWithDelta(1000.0, $start, 1e-9);

        // $2,400 notional = floor(2400 / (0.01 * 80,000)) = 3 contracts, exact -- no flooring loss.
        // margin = 2400 * 35% = 840; fee = 2400 * 0.02% = 0.48; 840.48 <= 1000 cash -> allowed.
        $buy = $ex->buy('BTC-USD', 2400.0, 80000.0);
        $this->assertTrue($buy->ok());
        $this->assertEqualsWithDelta(0.03, $buy->filledQty, 1e-12);
        $this->assertEqualsWithDelta(2400.0, $buy->filledUsd, 1e-9);
        $this->assertEqualsWithDelta(0.48, $buy->feeUsd, 1e-9);
        $this->assertEqualsWithDelta(840.0, $buy->raw['margin'], 1e-9);   // what Desk::enter() stores as meta['margin_usd']

        $cashAfterBuy = $start - (840.0 + 0.48);
        $this->assertEqualsWithDelta($cashAfterBuy, $ex->cash(), 1e-9);

        // +5%: price 84,000. gross = 0.03 * 84,000 = 2,520. fee = 2,520 * 0.02% = 0.504.
        // margin back = 2400 * 35% = 840. pnl = 2520 - 2400 = 120. ledger credit = 840 + 120 - 0.504 = 959.496.
        $this->market->price = 84000.0;
        $sell = $ex->sell('BTC-USD', $buy->filledQty, 84000.0, $buy->filledUsd);
        $this->assertTrue($sell->ok());
        $this->assertEqualsWithDelta(2520.0, $sell->filledUsd, 1e-9);
        $this->assertEqualsWithDelta(0.504, $sell->feeUsd, 1e-9);
        // sell()/coverShort() defer their ledger write so Desk::close()/trim() can run it inside their
        // own DB transaction (execution-boundary finding 3); a direct caller runs it explicitly.
        $sell->writeLedger();

        $this->assertEqualsWithDelta($cashAfterBuy + 959.496, $ex->cash(), 1e-6);

        $truePnl = 120.0 - $buy->feeUsd - $sell->feeUsd;
        $this->assertEqualsWithDelta($start + $truePnl, $ex->cash(), 1e-6);
    }

    public function test_buy_rejects_insufficient_margin(): void
    {
        config(['desk.paper.starting_cash' => 500]);
        $ex = app(PaperExecutor::class);
        $ex->cash();

        // Same $2,400 ticket needs $840.48; only $500 cash.
        $buy = $ex->buy('BTC-USD', 2400.0, 80000.0);

        $this->assertFalse($buy->ok());
        $this->assertStringContainsString('insufficient margin', (string) $buy->note);
    }

    public function test_a_maker_trim_charges_the_maker_rate_instead_of_taker(): void
    {
        config(['desk.paper.starting_cash' => 1000]);
        app('App\Desk\Settings')->set('fees.maker_rate', 0.001);
        $ex = app(PaperExecutor::class);
        $start = $ex->cash();

        // Same $2,400/3-contract entry as the taker-rate test above: margin 840, fee 0.48 at the taker rate.
        $buy = $ex->buy('BTC-USD', 2400.0, 80000.0);
        $this->assertTrue($buy->ok());
        $cashAfterBuy = $start - (840.0 + 0.48);
        $this->assertEqualsWithDelta($cashAfterBuy, $ex->cash(), 1e-9);

        // +5%: a TP rung rests at $84,000 and the bar trades through it -- maker fill, maker rate.
        // gross = 0.03 * 84,000 = 2,520; maker fee = 2,520 * 0.001 = 2.52 (vs 0.504 at the 0.0002 taker rate).
        $this->market->price = 84000.0;
        $sell = $ex->sell('BTC-USD', $buy->filledQty, 84000.0, $buy->filledUsd, maker: true);
        $this->assertTrue($sell->ok());
        $this->assertEqualsWithDelta(2.52, $sell->feeUsd, 1e-9);
        $sell->writeLedger();

        // margin back (840) + pnl (120) - maker fee (2.52) = 957.48; cash lands on an exact 1,117.00.
        $this->assertEqualsWithDelta($cashAfterBuy + 957.48, $ex->cash(), 1e-6);
    }

    public function test_funding_accrues_hourly_and_writes_a_ledger_row(): void
    {
        config(['desk.fees.funding_hourly_pct' => 0.01]);
        Carbon::setTestNow('2024-01-01 00:00:00');
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 0.03, 'entry_price' => 80000.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'peak_price' => 80000.0, 'last_price' => 80000.0, 'opened_at' => now(), 'meta' => [],
        ]);

        // A tick right after opening is a no-op (less than an hour has passed).
        app(Desk::class)->accrueFunding($position, 80000.0);
        $this->assertSame(0, PaperLedger::where('kind', 'funding')->count());

        Carbon::setTestNow('2024-01-01 01:00:00');
        app(Desk::class)->accrueFunding($position, 80000.0);
        Carbon::setTestNow();

        // notional = 0.03 * 80,000 = 2,400. funding = 2,400 * 0.01% * 1h = 0.24; a long pays it.
        $row = PaperLedger::where('kind', 'funding')->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(-0.24, (float) $row->amount, 1e-9);
        $this->assertEqualsWithDelta(0.24, (float) $position->fresh()->meta['funding_usd'], 1e-9);
    }
}
