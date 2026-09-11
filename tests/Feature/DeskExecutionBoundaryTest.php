<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Desk\Desk;
use App\Desk\Execution\ExecutionModeMismatchException;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PaperExecutor;
use App\Exchange\Coinbase\CoinbaseExecutor;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\DeskEvent;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\Feature\Fixtures\TrimAndFundStrategy;
use Tests\TestCase;

/**
 * Cluster A (EXECUTION BOUNDARIES) from code-reviews/2026-09-05-gpt-5.6-sol-code-review.md:
 * findings 1 (cross-mode close), 2 (partial close), 3 (trim sprintf), 4/5 (serialization +
 * atomic booking), 6 (Lot legacy flag — see tests/Unit/LotTest.php), 7 (net PnL).
 */
class DeskExecutionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    /** A trivial fake executor for tests that only need to prove routing/mode behavior, never a real fill's arithmetic. */
    private function fakeExecutor(string $mode, ?OrderResult $sellResult = null): Executor
    {
        return new class($mode, $sellResult) implements Executor
        {
            public int $sellCalls = 0;

            public int $coverCalls = 0;

            public function __construct(private string $mode, private ?OrderResult $sellResult) {}

            public function mode(): string
            {
                return $this->mode;
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                $this->sellCalls++;

                return $this->sellResult ?? new OrderResult('filled', $qty * $decisionPrice, $qty * $decisionPrice, $qty, $decisionPrice, $decisionPrice, 0.0);
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                $this->coverCalls++;

                return $this->sellResult ?? new OrderResult('filled', $entryUsdShare, $entryUsdShare, $qty, $decisionPrice, $decisionPrice, 0.0);
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    private function openPosition(array $overrides = []): Position
    {
        return Position::create(array_merge([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 0.03, 'entry_price' => 80000.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'peak_price' => 80000.0, 'last_price' => 80000.0, 'opened_at' => now(), 'meta' => [],
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Finding 1 — cross-mode close
    // ------------------------------------------------------------------

    public function test_close_routes_to_the_positions_own_mode_even_when_the_desk_is_globally_live(): void
    {
        // Desk is globally "live" but the position being closed is a paper one. Desk::close() must
        // resolve the paper executor for it regardless — never the live executor — with no explicit
        // executor argument at all (this is the default, safest path).
        config(['desk.mode' => 'live', 'desk.live_confirm' => 'yes']);
        $liveSpy = $this->fakeExecutor('live');
        $this->app->instance(CoinbaseExecutor::class, $liveSpy);

        $position = $this->openPosition(['mode' => 'paper']);
        $desk = app(Desk::class);

        $fill = $desk->close($position, 'manual', 80000.0);

        $this->assertNotNull($fill);
        $this->assertSame('paper', $fill->mode);
        $this->assertSame(0, $liveSpy->sellCalls, 'the live executor must never be touched for a paper position');
        $this->assertSame('closed', $position->fresh()->status);
    }

    public function test_close_throws_when_an_explicit_executor_mismatches_the_positions_mode(): void
    {
        $position = $this->openPosition(['mode' => 'paper']);
        $desk = app(Desk::class);
        $liveExecutor = $this->fakeExecutor('live');

        $this->expectException(ExecutionModeMismatchException::class);

        try {
            $desk->close($position, 'manual', 80000.0, $liveExecutor);
        } finally {
            $this->assertSame(0, $liveExecutor->sellCalls, 'must refuse before ever touching the mismatched executor');
            $this->assertSame(0, Fill::count());
            $this->assertSame('open', $position->fresh()->status);
        }
    }

    public function test_close_throws_when_a_paper_executor_is_forced_onto_a_live_position(): void
    {
        $position = $this->openPosition(['mode' => 'live']);
        $desk = app(Desk::class);
        $paperExecutor = app(PaperExecutor::class);

        $this->expectException(ExecutionModeMismatchException::class);

        try {
            $desk->close($position, 'manual', 80000.0, $paperExecutor);
        } finally {
            $this->assertSame('open', $position->fresh()->status, 'a live position must never be marked closed by a simulated paper fill');
            $this->assertSame(0, PaperLedger::count(), 'paper cash must be untouched');
        }
    }

    public function test_position_controller_close_refuses_a_paper_position_while_the_desk_is_live(): void
    {
        config(['desk.mode' => 'live', 'desk.live_confirm' => 'yes']);
        $liveSpy = $this->fakeExecutor('live');
        $this->app->instance(CoinbaseExecutor::class, $liveSpy);

        $position = $this->openPosition(['mode' => 'paper']);

        $this->postJson("/api/positions/{$position->id}/close")->assertStatus(409);

        $this->assertSame(0, $liveSpy->sellCalls, 'the live executor must never be invoked for a cross-mode close attempt');
        $this->assertSame('open', $position->fresh()->status);
    }

    public function test_position_controller_close_refuses_a_live_position_while_the_desk_is_paper(): void
    {
        // Desk stays paper (default). A live-mode position is browsed via the position list (the API
        // supports selecting any mode) and closed by ID — this must not be settled by a simulated fill.
        $position = $this->openPosition(['mode' => 'live']);

        $this->postJson("/api/positions/{$position->id}/close")->assertStatus(409);

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame(0, PaperLedger::count());
    }

    public function test_position_controller_close_succeeds_when_the_caller_confirms_the_positions_own_mode(): void
    {
        config(['desk.mode' => 'live', 'desk.live_confirm' => 'yes']);
        $liveSpy = $this->fakeExecutor('live');
        $this->app->instance(CoinbaseExecutor::class, $liveSpy);

        $position = $this->openPosition(['mode' => 'paper']);

        $this->postJson("/api/positions/{$position->id}/close?mode=paper")->assertOk();

        $this->assertSame(0, $liveSpy->sellCalls);
        $this->assertSame('closed', $position->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Finding 2 — partial close
    // ------------------------------------------------------------------

    public function test_partial_close_keeps_the_remainder_open_with_prorated_cost_basis(): void
    {
        // The review's own fixture: 0.03 BTC / $2,400 position, a 0.01 BTC / $800 fill (flat price,
        // so this leg's realised PnL is exactly zero) must leave 0.02 BTC / $1,600 still open rather
        // than being marked closed with the full remaining exposure treated as loss.
        $position = $this->openPosition(['quantity' => 0.03, 'entry_usd' => 2400.0]);
        $partial = new OrderResult('filled', 800.0, 800.0, 0.01, 80000.0, 80000.0, 0.0, partial: true);
        $executor = $this->fakeExecutor('paper', $partial);
        $desk = app(Desk::class);

        $fill = $desk->close($position, 'manual', 80000.0, $executor);

        $this->assertNotNull($fill);
        $this->assertTrue((bool) $fill->partial);
        $position->refresh();
        $this->assertSame('open', $position->status, 'a partial fill must not close the position');
        $this->assertEqualsWithDelta(0.02, $position->quantity, 1e-9);
        $this->assertEqualsWithDelta(1600.0, $position->entry_usd, 1e-9);
        $this->assertEqualsWithDelta(0.0, $position->realised_usd, 1e-9, 'flat-price fill on the sold fraction realises zero, not a loss on the whole position');
    }

    public function test_a_second_close_call_finishes_the_position_left_open_by_a_partial_fill(): void
    {
        $position = $this->openPosition(['quantity' => 0.03, 'entry_usd' => 2400.0]);
        $partial = new OrderResult('filled', 800.0, 800.0, 0.01, 80000.0, 80000.0, 0.0, partial: true);
        $desk = app(Desk::class);
        $desk->close($position, 'manual', 80000.0, $this->fakeExecutor('paper', $partial));
        $position->refresh();
        $this->assertSame('open', $position->status);

        $rest = new OrderResult('filled', 1600.0, 1600.0, 0.02, 80000.0, 80000.0, 0.0);
        $fill = $desk->close($position, 'manual', 80000.0, $this->fakeExecutor('paper', $rest));

        $this->assertNotNull($fill);
        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertEqualsWithDelta(0.0, $position->quantity, 1e-9);
        $this->assertEqualsWithDelta(0.0, $position->pnl_usd, 1e-6);
    }

    // ------------------------------------------------------------------
    // Finding 7 — net PnL (fees + funding)
    // ------------------------------------------------------------------

    public function test_close_net_pnl_and_cash_both_reflect_entry_and_exit_fees(): void
    {
        // The review's own evidence: a flat $2,400 paper round trip with 0.1% entry and 0.1% exit
        // fees must leave cash at $9,995.20 (already true before this fix) AND position PnL at
        // -$4.80 (previously $0 — margin-paper fills return gross notional with fees tracked apart,
        // and close() never subtracted them).
        config(['desk.perps.enabled' => true, 'desk.perps.paper_margin' => true, 'desk.perps.whole_contracts' => false, 'desk.paper.starting_cash' => 10000]);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.001);
        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class, 'desk.strategy' => 'fixed_ticket_test']);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(2400.0, 'BTC-USD', 'long'));

        $desk = app(Desk::class);
        $run = $desk->cycle();
        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->filled);

        $position = Position::sole();
        $this->assertEqualsWithDelta(2400.0, $position->entry_usd, 1e-6);

        $fill = $desk->close($position, 'manual', 80000.0);
        $this->assertNotNull($fill);
        $this->assertTrue($fill->status === 'filled');

        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertEqualsWithDelta(-4.80, $position->pnl_usd, 1e-6);
        $this->assertEqualsWithDelta(9995.20, app(PaperExecutor::class)->cash(), 1e-6);
    }

    // ------------------------------------------------------------------
    // Finding 3 — trim sprintf / risk sweep continuation
    // ------------------------------------------------------------------

    public function test_trim_succeeds_reports_the_rule_and_banks_the_gain(): void
    {
        $position = $this->openPosition(['quantity' => 1.0, 'entry_usd' => 1000.0, 'fees_usd' => 0.0, 'meta' => ['entry_fees_usd' => 0.0]]);
        $desk = app(Desk::class);
        $ex = app(PaperExecutor::class);
        $startCash = $ex->cash();

        $fill = $desk->trim($position, 0.5, 'take_profit_rung', 1200.0, $ex);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $position->refresh();
        $this->assertSame('open', $position->status);
        $this->assertEqualsWithDelta(0.5, $position->quantity, 1e-9);
        $this->assertEqualsWithDelta(500.0, $position->entry_usd, 1e-9);
        $this->assertGreaterThan($startCash, $ex->cash(), 'a profitable trim must add cash to the paper ledger');

        $event = DeskEvent::where('level', 'trade')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('TRIM', $event->message);
        $this->assertStringContainsString('take_profit_rung', $event->message, 'the fixed sprintf() must actually include the rule that fired');
    }

    public function test_risk_sweep_continues_to_the_next_position_after_a_trim(): void
    {
        // Before the fix, Desk.php:612's sprintf() threw ArgumentCountError on ANY successful trim,
        // which aborted riskSweep()'s foreach entirely — later positions never got their risk check.
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Product::create(['product_id' => 'ETH-USD', 'base_currency' => 'ETH', 'quote_currency' => 'USD']);

        $this->app->bind(ProductStatsBuilder::class, fn () => new class(app(MarketData::class), app(CandleStore::class)) extends ProductStatsBuilder
        {
            public function live(Product $product, bool $withBook = false): ProductStats
            {
                return ProductStats::fromArray(['product_id' => $product->product_id, 'price' => 100.0]);
            }
        });
        config(['desk.strategies.trim_fund_test' => TrimAndFundStrategy::class, 'desk.strategy' => 'trim_fund_test']);
        $this->app->instance(TrimAndFundStrategy::class, new TrimAndFundStrategy(1000.0, 'BTC-USD', 'long', trimOnCall: 1, trimFraction: 0.5));

        $first = Position::create(['mode' => 'paper', 'strategy' => 'trim_fund_test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open', 'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0, 'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now()->subMinute(), 'meta' => []]);
        $second = Position::create(['mode' => 'paper', 'strategy' => 'trim_fund_test', 'product_id' => 'ETH-USD', 'side' => 'long', 'status' => 'open', 'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0, 'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => []]);

        $desk = app(Desk::class);
        $out = $desk->riskSweep();

        $this->assertCount(2, $out, 'both positions must be visited — the sweep must not abort after the first trim');
        $this->assertSame('BTC-USD', $out[0]['position']);
        $this->assertSame('TRIM', $out[0]['action']);
        $this->assertSame('ETH-USD', $out[1]['position']);
        $this->assertSame('HOLD', $out[1]['action']);

        $first->refresh();
        $this->assertEqualsWithDelta(0.5, $first->quantity, 1e-9, 'the trim itself must actually have applied');
        $second->refresh();
        $this->assertSame(0, $second->trims_count);
    }

    // ------------------------------------------------------------------
    // Finding 4/5 — serialization + atomic booking
    // ------------------------------------------------------------------

    public function test_two_close_attempts_on_the_same_position_produce_exactly_one_fill(): void
    {
        $position = $this->openPosition();
        $desk = app(Desk::class);
        $ex = app(PaperExecutor::class);

        // The second call "sees the updated state" the way a second concurrent request would once the
        // first has committed under the lock: this call happens after the first has already run.
        $first = $desk->close($position, 'manual', 80000.0, $ex);
        $second = $desk->close($position, 'manual', 80000.0, $ex);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a close on an already-closed position is a no-op, not a second fill');
        $this->assertSame(1, Fill::where('kind', 'exit')->count());
    }

    public function test_a_throw_after_the_executor_returns_leaves_the_fill_and_position_untouched(): void
    {
        // The exchange call stays outside the DB transaction (a live order can't be un-sent), but for
        // PAPER the "exchange call" is just PaperExecutor computing numbers now — it no longer writes
        // PaperLedger itself. It hands Desk a deferred ledger write that runs INSIDE the same
        // transaction as the fill/position writes (finding 3), so a crash between "the venue confirmed
        // the fill" and "we finished writing it down" rolls back the cash movement too: no phantom
        // credit, no still-open position for the next sweep to sell (and credit) a second time.
        $position = $this->openPosition(['meta' => ['throw_on_save' => true]]);
        $desk = app(Desk::class);
        $ex = app(PaperExecutor::class);
        $startCash = $ex->cash();

        Position::saving(function (Position $m) {
            if (($m->meta['throw_on_save'] ?? false) === true) {
                throw new \RuntimeException('simulated crash mid-transaction');
            }
        });

        try {
            $threw = false;
            try {
                $desk->close($position, 'manual', 80000.0, $ex);
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString('simulated crash', $e->getMessage());
            }
            $this->assertTrue($threw, 'the save failure must propagate, not be swallowed');

            $this->assertSame(0, Fill::count(), 'the fill insert must have rolled back with the failed position save');
            $position->refresh();
            $this->assertSame('open', $position->status, 'the position must be untouched by the rolled-back transaction');
            $this->assertEqualsWithDelta(0.03, $position->quantity, 1e-9);

            // The deferred paper ledger write ran inside the same transaction as the failed position
            // save, so it rolled back with it — cash is exactly what it was before close() was called.
            $this->assertSame(0, PaperLedger::where('kind', 'sell')->count(), 'the ledger write must roll back with the failed position save');
            $this->assertEqualsWithDelta($startCash, $ex->cash(), 1e-9, 'cash must be unchanged after a throw between the executor call and the finished write');
        } finally {
            Event::forget('eloquent.saving: '.Position::class);
        }
    }

    public function test_paper_initial_deposit_is_idempotent(): void
    {
        $this->assertSame(0, PaperLedger::count());
        $ex = app(PaperExecutor::class);

        $first = $ex->cash();
        $second = $ex->cash();

        $this->assertSame(1, PaperLedger::where('kind', 'deposit')->count(), 're-checking under the lock must never seed a second deposit');
        $this->assertEqualsWithDelta($first, $second, 1e-9);
    }

    // ------------------------------------------------------------------
    // Reviewer blocker 1 — mutate lock TTL vs. worst-case exchange path
    // ------------------------------------------------------------------

    public function test_mutate_lock_ttl_outlasts_the_worst_case_exchange_path(): void
    {
        // A fixed 10s TTL expired while a call was still inside a slow exchange round trip (worst
        // case: config('coinbase.timeout') per HTTP call x several settlement polls), letting a
        // second caller acquire the lock, re-read the still-open position, and send a second close.
        // The formula must be applied, not just "some bigger number".
        $seconds = new \ReflectionMethod(Desk::class, 'mutateLockSeconds');
        $seconds->setAccessible(true);
        $desk = app(Desk::class);

        config(['coinbase.timeout' => 30]);
        $this->assertSame(90, $seconds->invoke($desk), 'max(90, 30*3) = 90');

        config(['coinbase.timeout' => 60]);
        $this->assertSame(180, $seconds->invoke($desk), 'max(90, 60*3) = 180 — the formula must scale with a larger configured timeout');

        config(['coinbase.timeout' => 5]);
        $this->assertSame(90, $seconds->invoke($desk), 'max(90, 5*3) = 90 — the 90s floor holds even for a tiny configured timeout');
    }

    public function test_a_second_lock_acquirer_cannot_enter_while_the_first_is_mid_call(): void
    {
        // Proves mutual exclusion actually holds for as long as a call is inside the lock — not just
        // at the instant it started — by having the exchange call itself (which Desk::close() is
        // still holding the lock around) attempt a second, independent acquire of the SAME key via
        // the lock's own non-blocking get() semantics, rather than a wall-clock sleep + real thread.
        $position = $this->openPosition(['mode' => 'paper', 'product_id' => 'BTC-USD']);
        $desk = app(Desk::class);

        $executor = new class implements Executor
        {
            public ?bool $acquiredWhileHeld = null;

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
                // Desk::close() is mid-call right now, holding desk:mutate:paper:BTC-USD. A brand new,
                // independently-owned Lock instance for the identical key must fail to acquire it.
                $this->acquiredWhileHeld = Cache::lock('desk:mutate:paper:BTC-USD', 5)->get();

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

        $desk->close($position, 'manual', 80000.0, $executor);

        $this->assertNotNull($executor->acquiredWhileHeld, 'the fake executor must actually have run and attempted the second acquire');
        $this->assertFalse($executor->acquiredWhileHeld, 'a second Lock instance for the same product/mode key must not acquire while Desk::close() is still inside its own lock');
    }

    // ------------------------------------------------------------------
    // Reviewer blocker 4 — zero-lot handling (trim skip + legacy full close)
    // ------------------------------------------------------------------

    public function test_trim_skips_a_zero_lot_without_treating_it_as_a_failure(): void
    {
        // A whole-contracts venue can floor a sub-contract rung to a zero lot (Lot::forQty's own
        // zero-lot behavior) without that being a rejection. Desk must recognize it explicitly and
        // skip cleanly rather than falling into the generic "TRIM FAILED" branch and writing a Fill
        // row for a fill that never happened.
        $position = $this->openPosition(['quantity' => 1.0, 'entry_usd' => 1000.0]);
        $zeroLot = new OrderResult('filled', 0.0, 0.0, 0.0, 1200.0, 1200.0, 0.0);
        $executor = $this->fakeExecutor('paper', $zeroLot);
        $desk = app(Desk::class);

        $fill = $desk->trim($position, 0.5, 'take_profit_rung', 1200.0, $executor);

        $this->assertNull($fill, 'a zero-lot rung is a no-op, not a failure fill');
        $this->assertSame(0, Fill::count());
        $this->assertSame(1, $executor->sellCalls, 'the executor was still asked — the skip happens after seeing its zero-qty answer');
        $position->refresh();
        $this->assertSame('open', $position->status);
        $this->assertEqualsWithDelta(1.0, $position->quantity, 1e-9, 'quantity must be untouched by a zero-fill rung');
    }

    public function test_close_fully_exits_a_legacy_fractional_holding_without_a_zero_lot(): void
    {
        // A position opened before whole-contract sizing existed can hold a genuinely fractional
        // quantity under today's contract_size (0.0015 BTC vs. a 0.01 BTC nano contract) — auto
        // detected here purely by PaperExecutor::legacyFractional()'s ratio heuristic (no explicit
        // meta['fractional'] flag), the realistic shape of a holding that predates that flag existing.
        // Closing it in full must sell the whole 0.0015 BTC at its real price; if Lot::forQty ever
        // floored it to a zero lot instead, the position would never actually close (OrderResult::ok()
        // is false for filledQty=0), stranding it open forever behind a "CLOSE FAILED" log line.
        config([
            'desk.perps.enabled' => true,
            'desk.perps.whole_contracts' => true,
            'desk.perps.paper_margin' => false,
            'desk.perps.map.BTC-USD' => ['product_id' => 'BIP-20DEC30-CDE', 'contract_size' => 0.01],
        ]);
        $position = $this->openPosition(['quantity' => 0.0015, 'entry_usd' => 120.0, 'entry_price' => 80000.0]);
        $desk = app(Desk::class);
        $ex = app(PaperExecutor::class);

        $fill = $desk->close($position, 'manual', 80000.0, $ex);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $this->assertEqualsWithDelta(0.0015, $fill->filled_qty, 1e-9, 'the full legacy quantity must be sold, not floored to zero');
        $position->refresh();
        $this->assertSame('closed', $position->status, 'a legacy fractional holding must actually close, not get stuck open behind a zero lot');
        $this->assertEqualsWithDelta(0.0, $position->quantity, 1e-9);
    }
}
