<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Exceptions\CycleInProgressException;
use App\Desk\Execution\ExecutionModeMismatchException;
use App\Desk\Execution\Executor;
use App\Desk\Execution\MarginBook;
use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\OrderBudget;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Execution\Perps;
use App\Desk\Execution\PerpsCalendar;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use App\Desk\Execution\PostOnlyShadows;
use App\Desk\Execution\ReconcilesOrders;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Exchange\Contracts\Exchange;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\OrderIntent;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\Product;
use App\Models\RiskCheck;
use App\Services\Market\ProductStatsBuilder;
use App\Support\Fees;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock as CacheLockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The pipeline. SCAN -> VET -> SIZE -> FILLS in cycle(); RISK in riskSweep()
 * on its own timer. Strategies decide, this class does everything else.
 */
class Desk
{
    /** Set only through forSeat() — null means the main desk's own account. */
    private ?int $arenaSeatId = null;

    /** DeskContext param overrides layered on top of the merged settings — a seat's pinned plugin version or built-in param set. Set only through forSeat(). */
    private array $paramOverrides = [];

    private string $cycleRole = 'api';

    private bool $stopRequested = false;

    /** [mode, token] of the cycle lock this instance currently holds, for owner heartbeats. */
    private ?array $heldCycle = null;

    public function __construct(
        private StrategyRegistry $strategies,
        private Settings $settings,
        private ProductStatsBuilder $stats,
        private BankService $bankService,
        private Reporter $reporter,
        private Chief $chief,
        private PostOnlyShadows $postOnlyShadows,
        private Exchange $exchange,
    ) {}

    // ------------------------------------------------------------------
    // wiring
    // ------------------------------------------------------------------

    /**
     * A Desk scoped to one arena seat's own paper account: every position/fill/ledger read or
     * write this clone makes carries $seatId instead of the main desk's null, and every
     * DeskContext it builds layers $paramOverrides on top (a seat's pinned json.plugin_version_id,
     * or a built-in strategy's own param set) — same services, same Chief/Reporter, just a
     * different account and a different strategy pin. Used by ArenaRunner, one clone per seat.
     */
    public function forSeat(?int $seatId, array $paramOverrides = []): static
    {
        $clone = clone $this;
        $clone->arenaSeatId = $seatId;
        $clone->paramOverrides = $paramOverrides;
        // The runner's signal handler stops the root desk, including seats cloned before the signal.
        // Share only cancellation; account params and cycle ownership stay local to each instance.
        $clone->stopRequested = &$this->stopRequested;

        return $clone;
    }

    public function mode(): string
    {
        return $this->settings->mode();
    }

    public function executor(): Executor
    {
        return $this->executorForMode($this->mode());
    }

    /** Build the executor for an explicit mode, independent of the desk's current global mode setting. */
    public function executorForMode(string $mode): Executor
    {
        if ($mode === 'live') {
            if (config('desk.live_confirm') !== 'yes') {
                throw new \RuntimeException('Live mode requires DESK_LIVE_CONFIRM=yes in .env. Paper mode is the default.');
            }

            return $this->exchange->executor(Perps::enabled());
        }

        return app(PaperExecutor::class)->withSeat($this->arenaSeatId);
    }

    public function strategy(): Strategy
    {
        return $this->strategies->make();
    }

    /** @return array<int, Position> */
    public function openPositions(?string $mode = null): array
    {
        return Position::open()->mode($mode ?? $this->mode())->seat($this->arenaSeatId)->orderBy('opened_at')->get()->all();
    }

    public function context(Strategy $strategy, bool $degraded = false, ?string $mode = null): DeskContext
    {
        $ctx = new DeskContext($this->settings->merged($strategy), $mode ?? $this->mode(), $degraded, $this->openPositions($mode), arenaSeatId: $this->arenaSeatId);

        return $this->paramOverrides === [] ? $ctx : $ctx->withParams($this->paramOverrides);
    }

    public function bank(?Executor $executor = null, bool $snapshot = false): Bank
    {
        $executor ??= $this->executor();

        return $this->bankService->current($executor, $this->openPositions($executor->mode()), $snapshot, $this->arenaSeatId);
    }

    private function paperMarginEnabled(): bool
    {
        return (bool) $this->settings->get('perps.paper_margin', config('desk.perps.paper_margin'));
    }

    /** The paper venue's own margin snapshot, built from the same open positions and ledger cash live reads off CFM. */
    public function paperSession(?Executor $executor = null): PerpsSession
    {
        $executor ??= $this->executor();

        return MarginBook::fromPositions(
            $executor->cash(),
            $this->openPositions($executor->mode()),
            fn (Position $p) => (float) ($p->last_price ?? $p->entry_price),
            MarginWindow::Overnight->marginRate(),
            (float) config('desk.perps.maintenance_margin_pct') / 100,
        );
    }

    // ------------------------------------------------------------------
    // SCAN -> VET -> SIZE -> FILLS
    // ------------------------------------------------------------------

    /**
     * One cycle per mode at a time. A cycle started while another (desk:run, the scheduler, the API)
     * is mid-flight would re-read the same open position and book a second full-size add, so entry
     * is guarded by a non-blocking lock; the loser gets CycleInProgressException and decides whether
     * to skip quietly (console) or report 409 (API). Released in finally, so a cycle that throws
     * never leaves the mode wedged; the TTL only matters if the process is killed mid-cycle, and
     * reclaimStaleCycleLocks() clears that case at desk:run boot instead of waiting out the TTL.
     *
     * @throws CycleInProgressException
     */
    public function cycle(): DeskRun
    {
        $mode = $this->mode();
        $lock = Cache::lock("desk:cycle:{$mode}", $this->cycleLockSeconds());
        $token = $lock->owner();
        $acquired = false;

        try {
            $this->withOwnerGuard($mode, function () use ($lock, $mode, $token, &$acquired) {
                if (! $lock->get()) {
                    throw new CycleInProgressException($mode);
                }
                $acquired = true;
                $this->heldCycle = [$mode, $token];
                Cache::put("desk:cycle-owner:{$mode}", [
                    'host' => gethostname(),
                    'pid' => getmypid(),
                    'role' => $this->cycleRole,
                    'token' => $token,
                    'beat' => now()->getTimestamp(),
                ], $this->cycleLockSeconds());
            });

            // Executor comes from the mode the lock was taken for; re-reading the global mode here could pair
            // this lock with an executor for a mode changed mid-request.
            return $this->runCycle($this->strategy(), $this->executorForMode($mode), null);
        } finally {
            if ($acquired) {
                $this->heldCycle = null;
                try {
                    $this->withOwnerGuard($mode, function () use ($lock, $mode, $token) {
                        $recorded = Cache::get("desk:cycle-owner:{$mode}");
                        if ($lock->release() && ($recorded['token'] ?? null) === $token) {
                            Cache::forget("desk:cycle-owner:{$mode}");
                        }
                    });
                } catch (\Throwable) {
                    // Guard timed out: the owner record is best-effort (it expires, and reclaim is token-checked).
                } finally {
                    // The lock itself is never left to the guard: token-matched, so a no-op if already released or replaced.
                    $lock->release();
                }
            }
        }
    }

    /**
     * Owner metadata and the cycle lock change hands together: every write, release and reclaim runs under
     * this short per-mode guard, so a replacement cycle can never have its owner record wiped by a cleanup
     * that started before it acquired the lock.
     */
    protected function withOwnerGuard(string $mode, \Closure $work): mixed
    {
        return Cache::lock("desk:cycle-meta:{$mode}", 10)->block(5, $work);
    }

    /**
     * Proof of life for the cycle lock this instance holds, refreshed throughout owned work. A replacement desk
     * on another host may only reclaim a foreign lock whose beat has gone quiet (see staleBeatSeconds()).
     * The owner guard keeps the token check and heartbeat write atomic with replacement/reclaim.
     */
    public function beatCycleOwner(): void
    {
        if ($this->heldCycle === null) {
            return;
        }
        [$mode, $token] = $this->heldCycle;
        $key = "desk:cycle-owner:{$mode}";
        $this->withOwnerGuard($mode, function () use ($mode, $key, $token) {
            $record = Cache::get($key);
            if (! is_array($record) || ($record['token'] ?? null) !== $token
                || ! Cache::restoreLock("desk:cycle:{$mode}", $token)->isOwnedByCurrentProcess()) {
                throw new \RuntimeException('cycle lock ownership lost; abandoning further cycle work');
            }
            Cache::put($key, ['beat' => now()->getTimestamp()] + $record, $this->cycleLockSeconds());
        });
    }

    /**
     * A foreign desk:run may still be inside docker's stop grace (60s, compose) finishing an in-flight entry
     * under a mutate lock (OrderBudget::lockSeconds()); reclaiming before both have elapsed risks a duplicate
     * full-size entry. Keep this >= stop_grace_period + mutate lock TTL.
     */
    public function staleBeatSeconds(): int
    {
        return 60 + OrderBudget::lockSeconds() + 30;
    }

    /** Who is taking cycle locks from this instance: 'api' (default), or 'run' for the desk:run loop. */
    public function runsAs(string $role): static
    {
        $this->cycleRole = $role;

        return $this;
    }

