<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Support\Facades\Cache;

use App\Desk\Backtester;
use App\Desk\DeskContext;
use App\Desk\Execution\Perps;
use App\Desk\Optimizer\SearchSpace;
use App\Desk\Reporter;
use App\Desk\Settings;
use App\Desk\Strategies\BacktestVersionPin;
use App\Desk\StrategyRegistry;
use App\Events\BacktestScored;
use App\Events\ChampionPromoted;
use App\Events\OptimizerRoundScored;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\OptimizerRound;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Fire;
use App\Support\Sharpe;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The optimizer: keeps the sweep farm busy and every coin on its best-known settings.
 *
 * Round (per coin):
 *   1. Sample N random parameter sets (timeframe × x1 × range filter × ladder) + the coin's current
 *      settings ("champion").
 *   2. Backtest every one on a TRAIN window (the last `train` days before the test window) and on a
 *      TEST window (the most recent `test` days) — two jobs each, all on the `backtests` queue.
 *   3. Rank candidates by TRAIN return (PF ≥ min_pf, trades ≥ min_trades). The best one is promoted
 *      to the coin's live params ONLY if it also beats the champion on the TEST window it was not
 *      tuned on and is positive there. That is the walk-forward guard against fitting last week.
 *   4. Log the round (optimizer_rounds) and move to the next coin. Loop forever unless --once.
 *
 *   php artisan desk:optimize                 # daemon (pm2: shoemoneyx-optimizer)
 *   php artisan desk:optimize --once --coins=BTC-USD --per-round=24
 */
class DeskOptimize extends Command
{
    protected $signature = 'desk:optimize {--coins= : comma list, default = perps.active / tracked} {--per-round=48} {--train=23} {--test=7} {--min-trades=20} {--min-pf=1.3} {--round-minutes=40 : give up waiting on a round after this} {--light-queue=backtests-light : queue for the short test-window jobs (Pis listen here)} {--tfs= : restrict the timeframe search space, e.g. 45s,90s,1m} {--space=mr : a name under config/spaces (e.g. mr, mr-short), or a path to a .json space file} {--mutate=0 : re-draw 1..N champion keys per candidate instead of a full random draw} {--target=0 : also promote a candidate reaching this train % with a positive test window while the champion is below it} {--pause=0 : seconds to sleep between coins in daemon mode (a 23-day window barely moves in a minute)} {--side=long : long | short | both — short sweeps the <strategy>.short.* set with longs disabled and promotes into it; both sweeps the same <strategy>.short.* set but leaves longs on (the coin\'s current long champion rides along) so candidates score combined long+short PnL, still promoting into the short set} {--rank=calmar : return | calmar — the train metric candidates are ranked and compared on} {--min-dsr=0 : refuse promotion when the deflated Sharpe of the best candidate is below this (0 = report only)} {--plateau=0 : minimum median(neighbour train metric) / best train metric over ±1-step neighbours before promoting (0 = off; research suggests 0.75)} {--cash=10000 : starting cash for every candidate backtest (set it to the live account\'s buying power to see what survives lot sizing)} {--cache-hours=12 : reuse a done backtest already scored on the same strategy/coin/window/cash/params instead of re-running it (0 = never reuse)} {--tag= : label every candidate this round with an experiment tag (params._opt.tag, optimizer_rounds.tag)} {--strategy=mr : strategy key from config/desk.php strategies — also the search-space key prefix (e.g. mr, custom)} {--folds=1 : split the test window into N equal contiguous folds (test/test2/.../testN); walk-forward promotion then also requires every fold positive} {--holdout=0 : exclude this many most-recent days from BOTH train and test selection; the winning candidate is then scored once on that untouched window and a negative holdout return blocks promotion} {--once} {--dry : never promote}';

    protected $description = 'Continuously sweep every coin and promote walk-forward-validated winners';

    /** Fixed for every run (perps costs, stacked defaults). A candidate's own value for one of these wins. */
    /** Knobs that do nothing while their switch is 0: pinned to the space's first value so two such draws dedupe as one trial. */
    private const DEPENDENT = [];

    private const FIXED = [
        'paper.slippage_bps' => 1, 'fees.taker_rate' => 0.0003, 'fees.maker_rate' => 0.0, 'fees.per_contract_usd' => 0.15, 'fees.contract_usd' => 500,   // CFM as documented 2026-09-05: taker 0.03%, maker 0%, $0.15/contract minimum
        // Tune against the venue's real lot size, not fractional contracts a live fill could never book.
        'perps.whole_contracts' => true,
        'perps.margin' => true,   // post overnight margin, reject over-margin entries, liquidate at maintenance — as CFM would
    ];

