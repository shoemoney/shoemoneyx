<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Candle;
use App\Models\Position;

/**
 * "mr" — fades extremes back toward a rolling mean (long side and, optionally, short).
 *
 *   SCAN   z = (close - rolling mean) / rolling stdev over mr.lookback step bars. Long when
 *          z <= -mr.entry_z, short when z >= +mr.entry_z (shorts only when mr.allow_shorts).
 *          An optional mr.trend_ma gate only fades in the direction of the 1H MA (price above
 *          it for longs, below it for shorts).
 *   VET    generic spread/liquidity checks (via BaseDeskStrategy::vet) plus a realised-vol floor
 *          (mr.vol_min_pct): skip a flat tape, there is nothing to revert from.
 *   SIZE   mr.qty_pct of equity x leverage, capped at mr.max_position_pct, floored to the cent.
 *   RISK   close ("mr_exit") once z crosses back through +/-mr.exit_z (the mean was reached),
 *          close as a stop ("mr_stop") if |z| keeps growing past mr.stop_z against the position,
 *          close after mr.max_hold_bars ("mr_max_hold"), optional trailing stop from the peak
 *          ("mr_tsl"). Every CONFIRMED close arms mr.cooldown_bars before the pair can re-enter --
 *          live/paper derives this from positions.closed_at, so it survives fresh strategy
 *          instances and process restarts; backtests arm an in-process timestamp directly.
 *
 * Shorts read mr.short.<key> first and fall back to mr.<key> (the sp() helper) — a coin can
 * carry a long parameter set and a short parameter set at once.
 */
class MeanReversionStrategy extends BaseDeskStrategy
{
    /** backtest only: full z-score series per pair (+timeframe+lookback), indexed like the bars */
    private array $full = [];

    /** backtest-only cooldown: product id => unix timestamp before which scan() will not re-enter.
     *  Live/paper derives the cooldown from the confirmed close instead -- see inCooldown(). */
    private array $cooldownUntil = [];

    public function key(): string
    {
        return 'mr';
    }

    public function name(): string
    {
        return 'Mean Reversion';
    }

    public function defaults(): array
    {
        return array_replace_recursive(parent::defaults(), [
            'mr' => [
                'timeframe' => '1m',
                'lookback' => 60,
                'band' => 'zscore',          // 'zscore' | 'bollinger' — same rolling mean/stdev, band is a display label today
                'entry_z' => 2.0,
                'exit_z' => 0.3,
                'stop_z' => 3.5,
                'max_hold_bars' => 240,
                'qty_pct' => 10.0,
                'max_position_pct' => 60.0,
                'leverage' => 1.0,
                'pyramiding' => 1,            // reserved: mr takes at most one position per pair, no add-signal is implemented
                'allow_longs' => true,
                'allow_shorts' => false,
                'trend_ma' => 0,              // fade only in the direction of the 1H SMA(n); 0 = no gate
                'vol_min_pct' => 0.0,         // skip when realised vol (stdev/mean %) is below this; 0 = off
                'tsl_pct' => 0.0,             // trailing stop from the favourable extreme, % giveback; 0 = off
                'cooldown_bars' => 5,         // step bars to sit out after any exit
            ],
            'scan' => ['min_score' => 0.0, 'liquidity_floor_usd' => 100000],
            'vet' => ['min_volume_surge_h1' => 0.0, 'max_price_change_h1_pct' => 1000.0, 'max_spread_bps' => 5.0],
            'size' => ['max_open_positions' => 7],
            // mr owns its own exits (mr_exit/mr_stop/mr_max_hold/mr_tsl); disable the generic rails
            // the base class would otherwise apply.
            'risk' => ['volume_ratio_close' => 0.0, 'hard_stop_pct' => -12.0, 'max_hold_hours' => 0, 'trail_activate_pct' => 1000.0],
        ]);
    }

    // ------------------------------------------------------------------
    // SCAN
    // ------------------------------------------------------------------

