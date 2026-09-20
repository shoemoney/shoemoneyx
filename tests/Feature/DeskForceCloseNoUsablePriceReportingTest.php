<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\DeskEvent;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-9 review, MINOR: the "no usable price to act on" skip logged via reporter->error()
 * unconditionally, every single time it was reached — including on a TERMINAL sweep, which
 * round 9's other fix (DeskForceCloseTerminalRereportTest) made fall through to this same price
 * resolution instead of `continue`-ing early. Left ungated, a terminal position whose price then
 * ALSO became fully unusable (last_price and entry_price both non-positive) would double up: the
 * ledger's own "giving up" message (on its slow re-report cadence) AND this "no usable price"
 * message firing on every terminal sweep, unconditionally — reintroducing the exact per-sweep
 * spam this whole ladder exists to prevent. This report now only fires on a genuine, cadence-
 * gated attempt sweep, never on a terminal one.
 */
class DeskForceCloseNoUsablePriceReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.risk.max_zero_price_sweeps' => 1,
            'desk.risk.force_close_backoff_sweeps' => 1,
            'desk.risk.force_close_max_attempts' => 1,
        ]);
    }

    private function strategy(): Strategy
    {
        return new class implements Strategy
        {
            public function key(): string
            {
                return 'probe';
            }

            public function name(): string
            {
                return 'probe';
            }

            public function defaults(): array
            {
                return [];
            }

            public function scan(array $candidates, DeskContext $ctx): array
            {
                return [];
            }

            public function vet(CandidateRow $c, Bank $b, DeskContext $ctx): Verdict
            {
                throw new \LogicException('not used in this test');
            }

            public function size(Verdict $v, Bank $b, DeskContext $ctx): SizeDecision
            {
                throw new \LogicException('not used in this test');
            }

            public function risk(Position $p, ProductStats $s, DeskContext $ctx): RiskDecision
            {
                throw new \LogicException('unreachable — a price-0 row must never reach the strategy, escalated or not');
            }
        };
    }

    private function alwaysRejectingExecutor(): Executor
    {
        return new class implements Executor
        {
            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                return new OrderResult('rejected', $qty * $decisionPrice, 0.0, 0.0, $decisionPrice, null, 0.0, false, null, [], 'simulated exchange rejection');
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    public function test_no_usable_price_report_never_fires_on_a_terminal_sweep(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 105.0, 'opened_at' => now()->subDays(30), 'meta' => [],
        ]);

        $zeroStats = ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => 0.0,
            'volume_h24_usd' => 0, 'volume_h1_usd' => 0, 'volume_h6_usd' => 0,
            'price_change_h24_pct' => 0, 'spread_bps' => 0, 'candles_h1_count' => 0,
        ]);
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andReturn($zeroStats);
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $strategy = $this->strategy();
        $executor = $this->alwaysRejectingExecutor();

        // Sweep 1: escalates and attempts immediately (max_zero=1, backoff=1), priced off
        // last_price (105) -> rejected -> attempts (1) == force_close_max_attempts (1).
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(0, DeskEvent::where('message', 'like', '%no usable price to act on%')->count());

        // Now the position's price becomes fully unusable everywhere (last_price AND entry_price
        // both non-positive) — a pathological but real edge case (e.g. a corrective backfill).
        $position->update(['last_price' => 0.0, 'entry_price' => 0.0]);

        // Sweeps 2 and 3: now terminal (attempts already at max), AND has no usable price at all.
        // The ledger's own "giving up" message must still report; the SEPARATE "no usable price"
        // message must never fire on a terminal sweep, no matter how many terminal sweeps pass.
        foreach ([2, 3] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('stale', $out[0]['action'], "sweep {$sweep}: no price at all to act on, even though the position is terminal");
        }

        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count(), 'the ledger\'s own terminal message must still report once');
        $this->assertSame(0, DeskEvent::where('message', 'like', '%no usable price to act on%')->count(), 'the "no usable price" report must never fire on a terminal sweep');
    }
}
