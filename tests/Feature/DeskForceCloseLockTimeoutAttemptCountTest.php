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
use Tests\Feature\Fixtures\DeskWithFakeLockTimeouts;
use Tests\TestCase;

/**
 * Round-9 review, MINOR: force_close_attempts incremented BEFORE close() ran, so a
 * LockTimeoutException on the mutate lock — whose contract everywhere else in this file is
 * "another chance next sweep", never a strike against the position — silently burned one of the
 * position's limited force_close_max_attempts anyway. A position that hit two unlucky lock
 * contentions in a row could go terminal without the exchange ever having genuinely rejected a
 * close. Asserts: a lock timeout never moves force_close_attempts, and a real (non-ok) close
 * result is what actually counts one.
 */
class DeskForceCloseLockTimeoutAttemptCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.risk.stale_data_retries' => 0,
            'desk.risk.max_zero_price_sweeps' => 1,
            'desk.risk.force_close_backoff_sweeps' => 1,
            'desk.risk.force_close_max_attempts' => 2,
        ]);
    }

    /** Every sell() attempt that actually reaches the "exchange" is rejected. */
    private function alwaysRejectingExecutor(): Executor
    {
        return new class implements Executor
        {
            public int $attempts = 0;

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
                $this->attempts++;

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

    public function test_lock_timeout_on_the_close_never_counts_as_a_force_close_attempt(): void
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

        // The very first mutateLock() call (sweep 1's escalated close, backoff=1 fires immediately)
        // times out — everything after that acquires the lock normally.
        $desk = app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [1]]);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $strategy = $this->strategy();
        $executor = $this->alwaysRejectingExecutor();

        // Sweep 1: escalates and is due immediately (backoff=1), but the mutate lock times out —
        // the close() call never even reaches the executor.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action'], 'the decision is still recorded even though the lock blocked it');
        $this->assertSame(0, $executor->attempts, 'the executor must never be reached when the lock times out');

        $position->refresh();
        $this->assertSame('open', $position->status);
        $this->assertSame(0, $position->meta['force_close_attempts'] ?? 0, 'a lock timeout must NEVER count as a force-close attempt');
        $this->assertSame(1, $position->meta['force_close_sweeps'] ?? null, 'the cadence counter still advances even though the attempt itself did not run');

        // Sweep 2: lock acquires fine this time; the close genuinely reaches the exchange and is
        // rejected — THIS is what should count as attempt #1, not sweep 1's lock timeout.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(1, $executor->attempts, 'sweep 2 must be the first real attempt against the executor');

        $position->refresh();
        $this->assertSame(1, $position->meta['force_close_attempts'] ?? null, 'the genuinely-rejected close is what counts, not the earlier lock timeout');

        // Sweep 3: second real rejection -> attempts reaches force_close_max_attempts (2).
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $position->refresh();
        $this->assertSame(2, $position->meta['force_close_attempts'] ?? null);

        // Sweep 4: NOW terminal — exactly two genuine failed attempts, never three, proving the
        // lock timeout in sweep 1 was not silently spent as one of them.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(2, $executor->attempts, 'only two real attempts must ever have reached the executor');
    }
}