    /** @param array<int, ProductStats> $universe */
    public function scan(array $universe, DeskContext $gctx): array
    {
        $out = [];
        $max = (int) $gctx->param('scan.max_candidates', 10);

        foreach ($universe as $s) {
            $ctx = $gctx->forProduct($s->productId);
            $longs = (bool) $ctx->param('mr.allow_longs', true);
            $shorts = (bool) $ctx->param('mr.allow_shorts', false);
            if (! $longs && ! $shorts) {
                continue;
            }
            // mr holds at most one position per pair (see mr.pyramiding above): once open, or
            // freshly closed and still cooling down, SCAN has nothing to say about this pair.
            if ($ctx->hasOpenPosition($s->productId) || $this->inCooldown($s->productId, $ctx)) {
                continue;
            }

            $kind = null;
            $snap = null;
            if ($longs) {
                $l = $this->snapshot($s->productId, $ctx, false);
                $entryZ = (float) $this->sp($ctx, false, 'entry_z', 2.0);
                if ($l !== null && $l['z'] <= -$entryZ) {
                    $kind = 'long';
                    $snap = $l;
                }
            }
            if ($kind === null && $shorts) {
                $sh = $this->snapshot($s->productId, $ctx, true);
                $entryZ = (float) $this->sp($ctx, true, 'entry_z', 2.0);
                if ($sh !== null && $sh['z'] >= $entryZ) {
                    $kind = 'short';
                    $snap = $sh;
                }
            }
            if ($kind === null) {
                continue;
            }

            $isShort = $kind === 'short';
            $gate = (int) $this->sp($ctx, $isShort, 'trend_ma', 0);
            if ($gate > 0 && ! $this->trendOk($s->productId, $s->price, $gate, $isShort, $ctx)) {
                continue;
            }

            $out[] = new Candidate(
                stats: ProductStats::fromArray(array_replace($s->jsonSerialize(), ['extra' => $s->extra + ['mr' => $snap]])),
                score: round(abs($snap['z']), 4),
                rankReason: sprintf('%s z=%.2f (mean %.6f, std %.6f)', $isShort ? 'Short fade' : 'Long fade', $snap['z'], $snap['mean'], $snap['std']),
                degraded: $ctx->degraded,
                edgeProbability: 0.55,
                payoffRatio: (float) $ctx->param('size.payoff_ratio', 1.5),
                side: $isShort ? 'short' : 'long',
            );
        }

        usort($out, fn (Candidate $a, Candidate $b) => $b->score <=> $a->score);

        return array_slice($out, 0, $max);
    }

    // ------------------------------------------------------------------
    // VET
    // ------------------------------------------------------------------

    public function vet(Candidate $c, Bank $bank, DeskContext $gctx): Verdict
    {
        $verdict = parent::vet($c, $bank, $gctx);
        if (! $verdict->passed()) {
            return $verdict;
        }

        $ctx = $gctx->forProduct($c->productId());
        $volMin = (float) $this->sp($ctx, $c->isShort(), 'vol_min_pct', 0.0);
        if ($volMin > 0) {
            $snap = $c->stats->extra['mr'] ?? null;
            $mean = (float) ($snap['mean'] ?? 0.0);
            $realised = $snap !== null && $mean != 0.0 ? abs(((float) $snap['std']) / $mean) * 100 : 0.0;
            if ($realised < $volMin) {
                return Verdict::reject($c, 'realised_vol', sprintf('realised vol %.4f%% < %.4f%% minimum — nothing to revert from', $realised, $volMin), array_merge($verdict->checksRun, ['realised_vol']));
            }
        }

        return $verdict;
    }

    // ------------------------------------------------------------------
    // SIZE
    // ------------------------------------------------------------------

    public function size(Verdict $v, Bank $bank, DeskContext $gctx): SizeDecision
    {
        $ctx = $gctx->forProduct($v->candidate->productId());
        $short = $v->candidate->isShort();
        $equity = $bank->equity();
        $lev = max(1.0, (float) $this->sp($ctx, $short, 'leverage', 1));
        $free = $lev > 1 ? max(0.0, $equity * (1 - $bank->lockedPct) * $lev - $bank->reserveUsd - $bank->positionsValue) : $bank->freeCash();
        $minTicket = (float) $ctx->param('size.min_ticket_usd', 10);

        $pct = (float) $this->sp($ctx, $short, 'qty_pct', 10) / 100;
        $maxPos = (float) $this->sp($ctx, $short, 'max_position_pct', 60) / 100;
        $want = min($equity * $pct * $lev, $equity * $maxPos * $lev);
        $why = sprintf('%.0f%% of equity x %.1fx leverage, capped at %.0f%% of equity', $pct * 100, $lev, $maxPos * 100);

        $dollars = floor(min($want, $free) * 100) / 100;
        if ($dollars < $minTicket) {
            return new SizeDecision($v, 0, 0, 0, true, false, $why.sprintf('; $%.2f under minimum ticket (free $%.2f)', max($dollars, 0), $free));
        }

        return new SizeDecision($v, $dollars, $free > 0 ? round($dollars / $free * 100, 4) : 0, $equity > 0 ? round($dollars / $equity * 100, 4) : 0, true, $want > $free, $why);
    }

    // ------------------------------------------------------------------
    // RISK
    // ------------------------------------------------------------------

