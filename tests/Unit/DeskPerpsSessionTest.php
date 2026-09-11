<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\OrderPlan;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskPerpsSessionTest extends TestCase
{
    private function bindExecutor(CoinbasePerpsExecutor $executor): void
    {
        $this->app->instance(CoinbasePerpsExecutor::class, $executor);
    }

    private function fakeTicker(): void
    {
        Http::fake(['api.coinbase.com/*' => Http::response(['best_bid' => '59990', 'best_ask' => '60010'])]);
    }

    public function test_prints_the_session_fields(): void
    {
        $session = new PerpsSession(
            window: MarginWindow::Overnight,
            windowEndsAt: new \DateTimeImmutable('2026-09-06T13:00:00Z'),
            futuresBuyingPower: 50000.0,
            initialMargin: 10000.0,
            availableMargin: 40000.0,
            liquidationBufferPct: 42.5,
        );

        $this->bindExecutor(new class($session) extends CoinbasePerpsExecutor
        {
            public function __construct(private readonly PerpsSession $fake) {}

            public function session(): PerpsSession
            {
                return $this->fake;
            }
        });

        $this->artisan('desk:perps-session')
            ->assertExitCode(0)
            ->expectsOutputToContain('overnight')
            ->expectsOutputToContain('50,000.00')
            ->expectsOutputToContain('42.50')
            ->expectsOutputToContain('halted:');
    }

    public function test_reports_a_single_line_when_the_api_rejects_the_key(): void
    {
        $this->bindExecutor(new class extends CoinbasePerpsExecutor
        {
            public function __construct() {}

            public function session(): PerpsSession
            {
                throw new CoinbaseApiException('invalid API key', 401);
            }
        });

        $this->artisan('desk:perps-session')
            ->assertExitCode(1)
            ->expectsOutputToContain('CDP key rejected or API unavailable: invalid API key');
    }

    public function test_dry_order_prints_would_send_when_the_plan_is_allowed(): void
    {
        $session = new PerpsSession(
            window: MarginWindow::Overnight,
            windowEndsAt: null,
            futuresBuyingPower: 50000.0,
            initialMargin: 0.0,
            availableMargin: 0.0,
            liquidationBufferPct: 50.0,
        );

        $plan = new OrderPlan(
            spotPid: 'BTC-USD',
            perpProductId: 'BIP-20DEC30-CDE',
            side: 'BUY',
            contracts: 1,
            notional: 600.0,
            gate: new PerpsGate(true, null),
            rejection: null,
        );

        $this->bindExecutor(new class($session, $plan) extends CoinbasePerpsExecutor
        {
            public function __construct(private readonly PerpsSession $fakeSession, private readonly OrderPlan $fakePlan) {}

            public function session(): PerpsSession
            {
                return $this->fakeSession;
            }

            public function plan(string $spotPid, string $side, ?float $usd, ?float $qty, float $decisionPrice, bool $checkMargin): OrderPlan
            {
                return $this->fakePlan;
            }
        });

        $this->fakeTicker();

        $this->artisan('desk:perps-session', ['--dry-order' => 'BTC-USD'])
            ->assertExitCode(0)
            ->expectsOutputToContain('would send: POST /api/v3/brokerage/orders market_ioc BUY 1xBIP-20DEC30-CDE')
            ->expectsOutputToContain('no order was sent');
    }

    public function test_dry_order_prints_would_reject_when_the_plan_refuses(): void
    {
        $session = new PerpsSession(
            window: MarginWindow::Overnight,
            windowEndsAt: null,
            futuresBuyingPower: 100.0,
            initialMargin: 0.0,
            availableMargin: 0.0,
            liquidationBufferPct: 5.0,
        );

        $plan = new OrderPlan(
            spotPid: 'BTC-USD',
            perpProductId: 'BIP-20DEC30-CDE',
            side: 'BUY',
            contracts: 1,
            notional: 600.0,
            gate: new PerpsGate(false, 'liquidation buffer 5% < 30% floor'),
            rejection: 'liquidation buffer 5% < 30% floor',
        );

        $this->bindExecutor(new class($session, $plan) extends CoinbasePerpsExecutor
        {
            public function __construct(private readonly PerpsSession $fakeSession, private readonly OrderPlan $fakePlan) {}

            public function session(): PerpsSession
            {
                return $this->fakeSession;
            }

            public function plan(string $spotPid, string $side, ?float $usd, ?float $qty, float $decisionPrice, bool $checkMargin): OrderPlan
            {
                return $this->fakePlan;
            }
        });

        $this->fakeTicker();

        $this->artisan('desk:perps-session', ['--dry-order' => 'BTC-USD'])
            ->assertExitCode(0)
            ->expectsOutputToContain('would reject: liquidation buffer 5% < 30% floor')
            ->expectsOutputToContain('no order was sent');
    }
}
