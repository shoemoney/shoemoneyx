<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\ProductStats;
use App\Desk\Execution\Lot;
use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\Perps;
use App\Desk\Strategies\BacktestVersionPin;
use App\Events\BacktestScored;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use App\Support\DeadlockRetry;
use App\Support\Fire;
use App\Support\Returns;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Replays the desk hour by hour over stored 1H candles. Entries fill at the
 * next bar's open (+ slippage + taker fee); RISK runs every bar; exits fill
 * at the bar close. Buys/sells on the tape are approximated from bar direction.
 */
class Backtester
{
    /** Zero measured drawdown isn't "infinite Calmar" -- treat it as this many percent so a lone bucket with no losers can't buy a 999.0 sentinel that wins every ranking. */
    private const CALMAR_DD_FLOOR_PCT = 0.5;

    /** Cap the ratio's magnitude so one thinly-traded, drawdown-free bucket can't dominate a comparison against buckets with real risk. */
    private const CALMAR_CAP = 50.0;

    public function __construct(
        private StrategyRegistry $strategies,
        private Settings $settings,
        private CandleStore $candles,
        private ProductStatsBuilder $builder,
    ) {}

    /**
     * @param  array<int, string>  $products
     * @param  array<string, mixed>  $overrides  parameter overrides (dotted or nested)
     */
    public function run(string $strategyKey, array $products, Carbon $from, Carbon $to, float $startingCash, array $overrides = [], ?callable $progress = null): Backtest
    {
        $strategy = $this->strategies->make($strategyKey);
        // A JSON plugin pins the exact version at creation, same as every other backtest entry
        // point (BacktestController::store, StrategyPluginController::backtest) — see BacktestVersionPin.
        [$versionId, $overrides] = BacktestVersionPin::resolve($strategyKey, $overrides);
        $bt = Backtest::create([
            'strategy' => $strategyKey,
            'strategy_plugin_version_id' => $versionId,
            'products' => $products,
            'from' => $from,
            'to' => $to,
            'starting_cash' => $startingCash,
            'params' => $overrides,
        ]);

        try {
            $result = $this->simulate($strategy, $products, $from, $to, $startingCash, $overrides, $progress);
            $this->persistDone($bt, $result);
        } catch (\Throwable $e) {
            $this->write($bt, ['status' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }

        return $bt->fresh();
    }

    /**
     * Every status write here races the rest of the worker pool on one table. DB::transaction's own
     * retry fires again immediately, so all its attempts re-collide inside the same lock window;
     * DeadlockRetry backs off between them instead. See App\Support\DeadlockRetry.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(Backtest $bt, array $values): void
    {
        (new DeadlockRetry)->run(fn () => $bt->update($values));
    }

    /**
     * The completion write races the whole pool; a deadlock (SQLSTATE 40001) here discards a
     * finished simulation, so it is the one write worth the full retry budget.
     *
     * @param  array<string, mixed>  $result
     */
    private function persistDone(Backtest $bt, array $result): void
    {
        $this->write($bt, ['status' => 'done', 'completed_at' => now(), 'under_one_contract' => (int) ($result['stats']['under_one_contract'] ?? 0)] + $result);
    }

    /** Run into an already-created (queued) backtest row. */
    public function runInto(Backtest $bt): Backtest
    {
        $strategy = $this->strategies->make($bt->strategy);
        $this->write($bt, ['status' => 'running']);
        try {
            $result = $this->simulate($strategy, $bt->products, $bt->from->copy(), $bt->to->copy(), (float) $bt->starting_cash, $bt->params ?? [], null);
            $this->persistDone($bt, $result);
        } catch (\Throwable $e) {
            $this->write($bt, ['status' => 'error', 'error' => $e->getMessage()]);
            Fire::event(new BacktestScored($bt->fresh()));
            throw $e;
        }
        $fresh = $bt->fresh();
        Fire::event(new BacktestScored($fresh));

        return $fresh;
    }

    /**
     * Merge settings with overrides for a run, then make explicit overrides win over any
     * per_product.<pid>.* champion for every product in the run — otherwise a global-layer
     * override (--set, a sweep grid cell, an optimizer candidate) is silently shadowed by
     * forProduct()'s per-coin layer.
     *
     * @param  array<int, string>  $products
     * @param  array<string, mixed>  $overrides  parameter overrides (dotted or nested)
     */
    public function paramsFor(Strategy $strategy, array $products, array $overrides): array
    {
        $params = array_replace_recursive($this->settings->merged($strategy), self::undot($overrides));
        foreach ($products as $pid) {
            foreach ($overrides as $key => $value) {
                if (str_starts_with((string) $key, 'per_product.')) {
                    continue;
                }
                Arr::set($params, "per_product.{$pid}.{$key}", $value);
            }
        }

        return $params;
    }

    private function simulate(Strategy $strategy, array $products, Carbon $from, Carbon $to, float $cash, array $overrides, ?callable $progress): array
    {
        $params = $this->paramsFor($strategy, $products, $overrides);
        $taker = (float) ($params['fees']['taker_rate'] ?? 0.006);
        $maker = (float) ($params['fees']['maker_rate'] ?? 0.004);
        // Hourly funding on open notional; positive = longs pay shorts. 0 = off.
        $fundingRate = (float) ($params['fees']['funding_hourly_pct'] ?? 0.00125);
        // Perps-style fee floor: $X per contract of $Y notional (Coinbase US perps: $0.15 / nano contract). 0 = off.
        $perContract = (float) ($params['fees']['per_contract_usd'] ?? 0);
        $contractUsd = (float) ($params['fees']['contract_usd'] ?? 0);
        $feeOf = fn (float $notional, ?float $rate = null): float => max($notional * ($rate ?? $taker), $perContract > 0 && $contractUsd > 0 ? ceil($notional / $contractUsd) * $perContract : 0.0);
        // Case-insensitive "does any of these reason strings mention one of these causes" check, used
        // to bucket a VET reject / SIZE zero by cause for skipped_risk_cap and skipped_liquidity below.
        // Strategies name these in free-text (Verdict::failedCheck/why, SizeDecision::why) rather than a
        // fixed enum, so this matches on substrings rather than an exact check-name list.
        $mentionsAny = function (array $needles, ?string ...$haystacks): bool {
            foreach ($haystacks as $h) {
                if ($h === null) {
                    continue;
                }
                $h = strtolower($h);
                foreach ($needles as $needle) {
                    if (str_contains($h, $needle)) {
                        return true;
                    }
                }
            }

            return false;
        };
        // Whole-contract sizing (the venue's real lot size) for any product mapped in perps.map; everything else
        // (param off, or an unmapped coin) keeps the fractional arithmetic above via $feeOf's contract_usd proxy.
        $wholeContracts = (bool) ($params['perps']['whole_contracts'] ?? false);
        $lotFor = fn (string $pid): ?array => $wholeContracts ? Perps::spec($pid) : null;
        // Margin gate + maintenance liquidation for mapped products, mirroring paper's MarginBook off the same overnight rate.
        $marginOn = (bool) ($params['perps']['margin'] ?? false);
        $initialRate = MarginWindow::Overnight->marginRate();
        $maintenanceRate = (float) config('desk.perps.maintenance_margin_pct') / 100;
        $isMarginPid = fn (string $pid): bool => $marginOn && Perps::spec($pid) !== null;
        // Releases the collateral behind $costSold of a margin-funded position's cost basis (proportional
        // to how much of its remaining entry_usd that share represents), mirroring PaperExecutor::sell()'s
        // marginBack. A non-margin-funded position (or the whole spot path) just returns its cash basis --
        // there is no collateral to release, the full cost was paid up front.
        $releaseMargin = function (Position $p, float $costSold): float {
            if (! $p->isMarginFunded()) {
                return $costSold;
            }
            $basis = (float) $p->entry_usd;
            $fraction = $basis > 0 ? min(1.0, $costSold / $basis) : 1.0;
            $before = $p->collateral();
            $released = $before * $fraction;
            $m = $p->meta ?? [];
            $m['margin_usd'] = $before - $released;
            $p->meta = $m;

            return $released;
        };
        $slip = (float) ($params['paper']['slippage_bps'] ?? 15) / 10_000;
        $lockedPct = (float) ($params['bank']['locked_pct'] ?? 0.2);

        // Step timeframe: backtest.step, else the strategy's own timeframe, else 1H.
        $stepTf = (string) ($params['backtest']['step'] ?? ($params[$strategy->key()]['timeframe'] ?? '1H'));
        // Per-coin timeframes: step at the fastest one; slower coins read their own bars through the provider fallback.
        foreach ($products as $pid) {
            $ptf = $params['per_product'][$pid][$strategy->key()]['timeframe'] ?? null;
            if ($ptf && (Candle::DURATIONS[$ptf] ?? PHP_INT_MAX) < (Candle::DURATIONS[$stepTf] ?? 3600)) {
                $stepTf = (string) $ptf;
            }
        }
        $dur = Candle::DURATIONS[$stepTf] ?? 3600;
        $warmupBars = (int) ($params[$strategy->key()]['bars'] ?? 300) + 10;

        // Any extra timeframe an ind.* field or a rule's "tf" override names, beyond the step
        // and 1H bundles already loaded below — preloaded here so the loop never queries mid-run.
        $extraTimeframes = [];
        if (method_exists($strategy, 'definition')) {
            $def = $strategy->definition(new DeskContext($params, 'backtest'));
            if ($def !== null) {
                $extraTimeframes = self::timeframesUsedBy($def);
            }
        }

        // Load bars once: 1H for liquidity/volume stats, step bars for prices and the strategy's indicator.
        $bars = [];
        $stepBars = [];
        $extra = [];   // pid => tf => whole series, for timeframes that are neither the step tf nor 1H
        foreach ($products as $pid) {
            // Fetches end at the window end: strategies only ever ask for bars closed before the step time, and a range
            // reaching past `to` was stamped "open" and re-keyed on every new candle, so the test-window slice missed the
            // cache once a minute for every coin and timeframe (2026-09-05).
            // 600h of warmup, not 50h: every 1H request routes through this array (see $provider below, which never
            // falls through to the 600-bar loader for tf === '1H'), and the longest gate a strategy runs against it
            // is a 200-bar trend MA -- 50h left trendOk() short by 150+ closed bars at the very first step, failing
            // it closed and silently zeroing every short entry for trend-gated champions (2026-09-05).
            $bars[$pid] = $this->candles->bars($pid, '1H', $from->getTimestamp() - 600 * 3600, $to->getTimestamp());
            $stepBars[$pid] = $stepTf === '1H' ? $bars[$pid] : $this->candles->bars($pid, $stepTf, $from->getTimestamp() - $warmupBars * $dur, $to->getTimestamp());
            foreach ($extraTimeframes as $tf) {
                // ind.* fields write "1h"/"15m" per the schema; Candle::DURATIONS (and the
                // provider closure below) key candles by its own canonical case ("1H"), so
                // preload and lookup must agree on the same casing or the preload just misses.
                $tf = self::canonicalTf($tf);
                if ($tf === $stepTf || $tf === '1H') {
                    continue;
                }
                $tfDur = Candle::DURATIONS[$tf] ?? 3600;
                $extra[$pid][$tf] = $this->candles->bars($pid, $tf, $from->getTimestamp() - 600 * $tfDur, $to->getTimestamp());
            }
        }
        $listed = Product::whereIn('product_id', $products)->pluck('listed_at', 'product_id');

        // Strategies read history through the context so no queries happen inside the loop.
        $provider = function (string $pid, string $tf, int $fromUnix, int $toUnix) use (&$bars, &$stepBars, &$extra, $stepTf, $from, $to): array {
            $src = $tf === $stepTf ? ($stepBars[$pid] ?? []) : ($tf === '1H' ? ($bars[$pid] ?? []) : null);
            if ($src === null) {
                // Load once and slice in memory: querying per call was a full-history scan on every step. Bounded to the
                // window plus 600 bars of warmup (the longest gate is a 200-bar trend MA): the unbounded 0..now fetch was
                // the 40k-row query in the slow log and could never be cached across rounds.
                $tfDur = Candle::DURATIONS[$tf] ?? 3600;
                $src = $extra[$pid][$tf] ??= $this->candles->bars($pid, $tf, $from->getTimestamp() - 600 * $tfDur, $to->getTimestamp());
            }

            return self::slice($src, $fromUnix, $toUnix);
        };

        // Hour-level stats (volumes, 1H indicators) only change when a new 1H bar lands: build them once per
        // hour bucket per product instead of on every 1m step (this was >80% of the runtime at 1m).
        $hourStats = [];   // pid => [hourKey, ProductStats]
        $prof = getenv('BT_PROFILE') ? ['stats' => 0.0, 'risk' => 0.0, 'scan' => 0.0, 'vetsize' => 0.0] : null;
        $pt = 0.0;

        /** @var array<string, Position> $open */
        $open = [];
        $trades = [];
        $curve = [];
        $pending = [];   // fills queued for next bar open: [pid => [usd, decision]]
        $steps = 0;
        $realised = 0.0;
        $peakEq = $cash;
        $maxDd = 0.0;
        $rejections = [];
        $candidatesSeen = 0;
        $sizedZero = 0;
        $trims = 0;
        $underOneContract = 0;
        $subContractTrims = 0;
        $fractionalExits = 0;
        $marginRejected = 0;
        // Why a sized ticket never became a fill / a vetted candidate never got sized, broken out
        // by cause rather than lumped into sized_zero/margin_rejected/rejections: capital (cash or
        // collateral ran out, or the min-ticket floor clipped the ticket to zero -- always AFTER
        // vet/size already approved it), a strategy-level risk/exposure cap (VET or SIZE naming one
        // in its check/reason text), and the executable-liquidity gate.
        $skippedCapital = 0;
        $skippedRiskCap = 0;
        $skippedLiquidity = 0;
        $liquidations = 0;
        $bothTouchedBars = 0;
        $fundingUsd = 0.0;
        $makerFills = 0;
        $takerFills = 0;

        $startTs = $from->getTimestamp() - $from->getTimestamp() % $dur;
        $endTs = $to->getTimestamp();
        $stepsPerHour = max(1, (int) (3600 / $dur));
        $eq = $cash;
        // The starting curve point, before any execution: preserves the true starting cash for total
        // return / Calmar / Sharpe even when the first sampled equity below is several bars later.
        $startEq = $cash;
        $curve[] = [$startTs, round($startEq, 2)];

        /**
         * Books an add onto an already-open position: margin/cash gates, lot sizing, fee, then
         * quantity/cost/average/meta bookkeeping — the exact mechanics of a fresh entry's add branch
         * below, reused by both an ordinary SCAN-driven add and a RISK-driven tp_reentry re-buy so a
         * strategy's ladder rebases off the same kind of fill either way. Returns false (nothing
         * booked, the relevant counter already bumped) when capital or the whole-contract floor blocks it.
         */
        $bookAdd = function (Position $p, string $pid, float $usd, float $px) use (
            &$cash, &$eq, $taker, $perContract, $lotFor, $isMarginPid, $initialRate,
            &$marginRejected, &$skippedCapital, &$takerFills, &$underOneContract, $feeOf, &$open
        ): bool {
            $marginPid = $isMarginPid($pid);
            if ($marginPid) {
                $notionalNow = 0.0;
                foreach ($open as $op) {
                    $notionalNow += $op->quantity * (float) ($op->last_price ?? $op->entry_price);
                }
                if (($notionalNow + $usd) * $initialRate > $eq) {
                    $marginRejected++;
                    $skippedCapital++;

                    return false;
                }
            }
            $usd = $marginPid ? $usd : min($usd, $cash);
            if ($usd <= 0) {
                $skippedCapital++;

                return false;
            }
            if ($lotFor($pid) !== null) {
                $lot = Lot::forUsd($pid, $usd, $px, $taker, $perContract, true);
                if ($lot === null) {
                    $underOneContract++;

                    return false;
                }
                $qty = $lot->qty;
                $entryUsd = $lot->notional;
                $fee = $lot->feeUsd;
            } else {
                $fee = $feeOf($usd);
                $qty = $marginPid ? ($usd / $px) : (($usd - $fee) / $px);
                $entryUsd = $usd;
            }
            $margin = null;
            if ($marginPid) {
                $margin = $entryUsd * $initialRate;
                if ($margin + $fee > $cash + 1e-6) {
                    $marginRejected++;
                    $skippedCapital++;

                    return false;
                }
                $cash -= $margin + $fee;
            } elseif ($lotFor($pid) !== null) {
                $cash -= $entryUsd + $fee;
            } else {
                $cash -= $usd;
            }
            $entryFeeExcluded = ($lotFor($pid) !== null || $marginPid) ? $fee : 0.0;
            $takerFills++;
            $p->quantity += $qty;
            $p->entry_usd += $entryUsd;
            $p->fees_usd += $fee;
            $p->entry_price = $p->entry_usd / $p->quantity;
            $p->adds_count++;
            $m = $p->meta ?? [];
            $m['entry_fee_excluded'] = (float) ($m['entry_fee_excluded'] ?? 0) + $entryFeeExcluded;
            if ($margin !== null) {
                $m['margin_usd'] = (float) ($m['margin_usd'] ?? 0) + $margin;
            }
            $p->meta = $m;

            return true;
        };

        // Half-open [from, to): a bar starting exactly at $endTs has not closed inside the window.
        for ($ts = $startTs; $ts < $endTs; $ts += $dur) {
            $t = Carbon::createFromTimestamp($ts);
            $steps++;
            $now = \DateTimeImmutable::createFromMutable($t->toDateTime());

            // 1. execute pending entries at this bar's open
            foreach ($pending as $pid => $order) {
                $bar = $this->barAt($stepBars[$pid], $ts);
                if (! $bar) {
                    continue;
                }
                $short = ($order['side'] ?? 'long') === 'short';
                $px = $bar['open'] * (1 + ($short ? -$slip : $slip));

                if (isset($open[$pid])) {
                    // Same gates and mechanics as a fresh entry below (Lot sizing, margin, fees) — shared
                    // via $bookAdd so this SCAN-driven add and a RISK-driven tp_reentry re-buy book
                    // identically.
                    $bookAdd($open[$pid], $pid, (float) $order['usd'], $px);

                    continue;
                }
                $marginPid = $isMarginPid($pid);

                if ($marginPid) {
                    $notionalNow = 0.0;
                    foreach ($open as $op) {
                        $notionalNow += $op->quantity * (float) ($op->last_price ?? $op->entry_price);
                    }
                    if (($notionalNow + (float) $order['usd']) * $initialRate > $eq) {
                        $marginRejected++;
                        $skippedCapital++;

                        continue;
                    }
                }

                // Margin posts collateral against the full requested exposure -- it is never clipped to
                // cash the way a cash-notional (spot) ticket is; the gate above already vetted it against
                // equity. Everything else keeps the old cash-notional behaviour.
                $usd = $marginPid ? (float) $order['usd'] : min($order['usd'], $cash);
                if ($usd <= 0) {
                    // SIZE already approved a positive ticket -- free cash (or, for margin, collateral
                    // headroom above) ran out before this fill, or a spot ticket got clipped to nothing.
                    $skippedCapital++;

                    continue;
                }
                if ($lotFor($pid) !== null) {
                    $lot = Lot::forUsd($pid, $usd, $px, $taker, $perContract, true);
                    if ($lot === null) {
                        $underOneContract++;

                        continue;
                    }
                    $qty = $lot->qty;
                    $entryUsd = $lot->notional;
                    $fee = $lot->feeUsd;
                } else {
                    $fee = $feeOf($usd);
                    // Margin: the fee is paid separately from posted collateral, so it never comes out of
                    // qty. Cash-notional (spot): $usd is the whole ticket, fee comes out of qty.
                    $qty = $marginPid ? ($usd / $px) : (($usd - $fee) / $px);
                    $entryUsd = $usd;
                }
                $margin = null;
                if ($marginPid) {
                    $margin = $entryUsd * $initialRate;
                    if ($margin + $fee > $cash + 1e-6) {
                        // The gate above passed on equity, but this bar's earlier fills already spent the
                        // cash this one needs for collateral + fee -- reject rather than overdraw.
                        $marginRejected++;
                        $skippedCapital++;

                        continue;
                    }
                    $cash -= $margin + $fee;
                } elseif ($lotFor($pid) !== null) {
                    $cash -= $entryUsd + $fee;
                } else {
                    $cash -= $usd;
                }
                // Whole-contract and margin entries pay their fee separately from entryUsd (the basis);
                // the plain cash-notional path already folded it into $usd/entryUsd above.
                $entryFeeExcluded = ($lotFor($pid) !== null || $marginPid) ? $fee : 0.0;
                $takerFills++;
                // first_entry_price/ts/trough_price are set once here and never touched by an add:
                // entry-quality metrics (mae_pct/mfe_pct/fwd_N -- entry review 10) judge the position
                // against its ORIGINAL entry, the same way peak_price already does for MFE.
                $meta = ['why' => $order['why'], 'entry_fee_excluded' => $entryFeeExcluded, 'first_entry_price' => $px, 'first_entry_ts' => $ts, 'trough_price' => $px];
                if ($margin !== null) {
                    $meta['margin_usd'] = $margin;
                }
                $open[$pid] = new Position([
                    'mode' => 'backtest', 'strategy' => $strategy->key(), 'product_id' => $pid, 'status' => 'open', 'side' => $short ? 'short' : 'long',
                    'quantity' => $qty, 'entry_price' => $px, 'entry_usd' => $entryUsd, 'fees_usd' => $fee,
                    'peak_price' => $px, 'last_price' => $px, 'adds_count' => 0,
                    'opened_at' => Carbon::createFromTimestamp($ts), 'meta' => $meta,
                ]);
            }
            $pending = [];

            // 2. stats as of this bar close (1H row for volumes/liquidity, price from the step bar)
            if ($prof !== null) {
                $pt = microtime(true);
            }
            $stats = [];
            $hourKey = intdiv($ts, 3600);
            foreach ($products as $pid) {
                if (($hourStats[$pid][0] ?? -1) !== $hourKey) {
                    $age = $listed[$pid] ? Carbon::parse($listed[$pid])->diffInMinutes($t) / 60 : null;
                    // Sub-hour steps must never see the hour bar they are currently inside: that bar's
                    // close/high/low/volume are not known until it closes at (hourKey+1)*3600, so only
                    // bars CLOSED before this hour started are eligible. A 1H step *is* the hour (the
                    // fixed unit of simulated time: fills at its open, RISK/exits at its close) so it
                    // keeps seeing the bar it is itself standing on.
                    $closedBefore = $stepTf !== '1H' ? $hourKey * 3600 : $ts + 1;
                    // only the trailing window matters to the builder; slicing bounds the indicator cost
                    $hourStats[$pid] = [$hourKey, $this->builder->fromBars($pid, self::slice($bars[$pid], $ts - 400 * 3600, $closedBefore - 1), $ts, $age, true)];
                }
                $s = $hourStats[$pid][1];
                if ($stepTf !== '1H') {
                    $sb = $this->barAt($stepBars[$pid], $ts);
                    if (! $sb) {
                        continue;
                    }
                    $row = $s->jsonSerialize();
                    $row['price'] = $sb['close'];
                    $row['extra'] = ($row['extra'] ?? []) + ['bar_high' => $sb['high'], 'bar_low' => $sb['low']];
                    $s = ProductStats::fromArray($row);
                }
                if ($s->price > 0) {
                    $stats[$pid] = $s;
                }
            }

            if ($prof !== null) {
                $prof['stats'] += microtime(true) - $pt;
                $pt = microtime(true);
            }
            // 3. RISK on every open position (exits at bar close)
            $ctx = new DeskContext($params, 'backtest', false, array_values($open), $now, true, $provider);
            foreach ($open as $pid => $p) {
                if (! isset($stats[$pid])) {
                    continue;
                }
                $s = $stats[$pid];
                $p->markPrice($s->price);
                // Adverse extreme, mirroring markPrice()'s own favourable one (peak_price) -- together
                // they give a closed trade its mae_pct/mfe_pct (entry review 10) at tradeRow() time.
                $mTrough = $p->meta ?? [];
                $trough = (float) ($mTrough['trough_price'] ?? $p->entry_price);
                $mTrough['trough_price'] = $p->isShort() ? max($trough, $s->price) : min($trough, $s->price);
                $p->meta = $mTrough;
                if ($fundingRate != 0.0) {
                    $notionalNow = $p->quantity * $s->price;
                    $fundingStep = $notionalNow * $fundingRate / 100 * ($dur / 3600) * $p->dir();
                    $cash -= $fundingStep;
                    $fundingUsd += $fundingStep;
                    $m = $p->meta ?? [];
                    $m['funding_usd'] = (float) ($m['funding_usd'] ?? 0) + $fundingStep;
                    $p->meta = $m;
                }
                $d = $strategy->risk($p, $s, $ctx);
                // Conservative touch policy: a strategy that wants its stop checked against the same
                // bar's range as its target stashes the stop in meta['stop_price'] alongside a TRIM
                // decision. 'optimistic' (default) keeps today's behaviour -- whatever risk() returned
                // fires as-is. 'conservative' processes the stop first when the bar's range reaches both,
                // closing the whole remaining position there instead of the trim risk() asked for.
                $forceStopClose = false;
                $stopFillPrice = null;
                if ($d->shouldTrim() && $d->limitPrice !== null && isset($d->meta['stop_price'])) {
                    $stopPrice = (float) $d->meta['stop_price'];
                    $barHigh = (float) ($s->extra['bar_high'] ?? $s->price);
                    $barLow = (float) ($s->extra['bar_low'] ?? $s->price);
                    $targetTouched = $p->isShort() ? $d->limitPrice >= $barLow : $d->limitPrice <= $barHigh;
                    $stopTouched = $p->isShort() ? $stopPrice <= $barHigh : $stopPrice >= $barLow;
                    if ($targetTouched && $stopTouched) {
                        $bothTouchedBars++;
                        $touchPolicy = (string) $ctx->forProduct($pid)->param('mr.exit_touch_policy', 'optimistic');
                        if ($touchPolicy === 'conservative') {
                            $forceStopClose = true;
                            $stopFillPrice = $stopPrice;
                        }
                    }
                }
                if ($d->shouldAdd()) {
                    // RISK asked to re-buy (a take-profit re-entry): same fill mechanics as an ordinary SCAN-driven
                    // add, via the shared $bookAdd closure — slippage applied the same direction a fresh
                    // entry uses (buying a long / selling more of a short both pay the unfavourable side).
                    $px = $s->price * (1 + ($p->isShort() ? -$slip : $slip));
                    $bookAdd($p, $pid, (float) $d->dollars, $px);

                    continue;
                }
                if ($d->shouldTrim() && ! $forceStopClose) {
                    // resting limit at the rung: fills at the rung price when the bar trades through it
                    if ($p->isShort()) {
                        $filledAtRung = $d->limitPrice !== null && $d->limitPrice >= (float) ($s->extra['bar_low'] ?? $s->price);
                        $px = $filledAtRung ? $d->limitPrice : $s->price * (1 + $slip);
                    } else {
                        $filledAtRung = $d->limitPrice !== null && $d->limitPrice <= (float) ($s->extra['bar_high'] ?? $s->price);
                        $px = $filledAtRung ? $d->limitPrice : $s->price * (1 - $slip);
                    }
                    $rawQty = $p->quantity * $d->fraction;
                    $rungRate = $filledAtRung ? $maker : $taker;
                    if ($lotFor($pid) !== null) {
                        $lot = Lot::forQty($pid, $rawQty, $px, $rungRate, $perContract, true);
                        if ($lot->contracts === 0 && $d->fraction < 1) {
                            $subContractTrims++;   // a rung that asks for less than one contract cannot fill; the ladder rides to a rung that can

                            continue;
                        }
                        $qty = $lot->qty;
                        $gross = $lot->notional;
                        $fee = $lot->feeUsd;
                        $costSold = $p->entry_usd * ($qty / $p->quantity);
                    } else {
                        $qty = $rawQty;
                        $gross = $qty * $px;
                        $fee = $feeOf($gross, $rungRate);
                        $costSold = $p->entry_usd * $d->fraction;
                    }
                    $filledAtRung ? $makerFills++ : $takerFills++;
                    // long: receive proceeds; short: release collateral + (collateral - buyback cost).
                    // Margin-funded: only the collateral behind $costSold comes back, not $costSold itself
                    // (mirrors PaperExecutor::sell()'s marginBack) -- releaseMargin() reads $p->entry_usd
                    // before it is trimmed below, so it must run first.
                    $pnlPart = $p->dir() * ($gross - $costSold) - $fee;
                    $cash += $releaseMargin($p, $costSold) + $pnlPart;
                    $realised += $pnlPart;
                    $p->realised_usd = (float) ($p->realised_usd ?? 0) + $pnlPart;
                    $p->quantity -= $qty;
                    $p->entry_usd -= $costSold;
                    $p->trims_count = ($p->trims_count ?? 0) + 1;
                    $m = $p->meta ?? [];
                    $m['cost_trimmed'] = (float) ($m['cost_trimmed'] ?? 0) + $costSold;
                    $p->meta = $m;
                    $trims++;
                    if ($p->quantity <= 1e-12) {
                        $trades[] = $this->tradeRow($p, $pid, $px, $t, $ts, $d->ruleFired ?? 'tp') + $this->entryQuality($p, $stepBars[$pid] ?? [], $dur, $taker);
                        unset($open[$pid]);
                    }

                    continue;
                }
                if ($d->shouldClose() || $forceStopClose) {
                    $px = ($forceStopClose ? $stopFillPrice : $s->price) * (1 - $p->dir() * $slip);
                    if ($lotFor($pid) !== null) {
                        $lot = Lot::forQty($pid, $p->quantity, $px, $taker, $perContract, true);
                        if ($lot->contracts === 0 && $lot->qty === 0.0) {
                            // Sub-contract holding, full exit: nothing is stranded, so close it fractionally
                            // rather than booking a phantom 100% loss on a position that was never sold.
                            $lot = Lot::forQty($pid, $p->quantity, $px, $taker, $perContract, true, legacyFractional: true);
                            $fractionalExits++;
                        }
                        $gross = $lot->notional;
                        $fee = $lot->feeUsd;
                    } else {
                        $gross = $p->quantity * $px;
                        $fee = $feeOf($gross);
                    }
                    $takerFills++;
                    $pnlPart = $p->dir() * ($gross - $p->entry_usd) - $fee;
                    $cash += $releaseMargin($p, (float) $p->entry_usd) + $pnlPart;
                    $realised += $pnlPart;
                    $trades[] = $this->tradeRow($p, $pid, $px, $t, $ts, $forceStopClose ? 'stop_before_target' : $d->ruleFired, $fee) + $this->entryQuality($p, $stepBars[$pid] ?? [], $dur, $taker);
                    unset($open[$pid]);
                }
            }

            if ($prof !== null) {
                $prof['risk'] += microtime(true) - $pt;
                $pt = microtime(true);
            }
            // 4. SCAN -> VET -> SIZE, queue entries for next open
            // A margin-funded position's worth to the book is its posted collateral plus open PnL, not
            // its full leveraged notional -- equityValue() branches on that per position (finding 6's
            // model, shared via Position), so a margin backtest's bank never inflates equity by the
            // leveraged multiple the way summing raw market value would.
            $posValue = 0.0;
            $collateralTotal = 0.0;
            $unrealisedTotal = 0.0;
            $exposureTotal = 0.0;
            foreach ($open as $pid => $p) {
                $mark = (float) ($stats[$pid]->price ?? $p->last_price);
                $posValue += $p->equityValue($mark);
                if ($p->isMarginFunded()) {
                    $collateralTotal += $p->collateral();
                    $unrealisedTotal += $p->unrealisedPnl($mark);
                    $exposureTotal += $p->notional($mark);
                }
            }
            $bank = new Bank($cash, $posValue, $lockedPct, 0.0, count($open), 0.0, $collateralTotal, $unrealisedTotal, $exposureTotal);
            $ctx = new DeskContext($params, 'backtest', false, array_values($open), $now, true, $provider);
            $cands = $strategy->scan(array_values($stats), $ctx);
            if ($prof !== null) {
                $prof['scan'] += microtime(true) - $pt;
                $pt = microtime(true);
            }
            foreach ($cands as $i => $c) {
                $c->rank = $i + 1;
                $candidatesSeen++;
                $v = $strategy->vet($c, $bank, $ctx);
                if (! $v->passed()) {
                    $rejections[$v->failedCheck] = ($rejections[$v->failedCheck] ?? 0) + 1;
                    if ($mentionsAny(['risk cap', 'exposure cap', 'risk_cap', 'exposure_cap'], $v->failedCheck, $v->why)) {
                        $skippedRiskCap++;
                    } elseif ($mentionsAny(['liquidity'], $v->failedCheck, $v->why)) {
                        $skippedLiquidity++;
                    }

                    continue;
                }
                $size = $strategy->size($v, $bank, $ctx);
                if ($size->zero()) {
                    $sizedZero++;
                    if ($mentionsAny(['risk cap', 'exposure cap', 'risk_cap', 'exposure_cap'], $size->why)) {
                        $skippedRiskCap++;
                    } elseif ($mentionsAny(['liquidity'], $size->why)) {
                        $skippedLiquidity++;
                    }

                    continue;
                }
                $pending[$c->productId()] = ['usd' => $size->dollars, 'why' => $c->rankReason, 'side' => $c->side];
                // Reserve cash so later candidates in the same bar don't over-allocate. Margin: only the
                // collateral behind the ticket is spoken for, not its full notional -- reserving the whole
                // dollar amount here was finding 10's other half of the over-conservative divergence.
                $reserve = $isMarginPid($c->productId()) ? $size->dollars * $initialRate : $size->dollars;
                $bank = new Bank($bank->cash - $reserve, $bank->positionsValue + $reserve, $lockedPct, 0.0, $bank->openPositions + 1, 0.0, $bank->collateral, $bank->unrealisedPnl, $bank->exposure);
            }

            if ($prof !== null) {
                $prof['vetsize'] += microtime(true) - $pt;
            }
            $eq = $cash + $posValue;
            if ($marginOn && $open !== []) {
                $notionalNow = 0.0;
                foreach ($open as $pid => $p) {
                    $notionalNow += $p->quantity * (float) ($stats[$pid]->price ?? $p->last_price ?? $p->entry_price);
                }
                $maintenance = $notionalNow * $maintenanceRate;
                if ($maintenance > 0 && $eq <= $maintenance) {
                    foreach ($open as $pid => $p) {
                        $liqPx = (float) ($stats[$pid]->price ?? $p->last_price ?? $p->entry_price) * (1 - $p->dir() * $slip);
                        if ($lotFor($pid) !== null) {
                            $lot = Lot::forQty($pid, $p->quantity, $liqPx, $taker, $perContract, true);
                            if ($lot->contracts === 0 && $lot->qty === 0.0) {
                                $lot = Lot::forQty($pid, $p->quantity, $liqPx, $taker, $perContract, true, legacyFractional: true);
                                $fractionalExits++;
                            }
                            $gross = $lot->notional;
                            $fee = $lot->feeUsd;
                        } else {
                            $gross = $p->quantity * $liqPx;
                            $fee = $feeOf($gross);
                        }
                        $takerFills++;
                        $pnlPart = $p->dir() * ($gross - $p->entry_usd) - $fee;
                        $cash += $releaseMargin($p, (float) $p->entry_usd) + $pnlPart;
                        $realised += $pnlPart;
                        $trades[] = $this->tradeRow($p, $pid, $liqPx, $t, $ts, 'liquidation', $fee) + $this->entryQuality($p, $stepBars[$pid] ?? [], $dur, $taker);
                        unset($open[$pid]);
                    }
                    $liquidations++;
                    $eq = $cash;
                }
            }
            $peakEq = max($peakEq, $eq);
            $maxDd = max($maxDd, $peakEq > 0 ? (1 - $eq / $peakEq) * 100 : 0);
            if ($steps % ($stepsPerHour * 4) === 0 || $ts + $dur > $endTs) {
                $curve[] = [$ts, round($eq, 2)];
            }
            if ($progress && $steps % ($stepsPerHour * 24) === 0) {
                $progress($t, $eq, count($trades));
            }
        }

        if ($prof !== null) {
            fwrite(STDERR, 'BT_PROFILE '.json_encode(array_map(fn ($v) => round($v, 2), $prof))." steps={$steps}\n");
        }
        // liquidate anything still open at the end, at last close
        $endEq = $cash;
        foreach ($open as $pid => $p) {
            $px = $p->last_price * (1 - $p->dir() * $slip);
            if ($lotFor($pid) !== null) {
                $lot = Lot::forQty($pid, $p->quantity, $px, $taker, $perContract, true);
                if ($lot->contracts === 0 && $lot->qty === 0.0) {
                    $lot = Lot::forQty($pid, $p->quantity, $px, $taker, $perContract, true, legacyFractional: true);
                    $fractionalExits++;
                }
                $gross = $lot->notional;
                $fee = $lot->feeUsd;
            } else {
                $gross = $p->quantity * $px;
                $fee = $feeOf($gross);
            }
            $takerFills++;
            $pnlPart = $p->dir() * ($gross - $p->entry_usd) - $fee;
            $endEq += $releaseMargin($p, (float) $p->entry_usd) + $pnlPart;
            $realised += $pnlPart;
            $trades[] = $this->tradeRow($p, $pid, $px, $to, $to->getTimestamp(), 'end_of_test', $fee) + $this->entryQuality($p, $stepBars[$pid] ?? [], $dur, $taker);
        }

        // Terminal equity point, after liquidation costs -- otherwise the last sampled curve point (an
        // intra-loop mark, still holding whatever was open) never reflects what closing it out actually
        // cost, and a short run whose only curve point was that mark could report a 0% return no matter
        // what starting cash actually became by the end (finding 9).
        $peakEq = max($peakEq, $endEq);
        $maxDd = max($maxDd, $peakEq > 0 ? (1 - $endEq / $peakEq) * 100 : 0);
        $curve[] = [$endTs, round($endEq, 2)];

        return [
            'ending_equity' => round($endEq, 2),
            'equity_curve' => $curve,
            'trades' => $trades,
            'stats' => self::stats($trades, $from->getTimestamp(), $to->getTimestamp(), $startEq, $endEq, $maxDd, $curve) + [
                'step' => $stepTf,
                'candidates' => $candidatesSeen,
                'rejections' => $rejections,
                'sized_zero' => $sizedZero,
                'trims' => $trims,
                'under_one_contract' => $underOneContract,
                'sub_contract_trims' => $subContractTrims,
                'fractional_exits' => $fractionalExits,
                'margin_rejected' => $marginRejected,
                'skipped_capital' => $skippedCapital,
                'skipped_risk_cap' => $skippedRiskCap,
                'skipped_liquidity' => $skippedLiquidity,
                'liquidations' => $liquidations,
                'both_touched_bars' => $bothTouchedBars,
                'funding_usd' => round($fundingUsd, 2),
                'maker_fills' => $makerFills,
                'taker_fills' => $takerFills,
                'bars' => $steps,
            ],
        ];
    }

    public static function stats(array $trades, int $from, int $to, float $startEq, float $endEq, float $maxDd, array $curve = []): array
    {
        $n = count($trades);
        $wins = array_filter($trades, fn ($t) => $t['pnl_usd'] > 0);
        $losses = array_filter($trades, fn ($t) => $t['pnl_usd'] <= 0);
        $gw = array_sum(array_column($wins, 'pnl_usd'));
        $gl = abs(array_sum(array_column($losses, 'pnl_usd')));
        $rules = [];
        foreach ($trades as $t) {
            $rules[$t['rule'] ?? 'unknown'] = ($rules[$t['rule'] ?? 'unknown'] ?? 0) + 1;
        }
        $totalReturnPct = $startEq > 0 ? round(($endEq / $startEq - 1) * 100, 2) : null;
        $returns = Returns::fromCurve($curve);
        $sharpe = Returns::sharpe($returns);
        $calmar = $totalReturnPct === null
            ? null
            : max(-self::CALMAR_CAP, min(self::CALMAR_CAP, round($totalReturnPct / ($maxDd == 0.0 ? self::CALMAR_DD_FLOOR_PCT : $maxDd), 2)));

        return [
            'trades' => $n,
            'wins' => count($wins),
            'losses' => count($losses),
            'win_rate' => $n ? round(count($wins) / $n * 100, 1) : null,
            'avg_win_pct' => $wins ? round(array_sum(array_column($wins, 'pnl_pct')) / count($wins), 2) : null,
            'avg_loss_pct' => $losses ? round(array_sum(array_column($losses, 'pnl_pct')) / count($losses), 2) : null,
            'profit_factor' => $gl > 0 ? round($gw / $gl, 2) : ($gw > 0 ? 999 : null),
            'total_return_pct' => $totalReturnPct,
            'max_drawdown_pct' => round($maxDd, 2),
            'sharpe' => $sharpe !== null ? round($sharpe, 4) : null,
            'sharpe_ann' => $sharpe !== null ? round($sharpe * sqrt(2190), 4) : null,
            'skew' => round(Returns::skew($returns), 4),
            'kurt' => round(Returns::kurt($returns), 4),
            'obs' => count($returns),
            'calmar' => $calmar,
            'avg_hold_hours' => $n ? round(array_sum(array_column($trades, 'held_hours')) / $n, 1) : null,
            // Entry review 10: entry-quality diagnostics. entry_count is one per closed position (its
            // ORIGINAL entry); add_count sums every add on top of that, so the two can be judged apart.
            'avg_mae_pct' => $n ? round(array_sum(array_column($trades, 'mae_pct')) / $n, 2) : null,
            'avg_mfe_pct' => $n ? round(array_sum(array_column($trades, 'mfe_pct')) / $n, 2) : null,
            'avg_fwd_5' => (function () use ($trades) {
                $vals = array_filter(array_column($trades, 'fwd_5'), fn ($v) => $v !== null);

                return $vals ? round(array_sum($vals) / count($vals), 2) : null;
            })(),
            'entry_count' => $n,
            'add_count' => array_sum(array_column($trades, 'adds')),
            'days' => round(($to - $from) / 86400, 1),
            'exit_rules' => $rules,
            'by_side' => (function () use ($trades) {
                $out = [];
                foreach (['long', 'short'] as $side) {
                    $rows = array_values(array_filter($trades, fn ($t) => ($t['side'] ?? 'long') === $side));
                    if ($rows === []) {
                        continue;
                    }
                    $w = array_filter($rows, fn ($t) => $t['pnl_usd'] > 0);
                    $out[$side] = ['trades' => count($rows), 'win_rate' => round(count($w) / count($rows) * 100, 1), 'pnl_usd' => round(array_sum(array_column($rows, 'pnl_usd')), 2)];
                }

                return $out;
            })(),
        ];
    }

    /**
     * One closed trade row: net PnL = what the final exit made on the remainder, plus everything the
     * ladder already banked (realised_usd, itself net of every trim's own fee), minus this exit's own
     * fee, minus the entry fee(s) that never made it into entry_usd (whole-contract and margin entries
     * pay theirs separately -- see the entry block), minus funding accrued over the position's life.
     * $exitFee defaults to 0 because the trim-to-zero call site already folded its leg's fee into
     * realised_usd before calling this; every other call site (a straight close, a liquidation, the
     * end-of-test sweep) passes its own fee explicitly since it was never otherwise accounted for.
     */
    /**
     * Entry-quality diagnostics (entry review 10), merged onto a trade row: mae_pct/mfe_pct (the worst
     * and best price this position ever saw, relative to its ORIGINAL entry -- multiple adds don't move
     * the reference point, same convention as Position::peak_price for MFE) and fwd_1/3/5/10 (net % a
     * naive hold would have made N strategy bars after entry, after an approximate 2 x taker round-trip
     * cost, regardless of what this position's own exits actually did -- null once the run ends before
     * that bar exists). $stepBarsForPid is the full step-timeframe series already loaded for the whole
     * window, so a forward lookup is just a bar-index step, not a query.
     *
     * @param  array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>  $stepBarsForPid
     */
    private function entryQuality(Position $p, array $stepBarsForPid, int $dur, float $taker): array
    {
        $meta = $p->meta ?? [];
        $firstEntryPrice = (float) ($meta['first_entry_price'] ?? $p->entry_price);
        $firstEntryTs = (int) ($meta['first_entry_ts'] ?? 0);
        $trough = (float) ($meta['trough_price'] ?? $firstEntryPrice);
        $peak = (float) ($p->peak_price ?? $firstEntryPrice);
        $dir = $p->dir();

        $out = [
            'mae_pct' => $firstEntryPrice > 0 ? round($dir * ($trough / $firstEntryPrice - 1) * 100, 4) : 0.0,
            'mfe_pct' => $firstEntryPrice > 0 ? round($dir * ($peak / $firstEntryPrice - 1) * 100, 4) : 0.0,
        ];
        foreach ([1, 3, 5, 10] as $n) {
            $bar = $firstEntryTs > 0 ? $this->barAt($stepBarsForPid, $firstEntryTs + $n * $dur) : null;
            $out["fwd_{$n}"] = ($bar && $firstEntryPrice > 0) ? round((($bar['close'] / $firstEntryPrice - 1) * $dir - 2 * $taker) * 100, 4) : null;
        }

        return $out;
    }

    private function tradeRow(Position $p, string $pid, float $exitPx, Carbon $t, int $ts, ?string $rule, float $exitFee = 0.0): array
    {
        $meta = $p->meta ?? [];
        $entryFeeExcluded = (float) ($meta['entry_fee_excluded'] ?? 0);
        $funding = (float) ($meta['funding_usd'] ?? 0);
        $costTotal = (float) $p->entry_usd + (float) ($meta['cost_trimmed'] ?? 0) + $entryFeeExcluded;
        $pnl = $p->dir() * ($p->quantity * $exitPx - $p->entry_usd) + (float) ($p->realised_usd ?? 0) - $exitFee - $entryFeeExcluded - $funding;

        return [
            'product' => $pid, 'side' => $p->side ?? 'long', 'opened_at' => $p->opened_at->toIso8601String(), 'closed_at' => $t->toIso8601String(),
            'entry' => round((float) $p->entry_price, 8), 'exit' => round($exitPx, 8), 'usd' => round($costTotal, 2),
            'pnl_usd' => round($pnl, 2), 'pnl_pct' => $costTotal > 0 ? round($pnl / $costTotal * 100, 2) : 0,
            'rule' => $rule, 'held_hours' => round(($ts - $p->opened_at->getTimestamp()) / 3600, 1),
            'adds' => (int) $p->adds_count, 'trims' => (int) ($p->trims_count ?? 0),
            'why' => $p->meta['why'] ?? null,
        ];
    }

    private function barAt(array $bars, int $ts): ?array
    {
        $lo = 0;
        $hi = count($bars) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $s = $bars[$mid]['start'];
            if ($s === $ts) {
                return $bars[$mid];
            }
            $s < $ts ? $lo = $mid + 1 : $hi = $mid - 1;
        }

        return null;
    }

    /**
     * Every timeframe a strategy-plugin definition's rules could ask for: any rule's own "tf"
     * override, plus meta.timeframe (the default every `ind.*` field falls back to). Walks the
     * whole definition rather than naming each rules array, so a new rules section picks this
     * up for free.
     *
     * @return array<int, string>
     */
    public static function timeframesUsedBy(array $definition): array
    {
        $tfs = [];
        $meta = is_array($definition['meta'] ?? null) ? $definition['meta'] : [];
        if (is_string($meta['timeframe'] ?? null) && $meta['timeframe'] !== '') {
            $tfs[$meta['timeframe']] = true;
        }

        $walk = function (mixed $node) use (&$walk, &$tfs): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['field']) && is_string($node['tf'] ?? null) && $node['tf'] !== '') {
                $tfs[$node['tf']] = true;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($definition);

        return array_keys($tfs);
    }

    /** Case-insensitive match against Candle::DURATIONS's own keys ("1h" in a strategy's JSON is Candle::DURATIONS's "1H"). */
    private static function canonicalTf(string $tf): string
    {
        return Candle::canonicalTimeframe($tf) ?? $tf;
    }

    /** Bars with $from <= start <= $to (binary-searched; bars are sorted). */
    public static function slice(array $bars, int $from, int $to): array
    {
        $n = count($bars);
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            $bars[$mid]['start'] < $from ? $lo = $mid + 1 : $hi = $mid;
        }
        $start = $lo;
        $lo = $start;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            $bars[$mid]['start'] <= $to ? $lo = $mid + 1 : $hi = $mid;
        }

        return array_slice($bars, $start, $lo - $start);
    }

    public static function undot(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            Arr::set($out, $k, $v);
        }

        return $out;
    }
}