    public function handle(Settings $settings): int
    {
        $strategyKey = (string) $this->option('strategy');
        do {
            foreach ($this->coins() as $pid) {
                try {
                    $this->round($pid, $strategyKey, $settings);
                    self::prune();
                } catch (\Throwable $e) {
                    $this->error("{$pid}: {$e->getMessage()}");
                    sleep(30);
                }
                if (! $this->option('once') && (int) $this->option('pause') > 0) {
                    sleep((int) $this->option('pause'));
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function coins(): array
    {
        if ($this->option('coins')) {
            return array_map('trim', explode(',', (string) $this->option('coins')));
        }
        $active = (array) config('desk.perps.active', []);
        if ($active !== []) {
            return $active;
        }
        if (Perps::enabled()) {
            return array_keys((array) config('desk.perps.map', []));
        }

        return Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
    }

    private function round(string $pid, string $strategyKey, Settings $settings): void
    {
        // Hour-aligned, not minute-aligned: a window that ends on the current minute is unique to every round, so the
        // candle-slice, series and result caches missed on each new round and MariaDB shipped every 3 MB slice again
        // (2200 % CPU, 2026-09-05). Rounds within the same hour now share every slice; an hour is noise on a 7-day test.
        $now = now()->startOfHour();
        // --holdout carves the most-recent N days out of BOTH train and test selection: $to becomes the
        // selection cutoff (every existing use below — test window, folds, the round's own test_to — is
        // untouched), and [$to, $now] is the untouched window the winning candidate alone gets scored on
        // once, after everything else has already agreed to promote it (see the holdout gate below).
        $holdoutDays = max(0, (int) $this->option('holdout'));
        $to = $now->copy()->subDays($holdoutDays);
        $testFrom = $to->copy()->subDays((int) $this->option('test'));
        $trainFrom = $testFrom->copy()->subDays((int) $this->option('train'));
        $n = (int) $this->option('per-round');
        $folds = max(1, (int) $this->option('folds'));
        $foldWindows = self::foldWindows($testFrom, $to, $folds);

        // One budget for the whole round: computed once here and threaded through every wait() call
        // (train+test dispatch, the plateau re-run, the holdout score) instead of each phase getting
        // its own fresh --round-minutes allowance, which let a plateau+holdout round run several
        // times longer than --round-minutes actually asked for.
        $deadline = time() + (int) $this->option('round-minutes') * 60;

        $cash = (float) $this->option('cash');
        $champion = $this->champion($pid, $settings);
        // --side=both sweeps the <strategy>.short.* set (like --side=short) but keeps longs on: every candidate
        // carries the coin's CURRENT long champion alongside the swept short keys so it scores combined
        // long+short PnL. Fetched once per round; [] for long/short where it is never used.
        $longChampion = $this->option('side') === 'both' ? $this->champion($pid, $settings, 'long') : [];
        $tfs = $this->timeframesWithData($pid, $trainFrom->getTimestamp());
        if ($tfs === []) {
            $this->warn("{$pid}: no bar history — skipped");

            return;
        }

        // --side=both promotes into the short set exactly like --side=short (longs are just along for
        // the ride during scoring) — the round record and the champion/CAS fingerprint both use this
        // mapped side so the arena/optimizer pages, which only know long|short, keep working.
        $roundSide = self::roundSide((string) $this->option('side'));

        // Compare-and-swap: fingerprint the coin/side's per_product champion settings BEFORE dispatching a
        // single job. If another optimizer variant promotes into the same slot while this round is out
        // waiting on the sweep farm, the fingerprint read back at promotion time will disagree and this
        // round refuses to publish against what is now a stale baseline (finding 13). Scoped to this run's
        // own strategy prefix and side subtree so an unrelated strategy's write, or the other side's own
        // promotion, never invalidates this round's baseline (finding: championVersion ignored $side).
        $championVersion = self::championVersion($pid, $roundSide['persisted'], $this->prefix());

        // candidates: champion first, then random draws (deduped) — or with --mutate=N the champion with 1..N keys re-drawn
        $space = $this->space();
        $tfKey = $this->prefix().'timeframe';
        $draw = fn (string $k, array $vals) => $k === $tfKey ? $tfs[array_rand($tfs)] : $vals[array_rand($vals)];
        $mutate = min((int) $this->option('mutate'), count($space));
        if ($mutate > 0 && in_array($this->option('side'), ['short', 'both'], true) && ! $this->hasShortSet($pid, $settings)) {
            $mutate = 0;   // nothing to climb from yet: the placeholder champion is the long set, so draw the whole space
        }
        $cands = [self::key($champion) => $champion];
        $tries = 0;
        while (count($cands) < $n + 1 && $tries++ < $n * 20) {
            if ($mutate > 0) {
                $c = $champion;
                foreach ((array) array_rand($space, random_int(1, $mutate)) as $k) {
                    $c[$k] = $draw($k, $space[$k]);
                }
            } else {
                $c = [];
                foreach ($space as $k => $vals) {
                    $c[$k] = $draw($k, $vals);
                }
            }
            $c = self::normalise($c, $space);
            $cands[self::key($c)] = $c;
        }
        $cands = array_values($cands);
        $this->info(sprintf('%s: round of %d candidates (train %s→%s, test %s→%s)', $pid, count($cands), $trainFrom->toDateString(), $testFrom->toDateString(), $testFrom->toDateString(), $to->toDateString()));

        // dispatch train + test for each; the batch id pairs a candidate's train and test rows for the dashboard
        $cacheHours = (int) $this->option('cache-hours');
        $tag = $this->option('tag') !== null && $this->option('tag') !== '' ? (string) $this->option('tag') : null;
        $ids = [];
        $cacheHits = 0;
        $batch = (string) Str::uuid();
        $jobs = [['train', $trainFrom, $testFrom, null]];
        foreach ($foldWindows as $fi => [$f, $t]) {
            $fold = $fi + 1;
            $jobs[] = [$fold === 1 ? 'test' : "test{$fold}", $f, $t, $fold];
        }
        foreach ($cands as $i => $c) {
            $reused = false;
            foreach ($jobs as [$win, $f, $t, $fold]) {
                $params = self::candidateParams($pid, $c, $win, $i, (string) $this->option('side'), $batch, $tag, $strategyKey, $fold, $longChampion);
                // heavy 23-day train jobs → the fast boxes only; light test-fold jobs → the queue the Pis also drain
                $bt = self::reuseOrQueue([
                    'strategy' => $strategyKey, 'products' => [$pid], 'from' => $f, 'to' => $t,
                    'starting_cash' => $cash, 'params' => $params,
                    'cache_key' => Backtest::cacheKeyFor($strategyKey, [$pid], $f, $t, $cash, self::cacheIdentity($strategyKey, [$pid], $params, $t)),
                    'queue' => $win === 'train' ? 'backtests' : (string) $this->option('light-queue'),
                ], $cacheHours);
                if (($bt->params['_opt']['cached_from'] ?? null) !== null) {
                    $reused = true;
                }
                $ids[$i][$win] = $bt->id;
            }
            if ($reused) {
                $cacheHits++;
            }
        }
        $all = Arr::flatten($ids);
        $this->wait($all, $deadline);

        // score — only status/stats feed the closures below, so the farm's heavy equity_curve/trades
        // payloads never cross the wire for a round that can hold hundreds of rows.
        $rank = (string) $this->option('rank');
        $rows = Backtest::whereIn('id', $all)->get(['id', 'status', 'stats'])->keyBy('id');
        $score = fn (?Backtest $b, string $k) => $b && $b->status === 'done' ? (float) ($b->stats[$k] ?? 0) : null;
        $stat = fn (?Backtest $b, string $k) => $b && $b->status === 'done' ? ($b->stats[$k] ?? null) : null;
        $table = [];
        foreach ($cands as $i => $c) {
            $tr = $rows[$ids[$i]['train']] ?? null;
            $te = $rows[$ids[$i]['test']] ?? null;   // fold 1 — still the "primary" window for pf/trades display
            $foldReturns = [];
            $foldCalmars = [];
            foreach (range(1, $folds) as $fold) {
                $fb = $rows[$ids[$i][$fold === 1 ? 'test' : "test{$fold}"]] ?? null;
                $r = $score($fb, 'total_return_pct');
                if ($r !== null) {
                    $foldReturns[] = $r;
                }
                $cal = $stat($fb, 'calmar');
                if ($cal !== null) {
                    $foldCalmars[] = $cal;
                }
            }
            $table[$i] = [
                'params' => $c,
                'train' => $score($tr, 'total_return_pct'), 'train_pf' => $score($tr, 'profit_factor'), 'train_trades' => (int) $score($tr, 'trades'),
                'train_calmar' => $stat($tr, 'calmar'), 'train_sharpe' => $stat($tr, 'sharpe'), 'train_skew' => $stat($tr, 'skew'), 'train_kurt' => $stat($tr, 'kurt'), 'train_obs' => $stat($tr, 'obs'),
                'test' => $foldReturns !== [] ? array_sum($foldReturns) / count($foldReturns) : null,
                'test_pf' => $score($te, 'profit_factor'), 'test_trades' => (int) $score($te, 'trades'),
                'test_calmar' => $foldCalmars !== [] ? array_sum($foldCalmars) / count($foldCalmars) : null,
                // Every requested fold must be present, not just the ones that happened to survive: a
                // missing/errored fold used to be silently dropped from the mean/min the gate reads below
                // (finding 12). foldsValid() is the one function every promotion branch calls to check this.
                'test_min' => $foldReturns !== [] ? min($foldReturns) : null,
                'folds_complete' => count($foldReturns),
                'folds_requested' => $folds,
            ];
        }
        $champ = $table[0];
        if ($champ['train'] === null) {
            // A champion that cannot be scored on this window (e.g. a sub-minute timeframe with no bars in a 23-day
            // train) must never be replaced: every candidate would "beat" it. Skip the round instead.
            OptimizerRound::create([
                'product_id' => $pid, 'strategy' => $strategyKey,
                'train_from' => $trainFrom, 'train_to' => $testFrom, 'test_from' => $testFrom, 'test_to' => $to,
                'candidates' => count($cands), 'champion_params' => $champ['params'], 'promoted' => false, 'side' => $roundSide['persisted'],
                'cash' => $cash, 'tag' => $tag, 'cache_hits' => $cacheHits, 'note' => 'champion has no result on this window — round skipped'.$roundSide['noteSuffix'],
            ]);
            $this->warn("{$pid}: champion has no result on this window — round skipped");

            return;
        }
        $eligible = array_filter($table, fn ($r, $i) => $i !== 0 && $r['train'] !== null && $r['train_trades'] >= (int) $this->option('min-trades') && ($r['train_pf'] ?? 0) >= (float) $this->option('min-pf'), ARRAY_FILTER_USE_BOTH);
        uasort($eligible, function ($a, $b) use ($rank) {
            $am = self::metricOf($a, 'train', $rank);
            $bm = self::metricOf($b, 'train', $rank);

            return $am === $bm ? 0 : ($am === null ? 1 : ($bm === null ? -1 : $bm <=> $am));
        });
        $best = $eligible !== [] ? reset($eligible) : null;

        // The one fold-validation gate every promotion branch goes through — foldsValid() is called from
        // inside shouldPromote() itself now, including on the first-ever short/mixed-set branch below,
        // which used to hand-roll its own "positive mean" check and never call shouldPromote() at all,
        // so a completed negative fold could hide inside that mean (finding 12).
        $foldsOk = $best !== null && self::foldsValid($best, $folds);

        $target = (float) $this->option('target');
        if (in_array($this->option('side'), ['short', 'both'], true) && ! $this->hasShortSet($pid, $settings)) {
            // No short set exists yet: the "champion" is the long parameters run shorts-only, a placeholder, not a bar.
            // The first real short/mixed set only has to be positive on both windows — routed through shouldPromote()
            // itself (with a nulled-out champion and no --target) so it gets the exact same fold validation as every
            // other promotion branch instead of a hand-rolled duplicate of it.
            $champ['train'] = null;
            $champ['test'] = null;
            $promote = $best !== null && self::shouldPromote($champ, $best, 0.0, $rank, $folds);
        } else {
            $promote = $best !== null && self::shouldPromote($champ, $best, $target, $rank, $folds);
        }
        $note = match (true) {
            $best === null => 'no candidate met the trade/PF floor',
            ! $foldsOk => sprintf('best-on-train has %d/%d test fold(s) complete — kept champion', $best['folds_complete'] ?? 0, $folds),
            $promote && self::promotedViaTarget($champ, $best, $target, $rank, $folds) => "promoted via --target: reaches the {$target}% train target with a positive unseen test window (champion below target)",
            $promote => 'promoted: beats champion on train AND on the unseen test window'.($rank === 'calmar' ? ' (ranked on Calmar, not raw return)' : ''),
            self::wouldDropBelowTarget($champ, $best, $target) => "kept champion: it holds the {$target}% train target and the challenger does not",
            $best['test'] === null => 'best-on-train had no test result',
            $best['test'] <= 0 => 'best-on-train lost money on the test window — kept champion',
            default => 'best-on-train did not beat champion on the test window — kept champion',
        };

        // deflated Sharpe of the best-on-train candidate: trialVar = population variance of this round's
        // train Sharpe ratios; the trial COUNT is cumulative across every round this (strategy, coin,
        // side, tag) lineage has ever run, so the correction accounts for the whole search history, not
        // just what one round tried (a coin re-swept for months looks like one giant multiple-comparisons
        // problem to the deflated Sharpe, not a fresh 48-candidate sample every 40 minutes).
        $minDsr = (float) $this->option('min-dsr');
        $trainRows = array_filter($table, fn ($r) => $r['train'] !== null);
        $trials = self::cumulativeTrials($pid, $strategyKey, $roundSide['persisted'], $tag, $space, count($trainRows));
        $sharpes = array_values(array_filter(array_map(fn ($r) => $r['train_sharpe'], $trainRows), fn ($v) => $v !== null));
        $trialVar = 0.0;
        if ($sharpes !== []) {
            $mean = array_sum($sharpes) / count($sharpes);
            $trialVar = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $sharpes)) / count($sharpes);
        }
        $bestDsr = $best !== null && $best['train_sharpe'] !== null && $best['train_obs'] !== null
            ? Sharpe::deflated((float) $best['train_sharpe'], $trials, $trialVar, (int) $best['train_obs'], (float) ($best['train_skew'] ?? 0.0), (float) ($best['train_kurt'] ?? 3.0))
            : null;
        if ($promote && ! self::passesDsr($bestDsr, $minDsr)) {
            $promote = false;
            $note = $bestDsr === null
                ? 'best-on-train has no DSR (too few equity points) — kept champion'
                : sprintf('best-on-train DSR %.2f < %.2f — kept champion', $bestDsr, $minDsr);
        }

        // plateau: does the best candidate still hold up with each search-space key nudged one step either
        // way, on the train window only? Only worth spending backtests on when we would otherwise promote.
        $plateauMin = (float) $this->option('plateau');
        $bestPlateau = null;
        if ($promote && $plateauMin > 0 && $best !== null) {
            $bestPlateau = $this->plateauScore($pid, $strategyKey, $best, $space, $rank, $batch, $trainFrom, $testFrom, $deadline, $longChampion);
            if ($bestPlateau === null || $bestPlateau < $plateauMin) {
                $promote = false;
                $note = sprintf('best-on-train sits on a peak: neighbours keep %.0f%% of it (< %.0f%%) — kept champion', ($bestPlateau ?? 0.0) * 100, $plateauMin * 100);
            }
        }

        // holdout: the winning candidate alone, scored once on the untouched window, never used to rank
        // or select anything. This is the LAST gate — only worth the extra backtest once every other
        // check (folds, walk-forward, DSR, plateau) has already agreed to promote.
        $holdoutReturn = null;
        if ($promote && $holdoutDays > 0) {
            $holdoutReturn = $this->scoreHoldout($pid, $strategyKey, $best, $to, $now, $cash, $batch, $tag, $deadline, $longChampion);
            if (self::holdoutBlocks($holdoutReturn)) {
                $promote = false;
                $note = $holdoutReturn === null
                    ? 'best-on-train had no holdout result — kept champion'
                    : sprintf('best-on-train lost %.2f%% on the untouched holdout window — kept champion', $holdoutReturn);
            }
        }

        $attrs = [
            'product_id' => $pid, 'strategy' => $strategyKey,
            'train_from' => $trainFrom, 'train_to' => $testFrom, 'test_from' => $testFrom, 'test_to' => $to,
            'candidates' => count($cands), 'side' => $roundSide['persisted'], 'folds' => $folds,
            'champion_params' => $champ['params'], 'champion_train' => $champ['train'], 'champion_test' => $champ['test'],
            'best_params' => $best['params'] ?? null, 'best_train' => $best['train'] ?? null, 'best_test' => $best['test'] ?? null,
            'best_calmar' => $best['train_calmar'] ?? null, 'best_dsr' => $bestDsr, 'best_plateau' => $bestPlateau, 'rank' => $rank,
            'best_test_min' => $best['test_min'] ?? null, 'best_folds_complete' => $best['folds_complete'] ?? null,
            'holdout_return' => $holdoutReturn, 'trials_cumulative' => $trials,
            'cash' => $cash, 'tag' => $tag, 'cache_hits' => $cacheHits, 'note' => $note.$roundSide['noteSuffix'],
        ];

        if ($promote && ! $this->option('dry')) {
            // Compare-and-swap publish: every promoted key plus the round's own promoted flag land in one
            // DB transaction that re-checks the champion fingerprint captured at round start. If another
            // optimizer variant promoted into this same coin/side while this round was out on the sweep
            // farm, the fingerprint disagrees and nothing here gets written — a reader can never observe a
            // parameter set that was only partially applied, and this round never clobbers a newer winner.
            $mixed = $this->option('side') === 'both';
            $short = $this->option('side') === 'short' || $mixed;
            $prefix = $this->prefix();
            $keyed = [];
            foreach ($best['params'] as $k => $v) {
                $keyed["per_product.{$pid}.".($short ? self::shortKey($k, $prefix) : $k)] = $v;
            }
            if ($short) {
                // a validated short set goes live next to the coin's long set
                $keyed["per_product.{$pid}.{$prefix}allow_shorts"] = true;
            }
            if ($mixed) {
                // --side=both: the whole point is trading both sides at once, so promotion also keeps longs on
                $keyed["per_product.{$pid}.{$prefix}allow_longs"] = true;
            }
            $round = self::publishOrSkip($attrs, $keyed, $championVersion, $pid, $roundSide['persisted'], $this->prefix());
            if ($round->promoted) {
                // cache invalidation + broadcast happen once, only after the transaction committed
                Settings::invalidate();
            } else {
                $this->warn("{$pid}: {$round->note}");
            }
        } else {
            $round = OptimizerRound::create($attrs + ['promoted' => false]);
        }
        Fire::event(new OptimizerRoundScored($round));

        $this->line(sprintf('  champion train %+.2f%% / test %+.2f%%   best train %+.2f%% / test %+.2f%% (calmar %.2f, dsr %s)   rank=%s cash=$%s   cache: %d of %d candidates reused → %s · min fold %+.2f',
            $champ['train'] ?? 0, $champ['test'] ?? 0, $best['train'] ?? 0, $best['test'] ?? 0, $best['train_calmar'] ?? 0.0, $bestDsr !== null ? number_format($bestDsr, 3) : '—', $rank, number_format($cash, 0), $cacheHits, count($cands), $round->note, $best['test_min'] ?? 0.0));

        if ($round->promoted) {
            Fire::event(new ChampionPromoted($pid, $roundSide['persisted'], $champ['params'], $best['params'], $best['train'], $best['test'], $champ['train'], $champ['test']));
            app(Reporter::class)->info('OPTIM', sprintf('%s → %s (train %+.2f%%, test %+.2f%% vs champion %+.2f%%)', $pid, json_encode($best['params']), $best['train'], $best['test'], $champ['test'] ?? 0));
        }
    }

    /**
     * The coin's current effective params (global ← per_product) projected onto the search space.
     * $sideOverride forces long-namespace reads regardless of --side — used to fetch the LONG
     * champion for --side=both, which sweeps the short namespace but keeps the long set riding along.
     */
    private function champion(string $pid, Settings $settings, ?string $sideOverride = null): array
    {
        $strategy = app(StrategyRegistry::class)->make((string) $this->option('strategy'));
        $ctx = (new DeskContext($settings->merged($strategy), 'paper'))->forProduct($pid);
        $out = [];
        $short = in_array($sideOverride ?? (string) $this->option('side'), ['short', 'both'], true);
        $prefix = $this->prefix();
        foreach ($this->space() as $k => $vals) {
            $v = $short ? $ctx->param(self::shortKey($k, $prefix), $ctx->param($k, $vals[0])) : $ctx->param($k, $vals[0]);
            $out[$k] = is_numeric($v) ? $v + 0 : $v;
        }

        return $out;
    }

    /** A named space from config/spaces/*.json or any .json path, validated against this run's strategy prefix. */
    private function space(): array
    {
        return SearchSpace::resolve((string) $this->option('space'), $this->prefix())->dims;
    }

    /** Does this coin already carry a validated short set (<prefix>short.* at the global or per_product layer)? */
    private function hasShortSet(string $pid, Settings $settings): bool
    {
        $strategy = app(StrategyRegistry::class)->make((string) $this->option('strategy'));
        $short = (new DeskContext($settings->merged($strategy), 'paper'))->forProduct($pid)->param($this->prefix().'short');

        return is_array($short) && $short !== [];
    }

    /** "<strategy>." — the search-space and param-namespace prefix for this run (--strategy, default mr). */
    private function prefix(): string
    {
        return ((string) $this->option('strategy')).'.';
    }

    /** <prefix><key> → <prefix>short.<key>: the namespace the strategy reads for short positions. */
    public static function shortKey(string $k, string $prefix = 'mr.'): string
    {
        return str_starts_with($k, $prefix) && ! str_starts_with($k, $prefix.'short.') ? $prefix.'short.'.substr($k, strlen($prefix)) : $k;
    }

    /**
     * Split [$testFrom, $to) into $n equal contiguous folds. Integer-second boundaries so the folds
     * cover the period exactly: fold i's end is fold i+1's start, and the first/last boundary are
     * $testFrom/$to themselves.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public static function foldWindows(Carbon $testFrom, Carbon $to, int $n): array
    {
        $n = max(1, $n);
        $span = $to->getTimestamp() - $testFrom->getTimestamp();
        $windows = [];
        for ($i = 0; $i < $n; $i++) {
            $windows[] = [
                $testFrom->copy()->addSeconds(intdiv($span * $i, $n)),
                $testFrom->copy()->addSeconds(intdiv($span * ($i + 1), $n)),
            ];
        }

        return $windows;
    }

    /** Only timeframes whose bars cover the train window (sub-minute needs the tape backfill). */
    private function timeframesWithData(string $pid, int $fromUnix): array
    {
        $ok = [];
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('tfs'))));
        $key = $this->prefix().'timeframe';
        // A space without a timeframe knob (the one-idea ablation spaces) keeps the coin's current timeframe.
        $tfs = $this->space()[$key] ?? [(string) (new \App\Desk\DeskContext(app(Settings::class)->merged(app(\App\Desk\StrategyRegistry::class)->make((string) $this->option('strategy'))), 'paper'))->forProduct($pid)->param($key, '1m')];
        foreach ($tfs as $tf) {
            if ($only !== [] && ! in_array($tf, $only, true)) {
                continue;
            }
            $base = Candle::DERIVED[$tf] ?? $tf;
            $min = Candle::where('product_id', $pid)->where('timeframe', $base)->min('candle_start');
            if ($min !== null && strtotime((string) $min) <= $fromUnix + 6 * 3600) {
                $ok[] = $tf;
            }
        }