    /** Cooperative stop (SIGTERM): the in-flight cycle starts no new entries and unwinds through its finally. */
    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }

    /**
     * Boot-time cleanup for a cycle lock left by a process that was killed mid-cycle (OOM, SIGKILL after
     * the stop grace period, host reboot) — otherwise no entries for up to the 1h lease while the
     * watchdog reports the desk running. A lock is only cleared when its recorded owner is provably dead:
     *   - same host and the recorded pid is gone, or is our own pid (a restarted container reuses pid 1,
     *     and at boot we hold nothing); a live other pid on this host (a second `bin/desk` dev run, a
     *     scheduler or API cycle) is left alone, as is a lock with no owner record;
     *   - a different host (docker recreate gives the container a new hostname) only when the owner was
     *     a desk:run loop AND $trustSingleRunner is set — compose pins exactly one desk replica, so a
     *     foreign desk:run owner is the dead predecessor. Bare-metal setups leave that off.
     *
     * @return list<string> modes whose lock was cleared
     */
    public function reclaimStaleCycleLocks(bool $trustSingleRunner = false): array
    {
        $cleared = [];
        foreach (['paper', 'live'] as $mode) {
            $reclaimed = $this->withOwnerGuard($mode, function () use ($mode, $trustSingleRunner) {
                $key = "desk:cycle:{$mode}";
                $probe = Cache::lock($key, 5);
                if ($probe->get()) {
                    $probe->release();
                    Cache::forget("desk:cycle-owner:{$mode}");

                    return null;
                }
                $owner = Cache::get("desk:cycle-owner:{$mode}");
                if (! is_array($owner) || ! isset($owner['host'], $owner['pid'], $owner['token'])) {
                    return null;
                }
                $sameHost = $owner['host'] === gethostname();
                $dead = $sameHost
                    ? ((($owner['role'] ?? null) === 'run' && (int) $owner['pid'] === getmypid()) || $this->pidPositivelyDead((int) $owner['pid']))
                    : ($trustSingleRunner && ($owner['role'] ?? null) === 'run' && $this->beatIsStale($owner));
                // Token-matched release: if a replacement cycle took the lock since we read the record, this is a no-op.
                if (! $dead || ! Cache::restoreLock($key, $owner['token'])->release()) {
                    return null;
                }
                Cache::forget("desk:cycle-owner:{$mode}");
                $this->reporter->warn('CHIEF', "cleared stale {$key} lock left by {$owner['host']}:{$owner['pid']}");

                return $mode;
            });
            if ($reclaimed !== null) {
                $cleared[] = $reclaimed;
            }
        }

        return $cleared;
    }

    /** A record with no beat (written before heartbeats existed) is kept: we cannot prove its owner stopped. */
    private function beatIsStale(array $owner): bool
    {
        if (! isset($owner['beat'])) {
            return false;
        }
        $quiet = now()->getTimestamp() - (int) $owner['beat'];
        if ($quiet <= $this->staleBeatSeconds()) {
            $this->reporter->warn('CHIEF', sprintf('keeping desk:cycle lock of %s:%s — last heartbeat %ds ago, owner may still be stopping', $owner['host'], $owner['pid'], $quiet));

            return false;
        }

        return true;
    }

    /** True only when the OS affirmatively says the pid does not exist (ESRCH); an unanswerable check means alive. */
    private function pidPositivelyDead(int $pid): bool
    {
        if ($pid <= 0 || ! $this->posixAvailable()) {
            return false;
        }

        return ! posix_kill($pid, 0) && posix_get_last_error() === 3;
    }

    protected function posixAvailable(): bool
    {
        return function_exists('posix_kill');
    }

    /**
     * Lease far longer than the cycle budget (config desk.cycle_budget_seconds), which stops new entries
     * well before this expires; the slack covers the one in-flight entry (mutate lock TTL) at the deadline.
     */
    private function cycleLockSeconds(): int
    {
        return max(3600, $this->cycleBudgetSeconds() * 3);
    }

    private const DEFAULT_CYCLE_BUDGET_SECONDS = 1200;

    private const MAX_ADDS_PER_POSITION = 5;

    private function cycleBudgetSeconds(): int
    {
        $raw = config('desk.cycle_budget_seconds', self::DEFAULT_CYCLE_BUDGET_SECONDS);

        return $this->validCycleBudget($raw) ? (int) $raw : self::DEFAULT_CYCLE_BUDGET_SECONDS;
    }

    private function validCycleBudget(mixed $raw): bool
    {
        return (is_int($raw) && $raw >= 0) || (is_string($raw) && ctype_digit($raw));
    }

    /**
     * SCAN -> VET -> SIZE -> FILLS for one strategy/executor pair. Shared by cycle() (the main
     * desk, $universe rebuilt live every call) and ArenaRunner (one call per active seat, all
     * sharing the one $universe snapshot ArenaRunner builds per arena cycle) — this is the whole
     * per-strategy portion of the pipeline; nothing above forks it.
     *
     * @param  array<int, ProductStats>|null  $universe  reuse an already-built snapshot instead of fetching a fresh one.
     */
    public function runCycle(Strategy $strategy, Executor $executor, ?array $universe): DeskRun
    {
        $this->beatCycleOwner();
        $health = $this->chief->health();

        $run = DeskRun::create([
            'arena_seat_id' => $this->arenaSeatId,
            'mode' => $executor->mode(),
            'strategy' => $strategy->key(),
            'degraded' => $health['degraded'],
            'started_at' => now(),
        ]);
        $this->reporter->runId = $run->id;
        $cycleDeadline = microtime(true) + $this->cycleBudgetSeconds();
        if (! $this->validCycleBudget(config('desk.cycle_budget_seconds', self::DEFAULT_CYCLE_BUDGET_SECONDS))) {
            $this->reporter->warn('CHIEF', sprintf('DESK_CYCLE_BUDGET_SECONDS is not a non-negative integer — using %ds', self::DEFAULT_CYCLE_BUDGET_SECONDS));
        }

        try {
            if ($this->chief->halted()) {
                throw new \RuntimeException('desk is halted: '.($this->chief->halted()['reason'] ?? ''));
            }
            if (! $health['ready']) {
                $this->reporter->warn('CHIEF', 'not READY — fewer than three green checks; no new entries this cycle', $health['checks']);
                $run->update(['status' => 'done', 'finished_at' => now(), 'stats' => ['health' => $health['checks']]]);

                return $run;
            }

            $this->reconcileLiveOrders($strategy, $executor);
            $this->beatCycleOwner();

            $ctx = $this->context($strategy, $health['degraded'], $executor->mode());
            $bank = $this->bank($executor, true);

            // ---- SCAN ------------------------------------------------
            $this->chief->heartbeat('SCAN');
            $universe ??= $this->universe();
            $this->beatCycleOwner();
            $candidates = $strategy->scan($universe, $ctx);
            $this->beatCycleOwner();
            $run->products_scanned = count($universe);

            // Order-book depth only for the shortlist that will actually be vetted (rate limits) — the
            // same truncation the shortlist itself is supposed to apply, enforced here too in case a
            // strategy's scan() ever returns more than its own scan.max_candidates. Each fetch can take
            // up to config('coinbase.timeout'); a fixed wall-clock budget across the whole batch means
            // a slow book (or a long shortlist) can no longer stall the cycle — once spent, the
            // remaining candidates scan without depth (a live degraded-quote state, not a stall).
            $maxCandidates = (int) $ctx->param('scan.max_candidates', 10);
            if (count($candidates) > $maxCandidates) {
                $candidates = array_slice($candidates, 0, $maxCandidates);
            }
            $bookBudgetSeconds = (float) config('desk.scan.book_fetch_budget_seconds', 5.0);
            $bookDeadline = microtime(true) + $bookBudgetSeconds;
            $booksSkipped = 0;
            $candidates = array_map(function (CandidateRow $c) use ($bookDeadline, &$booksSkipped) {
                $this->beatCycleOwner();
                if ($this->stopRequested || microtime(true) >= $bookDeadline) {
                    $booksSkipped++;

                    return new CandidateRow($c->stats, $c->score, $c->rankReason, 0, $c->context, $c->degraded, $c->edgeProbability, $c->payoffRatio, $c->side);
                }

                return new CandidateRow($this->stats->withBook($c->stats), $c->score, $c->rankReason, 0, $c->context, $c->degraded, $c->edgeProbability, $c->payoffRatio, $c->side);
            }, $candidates);
            if ($booksSkipped > 0) {
                $this->reporter->warn('SCAN', "book-fetch budget ({$bookBudgetSeconds}s) spent — {$booksSkipped} candidate(s) scanned without depth");
            }

            $rows = [];
            foreach ($candidates as $i => $c) {
                $this->beatCycleOwner();
                $c->rank = $i + 1;
                $rows[$c->productId()] = Candidate::create([
                    'desk_run_id' => $run->id,
                    'product_id' => $c->productId(),
                    'rank' => $c->rank,
                    'rank_reason' => mb_substr($c->rankReason, 0, 255),
                    'score' => $c->score,
                    'metrics' => $c->stats->jsonSerialize(),
                    'context' => $c->context,
                    'degraded' => $c->degraded,
                ]);
            }
            $run->candidates = count($candidates);
            $this->reporter->info('SCAN', sprintf('%d products scanned, %d candidates', count($universe), count($candidates)), [
                'top' => array_map(fn ($c) => [$c->productId(), $c->score, $c->rankReason], array_slice($candidates, 0, 5)),
            ]);

            // ---- VET -> SIZE -> FILLS, one candidate at a time --------
            // Context is rebuilt per candidate so capacity and free cash reflect fills made this cycle.
            foreach ($candidates as $c) {
                if ($this->stopRequested) {
                    $this->reporter->warn('CHIEF', 'stop requested — no further candidates this cycle');

                    break;
                }
                if (microtime(true) >= $cycleDeadline) {
                    $this->reporter->warn('CHIEF', sprintf('cycle budget (%ds) spent — no further candidates this cycle', $this->cycleBudgetSeconds()));

                    break;
                }
                $this->beatCycleOwner();
                $this->chief->heartbeat('VET');
                $ctx = $this->context($strategy, $health['degraded'], $executor->mode());
                $bank = $this->bank($executor);
                $v = $strategy->vet($c, $bank, $ctx);
                $rows[$c->productId()]->update([
                    'verdict' => $v->verdict,
                    'failed_check' => $v->failedCheck,
                    'checks_run' => $v->checksRun,
                    'checks_skipped' => $v->checksSkipped,
                    'evidence' => $v->evidence,
                    'why' => $v->why,
                ]);
                if (! $v->passed()) {
                    $run->rejected++;
                    $this->reporter->info('VET', "REJECT {$c->productId()} [{$v->failedCheck}] {$v->why}");

                    continue;
                }
                $run->passed++;

                $this->chief->heartbeat('SIZE');
                $size = $strategy->size($v, $bank, $ctx);
                $rows[$c->productId()]->update([
                    'size_usd' => $size->dollars,
                    'pct_of_free_cash' => max(-1e9, min(1e9, $size->percentOfFreeCash)), // columns are decimal(14,4)
                    'pct_of_bank' => max(-1e9, min(1e9, $size->percentOfBank)),
                    'exitable' => $size->exitable,
                    'ceiling_applied' => $size->ceilingApplied,
                    'size_why' => $size->why,
                ]);
                if ($size->zero()) {
                    $this->reporter->info('SIZE', "0 for {$c->productId()}: {$size->why}");

                    continue;
                }

                $this->chief->heartbeat('FILLS');
                if ($this->chief->halted()) {
                    $this->reporter->warn('FILLS', 'desk halted mid-cycle — no further entries this cycle');

                    break;
                }
                try {
                    $fill = $this->enter($executor, $size, $ctx, $run, $rows[$c->productId()], $cycleDeadline);
                } catch (LockTimeoutException $e) {
                    // Someone else is still mid-mutation on this product (a concurrent close/trim, or a
                    // slow exchange call from another cycle) — skip this candidate this cycle rather than
                    // aborting the whole batch; the rest of the shortlist still gets its turn.
                    $this->reporter->warn('FILLS', "{$c->productId()}: mutate lock timed out, skipping this candidate this cycle");

                    continue;
                }
                if ($fill?->status === 'filled') {
                    $run->filled++;
                }
            }
            if ($candidates !== [] && $run->rejected === 0) {
                $this->reporter->warn('VET', 'zero rejections this cycle — if this persists the filters are misconfigured');
            }
            $bank = $this->bank($executor);

            $run->update(['status' => 'done', 'finished_at' => now(), 'stats' => [
                'bank' => $bank->toArray(),
                'health' => $health['checks'],
            ]]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'error', 'finished_at' => now(), 'error' => $e->getMessage()]);
            $this->reporter->error('CHIEF', 'cycle failed: '.$e->getMessage(), ['trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5)]);
        } finally {
            $this->reporter->runId = null;
        }

        return $run->fresh();
    }

    /** @return array<int, ProductStats> */
    public function universe(): array
    {
        $open = array_map(fn (Position $p) => $p->product_id, $this->openPositions());
        $q = Product::tradeable()->where(fn ($q) => $q->where('is_tracked', true)->orWhereIn('product_id', $open));
        if (Perps::enabled()) {
            // Perps: only symbols with a mapped contract can be executed, so only they are scanned.
            $active = (array) config('desk.perps.active', []);
            $q->whereIn('product_id', $active !== [] ? $active : array_keys((array) config('desk.perps.map', [])));
        }
        $products = $q->orderByDesc('volume_24h_usd')->get();

        $out = [];
        foreach ($products as $p) {
            if ($this->stopRequested) {
                break;
            }
            $this->beatCycleOwner();
            try {
                $out[] = $this->stats->live($p);
            } catch (\Throwable $e) {
                $this->reporter->warn('SCAN', "stats failed for {$p->product_id}: ".$e->getMessage());
            } finally {
                $this->beatCycleOwner();
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // FILLS
    // ------------------------------------------------------------------

    /**
     * Seconds a `desk:mutate:{mode}:{pid}` lock is held for. Must outlast the exchange call it wraps:
     * worst case is config('coinbase.timeout') per HTTP call × several settlement polls, so a fixed
     * 10s TTL used to expire while the holder was still inside it, letting a second caller acquire the
     * lock, re-read the still-open position, and send a second close (a netting venue opens the
     * opposite position from that). Bounded below at 90s regardless of a tiny configured timeout.
     */
    private function mutateLockSeconds(): int
    {
        return OrderBudget::lockSeconds();
    }

    /**
     * One lock per product/mode mutation. Each call gets its own randomly-owned Lock instance (see
     * Illuminate\Cache\Lock) so `block()`'s own release() only ever unlocks a lock this call acquired
     * — never a lock some other caller (or a previous, TTL-expired attempt) is still holding.
     * Protected so tests can substitute a lock that fails/times out without real concurrency or sleeps.
     */
    protected function mutateLock(string $mode, string $pid): CacheLockContract
    {
        return Cache::lock("desk:mutate:{$mode}:{$pid}", $this->mutateLockSeconds());
    }

    private function enter(Executor $executor, SizeDecision $size, DeskContext $ctx, DeskRun $run, Candidate $row, float $cycleDeadline): ?Fill
    {
        $c = $size->verdict->candidate;
        $pid = $c->productId();
        $mode = $executor->mode();

        // Serialize every mutation of this product's position/cash under one lock, and re-read the
        // position fresh under it — the copy on $ctx was snapshotted before this candidate's turn and
        // can be stale if another caller (API close/trim, a parallel cycle) touched it meanwhile.
        return $this->mutateLock($mode, $pid)->block(5, function () use ($executor, $size, $ctx, $run, $row, $c, $pid, $mode, $cycleDeadline) {
            return $this->doEnter($executor, $size, $ctx, $run, $row, $c, $pid, $mode, $cycleDeadline);
        });
    }

    private function doEnter(Executor $executor, SizeDecision $size, DeskContext $ctx, DeskRun $run, Candidate $row, CandidateRow $c, string $pid, string $mode, float $cycleDeadline): ?Fill
    {
        if ($this->entriesHaltedByStuckOrder($executor, $pid)) {
            $this->reporter->warn('FILLS', "{$pid}: entry skipped, a live order on it has been unresolved too long (desk:intents:resolve)");

            return null;
        }

        $existing = Position::open()->mode($mode)->seat($this->arenaSeatId)->where('product_id', $pid)->first();
        if ($existing && $this->refusesVenue($existing, $executor, 'ADD')) {
            return null;
        }
        $kind = $existing ? 'add' : 'entry';

        $short = $c->isShort();
        $orderSide = $short ? 'SELL' : 'BUY';
        if ($existing && $existing->isShort() !== $short) {
            $this->reporter->warn('FILLS', "{$pid}: {$c->side} candidate while a ".($existing->side).' position is open — skipped (RISK closes first)');

            return null;
        }

        if (Perps::enabled() && PerpsCalendar::halted(now()->toDateTimeImmutable())) {
            Fill::create($this->fillAttrs($run, $existing, $pid, $orderSide, $kind, OrderResult::rejected('halt', $size->dollars, $c->stats->price, 'CFM weekly halt'), $executor->mode()));

            return null;
        }

        // Fee floor, computed BEFORE sending.
        $eff = Fees::effectiveRate($size->dollars, (float) $ctx->param('fees.taker_rate', 0.006), (float) $ctx->param('fees.floor_usd', 0));
        if ($eff > (float) $ctx->param('fees.max_effective_fee_pct', 0.02)) {
            $this->reporter->warn('FILLS', sprintf('FEE_FLOOR %s: %.2f%% effective on $%.2f', $pid, $eff * 100, $size->dollars));
            Fill::create($this->fillAttrs($run, $existing, $pid, $orderSide, $kind, OrderResult::rejected('fee_floor', $size->dollars, $c->stats->price, 'fee floor'), $executor->mode()));

            return null;
        }

        if ($executor instanceof PaperExecutor && Perps::enabled() && $this->paperMarginEnabled()) {
            $gate = PerpsGate::newRisk($this->paperSession($executor), now()->toDateTimeImmutable(), $size->dollars, config('desk.perps'));
            if (! $gate->allowed) {
                Fill::create($this->fillAttrs($run, $existing, $pid, $orderSide, $kind, OrderResult::rejected('rejected', $size->dollars, $c->stats->price, (string) $gate->reason), $executor->mode()));

                return null;
            }
        }

        if ($this->stopRequested) {
            $this->reporter->warn('FILLS', "{$pid}: stop requested — entry skipped");

            return null;
        }
        if (microtime(true) >= $cycleDeadline) {
            $this->reporter->warn('FILLS', "{$pid}: cycle budget spent — entry skipped");

            return null;
        }

        // Vetting, sizing or waiting for the product lock may have outlived our cycle lease.
        // Fence a resumed old owner before sending; an already-sent order must still be booked below.
        $entryFence = $this->assertEntryAllowed(...);
        $entryFence();
        // The exchange call stays outside the transaction — a DB rollback cannot un-send an order.
        $result = OrderBudget::withEntryFence(
            $entryFence,
            fn () => $short ? $executor->openShort($pid, $size->dollars, $c->stats->price) : $executor->buy($pid, $size->dollars, $c->stats->price),
        );

        $pluginVersionId = $existing ? null : $this->pinnedPluginVersionId($run->strategy, $ctx);

        [$fill, $position] = DB::transaction(function () use ($run, $existing, $pid, $orderSide, $kind, $result, $executor, $c, $row, $short, $pluginVersionId) {
            $fill = Fill::create($this->fillAttrs($run, $existing, $pid, $orderSide, $kind, $result, $executor->mode()));
            $this->resolveIntentFor($result);

            if (! $result->ok()) {
                return [$fill, $existing];
            }

            $margin = isset($result->raw['margin']) ? (float) $result->raw['margin'] : null;

            if ($existing) {
                // Add-restraint knobs (e.g. a strategy's own add_min_bars_between /
                // add_requires_new_signal) stamp these onto the strategy's own in-memory Position copy
                // during scan(), but Desk rebuilds $existing fresh from the DB per candidate and never
                // sees that copy again — so that stamp never reached disk and live never honoured it
                // (only backtests, which keep positions in memory across bars, did). Persist here, where
                // the fill that actually happened is recorded. initial_cost_usd is never touched again
                // once set on the first entry below.
                $this->bookAdd($existing, $result, $margin, $this->candidateSignalTs($c));
                $fill->update(['position_id' => $existing->id]);
                $position = $existing;
            } else {
                $meta = ['decision_price' => $c->stats->price, 'score' => $c->score, 'rank_reason' => $c->rankReason, 'entry_fees_usd' => $result->feeUsd];
                if ($margin !== null) {
                    $meta['margin_usd'] = $margin;
                }
                // Same add-restraint bookkeeping as the add branch above: initial_cost_usd is the true
                // cost basis a strategy's add sizing caps against, set once, here, on the fill that
                // actually opened the position — never recomputed or overwritten by a later add.
                $meta['initial_cost_usd'] = $result->filledUsd;
                $meta['last_action_ts'] = now()->getTimestamp();
                $meta['last_signal_ts'] = $this->candidateSignalTs($c);
                $position = Position::create([
                    'arena_seat_id' => $this->arenaSeatId,
                    'mode' => $executor->mode(),
                    'venue' => $this->venueOf($executor),
                    'strategy' => $run->strategy,
                    'plugin_version_id' => $pluginVersionId,
                    'product_id' => $pid,
                    'side' => $short ? 'short' : 'long',
                    'quantity' => $result->filledQty,
                    'entry_price' => $result->fillPrice,
                    'entry_usd' => $result->filledUsd,
                    'fees_usd' => $result->feeUsd,
                    'peak_price' => $result->fillPrice,
                    'last_price' => $result->fillPrice,
                    'candidate_id' => $row->id,
                    'opened_at' => now(),
                    'meta' => $meta,
                ]);
                $fill->update(['position_id' => $position->id]);
            }

            $this->postOnlyShadows->record($fill, $position, $kind, null);

            return [$fill, $position];
        });

        if (! $result->ok()) {
            $this->reporter->warn('FILLS', "{$pid} not filled: ".($result->note ?? $result->status));

            return $fill;
        }

        $slip = $result->slippageBps($orderSide) ?? 0;
        $msg = sprintf('%s %s%s $%.2f @ %.6f (slip %.1f bps, fee %.2f%%)%s', strtoupper($kind), $short ? 'SHORT ' : '', $pid, $result->filledUsd, $result->fillPrice, $slip, $result->feePct() * 100, $result->partial ? ' PARTIAL' : '');
        if ($result->basisRecovered) {
            // slippageBps() refuses to report a number for a fabricated basis (null here, floored
            // to 0 above) — comparing that manufactured zero against the threshold would silently
            // pass every recovered fill through as if it slipped not at all (round-6 review,
            // MAJOR). Surfaced unconditionally instead of gated on a number that doesn't exist.
            $this->reporter->error('FILLS', 'FILL BASIS RECOVERED (slippage unmeasured) — '.$msg);
        } elseif ($slip > (float) $ctx->param('size.max_slippage_bps', 50)) {
            $this->reporter->error('FILLS', 'SLIPPAGE OVER MAX — '.$msg);
        } else {
            $this->reporter->trade('FILLS', $msg, ['position_id' => $position->id]);
        }

        return $fill;
    }

    /** Fence new exposure only; confirmed results must still reach the booking transaction. */
    private function assertEntryAllowed(): void
    {
        if ($this->stopRequested()) {
            throw new \RuntimeException('termination requested; entry abandoned before submission');
        }
        $this->beatCycleOwner();
    }

    /**
     * Resolves the live order intent behind a terminal result. Called inside the booking transaction, so a
     * booking that rolls back leaves the intent pending and reconcileLiveOrders books it on the next sweep.
     */
    private function resolveIntentFor(OrderResult $result): void
    {
        if ($result->intentId !== null) {
            OrderIntent::pending()->whereKey($result->intentId)->first()?->resolve($result->filledQty > 0 ? 'filled' : 'rejected');
        }
    }

    /**
     * Books a filled add onto an already-open position: quantity/cost/fees/average, meta bookkeeping.
     * Shared by an ordinary SCAN-driven add (doEnter, ADD kind) and a RISK-driven tp_reentry re-buy
     * (addFromRisk below) — both must move the average and update meta exactly the same way, since a
     * strategy's take-profit ladder rebases off whichever one just happened.
     */
    private function bookAdd(Position $existing, OrderResult $result, ?float $margin, ?int $signalTs): void
    {
        $existing->quantity += $result->filledQty;
        $existing->entry_usd += $result->filledUsd;
        $existing->fees_usd += $result->feeUsd;
        $existing->entry_price = $existing->entry_usd / max($existing->quantity, 1e-12);
        $existing->adds_count++;
        $meta = $existing->meta ?? [];
        $meta['entry_fees_usd'] = (float) ($meta['entry_fees_usd'] ?? 0) + $result->feeUsd;
        // Mirrors Backtester's own bookAdd (Backtester.php, entry_fee_excluded): whole-contract perps
        // and margin fills book filledUsd = Lot::notional/OrderResult::filledUsd, which never carried
        // the fee (booked separately, above); the plain spot/cash-notional path's filledUsd DOES carry
        // it. JsonPluginStrategy::reconcileV2Avg() needs to know which convention produced this fill to
        // recover the true per-unit price, and this is the only place that still has $result to ask.
        $feeExcluded = $result->fillPrice !== null && abs($result->filledUsd - $result->filledQty * $result->fillPrice) < max(1e-6, abs($result->filledUsd) * 1e-9)
            ? $result->feeUsd : 0.0;
        $meta['entry_fee_excluded'] = (float) ($meta['entry_fee_excluded'] ?? 0) + $feeExcluded;
        if ($margin !== null) {
            $meta['margin_usd'] = (float) ($meta['margin_usd'] ?? 0) + $margin;
        }
        $meta['last_action_ts'] = now()->getTimestamp();
        $meta['last_signal_ts'] = $signalTs ?? ($meta['last_signal_ts'] ?? null);
        $existing->meta = $meta;
        $existing->save();
    }

    /**
     * RISK asked for an add (RiskDecision::ADD — e.g. a strategy's own take-profit re-entry): books
     * it through the exact same path as an ordinary add (bookAdd above), under the same per-product
     * mutate lock doEnter uses, re-reading the position fresh in case it moved since RISK's snapshot.
     */
    private function addFromRisk(Position $p, RiskDecision $decision, DeskContext $ctx, Executor $executor): ?Fill
    {
        $pid = $p->product_id;
        $mode = $executor->mode();

        return $this->mutateLock($mode, $pid)->block(5, function () use ($p, $decision, $ctx, $executor, $pid, $mode) {
            return $this->doAddFromRisk($p, $decision, $ctx, $executor, $pid, $mode);
        });
    }

    private function doAddFromRisk(Position $p, RiskDecision $decision, DeskContext $ctx, Executor $executor, string $pid, string $mode): ?Fill
    {
        if ($this->stopRequested()) {
            return null;
        }
        $existing = Position::open()->mode($mode)->seat($this->arenaSeatId)->where('product_id', $pid)->first();
        if (! $existing) {
            return null;   // closed since RISK read it — nothing left to add to
        }
        if ($this->refusesVenue($existing, $executor, 'ADD')) {
            return null;
        }
        if ($this->entriesHaltedByStuckOrder($executor, $pid)) {
            return null;
        }
        $short = $existing->isShort();
        $orderSide = $short ? 'SELL' : 'BUY';
        $price = (float) ($existing->last_price ?? $existing->entry_price);
        $dollars = $this->cappedAddDollars($existing, (float) $decision->dollars, $ctx, $executor, $pid, (string) ($decision->ruleFired ?? 'add'));
        if ($dollars <= 0) {
            return null;
        }

        if (Perps::enabled() && PerpsCalendar::halted(now()->toDateTimeImmutable())) {
            Fill::create($this->fillAttrs(null, $existing, $pid, $orderSide, 'add', OrderResult::rejected('halt', $dollars, $price, 'CFM weekly halt'), $mode));

            return null;
        }

        $eff = Fees::effectiveRate($dollars, (float) $ctx->param('fees.taker_rate', 0.006), (float) $ctx->param('fees.floor_usd', 0));
        if ($eff > (float) $ctx->param('fees.max_effective_fee_pct', 0.02)) {
            $this->reporter->warn('FILLS', sprintf('FEE_FLOOR %s: %.2f%% effective on $%.2f (%s)', $pid, $eff * 100, $dollars, $decision->ruleFired ?? 'add'));
            Fill::create($this->fillAttrs(null, $existing, $pid, $orderSide, 'add', OrderResult::rejected('fee_floor', $dollars, $price, 'fee floor'), $mode));

            return null;
        }

        if ($executor instanceof PaperExecutor && Perps::enabled() && $this->paperMarginEnabled()) {
            $gate = PerpsGate::newRisk($this->paperSession($executor), now()->toDateTimeImmutable(), $dollars, config('desk.perps'));
            if (! $gate->allowed) {
                Fill::create($this->fillAttrs(null, $existing, $pid, $orderSide, 'add', OrderResult::rejected('rejected', $dollars, $price, (string) $gate->reason), $mode));

                return null;
            }
        }

        $entryFence = $this->assertEntryAllowed(...);
        $entryFence();
        $result = OrderBudget::withEntryFence(
            $entryFence,
            fn () => $short ? $executor->openShort($pid, $dollars, $price) : $executor->buy($pid, $dollars, $price),
        );

        [$fill, $position] = DB::transaction(function () use ($existing, $pid, $orderSide, $result, $executor) {
            $fill = Fill::create($this->fillAttrs(null, $existing, $pid, $orderSide, 'add', $result, $executor->mode()));
            $this->resolveIntentFor($result);
            if (! $result->ok()) {
                return [$fill, $existing];
            }
            $margin = isset($result->raw['margin']) ? (float) $result->raw['margin'] : null;
            $this->bookAdd($existing, $result, $margin, null);
            $fill->update(['position_id' => $existing->id]);

            return [$fill, $existing];
        });

        if (! $result->ok()) {
            $this->reporter->warn('FILLS', "{$pid} {$decision->ruleFired} not filled: ".($result->note ?? $result->status));

            return $fill;
        }

        $this->reporter->trade('FILLS', sprintf('ADD %s%s $%.2f @ %.6f (%s)', $short ? 'SHORT ' : '', $pid, $result->filledUsd, $result->fillPrice, $decision->ruleFired ?? 'add'), ['position_id' => $position->id]);

        return $fill;
    }

    /**
     * RISK-driven adds/re-entries bypass the SIZE stage, so the desk caps them here, after the
     * strategy has spoken: at most MAX_ADDS_PER_POSITION per position, each add no bigger than the
     * position's original entry, and the whole position (entry + adds) never above twice the Kelly
     * cap, nor the add above free cash or the leverage room. Returns 0.0 when nothing may be added.
     */
    private function cappedAddDollars(Position $p, float $asked, DeskContext $ctx, Executor $executor, string $pid, string $rule): float
    {
        if ((int) $p->adds_count >= self::MAX_ADDS_PER_POSITION) {
            $this->reporter->warn('FILLS', sprintf('%s %s skipped: position already has %d adds/re-entries (max %d)', $pid, $rule, $p->adds_count, self::MAX_ADDS_PER_POSITION));

            return 0.0;
        }

        $bank = $this->bank($executor);
        $equity = $bank->equity();
        $kelly = $ctx->param('size.kelly_cap_pct', 0.06);
        $kellyPct = is_numeric($kelly) && $kelly > 0 ? (float) $kelly : (float) config('desk.size.kelly_cap_pct', 0.06);
        $lev = $ctx->param('size.max_leverage', 3.0);
        $levCap = is_numeric($lev) && $lev >= 1 ? (float) $lev : (float) config('desk.size.max_leverage', 3.0);

        $limit = min(
            (float) ($p->meta['initial_cost_usd'] ?? $p->entry_usd),
            2 * $equity * $kellyPct - (float) $p->entry_usd,
            $bank->freeCash(),
            $equity * $levCap - $bank->exposure,
        );
        $dollars = floor(min($asked, max(0.0, $limit)) * 100) / 100;

        if ($dollars < (float) $ctx->param('size.min_ticket_usd', 10)) {
            $this->reporter->warn('FILLS', sprintf('%s %s skipped: desk caps leave $%.2f of the $%.2f asked', $pid, $rule, max(0.0, $limit), $asked));

            return 0.0;
        }
        if ($dollars < $asked) {
            $this->reporter->warn('FILLS', sprintf('%s %s clamped $%.2f -> $%.2f by the desk add caps', $pid, $rule, $asked, $dollars));
        }

        return $dollars;
    }

    /**
     * The strategy's own signal-bar timestamp for this candidate, when it published one under
     * extra['signal_ts'] — Desk is strategy-agnostic and has no other way to know it. A strategy
     * that never sets this leaves last_signal_ts unset, same as before this field existed, rather
     * than being guessed at.
     */
    private function candidateSignalTs(CandidateRow $c): ?int
    {
        $ts = $c->stats->extra['signal_ts'] ?? null;

        return is_numeric($ts) ? (int) $ts : null;
    }

    private function fillAttrs(?DeskRun $run, ?Position $position, string $pid, string $side, string $kind, OrderResult $r, string $mode): array
    {
        return [
            'arena_seat_id' => $this->arenaSeatId,
            'position_id' => $position?->id,
            'desk_run_id' => $run?->id,
            'mode' => $mode,
            'product_id' => $pid,
            'side' => $side,
            'kind' => $kind,
            'requested_usd' => $r->requestedUsd,
            'filled_usd' => $r->filledUsd,
            'filled_qty' => $r->filledQty,
            'decision_price' => $r->decisionPrice,
            'fill_price' => $r->fillPrice,
            'slippage_bps' => $r->slippageBps($side),
            'fee_usd' => $r->feeUsd,
            'fee_pct' => $r->feePct(),
            'partial' => $r->partial,
            'status' => $r->status,
            'venue_order_id' => $r->venueOrderId,
            'raw' => $r->raw ?: null,
            'note' => $r->note,
        ];
    }

    // ------------------------------------------------------------------
    // Live order reconciliation
    // ------------------------------------------------------------------

    /**
     * A live order whose outcome was ambiguous (timeout on create, readback that never answered) stays a
     * pending OrderIntent and blocks new orders on its product. Here the desk asks the venue what became
     * of each one and books the answer exactly once, so a live fill nobody saw is never left unrecorded
     * and an order that never landed frees the product again. This pass NEVER sends an order: it only
     * reads the venue, then books or resolves under the product's mutate lock after re-checking the
     * intent is still pending, so concurrent cycle/risk processes cannot double-book or re-sell.
     */
    public function reconcileLiveOrders(Strategy $strategy, Executor $executor): void
    {
        if (! $executor instanceof ReconcilesOrders) {
            return;
        }

        $stuckAfter = (int) config('desk.live_orders.stuck_minutes', 30) * 60;
        foreach (OrderIntent::pending()->mode($executor->mode())->venue($executor->orderVenue())->orderBy('id')->get() as $intent) {
            $this->beatCycleOwner();
            try {
                $result = $executor->reconcile($intent);
                if ($result !== null) {
                    $this->bookReconciled($strategy, $executor, $intent, $result);

                    continue;
                }
                $this->releaseIfAbsent($executor, $intent);
                if ($intent->refresh()->status === 'pending') {
                    $stuck = $intent->secondsSinceSent() >= $stuckAfter;
                    // A stuck order holds exits until an operator acts, so it pages (Reporter::error goes to
                    // Telegram) — but once per 15 minutes per intent, not every sweep.
                    $report = ! $stuck || Cache::add("desk:stuck-report:{$intent->id}", 1, 900);
                    if ($report) {
                        $this->reporter->error('FILLS', ($stuck ? 'LIVE ORDER STUCK, new entries halted on this product until resolved (php artisan desk:intents:resolve) — ' : 'LIVE ORDER UNRESOLVED — ')
                            ."{$intent->method} {$intent->desk_product_id} ({$intent->client_order_id}): the venue has not confirmed it; new orders on this product are held");
                    }
                }
            } catch (LockTimeoutException) {
                $this->reporter->warn('FILLS', "{$intent->desk_product_id}: mutate lock timed out reconciling {$intent->client_order_id}, will retry next sweep");
            } catch (\Throwable $e) {
                $this->reporter->warn('FILLS', "{$intent->desk_product_id}: reconcile of {$intent->client_order_id} failed: ".$e->getMessage());
            } finally {
                $this->beatCycleOwner();
            }
        }
    }

    /**
     * Operator resolution of a pending live order (desk:intents:resolve). Never takes the operator's word
     * over the venue: 'filled' is booked from the venue's own record through the reconcile path, and
     * 'rejected'/'abandoned' are refused unless the venue confirms the order is absent (or is terminal and
     * unfilled) — $force overrides that and says so loudly.
     *
     * @return array{0: bool, 1: string} [done, message]
     */
    public function resolveIntent(Executor $executor, OrderIntent $intent, string $outcome, bool $force = false): array
    {
        if (! $executor instanceof ReconcilesOrders || $executor->orderVenue() !== $intent->venue) {
            return [false, "intent {$intent->client_order_id} belongs to {$intent->venue}; the configured executor cannot reach that venue"];
        }

        if ($outcome === 'filled') {
            $result = $executor->reconcile($intent);
            if ($result === null || ! $result->ok()) {
                return [false, 'the venue does not show this order as filled; refusing to book a fill it cannot confirm'];
            }
            $this->bookReconciled($this->strategy(), $executor, $intent, $result);

            $intent->refresh();

            return [true, str_starts_with((string) $intent->note, 'ORPHAN LIVE FILL')
                ? 'venue fill recorded without a matching position; manual recovery required'
                : ($intent->status === 'resolved' ? 'booked from the venue record' : 'not booked')];
        }

        return $this->mutateLock($executor->mode(), $intent->desk_product_id)->block(5, function () use ($executor, $intent, $outcome, $force) {
            if ($intent->refresh()->status !== 'pending') {
                return [false, 'already resolved'];
            }
            $result = $executor->reconcile($intent);
            $safe = $executor->confirmedAbsent($intent) || ($result !== null && ! $result->ok());
            if (! $safe && ! $force) {
                return [false, 'the venue has not confirmed this order is absent or unfilled; it may still be live. Re-run with --force only if you have checked the venue yourself'];
            }
            if ($result !== null && $result->ok()) {
                $this->reporter->error('FILLS', "FORCED {$outcome} on {$intent->client_order_id} although the venue shows it FILLED: book the position by hand");
            } elseif (! $safe) {
                $this->reporter->error('FILLS', "FORCED {$outcome} on {$intent->client_order_id} without venue confirmation");
            }
            $intent->resolve($outcome, 'resolved manually by an operator'.($safe ? '' : ' (forced)'));

            return [true, 'resolved'.($safe ? '' : ' (FORCED, unverified)')];
        });
    }

    /** An order the venue provably never received frees its product; decided only while holding the product lock. */
    private function releaseIfAbsent(ReconcilesOrders&Executor $executor, OrderIntent $intent): void
    {
        $this->mutateLock($executor->mode(), $intent->desk_product_id)->block(5, function () use ($executor, $intent) {
            if ($intent->refresh()->status === 'pending' && $executor->confirmedAbsent($intent)) {
                $intent->resolve('abandoned', 'order never reached the venue');
                $this->reporter->warn('FILLS', "{$intent->desk_product_id}: {$intent->method} {$intent->client_order_id} never reached the venue, product released");
            }
        });
    }

    /** True when a pending live order on this product has been unresolved for so long that new entries stop. */
    private function entriesHaltedByStuckOrder(Executor $executor, string $pid): bool
    {
        if (! $executor instanceof ReconcilesOrders) {
            return false;
        }
        $cutoff = now()->subMinutes((int) config('desk.live_orders.stuck_minutes', 30));

        return OrderIntent::pending()->mode($executor->mode())->venue($executor->orderVenue())
            ->where('desk_product_id', $pid)
            ->whereRaw('COALESCE(sent_at, created_at) <= ?', [$cutoff])->exists();
    }

    private function bookReconciled(Strategy $strategy, Executor $executor, OrderIntent $intent, OrderResult $result): void
    {
        $mode = $executor->mode();
        $pid = $intent->desk_product_id;
        $exit = in_array($intent->method, ['sell', 'cover_short'], true);

        $this->mutateLock($mode, $pid)->block(5, function () use ($strategy, $executor, $intent, $result, $mode, $pid, $exit) {
            if ($intent->refresh()->status !== 'pending') {
                return;   // another process booked it while we waited for the lock
            }
            $positions = Position::open()->mode($mode)->seat($this->arenaSeatId)->where('product_id', $pid);
            // A pending order keeps its original venue even after the desk setting changes.
            // Prefer that pinned position over any same-product holding on another venue;
            // legacy unpinned rows retain the same fallback as ordinary close/add paths.
            $existing = (clone $positions)->where('venue', $intent->venue)->first()
                ?? (clone $positions)->whereNull('venue')->first();
            $venueConflict = $existing === null && (clone $positions)->whereNotNull('venue')->exists();
            $short = $intent->method === 'open_short';
            $kind = $exit ? 'exit' : ($existing ? 'add' : 'entry');
            $orphan = $result->ok() && ($venueConflict || ($exit && $existing === null)
                || ($existing !== null && $existing->isShort() !== ($exit ? $intent->method === 'cover_short' : $short)));
            $orphanNote = "ORPHAN LIVE FILL {$intent->method} {$pid} on {$intent->venue} ({$intent->client_order_id}): filled at the venue but there is no matching open position to book it against; manual recovery required";

            DB::transaction(function () use ($strategy, $executor, $intent, $result, $mode, $pid, $exit, $existing, $short, $kind, $orphan, $orphanNote) {
                $attrs = $this->fillAttrs(null, $orphan ? null : $existing, $pid, $intent->side, $kind, $result, $mode);
                if ($orphan) {
                    $attrs['note'] = $orphanNote;
                }
                $fill = Fill::create($attrs);

                if ($result->ok() && ! $orphan && $exit) {
                    if ($existing !== null && $existing->isShort() === ($intent->method === 'cover_short')) {
                        $this->bookExit($existing, $result);
                        if ($existing->quantity > self::QTY_EPSILON) {
                            $existing->save();
                        } else {
                            $this->markFullyClosed($existing, $result, 'reconcile');
                            $existing->save();
                        }
                    }
                } elseif ($result->ok() && ! $orphan) {
                    if ($existing === null) {
                        $position = Position::create([
                            'arena_seat_id' => $this->arenaSeatId, 'mode' => $mode, 'venue' => $this->venueOf($executor), 'strategy' => $strategy->key(),
                            'product_id' => $pid, 'side' => $short ? 'short' : 'long',
                            'quantity' => $result->filledQty, 'entry_price' => $result->fillPrice, 'entry_usd' => $result->filledUsd,
                            'fees_usd' => $result->feeUsd, 'peak_price' => $result->fillPrice, 'last_price' => $result->fillPrice,
                            'opened_at' => now(),
                            'meta' => [
                                'decision_price' => $result->decisionPrice, 'entry_fees_usd' => $result->feeUsd,
                                'initial_cost_usd' => $result->filledUsd, 'last_action_ts' => now()->getTimestamp(),
                                'reconciled_from_intent' => $intent->client_order_id,
                            ],
                        ]);
                        $fill->update(['position_id' => $position->id]);
                    } elseif ($existing->isShort() === $short) {
                        $this->bookAdd($existing, $result, null, null);
                        $fill->update(['position_id' => $existing->id]);
                    }
                }

                $intent->resolve($result->ok() ? 'filled' : 'rejected', $orphan ? $orphanNote : 'booked by reconcile');
            });

            if ($orphan) {
                $this->reporter->error('FILLS', $orphanNote);
            } elseif ($result->ok()) {
                $this->reporter->trade('FILLS', sprintf('RECONCILED %s %s $%.2f @ %.6f (%s)', strtoupper($intent->method), $pid, $result->filledUsd, $result->fillPrice, $intent->client_order_id));
            } else {
                $this->reporter->warn('FILLS', "{$pid} reconciled {$intent->method} did not fill: ".($result->note ?? $result->status));
            }
        });
    }

    // ------------------------------------------------------------------
    // RISK — own timer, final authority
    // ------------------------------------------------------------------

    /** @return array<int, array{position:string, action:string, rule:?string}> */
    public function riskSweep(): array
    {
        return $this->runRiskSweep($this->strategy(), $this->executor());
    }

    /** RISK for one strategy/executor pair — every open position under that pair's own account. Shared by riskSweep() (the main desk) and ArenaRunner (one call per active seat). */
    public function runRiskSweep(Strategy $strategy, Executor $executor): array
    {
        $this->chief->heartbeat('RISK');
        $out = [];

        $this->reconcileLiveOrders($strategy, $executor);

        if ($executor instanceof CoinbasePerpsExecutor) {
            try {
                $session = $executor->session();
            } catch (\Throwable $e) {
                $this->reporter->warn('RISK', 'perps session check failed: '.$e->getMessage());
                $session = null;
            }
            if ($session !== null) {
                $this->deleverageIfBufferLow($session, $executor);
            }
        } elseif ($executor instanceof PaperExecutor && Perps::enabled() && $this->paperMarginEnabled()) {
            $session = $this->paperSession($executor);
            if (MarginBook::liquidated($session)) {
                foreach ($this->openPositions($executor->mode()) as $p) {
                    $price = (float) ($p->last_price ?? $p->entry_price);
                    $this->reporter->error('RISK', sprintf('LIQUIDATED %s: buffer %.1f%% <= 0', $p->product_id, $session->liquidationBufferPct));
                    try {
                        $this->close($p, 'liquidation', $price, $executor);
                    } catch (LockTimeoutException $e) {
                        // Same reasoning as the main loop below: one stuck lock must not stop the rest of
                        // a liquidation sweep from closing out every other position.
                        $this->reporter->warn('RISK', "{$p->product_id}: mutate lock timed out on liquidation close, will retry next sweep");
                    }
                }
            } else {
                $this->deleverageIfBufferLow($session, $executor);
            }
        }

        foreach ($this->openPositions($executor->mode()) as $p) {
            // A position on another venue is not this executor's to manage. Skipped before any stats or
            // force-close bookkeeping, so a refusal never counts as a failed close attempt (which would
            // walk it into force_close_terminal) and clears the moment the right venue is configured.
            if ($this->onOtherVenue($p, $executor)) {
                $this->reporter->error('RISK', "{$p->product_id}: lives on {$p->venue} but the configured executor is {$this->venueOf($executor)}; not managed this sweep, no order sent");
                $out[] = ['position' => $p->product_id, 'action' => 'venue_refused', 'rule' => null];

                continue;
            }

            try {
                [$posStrategy, $ctx] = $this->riskStrategyFor($p, $strategy, $executor->mode());
                $stats = $this->statsWithRetries($p->product_id, (int) $ctx->param('risk.stale_data_retries', 2));
                // Set whenever $decision comes out of forceCloseDecision() below, so the
                // close-attempt block and the final $out[] entry below can tell a real attempt
                // apart from an inert terminal placeholder (round-9 review — see
                // forceCloseDecision()'s docblock).
                $isForceCloseAttempt = false;
                $forceCloseTerminal = false;

                if ($stats === null) {
                    // Round-9 review, MAJOR: this used to issue close('unmeasurable', ...)
                    // directly here, with no backoff and no terminal check — unlike the
                    // stats->price<=0 route below, which only got the ladder in round 8. Routed
                    // through the same shared ledger now: forceCloseDecision() returns null and
                    // has already recorded 'stale' (backoff) in $out (the caller `continue`s
                    // without a RiskCheck row); terminal falls through instead, see below.
                    $forceClose = $this->forceCloseDecision($p, $ctx, 'no answer after retries — a position you cannot measure is a position you do not hold', $out);
                    if ($forceClose === null) {
                        continue;
                    }
                    $decision = $forceClose['decision'];
                    $forceCloseTerminal = $forceClose['terminal'];
                    $isForceCloseAttempt = ! $forceCloseTerminal;
                } elseif ($stats->price <= 0) {
                    // ProductStatsBuilder::fromBars() returns price 0.0 for a product with no
                    // closed 1H bars yet, and statsWithRetries() passes it straight through — a
                    // real (non-null) stats row that is still useless. Skipped before
                    // markPrice(0.0) can stamp a zero last_price onto the position (round-6 review).
                    // Round-7 review, MAJOR: that skip was unbounded — a position whose stats price
                    // never recovers (a halted/delisted product, or a stalled candle feeder) was
                    // never risk-evaluated again and desk:risk kept reporting "no open positions"
                    // while it sat unmanaged. Bounded here the same way a null stats row already
                    // is: after risk.max_zero_price_sweeps consecutive price-0 sweeps, force-close
                    // it instead of skipping an (N+1)th time.
                    $zeroSweeps = ((int) ($p->meta['zero_price_sweeps'] ?? 0)) + 1;
                    $maxZeroSweeps = (int) $ctx->param('risk.max_zero_price_sweeps', 5);

                    if ($zeroSweeps < $maxZeroSweeps) {
                        // error(), not warn() — warn() never reaches Telegram (Reporter.php), and this
                        // runs on desk:risk's everyMinute schedule, so a warn() here is functionally silent.
                        $this->reporter->error('RISK', "{$p->product_id}: stats price is {$stats->price}, skipping this position this sweep ({$zeroSweeps}/{$maxZeroSweeps} before force-close)");
                        $this->saveSweepMeta($p, ['zero_price_sweeps' => $zeroSweeps]);
                        // So desk:risk stops printing "no open positions" (DeskRisk.php) and the sweep
                        // output stops implying this position doesn't exist while it sits unmanaged.
                        $out[] = ['position' => $p->product_id, 'action' => 'stale', 'rule' => null];

                        continue;
                    }

                    // Round-8 review, MAJOR: escalating past max_zero_price_sweeps into a force-close
                    // had no backoff and no terminal state. Round 9: this ladder is now shared with
                    // the stats===null route above via forceCloseDecision() (same reasoning — a
                    // close that can't fill must not retry every single sweep forever, and a
                    // position already terminal on one route must not hammer again just because it
                    // arrived here via the other).
                    //
                    // Round-9 review, MINOR: zero_price_sweeps used to only ever get saved by the
                    // "< threshold" skip branch above — past the threshold it was read as
                    // stored+1 but never written back, so the reason string below froze at
                    // max_zero_price_sweeps forever no matter how many more sweeps actually
                    // passed. Kept incrementing and persisted here too, merged into the same
                    // save forceCloseDecision() already does, so the reason string always
                    // reports the TRUE stall length.
                    $forceClose = $this->forceCloseDecision($p, $ctx, "price stuck at {$stats->price} for {$zeroSweeps} consecutive sweeps — a position you cannot measure is a position you do not hold", $out, ['zero_price_sweeps' => $zeroSweeps]);
                    if ($forceClose === null) {
                        continue;
                    }
                    $decision = $forceClose['decision'];
                    $forceCloseTerminal = $forceClose['terminal'];
                    $isForceCloseAttempt = ! $forceCloseTerminal;
                } else {
                    $p->markPrice($stats->price);
                    $baseMeta = $p->meta ?? [];
                    $decision = $posStrategy->risk($p, $stats, $ctx);
                    $reset = [];
                    if (($p->meta['zero_price_sweeps'] ?? 0) !== 0 || ($p->meta['force_close_sweeps'] ?? 0) !== 0 || ($p->meta['force_close_attempts'] ?? 0) !== 0 || ($p->meta['force_close_terminal_reported'] ?? false) !== false || ($p->meta['force_close_terminal_sweeps'] ?? 0) !== 0) {
                        // Price recovered — the whole force-close escalation state is stale, clear it
                        // so a FUTURE stall starts its own backoff/terminal count from zero.
                        // Round-10 review, MINOR: force_close_terminal_sweeps (the re-report
                        // cadence counter) was left out of both this condition and the reset
                        // below — docs/RISK.md's manual-recovery snippet clears it, so a later
                        // terminal episode that started at a stale nonzero count re-reported on
                        // a phase-shifted cadence instead of matching the documented behaviour.
                        $reset = [
                            'zero_price_sweeps' => 0,
                            'force_close_sweeps' => 0,
                            'force_close_attempts' => 0,
                            'force_close_terminal_reported' => false,
                            'force_close_terminal_sweeps' => 0,
                        ];
                    }
                    $this->saveSweepMeta($p, $reset, $baseMeta);
                }

                // A price-0 stats row (handled above) must not stamp $price to 0 for the escalated
                // close below — fall back to the last known price, then entry price, exactly like the
                // null-stats case. Round-8 review, MAJOR: the old `??` fallback only catches NULL, so a
                // position whose last_price was ALREADY 0 (e.g. stamped before this class's own
                // zero-price guards existed) closed at decision price 0 instead of falling through to
                // entry_price. A position with no usable price anywhere is skipped, not closed at 0.
                if ($stats !== null && $stats->price > 0) {
                    $price = $stats->price;
                } elseif ((float) ($p->last_price ?? 0) > 0) {
                    $price = (float) $p->last_price;
                } elseif ((float) ($p->entry_price ?? 0) > 0) {
                    $price = (float) $p->entry_price;
                } else {
                    // Round-9 review, MINOR: this used to report unconditionally every time it was
                    // reached — including on a TERMINAL sweep once those started falling through
                    // to this same price resolution (see forceCloseDecision()'s terminal branch),
                    // which reintroduced per-sweep spam for exactly this ladder's whole reason to
                    // exist. Only a genuine, cadence-gated attempt sweep reports it now; a terminal
                    // sweep relies on the ledger's own (slow-cadence) "giving up" message instead.
                    if ($isForceCloseAttempt) {
                        $this->reporter->error('RISK', "{$p->product_id}: no usable price to act on (stats, last_price and entry_price are all <= 0), skipping this position this sweep");
                        // Round-10 review, MAJOR: recordForceCloseAttemptOutcome() only ever ran
                        // after close() — this branch `continue`s before close() is ever reached,
                        // so a position with no usable price anywhere never burned an attempt and
                        // the ladder could never reach terminal, reporting on the backoff cadence
                        // forever instead of going quiet after max_attempts. An unpriceable close
                        // is a failed attempt (null fill fails recordForceCloseAttemptOutcome()'s
                        // "ok" check the same way a rejected fill does), so it counts the same way.
                        $this->recordForceCloseAttemptOutcome($p, null);
                    }
                    $out[] = ['position' => $p->product_id, 'action' => 'stale', 'rule' => null];

                    continue;
                }
                if ($executor instanceof PaperExecutor && Perps::enabled()) {
                    $this->accrueFunding($p, $price);
                }
                RiskCheck::create([
                    'position_id' => $p->id,
                    // Round-9 review, MINOR: a terminal force-close position used to skip this
                    // entirely (early `continue` in the old code) — the dashboard had no row at
                    // all for a position that was, correctly, parked. 'force_close_terminal' here
                    // instead of the inert HOLD placeholder's own action.
                    'action' => $forceCloseTerminal ? 'force_close_terminal' : $decision->action,
                    'rule_fired' => $decision->ruleFired,
                    'volume_6h' => $decision->volume6h,
                    'avg_6h' => $decision->avg6h,
                    'ratio' => $decision->ratio,
                    'price' => $price,
                    'pnl_usd' => $p->unrealisedPnl($price),
                    'pnl_pct' => $p->unrealisedPnlPct($price),
                    'held_minutes' => $p->heldMinutes(),
                    'meta' => ['why' => $decision->why] + $decision->meta,
                ]);

                try {
                    if ($decision->shouldClose()) {
                        $fill = $this->close($p, $decision->ruleFired ?? 'risk', $price, $executor);
                        if ($isForceCloseAttempt) {
                            $this->recordForceCloseAttemptOutcome($p, $fill);
                        }
                    } elseif ($decision->shouldTrim()) {
                        $this->trim($p, $decision->fraction, $decision->ruleFired ?? 'trim', $price, $executor, $decision->limitPrice);
                    } elseif ($decision->shouldAdd()) {
                        $this->addFromRisk($p, $decision, $ctx, $executor);
                    }
                } catch (LockTimeoutException $e) {
                    // A close/trim's mutate lock is held by someone else (a concurrent API close/trim, or a
                    // slow exchange call) — this position gets another chance next sweep; the ones after it
                    // in this loop must not go unmanaged because of it.
                    $this->reporter->warn('RISK', "{$p->product_id}: mutate lock timed out on {$decision->action}, will retry next sweep");
                }

                $out[] = ['position' => $p->product_id, 'action' => $forceCloseTerminal ? 'force_close_terminal' : $decision->action, 'rule' => $decision->ruleFired];
            } catch (LockTimeoutException $e) {
                // The metadata save (or funding accrual) could not get this product's mutate lock. Nothing was
                // committed and no action was taken: the whole evaluation, including any close/trim/add it would
                // have chosen, is retried next sweep. Acting now would let close()/trim() refresh the row under
                // their own lock and discard the unsaved ladder state.
                $this->reporter->warn('RISK', "{$p->product_id}: mutate lock timed out saving risk state, will retry next sweep");
                $out[] = ['position' => $p->product_id, 'action' => 'stale', 'rule' => null];

                continue;
            } catch (\Throwable $e) {
                // Any unhandled failure evaluating ONE position (a strategy bug, a malformed stored
                // definition reaching an unguarded runtime path, a division by zero) must not starve
                // every position after it in the sweep — round-6 review, blockers 2 and 3 both proved
                // a live way to trigger this from stored data the schema validator waved through.
                $this->reporter->error('RISK', "{$p->product_id}: risk evaluation failed, skipping this position: ".$e->getMessage());
                report($e);
                $out[] = ['position' => $p->product_id, 'action' => 'error', 'rule' => null];

                continue;
            }
        }

        $this->notifyOnBigMove($executor);

        return $out;
    }

    /**
     * Which strategy (and plugin version) judges an open position in the sweep: the one it was opened
     * with, not whatever the desk runs now — a strategy switch must not strip an open position of its
     * stops. Positions that predate the strategy/version columns, or whose strategy is no longer
     * registered, fall back to the sweep's own strategy.
     *
     * @return array{0: Strategy, 1: DeskContext}
     */
    private function riskStrategyFor(Position $p, Strategy $active, string $mode): array
    {
        $strategy = $active;
        if ($p->strategy !== null && $p->strategy !== '' && $p->strategy !== $active->key()) {
            try {
                $strategy = $this->strategies->make($p->strategy);
            } catch (\InvalidArgumentException) {
                $this->reporter->warn('RISK', "{$p->product_id}: opened under unknown strategy '{$p->strategy}', judging it by '{$active->key()}'");
            }
        }

        $ctx = $this->context($strategy, false, $mode);
        if ($p->plugin_version_id !== null) {
            $ctx = $ctx->withParams(['json' => ['plugin_version_id' => (int) $p->plugin_version_id]]);
        }

        return [$strategy, $ctx];
    }

    private function pinnedPluginVersionId(string $strategyKey, DeskContext $ctx): ?int
    {
        $strategy = $this->strategies->make($strategyKey);

        return $strategy instanceof JsonPluginStrategy ? $strategy->pinnedVersionId($ctx) : null;
    }

    /**
     * Persists the sweep's in-memory view of a position without clobbering what a concurrent
     * mutation wrote meanwhile: under the product's mutate lock, re-read the row and write back ONLY
     * the meta keys this sweep changed (those differing from $baseMeta, plus $set), and re-apply the
     * mark price. $p is then synced to the saved row so later close/trim/add work off fresh data.
     *
     * @param  array<string, mixed>  $set  keys to force
     * @param  array<string, mixed>|null  $baseMeta  meta as it was before the strategy ran; null means "$p->meta as it is now"
     */
    private function saveSweepMeta(Position $p, array $set = [], ?array $baseMeta = null): void
    {
        $mine = $p->meta ?? [];
        $base = $baseMeta ?? $mine;

        $changed = [];
        foreach ($mine as $key => $value) {
            if (! array_key_exists($key, $base) || $base[$key] !== $value) {
                $changed[$key] = $value;
            }
        }
        $removed = array_keys(array_diff_key($base, $mine));
        $changed = array_merge($changed, $set);

        if ($changed === [] && $removed === []) {
            $p->save();   // price columns only (markPrice) — no meta to race a concurrent writer for

            return;
        }

        $this->mutateLock($p->mode, $p->product_id)->block(5, function () use ($p, $changed, $removed) {
            $fresh = Position::find($p->id);
            if ($fresh === null || $fresh->status !== 'open') {
                return;   // settled meanwhile — nothing left to annotate
            }

            $meta = $fresh->meta ?? [];
            foreach ($removed as $key) {
                unset($meta[$key]);
            }
            $fresh->meta = array_merge($meta, $changed);
            if ($p->last_price !== null) {
                $fresh->markPrice((float) $p->last_price);
            }
            $fresh->save();
            $p->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    /** Close the worst open position when the liquidation buffer drops below the deleverage floor. One position per sweep. Live and paper share this off a PerpsSession snapshot. */
    private function deleverageIfBufferLow(PerpsSession $session, Executor $executor): void
    {
        $floor = (float) config('desk.perps.deleverage_buffer_pct');
        if ($session->liquidationBufferPct === null || $session->liquidationBufferPct >= $floor) {
            return;
        }

        $worst = null;
        $worstPrice = 0.0;
        $worstPct = null;
        foreach ($this->openPositions($executor->mode()) as $p) {
            if ($this->onOtherVenue($p, $executor)) {
                continue;   // close() would refuse it and the same position would be picked every sweep
            }
            $price = (float) ($p->last_price ?? $p->entry_price);
            $pct = $p->unrealisedPnlPct($price);
            if ($worstPct === null || $pct < $worstPct) {
                $worst = $p;
                $worstPrice = $price;
                $worstPct = $pct;
            }
        }
        if ($worst === null) {
            return;
        }

        $this->reporter->warn('RISK', sprintf('liquidation buffer %.1f%% < %.0f%% floor — closing worst position %s (%.2f%%)', $session->liquidationBufferPct, $floor, $worst->product_id, $worstPct));
        try {
            $this->close($worst, 'liq_buffer', $worstPrice, $executor);
        } catch (LockTimeoutException $e) {
            // A stuck lock here must not propagate out of riskSweep() and abort the per-position loop
            // that runs right after this — the deleverage retries next sweep either way.
            $this->reporter->warn('RISK', "{$worst->product_id}: mutate lock timed out on deleverage close, will retry next sweep");
        }
    }

    /**
     * Shared force-close ledger for both "unmeasurable" routes in runRiskSweep() — stats===null
     * (retries exhausted) and stats->price stuck at <= 0 past risk.max_zero_price_sweeps. Round-9
     * review, MAJOR: previously only the price-0 route escalated through backoff/terminal; the
     * null-stats route issued close('unmeasurable') directly on every single sweep forever, no
     * backoff and no terminal check, and a position already terminal via one route would start
     * hammering again the moment it arrived here via the other (e.g. a stalled feed recovering
     * just enough to report price 0 instead of throwing, or vice versa). Both routes now read and
     * write the same force_close_* meta keys and both check the terminal flag before ever
     * attempting another close.
     *
     * @param  array<int, array{position:string, action:string, rule:?string}>  $out
     * @return array{decision:RiskDecision, terminal:bool}|null
     *                                                          null when $out already got its 'stale' backoff entry for this sweep and the caller
     *                                                          must `continue` without resolving a price or writing a RiskCheck row.
     *                                                          Non-null otherwise: terminal=false means actually attempt the CLOSE decision;
     *                                                          terminal=true means the position has given up for good (decision is an inert HOLD)
     *                                                          — the caller must still resolve a price and write a RiskCheck row (round-9 review,
     *                                                          MINOR: a terminal position used to vanish from the dashboard entirely), but must
     *                                                          never attempt close/trim/add and must report the 'force_close_terminal' action, not
     *                                                          the HOLD placeholder's own action.
     */
    private function forceCloseDecision(Position $p, DeskContext $ctx, string $reason, array &$out, array $pending = []): ?array
    {
        $forceCloseSweeps = ((int) ($p->meta['force_close_sweeps'] ?? 0)) + 1;
        $forceCloseAttempts = (int) ($p->meta['force_close_attempts'] ?? 0);
        $backoffSweeps = max(1, (int) $ctx->param('risk.force_close_backoff_sweeps', 5));
        $maxForceCloseAttempts = (int) $ctx->param('risk.force_close_max_attempts', 10);

        if ($forceCloseAttempts >= $maxForceCloseAttempts) {
            // Round-9 review, MINOR: this used to report once ever and never again — useful the
            // first time, silent and forgettable a week later. Re-reports every
            // risk.force_close_rereport_sweeps'th terminal sweep instead of never again.
            $rereportSweeps = max(1, (int) $ctx->param('risk.force_close_rereport_sweeps', 60));
            $terminalSweeps = ((int) ($p->meta['force_close_terminal_sweeps'] ?? 0)) + 1;
            $alreadyReported = ($p->meta['force_close_terminal_reported'] ?? false) === true;

            if (! $alreadyReported || (($terminalSweeps - 1) % $rereportSweeps) === 0) {
                $this->reporter->error('RISK', "{$p->product_id}: force-close failed {$forceCloseAttempts} attempts, giving up — needs manual intervention");
            }
            $this->saveSweepMeta($p, $pending + [
                'force_close_terminal_reported' => true,
                'force_close_terminal_sweeps' => $terminalSweeps,
            ]);

            // HOLD is inert — shouldClose()/shouldTrim()/shouldAdd() are all false for it, so the
            // caller's close/trim/add block is a guaranteed no-op without any special-casing there.
            return ['decision' => new RiskDecision(RiskDecision::HOLD, null, null, null, null, 'force_close_terminal'), 'terminal' => true];
        }

        // 0-based cadence (round-9 review, BLOCKER): fires on sweep 1, then every Nth sweep after
        // it, for every N >= 1 including backoffSweeps=1 (no pause at all).
        if ((($forceCloseSweeps - 1) % $backoffSweeps) !== 0) {
            $this->saveSweepMeta($p, $pending + ['force_close_sweeps' => $forceCloseSweeps]);
            $out[] = ['position' => $p->product_id, 'action' => 'stale', 'rule' => null];

            return null;
        }

        // Round-9 review, MINOR: force_close_attempts is deliberately NOT bumped here anymore —
        // only force_close_sweeps (the backoff cadence). The caller (runRiskSweep()) records the
        // real outcome via recordForceCloseAttemptOutcome() once close() has actually run, so a
        // LockTimeoutException between here and there (contract everywhere else: "another chance
        // next sweep") never burns one of the position's limited attempts.
        $this->saveSweepMeta($p, $pending + ['force_close_sweeps' => $forceCloseSweeps]);

        return ['decision' => RiskDecision::close('unmeasurable', null, null, null, $reason), 'terminal' => false];
    }

    /**
     * Counts a force-close attempt against the position ONLY when close() actually ran and did
     * NOT succeed — a LockTimeoutException never reaches this method at all (the caller's catch
     * block skips straight past it), and a genuinely successful close needs no counting: the
     * position is no longer open, so no future sweep will ever consult this ledger again.
     */
    private function recordForceCloseAttemptOutcome(Position $p, ?Fill $fill): void
    {
        if ($fill?->status === 'unknown') {
            return;   // an unconfirmed order is held for reconcile, not a failed attempt to count toward giving up
        }

        $ok = $fill !== null && $fill->status === 'filled' && $fill->filled_qty > 0 && $fill->fill_price !== null && $fill->fill_price > 0;
        if ($ok) {
            return;
        }

        $this->saveSweepMeta($p, ['force_close_attempts' => ((int) ($p->meta['force_close_attempts'] ?? 0)) + 1]);
    }

    private function statsWithRetries(string $pid, int $retries): ?ProductStats
    {
        $product = Product::where('product_id', $pid)->first();
        for ($i = 0; $i <= $retries; $i++) {
            try {
                Cache::forget("cb:ticker:{$pid}:100");

                return $product ? $this->stats->live($product) : null;
            } catch (\Throwable $e) {
                $this->reporter->warn('RISK', "stats retry {$i} for {$pid}: ".$e->getMessage());
                usleep(500_000);
            }
        }

        return null;
    }

    /** A position's stored mode never changes; this is the floor under which "sold everything" rounds to closed. */
    private const QTY_EPSILON = 1e-8;

    /**
     * A close/trim fill's net PnL: gross minus this event's share of entry fees, this fill's own exit
     * fee, and this event's share of accumulated funding — the costs the cash ledger actually paid.
     * Mutates $p in place (quantity, entry_usd, fees_usd, realised_usd, meta) but does not save it;
     * callers persist inside their own transaction once they know whether the position is now closed.
     */
    private function bookExit(Position $p, OrderResult $result): float
    {
        $totalQty = (float) $p->quantity;
        $soldQty = min($result->filledQty, $totalQty);
        $soldFraction = $totalQty > 0 ? $soldQty / $totalQty : 1.0;

        $costSold = (float) $p->entry_usd * $soldFraction;
        $meta = $p->meta ?? [];
        $entryFeesRemaining = (float) ($meta['entry_fees_usd'] ?? $p->fees_usd);
        $entryFeeSold = $entryFeesRemaining * $soldFraction;
        $fundingRemaining = (float) ($meta['funding_usd'] ?? 0);
        $fundingSold = $fundingRemaining * $soldFraction;

        $grossPnl = $p->dir() * ($result->filledUsd - $costSold);
        $netPnl = $grossPnl - $entryFeeSold - $result->feeUsd - $fundingSold;

        $meta['entry_fees_usd'] = $entryFeesRemaining - $entryFeeSold;
        $meta['funding_usd'] = $fundingRemaining - $fundingSold;
        if (isset($meta['margin_usd'])) {
            $meta['margin_usd'] = (float) $meta['margin_usd'] * (1 - $soldFraction);
        }
        $meta['cost_trimmed'] = (float) ($meta['cost_trimmed'] ?? 0) + $costSold;

        $p->quantity = $totalQty - $soldQty;
        $p->entry_usd = (float) $p->entry_usd - $costSold;
        $p->fees_usd = (float) $p->fees_usd + $result->feeUsd;
        $p->realised_usd = (float) ($p->realised_usd ?? 0) + $netPnl;
        $p->last_price = $result->fillPrice;
        $p->meta = $meta;

        return $netPnl;
    }

    private function writeOffDust(Position $p): void
    {
        $cost = (float) $p->entry_usd;
        $meta = $p->meta ?? [];
        $meta['cost_trimmed'] = (float) ($meta['cost_trimmed'] ?? 0) + $cost;
        $meta['dust_written_off_qty'] = (float) $p->quantity;
        $p->realised_usd = (float) ($p->realised_usd ?? 0) - $cost;
        $p->entry_usd = 0;
        $p->quantity = 0;
        $p->meta = $meta;
    }

    private function markFullyClosed(Position $p, OrderResult $result, string $rule): void
    {
        $p->status = 'closed';
        $p->exit_price = $result->fillPrice ?? $result->decisionPrice;
        $p->exit_usd = $result->filledUsd;
        $p->pnl_usd = (float) $p->realised_usd;
        $totalCost = (float) ($p->meta['cost_trimmed'] ?? 0);
        $p->pnl_pct = $totalCost > 0 ? $p->pnl_usd / $totalCost * 100 : 0;
        $p->close_rule = $rule;
        $p->closed_at = now();
    }

    /** The venue id an executor books against: order_intents.venue for live executors, 'paper' for the simulator, null when it cannot say. */
    private function venueOf(Executor $executor): ?string
    {
        if ($executor instanceof ReconcilesOrders) {
            return $executor->orderVenue();
        }

        return $executor->mode() === 'paper' ? 'paper' : null;
    }

    /**
     * True (after logging and paging) when the executor cannot reach the venue this position lives on:
     * spot and perps share mode and desk product ids, so a config flip must not route an exit, trim or
     * add to the wrong venue. A NULL pinned venue (a row from before venues were recorded that the
     * migration could not infer) is treated as matching the current executor, with a warning.
     */
    private function refusesVenue(Position $p, Executor $executor, string $what): bool
    {
        $current = $this->venueOf($executor);
        if ($current === null) {
            return false;
        }
        if ($this->onOtherVenue($p, $executor)) {
            $this->reporter->error('RISK', "{$what} REFUSED {$p->product_id}: position lives on {$p->venue} but the configured executor is {$current}; no order sent. Switch the exchange/perps setting back to close it.");

            return true;
        }
        if ($p->venue === null) {
            $this->reporter->warn('RISK', "{$p->product_id}: position has no pinned venue, assuming {$current} for {$what}");

            return false;
        }

        return false;
    }

    /** Pure check: a pinned venue that differs from the executor's. NULL (unpinned) or an executor that cannot name its venue never mismatches. */
    private function onOtherVenue(Position $p, Executor $executor): bool
    {
        $current = $this->venueOf($executor);

        return $current !== null && $p->venue !== null && $p->venue !== $current;
    }

    /**
     * Close a position. Routes to the executor matching the POSITION's own stored mode, never the
     * desk's current global mode — a paper position is never sent to the live executor just because
     * someone flipped the desk to live, and vice versa. Passing an explicit $executor whose mode does
     * not match the position throws rather than silently mis-routing the order.
     *
     * A fill that only partially closes the position (an IOC exit that didn't fully fill) keeps the
     * remainder open with a pro-rated cost basis; only a fill that empties the position (within
     * QTY_EPSILON) actually marks it closed. Under 60 seconds from trigger to fill sent.
     */
    public function close(Position $p, string $rule, float $decisionPrice, ?Executor $executor = null): ?Fill
    {
        if ($p->arena_seat_id !== $this->arenaSeatId) {
            // Cash, fills and funding must land on the account that owns the position, not the caller's.
            return $this->forSeat($p->arena_seat_id)->close($p, $rule, $decisionPrice, $executor);
        }
        if ($executor !== null && $executor->mode() !== $p->mode) {
            throw new ExecutionModeMismatchException(sprintf(
                'position %s is %s-mode; refusing to close it with a %s-mode executor',
                $p->product_id, $p->mode, $executor->mode()
            ));
        }
        $executor ??= $this->executorForMode($p->mode);
        if ($executor instanceof PaperExecutor) {
            // A caller-supplied paper executor may be scoped to a different account; cash must follow the position.
            $executor = $executor->withSeat($p->arena_seat_id);
        }
        $mode = $p->mode;
        $pid = $p->product_id;

        return $this->mutateLock($mode, $pid)->block(5, function () use ($p, $rule, $decisionPrice, $executor) {
            $p->refresh();
            if ($p->status !== 'open') {
                // Already settled by a concurrent caller under this same lock — nothing more to do.
                return null;
            }
            if ($this->refusesVenue($p, $executor, "CLOSE ({$rule})")) {
                return null;
            }

            // The exchange call stays outside the transaction — a DB rollback cannot un-send an order.
            $result = $p->isShort() ? $executor->coverShort($p->product_id, $p->quantity, $decisionPrice, (float) $p->entry_usd) : $executor->sell($p->product_id, $p->quantity, $decisionPrice, (float) $p->entry_usd);

            return DB::transaction(function () use ($p, $rule, $executor, $result) {
                $fill = Fill::create($this->fillAttrs(null, $p, $p->product_id, $p->isShort() ? 'BUY' : 'SELL', 'exit', $result, $executor->mode()));
                $this->resolveIntentFor($result);

                // Nothing sellable was left (below the venue minimum): no order went out, so the whole holding is a write-off.
                $dustOnly = $result->status === 'filled' && $result->filledQty <= 0 && $result->dustQty > 0;

                if (! $dustOnly && ! $result->ok()) {
                    $this->reporter->error('RISK', ($result->raw['blocked_by_intent'] ?? null) !== null
                        ? "EXIT BLOCKED {$p->product_id} ({$rule}) by unresolved live order {$result->raw['blocked_by_intent']}: resolve it (desk:intents:resolve) — ".($result->note ?? '')
                        : "CLOSE FAILED {$p->product_id} ({$rule}): ".($result->note ?? $result->status));

                    return $fill;
                }

                // Paper fills defer their cash-ledger write to here, inside this same transaction, so a
                // rollback below (a save() failure) undoes the cash movement too — no longer possible to
                // end up with cash moved while the position stays open at full quantity for the next
                // sweep to sell (and credit) a second time. Live fills carry no ledgerWrite; no-op.
                $result->writeLedger();

                $netPnl = $dustOnly ? 0.0 : $this->bookExit($p, $result);

                if ($result->basisRecovered) {
                    // Same hazard doEnter()'s slippage check guards against: a recovered fill's
                    // slippage is unmeasured, not zero, and exit_price/pnl here come straight off
                    // that same fabricated basis (round-6 review, MAJOR — proven: a sell recovered
                    // to the decision price booked pnl overstated by the real, unmeasured
                    // slippage, understating risk.daily_loss_cap_pct via dailyPnlPct()).
                    $this->reporter->error('FILLS', sprintf('FILL BASIS RECOVERED (slippage unmeasured) — %s %s [%s] @ %.6f', $p->isShort() ? 'COVER' : 'SELL', $p->product_id, $rule, $result->fillPrice));
                }

                if ($p->quantity > 0 && $result->dustQty > 0 && $result->dustQty + self::QTY_EPSILON >= $p->quantity) {
                    // What is left is smaller than the venue can ever sell: close the position and write its cost off,
                    // otherwise it sits open and the force-close ladder burns its attempts on an order that cannot exist.
                    $this->writeOffDust($p);
                    $this->reporter->warn('RISK', sprintf('%s [%s]: %.8f below the venue minimum, written off as dust', $p->product_id, $rule, $result->dustQty));
                }

                if ($p->quantity > self::QTY_EPSILON) {
                    $p->save();
                    $this->reporter->trade('RISK', sprintf('PARTIAL CLOSE %s [%s] pnl $%.2f, %.8f still open', $p->product_id, $rule, $netPnl, $p->quantity), ['position_id' => $p->id]);

                    return $fill;
                }

                $this->markFullyClosed($p, $result, $rule);
                $p->save();
                $this->reporter->trade('RISK', sprintf('CLOSE %s [%s] pnl $%.2f (%.2f%%) held %dm', $p->product_id, $rule, $p->pnl_usd, $p->pnl_pct, $p->heldMinutes()), ['position_id' => $p->id]);

                return $fill;
            });
        });
    }

    /**
     * Coinbase US perps charge funding hourly on open notional; a positive rate means longs pay
     * shorts. Paper mirrors that here, paced to once per elapsed hour via meta['funding_at'] so a
     * riskSweep tick before the hour is up is a no-op.
     */
    public function accrueFunding(Position $p, float $price): void
    {
        $rate = (float) $this->settings->get('fees.funding_hourly_pct', 0.00125);
        if ($rate == 0.0 || $price <= 0) {
            return;
        }
        // Ledger row and cursor move together, under the product lock, off a fresh read: a lock timeout
        // commits nothing, and a second sweep can never re-charge an interval already booked.
        $this->mutateLock($p->mode, $p->product_id)->block(5, function () use ($p, $price, $rate) {
            $fresh = Position::find($p->id);
            if ($fresh === null || $fresh->status !== 'open') {
                return;
            }
            $meta = $fresh->meta ?? [];
            $lastAt = isset($meta['funding_at']) ? Carbon::parse($meta['funding_at']) : $fresh->opened_at;
            $hours = $lastAt->diffInSeconds(now()) / 3600;
            if ($hours < 1.0) {
                return;
            }
            $notional = $fresh->quantity * $price;
            $owed = $notional * $rate / 100 * $hours * $fresh->dir();

            DB::transaction(function () use ($fresh, $p, $meta, $owed, $notional, $rate, $hours) {
                PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'funding', 'amount' => -$owed, 'ref' => $fresh->product_id, 'note' => sprintf('funding %.5f%%/h on $%.2f notional over %.2fh', $rate, $notional, $hours)]);
                $meta['funding_usd'] = (float) ($meta['funding_usd'] ?? 0) + $owed;
                $meta['funding_at'] = now()->toIso8601String();
                $fresh->meta = $meta;
                if ($p->last_price !== null) {
                    $fresh->markPrice((float) $p->last_price);
                }
                $fresh->save();
            });
            $p->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    /**
     * Sell part of a position (take-profit rung). Banks the net gain (after this event's share of
     * entry fee, its own exit fee, and its share of accumulated funding) in realised_usd, keeps the
     * rest open — unless the trim empties the position, in which case it closes like close() would.
     * Routes through the position's own mode's executor and the same per-product lock as close().
     */
    public function trim(Position $p, float $fraction, string $rule, float $decisionPrice, ?Executor $executor = null, ?float $limitPrice = null): ?Fill
    {
        if ($p->arena_seat_id !== $this->arenaSeatId) {
            return $this->forSeat($p->arena_seat_id)->trim($p, $fraction, $rule, $decisionPrice, $executor, $limitPrice);
        }
        if ($executor !== null && $executor->mode() !== $p->mode) {
            throw new ExecutionModeMismatchException(sprintf(
                'position %s is %s-mode; refusing to trim it with a %s-mode executor',
                $p->product_id, $p->mode, $executor->mode()
            ));
        }
        $executor ??= $this->executorForMode($p->mode);
        if ($executor instanceof PaperExecutor) {
            // A caller-supplied paper executor may be scoped to a different account; cash must follow the position.
            $executor = $executor->withSeat($p->arena_seat_id);
        }
        $mode = $p->mode;
        $pid = $p->product_id;

        return $this->mutateLock($mode, $pid)->block(5, function () use ($p, $fraction, $rule, $decisionPrice, $executor, $limitPrice) {
            $p->refresh();
            if ($p->status !== 'open') {
                return null;
            }
            if ($this->refusesVenue($p, $executor, "TRIM ({$rule})")) {
                return null;
            }

            $qty = $p->quantity * min(1.0, max(0.0, $fraction));
            if ($qty <= 0) {
                return null;
            }
            $spec = Perps::enabled() && config('desk.perps.whole_contracts') ? Perps::spec($p->product_id) : null;
            if ($spec !== null && $fraction < 1 && $qty < $spec['contract_size']) {
                // A rung that asks for less than one contract cannot fill on CFM; the ladder rides to a rung that can.
                $this->reporter->info('RISK', sprintf('%s %s: %.4f < one contract (%.4f), holding', $p->product_id, $rule, $qty, $spec['contract_size']));

                return null;
            }
            // A TRIM carrying a limit price rested on the book as a TP rung rather than crossing the spread: maker fill.
            $maker = $limitPrice !== null;
            $entryShare = (float) $p->entry_usd * min(1.0, max(0.0, $fraction));
            $qtyBefore = (float) $p->quantity;

            // The exchange call stays outside the transaction — a DB rollback cannot un-send an order.
            $result = $p->isShort()
                ? $executor->coverShort($p->product_id, $qty, $decisionPrice, $entryShare, $maker)
                : $executor->sell($p->product_id, $qty, $decisionPrice, $entryShare, $maker);

            if ($result->status === 'filled' && $result->filledQty <= 0.0) {
                // Whole-contract flooring zeroed this rung out (a sub-contract remainder that is not a
                // legacy fractional holding, per PaperExecutor::legacyFractional()) — nothing filled,
                // nothing to book. Not a failure: skip explicitly rather than falling into the generic
                // "TRIM FAILED" branch below, which is for genuine rejections (margin, halts, etc).
                $this->resolveIntentFor($result);
                $this->reporter->info('RISK', sprintf('%s %s: rung floored to zero contracts, holding', $p->product_id, $rule));

                return null;
            }

            return DB::transaction(function () use ($p, $rule, $result, $executor, $qtyBefore, $limitPrice) {
                $fill = Fill::create($this->fillAttrs(null, $p, $p->product_id, $p->isShort() ? 'BUY' : 'SELL', 'trim', $result, $executor->mode()));
                $this->resolveIntentFor($result);
                if (! $result->ok()) {
                    if (($result->raw['blocked_by_intent'] ?? null) !== null) {
                        $this->reporter->error('RISK', "EXIT BLOCKED {$p->product_id} ({$rule}) by unresolved live order {$result->raw['blocked_by_intent']}: resolve it (desk:intents:resolve) — ".($result->note ?? ''));
                    } else {
                        $this->reporter->warn('RISK', "TRIM FAILED {$p->product_id} ({$rule}): ".($result->note ?? $result->status));
                    }

                    return $fill;
                }

                $this->postOnlyShadows->record($fill, $p, 'trim', $limitPrice);

                // See close() above: the paper ledger write happens here, inside this transaction.
                $result->writeLedger();

                $soldFraction = min(1.0, $result->filledQty / max($qtyBefore, 1e-12));
                $netPnl = $this->bookExit($p, $result);
                $p->trims_count = $p->trims_count + 1;
                // v2's ladder reconciles its rung price from this, not the rung's computed target
                // (JsonPluginStrategy::reconcilePendingRung(), docs/STRATEGY_SCHEMA_V2.md review round 1)
                // — a live/paper trim fills at market, not at the target. No null/zero guard needed
                // here: we already returned above unless $result->ok(), which now requires a
                // positive fillPrice (round-4 review, OrderResult::ok()).
                $meta = $p->meta ?? [];
                $meta['v2']['last_trim_fill_price'] = (float) $result->fillPrice;
                $p->meta = $meta;

                if ($p->quantity > self::QTY_EPSILON) {
                    $p->save();
                } else {
                    $this->markFullyClosed($p, $result, $rule);
                    $p->save();
                }

                if ($result->basisRecovered) {
                    // Same hazard as close() (round-6 review, MAJOR): a recovered fill's slippage
                    // is unmeasured, not zero, and the banked pnl above comes straight off that
                    // same fabricated basis.
                    $this->reporter->error('FILLS', sprintf('FILL BASIS RECOVERED (slippage unmeasured) — TRIM %s [%s] @ %.6f', $p->product_id, $rule, $result->fillPrice));
                }

                $this->reporter->trade('RISK', sprintf('TRIM %s [%s] sold %.0f%% @ %.6f, banked $%.2f (total banked $%.2f)', $p->product_id, $rule, $soldFraction * 100, $result->fillPrice, $netPnl, $p->realised_usd), ['position_id' => $p->id]);

                return $fill;
            });
        });
    }

    /** "Message the human mid session only when a number moves more than 10%." */
    private function notifyOnBigMove(Executor $executor): void
    {
        try {
            $bank = $this->bank($executor);
        } catch (\Throwable) {
            return;
        }
        $key = 'desk:last_notified_equity:'.$executor->mode();
        $last = (float) Cache::get($key, 0);
        $eq = $bank->equity();
        if ($last <= 0) {
            Cache::forever($key, $eq);

            return;
        }
        $move = ($eq / $last - 1) * 100;
        if (abs($move) >= (float) $this->settings->get('chief.notify_move_pct', 10)) {
            $this->reporter->telegram(sprintf('%s equity moved %+.1f%%: $%.2f -> $%.2f', $move > 0 ? '📈' : '📉', $move, $last, $eq));
            Cache::forever($key, $eq);
        }
    }
}
