<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\BankService;
use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Execution\PerpsSession;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 6 (code-reviews/2026-09-05-gpt-5.6-sol-code-review.md): the bank added a margin
 * position's full notional back as market value on top of cash that had already been debited
 * the (much smaller) margin posted for it, so opening a position minted equity out of nothing.
 * MarginBook's own accounting (collateral + unrealised PnL) is the correct contract; the bank
 * must agree with it instead of inventing a second, richer view of the same position.
 */
class BankServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMarket(float $price): CoinbaseMarketData
    {
        return new class($price) extends CoinbaseMarketData
        {
            public function __construct(public float $price) {}

            public function price(string $productId): ?float
            {
                return $this->price;
            }
        };
    }

    /** The review's own reproduction: $10,000 cash, a flat $2,400 position posting $840 margin. */
    public function test_a_flat_margin_position_does_not_inflate_equity(): void
    {
        PaperLedger::create(['kind' => 'deposit', 'amount' => 10_000.0, 'note' => 'seed']);
        PaperLedger::create(['kind' => 'buy', 'amount' => -840.0, 'ref' => 'BTC-USD', 'note' => 'MARGIN 24.00000000 @ 100.000000']);

        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 24.0, 'entry_price' => 100.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'last_price' => 100.0, 'peak_price' => 100.0, 'opened_at' => now(),
            'meta' => ['margin_usd' => 840.0],
        ]);

        $executor = new PaperExecutor($this->fakeMarket(100.0), app('App\Desk\Settings'));
        $bank = app(BankService::class, ['market' => $this->fakeMarket(100.0)])->current($executor, [$position]);

        $this->assertEqualsWithDelta(9_160.0, $bank->cash, 1e-9);         // 10,000 - 840 margin
        $this->assertEqualsWithDelta(840.0, $bank->collateral, 1e-9);     // posted margin, not the $2,400 notional
        $this->assertEqualsWithDelta(0.0, $bank->unrealisedPnl, 1e-9);    // price never moved
        $this->assertEqualsWithDelta(840.0, $bank->positionsValue, 1e-9); // collateral + PnL, never the notional
        $this->assertEqualsWithDelta(10_000.0, $bank->equity(), 1e-9);    // NOT $11,560
        $this->assertEqualsWithDelta(2_400.0, $bank->exposure, 1e-9);     // exposure still sees the full notional
    }

    public function test_cash_plus_collateral_plus_open_pnl_equals_equity_after_a_price_move(): void
    {
        PaperLedger::create(['kind' => 'deposit', 'amount' => 10_000.0, 'note' => 'seed']);
        PaperLedger::create(['kind' => 'buy', 'amount' => -840.0, 'ref' => 'BTC-USD', 'note' => 'margin']);

        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 24.0, 'entry_price' => 100.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'last_price' => 100.0, 'peak_price' => 100.0, 'opened_at' => now(),
            'meta' => ['margin_usd' => 840.0],
        ]);

        // +10%: price 110. unrealised = 24 * 110 - 2400 = 240.
        $executor = new PaperExecutor($this->fakeMarket(110.0), app('App\Desk\Settings'));
        $bank = app(BankService::class, ['market' => $this->fakeMarket(110.0)])->current($executor, [$position]);

        $this->assertEqualsWithDelta(240.0, $bank->unrealisedPnl, 1e-9);
        $this->assertEqualsWithDelta(9_160.0 + 840.0 + 240.0, $bank->equity(), 1e-9);
        $this->assertEqualsWithDelta($bank->cash + $bank->collateral + $bank->unrealisedPnl, $bank->equity(), 1e-9);
    }

    /** A plain spot buy (no margin posted) already balances cash against market value -- must stay that way. */
    public function test_a_flat_spot_position_does_not_create_equity(): void
    {
        PaperLedger::create(['kind' => 'deposit', 'amount' => 10_000.0, 'note' => 'seed']);
        PaperLedger::create(['kind' => 'buy', 'amount' => -2400.0, 'ref' => 'BTC-USD', 'note' => 'spot buy']);

        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 24.0, 'entry_price' => 100.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'last_price' => 100.0, 'peak_price' => 100.0, 'opened_at' => now(),
            'meta' => [],
        ]);

        $executor = new PaperExecutor($this->fakeMarket(100.0), app('App\Desk\Settings'));
        $bank = app(BankService::class, ['market' => $this->fakeMarket(100.0)])->current($executor, [$position]);

        $this->assertEqualsWithDelta(0.0, $bank->collateral, 1e-9);
        $this->assertEqualsWithDelta(2_400.0, $bank->positionsValue, 1e-9); // market value, unchanged for spot
        $this->assertEqualsWithDelta(10_000.0, $bank->equity(), 1e-9);
        $this->assertEqualsWithDelta(2_400.0, $bank->exposure, 1e-9);
    }

    /** Live futures: cash() returns CFM's buying power, which must be labelled as such, not spent as settled cash. */
    public function test_live_buying_power_is_labelled_as_buying_power_not_cash(): void
    {
        $executor = \Mockery::mock(CoinbasePerpsExecutor::class);
        $executor->shouldReceive('mode')->andReturn('live');
        $executor->shouldReceive('cash')->andReturn(5_000.0); // this is buying power, not settled cash
        $executor->shouldReceive('session')->andReturn(new PerpsSession(
            window: MarginWindow::Overnight,
            windowEndsAt: null,
            futuresBuyingPower: 5_000.0,
            initialMargin: 840.0,
            availableMargin: 9_160.0,
            liquidationBufferPct: 300.0,
        ));

        $position = new Position([
            'mode' => 'live', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 24.0, 'entry_price' => 100.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'last_price' => 100.0, 'peak_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
        $position->exists = true;

        $bank = app(BankService::class, ['market' => $this->fakeMarket(100.0)])->current($executor, [$position]);

        // Buying power now lives under its own name -- it is never read back out as "cash" for
        // equity math, so a client relying on the label sees exactly what it is.
        $this->assertSame(5_000.0, $bank->buyingPower);
        $this->assertArrayHasKey('buying_power', $bank->toArray());
        $this->assertSame(5_000.0, $bank->toArray()['buying_power']);

        // The bank no longer stacks the full $2,400 notional on top of buying power: collateral
        // comes from CFM's own initial_margin (positions never carry paper's meta['margin_usd']).
        $this->assertEqualsWithDelta(840.0, $bank->collateral, 1e-9);
        $this->assertEqualsWithDelta(0.0, $bank->unrealisedPnl, 1e-9);
        $this->assertEqualsWithDelta(840.0, $bank->positionsValue, 1e-9); // not the $2,400 notional

        // Equity's cash leg is the session's availableMargin, not the leveraged buying-power
        // figure: 9,160 available margin + 840 initial margin = 10,000 true equity, never the
        // $5,840 that buying power (5,000) + collateral (840) would otherwise produce.
        $this->assertEqualsWithDelta(9_160.0, $bank->cash, 1e-9);
        $this->assertEqualsWithDelta(10_000.0, $bank->equity(), 1e-9);
    }
}
