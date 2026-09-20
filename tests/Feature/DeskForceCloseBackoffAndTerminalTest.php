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
 * Round-8 review, MAJOR: once a stuck-price position escalates into a force-close, a close that
 * cannot fill (exchange rejects it, no liquidity) used to retry on every single sweep forever —
 * zeroSweeps was read as stored+1 but never written back on the escalated branch. Asserts the
 * fix: the actual close is only retried every risk.force_close_backoff_sweeps'th sweep, and after
 * risk.force_close_max_attempts failed attempts the position goes terminal — reported once, then
 * every later sweep is a silent no-op instead of hammering the exchange again.
 */
class DeskForceCloseBackoffAndTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            // Escalate to force-close on the very first price-0 sweep, so every sweep below is
            // already in the escalated branch under test.
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

                // status != 'filled' -> OrderResult::ok() is false -> Desk::close() logs "CLOSE
                // FAILED" and leaves the position open, exactly like a real exchange rejection.
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

    public function test_force_close_backs_off_then_goes_terminal_instead_of_retrying_forever(): void
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

        // Sweep 1: escalates immediately (max_zero_price_sweeps=1) — backoff cadence is 1 (first
        // sweep since escalation), so the close is actually attempted.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action'], 'sweep 1 must attempt the force-close immediately on escalation');
        $this->assertSame(1, $executor->attempts, 'sweep 1 must be a real attempt against the executor');
        $position->refresh();
        $this->assertSame('open', $position->status, 'the simulated rejection must leave the position open, not closed');
        $this->assertSame(1, $position->meta['force_close_attempts'] ?? null);

        // Sweeps 2 and 3: backoff (N=3) — reported as stale, no new attempt against the executor.
        foreach ([2, 3] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('stale', $out[0]['action'], "sweep {$sweep} must back off, not retry the close");
            $this->assertSame(1, $executor->attempts, "sweep {$sweep} must not call the executor again during backoff");
        }

        // Sweep 4: the Nth sweep since escalation — retries, and this is the 2nd (== max) attempt.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action'], 'sweep 4 must retry the close after N backoff sweeps');
        $this->assertSame(2, $executor->attempts);
        $position->refresh();
        $this->assertSame(2, $position->meta['force_close_attempts'] ?? null);

        // Sweep 5: attempts (2) has now reached force_close_max_attempts (2) -> terminal. No further
        // attempt against the executor, ever, and the give-up is reported exactly once.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(2, $executor->attempts, 'a terminal position must never attempt another close');

        $terminalReports = DeskEvent::where('message', 'like', '%force-close failed%')->count();
        $this->assertSame(1, $terminalReports, 'the terminal give-up must be reported exactly once');

        // Sweeps 6 and 7: stays terminal forever, still without hitting the executor or re-reporting.
        foreach ([6, 7] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('force_close_terminal', $out[0]['action'], "sweep {$sweep} must stay terminal");
        }
        $this->assertSame(2, $executor->attempts, 'the executor must never be called again once terminal');
        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count(), 'the terminal give-up must not be re-reported on every later sweep');
    }
}
