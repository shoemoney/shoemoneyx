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
 * Round-10 review, MAJOR: recordForceCloseAttemptOutcome() only ever runs after close() has
 * actually executed — but the "no usable price to act on" branch (stats, last_price AND
 * entry_price all <= 0) `continue`s BEFORE the close block is ever reached, so a position stuck
 * there never had an attempt counted. The escalation ladder could never reach
 * force_close_max_attempts and therefore never went terminal: it reported on the backoff cadence
 * forever instead of eventually going quiet and paging once for manual intervention, exactly the
 * failure mode this whole ladder exists to prevent. Reproduces the review's own repro shape
 * (backoff=5, max_attempts=2, 20+ sweeps) and asserts the ladder DOES reach terminal.
 */
class DeskForceCloseUnpriceableAttemptCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.risk.max_zero_price_sweeps' => 1,
            'desk.risk.force_close_backoff_sweeps' => 5,
            'desk.risk.force_close_max_attempts' => 2,
            'desk.risk.force_close_rereport_sweeps' => 60,
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

    private function neverCalledExecutor(): Executor
    {
        // A position with no usable price anywhere must NEVER reach close()/the executor at all
        // — the price resolution `continue`s first, every single sweep.
        return new class implements Executor
        {
            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('a position with no usable price must never reach the executor');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                throw new \LogicException('a position with no usable price must never reach the executor');
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('a position with no usable price must never reach the executor');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \LogicException('a position with no usable price must never reach the executor');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    public function test_an_unpriceable_position_still_reaches_terminal_after_max_attempts(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 0.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            // No usable price ANYWHERE: last_price and entry_price both non-positive from the start.
            'peak_price' => 0.0, 'last_price' => 0.0, 'opened_at' => now()->subDays(30), 'meta' => [],
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
        $executor = $this->neverCalledExecutor();

        // Round-10 review's own repro: 20 sweeps at backoff=5, max_attempts=2 used to leave
        // meta={zero_price_sweeps:20, force_close_sweeps:20} with NO force_close_attempts key at
        // all, and zero "giving up" reports — the ladder never terminated. Run well past that.
        for ($sweep = 1; $sweep <= 20; $sweep++) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('stale', $out[0]['action'], "sweep {$sweep}: no price anywhere, never a real close attempt");
        }

        $this->assertSame(
            2,
            (int) ($position->fresh()->meta['force_close_attempts'] ?? 0),
            'every attempt-cadence sweep with no usable price must still burn an attempt, capped at max_attempts'
        );
        $this->assertGreaterThanOrEqual(
            1,
            DeskEvent::where('message', 'like', '%force-close failed%giving up%')->count(),
            'the ladder must reach terminal and report "giving up" once max_attempts is exhausted, even with no usable price'
        );
    }
}