        return array_values($ok);
    }

    /**
     * Re-run the best-on-train candidate's ±1-step neighbours on the train window, and return the median
     * neighbour metric as a fraction of the best's own metric (null when it can't be computed).
     */
    private function plateauScore(string $pid, string $strategyKey, array $best, array $space, string $rank, string $batch, $trainFrom, $testFrom, int $deadline, array $longChampion = []): ?float
    {
        $bestMetric = self::metricOf($best, 'train', $rank);
        if ($bestMetric === null || $bestMetric <= 0) {
            return null;
        }
        $neighbours = self::neighbours($best['params'], $space);
        if ($neighbours === []) {
            return null;
        }
        $ids = [];
        $cash = (float) $this->option('cash');
        $tag = $this->option('tag') !== null && $this->option('tag') !== '' ? (string) $this->option('tag') : null;
        foreach ($neighbours as $j => $nc) {
            // Neighbour rows get their own negative cand index — never 0, the round's own champion slot,
            // or they would be mistaken for it and escape retention stripping forever (finding 7).
            $params = self::candidateParams($pid, $nc, 'plateau', -($j + 1), (string) $this->option('side'), $batch, $tag, $strategyKey, null, $longChampion);
            $bt = self::reuseOrQueue([
                'strategy' => $strategyKey, 'products' => [$pid], 'from' => $trainFrom, 'to' => $testFrom,
                'starting_cash' => $cash, 'params' => $params,
                'cache_key' => Backtest::cacheKeyFor($strategyKey, [$pid], $trainFrom, $testFrom, $cash, self::cacheIdentity($strategyKey, [$pid], $params, $testFrom)),
                'queue' => 'backtests',
            ], (int) $this->option('cache-hours'));
            $ids[] = $bt->id;
        }
        $this->wait($ids, $deadline);
        $rows = Backtest::whereIn('id', $ids)->get(['id', 'status', 'stats'])->keyBy('id');
        $metrics = [];
        foreach ($ids as $id) {
            $b = $rows[$id] ?? null;
            $row = [
                'train' => $b && $b->status === 'done' ? (float) ($b->stats['total_return_pct'] ?? 0) : null,
                'train_calmar' => $b && $b->status === 'done' ? ($b->stats['calmar'] ?? null) : null,
            ];
            $metrics[] = self::metricOf($row, 'train', $rank) ?? 0.0;
        }
        sort($metrics);
        $mid = intdiv(count($metrics), 2);
        $median = count($metrics) % 2 ? $metrics[$mid] : ($metrics[$mid - 1] + $metrics[$mid]) / 2;

        return $median / $bestMetric;
    }

    /**
     * Scores ONLY the candidate about to be promoted on the untouched holdout window (--holdout), once
     * per round, after every other gate has already agreed to promote — the holdout is never used to
     * rank or select candidates, only to veto a promotion that would not have survived it.
     */
    private function scoreHoldout(string $pid, string $strategyKey, array $best, Carbon $from, Carbon $to, float $cash, string $batch, ?string $tag, int $deadline, array $longChampion = []): ?float
    {
        // A distinct sentinel cand index, well away from both the round's own champion slot (0) and any
        // real per-round candidate index, so this row is never mistaken for the champion and skipped by
        // retention forever (finding 7).
        $params = self::candidateParams($pid, $best['params'], 'holdout', -1000, (string) $this->option('side'), $batch, $tag, $strategyKey, null, $longChampion);
        $bt = self::reuseOrQueue([
            'strategy' => $strategyKey, 'products' => [$pid], 'from' => $from, 'to' => $to,
            'starting_cash' => $cash, 'params' => $params,
            'cache_key' => Backtest::cacheKeyFor($strategyKey, [$pid], $from, $to, $cash, self::cacheIdentity($strategyKey, [$pid], $params, $to)),
            'queue' => 'backtests',
        ], (int) $this->option('cache-hours'));
        $this->wait([$bt->id], $deadline);
        $row = Backtest::find($bt->id);

        return $row && $row->status === 'done' ? (float) ($row->stats['total_return_pct'] ?? 0) : null;
    }

    /** A null holdout result (the job never finished) is treated the same as a losing one: never promote
     *  on an unverified holdout. */
    public static function holdoutBlocks(?float $holdoutReturn): bool
    {
        return $holdoutReturn === null || $holdoutReturn < 0;
    }

    /**
     * --side=both promotes into the short set exactly like --side=short (see candidateParams()) — the
     * persisted OptimizerRound.side column stays 'short' so the arena/optimizer pages, which only know
     * long|short, keep working, and the mixed nature is recorded as a note suffix instead of a schema
     * change. long/short pass through unchanged with no suffix.
     *
     * @return array{persisted: string, noteSuffix: string}
     */
    public static function roundSide(string $optionSide): array
    {
        return $optionSide === 'both'
            ? ['persisted' => 'short', 'noteSuffix' => ' [mixed: longs stay enabled from the coin\'s current champion]']
            : ['persisted' => $optionSide, 'noteSuffix' => ''];
    }

    /**
     * Running DSR trial count for a (strategy, coin, side, tag, space) lineage: this round's own
     * qualifying candidates on top of every trial ever counted for the same lineage (the highest
     * trials_cumulative any prior round for this lineage recorded), so the deflated-Sharpe correction
     * accounts for the whole search history a coin has been through, not just what one round tried.
     * $space is folded in via the sorted key-set fingerprint of each historical round's own
     * champion_params — every round's champion is built from exactly its own run's search-space keys
     * (see champion()) — rather than a dedicated column, so a changed --space under the same tag
     * starts its own count instead of silently inheriting a differently-shaped space's trial history.
     */
    public static function cumulativeTrials(string $pid, string $strategyKey, string $side, ?string $tag, array $space, int $thisRoundTrials): int
    {
        $signature = self::spaceSignature($space);
        $prior = OptimizerRound::where('product_id', $pid)->where('strategy', $strategyKey)->where('side', $side)->where('tag', $tag)
            ->get(['champion_params', 'trials_cumulative'])
            ->filter(fn (OptimizerRound $r) => self::spaceSignature((array) $r->champion_params) === $signature)
            ->max('trials_cumulative');

        return (int) ($prior ?? 0) + $thisRoundTrials;
    }

    /** Sorted key-set fingerprint of a search space (or a stored champion_params row) — identifies
     *  which --space a round used without needing a dedicated column. */
    private static function spaceSignature(array $space): string
    {
        $keys = array_keys($space);
        sort($keys);

        return implode(',', $keys);
    }

    /**
     * True when shouldPromote() only agreed because of --target (the champion is below target and this
     * candidate reaches it), not because it actually beat the champion on both windows on the rank
     * metric — so the round note can say "promoted via --target" and an ablation run without --target
     * is distinguishable in the rounds table from one that would have promoted either way.
     */
    public static function promotedViaTarget(array $champ, array $best, float $target, string $rank, int $folds): bool
    {
        if (! self::shouldPromote($champ, $best, $target, $rank, $folds)) {
            return false;
        }
        $bestMetricTrain = self::metricOf($best, 'train', $rank);
        $bestMetricTest = self::metricOf($best, 'test', $rank);
        $champMetricTrain = self::metricOf($champ, 'train', $rank) ?? -INF;
        $champMetricTest = self::metricOf($champ, 'test', $rank) ?? -INF;
        $wonOnMerit = $bestMetricTrain !== null && $bestMetricTest !== null && $bestMetricTest > $champMetricTest && $bestMetricTrain > $champMetricTrain;

        return ! $wonOnMerit;
    }

    /**
     * Wait for the round's jobs, up to $deadline (a single Unix timestamp round() computes ONCE per
     * round and threads through every wait() call — the train+test dispatch, the plateau re-run, the
     * holdout score — so a plateau+holdout round is bounded by one --round-minutes budget instead of
     * each phase getting its own fresh allowance and running several times longer than asked for. Rows
     * still "running" after the job timeout (a worker died mid-run) are marked error so a round can
     * never hang; past the deadline whatever is missing is simply excluded.
     */
    /**
     * Symfony's ProgressBar redraws on a 1s timer, not just on progress: with our 5s poll and a
     * non-TTY stream (pm2 log) it disables overwrite and instead prepends a bare newline to every
     * redraw, which pm2 timestamps as a blank log line. Keep the live bar for interactive runs and
     * fall back to a throttled plain-text line when nothing is attached to a terminal.
     */
    private function wait(array $ids, int $deadline): void
    {
        $total = count($ids);
        $interactive = $this->output->isDecorated();
        $bar = $interactive ? $this->output->createProgressBar($total) : null;
        $loggedDone = null;
        $loggedAt = 0;
        while (true) {
            Backtest::whereIn('id', $ids)->where('status', 'running')->where('updated_at', '<', now()->subSeconds(3700))
                ->update(['status' => 'error', 'error' => 'stale: worker died mid-run']);
            $done = Backtest::whereIn('id', $ids)->whereIn('status', ['done', 'error'])->count();
            if ($interactive) {
                $bar->setProgress($done);
            } elseif ($done !== $loggedDone && time() - $loggedAt >= 60) {
                $this->line("waiting: {$done}/{$total} done");
                $loggedDone = $done;
                $loggedAt = time();
            }
            if ($done >= $total || time() > $deadline) {
                break;
            }
            sleep(5);
        }
        if ($interactive) {
            $bar->finish();
            $this->newLine();
        }
        $missing = $total - Backtest::whereIn('id', $ids)->whereIn('status', ['done', 'error'])->count();
        if ($missing > 0) {
            $this->warn("{$missing} job(s) not finished by the round deadline — excluded from scoring");
        }
    }

    /**
     * Backtest overrides for one candidate. forProduct() merges per_product.<pid>.* on top of the globals, so a
     * candidate written only at the global layer is shadowed by the coin's own champion settings (every candidate
     * then scores identically) — apply it at the per-coin layer too.
     */
    /**
     * $longChampion (only meaningful for $side==='both') is the coin's current LONG <strategy>.* champion
     * values, plain (non-short) key names — see DeskOptimize::champion() called with a 'long' override.
     * --side=both sweeps the same <strategy>.short.* namespace as --side=short, but leaves longs enabled and
     * carries that long champion alongside the swept short keys so every candidate backtest scores
     * combined long+short PnL. Bookkeeping (_opt.side, and per_product mirroring) still reports 'short'
     * for a 'both' candidate so the arena/optimizer side filter (which only knows long|short) keeps
     * working — the distinction lives in the round record's note, not per-candidate.
     */
    public static function candidateParams(string $pid, array $c, string $win, int $i, string $side = 'long', ?string $batch = null, ?string $tag = null, string $strategy = 'mr', ?int $fold = null, array $longChampion = []): array
    {
        $prefix = "{$strategy}.";
        if ($side === 'short' || $side === 'both') {
            // shorts sweep: candidate keys land in the <prefix>short.* namespace either way.
            $c = array_combine(array_map(fn ($k) => self::shortKey($k, $prefix), array_keys($c)), array_values($c));
            if ($side === 'both') {
                $c = $longChampion + $c;   // the long champion's plain <strategy>.* keys ride alongside the swept <strategy>.short.* keys
            }
            $fixed = ["{$prefix}allow_longs" => $side === 'both', "{$prefix}allow_shorts" => true] + self::fixedFor($strategy);
        } else {
            $fixed = self::fixedFor($strategy);
        }
        $bookkeepingSide = $side === 'both' ? 'short' : $side;
        $params = $c + $fixed + ['_opt' => ['coin' => $pid, 'window' => $win, 'cand' => $i, 'side' => $bookkeepingSide, 'batch' => $batch, 'tag' => $tag, 'fold' => $fold]];
        foreach ($c + $fixed as $k => $v) {
            $params["per_product.{$pid}.{$k}"] = $v;
        }

        return $params;
    }

    /** FIXED is namespace-agnostic (fees.*, paper.*, perps.*) — same overrides for every strategy. */
    private static function fixedFor(string $strategy): array
    {
        return self::FIXED;
    }

    /**
     * Create the Backtest row for one (candidate, window): reuse the newest done backtest with the
     * same cache_key and a fresh-enough created_at instead of paying for the same simulation twice,
     * otherwise queue it as usual. $attrs needs the row's create() attributes plus 'cache_key' and
     * 'queue'; a hit is flagged by params._opt.cached_from on the returned row.
     */
    public static function reuseOrQueue(array $attrs, int $cacheHours): Backtest
    {
        $queue = Arr::pull($attrs, 'queue', 'backtests');
        // A JSON plugin pins the exact version at dispatch time — a later edit to the plugin
        // must not change what an already-queued candidate runs. See BacktestVersionPin. The
        // cache_key above is computed by the caller against the pre-pin params (candidateParams'
        // json.plugin_key, not the resolved json.plugin_version_id), so a plugin version bump
        // between rounds does not itself bust the cache — only the row's own FK/params are pinned.
        [$versionId, $attrs['params']] = BacktestVersionPin::resolve((string) $attrs['strategy'], $attrs['params']);
        $attrs['strategy_plugin_version_id'] = $versionId;
        $cutoff = now()->subHours($cacheHours);
        // A cache-hit clone used to stamp a fresh created_at, so a chain of reused rows could look
        // "just computed" forever even though the underlying simulation is many cache-hour windows
        // stale (finding 18). The freshness test reads computed_at when a row carries one (i.e. it is
        // itself a clone) and falls back to created_at for a normally-computed row.
        $hit = Backtest::where('cache_key', $attrs['cache_key'])
            ->where('status', 'done')->whereNotNull('stats')
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($q2) => $q2->whereNull('computed_at')->where('created_at', '>=', $cutoff))
                    ->orWhere('computed_at', '>=', $cutoff);
            })
            ->latest('id')
            ->first(['id', 'stats', 'equity_curve', 'ending_equity', 'trades', 'computed_at', 'created_at']);
        if ($hit) {
            $params = $attrs['params'];
            $params['_opt']['cached_from'] = $hit->id;
            $attrs['params'] = $params;
            $attrs['status'] = 'done';
            $attrs['stats'] = $hit->stats;
            $attrs['equity_curve'] = $hit->equity_curve;
            $attrs['ending_equity'] = $hit->ending_equity;
            $attrs['trades'] = $hit->trades;
            // completed_at is "when THIS round obtained a result" (now); computed_at preserves the
            // original simulation's true moment through the whole reuse chain.
            $attrs['completed_at'] = now();
            $attrs['computed_at'] = $hit->computed_at ?? $hit->created_at;
            $bt = Backtest::create($attrs);
            Fire::event(new BacktestScored($bt));

            return $bt;
        }
        $attrs['status'] = 'queued';
        $bt = Backtest::create($attrs);
        RunBacktest::dispatch($bt->id)->onQueue($queue);

        return $bt;
    }

    private const PRUNE_BATCH = 2000;

    /** One strip pass never runs longer than this — a slow pass renews the lease instead of hogging it. */
    private const PRUNE_TIME_BUDGET_SECONDS = 20;

    /**
     * Optimizer rounds write 194 backtests each with an equity curve and trade list (~15 KB/row; measured 1.5 GB/hour
     * with two daemons on 18 coins). Keep the stats, drop the heavy columns after 2 hours, drop the rows after
     * optimizer.retain_hours (default 24; nothing reads a row past --cache-hours, so 12 is safe when disk is tight).
     */
    public static function prune(): void
    {
        // Every optimizer used to run this on every coin: nineteen concurrent full scans of a 380k-row table
        // (no created_at index at the time) held the DB at 100+ running threads. One process, every 10 minutes, in chunks.
        if (! Cache::add('optimizer:prune-lease', 1, 600)) {
            return;
        }
        $deleteBefore = now()->subHours((int) app(Settings::class)->get('optimizer.retain_hours', 24));
        do {
            $deleted = Backtest::where('created_at', '<', $deleteBefore)->whereNotNull('params->_opt')->limit(2000)->delete();
        } while ($deleted === 2000);

        self::stripPayloads(now()->subHours(2));
    }

    /**
     * Strip equity_curve/trades off backtests older than $cutoff. Walked as a keyset-paginated
     * (payload_stripped_at IS NULL, created_at, id) range — indexed — instead of re-evaluating
     * whereNotNull('equity_curve') over rows already stripped: at ~615k rows that JSON predicate was
     * most of a pass even though it matched nothing. $cutoff is captured once by the caller, so a row
     * inserted mid-pass (created_at ~= now) is never a candidate. Bounded by both batch count and a
     * wall-clock budget so one call can never run long enough to starve the prune lease; a pass that
     * does run long renews the lease itself. Never strips a round's own champion slot (_opt.cand 0)
     * or a row still named as another row's cache source (_opt.cached_from). Only ever considers rows
     * the optimizer itself created (opt_coin not null) — a user-run backtest carries no `_opt` at all,
     * so `params->_opt->cand = 0` reads NULL for it and the old champion guard failed OPEN, stripping
     * (and eventually deleting) backtests nobody asked the optimizer to touch.
     */
    private static function stripPayloads(Carbon $cutoff): void
    {
        $deadline = microtime(true) + self::PRUNE_TIME_BUDGET_SECONDS;
        $lastId = 0;
        do {
            Cache::put('optimizer:prune-lease', 1, 600);

            $batch = Backtest::where('payload_stripped_at', null)
                ->where('created_at', '<', $cutoff)
                ->where('id', '>', $lastId)
                ->whereNotNull('opt_coin')
                ->orderBy('id')
                ->limit(self::PRUNE_BATCH)
                ->pluck('id');
            if ($batch->isEmpty()) {
                return;
            }
            $lastId = $batch->max();

            $champions = Backtest::whereIn('id', $batch)->where('opt_cand', 0)->pluck('id');
            $referenced = Backtest::whereNotNull('opt_cached_from')->whereIn('opt_cached_from', $batch)->pluck('opt_cached_from');
            $strip = $batch->diff($champions)->diff($referenced);
            if ($strip->isNotEmpty()) {
                Backtest::whereIn('id', $strip)->update(['equity_curve' => null, 'trades' => null, 'payload_stripped_at' => now()]);
            }
        } while ($batch->count() === self::PRUNE_BATCH && microtime(true) < $deadline);
    }

    /**
     * All N requested test-fold windows must be terminal-successful with a valid score before a
     * candidate is promotion-eligible. A missing, errored, or timed-out fold must never be silently
     * dropped from the mean/min the gate reads — folds_complete comes from the round's own per-fold
     * results (see round()), not from how many happened to survive. folds >= 2 also requires every
     * fold's own return to be positive, not just the mean across whichever folds are present. This is
     * the one function every promotion branch calls, including the first-ever short-set branch in
     * round(), which used to check only a positive mean and bypass shouldPromote() entirely. The
     * worst-fold positivity check used to be skipped entirely at foldsRequested < 2, which made the
     * function vacuous (folds_complete alone) for the default --folds=1 — a single fold must itself be
     * positive to be "valid", not merely present; at folds=1, test_min is always exactly the one fold's
     * own return (see round()), so this never adds a second check beyond what a real single fold means.
     */
    public static function foldsValid(?array $row, int $foldsRequested): bool
    {
        if ($row === null || $foldsRequested < 1 || (int) ($row['folds_complete'] ?? 0) < $foldsRequested) {
            return false;
        }

        return (float) ($row['test_min'] ?? -INF) > 0;
    }

    /**
     * Fingerprint of a coin/side's promotable settings under ONE strategy: key, value and updated_at
     * for every matching per_product.<pid>.<prefix>* row, hashed. Scoped to $strategyPrefix (e.g.
     * "mr.") and, within it, to $side's own subtree — long reads everything except <prefix>short.*,
     * short reads only <prefix>short.* — so a short-side promotion or Settings-UI save never
     * invalidates an in-flight long round's CAS (or vice versa), and another strategy's settings
     * (e.g. custom.*) never invalidate this one's version either. `_`/`%` are escaped in the LIKE pattern
     * since $pid/$strategyPrefix are not guaranteed free of them. Captured at round start and
     * re-checked at promotion time (see publishOrSkip()) so a round that raced against a concurrent
     * promotion into the same slot can tell its baseline went stale instead of overwriting a newer
     * winner (finding 13).
     */
    public static function championVersion(string $pid, string $side, string $strategyPrefix = 'mr.'): string
    {
        $base = self::likeEscape("per_product.{$pid}.{$strategyPrefix}");
        $shortPattern = $base.'short.%';
        $query = $side === 'short'
            ? Setting::whereRaw('`key` LIKE ? ESCAPE ?', [$shortPattern, '\\'])
            : Setting::whereRaw('`key` LIKE ? ESCAPE ?', [$base.'%', '\\'])->whereRaw('`key` NOT LIKE ? ESCAPE ?', [$shortPattern, '\\']);
        $rows = $query->orderBy('key')->get(['key', 'value', 'updated_at']);

        return hash('sha256', $rows->map(fn (Setting $r) => $r->key.'='.json_encode($r->value).'@'.optional($r->updated_at)->toIso8601String())->implode('|'));
    }

    /** Escapes \, % and _ for a LIKE pattern matched with ESCAPE '\\'. */
    private static function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * Compare-and-swap publish: re-check the champion fingerprint captured at round start and, if it
     * still matches, write every promoted key AND mark the round promoted in one DB transaction — a
     * reader can never observe a parameter set that was only partially applied. If the fingerprint
     * moved (another optimizer variant promoted into this coin/side while this round waited on the
     * sweep farm), nothing in $keyed is written and the round is recorded not-promoted instead.
     * Cache invalidation and the ChampionPromoted broadcast are the caller's job, AFTER this commits.
     */
    public static function publishOrSkip(array $attrs, array $keyed, string $expectedVersion, string $pid, string $side, string $strategyPrefix = 'mr.'): OptimizerRound
    {
        return DB::transaction(function () use ($attrs, $keyed, $expectedVersion, $pid, $side, $strategyPrefix) {
            // Block a concurrent publisher on this coin's own settings rows rather than race it.
            DB::table('settings')->where('key', 'like', "per_product.{$pid}.%")->lockForUpdate()->get();
            if (self::championVersion($pid, $side, $strategyPrefix) !== $expectedVersion) {
                return OptimizerRound::create(array_merge($attrs, ['promoted' => false, 'note' => 'champion changed during round — re-score']));
            }
            foreach ($keyed as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }

            return OptimizerRound::create(array_merge($attrs, ['promoted' => true]));
        });
    }

    /**
     * Cache identity companion to Backtest::cacheKeyFor(): freezes the FULLY resolved parameter tree
     * (settings <- config <- strategy defaults, exactly what Backtester::paramsFor() merges) plus an
     * engine/data revision fingerprint, so one cache key can never describe two effectively different
     * runs. A settings change, a promotion, a code deploy, or a candle repair now all miss the cache
     * instead of silently reusing a result computed under different conditions (finding 18).
     *
     * $to bounds the candle-revision fingerprint to candles that existed within the window being
     * scored (candle_start < $to): without that bound, max(updated_at) over the whole table/timeframe
     * moves every time the tape's newest (still-open) candle gets its next tick — every few seconds —
     * so a completed window's cache identity, and therefore its cache key, never settled.
     */
    public static function cacheIdentity(string $strategyKey, array $products, array $overrides, $to): array
    {
        // _opt (candidate id, batch UUID, tag) and _sim are per-run bookkeeping, not identity — strip
        // them before resolving so they never reach the per_product mirror paramsFor() performs below.
        unset($overrides['_opt'], $overrides['_sim']);
        $strategy = app(StrategyRegistry::class)->make($strategyKey);
        $resolved = app(Backtester::class)->paramsFor($strategy, $products, $overrides);

        $strategyFile = (new \ReflectionClass($strategy))->getFileName();
        $engineFile = (new \ReflectionClass(Backtester::class))->getFileName();
        $engineVersion = ($strategyFile ? md5_file($strategyFile) : 'unknown').':'.($engineFile ? md5_file($engineFile) : 'unknown');

        $pid = $products[0] ?? null;
        $tf = (string) ($resolved['per_product'][$pid][$strategyKey]['timeframe'] ?? $resolved[$strategyKey]['timeframe'] ?? '1H');
        $bases = array_unique([Candle::DERIVED[$tf] ?? $tf, '1H']);
        $candleRevision = $pid !== null
            ? (string) (Candle::whereIn('product_id', $products)->whereIn('timeframe', $bases)->where('candle_start', '<', $to)->max('updated_at') ?? 'none')
            : 'none';

        $resolved['_cache'] = ['engine' => $engineVersion, 'candles' => $candleRevision];

        return $resolved;
    }

    /**
     * Walk-forward promotion rule. Default: the best-on-train candidate must also beat the champion on the unseen
     * test window (both compared on the rank metric — return or Calmar) and be positive there in raw return. With
     * a --target, a candidate that reaches the (raw) target on train with a positive test window is promoted even
     * if the champion's test week was better — but only while the champion itself is below the target. With
     * $folds >= 2 ('test' is the mean across folds), every fold must also be individually positive (test_min > 0).
     */
    public static function shouldPromote(array $champ, array $best, float $target = 0.0, string $rank = 'return', int $folds = 1): bool
    {
        if ($best['test'] === null || $best['train'] === null || $best['test'] <= 0) {
            return false;
        }
        if (! self::foldsValid($best, $folds)) {
            return false;
        }
        if (self::wouldDropBelowTarget($champ, $best, $target)) {
            return false;
        }
        $bestMetricTrain = self::metricOf($best, 'train', $rank);
        $bestMetricTest = self::metricOf($best, 'test', $rank);
        $champMetricTrain = self::metricOf($champ, 'train', $rank) ?? -INF;
        $champMetricTest = self::metricOf($champ, 'test', $rank) ?? -INF;
        if ($bestMetricTrain !== null && $bestMetricTest !== null && $bestMetricTest > $champMetricTest && $bestMetricTrain > $champMetricTrain) {
            return true;
        }
        $champTrain = (float) ($champ['train'] ?? -INF);

        return $target > 0 && (float) $best['train'] >= $target && $champTrain < $target;
    }

    /**
     * A champion that holds the train target with a positive test window is not replaced by a challenger under the
     * target, whatever the rank metric says: Calmar ranking took ADA short from 11.0 % to 6.5 % train in two
     * promotions on 2026-09-05 while the goal was 7.5 % on every coin.
     */
    public static function wouldDropBelowTarget(array $champ, array $best, float $target): bool
    {
        return $target > 0
            && (float) ($champ['train'] ?? -INF) >= $target
            && (float) ($champ['test'] ?? -INF) > 0
            && (float) ($best['train'] ?? -INF) < $target;
    }

    /** A table row's value for the metric candidates are ranked and compared on: raw window return, or Calmar. */
    private static function metricOf(array $row, string $win, string $rank): ?float
    {
        return $rank === 'calmar' ? ($row["{$win}_calmar"] ?? null) : ($row[$win] ?? null);
    }

    /** The deflated-Sharpe gate: min <= 0 is report-only (always passes); otherwise a null DSR also fails it. */
    public static function passesDsr(?float $dsr, float $min): bool
    {
        return $min <= 0 || ($dsr !== null && $dsr >= $min);
    }

    /**
     * ±1-step neighbours of $best in $space: for every numeric key with ≥2 values, the value one index either
     * side of $best's own (categorical, boolean and string keys are skipped; an edge value gives one neighbour).
     */
    public static function neighbours(array $best, array $space): array
    {
        $out = [];
        foreach ($space as $k => $vals) {
            if (count($vals) < 2 || array_filter($vals, 'is_numeric') !== $vals) {
                continue;
            }
            $idx = array_search($best[$k] ?? null, $vals);
            if ($idx === false) {
                continue;
            }
            foreach ([$idx - 1, $idx + 1] as $j) {
                if ($j >= 0 && $j < count($vals)) {
                    $c = $best;
                    $c[$k] = $vals[$j];
                    $c = self::normalise($c, $space);
                    $out[self::key($c)] = $c;
                }
            }
        }

        return array_values($out);
    }

    /** Collapse inert dependent knobs (see DEPENDENT) so identical strategies never cost two backtests. */
    public static function normalise(array $c, array $space): array
    {
        foreach (self::DEPENDENT as $knob => $switch) {
            if (isset($space[$knob]) && array_key_exists($knob, $c) && (float) ($c[$switch] ?? 0) == 0.0) {
                $c[$knob] = $space[$knob][0];
            }
        }

        return $c;
    }

    private static function key(array $c): string
    {
        ksort($c);

        return json_encode($c);
    }
}
