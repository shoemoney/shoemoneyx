<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-4 review, finding 1 (BLOCKER): a degraded venue fill — filled_size > 0 but no usable
 * price/value (CoinbaseExecutor.php:104's shape) — must never book. The fix lives once at the
 * boundary (OrderResult::ok()); these prove both callers that create/move a position (entry via
 * Desk::enter()/doEnter(), an add via Desk::addFromRisk()/doAddFromRisk()) respect it.
 */
class DeskDegradedFillBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    /** A degraded fill: the venue reports the order as filled with real quantity but no derivable price. */
    private function degradedResult(float $qty = 2.0): OrderResult
    {
        return new OrderResult('filled', $qty * 100.0, $qty * 100.0, $qty, 100.0, 0.0, 1.2);
    }

    private function degradedBuyExecutor(OrderResult $result): Executor
    {
        return new class($result) implements Executor
        {
            public function __construct(private OrderResult $result) {}

            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                return $this->result;
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

    public function test_entry_never_books_a_position_off_a_degraded_zero_price_fill(): void
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

        $desk = app(Desk::class);
        $executor = $this->degradedBuyExecutor($this->degradedResult());
        $enter = new \ReflectionMethod(Desk::class, 'enter');
        $enter->setAccessible(true);
        $fill = $enter->invoke($desk, $executor, $size, $ctx, $run, $row);

        $this->assertNotNull($fill, 'a Fill row is still written for the degraded attempt, just never a position');
        $this->assertSame(0, Position::count(), 'a degraded (fillPrice<=0) fill must never open a position');
    }

    /**
     * A sell executor whose sell()/coverShort() runs the raw degraded venue shape (real qty, no
     * usable average/value) through the actual OrderResult::recoverFillBasis() helper every
     * settle() now calls — exercising the real round-5 fix end to end through Desk::close()/
     * trim(), not a hand-built "already fine" OrderResult.
     */
    private function recoveringSellExecutor(float $qty, float $fee, float $decisionPrice): Executor
    {
        return new class($qty, $fee, $decisionPrice) implements Executor
        {
            public int $sellCalls = 0;

            public function __construct(private float $qty, private float $fee, private float $decisionPrice) {}

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
                $this->sellCalls++;
                // The exact degraded shape CoinbaseExecutor/CcxtExecutor/CoinbasePerpsExecutor can
                // all report: filled_size > 0, no filled_value, no average.
                [$avg, $value] = OrderResult::recoverFillBasis($qty, null, 0.0, $this->decisionPrice, 'test-venue', 'order-1');

                return new OrderResult('filled', $qty * $this->decisionPrice, $value - $this->fee, $qty, $this->decisionPrice, $avg, $this->fee);
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

    public function test_close_books_correctly_off_a_degraded_but_recovered_fill_and_never_double_sells(): void
    {
        // Round-5 review, BLOCKER 1: CcxtExecutor and CoinbasePerpsExecutor never got round 4's
        // fallback, so this exact shape (real qty, no usable average) made OrderResult::ok() false
        // and Desk::close() logged CLOSE FAILED, leaving the position open while the venue had
        // already sold it — the next sweep sent a second sell.
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 2.0, 'entry_price' => 90.0, 'entry_usd' => 180.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
        $desk = app(Desk::class);
        $executor = $this->recoveringSellExecutor(qty: 2.0, fee: 1.2, decisionPrice: 100.0);

        $fill = $desk->close($position, 'manual', 100.0, $executor);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $position->refresh();
        $this->assertSame('closed', $position->status, 'a real fill recovered to a sane basis must actually close the position');
        $this->assertEqualsWithDelta(0.0, $position->quantity, 1e-9);

        $second = $desk->close($position, 'manual', 100.0, $executor);

        $this->assertNull($second, 'an already-closed position must never be sold a second time');
        $this->assertSame(1, $executor->sellCalls, 'the venue must only ever see one sell for this position');
    }

    public function test_trim_books_correctly_off_a_degraded_but_recovered_fill(): void
    {
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 4.0, 'entry_price' => 90.0, 'entry_usd' => 360.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
        $desk = app(Desk::class);
        $executor = $this->recoveringSellExecutor(qty: 2.0, fee: 1.2, decisionPrice: 100.0);

        $fill = $desk->trim($position, 0.5, 'take_profit_rung', 100.0, $executor);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $position->refresh();
        $this->assertSame('open', $position->status);
        $this->assertEqualsWithDelta(2.0, $position->quantity, 1e-9, 'the trim must actually apply, not be refused as a degraded fill');
        $this->assertSame(1, $position->trims_count);
    }

    public function test_add_never_moves_the_position_off_a_degraded_zero_price_fill(): void
    {
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 10.0, 'entry_price' => 100.0, 'entry_usd' => 1000.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
        $desk = app(Desk::class);
        $ctx = new DeskContext([], 'paper');
        $decision = RiskDecision::add('adds.0', 200.0, 100.0, 'test add');
        $executor = $this->degradedBuyExecutor($this->degradedResult());

        $addFromRisk = new \ReflectionMethod(Desk::class, 'addFromRisk');
        $addFromRisk->setAccessible(true);
        $fill = $addFromRisk->invoke($desk, $position, $decision, $ctx, $executor);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status, 'the venue still reported the order as filled — Fill records what happened');
        $position->refresh();
        $this->assertEqualsWithDelta(10.0, $position->quantity, 1e-9, 'a degraded add must never change quantity');
        $this->assertEqualsWithDelta(1000.0, $position->entry_usd, 1e-9, 'a degraded add must never change entry_usd');
        $this->assertEqualsWithDelta(100.0, $position->entry_price, 1e-9, 'a degraded add must never drag the average down to a zero-priced fill');
        $this->assertSame(0, $position->adds_count);
    }
}
