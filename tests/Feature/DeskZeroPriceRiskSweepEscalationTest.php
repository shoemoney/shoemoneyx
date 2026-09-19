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
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-7 review, MAJOR: DeskZeroPriceRiskSweepTest (round 6) proved a price-0 stats row gets
 * skipped instead of stamping a zero last_price, but the skip itself was unbounded — a position
 * whose stats price never recovers (a halted/delisted product, or a stalled candle feeder) was
 * never risk-evaluated again while desk:risk kept reporting "no open positions". This asserts the
 * bound: after risk.max_zero_price_sweeps consecutive price-0 sweeps, the position force-closes
 * on the Nth sweep — not skipped an (N+1)th time — the same way a null stats row already does.
 */
class DeskZeroPriceRiskSweepEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.risk.max_zero_price_sweeps' => 3]);
    }

    private function fakeExecutor(): Executor
    {
        return new class implements Executor
        {
            public array $sold = [];

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
                $this->sold[] = $productId;

                return new OrderResult('filled', $qty * $decisionPrice, $qty * $decisionPrice, $qty, $decisionPrice, $decisionPrice, 0.0);
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

    private function strategy(): Strategy
    {
        return new class implements Strategy
        {
            public bool $riskCalled = false;

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
                // A price-0 row must never reach here, escalated or not — this position is either
                // skipped or force-closed by runRiskSweep() before the strategy is ever consulted.
                $this->riskCalled = true;

                return RiskDecision::hold('unreachable');
            }
        };
    }

    public function test_n_consecutive_price_0_sweeps_end_in_a_close_not_an_nplus1th_skip(): void
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
        $executor = $this->fakeExecutor();

        // Sweeps 1 and 2 (below the max of 3): skipped, reported as "stale", position stays open.
        for ($i = 1; $i <= 2; $i++) {
            $out = $desk->runRiskSweep($strategy, $executor);

            $this->assertSame([['position' => 'BTC-USD', 'action' => 'stale', 'rule' => null]], $out, "sweep {$i} must report a stale row, not silently drop the position");
            $position->refresh();
            $this->assertSame('open', $position->status, "sweep {$i} must not close the position yet");
            $this->assertSame($i, $position->meta['zero_price_sweeps'] ?? null, "sweep {$i} must persist its consecutive count");
        }

        $this->assertFalse($strategy->riskCalled, 'the strategy must never be consulted with a price-0 row, escalated or not');
        $this->assertSame([], $executor->sold, 'nothing sold yet — only two sweeps have run against the max of 3');

        // Sweep 3 reaches the max: force-close instead of a 4th skip.
        $out = $desk->runRiskSweep($strategy, $executor);

        $this->assertCount(1, $out);
        $this->assertSame('BTC-USD', $out[0]['position']);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame('unmeasurable', $out[0]['rule']);

        $position->refresh();
        $this->assertSame('closed', $position->status, 'the 3rd consecutive price-0 sweep must force-close, not skip again');
        $this->assertSame(['BTC-USD'], $executor->sold);
        $this->assertFalse($strategy->riskCalled);

        // error(), not warn() — Reporter::warn() never reaches Telegram, so the escalating skips
        // must be loud, and desk_events must show it.
        $this->assertDatabaseHas('desk_events', ['level' => 'error']);
    }
}
