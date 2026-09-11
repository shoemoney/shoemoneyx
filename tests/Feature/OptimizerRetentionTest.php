<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DeskOptimize;
use App\Desk\Settings;
use App\Models\Backtest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-performance review, cluster C: the retention strip pass used to re-evaluate a JSON
 * predicate (whereNotNull('equity_curve')) over every old row on every batch, including rows already
 * stripped — most of a pass at ~615k rows even though it matched nothing. It is now a keyset-paginated
 * (payload_stripped_at IS NULL, created_at, id) range scan that only ever examines unstripped rows,
 * and it must never strip a round's champion slot or a row another row still names as its cache source.
 */
class OptimizerRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $overrides = []): Backtest
    {
        return Backtest::create(array_merge([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDays(8), 'to' => now()->subDay(),
            'starting_cash' => 10000, 'status' => 'done', 'stats' => ['total_return_pct' => 1.0],
            'equity_curve' => [[0, 10000], [1, 10100]], 'trades' => [['pnl_usd' => 100]],
            'created_at' => now()->subHours(3), 'params' => ['_opt' => ['coin' => 'BTC-USD', 'cand' => 1]],
        ], $overrides));
    }

    public function test_a_fresh_row_created_during_the_pass_is_never_touched(): void
    {
        config(['cache.default' => 'array']);
        $fresh = $this->row(['created_at' => now(), 'params' => ['_opt' => ['coin' => 'BTC-USD', 'cand' => 1]]]);

        DeskOptimize::prune();

        $this->assertNotNull($fresh->fresh()->equity_curve, 'the cutoff is captured once per pass; a row inside the retention window is never a candidate');
        $this->assertNull($fresh->fresh()->payload_stripped_at);
    }

    public function test_an_eligible_row_is_stripped_and_marked(): void
    {
        config(['cache.default' => 'array']);
        $old = $this->row();

        DeskOptimize::prune();

        $fresh = $old->fresh();
        $this->assertNull($fresh->equity_curve);
        $this->assertNull($fresh->trades);
        $this->assertNotNull($fresh->payload_stripped_at);
        $this->assertNotNull($fresh->stats, 'stats survive — only the heavy payloads strip');
    }

    public function test_a_round_champion_slot_is_never_stripped(): void
    {
        config(['cache.default' => 'array']);
        $champion = $this->row(['params' => ['_opt' => ['coin' => 'BTC-USD', 'cand' => 0]]]);

        DeskOptimize::prune();

        $this->assertNotNull($champion->fresh()->equity_curve, 'cand 0 is the round\'s own champion slot');
        $this->assertNull($champion->fresh()->payload_stripped_at);
    }

    public function test_a_row_still_named_as_a_cache_source_is_never_stripped(): void
    {
        config(['cache.default' => 'array']);
        $source = $this->row(['params' => ['_opt' => ['coin' => 'BTC-USD', 'cand' => 1]]]);
        $this->row(['created_at' => now()->subHours(1), 'params' => ['_opt' => ['coin' => 'BTC-USD', 'cand' => 2, 'cached_from' => $source->id]]]);

        DeskOptimize::prune();

        $this->assertNotNull($source->fresh()->equity_curve, 'a clone still points here — the original must survive');
    }

    /**
     * Reviewer blocker 2: the strip pass dropped its whereNotNull('params->_opt') guard, so a plain
     * user-run backtest (no `_opt` bookkeeping at all) reads NULL for `params->_opt->cand`, and
     * `NULL = 0` is NULL (falsy) — the champion guard failed OPEN and stripped (then eventually
     * deleted) backtests the optimizer never created. whereNotNull('opt_coin') on the batch query
     * excludes anything without `_opt` from ever being a stripping candidate.
     */
    public function test_a_backtest_with_no_opt_bookkeeping_at_all_is_never_stripped(): void
    {
        config(['cache.default' => 'array']);
        $userRun = $this->row(['params' => ['mr.timeframe' => '2m']]);

        DeskOptimize::prune();

        $this->assertNotNull($userRun->fresh()->equity_curve, 'a backtest with no _opt bookkeeping must never be treated as prunable optimizer noise');
        $this->assertNull($userRun->fresh()->payload_stripped_at);
    }

    public function test_a_mostly_stripped_dataset_is_walked_without_re_examining_stripped_rows(): void
    {
        config(['cache.default' => 'array']);
        for ($i = 0; $i < 300; $i++) {
            $this->row(['payload_stripped_at' => now(), 'equity_curve' => null, 'trades' => null]);
        }
        $target = $this->row();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        DeskOptimize::prune();

        // One pass over an already-stripped backlog touches only a handful of statements (the delete
        // sweep, one keyset batch, the champion/cached_from lookups, one update) — not one query per row.
        $this->assertLessThan(20, $queries, 'the strip pass must not scan every already-stripped row individually');
        $this->assertNull($target->fresh()->equity_curve);
        $this->assertNotNull($target->fresh()->payload_stripped_at);
    }

    public function test_prune_is_a_no_op_while_another_process_holds_the_lease(): void
    {
        config(['cache.default' => 'array']);
        $old = $this->row();
        \Illuminate\Support\Facades\Cache::add('optimizer:prune-lease', 1, 600);

        DeskOptimize::prune();

        $this->assertNotNull($old->fresh()->equity_curve, 'a held lease means this call does nothing');
    }

    public function test_rows_older_than_optimizer_retain_hours_are_deleted(): void
    {
        $old = $this->row(['created_at' => now()->subHours(13)]);
        $young = $this->row(['created_at' => now()->subHours(11)]);

        DeskOptimize::prune();
        $this->assertNotNull(Backtest::find($old->id), 'default horizon is 24 h');

        Cache::forget('optimizer:prune-lease');
        app(Settings::class)->set('optimizer.retain_hours', 12);
        DeskOptimize::prune();

        $this->assertNull(Backtest::find($old->id));
        $this->assertNotNull(Backtest::find($young->id));
    }
}
