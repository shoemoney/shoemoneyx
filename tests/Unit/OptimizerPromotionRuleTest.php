<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\DeskOptimize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimizerPromotionRuleTest extends TestCase
{
    use RefreshDatabase;

    /** folds_complete defaults to "the single test window ran" (1) whenever it has a score, matching
     *  the folds=1 default every shouldPromote() call below uses unless it passes $folds explicitly. */
    private function row(?float $train, ?float $test): array
    {
        return ['train' => $train, 'test' => $test, 'test_min' => $test, 'folds_complete' => $test !== null ? 1 : 0];
    }

    public function test_strict_rule_needs_a_win_on_both_windows(): void
    {
        $champ = $this->row(3.62, 2.23);

        $this->assertTrue(DeskOptimize::shouldPromote($champ, $this->row(4.0, 2.5)));
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(5.06, 1.96)), 'better train, worse test: kept champion');
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(6.0, -0.1)), 'loses money on the unseen window');
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(6.0, null)), 'no test result');
    }

    public function test_target_promotes_a_positive_candidate_that_reaches_it_while_the_champion_is_below(): void
    {
        $champ = $this->row(3.62, 2.23);

        $this->assertTrue(DeskOptimize::shouldPromote($champ, $this->row(5.06, 1.96), 5.0), 'BTC 2026-09-05: reaches 5% with a positive test week');
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(4.9, 1.0), 5.0), 'below target and worse on the test window');
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(5.5, -0.2), 5.0), 'target never overrides the positive-test guard');
    }

    public function test_target_does_not_relax_the_rule_for_a_champion_already_above_it(): void
    {
        $champ = $this->row(9.16, 4.76);   // XRP: already over 5%

        $this->assertFalse(DeskOptimize::shouldPromote($champ, $this->row(9.5, 1.0), 5.0), 'must still beat the champion on the test window');
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $this->row(9.5, 5.0), 5.0));
    }

    public function test_unscorable_champion_never_blocks_but_null_candidate_never_promotes(): void
    {
        $this->assertTrue(DeskOptimize::shouldPromote($this->row(null, null), $this->row(1.0, 0.5)));
        $this->assertFalse(DeskOptimize::shouldPromote($this->row(3.0, 1.0), $this->row(null, 1.0)));
    }

    public function test_rank_calmar_promotes_a_lower_return_higher_calmar_candidate(): void
    {
        $champ = ['train' => 3.0, 'test' => 1.0, 'train_calmar' => 1.0, 'test_calmar' => 1.0];
        $best = ['train' => 2.0, 'test' => 0.5, 'train_calmar' => 5.0, 'test_calmar' => 3.0, 'test_min' => 0.5, 'folds_complete' => 1];

        $this->assertFalse(DeskOptimize::shouldPromote($champ, $best, 0.0, 'return'), 'lower raw return does not beat champion on return');
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $best, 0.0, 'calmar'), 'higher Calmar on both windows promotes under rank=calmar');
    }

    public function test_dsr_gate_blocks_a_low_confidence_candidate(): void
    {
        $this->assertTrue(DeskOptimize::passesDsr(null, 0.0), 'min-dsr=0 is report-only');
        $this->assertFalse(DeskOptimize::passesDsr(null, 0.9), 'no DSR fails a live gate');
        $this->assertFalse(DeskOptimize::passesDsr(0.85, 0.9));
        $this->assertTrue(DeskOptimize::passesDsr(0.95, 0.9));
    }

    public function test_neighbours_covers_edges_interior_and_categorical_skip(): void
    {
        $space = [
            'mr.timeframe' => ['15s', '30s', '45s'],
            'mr.engine.x1' => [3, 4, 6, 9],
            'mr.allow_shorts' => [false, true],
        ];

        $edge = DeskOptimize::neighbours(['mr.timeframe' => '30s', 'mr.engine.x1' => 3, 'mr.allow_shorts' => false], $space);
        $this->assertCount(1, $edge, 'x1 sits at the low edge of its list: one neighbour');
        $this->assertSame(4, $edge[0]['mr.engine.x1']);

        $interior = DeskOptimize::neighbours(['mr.timeframe' => '30s', 'mr.engine.x1' => 4, 'mr.allow_shorts' => false], $space);
        $this->assertCount(2, $interior, 'x1 sits in the interior: two neighbours');
        $this->assertEqualsCanonicalizing([3, 6], array_column($interior, 'mr.engine.x1'));

        foreach ([...$edge, ...$interior] as $c) {
            $this->assertSame('30s', $c['mr.timeframe'], 'categorical key never nudged');
            $this->assertSame(false, $c['mr.allow_shorts'], 'boolean key never nudged');
        }
    }

    /**
     * Reviewer blocker 5: foldsValid() used to skip the worst-fold positivity check entirely whenever
     * foldsRequested < 2, making it vacuous (folds_complete alone) at the default --folds=1. A single
     * fold must itself be positive to be "valid" — at folds=1, test_min is always exactly that one
     * fold's own return, so this is "a single complete positive fold", not a new restriction on real data.
     */
    public function test_folds_require_every_fold_positive(): void
    {
        $champ = $this->row(3.0, 0.5);

        $singleFold = ['train' => 5.0, 'test' => -0.5, 'test_min' => -0.5, 'folds_complete' => 1];
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $singleFold, 0.0, 'return', 1), 'folds=1: the one fold itself must be positive');
        $singleFold['test'] = $singleFold['test_min'] = 1.0;
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $singleFold, 0.0, 'return', 1));

        $best = ['train' => 5.0, 'test' => 1.0, 'test_min' => -0.5, 'folds_complete' => 2];   // both folds ran; mean positive, worst fold negative
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $best, 0.0, 'return', 2), 'folds>=2: every fold must be positive, not just the mean');

        $best['test_min'] = 0.1;
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $best, 0.0, 'return', 2), 'folds>=2 with every fold positive still promotes');
    }

    /** Finding 12: a candidate missing test folds must never be eligible, whatever its mean/min look like. */
    public function test_folds_valid_requires_every_requested_fold_to_have_run(): void
    {
        // one positive fold + two missing (only 1 of 3 folds completed) — never eligible, mean and min
        // both being positive is exactly the trap: nothing here says two folds never ran.
        $onePositiveTwoMissing = ['test' => 4.0, 'test_min' => 4.0, 'folds_complete' => 1];
        $this->assertFalse(DeskOptimize::foldsValid($onePositiveTwoMissing, 3));

        // every fold ran, but one of three is negative — not eligible, whatever the mean says.
        $mixedButComplete = ['test' => 1.2, 'test_min' => -0.8, 'folds_complete' => 3];
        $this->assertFalse(DeskOptimize::foldsValid($mixedButComplete, 3));

        // every fold ran and every fold is positive — eligible.
        $allComplete = ['test' => 1.2, 'test_min' => 0.4, 'folds_complete' => 3];
        $this->assertTrue(DeskOptimize::foldsValid($allComplete, 3));

        // no row at all (e.g. the candidate never scored on train) — never eligible.
        $this->assertFalse(DeskOptimize::foldsValid(null, 3));
    }

    /**
     * Reviewer blocker 5: the first-ever short/mixed-set branch used to hand-roll its own "positive
     * mean" check and never call shouldPromote() at all — a completed negative fold could hide inside
     * that mean (finding 12). round() now routes that branch through shouldPromote() itself (nulled
     * champion, no --target) so it gets the exact same fold-validation gate as every other branch.
     */
    public function test_folds_valid_gates_the_first_short_set_branch_the_same_way(): void
    {
        // Mirrors round()'s first-short/mixed-set branch exactly: $champ['train']/['test'] nulled to
        // "no score", promote = $best !== null && shouldPromote($champ, $best, 0.0, $rank, $folds).
        $folds = 3;
        $missingTwoFolds = ['train' => 6.0, 'test' => 2.0, 'test_min' => 2.0, 'folds_complete' => 1];
        $this->assertFalse(self::shortBranchWouldPromote($missingTwoFolds, $folds), 'two of three folds never completed — must not promote even though train/test are both positive');

        $oneNegativeFoldHiddenByMean = ['train' => 6.0, 'test' => 1.0, 'test_min' => -0.3, 'folds_complete' => 3];
        $this->assertFalse(self::shortBranchWouldPromote($oneNegativeFoldHiddenByMean, $folds), 'a completed negative fold must not hide behind a positive mean');

        $allFoldsPositive = ['train' => 6.0, 'test' => 1.0, 'test_min' => 0.2, 'folds_complete' => 3];
        $this->assertTrue(self::shortBranchWouldPromote($allFoldsPositive, $folds));
    }

    /** Mirrors round()'s first-short/mixed-set promotion expression exactly (see DeskOptimize::round()). */
    private static function shortBranchWouldPromote(array $best, int $folds): bool
    {
        $champ = ['train' => null, 'test' => null];

        return DeskOptimize::shouldPromote($champ, $best, 0.0, 'return', $folds);
    }

    /** Strategy review 8, item 3: the note must say "promoted via --target" ONLY when --target is the
     *  actual reason (champion below target, this candidate reaches it) — never when it also would have
     *  won on plain walk-forward merit, so ablation runs without --target are distinguishable later. */
    public function test_promoted_via_target_is_true_only_when_target_is_the_actual_reason(): void
    {
        $champ = $this->row(3.62, 2.23);

        // wins on merit alone (both windows beat champion on the rank metric) — target had nothing to do with it.
        $wonOnMerit = $this->row(4.0, 2.5);
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $wonOnMerit, 5.0, 'return'));
        $this->assertFalse(DeskOptimize::promotedViaTarget($champ, $wonOnMerit, 5.0, 'return', 1), 'this candidate also beat the champion outright');

        // only reaches the target while the champion sits below it (BTC 2026-09-05 case).
        $viaTarget = $this->row(5.06, 1.96);
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $viaTarget, 5.0, 'return'));
        $this->assertTrue(DeskOptimize::promotedViaTarget($champ, $viaTarget, 5.0, 'return', 1));

        // not promoted at all — never "via target" either.
        $notPromoted = $this->row(4.9, 1.0);
        $this->assertFalse(DeskOptimize::promotedViaTarget($champ, $notPromoted, 5.0, 'return', 1));
    }

    /**
     * Strategy review 8, item 2 + reviewer blocker 6: DSR's trial count must accumulate across rounds
     * for the same (strategy, coin, side, tag) lineage, not reset to "just this round" every time —
     * AND a changed --space under the same tag must start its own count instead of inheriting a
     * differently-shaped space's trial history. There is no dedicated `space` column, so the space is
     * identified by the sorted key-set of each round's own champion_params (built from exactly that
     * round's search-space keys — see champion()).
     */
    public function test_cumulative_trials_adds_this_rounds_trials_to_the_lineages_running_total(): void
    {
        $space = ['mr.timeframe' => ['1m', '2m'], 'mr.qty_pct' => [10, 15]];

        $this->assertSame(15, DeskOptimize::cumulativeTrials('BTC-USD', 'mr', 'long', null, $space, 15), 'no prior rounds: this round is the whole total');

        \App\Models\OptimizerRound::create([
            'product_id' => 'BTC-USD', 'strategy' => 'mr', 'side' => 'long', 'candidates' => 20,
            'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(),
            'champion_params' => ['mr.timeframe' => '1m', 'mr.qty_pct' => 10], 'promoted' => false, 'trials_cumulative' => 20,
        ]);

        $this->assertSame(35, DeskOptimize::cumulativeTrials('BTC-USD', 'mr', 'long', null, $space, 15), 'round 2 uses round1 (20) + round2 (15) trials');

        // a different coin/side/tag lineage never contributes.
        \App\Models\OptimizerRound::create([
            'product_id' => 'ETH-USD', 'strategy' => 'mr', 'side' => 'long', 'candidates' => 99,
            'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(),
            'champion_params' => ['mr.timeframe' => '1m', 'mr.qty_pct' => 10], 'promoted' => false, 'trials_cumulative' => 999,
        ]);
        $this->assertSame(35, DeskOptimize::cumulativeTrials('BTC-USD', 'mr', 'long', null, $space, 15));

        // a --space with a different key set never inherits this lineage's trial history.
        $widerSpace = ['mr.timeframe' => ['1m'], 'mr.qty_pct' => [10], 'mr.engine.x1' => [3, 4]];
        $this->assertSame(15, DeskOptimize::cumulativeTrials('BTC-USD', 'mr', 'long', null, $widerSpace, 15), 'a differently-shaped space starts its own count');
    }

    /** Strategy review 8, item 1: a candidate that wins train+test but loses money on the untouched
     *  holdout window must not promote — a null holdout (the job never finished) blocks the same way. */
    public function test_holdout_blocks_promotion_on_a_negative_or_missing_result_only(): void
    {
        $this->assertTrue(DeskOptimize::holdoutBlocks(null), 'no holdout result at all — never promote on an unverified holdout');
        $this->assertTrue(DeskOptimize::holdoutBlocks(-0.01), 'lost money on the untouched window');
        $this->assertFalse(DeskOptimize::holdoutBlocks(0.0), 'flat is not a loss');
        $this->assertFalse(DeskOptimize::holdoutBlocks(3.2));
    }

    public function test_fold_windows_are_contiguous_and_cover_the_exact_span(): void
    {
        $testFrom = \Illuminate\Support\Carbon::parse('2026-08-01 00:00:00');
        $to = \Illuminate\Support\Carbon::parse('2026-08-08 00:00:00');   // 7 days

        $windows = DeskOptimize::foldWindows($testFrom, $to, 3);

        $this->assertCount(3, $windows);
        $this->assertTrue($testFrom->eq($windows[0][0]), 'first fold starts at testFrom');
        $this->assertTrue($to->eq($windows[2][1]), 'last fold ends at to');
        $this->assertTrue($windows[0][1]->eq($windows[1][0]), 'fold 1 end is fold 2 start');
        $this->assertTrue($windows[1][1]->eq($windows[2][0]), 'fold 2 end is fold 3 start');

        $single = DeskOptimize::foldWindows($testFrom, $to, 1);
        $this->assertCount(1, $single);
        $this->assertTrue($testFrom->eq($single[0][0]));
        $this->assertTrue($to->eq($single[0][1]));
    }

    /** DEPENDENT is currently empty (no shipped strategy has an activate/giveback-style knob pair);
     *  normalise() must still pass every candidate through unchanged rather than erroring or dropping keys. */
    public function test_normalise_is_a_no_op_with_no_dependent_knobs_registered(): void
    {
        $space = ['mr.qty_pct' => [10, 15, 20]];
        $candidate = ['mr.qty_pct' => 15];

        $this->assertSame($candidate, DeskOptimize::normalise($candidate, $space));
    }

    public function test_a_champion_holding_the_target_is_not_replaced_by_a_challenger_under_it(): void
    {
        $champ = ['train' => 11.02, 'test' => 2.10, 'train_calmar' => 7.31, 'test_calmar' => 1.2];
        $better_calmar_under_bar = ['train' => 6.70, 'test' => 2.10, 'train_calmar' => 8.52, 'test_calmar' => 1.4, 'test_min' => 2.10, 'folds_complete' => 1];
        $this->assertFalse(DeskOptimize::shouldPromote($champ, $better_calmar_under_bar, 7.5, 'calmar'), 'ADA short 2026-09-05: higher Calmar, lower return, would drop below the bar');
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $better_calmar_under_bar, 0.0, 'calmar'), 'no target: the rank metric decides');

        $above_bar = ['train' => 12.0, 'test' => 2.2, 'train_calmar' => 8.0, 'test_calmar' => 1.5, 'test_min' => 2.2, 'folds_complete' => 1];
        $this->assertTrue(DeskOptimize::shouldPromote($champ, $above_bar, 7.5, 'calmar'), 'a challenger that also holds the bar can win on the rank metric');

        $champ_below = ['train' => 5.0, 'test' => 1.0, 'train_calmar' => 3.0, 'test_calmar' => 0.5];
        $this->assertTrue(DeskOptimize::shouldPromote($champ_below, $better_calmar_under_bar, 7.5, 'calmar'), 'champion below the bar: ordinary rule applies');
    }

    /**
     * Reviewer blocker 4: --round-minutes stopped bounding a round because wait() recomputed its own
     * fresh "time() + round-minutes*60" deadline on every call (train+test dispatch, the plateau
     * re-run, the holdout score), so a plateau+holdout round could run several times longer than the
     * single budget the flag names. round() now computes one deadline and passes it to every wait()
     * call; wait() itself must never manufacture a second one from the live clock.
     */
    public function test_wait_honours_an_externally_supplied_deadline_instead_of_a_fresh_one(): void
    {
        $method = new \ReflectionMethod(DeskOptimize::class, 'wait');
        $this->assertTrue($method->isPrivate());
        $params = array_map(fn ($p) => $p->getName(), $method->getParameters());
        $this->assertSame(['ids', 'deadline'], $params, 'wait() must take the round\'s deadline as a parameter, not compute its own');

        // An already-past deadline for jobs that will never finish must return immediately rather than
        // sleep(5)-looping toward a fresh --round-minutes allowance. wait() reads $this->output directly
        // (no full console bootstrap here), so stub a NullOutput the way Command::run() would set one.
        $bt = \App\Models\Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDay(), 'to' => now(),
            'starting_cash' => 10000, 'status' => 'queued',
        ]);
        $cmd = new DeskOptimize();
        (new \ReflectionProperty($cmd, 'output'))->setValue($cmd, new \Symfony\Component\Console\Output\NullOutput());
        $start = microtime(true);
        $method->invoke($cmd, [$bt->id], time() - 1);
        $this->assertLessThan(3.0, microtime(true) - $start, 'a past deadline must not sleep waiting for --round-minutes to elapse');
    }

    /** Reviewer blocker 4: plateauScore() and scoreHoldout() must each thread the round's single shared
     *  deadline into their own wait() call rather than letting it default/recompute. */
    public function test_plateau_and_holdout_scoring_accept_the_rounds_shared_deadline(): void
    {
        foreach (['plateauScore', 'scoreHoldout'] as $method) {
            $names = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod(DeskOptimize::class, $method))->getParameters());
            $this->assertContains('deadline', $names, "{$method}() must accept the round's shared deadline");
        }
    }

    /**
     * The mid-task addition: --side=both promotes into the short set exactly like --side=short — the
     * persisted OptimizerRound.side stays 'short' (arena/optimizer pages only know long|short) and the
     * mixed nature rides along as a note suffix instead of a schema change.
     */
    public function test_round_side_both_persists_as_short_with_a_mixed_note_suffix(): void
    {
        $both = DeskOptimize::roundSide('both');
        $this->assertSame('short', $both['persisted']);
        $this->assertStringContainsString('mixed', $both['noteSuffix']);

        $this->assertSame(['persisted' => 'long', 'noteSuffix' => ''], DeskOptimize::roundSide('long'));
        $this->assertSame(['persisted' => 'short', 'noteSuffix' => ''], DeskOptimize::roundSide('short'));
    }
}
