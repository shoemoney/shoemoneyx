<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\Candidate;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-6 review, MAJOR 2: a fill recovered off the decision price (OrderResult::
 * recoverFillBasis()) makes OrderResult::slippageBps() read as exactly zero, so Desk's SLIPPAGE
 * OVER MAX check could never fire on the one fill class where slippage is genuinely unmeasured,
 * and Position.pnl_usd/exit_price built off that same fabricated basis silently overstated the
 * real fill. A recovered fill now routes to a loud, unconditional reporter error instead.
 */
class DeskFillBasisRecoveredReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function sellExecutor(OrderResult $r): Executor
    {
        return new class($r) implements Executor
        {
            public function __construct(private OrderResult $r) {}

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
                return $this->r;
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

    private function position(): Position
    {
        return Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 2.0, 'entry_price' => 90.0, 'entry_usd' => 180.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
    }

    public function test_close_with_a_recovered_fill_reports_the_basis_recovered_error(): void
    {
        [$avg, $val] = OrderResult::recoverFillBasis(2.0, null, 0.0, 100.0, 'probe', 'o-1');
        $recovered = new OrderResult('filled', 200.0, $val - 1.2, 2.0, 100.0, $avg, 1.2, basisRecovered: true);

        $p = $this->position();
        app(Desk::class)->close($p, 'manual', 100.0, $this->sellExecutor($recovered));

        $p->refresh();
        $this->assertSame('closed', $p->status);
        $this->assertDatabaseHas('desk_events', [
            'level' => 'error',
            'agent' => 'FILLS',
        ]);
        $event = DeskEvent::where('agent', 'FILLS')->where('level', 'error')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('FILL BASIS RECOVERED', $event->message);
    }

    public function test_close_with_a_healthy_fill_never_reports_a_basis_recovered_error(): void
    {
        $healthy = new OrderResult('filled', 200.0, 194.0, 2.0, 100.0, 97.0, 1.2);

        $p = $this->position();
        app(Desk::class)->close($p, 'manual', 100.0, $this->sellExecutor($healthy));

        $this->assertDatabaseMissing('desk_events', ['level' => 'error', 'agent' => 'FILLS']);
    }

    private function buyExecutor(OrderResult $r): Executor
    {
        return new class($r) implements Executor
        {
            public function __construct(private OrderResult $r) {}

            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                return $this->r;
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                throw new \LogicException('not used in this test');
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

    /** The pre-existing SLIPPAGE OVER MAX path (a MEASURED fill, never recovered) must still fire exactly as before. */
    public function test_entry_with_a_measured_fill_over_the_slippage_max_still_reports_slippage_over_max(): void
    {
        $stats = new ProductStats(
            productId: 'BTC-USD', price: 100.0, bestBid: 99.9, bestAsk: 100.1, ageHours: null,
            volumeM5Usd: 0.0, volumeH1Usd: 0.0, volumeH6Usd: 0.0, volumeH24Usd: 0.0, volumePrevH24Usd: 0.0,
            priceChangeM5Pct: 0.0, priceChangeH1Pct: 0.0, priceChangeH6Pct: 0.0, priceChangeH24Pct: 0.0,
            buysH1: 0, sellsH1: 0, buysM5: 0, sellsM5: 0, buyVolumeH1Usd: 0.0, sellVolumeH1Usd: 0.0,
            spreadBps: null, bookDepthUsd: null, candlesH1Count: 1,
        );
        $candidateRow = new CandidateRow($stats, 1.0, 'test', 1);
        $verdict = Verdict::pass($candidateRow, ['test']);
        $size = new SizeDecision($verdict, 200.0, 0.1, 0.05, true, false, 'test');
        $ctx = new DeskContext([], 'paper');
        $run = DeskRun::create(['mode' => 'paper', 'strategy' => 'mr', 'degraded' => false, 'started_at' => now()]);
        $row = Candidate::create([
            'desk_run_id' => $run->id, 'product_id' => 'BTC-USD', 'rank' => 1,
            'rank_reason' => 'test', 'score' => 1.0, 'metrics' => $stats->jsonSerialize(),
        ]);

        // decisionPrice 100, real fillPrice 101 -- a genuine (never recovered) 100 bps BUY slip, over the 50 bps default max.
        $measured = new OrderResult('filled', 200.0, 202.0, 2.0, 100.0, 101.0, 1.2);
        $desk = app(Desk::class);
        $enter = new \ReflectionMethod(Desk::class, 'enter');
        $enter->setAccessible(true);
        $enter->invoke($desk, $this->buyExecutor($measured), $size, $ctx, $run, $row);

        $this->assertDatabaseHas('desk_events', ['level' => 'error', 'agent' => 'FILLS']);
        $event = DeskEvent::where('agent', 'FILLS')->where('level', 'error')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('SLIPPAGE OVER MAX', $event->message);
        $this->assertStringNotContainsString('FILL BASIS RECOVERED', $event->message);
    }
}
