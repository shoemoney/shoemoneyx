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
 * Round-6 review, BLOCKER 1: any unhandled failure evaluating ONE position inside
 * Desk::runRiskSweep()'s per-position loop used to propagate out of the whole method, so a single
 * poisoned position (a strategy bug, a malformed stored definition reaching an unguarded runtime
 * path — round-6 blockers 2 and 3 both proved a live way in) starved every position after it in
 * the sweep. Declined as unnecessary in round 5 since the two known root causes were fixed
 * directly; required now that a second and third live trigger were proven, since the fix at each
 * root does not cover the next unknown shape.
 */
class DeskRiskSweepIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
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

    public function test_a_position_whose_risk_evaluation_throws_never_starves_the_rest_of_the_sweep(): void
    {
        Product::create(['product_id' => 'AAA-USD', 'base_currency' => 'AAA', 'quote_currency' => 'USD']);
        Product::create(['product_id' => 'ZZZ-USD', 'base_currency' => 'ZZZ', 'quote_currency' => 'USD']);
        $poisoned = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'AAA-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now()->subMinutes(10), 'meta' => [],
        ]);
        $healthy = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'ZZZ-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now()->subMinutes(9), 'meta' => [],
        ]);

        $strategy = new class implements Strategy
        {
            public array $seen = [];

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
                $this->seen[] = $p->product_id;
                if ($p->product_id === 'AAA-USD') {
                    throw new \RuntimeException('poisoned position: simulated risk() failure');
                }

                return RiskDecision::close('probe_close', null, null, null, 'probe close');
            }
        };

        $stats = fn (string $pid) => new ProductStats(
            productId: $pid, price: 100.0, bestBid: 99.9, bestAsk: 100.1, ageHours: 1.0,
            volumeM5Usd: 1.0, volumeH1Usd: 1.0, volumeH6Usd: 1.0, volumeH24Usd: 1.0, volumePrevH24Usd: 1.0,
            priceChangeM5Pct: 0.0, priceChangeH1Pct: 0.0, priceChangeH6Pct: 0.0, priceChangeH24Pct: 0.0,
            buysH1: 1, sellsH1: 1, buysM5: 1, sellsM5: 1, buyVolumeH1Usd: 1.0, sellVolumeH1Usd: 1.0,
            spreadBps: 1.0, bookDepthUsd: 1.0, candlesH1Count: 10,
        );
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andReturnUsing(fn (Product $product) => $stats($product->product_id));
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $executor = $this->fakeExecutor();
        $out = $desk->runRiskSweep($strategy, $executor);

        $this->assertSame(['AAA-USD', 'ZZZ-USD'], $strategy->seen, 'both positions must be visited — the throw must not abort the sweep before ZZZ-USD is even reached');
        $this->assertCount(2, $out, 'the sweep must report on both positions, including the one that errored');
        $this->assertSame('error', $out[0]['action']);
        $this->assertSame('ZZZ-USD', $out[1]['position']);
        $this->assertSame('CLOSE', $out[1]['action']);

        $poisoned->refresh();
        $this->assertSame('open', $poisoned->status, 'the poisoned position is left alone, not silently closed');

        $healthy->refresh();
        $this->assertSame('closed', $healthy->status, 'the position after the poisoned one must still be actioned');
        $this->assertSame(['ZZZ-USD'], $executor->sold);

        $this->assertDatabaseHas('desk_events', ['level' => 'error']);
    }
}
