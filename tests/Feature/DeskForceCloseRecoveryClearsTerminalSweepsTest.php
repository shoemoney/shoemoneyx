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
 * Round-10 review, MINOR: the healthy-price recovery reset cleared zero_price_sweeps,
 * force_close_sweeps, force_close_attempts and force_close_terminal_reported, but NOT
 * force_close_terminal_sweeps (the terminal re-report cadence counter) — docs/RISK.md's own
 * manual-recovery snippet DOES clear it. A position that recovers, then stalls again later,
 * carried a stale nonzero force_close_terminal_sweeps into the new episode, phase-shifting its
 * re-report cadence so the second give-up landed one sweep early.
 */
class DeskForceCloseRecoveryClearsTerminalSweepsTest extends TestCase
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
            'desk.risk.force_close_rereport_sweeps' => 3,
        ]);
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
                // Only ever reached on a genuinely recovered (positive-price) sweep.
                return RiskDecision::hold();
            }
        };
    }

    public function test_recovery_clears_force_close_terminal_sweeps_so_a_later_episodes_cadence_is_not_phase_shifted(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 105.0, 'opened_at' => now()->subDays(30), 'meta' => [],
        ]);

        // Sweep 3 (only) recovers to a positive price; every other sweep is priceless (0.0).
        $callCount = 0;
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            $price = $callCount === 3 ? 100.0 : 0.0;

            return ProductStats::fromArray([
                'product_id' => 'BTC-USD', 'price' => $price,
                'volume_h24_usd' => 0, 'volume_h1_usd' => 0, 'volume_h6_usd' => 0,
                'price_change_h24_pct' => 0, 'spread_bps' => 0, 'candles_h1_count' => 0,
            ]);
        });
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $strategy = $this->strategy();
        $executor = $this->alwaysRejectingExecutor();

        // Sweep 1: escalates + attempts immediately -> rejected -> attempts (1) == max (1).
        $desk->runRiskSweep($strategy, $executor);
        // Sweep 2: terminal for the first time.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(1, (int) $position->fresh()->meta['force_close_terminal_sweeps']);

        // Sweep 3: price recovers -> the whole escalation ledger must reset, including the
        // terminal re-report counter this fix adds.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('HOLD', $out[0]['action']);
        $meta = $position->fresh()->meta;
        $this->assertSame(0, (int) ($meta['force_close_terminal_sweeps'] ?? -1), 'force_close_terminal_sweeps must be cleared on recovery');
        $this->assertSame(0, (int) ($meta['zero_price_sweeps'] ?? -1));
        $this->assertSame(0, (int) ($meta['force_close_sweeps'] ?? -1));
        $this->assertSame(0, (int) ($meta['force_close_attempts'] ?? -1));
        $this->assertFalse($meta['force_close_terminal_reported']);

        // Sweeps 4-8: stalls again from a clean slate. Sweep 4 escalates+attempts, sweep 5 goes
        // terminal (reports immediately since force_close_terminal_reported was reset to false),
        // sweeps 6-7 must stay quiet, sweep 8 is the correctly-phased second re-report
        // (rereport cadence 3: terminal sweeps 1,2,3,4 -> reports on 1 and 4).
        $out = $desk->runRiskSweep($strategy, $executor); // sweep 4: attempts (from a clean slate)
        $this->assertSame('CLOSE', $out[0]['action']);
        foreach ([5, 6, 7] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('force_close_terminal', $out[0]['action'], "sweep {$sweep}");
        }
        $this->assertSame(
            2,
            DeskEvent::where('message', 'like', '%force-close failed%giving up%')->count(),
            'sweep 7 must NOT have re-reported yet — a phase-shifted cadence (the bug) reports one sweep early here'
        );

        $desk->runRiskSweep($strategy, $executor); // sweep 8
        $this->assertSame(
            3,
            DeskEvent::where('message', 'like', '%force-close failed%giving up%')->count(),
            'sweep 8 is the correctly-phased second re-report of the new episode'
        );
    }
}
