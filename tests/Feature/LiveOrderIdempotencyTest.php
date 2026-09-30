<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\OrderBudget;
use App\Exchange\Ccxt\CcxtExecutor;
use App\Exchange\Coinbase\CoinbaseExecutor;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Models\CoinbaseAccount;
use App\Models\DeskEvent;
use App\Models\Fill;
use App\Models\OrderIntent;
use App\Models\Position;
use ccxt\DuplicateOrderId;
use ccxt\InsufficientFunds;
use ccxt\RequestTimeout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Exchange\FakeCoinbaseVenue;
use Tests\Exchange\StubCcxtClient;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\TestCase;

/**
 * A timeout or a failed readback on a LIVE order must never become a duplicate order (the next cycle
 * re-buys, the next sweep re-sells, on perps opens the opposite side) or an unrecorded one (a live fill
 * with no Fill/Position). Every path here is driven through a fake venue that dedups on client_order_id
 * the way the real one does.
 */
class LiveOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private FakeCoinbaseVenue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.live_orders.not_found_grace_seconds' => 120]);
        CoinbaseAccount::create(['name' => 'test', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true]);
        $this->venue = new FakeCoinbaseVenue;
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function spot(): CoinbaseExecutor
    {
        return new CoinbaseExecutor($this->venue);
    }

    private function perps(): CoinbasePerpsExecutor
    {
        return new CoinbasePerpsExecutor($this->venue);
    }

    private function livePosition(array $overrides = []): Position
    {
        return Position::create(array_merge([
            'mode' => 'live', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ], $overrides));
    }

    // ------------------------------------------------------------------ spot entry

    public function test_a_create_timeout_after_the_order_landed_is_unknown_not_rejected_and_never_re_sent(): void
    {
        $this->venue->timeoutAfterPlacing = true;

        $first = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $first->status);
        $this->assertFalse($first->ok());
        $intent = OrderIntent::sole();
        $this->assertSame('pending', $intent->status);
        $this->assertSame($intent->client_order_id, $first->raw['client_order_id']);
        $this->assertSame(1, $this->venue->landedCount());

        // Next cycle asks for the same buy again. The venue is healthy now, but the desk must look the
        // original order up rather than send another one.
        $this->venue->timeoutAfterPlacing = false;
        $second = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('filled', $second->status);
        $this->assertTrue($second->ok());
        $this->assertEqualsWithDelta(1.0, $second->filledQty, 1e-9);
        $this->assertSame(1, count($this->venue->attempts), 'no second create call was ever made');
        $this->assertSame(1, $this->venue->landedCount());
        $this->assertSame('resolved', $intent->refresh()->status);
        $this->assertSame('filled', $intent->outcome);
    }

    public function test_the_desk_books_the_position_after_reconciling_an_orphaned_entry(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $executor = $this->spot();
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100.0, 100.0)->status);
        $this->assertSame(0, Position::count());

        $this->venue->timeoutAfterPlacing = false;
        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);

        $position = Position::sole();
        $this->assertSame('live', $position->mode);
        $this->assertSame('open', $position->status);
        $this->assertEqualsWithDelta(1.0, (float) $position->quantity, 1e-9);
        $fill = Fill::sole();
        $this->assertSame('filled', $fill->status);
        $this->assertSame($position->id, $fill->position_id);
        $this->assertSame('resolved', OrderIntent::sole()->status);
        $this->assertSame(1, count($this->venue->attempts));

        // A second pass is a no-op: booked exactly once.
        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);
        $this->assertSame(1, Position::count());
        $this->assertSame(1, Fill::count());
    }

    public function test_an_order_that_never_reached_the_venue_is_held_through_the_grace_window_then_re_sent_under_the_same_id(): void
    {
        $this->venue->timeoutBeforePlacing = true;
        $this->assertSame('unknown', $this->spot()->buy('BTC-USD', 100.0, 100.0)->status);
        $cid = OrderIntent::sole()->client_order_id;

        $this->venue->timeoutBeforePlacing = false;
        $held = $this->spot()->buy('BTC-USD', 100.0, 100.0);
        $this->assertSame('unknown', $held->status);
        $this->assertSame(1, count($this->venue->attempts), 'inside the grace window nothing is sent');

        $this->travel(3)->minutes();
        $resent = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('filled', $resent->status);
        $this->assertSame(2, count($this->venue->attempts));
        $this->assertSame($cid, $this->venue->attempts[1]['client_order_id'], 'same identity, so a late-indexed original is still deduped');
        $this->assertSame(1, $this->venue->landedCount());
    }

    public function test_an_unverifiable_pending_order_blocks_re_sending(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $this->spot()->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutAfterPlacing = false;
        $this->venue->listFails = true;

        $this->travel(10)->minutes();   // way past grace, but the lookup itself fails: still cannot say "not there"
        $result = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $result->status);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_a_different_side_is_refused_while_an_order_on_the_product_is_unresolved(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $this->spot()->sell('BTC-USD', 1.0, 100.0);
        $this->venue->timeoutAfterPlacing = false;

        $buy = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $buy->status);
        $this->assertStringContainsString('not sending buy', (string) $buy->note);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_another_product_is_not_blocked(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $this->spot()->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutAfterPlacing = false;

        $this->assertSame('filled', $this->spot()->buy('ETH-USD', 100.0, 100.0)->status);
    }

    public function test_a_readback_that_never_answers_is_unknown_and_the_order_is_not_repeated(): void
    {
        $this->venue->readbackFails = true;

        $first = $this->spot()->buy('BTC-USD', 100.0, 100.0);
        $this->assertSame('unknown', $first->status);
        $this->assertSame('ord-1', $first->venueOrderId);

        $this->venue->readbackFails = false;
        $second = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('filled', $second->status);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_an_order_still_working_after_polling_is_unknown(): void
    {
        $this->venue->orderStatus = 'OPEN';

        $result = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $result->status);
        $this->assertSame('pending', OrderIntent::sole()->status);
    }

    public function test_a_definitive_refusal_is_rejected_and_frees_the_product(): void
    {
        $this->venue->failCreateWithStatus = 400;
        $first = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('error', $first->status);
        $this->assertSame('resolved', OrderIntent::sole()->status);

        $this->venue->failCreateWithStatus = null;
        $second = $this->spot()->buy('BTC-USD', 100.0, 100.0);
        $this->assertSame('filled', $second->status);
        $this->assertNotSame($this->venue->attempts[0]['client_order_id'], $this->venue->attempts[1]['client_order_id'], 'a fresh decision is a fresh order');
    }

    public function test_a_gateway_error_is_ambiguous(): void
    {
        $this->venue->failCreateWithStatus = 504;

        $this->assertSame('unknown', $this->spot()->buy('BTC-USD', 100.0, 100.0)->status);
        $this->assertSame('pending', OrderIntent::sole()->status);
    }

    public function test_a_cancelled_unfilled_order_is_a_genuine_rejection(): void
    {
        $this->venue->orderStatus = 'CANCELLED';

        $result = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('rejected', $result->status);
        $this->assertSame('resolved', OrderIntent::sole()->status);
    }

    // ------------------------------------------------------------------ spot exit

    public function test_a_failed_readback_on_close_does_not_sell_again_and_the_replay_books_the_close(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $position = $this->livePosition();

        $this->venue->readbackFails = true;
        $first = $desk->close($position, 'stop', 100.0, $executor);

        $this->assertSame('unknown', $first->status);
        $this->assertSame('open', $position->fresh()->status, 'an unconfirmed sell must not mark the position closed');
        $this->assertSame(1, count($this->venue->attempts));

        // The next risk sweep would sell again. The venue already holds the first sell, so it must
        // reconcile that instead.
        $this->venue->readbackFails = false;
        $second = $desk->close($position->fresh(), 'stop', 100.0, $executor);

        $this->assertSame('filled', $second->status);
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(1, count($this->venue->attempts), 'the position was sold exactly once');
        $this->assertSame(1, $this->venue->landedCount());
    }

    public function test_the_reconcile_pass_books_a_close_the_rules_no_longer_ask_for(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $position = $this->livePosition();

        $this->venue->timeoutAfterPlacing = true;
        $this->assertSame('unknown', $desk->close($position, 'stop', 100.0, $executor)->status);
        $this->venue->timeoutAfterPlacing = false;

        $desk->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);

        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(1, count($this->venue->attempts));
        $this->assertSame(0, OrderIntent::pending()->count());
    }

    // ------------------------------------------------------------------ perps

    public function test_a_perps_create_timeout_does_not_open_the_opposite_side_on_the_next_sweep(): void
    {
        config(['desk.perps.enabled' => true]);
        $this->venue->price = 10_000.0;
        $this->venue->timeoutAfterPlacing = true;

        $first = $this->perps()->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('unknown', $first->status);

        $this->venue->timeoutAfterPlacing = false;
        $second = $this->perps()->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('filled', $second->status);
        $this->assertEqualsWithDelta(0.01, $second->filledQty, 1e-9);
        $this->assertSame(1, count($this->venue->attempts));
        $this->assertSame(1, $this->venue->landedCount());
    }

    public function test_perps_short_and_long_sells_do_not_share_an_unresolved_order(): void
    {
        config(['desk.perps.enabled' => true]);
        $this->venue->price = 10_000.0;
        $this->venue->timeoutAfterPlacing = true;
        $this->perps()->sell('BTC-USD', 0.01, 10_000.0);
        $this->venue->timeoutAfterPlacing = false;

        // Both are a venue SELL, but one closes a long and the other opens a short: never interchangeable.
        $blocked = $this->perps()->coverShort('BTC-USD', 0.01, 10_000.0, 100.0);

        $this->assertSame('unknown', $blocked->status);
        $this->assertSame(1, count($this->venue->attempts));
    }

    // ------------------------------------------------------------------ ccxt

    public function test_a_ccxt_timeout_is_unknown_not_rejected_and_is_looked_up_by_client_id(): void
    {
        $client = new StubCcxtClient([]);
        $executor = new CcxtExecutor($client, 'stubex');

        $client->createThrows = new RequestTimeout('timed out');
        $first = $executor->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame('unknown', $first->status);
        $intent = OrderIntent::sole();
        $this->assertSame($intent->client_order_id, $client->clientOrderIds[0], 'ccxt is handed the client id');

        // Cannot list orders on this venue: the pending order stays pending and nothing is re-sent.
        $client->createThrows = null;
        $client->has['fetchOrders'] = false;
        $this->assertSame('unknown', $executor->buy('BTC-USD', 1000.0, 50_000.0)->status);
        $this->assertCount(1, $client->clientOrderIds);

        // The order exists at the venue: report it, still without a second create.
        $client->has['fetchOrders'] = true;
        $client->stubOrderList = [['id' => 'v-9', 'clientOrderId' => $intent->client_order_id, 'status' => 'closed', 'filled' => 0.02, 'cost' => 1000.0, 'average' => 50_000.0, 'fee' => ['cost' => 0.0]]];
        $third = $executor->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame('filled', $third->status);
        $this->assertSame('v-9', $third->venueOrderId);
        $this->assertCount(1, $client->clientOrderIds);
        $this->assertSame('resolved', $intent->refresh()->status);
    }

    public function test_a_ccxt_insufficient_funds_is_still_a_definitive_error(): void
    {
        $client = new StubCcxtClient([]);
        $client->createThrows = new InsufficientFunds('not enough USD');

        $result = (new CcxtExecutor($client, 'stubex'))->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame('error', $result->status);
        $this->assertSame('resolved', OrderIntent::sole()->status);
    }

    public function test_a_ccxt_order_still_open_after_readback_is_unknown(): void
    {
        $client = new StubCcxtClient([]);
        $client->stubCreate = ['id' => 'o-1', 'status' => 'open', 'filled' => 0, 'cost' => 0];
        $client->stubOrders = [['id' => 'o-1', 'status' => 'open', 'filled' => 0, 'cost' => 0]];

        $result = (new CcxtExecutor($client, 'stubex'))->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame('unknown', $result->status);
        $this->assertSame('pending', OrderIntent::sole()->status);
    }

    // ------------------------------------------------------------------ review round

    public function test_two_reconcile_passes_on_the_same_intent_book_once_and_send_nothing(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $position = $this->livePosition();
        $strategy = new FixedTicketStrategy(100.0);

        $this->venue->timeoutAfterPlacing = true;
        $this->assertSame('unknown', $desk->close($position, 'stop', 100.0, $executor)->status);
        $this->venue->timeoutAfterPlacing = false;

        // Pass A is mid-lookup (holding a stale, still-pending intent) when pass B runs to completion.
        $this->venue->onLookup = fn () => $desk->reconcileLiveOrders($strategy, $executor);
        $desk->reconcileLiveOrders($strategy, $executor);

        $this->assertSame(1, Fill::where('status', 'filled')->count(), 'booked exactly once');
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(1, count($this->venue->attempts), 'reconcile never sends an order');
        $this->assertSame(0, OrderIntent::pending()->count());
    }

    public function test_reconcile_books_a_partial_exit_without_closing_and_never_calls_the_executor(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $position = $this->livePosition();

        $this->venue->timeoutAfterPlacing = true;
        $this->assertSame('unknown', $executor->sell('BTC-USD', 0.4, 100.0)->status);   // a trim whose reply was lost
        $this->venue->timeoutAfterPlacing = false;

        // A stop close for the WHOLE position must not be handed the trim's fill.
        $stop = $desk->close($position, 'stop', 100.0, $executor);
        $this->assertSame('unknown', $stop->status);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame(1, count($this->venue->attempts));

        $desk->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);

        $fresh = $position->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertEqualsWithDelta(0.6, (float) $fresh->quantity, 1e-9);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_a_resend_that_times_out_again_is_not_abandoned_until_its_own_grace_passes(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $strategy = new FixedTicketStrategy(100.0);

        $this->venue->timeoutBeforePlacing = true;
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->travel(3)->minutes();
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100.0, 100.0)->status);   // same-id resend, times out again
        $this->assertSame(2, count($this->venue->attempts));

        $this->venue->timeoutBeforePlacing = false;
        $desk->reconcileLiveOrders($strategy, $executor);
        $this->assertSame(1, OrderIntent::pending()->count(), 'the intent is old, but its last send is fresh');

        $this->travel(3)->minutes();
        $desk->reconcileLiveOrders($strategy, $executor);
        $this->assertSame('abandoned', OrderIntent::sole()->outcome);
    }

    public function test_a_spot_executor_never_reconciles_a_perps_intent(): void
    {
        config(['desk.perps.enabled' => true]);
        $this->venue->price = 10_000.0;
        $this->venue->timeoutAfterPlacing = true;
        $this->perps()->sell('BTC-USD', 0.01, 10_000.0);
        $this->venue->timeoutAfterPlacing = false;
        $this->assertSame('coinbase_perps', OrderIntent::sole()->venue);

        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100.0), $this->spot());

        $this->assertSame(1, OrderIntent::pending()->count());
        $this->assertSame(0, Fill::where('status', 'filled')->count());
    }

    public function test_a_duplicate_client_id_response_is_a_lookup_not_a_refusal(): void
    {
        $this->venue->respondDuplicate = true;

        $first = $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $first->status);
        $this->assertSame('pending', OrderIntent::sole()->status);

        $this->venue->respondDuplicate = false;
        $this->assertSame('filled', $this->spot()->buy('BTC-USD', 100.0, 100.0)->status);
        $this->assertSame(1, $this->venue->landedCount());
    }

    public function test_an_unknown_close_does_not_count_as_a_failed_force_close_attempt(): void
    {
        $position = $this->livePosition();
        $desk = app(Desk::class);
        $record = new \ReflectionMethod($desk, 'recordForceCloseAttemptOutcome');

        $unknown = Fill::create(['mode' => 'live', 'product_id' => 'BTC-USD', 'side' => 'SELL', 'kind' => 'exit', 'requested_usd' => 100, 'decision_price' => 100, 'status' => 'unknown']);
        $record->invoke($desk, $position, $unknown);
        $this->assertSame(0, (int) ($position->fresh()->meta['force_close_attempts'] ?? 0));

        $rejected = Fill::create(['mode' => 'live', 'product_id' => 'BTC-USD', 'side' => 'SELL', 'kind' => 'exit', 'requested_usd' => 100, 'decision_price' => 100, 'status' => 'rejected']);
        $record->invoke($desk, $position, $rejected);
        $this->assertSame(1, (int) ($position->fresh()->meta['force_close_attempts'] ?? 0));
    }

    public function test_a_stuck_order_halts_entries_and_the_operator_command_releases_it(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $halted = new \ReflectionMethod(Desk::class, 'entriesHaltedByStuckOrder');
        $desk = app(Desk::class);

        $this->assertFalse($halted->invoke($desk, $executor, 'BTC-USD'));
        $this->travel(31)->minutes();
        $this->assertTrue($halted->invoke($desk, $executor, 'BTC-USD'));

        $cid = OrderIntent::sole()->client_order_id;
        config(['desk.live_confirm' => 'yes']);
        $this->app->instance(CoinbaseExecutor::class, $executor);

        // The order really landed at the venue, so the command refuses to call it abandoned...
        $this->artisan('desk:intents:resolve', ['client_order_id' => $cid, 'outcome' => 'abandoned'])->assertExitCode(1);
        $this->assertSame('pending', OrderIntent::sole()->status);

        // ...unless the operator forces it, knowing nothing will book the fill.
        $this->artisan('desk:intents:resolve', ['client_order_id' => $cid, 'outcome' => 'abandoned', '--force' => true])->assertExitCode(0);

        $this->assertSame('abandoned', OrderIntent::sole()->outcome);
        $this->assertFalse($halted->invoke($desk, $executor, 'BTC-USD'));
    }

    public function test_the_stuck_clock_runs_from_the_last_send_not_from_creation(): void
    {
        $executor = $this->spot();
        $this->venue->timeoutBeforePlacing = true;
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->travel(31)->minutes();
        $executor->buy('BTC-USD', 100.0, 100.0);   // grace long gone: same-id resend, times out again

        $halted = new \ReflectionMethod(Desk::class, 'entriesHaltedByStuckOrder');

        $this->assertFalse($halted->invoke(app(Desk::class), $executor, 'BTC-USD'));
    }

    public function test_a_send_that_burns_the_grace_window_inside_the_call_is_not_abandoned_on_failure(): void
    {
        $this->venue->timeoutBeforePlacing = true;
        $this->venue->createTakesSeconds = 130;   // the HTTP timeout ate longer than the 120s grace
        $executor = $this->spot();
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100.0, 100.0)->status);

        $this->venue->timeoutBeforePlacing = false;
        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);

        $this->assertSame('pending', OrderIntent::sole()->status, 'the window restarts when the failed send returns');
    }

    public function test_resolving_as_filled_books_from_the_venue_record(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutAfterPlacing = false;

        [$done] = app(Desk::class)->resolveIntent($executor, OrderIntent::sole(), 'filled');

        $this->assertTrue($done);
        $this->assertSame(1, Position::count());
        $this->assertSame('filled', OrderIntent::sole()->outcome);
    }

    public function test_resolving_as_filled_is_refused_when_the_venue_cannot_confirm_a_fill(): void
    {
        $this->venue->timeoutBeforePlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutBeforePlacing = false;

        [$done] = app(Desk::class)->resolveIntent($executor, OrderIntent::sole(), 'filled');

        $this->assertFalse($done);
        $this->assertSame('pending', OrderIntent::sole()->status);
        $this->assertSame(0, Position::count());
    }

    public function test_abandoning_needs_the_venue_to_confirm_absence_after_the_grace_window(): void
    {
        $this->venue->timeoutBeforePlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutBeforePlacing = false;
        $desk = app(Desk::class);

        [$done] = $desk->resolveIntent($executor, OrderIntent::sole(), 'abandoned');
        $this->assertFalse($done, 'inside the grace window an absent order may just be slow to appear');

        $this->travel(3)->minutes();
        [$done] = $desk->resolveIntent($executor, OrderIntent::sole(), 'abandoned');
        $this->assertTrue($done);
        $this->assertSame('abandoned', OrderIntent::sole()->outcome);
    }

    public function test_abandoning_is_refused_when_the_lookup_itself_fails_and_force_overrides(): void
    {
        $this->venue->timeoutAfterPlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->listFails = true;
        $desk = app(Desk::class);

        [$done] = $desk->resolveIntent($executor, OrderIntent::sole(), 'rejected');
        $this->assertFalse($done);

        [$done, $message] = $desk->resolveIntent($executor, OrderIntent::sole(), 'rejected', force: true);
        $this->assertTrue($done);
        $this->assertStringContainsString('FORCED', $message);
    }

    public function test_a_slow_venue_returns_unknown_before_the_lock_ttl_and_the_lock_is_held_throughout(): void
    {
        $desk = app(Desk::class);
        $ttl = OrderBudget::lockSeconds();
        $position = $this->livePosition();
        $started = now()->getTimestamp();
        $lockWasHeld = null;

        $this->venue->readbackTakesSeconds = 30;   // every readback burns the full HTTP timeout
        $this->venue->onLookup = function () use (&$lockWasHeld) {
            // A second process trying for the product lock mid-poll must be refused.
            $other = Cache::lock('desk:mutate:live:BTC-USD', 5);
            $lockWasHeld = ! $other->get();
        };

        $fill = $desk->close($position, 'stop', 100.0, $this->spot());

        $this->assertSame('unknown', $fill->status);
        $this->assertTrue($lockWasHeld, 'nobody else can book while the first process is still polling');
        $this->assertLessThan($ttl, now()->getTimestamp() - $started, 'the order gave up before the lock could expire');
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_the_http_timeout_is_clamped_to_the_remaining_order_budget(): void
    {
        config(['coinbase.timeout' => 30]);

        $this->assertSame(30, OrderBudget::clamp(30), 'no clamp outside an order');
        OrderBudget::within(function () {
            $this->assertLessThanOrEqual(63, OrderBudget::remaining());
            $this->travel(50)->seconds();
            $left = OrderBudget::remaining();
            $this->assertSame($left, OrderBudget::clamp(30));
            $this->assertSame(intdiv($left, 3), OrderBudget::clamp(30, 3));
            $this->travel(20)->seconds();
            $this->assertTrue(OrderBudget::exhausted());
        });
    }

    public function test_a_lookup_that_overruns_the_order_budget_never_counts_as_absent(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $this->venue->timeoutBeforePlacing = true;
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutBeforePlacing = false;
        $this->travel(3)->minutes();

        // The lookup says "not there", but only after the product lock could have expired.
        $this->venue->lookupTakesSeconds = 70;
        $desk->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);
        $this->assertSame(1, OrderIntent::pending()->count());

        [$done] = $desk->resolveIntent($executor, OrderIntent::sole(), 'abandoned');
        $this->assertFalse($done);

        $this->venue->lookupTakesSeconds = 0;
        $desk->reconcileLiveOrders(new FixedTicketStrategy(100.0), $executor);
        $this->assertSame('abandoned', OrderIntent::sole()->outcome);
    }

    public function test_a_ccxt_venue_that_only_lists_open_orders_cannot_prove_a_filled_order_absent(): void
    {
        $client = new StubCcxtClient([]);
        $client->has['fetchOrder'] = false;
        $client->has['fetchOpenOrders'] = true;
        $client->stubCreate = ['id' => 'o-1', 'status' => 'open', 'filled' => 0, 'cost' => 0];
        $executor = new CcxtExecutor($client, 'stubex');

        $this->assertSame('unknown', $executor->buy('BTC-USD', 1000.0, 50_000.0)->status);
        $this->travel(10)->minutes();   // the order filled meanwhile, so it is not in the open list
        $client->stubOpenOrders = [];

        $this->assertSame('unknown', $executor->buy('BTC-USD', 1000.0, 50_000.0)->status);
        $this->assertCount(1, $client->clientOrderIds, 'no second buy');
        $this->assertSame(1, OrderIntent::pending()->count());
        $this->assertFalse($executor->confirmedAbsent(OrderIntent::sole()));
    }

    public function test_client_order_ids_are_32_lowercase_hex(): void
    {
        $this->spot()->buy('BTC-USD', 100.0, 100.0);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', OrderIntent::sole()->client_order_id);
    }

    public function test_a_same_method_lookup_that_overruns_the_budget_is_not_proof_of_absence(): void
    {
        $executor = $this->spot();
        $this->venue->timeoutBeforePlacing = true;
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutBeforePlacing = false;
        $this->travel(3)->minutes();

        $this->venue->lookupTakesSeconds = 70;
        $result = $executor->buy('BTC-USD', 100.0, 100.0);

        $this->assertSame('unknown', $result->status);
        $this->assertSame(1, count($this->venue->attempts), 'no re-send on a stale "absent"');
    }

    public function test_a_zero_grace_setting_is_floored(): void
    {
        config(['desk.live_orders.not_found_grace_seconds' => 0]);
        $this->venue->timeoutBeforePlacing = true;
        $executor = $this->spot();
        $executor->buy('BTC-USD', 100.0, 100.0);
        $this->venue->timeoutBeforePlacing = false;
        $this->travel(20)->seconds();

        $this->assertSame('unknown', $executor->buy('BTC-USD', 100.0, 100.0)->status);
        $this->assertSame(1, count($this->venue->attempts));
    }

    public function test_a_ccxt_duplicate_order_id_stays_pending_until_the_original_is_found(): void
    {
        $client = new StubCcxtClient([]);
        $client->has['fetchOrders'] = true;
        $client->createThrows = new DuplicateOrderId('duplicate');
        $executor = new CcxtExecutor($client, 'stubex');

        $this->assertSame('unknown', $executor->buy('BTC-USD', 1000.0, 50_000.0)->status);
        $intent = OrderIntent::sole();
        $this->assertSame('pending', $intent->status);
        $client->stubOrderList = [['id' => 'original-1', 'clientOrderId' => $intent->client_order_id, 'status' => 'closed', 'filled' => 0.02, 'cost' => 1000.0, 'average' => 50_000.0, 'fee' => ['cost' => 0.0]]];

        $this->assertSame('filled', $executor->buy('BTC-USD', 1000.0, 50_000.0)->status);
        $this->assertCount(1, $client->clientOrderIds);
        $this->assertSame('resolved', $intent->fresh()->status);
    }

    public function test_a_ccxt_resend_after_grace_reuses_the_client_id(): void
    {
        $client = new StubCcxtClient([]);
        $client->has['fetchOrders'] = true;
        $client->createThrows = new RequestTimeout('timed out');
        $executor = new CcxtExecutor($client, 'stubex');
        $executor->buy('BTC-USD', 1000.0, 50_000.0);

        $client->createThrows = null;
        $client->stubCreate = ['id' => 'o-2', 'status' => 'closed', 'filled' => 0.02, 'cost' => 1000.0, 'average' => 50_000.0];
        $client->stubOrders = [$client->stubCreate];
        $this->travel(3)->minutes();
        $result = $executor->buy('BTC-USD', 1000.0, 50_000.0);

        $this->assertSame('filled', $result->status);
        $this->assertCount(2, $client->clientOrderIds);
        $this->assertSame($client->clientOrderIds[0], $client->clientOrderIds[1]);
    }

    public function test_a_spot_stop_loss_fires_while_an_entry_is_unresolved(): void
    {
        $desk = app(Desk::class);
        $executor = $this->spot();
        $position = $this->livePosition();

        $this->venue->timeoutAfterPlacing = true;
        $this->assertSame('unknown', $executor->buy('ETH-USD', 100.0, 100.0)->status);   // other product, control
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100.0, 100.0)->status);   // pending add on the held product
        $this->venue->timeoutAfterPlacing = false;

        $fill = $desk->close($position, 'stop', 100.0, $executor);

        $this->assertSame('filled', $fill->status);
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(1.0, (float) $fill->filled_qty, 1e-9, 'only what was booked is sold');
        $this->assertSame(1, OrderIntent::pending()->where('desk_product_id', 'BTC-USD')->where('method', 'buy')->count());
    }

    public function test_a_blocked_perps_exit_alerts_immediately(): void
    {
        config(['desk.perps.enabled' => true]);
        $this->venue->price = 10_000.0;
        $executor = $this->perps();
        $position = $this->livePosition(['quantity' => 0.01, 'entry_price' => 10_000.0, 'entry_usd' => 100.0]);

        $this->venue->timeoutAfterPlacing = true;
        $executor->coverShort('BTC-USD', 0.01, 10_000.0, 100.0);   // an unresolved order on the same product
        $this->venue->timeoutAfterPlacing = false;

        $fill = app(Desk::class)->close($position, 'stop', 10_000.0, $executor);

        $this->assertSame('unknown', $fill->status);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertTrue(DeskEvent::where('level', 'error')->where('message', 'like', 'EXIT BLOCKED%')->exists());
    }
}
