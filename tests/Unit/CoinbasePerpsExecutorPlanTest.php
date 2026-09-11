<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use Tests\TestCase;

class CoinbasePerpsExecutorPlanTest extends TestCase
{
    private function executor(): CoinbasePerpsExecutor
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldNotReceive('marketContracts');

        return new CoinbasePerpsExecutor($coinbase);
    }

    public function test_plan_sizes_one_contract_for_a_thousand_dollars(): void
    {
        $plan = $this->executor()->plan('BTC-USD', 'BUY', 1000.0, null, 60000.0, checkMargin: false);

        $this->assertSame('BIP-20DEC30-CDE', $plan->perpProductId);
        $this->assertSame(1, $plan->contracts);
        $this->assertEqualsWithDelta(600.0, $plan->notional, 0.01); // 1 contract x 0.01 BTC x $60,000
        $this->assertNull($plan->rejection);
    }

    /**
     * Perps::contractsFor now floors true (0 allowed, locked in by PerpsShortTest::test_contract_sizing),
     * so a $50 ticket against a $600 per-contract notional rejects instead of silently becoming a full
     * contract — the live executor must never turn a $50 signal into a $600+ position.
     */
    public function test_plan_rejects_a_fifty_dollar_ticket_as_under_one_contract(): void
    {
        $plan = $this->executor()->plan('BTC-USD', 'BUY', 50.0, null, 60000.0, checkMargin: false);

        $this->assertSame(0, $plan->contracts);
        $this->assertSame('under one contract', $plan->rejection);
    }

    public function test_plan_rejects_under_one_contract_when_the_decision_price_is_zero(): void
    {
        $plan = $this->executor()->plan('BTC-USD', 'BUY', 50.0, null, 0.0, checkMargin: false);

        $this->assertSame(0, $plan->contracts);
        $this->assertSame('under one contract', $plan->rejection);
    }

    public function test_plan_rejects_when_the_spot_product_has_no_perps_map_entry(): void
    {
        $plan = $this->executor()->plan('NOPE-USD', 'BUY', 1000.0, null, 60000.0, checkMargin: false);

        $this->assertNull($plan->perpProductId);
        $this->assertSame('no perps.map entry for NOPE-USD', $plan->rejection);
    }
}