    public function risk(Position $p, ProductStats $s, DeskContext $gctx): RiskDecision
    {
        $ctx = $gctx->forProduct($p->product_id);
        $short = $p->isShort();
        $snap = $this->snapshot($p->product_id, $ctx, $short);

        if ($snap !== null) {
            $z = $snap['z'];
            $exitZ = (float) $this->sp($ctx, $short, 'exit_z', 0.3);
            $stopZ = (float) $this->sp($ctx, $short, 'stop_z', 3.5);

            // 1. Mean reached: z crossed back through the exit band.
            $reverted = $short ? $z <= $exitZ : $z >= -$exitZ;
            if ($reverted) {
                $this->armCooldown($p->product_id, $ctx, $short);

                return RiskDecision::close('mr_exit', $s->volumeH6Usd, $s->volumeH24Usd / 4, $s->volumeRatio6h(), sprintf('z %.2f reverted through %.2f', $z, $short ? $exitZ : -$exitZ));
            }

            // 2. Stop: the move kept going further away from the mean than stop_z.
            $stopped = $short ? $z >= $stopZ : $z <= -$stopZ;
            if ($stopped) {
                $this->armCooldown($p->product_id, $ctx, $short);

                return RiskDecision::close('mr_stop', $s->volumeH6Usd, $s->volumeH24Usd / 4, $s->volumeRatio6h(), sprintf('z %.2f beyond stop %.2f', $z, $short ? $stopZ : -$stopZ));
            }
        }

        // 3. Max hold.
        $maxHold = (int) $this->sp($ctx, $short, 'max_hold_bars', 240);
        if ($maxHold > 0) {
            $tf = (string) $this->sp($ctx, $short, 'timeframe', '1m');
            $dur = Candle::DURATIONS[$tf] ?? 60;
            $heldBars = (int) floor(($ctx->now()->getTimestamp() - $p->opened_at->getTimestamp()) / $dur);
            if ($heldBars >= $maxHold) {
                $this->armCooldown($p->product_id, $ctx, $short);

                return RiskDecision::close('mr_max_hold', $s->volumeH6Usd, $s->volumeH24Usd / 4, $s->volumeRatio6h(), sprintf('held %d bars >= %d', $heldBars, $maxHold));
            }
        }

        // 4. Optional trailing stop from the favourable extreme.
        $tsl = (float) $this->sp($ctx, $short, 'tsl_pct', 0.0);
        if ($tsl > 0) {
            $peakPct = $p->entry_price > 0 ? $p->dir() * ((float) $p->peak_price / (float) $p->entry_price - 1) * 100 : 0.0;
            $pnlPct = $p->unrealisedPnlPct($s->price);
            if ($peakPct >= 0 && $pnlPct <= $peakPct - $tsl) {
                $this->armCooldown($p->product_id, $ctx, $short);

                return RiskDecision::close('mr_tsl', $s->volumeH6Usd, $s->volumeH24Usd / 4, $s->volumeRatio6h(), sprintf('peak %+.2f%%, now %+.2f%% (giveback %.2f)', $peakPct, $pnlPct, $tsl));
            }
        }

        // 5. Rails (disabled by defaults above; still live if a dashboard override re-enables them).
        return parent::risk($p, $s, $ctx);
    }

    // ------------------------------------------------------------------

