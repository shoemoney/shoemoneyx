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
use App\Models\RiskCheck;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-9 review, MINOR: a terminal force-close position reported its give-up exactly once, ever
 * — useful the first time, useless a week later when nobody remembers a Telegram message from
 * days ago and the dashboard's RiskCheck table has no rows for the position at all (the terminal
 * branch `continue`d before ever reaching RiskCheck::create()), making it look unmanaged even
 * though it correctly IS parked. Asserts: the give-up re-reports on a slow cadence
 * (desk.risk.force_close_rereport_sweeps) instead of never again, and a RiskCheck row is written
 * every terminal sweep so the dashboard has something to show.
 */
class DeskForceCloseTerminalRereportTest extends TestCase
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

    public function test_terminal_position_rereports_on_a_slow_cadence_and_gets_a_riskcheck_row_every_sweep(): void
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

        // Sweep 1: escalates and attempts immediately (max_zero=1, backoff=1) -> rejected -> attempts=1 == max(1).
        // A real attempt sweep has always written its own RiskCheck row (unaffected by this fix).
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame(1, RiskCheck::where('position_id', $position->id)->count());

        // Sweep 2: now terminal, reports for the first time, and must ALSO write a RiskCheck row
        // (the old code `continue`d before ever reaching RiskCheck::create()).
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count());
        $this->assertSame(2, RiskCheck::where('position_id', $position->id)->count(), 'a terminal sweep must still write a RiskCheck row for the dashboard');

        // Sweeps 3-4: rereport cadence is 3, so these two stay quiet (no new report) but each still
        // gets its own RiskCheck row.
        foreach ([3, 4] as $sweep) {
            $out = $desk->runRiskSweep($strategy, $executor);
            $this->assertSame('force_close_terminal', $out[0]['action'], "sweep {$sweep}");
        }
        $this->assertSame(1, DeskEvent::where('message', 'like', '%force-close failed%')->count(), 'sweeps 3-4 must not re-report yet');
        $this->assertSame(4, RiskCheck::where('position_id', $position->id)->count(), 'every terminal sweep gets a RiskCheck row, reported or not');

        // Sweep 5: this is the 4th terminal sweep (2,3,4,5) -> cadence 3 means sweep 5 re-reports.
        $out = $desk->runRiskSweep($strategy, $executor);
        $this->assertSame('force_close_terminal', $out[0]['action']);
        $this->assertSame(2, DeskEvent::where('message', 'like', '%force-close failed%')->count(), 'the give-up must re-report on the configured cadence, not stay silent forever');
        $this->assertSame(5, RiskCheck::where('position_id', $position->id)->count());

        // The executor must never have been hit again once terminal.
        $this->assertSame(1, $executor->attempts);
    }
}
