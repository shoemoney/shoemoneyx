<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Ccxt\CcxtExecutor;
use Tests\Exchange\StubCcxtClient;
use Tests\TestCase;

class CcxtExecutorTest extends TestCase
{
    private StubCcxtClient $client;

    private CcxtExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubCcxtClient([]);
        $this->executor = new CcxtExecutor($this->client, 'stubex');
    }

    public function test_mode_is_live(): void
    {
        $this->assertSame('live', $this->executor->mode());
    }

    public function test_cash_sums_free_balances_across_quote_currencies(): void
    {
        config(['desk.universe.quote_currencies' => ['USD', 'USDC']]);
        $this->client->stubBalance = ['free' => ['USD' => 100.25, 'USDC' => 50.0, 'BTC' => 3.0]];

        $this->assertSame(150.25, $this->executor->cash());
    }

    public function test_buy_reports_the_readback_not_the_create_response(): void
    {
        $this->client->stubCreate = ['id' => 'o-1', 'status' => 'open', 'filled' => 0, 'cost' => 0];
        $this->client->stubOrders = [
            ['id' => 'o-1', 'status' => 'closed', 'filled' => 0.01, 'cost' => 995.0, 'average' => 99_500.0, 'fee' => ['cost' => 5.0]],
        ];

        $result = $this->executor->buy('BTC-USD', 1000.0, 99_000.0);

        $this->assertSame('filled', $result->status);
        $this->assertSame(0.01, $result->filledQty);
        $this->assertSame(1000.0, $result->filledUsd);      // cost + fee for a buy
        $this->assertSame(5.0, $result->feeUsd);
        $this->assertSame(99_500.0, $result->fillPrice);
        $this->assertSame(1000.0, $result->requestedUsd);
        $this->assertSame('o-1', $result->venueOrderId);
        $this->assertFalse($result->partial);
        $this->assertTrue($result->ok());
        $this->assertNull($result->ledgerWrite);            // live venue already settled cash
    }

    public function test_buy_uses_cost_sizing_when_the_venue_supports_it(): void
    {
        $this->client->has['createMarketBuyOrderWithCost'] = true;
        $this->client->stubCreate = ['id' => 'o-1', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0];
        $this->client->stubOrders = [$this->client->stubCreate];

        $this->executor->buy('BTC-USD', 1000.0, 99_000.0);

        $this->assertSame(['create_market_buy_order_with_cost', 'BTC/USD', 1000.0], $this->client->calls[0]);
    }

    public function test_buy_falls_back_to_base_sizing_from_the_decision_price(): void
    {
        $this->client->has['createMarketBuyOrderWithCost'] = false;
        $this->client->stubCreate = ['id' => 'o-1', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0];
        $this->client->stubOrders = [$this->client->stubCreate];

        $this->executor->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame(['create_order', 'BTC/USD', 'market', 'buy', 0.02], $this->client->calls[0]);
    }

    public function test_buy_without_cost_support_or_a_decision_price_is_rejected_before_ordering(): void
    {
        $this->client->has['createMarketBuyOrderWithCost'] = false;

        $result = $this->executor->buy('BTC-USD', 1000.0, 0.0);

        $this->assertSame('error', $result->status);
        $this->assertSame([], $this->client->calls);
        $this->assertStringContainsString('sizes market buys in base units', (string) $result->note);
    }

    public function test_a_buy_filled_well_under_the_request_is_partial(): void
    {
        $this->client->stubCreate = ['id' => 'o-1', 'status' => 'closed'];
        $this->client->stubOrders = [
            ['id' => 'o-1', 'status' => 'closed', 'filled' => 0.005, 'cost' => 500.0, 'fee' => ['cost' => 2.5]],
        ];

        $this->assertTrue($this->executor->buy('BTC-USD', 1000.0, 99_000.0)->partial);
    }

    public function test_a_create_failure_becomes_a_rejection_carrying_the_venue_message(): void
    {
        $this->client->createThrows = new \ccxt\InsufficientFunds('not enough USD');

        $result = $this->executor->buy('BTC-USD', 1000.0, 99_000.0);

        $this->assertSame('error', $result->status);
        $this->assertSame(0.0, $result->filledQty);
        $this->assertSame(1000.0, $result->requestedUsd);
        $this->assertSame('stubex: not enough USD', $result->note);
    }

    public function test_an_unfilled_order_is_rejected_and_notes_the_venue_status(): void
    {
        $this->client->stubCreate = ['id' => 'o-1', 'status' => 'open'];
        $this->client->stubOrders = [['id' => 'o-1', 'status' => 'canceled', 'filled' => 0, 'cost' => 0]];

        $result = $this->executor->buy('BTC-USD', 1000.0, 99_000.0);

        $this->assertSame('rejected', $result->status);
        $this->assertFalse($result->ok());
        $this->assertSame('order canceled', $result->note);
    }

    public function test_sell_subtracts_the_fee_and_sizes_in_base_units(): void
    {
        $this->client->stubCreate = ['id' => 'o-2', 'status' => 'closed'];
        $this->client->stubOrders = [
            ['id' => 'o-2', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0, 'average' => 100_000.0, 'fee' => ['cost' => 4.0]],
        ];

        $result = $this->executor->sell('BTC-USD', 0.01, 99_000.0);

        $this->assertSame(['create_order', 'BTC/USD', 'market', 'sell', 0.01], $this->client->calls[0]);
        $this->assertSame('filled', $result->status);
        $this->assertSame(996.0, $result->filledUsd);       // cost - fee for a sell
        $this->assertSame(990.0, $result->requestedUsd);    // qty priced at the decision price
        $this->assertFalse($result->partial);
    }

    public function test_a_sell_filled_well_under_the_requested_qty_is_partial(): void
    {
        $this->client->stubCreate = ['id' => 'o-2', 'status' => 'closed'];
        $this->client->stubOrders = [['id' => 'o-2', 'status' => 'closed', 'filled' => 0.004, 'cost' => 400.0]];

        $this->assertTrue($this->executor->sell('BTC-USD', 0.01, 99_000.0)->partial);
    }

    public function test_a_fees_list_wins_over_the_single_fee_field(): void
    {
        $this->client->stubCreate = ['id' => 'o-3', 'status' => 'closed'];
        $this->client->stubOrders = [[
            'id' => 'o-3', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0,
            'fee' => ['cost' => 7.0],
            'fees' => [['cost' => 3.0], ['cost' => 4.0]],
        ]];

        $this->assertSame(7.0, $this->executor->sell('BTC-USD', 0.01, 99_000.0)->feeUsd);
    }

    public function test_fill_price_falls_back_to_cost_over_quantity(): void
    {
        $this->client->stubCreate = ['id' => 'o-4', 'status' => 'closed'];
        $this->client->stubOrders = [['id' => 'o-4', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0]];

        $this->assertSame(100_000.0, $this->executor->sell('BTC-USD', 0.01, 99_000.0)->fillPrice);
    }

    /**
     * Round-5 review, BLOCKER 1: this venue never got round 4's fallback at all — a real fill
     * with no usable average (cost 0, average 0) yielded fillPrice 0.0, OrderResult::ok() was
     * false, and Desk::close() left the position open while the venue had already sold it.
     */
    public function test_a_degraded_fill_with_no_usable_average_falls_back_to_the_decision_price(): void
    {
        $this->client->stubCreate = ['id' => 'o-7', 'status' => 'closed'];
        $this->client->stubOrders = [['id' => 'o-7', 'status' => 'closed', 'filled' => 0.01, 'cost' => 0, 'average' => 0, 'fee' => ['cost' => 1.2]]];

        $result = $this->executor->sell('BTC-USD', 0.01, 99_000.0);

        $this->assertSame(99_000.0, $result->fillPrice, 'falls back to the decision price, not 0.0');
        $this->assertEqualsWithDelta(0.01 * 99_000.0 - 1.2, $result->filledUsd, 1e-9, 'cost is rebuilt off the same fallback basis, not left at 0');
        $this->assertTrue($result->ok(), 'a real fill must still book once a sane basis is recovered');
    }

    public function test_readback_polls_until_the_status_is_terminal(): void
    {
        $this->client->stubCreate = ['id' => 'o-5', 'status' => 'open'];
        $this->client->stubOrders = [
            ['id' => 'o-5', 'status' => 'open', 'filled' => 0, 'cost' => 0],
            ['id' => 'o-5', 'status' => 'open', 'filled' => 0.005, 'cost' => 500.0],
            ['id' => 'o-5', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0],
        ];

        $result = $this->executor->sell('BTC-USD', 0.01, 99_000.0);

        $this->assertSame(0.01, $result->filledQty);
        $this->assertCount(3, array_filter($this->client->calls, fn ($c) => $c[0] === 'fetch_order'));
    }

    public function test_a_venue_without_fetch_order_settles_from_the_create_response(): void
    {
        $this->client->has['fetchOrder'] = false;
        $this->client->stubCreate = ['id' => 'o-6', 'status' => 'closed', 'filled' => 0.01, 'cost' => 1000.0, 'fee' => ['cost' => 2.0]];

        $result = $this->executor->sell('BTC-USD', 0.01, 99_000.0);

        $this->assertSame('filled', $result->status);
        $this->assertSame(998.0, $result->filledUsd);
        $this->assertSame([], array_filter($this->client->calls, fn ($c) => $c[0] === 'fetch_order'));
    }

    public function test_shorting_is_rejected_on_a_spot_adapter(): void
    {
        $open = $this->executor->openShort('BTC-USD', 1000.0, 99_000.0);
        $cover = $this->executor->coverShort('BTC-USD', 0.01, 99_000.0, 1000.0);

        foreach ([$open, $cover] as $result) {
            $this->assertSame('rejected', $result->status);
            $this->assertSame('ccxt spot adapter cannot short — perps not supported in v1', $result->note);
        }

        $this->assertSame(1000.0, $open->requestedUsd);
        $this->assertSame(990.0, $cover->requestedUsd);
        $this->assertSame([], $this->client->calls);
    }
}