    /**
     * Rolling z-score over closes[i-lookback+1..i] inclusive, oldest -> newest. Entries before
     * enough history exists are null. Public + static so the math is directly unit-testable.
     *
     * @param  array<int, float>  $closes
     * @return array<int, array{mean: float, std: float, z: float}|null>
     */
    public static function zseries(array $closes, int $lookback): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = $lookback - 1; $i < $n; $i++) {
            $window = array_slice($closes, $i - $lookback + 1, $lookback);
            $mean = array_sum($window) / $lookback;
            $variance = 0.0;
            foreach ($window as $c) {
                $variance += ($c - $mean) ** 2;
            }
            $variance /= $lookback;
            $std = sqrt($variance);
            $out[$i] = ['mean' => $mean, 'std' => $std, 'z' => $std > 0.0 ? ($closes[$i] - $mean) / $std : 0.0];
        }

        return $out;
    }

    /**
     * Signal snapshot for the last closed bar. Live: computed on demand over a short bar window.
     * Backtest: the whole series is computed once per pair (causal, oldest -> newest) and indexed,
     * so replaying a backtest bar-by-bar stays cheap.
     */
    protected function snapshot(string $pid, DeskContext $ctx, bool $short): ?array
    {
        $tf = (string) $this->sp($ctx, $short, 'timeframe', '1m');
        $lookback = max(2, (int) $this->sp($ctx, $short, 'lookback', 60));
        $dur = Candle::DURATIONS[$tf] ?? 60;
        $now = $ctx->now()->getTimestamp();

        if ($ctx->backtest) {
            $key = $pid.'|'.$tf.'|'.$lookback;
            if (! array_key_exists($key, $this->full)) {
                $bars = $ctx->bars($pid, $tf, 0, PHP_INT_MAX);
                $this->full[$key] = count($bars) <= $lookback ? null : [
                    'starts' => array_column($bars, 'start'),
                    'z' => self::zseries(array_column($bars, 'close'), $lookback),
                ];
            }
            $f = $this->full[$key];
            if ($f === null) {
                return null;
            }
            $lastClosed = $now - $dur;
            $lo = 0;
            $hi = count($f['starts']);
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                $f['starts'][$mid] <= $lastClosed ? $lo = $mid + 1 : $hi = $mid;
            }
            $i = $lo - 1;

            return $i >= 0 ? $f['z'][$i] : null;
        }

        $bars = $ctx->bars($pid, $tf, $now - ($lookback + 5) * $dur, $now - $dur);
        if (count($bars) <= $lookback) {
            return null;
        }
        $series = self::zseries(array_column($bars, 'close'), $lookback);

        return $series[count($series) - 1];
    }

    /** Trend gate on the 1H tape: longs want price over the SMA, shorts want it under (fade with the trend, not against it). */
    protected function trendOk(string $pid, float $price, int $len, bool $short, DeskContext $ctx): bool
    {
        $now = $ctx->now()->getTimestamp();
        $bars = $ctx->bars($pid, '1H', $now - ($len + 2) * 3600, $now - 3600);
        if (count($bars) < $len) {
            return true;   // not enough history: don't block
        }
        $closes = array_column(array_slice($bars, -$len), 'close');
        $sma = array_sum($closes) / $len;

        return $short ? $price < $sma : $price > $sma;
    }

    /**
     * Live/paper: derived from the most recently CONFIRMED close of this pair under this strategy
     * and mode instead of any in-instance state. Desk::close() only sets positions.status=closed
     * (with closed_at) after the exchange fill actually succeeds, so this is armed by execution,
     * not by risk()'s proposed decision, and it is naturally shared by every fresh strategy
     * instance risk() and scan() resolve through the container, and naturally survives a process
     * restart -- it is a plain query, there is no separate cache/state to go stale or get lost.
     *
     * Backtest: Backtester never persists positions to the database (they live in memory for the
     * run), so this path is unreachable there -- backtests keep using the in-process
     * $cooldownUntil armed directly by armCooldown(), unchanged from before this fix.
     */
    protected function inCooldown(string $pid, DeskContext $ctx): bool
    {
        if ($ctx->backtest) {
            return isset($this->cooldownUntil[$pid]) && $ctx->now()->getTimestamp() < $this->cooldownUntil[$pid];
        }

        $last = Position::query()
            ->where('mode', $ctx->mode)
            ->where('strategy', $this->key())
            ->where('product_id', $pid)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->first(['side', 'closed_at']);

        if ($last === null) {
            return false;
        }

        $bars = (int) $this->sp($ctx, $last->isShort(), 'cooldown_bars', 5);
        if ($bars <= 0) {
            return false;
        }
        $tf = (string) $this->sp($ctx, $last->isShort(), 'timeframe', '1m');
        $dur = Candle::DURATIONS[$tf] ?? 60;

        return $ctx->now()->getTimestamp() < $last->closed_at->getTimestamp() + $bars * $dur;
    }

    /** Backtest-only: live/paper derives the cooldown from the confirmed close instead (see inCooldown). */
    protected function armCooldown(string $pid, DeskContext $ctx, bool $short): void
    {
        if (! $ctx->backtest) {
            return;
        }

        $bars = (int) $this->sp($ctx, $short, 'cooldown_bars', 5);
        if ($bars <= 0) {
            return;
        }
        $tf = (string) $this->sp($ctx, $short, 'timeframe', '1m');
        $dur = Candle::DURATIONS[$tf] ?? 60;
        $this->cooldownUntil[$pid] = $ctx->now()->getTimestamp() + $bars * $dur;
    }

    /** Shorts read mr.short.<key> when set and fall back to mr.<key>. */
    protected function sp(DeskContext $ctx, bool $short, string $key, mixed $default = null): mixed
    {
        if ($short) {
            $v = $ctx->param("mr.short.{$key}");
            if ($v !== null) {
                return $v;
            }
        }

        return $ctx->param("mr.{$key}", $default);
    }
}
