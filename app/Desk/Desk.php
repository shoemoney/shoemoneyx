<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Execution\Executor;
use App\Desk\Execution\ExecutionModeMismatchException;
use App\Desk\Execution\MarginBook;
use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Execution\Perps;
use App\Desk\Execution\PerpsCalendar;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use App\Desk\Execution\PostOnlyShadows;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Exchange\Contracts\Exchange;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
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
        $ctx = new DeskContext($this->settings->merged($strategy), $mode ?? $this->mode(), $degraded, $this->openPositions($mode));

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

    public function cycle(): DeskRun
    {
        return $this->runCycle($this->strategy(), $this->executor(), null);
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
        $health = $this->chief->health();

        $run = DeskRun::create([
            'mode' => $executor->mode(),
            'strategy' => $strategy->key(),
            'degraded' => $health['degraded'],
            'started_at' => now(),
        ]);
        $this->reporter->runId = $run->id;

        try {
            if ($this->chief->halted()) {
                throw new \RuntimeException('desk is halted: '.($this->chief->halted()['reason'] ?? ''));
            }
            if (! $health['ready']) {
                $this->reporter->warn('CHIEF', 'not READY — fewer than three green checks; no new entries this cycle', $health['checks']);
                $run->update(['status' => 'done', 'finished_at' => now(), 'stats' => ['health' => $health['checks']]]);

                return $run;
            }

            $ctx = $this->context($strategy, $health['degraded'], $executor->mode());
            $bank = $this->bank($executor, true);

            // ---- SCAN ------------------------------------------------
            $this->chief->heartbeat('SCAN');
            $universe ??= $this->universe();
            $candidates = $strategy->scan($universe, $ctx);
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
                if (microtime(true) >= $bookDeadline) {
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
                    'pct_of_free_cash' => $size->percentOfFreeCash,
                    'pct_of_bank' => $size->percentOfBank,
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
                    $fill = $this->enter($executor, $size, $ctx, $run, $rows[$c->productId()]);
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
            try {
                $out[] = $this->stats->live($p);
            } catch (\Throwable $e) {
                $this->reporter->warn('SCAN', "stats failed for {$p->product_id}: ".$e->getMessage());
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
        return max(90, (int) config('coinbase.timeout', 30) * 3);
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

    private function enter(Executor $executor, SizeDecision $size, DeskContext $ctx, DeskRun $run, Candidate $row): ?Fill
    {
        $c = $size->verdict->candidate;
        $pid = $c->productId();
        $mode = $executor->mode();

        // Serialize every mutation of this product's position/cash under one lock, and re-read the
        // position fresh under it — the copy on $ctx was snapshotted before this candidate's turn and
        // can be stale if another caller (API close/trim, a parallel cycle) touched it meanwhile.
        return $this->mutateLock($mode, $pid)->block(5, function () use ($executor, $size, $ctx, $run, $row, $c, $pid, $mode) {
            return $this->doEnter($executor, $size, $ctx, $run, $row, $c, $pid, $mode);
        });
    }

    private function doEnter(Executor $executor, SizeDecision $size, DeskContext $ctx, DeskRun $run, Candidate $row, CandidateRow $c, string $pid, string $mode): ?Fill
    {
        $existing = Position::open()->mode($mode)->seat($this->arenaSeatId)->where('product_id', $pid)->first();
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

        // The exchange call stays outside the transaction — a DB rollback cannot un-send an order.
        $result = $short ? $executor->openShort($pid, $size->dollars, $c->stats->price) : $executor->buy($pid, $size->dollars, $c->stats->price);

        [$fill, $position] = DB::transaction(function () use ($run, $existing, $pid, $orderSide, $kind, $result, $executor, $c, $row, $short) {
            $fill = Fill::create($this->fillAttrs($run, $existing, $pid, $orderSide, $kind, $result, $executor->mode()));

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
                    'strategy' => $run->strategy,
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
        if ($slip > (float) $ctx->param('size.max_slippage_bps', 50)) {
            $this->reporter->error('FILLS', 'SLIPPAGE OVER MAX — '.$msg);
        } else {
            $this->reporter->trade('FILLS', $msg, ['position_id' => $position->id]);
        }

        return $fill;
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
        $existing = Position::open()->mode($mode)->seat($this->arenaSeatId)->where('product_id', $pid)->first();
        if (! $existing) {
            return null;   // closed since RISK read it — nothing left to add to
        }
        $short = $existing->isShort();
        $orderSide = $short ? 'SELL' : 'BUY';
        $dollars = (float) $decision->dollars;
        $price = (float) ($existing->last_price ?? $existing->entry_price);

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

        $result = $short ? $executor->openShort($pid, $dollars, $price) : $executor->buy($pid, $dollars, $price);

        [$fill, $position] = DB::transaction(function () use ($existing, $pid, $orderSide, $result, $executor) {
            $fill = Fill::create($this->fillAttrs(null, $existing, $pid, $orderSide, 'add', $result, $executor->mode()));
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
            $ctx = $this->context($strategy, false, $executor->mode());
            $stats = $this->statsWithRetries($p->product_id, (int) $ctx->param('risk.stale_data_retries', 2));

            if ($stats === null) {
                $decision = RiskDecision::close('unmeasurable', null, null, null, 'no answer after retries — a position you cannot measure is a position you do not hold');
            } else {
                $p->markPrice($stats->price);
                $decision = $strategy->risk($p, $stats, $ctx);
                $p->save();
            }

            $price = $stats?->price ?? (float) ($p->last_price ?? $p->entry_price);
            if ($executor instanceof PaperExecutor && Perps::enabled()) {
                $this->accrueFunding($p, $price);
            }
            RiskCheck::create([
                'position_id' => $p->id,
                'action' => $decision->action,
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
                    $this->close($p, $decision->ruleFired ?? 'risk', $price, $executor);
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

            $out[] = ['position' => $p->product_id, 'action' => $decision->action, 'rule' => $decision->ruleFired];
        }

        $this->notifyOnBigMove($executor);

        return $out;
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

    private function markFullyClosed(Position $p, OrderResult $result, string $rule): void
    {
        $p->status = 'closed';
        $p->exit_price = $result->fillPrice;
        $p->exit_usd = $result->filledUsd;
        $p->pnl_usd = (float) $p->realised_usd;
        $totalCost = (float) ($p->meta['cost_trimmed'] ?? 0);
        $p->pnl_pct = $totalCost > 0 ? $p->pnl_usd / $totalCost * 100 : 0;
        $p->close_rule = $rule;
        $p->closed_at = now();
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
        if ($executor !== null && $executor->mode() !== $p->mode) {
            throw new ExecutionModeMismatchException(sprintf(
                'position %s is %s-mode; refusing to close it with a %s-mode executor',
                $p->product_id, $p->mode, $executor->mode()
            ));
        }
        $executor ??= $this->executorForMode($p->mode);
        $mode = $p->mode;
        $pid = $p->product_id;

        return $this->mutateLock($mode, $pid)->block(5, function () use ($p, $rule, $decisionPrice, $executor) {
            $p->refresh();
            if ($p->status !== 'open') {
                // Already settled by a concurrent caller under this same lock — nothing more to do.
                return null;
            }

            // The exchange call stays outside the transaction — a DB rollback cannot un-send an order.
            $result = $p->isShort() ? $executor->coverShort($p->product_id, $p->quantity, $decisionPrice, (float) $p->entry_usd) : $executor->sell($p->product_id, $p->quantity, $decisionPrice, (float) $p->entry_usd);

            return DB::transaction(function () use ($p, $rule, $executor, $result) {
                $fill = Fill::create($this->fillAttrs(null, $p, $p->product_id, $p->isShort() ? 'BUY' : 'SELL', 'exit', $result, $executor->mode()));

                if (! $result->ok()) {
                    $this->reporter->error('RISK', "CLOSE FAILED {$p->product_id} ({$rule}): ".($result->note ?? $result->status));

                    return $fill;
                }

                // Paper fills defer their cash-ledger write to here, inside this same transaction, so a
                // rollback below (a save() failure) undoes the cash movement too — no longer possible to
                // end up with cash moved while the position stays open at full quantity for the next
                // sweep to sell (and credit) a second time. Live fills carry no ledgerWrite; no-op.
                $result->writeLedger();

                $netPnl = $this->bookExit($p, $result);

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
        $meta = $p->meta ?? [];
        $lastAt = isset($meta['funding_at']) ? Carbon::parse($meta['funding_at']) : $p->opened_at;
        $hours = $lastAt->diffInSeconds(now()) / 3600;
        if ($hours < 1.0) {
            return;
        }
        $notional = $p->quantity * $price;
        $owed = $notional * $rate / 100 * $hours * $p->dir();
        PaperLedger::create(['arena_seat_id' => $this->arenaSeatId, 'kind' => 'funding', 'amount' => -$owed, 'ref' => $p->product_id, 'note' => sprintf('funding %.5f%%/h on $%.2f notional over %.2fh', $rate, $notional, $hours)]);
        $meta['funding_usd'] = (float) ($meta['funding_usd'] ?? 0) + $owed;
        $meta['funding_at'] = now()->toIso8601String();
        $p->update(['meta' => $meta]);
    }

    /**
     * Sell part of a position (take-profit rung). Banks the net gain (after this event's share of
     * entry fee, its own exit fee, and its share of accumulated funding) in realised_usd, keeps the
     * rest open — unless the trim empties the position, in which case it closes like close() would.
     * Routes through the position's own mode's executor and the same per-product lock as close().
     */
    public function trim(Position $p, float $fraction, string $rule, float $decisionPrice, ?Executor $executor = null, ?float $limitPrice = null): ?Fill
    {
        if ($executor !== null && $executor->mode() !== $p->mode) {
            throw new ExecutionModeMismatchException(sprintf(
                'position %s is %s-mode; refusing to trim it with a %s-mode executor',
                $p->product_id, $p->mode, $executor->mode()
            ));
        }
        $executor ??= $this->executorForMode($p->mode);
        $mode = $p->mode;
        $pid = $p->product_id;

        return $this->mutateLock($mode, $pid)->block(5, function () use ($p, $fraction, $rule, $decisionPrice, $executor, $limitPrice) {
            $p->refresh();
            if ($p->status !== 'open') {
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
                $this->reporter->info('RISK', sprintf('%s %s: rung floored to zero contracts, holding', $p->product_id, $rule));

                return null;
            }

            return DB::transaction(function () use ($p, $rule, $result, $executor, $qtyBefore, $limitPrice) {
                $fill = Fill::create($this->fillAttrs(null, $p, $p->product_id, $p->isShort() ? 'BUY' : 'SELL', 'trim', $result, $executor->mode()));
                if (! $result->ok()) {
                    $this->reporter->warn('RISK', "TRIM FAILED {$p->product_id} ({$rule}): ".($result->note ?? $result->status));

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
                // — a live/paper trim fills at market, not at the target. Guarded against a null OR
                // zero fillPrice (CoinbaseExecutor can report 0.0 on a poll with filled_size > 0 but
                // no filled_value) — reconcilePendingRung()'s is_numeric() fallback guard treats 0.0
                // as present, which would stamp a zero sale price and silently kill the re-entry loop.
                if ($result->fillPrice !== null && $result->fillPrice > 0) {
                    $meta = $p->meta ?? [];
                    $meta['v2']['last_trim_fill_price'] = (float) $result->fillPrice;
                    $p->meta = $meta;
                }

                if ($p->quantity > self::QTY_EPSILON) {
                    $p->save();
                } else {
                    $this->markFullyClosed($p, $result, $rule);
                    $p->save();
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
