<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Arena\ArenaRunner;
use App\Desk\Chief;
use App\Desk\Contracts\Strategy;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\Exceptions\CycleInProgressException;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderBudget;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\ReconcilesOrders;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\OrderIntent;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskRestartLocksTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD_PID = 4194000;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake(['api.coinbase.com/*' => Http::response(['products' => [], 'candles' => []])]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function strand(string $host, int $pid, string $role, ?int $beat = null): void
    {
        $lock = Cache::lock('desk:cycle:paper', 3600);
        $this->assertTrue($lock->get());
        Cache::put('desk:cycle-owner:paper', ['host' => $host, 'pid' => $pid, 'role' => $role, 'token' => $lock->owner(), 'beat' => $beat ?? time()], 3600);
    }

    private function cycleLockIsFree(): bool
    {
        $probe = Cache::lock('desk:cycle:paper', 5);
        $free = $probe->get();
        $free && $probe->release();

        return $free;
    }

    public function test_boot_clears_a_cycle_lock_whose_owner_pid_died_on_this_host(): void
    {
        $this->strand(gethostname(), self::DEAD_PID, 'run');

        $this->assertSame(['paper'], app(Desk::class)->reclaimStaleCycleLocks());
        $this->assertTrue($this->cycleLockIsFree());
        $this->assertNull(Cache::get('desk:cycle-owner:paper'));
    }

    public function test_boot_clears_a_lock_left_by_our_own_pid_after_a_container_restart(): void
    {
        $this->strand(gethostname(), (int) getmypid(), 'run');

        $this->assertSame(['paper'], app(Desk::class)->reclaimStaleCycleLocks());
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_an_api_lock_owned_by_our_own_pid_is_not_mistaken_for_a_dead_predecessor(): void
    {
        $this->strand(gethostname(), (int) getmypid(), 'api');

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks());
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_live_other_process_on_this_host_keeps_its_lock(): void
    {
        $this->strand(gethostname(), (int) posix_getppid(), 'api');

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_lock_with_no_owner_record_is_left_alone(): void
    {
        $this->assertTrue(Cache::lock('desk:cycle:paper', 3600)->get());

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_foreign_host_desk_run_lock_is_cleared_only_when_single_runner_is_trusted(): void
    {
        $this->strand('old-container-id', 1, 'run', time() - 3600);

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(false), 'bare metal: another host name may be a live runner');
        $this->assertFalse($this->cycleLockIsFree());

        $this->assertSame(['paper'], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_foreign_run_lock_with_a_fresh_heartbeat_is_kept_while_its_owner_may_still_be_stopping(): void
    {
        $this->strand('old-container-id', 1, 'run', time() - 20);

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_foreign_run_lock_is_reclaimed_once_the_heartbeat_outlives_grace_plus_mutate_ttl(): void
    {
        $desk = app(Desk::class);
        $this->strand('old-container-id', 1, 'run', time() - $desk->staleBeatSeconds() + 5);
        $this->assertSame([], $desk->reclaimStaleCycleLocks(true), 'still inside grace + mutate TTL');

        Cache::put('desk:cycle-owner:paper', ['beat' => time() - $desk->staleBeatSeconds() - 1] + Cache::get('desk:cycle-owner:paper'), 3600);
        $this->assertSame(['paper'], $desk->reclaimStaleCycleLocks(true));
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_foreign_run_lock_without_a_heartbeat_is_kept(): void
    {
        $this->strand('old-container-id', 1, 'run');
        Cache::put('desk:cycle-owner:paper', array_diff_key(Cache::get('desk:cycle-owner:paper'), ['beat' => 1]), 3600);

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_running_cycle_refreshes_its_beat_and_never_a_replacements(): void
    {
        $beats = [];
        $this->partialMock(Desk::class, function ($mock) use (&$beats) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () use (&$beats, &$mock) {
                Cache::put('desk:cycle-owner:paper', ['beat' => 1] + Cache::get('desk:cycle-owner:paper'), 3600);
                app(Desk::class)->beatCycleOwner();
                $beats[] = Cache::get('desk:cycle-owner:paper')['beat'];
                Cache::put('desk:cycle-owner:paper', ['host' => 'x', 'pid' => 1, 'role' => 'run', 'token' => 'replacement', 'beat' => 5], 3600);
                $this->assertLostCycleOwner(app(Desk::class));
                $beats[] = Cache::get('desk:cycle-owner:paper')['beat'];

                return new DeskRun;
            });
        });

        app(Desk::class)->cycle();

        $this->assertGreaterThan(1000, $beats[0]);
        $this->assertSame(5, $beats[1]);
    }

    private function interleavingCache(): ArrayStore
    {
        $store = new class extends ArrayStore
        {
            public ?\Closure $afterOwnerRead = null;

            public ?\Closure $afterCycleAcquire = null;

            public function get($key)
            {
                $value = parent::get($key);
                if ($key === 'desk:cycle-owner:paper' && $this->afterOwnerRead !== null) {
                    $callback = $this->afterOwnerRead;
                    $this->afterOwnerRead = null;
                    $callback();
                }

                return $value;
            }

            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($this, $name, $seconds, $owner) extends ArrayLock
                {
                    public function acquire()
                    {
                        $acquired = parent::acquire();
                        if ($acquired && $this->name === 'desk:cycle:paper' && $this->store->afterCycleAcquire !== null) {
                            $callback = $this->store->afterCycleAcquire;
                            $this->store->afterCycleAcquire = null;
                            $callback();
                        }

                        return $acquired;
                    }
                };
            }
        };
        Cache::swap(new Repository($store));

        return $store;
    }

    public function test_a_replacement_cannot_change_owner_between_the_heartbeat_read_and_write(): void
    {
        $store = $this->interleavingCache();
        $competingWriterEntered = null;
        $this->partialMock(Desk::class, function ($mock) use ($store, &$competingWriterEntered) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () use ($store, &$competingWriterEntered) {
                $store->afterOwnerRead = function () use (&$competingWriterEntered) {
                    $guard = Cache::lock('desk:cycle-meta:paper', 10);
                    $competingWriterEntered = $guard->get();
                    if ($competingWriterEntered) {
                        Cache::put('desk:cycle-owner:paper', ['token' => 'replacement', 'beat' => 5], 3600);
                        $guard->release();
                    }
                };
                app(Desk::class)->beatCycleOwner();
                $this->assertFalse($competingWriterEntered, 'replacement must wait until heartbeat check and write finish');

                // Once the heartbeat lets go, a replacement may publish. The old cycle
                // must leave this record alone on any subsequent heartbeat and cleanup.
                Cache::lock('desk:cycle-meta:paper', 10)->get(function () {
                    Cache::lock('desk:cycle:paper')->forceRelease();
                    $this->strand('replacement-host', 1, 'run', 5);
                });
                $this->assertLostCycleOwner(app(Desk::class));

                return new DeskRun;
            });
        });

        app(Desk::class)->cycle();

        $this->assertSame(5, Cache::get('desk:cycle-owner:paper')['beat']);
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_cycle_acquisition_and_owner_publication_share_the_same_guard(): void
    {
        $store = $this->interleavingCache();
        $competingWriterEntered = null;
        $store->afterCycleAcquire = function () use (&$competingWriterEntered) {
            $guard = Cache::lock('desk:cycle-meta:paper', 10);
            $competingWriterEntered = $guard->get();
            if ($competingWriterEntered) {
                // Simulate a paused acquirer whose lease expired before publishing metadata.
                Cache::lock('desk:cycle:paper')->forceRelease();
                $this->strand('replacement-host', 1, 'run', 5);
                $guard->release();
            }
        };
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () {
                $owner = Cache::get('desk:cycle-owner:paper');
                $this->assertTrue(Cache::restoreLock('desk:cycle:paper', $owner['token'])->isOwnedByCurrentProcess());

                return new DeskRun;
            });
        });

        app(Desk::class)->cycle();

        $this->assertFalse($competingWriterEntered, 'a new lock cannot be acquired before its owner guard');
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_foreign_host_api_lock_is_never_cleared(): void
    {
        $this->strand('web-container-id', 1, 'api', time() - 3600);

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_desk_run_boot_clears_the_stale_lock_so_the_first_cycle_runs(): void
    {
        config(['desk.reclaim_foreign_cycle_locks' => true]);
        $this->strand('old-container-id', 1, 'run', time() - 3600);

        $this->artisan('desk:run', ['--once' => true])->assertSuccessful();

        $this->assertSame(1, DeskRun::count(), 'the cycle must not be skipped behind a dead predecessor\'s lock');
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_transient_boot_reclaim_failure_does_not_skip_risk_and_is_retried_when_the_cycle_is_blocked(): void
    {
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('reclaimStaleCycleLocks')->once()->ordered()->andThrow(new LockTimeoutException('busy owner guard'));
            $mock->shouldReceive('riskSweep')->once()->ordered()->andReturn([]);
            $mock->shouldReceive('cycle')->once()->ordered()->andThrow(new CycleInProgressException('paper'));
            $mock->shouldReceive('reclaimStaleCycleLocks')->once()->ordered()->andReturn([]);
        });
        $this->mock(ArenaRunner::class)->shouldReceive('cycle')->once()->andReturn([]);

        $this->artisan('desk:run', ['--once' => true])->assertSuccessful();

        $this->assertTrue(DeskEvent::where('message', 'like', 'boot cycle-lock cleanup deferred:%busy owner guard%')->exists());
    }

    public function test_a_stop_during_risk_starts_no_cycle_or_arena_and_has_no_sleep_penalty(): void
    {
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('riskSweep')->once()->andReturnUsing(function () {
                app(Desk::class)->requestStop();

                return [];
            });
            $mock->shouldNotReceive('cycle');
        });
        $this->mock(ArenaRunner::class)->shouldNotReceive('cycle');
        $started = microtime(true);

        $this->artisan('desk:run')->assertSuccessful();

        $this->assertLessThan(2.0, microtime(true) - $started, 'a cooperative stop between phases must bypass the five-second idle wait');
        $this->assertSame(0, DeskRun::count());
    }

    /** Advance individual bounded operations until the overall phase exceeds the stale-owner threshold. */
    private function advanceOwnedPhase(Desk $desk): void
    {
        $owner = Cache::get('desk:cycle-owner:paper');
        Cache::put('desk:cycle-owner:paper', ['host' => 'active-foreign-container'] + $owner, 3600);
        $this->assertSame([], $desk->reclaimStaleCycleLocks(true), 'a live phase must not be mistaken for a dead foreign runner');
        $this->assertFalse($this->cycleLockIsFree());
        $this->travel((int) ceil($desk->staleBeatSeconds() / 2))->seconds();
    }

    private function assertLostCycleOwner(Desk $desk): void
    {
        try {
            $desk->beatCycleOwner();
        } catch (\RuntimeException $e) {
            $this->assertSame('cycle lock ownership lost; abandoning further cycle work', $e->getMessage());

            return;
        }
        $this->fail('a resumed cycle must abort after losing its ownership token');
    }

    private function ownedPhaseDesk(Executor $executor, ?\Closure $phase, ?Strategy $strategy = null): Desk
    {
        $constructor = (new \ReflectionClass(Desk::class))->getConstructor();
        $dependencies = array_map(fn ($parameter) => app($parameter->getType()->getName()), $constructor->getParameters());
        $desk = \Mockery::mock(Desk::class, $dependencies)->makePartial();
        $desk->shouldReceive('strategy')->andReturn($strategy ?? \Mockery::mock(Strategy::class));
        $desk->shouldReceive('executorForMode')->andReturn($executor);
        if ($phase !== null) {
            $desk->shouldReceive('runCycle')->once()->andReturnUsing($phase);
        }
        $this->app->instance(Desk::class, $desk);

        return $desk->runsAs('run');
    }

    private function entryPipeline(\Closure $duringSize, ?\Closure $duringSend = null): Desk
    {
        $this->mock(Chief::class, function ($mock) {
            $mock->shouldReceive('health')->andReturn(['ready' => true, 'degraded' => false, 'checks' => []]);
            $mock->shouldReceive('halted')->andReturn(null);
            $mock->shouldReceive('heartbeat');
        });
        $this->mock(ProductStatsBuilder::class)->shouldReceive('withBook')->andReturnUsing(fn ($stats) => $stats);
        $candidate = new CandidateRow(ProductStats::fromArray(['product_id' => 'BTC-USD', 'price' => 100]), 1, 'test');
        $strategy = \Mockery::mock(Strategy::class);
        $strategy->shouldReceive('key')->andReturn('mr');
        $strategy->shouldReceive('defaults')->andReturn([]);
        $strategy->shouldReceive('scan')->once()->andReturn([$candidate]);
        $strategy->shouldReceive('vet')->once()->andReturn(Verdict::pass($candidate, []));
        $strategy->shouldReceive('size')->once()->andReturnUsing(function ($verdict) use ($duringSize) {
            $duringSize();

            return new SizeDecision($verdict, 100, 10, 10, true, false, 'test');
        });
        $executor = \Mockery::mock(Executor::class);
        $executor->shouldReceive('mode')->andReturn('paper');
        $executor->shouldReceive('cash')->andReturn(1000);
        if ($duringSend === null) {
            $executor->shouldNotReceive('buy');
        } else {
            $executor->shouldReceive('buy')->once()->andReturnUsing($duringSend);
        }

        return $this->ownedPhaseDesk($executor, null, $strategy);
    }

    public function test_an_owner_replaced_during_sizing_sends_no_order_and_keeps_the_replacements_lock(): void
    {
        $replacementToken = null;
        $desk = $this->entryPipeline(function () use (&$replacementToken) {
            Cache::lock('desk:cycle:paper')->forceRelease();
            $this->strand('replacement', 1, 'run');
            $replacementToken = Cache::get('desk:cycle-owner:paper')['token'];
        });

        $run = $desk->cycle();

        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('cycle lock ownership lost', $run->error);
        $this->assertSame(0, Fill::count());
        $this->assertSame(0, Position::count());
        $this->assertSame($replacementToken, Cache::get('desk:cycle-owner:paper')['token']);
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_stop_received_during_sizing_prevents_the_entry_submission(): void
    {
        $desk = $this->entryPipeline(fn () => app(Desk::class)->requestStop());

        $run = $desk->cycle();

        $this->assertTrue($desk->stopRequested());
        $this->assertSame(0, Fill::count());
        $this->assertSame(0, Position::count());
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_stop_after_executor_preparation_prevents_the_entry_side_effect(): void
    {
        $submitted = false;
        $desk = $this->entryPipeline(static function () {}, function () use (&$submitted) {
            app(Desk::class)->requestStop();
            OrderBudget::assertEntryAllowed();
            $submitted = true;

            return new OrderResult('filled', 100, 100, 1, 100, 100, 0);
        });

        $desk->cycle();

        $this->assertFalse($submitted);
        $this->assertSame(0, Fill::count());
        $this->assertSame(0, Position::count());
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_an_actual_lock_takeover_fences_the_old_cycle_even_if_its_metadata_was_not_replaced(): void
    {
        $desk = $this->ownedPhaseDesk(\Mockery::mock(Executor::class), function () {
            $oldOwner = Cache::get('desk:cycle-owner:paper');
            Cache::lock('desk:cycle:paper')->forceRelease();
            $replacement = Cache::lock('desk:cycle:paper', 3600);
            $this->assertTrue($replacement->get());

            $this->assertLostCycleOwner(app(Desk::class));
            $this->assertSame($oldOwner, Cache::get('desk:cycle-owner:paper'));

            return new DeskRun;
        });

        $desk->cycle();

        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_submitted_fill_is_booked_even_if_stop_and_takeover_arrive_before_its_result(): void
    {
        $replacementToken = null;
        $desk = $this->entryPipeline(static function () {}, function () use (&$replacementToken) {
            app(Desk::class)->requestStop();
            Cache::lock('desk:cycle:paper')->forceRelease();
            $this->strand('replacement', 1, 'run');
            $replacementToken = Cache::get('desk:cycle-owner:paper')['token'];

            return new OrderResult('filled', 100, 100, 1, 100, 100, 0);
        });

        $run = $desk->cycle();

        $this->assertSame('done', $run->status, $run->error ?? '');
        $this->assertSame(1, Fill::count());
        $this->assertSame(1, Position::count());
        $this->assertSame(Position::sole()->id, Fill::sole()->position_id);
        $this->assertSame($replacementToken, Cache::get('desk:cycle-owner:paper')['token']);
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_a_long_universe_phase_keeps_its_foreign_owner_live_between_products(): void
    {
        $this->freezeTime();
        $started = now()->getTimestamp();
        foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $symbol) {
            Product::create(['product_id' => $symbol.'-USD', 'base_currency' => $symbol, 'quote_currency' => 'USD', 'status' => 'online', 'trading_disabled' => false, 'is_tracked' => true]);
        }
        $this->mock(ProductStatsBuilder::class)->shouldReceive('live')->times(4)->andReturnUsing(function (Product $product) {
            $this->advanceOwnedPhase(app(Desk::class));

            return ProductStats::fromArray(['product_id' => $product->product_id, 'price' => 100]);
        });
        $desk = $this->ownedPhaseDesk(\Mockery::mock(Executor::class), function () {
            $this->assertCount(4, app(Desk::class)->universe());
            $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));

            return new DeskRun;
        });

        $desk->cycle();

        $this->assertGreaterThan($desk->staleBeatSeconds(), now()->getTimestamp() - $started);
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_a_long_reconciliation_phase_keeps_its_foreign_owner_live_even_when_lookups_fail(): void
    {
        $this->freezeTime();
        $started = now()->getTimestamp();
        foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $symbol) {
            OrderIntent::create(['venue' => 'test', 'mode' => 'live', 'desk_product_id' => $symbol.'-USD', 'venue_product_id' => $symbol.'-USD', 'client_order_id' => $symbol, 'method' => 'buy', 'status' => 'pending', 'requested_usd' => 100, 'decision_price' => 100, 'side' => 'BUY']);
        }
        $executor = \Mockery::mock(Executor::class.', '.ReconcilesOrders::class);
        $executor->shouldReceive('mode')->andReturn('live');
        $executor->shouldReceive('orderVenue')->andReturn('test');
        $executor->shouldReceive('reconcile')->times(4)->andReturnUsing(function () {
            $this->advanceOwnedPhase(app(Desk::class));
            throw new \RuntimeException('bounded venue timeout');
        });
        $strategy = \Mockery::mock(Strategy::class);
        $desk = $this->ownedPhaseDesk($executor, function () use ($executor, $strategy) {
            app(Desk::class)->reconcileLiveOrders($strategy, $executor);
            $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));

            return new DeskRun;
        });

        $desk->cycle();

        $this->assertGreaterThan($desk->staleBeatSeconds(), now()->getTimestamp() - $started);
        $this->assertSame(4, OrderIntent::pending()->count(), 'proof of life must not resolve unknown orders');
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_cycle_records_its_owner_and_forgets_it_on_release(): void
    {
        $seen = [];
        $this->partialMock(Desk::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () use (&$seen) {
                $seen[] = Cache::get('desk:cycle-owner:paper');

                return new DeskRun;
            });
        });

        app(Desk::class)->runsAs('run')->cycle();

        $this->assertSame(['host' => gethostname(), 'pid' => getmypid(), 'role' => 'run'], array_diff_key($seen[0], ['token' => 1, 'beat' => 1]));
        $this->assertNull(Cache::get('desk:cycle-owner:paper'));
        $this->assertTrue($this->cycleLockIsFree());
    }

    public function test_sigterm_winds_the_loop_down_and_releases_the_cycle_lock(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl not available');
        }
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('riskSweep')->andReturn([]);
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () {
                posix_kill((int) getmypid(), SIGTERM);

                return new DeskRun(['status' => 'done']);
            });
        });
        $this->mock(ArenaRunner::class)->shouldNotReceive('cycle');
        $started = microtime(true);

        $this->artisan('desk:run')->assertSuccessful();

        $this->assertLessThan(2.0, microtime(true) - $started, 'SIGTERM received inside the cycle must bypass the idle wait');
        $this->assertTrue($this->cycleLockIsFree(), 'the lock must not outlive a stopped desk:run');
        $this->assertNull(Cache::get('desk:cycle-owner:paper'));
    }

    public function test_a_token_matched_release_never_frees_a_replacement_cycles_lock(): void
    {
        $this->strand('old-container-id', 1, 'run', time() - 3600);
        $stale = Cache::get('desk:cycle-owner:paper');
        // The dead owner's lock expires and a replacement cycle takes over after the reclaim read the stale record.
        Cache::lock('desk:cycle:paper')->forceRelease();
        $replacement = Cache::lock('desk:cycle:paper', 3600);
        $this->assertTrue($replacement->get());

        $this->assertFalse(Cache::restoreLock('desk:cycle:paper', $stale['token'])->release());
        $this->assertFalse($this->cycleLockIsFree(), 'the replacement keeps its lock');
    }

    public function test_cycle_does_not_forget_a_replacements_owner_record(): void
    {
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturnUsing(function () {
                // Our lease lapsed mid-cycle and a replacement took over and recorded itself.
                Cache::lock('desk:cycle:paper')->forceRelease();
                $this->strand('other-host', 1, 'run');

                return new DeskRun;
            });
        });

        app(Desk::class)->cycle();

        $this->assertNotNull(Cache::get('desk:cycle-owner:paper'), 'the replacement keeps its owner record');
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_reclaim_leaves_a_lock_a_replacement_took_after_the_stale_owner_was_read(): void
    {
        $this->strand('old-container-id', 1, 'run', time() - 3600);
        $replacementToken = null;
        // The stale record is read, then a replacement cycle takes the lock before the release acts.
        $this->partialMock(Desk::class, function ($mock) use (&$replacementToken) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('withOwnerGuard')->andReturnUsing(function (string $mode, \Closure $work) use (&$replacementToken) {
                Cache::lock('desk:cycle:paper')->forceRelease();
                $replacement = Cache::lock('desk:cycle:paper', 3600);
                $replacement->get();
                $replacementToken = $replacement->owner();

                return $work();
            });
        });
        // Re-seed the stale record the guard stub's takeover left in place.
        Cache::put('desk:cycle-owner:paper', ['host' => 'old-container-id', 'pid' => 1, 'role' => 'run', 'token' => 'stale-token', 'beat' => time() - 3600], 3600);

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks(true));
        $this->assertFalse($this->cycleLockIsFree(), 'the replacement keeps its lock');
        $this->assertTrue(Cache::restoreLock('desk:cycle:paper', $replacementToken)->isOwnedByCurrentProcess(), 'the lock must still belong to the actual replacement token');
    }

    public function test_the_lock_is_released_when_the_owner_guard_times_out_before_the_cycle(): void
    {
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('withOwnerGuard')->andThrow(new LockTimeoutException);
        });

        try {
            app(Desk::class)->cycle();
        } catch (\Throwable) {
            // the cycle may fail; the lock must not outlive it
        }

        $this->assertTrue($this->cycleLockIsFree(), 'a failure between acquire and the cycle must not leak the lock');
    }

    public function test_the_lock_is_released_when_the_owner_guard_times_out_after_the_cycle(): void
    {
        $calls = 0;
        $this->partialMock(Desk::class, function ($mock) use (&$calls) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
            $mock->shouldReceive('executorForMode')->andReturn(\Mockery::mock(Executor::class));
            $mock->shouldReceive('runCycle')->once()->andReturn(new DeskRun);
            $mock->shouldReceive('withOwnerGuard')->andReturnUsing(function (string $mode, \Closure $work) use (&$calls) {
                if (++$calls > 1) {
                    throw new LockTimeoutException;
                }

                return $work();
            });
        });

        app(Desk::class)->cycle();

        $this->assertTrue($this->cycleLockIsFree(), 'a guard timeout on release must still free the cycle lock');
    }

    public function test_an_unanswerable_liveness_check_keeps_the_lock(): void
    {
        $this->strand(gethostname(), self::DEAD_PID, 'run');
        $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('posixAvailable')->andReturn(false);
        });

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks());
        $this->assertFalse($this->cycleLockIsFree(), 'no posix means we cannot prove the owner dead');
    }

    public function test_an_invalid_pid_in_the_owner_record_keeps_the_lock(): void
    {
        $this->strand(gethostname(), 0, 'run');

        $this->assertSame([], app(Desk::class)->reclaimStaleCycleLocks());
        $this->assertFalse($this->cycleLockIsFree());
    }

    public function test_every_overlap_mutex_expires_in_minutes_not_the_24h_default(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->withoutOverlapping);

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertLessThanOrEqual(60, $event->expiresAt, $event->command.' keeps its mutex for '.$event->expiresAt.' minutes');
        }
    }
}
