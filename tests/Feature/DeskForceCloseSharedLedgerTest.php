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
 * Round-9 review, MAJOR: the stats===null "unmeasurable" route (Desk.php ~702) issued
 * close('unmeasurable', ...) directly, with no backoff and no terminal check — unlike the
 * stats->price<=0 route a few lines below it, which only got the backoff/terminal ladder in
 * round 8. A position whose stats retries kept failing (not returning price 0, genuinely null)
 * hammered the exchange every single sweep forever, paging Telegram each minute. Worse: a
 * position already terminal via the price-0 route would start hammering again the instant its
 * stats flipped from "price 0" to "null" (e.g. the feed dropped entirely instead of just
 * stalling). Both routes now share one force_close_* ledger and both check the terminal flag
 * before ever attempting another close.
 */
class DeskForceCloseSharedLedgerTest extends TestCase
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
            'desk.risk.force_close_backoff_sweeps' => 3,
            'desk.risk.force_close_max_attempts' => 2,
        ]);
    }

    /** Every sell() attempt is rejected by the "exchange" — the position can never actually close. */
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
                throw new \LogicException('unreachable — a null-stats row must never reach the strategy');
            }
        };
    }

    private function position(): Position
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        return Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 105.0, 'opened_at' => now()->subDays(30), 'meta' => [],
        ]);
    }

    public function test_null_stats_route_backs_off_via_the_shared_ledger_instead_of_hammering_every_sweep(): void
    {
        $this->position();

        // stats() always throws -> statsWithRetries() returns null on every sweep, forever.
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andThrow(new \RuntimeException('feed is down'));
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $strategy = $this->strategy();
        $executor = $this->alwaysRejectingExecutor();

        // Sweep 1: first sweep since escalation -> real attempt.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(1, $executor->attempts);

        // Sweeps 2 and 3: backoff (N=3) — must NOT hit the executor again. Without the shared
        // ledger, the null-stats route calls close() unconditionally every sweep here.
        foreach ([2, 3] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('stale', $out[0]['action'], "sweep {$sweep} must back off, not retry the close");
            $this->assertSame(1, $executor->attempts, "sweep {$sweep} must not call the executor again during backoff");
        }

        // Sweep 4: Nth sweep since escalation -> retries (2nd == max attempt).
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(2, $executor->attempts);

        // Sweep 5: now terminal — no further attempt, ever.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(2, $executor->attempts);
    }

    public function test_terminal_via_price_zero_route_does_not_hammer_again_when_stats_flips_to_null(): void
    {
        $this->position();

        $zeroStats = ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => 0.0,
            'volume_h24_usd' => 0, 'volume_h1_usd' => 0, 'volume_h6_usd' => 0,
            'price_change_h24_pct' => 0, 'spread_bps' => 0, 'candles_h1_count' => 0,
        ]);

        // Sweeps 1-2 return a price-0 stats row (max_zero_price_sweeps=1 escalates immediately);
        // from sweep 3 onward the feed drops entirely (throws -> null), simulating exactly the
        // review's "went terminal on price-0 then stats flip to null" scenario.
        $callCount = 0;
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andReturnUsing(function () use (&$callCount, $zeroStats) {
            $callCount++;

            return $callCount <= 2 ? $zeroStats : throw new \RuntimeException('feed dropped entirely');
        });
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $strategy = $this->strategy();
        $executor = $this->alwaysRejectingExecutor();

        // Sweep 1: escalates immediately, backoff cadence is 1 -> real attempt.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(1, $executor->attempts);

        // Sweep 2: attempts (1) has now reached force_close_max_attempts (2)? No — max is 2, so
        // this is still a price-0 sweep; backoff=3 means sweep 2 backs off.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('stale', $out[0]['action']);
        $this->assertSame(1, $executor->attempts);

        // Sweep 3: stats has now gone fully null (feed dropped). Still mid-backoff (sweep 3 of 3)
        // -> backs off again, via the SAME shared ledger the price-0 route was using.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('stale', $out[0]['action'], 'the null-stats route must share the price-0 route\'s backoff cadence, not reset or hammer');
        $this->assertSame(1, $executor->attempts);

        // Sweep 4: Nth sweep -> retries (2nd == max attempt), still via null stats.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(2, $executor->attempts);

        // Sweep 5: now terminal, reported once.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(2, $executor->attempts);
        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count());

        // Sweeps 6-7: stays terminal, null stats included — must never hammer the executor again.
        foreach ([6, 7] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('force_close_terminal', $out[0]['action'], "sweep {$sweep} must stay terminal under null stats too");
        }
        $this->assertSame(2, $executor->attempts, 'a terminal position must never attempt another close, even after stats flip to null');
        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count(), 'terminal give-up must not re-report just because stats flipped to null');
    }
}
