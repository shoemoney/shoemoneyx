<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Position;
use App\Support\Fees;
use App\Support\Kelly;

/**
 * Shared spot-desk plumbing: not a selectable strategy itself (it is not registered in
 * config/desk.php `strategies`), but the base every shipped strategy either extends outright
 * (CustomStrategy) or falls back to for the parts it does not override (MeanReversionStrategy's
 * generic vet chain and risk rails).
 *
 *   SCAN  ranks on rate of change (volume surge, participation), never size.
 *   VET   kills: spread/book, liquidity vs size, one-buyer shape, already priced,
 *         fee viability, age & shape, data quality.
 *   SIZE  fractional Kelly on the strategy's edge estimate, capped at 6% of the book.
 *   RISK  THE RULE: 6h volume < 20% of the 24h average -> CLOSE, plus spot rails
 *         (hard stop, trailing giveback after +60%, max hold).
 *
 * Holder counts do not exist on a CEX, so "holders climbing faster than price"
 * becomes "buy-side participation climbing faster than price": the share of buy
 * trades and the volume surge versus the price move.
 */
abstract class BaseDeskStrategy implements Strategy
{
    public function defaults(): array
    {
        return [
            'scan' => [
                'max_candidates' => 10,
                'min_age_hours' => 24,
                'liquidity_floor_usd' => 100000,
                'min_score' => 0.15,
                'min_buy_share' => 0.5,        // buys / (buys+sells) in h1
                'one_buyer_price_pct' => 3.0,  // price up this much with weak participation = warning
            ],
            'vet' => [
                'max_spread_bps' => 40,
                'min_volume_surge_h1' => 1.25,  // h1 volume must be this multiple of the hourly 24h average
                'max_price_change_h1_pct' => 12.0,
                'max_breakeven_move_pct' => 2.0,
                'max_pct_of_volume_24h' => 0.5,
                'min_book_depth_multiple' => 3.0,
                'min_candles_h1' => 24,
            ],
            'size' => [
                'kelly_cap_pct' => 0.06,
                'kelly_fraction' => 0.5,
                'payoff_ratio' => 1.5,
                'min_ticket_usd' => 10.0,
                'max_open_positions' => 7,
                'max_slippage_bps' => 50,
                'partial_multiplier' => 0.5,   // PASS_PARTIAL cuts the ticket
                'allow_one_add_after_pct' => 50.0,
                'depth_gate' => true,          // side-aware executable-liquidity gate (vetExecutableLiquidity); backtests always skip it
                'max_quote_age_sec' => 10,      // depth older than this (or missing) is treated as unavailable
            ],
            'risk' => [
                'volume_ratio_close' => 0.20,
                'hard_stop_pct' => -12.0,
                'trail_activate_pct' => 60.0,
                'trail_giveback_pct' => 25.0,
                'max_hold_hours' => 72,
            ],
        ];
    }

    // =====================================================================
    // SCAN
    // =====================================================================

    public function scan(array $universe, DeskContext $ctx): array
    {
        $floor = (float) $ctx->param('scan.liquidity_floor_usd');
        $minAge = (float) $ctx->param('scan.min_age_hours');
        $minScore = (float) $ctx->param('scan.min_score');
        $max = (int) $ctx->param('scan.max_candidates', 10);

        $out = [];
        foreach ($universe as $s) {
            // Liquidity below the floor never enters the list at all.
            if ($s->volumeH24Usd < $floor) {
                continue;
            }
            // Too early to read: hold and re-check next poll rather than ranking noise.
            if ($s->ageHours !== null && $s->ageHours < $minAge) {
                continue;
            }
            // More sells than buys in the last two windows drops the candidate regardless of price.
            if ($s->sellsH1 > $s->buysH1 && $s->sellsM5 > $s->buysM5) {
                continue;
            }
            // Positions we already hold are RISK's problem, not SCAN's — unless a retest add is allowed.
            if ($ctx->hasOpenPosition($s->productId) && ! $this->addAllowed($ctx->openPosition($s->productId), $s, $ctx)) {
                continue;
            }

            [$score, $reason, $p] = $this->score($s, $ctx);
            if ($score < $minScore) {
                continue;
            }

            $out[] = new Candidate(
                stats: $s,
                score: $score,
                rankReason: $reason,
                degraded: $ctx->degraded,
                edgeProbability: $p,
                payoffRatio: (float) $ctx->param('size.payoff_ratio', 1.5),
            );
        }

        usort($out, fn (Candidate $a, Candidate $b) => $b->score <=> $a->score);

        return array_slice($out, 0, $max);
    }

    /**
     * Rate of change, never absolute size.
     *
     * @return array{0: float, 1: string, 2: float} [score, reason, edge probability]
     */
    protected function score(ProductStats $s, DeskContext $ctx): array
    {
        $surge = $s->volumeSurgeH1() ?? 1.0;          // h1 vs hourly h24 average
        $accel = $s->volumeAcceleration() ?? 1.0;     // h24 vs previous h24
        $trades = $s->buysH1 + $s->sellsH1;
        $buyShare = $trades > 0 ? $s->buysH1 / $trades : 0.5;
        $participation = $buyShare - 0.5;             // -0.5 .. +0.5
        $price = $s->priceChangeH1Pct;

        $surgeTerm = log(max($surge, 0.01));          // 0 at average, + when attention arrives
        $accelTerm = log(max($accel, 0.01)) * 0.5;
        $partTerm = $participation * 4;               // +2 at 100% buys

        $score = $surgeTerm + $accelTerm + $partTerm;
        $reasons = [];

        if ($surge > 1.5) {
            $reasons[] = sprintf('h1 volume %.1fx hourly avg', $surge);
        }
        if ($accel > 1.3) {
            $reasons[] = sprintf('24h volume %.1fx prior day', $accel);
        }
        if ($buyShare >= (float) $ctx->param('scan.min_buy_share')) {
            $reasons[] = sprintf('buy share %.0f%%', $buyShare * 100);
        }

        // Price climbing faster than participation is a warning. Mark it, never rank it up.
        if ($price > (float) $ctx->param('scan.one_buyer_price_pct') && $buyShare < (float) $ctx->param('scan.min_buy_share')) {
            $score -= 1.0;
            $reasons[] = 'WARN price climbing faster than participation';
        }

        // Edge estimate for SIZE: a gentle logistic on the score, capped so Kelly stays humble.
        $p = 0.45 + 0.12 / (1 + exp(-$score));       // 0.45 .. 0.57

        return [round($score, 4), $reasons === [] ? 'flat' : implode('; ', $reasons), round($p, 4)];
    }

    protected function addAllowed(?Position $position, ProductStats $s, DeskContext $ctx): bool
    {
        if (! $position || $position->adds_count > 0) {
            return false;
        }
        $threshold = (float) $ctx->param('size.allow_one_add_after_pct', 50.0);

        return $position->entry_price > 0 && $position->unrealisedPnlPct($s->price) >= $threshold;
    }

    // =====================================================================
    // VET
    // =====================================================================

    public function vet(Candidate $c, Bank $bank, DeskContext $ctx): Verdict
    {
        $s = $c->stats;
        $run = [];
        $skipped = [];
        $intended = $this->intendedTicket($c, $bank, $ctx);

        // 0. capacity — an empty list is a valid output and a normal one.
        $run[] = 'capacity';
        $maxOpen = (int) $ctx->param('size.max_open_positions', 7);
        $isAdd = $ctx->hasOpenPosition($s->productId);
        if (! $isAdd && count($ctx->openPositions) >= $maxOpen) {
            return Verdict::reject($c, 'capacity', "desk already holds {$maxOpen} positions", $run);
        }

        // 1. data quality — a candidate you cannot measure is not a candidate.
        $run[] = 'data_quality';
        $minCandles = (int) $ctx->param('vet.min_candles_h1', 24);
        if ($s->candlesH1Count < $minCandles || $s->price <= 0) {
            return Verdict::reject($c, 'data_quality', "only {$s->candlesH1Count} hourly candles behind this row (need {$minCandles})", $run);
        }

        // 2. spread & book (the CEX stand-in for top-wallet concentration).
        $run[] = 'spread_and_book';
        $maxSpread = (float) $ctx->param('vet.max_spread_bps', 40);
        if ($s->spreadBps === null) {
            return Verdict::reject($c, 'spread_and_book', 'no quote available — a failed call is a REJECT, not a retry loop', $run);
        }
        if ($s->spreadBps > $maxSpread) {
            return Verdict::reject($c, 'spread_and_book', sprintf('spread %.1f bps > %.1f max', $s->spreadBps, $maxSpread), $run);
        }

        // 3. liquidity vs intended size. A position you cannot leave is not a position.
        $run[] = 'liquidity';
        $maxPctVol = (float) $ctx->param('vet.max_pct_of_volume_24h', 0.5) / 100;
        if ($s->volumeH24Usd <= 0 || $intended > $s->volumeH24Usd * $maxPctVol) {
            return Verdict::reject($c, 'liquidity', sprintf('$%.0f ticket is %.2f%% of 24h volume (max %.2f%%)', $intended, $s->volumeH24Usd > 0 ? $intended / $s->volumeH24Usd * 100 : 100, $maxPctVol * 100), $run);
        }
        $depthMult = (float) $ctx->param('vet.min_book_depth_multiple', 3.0);
        if ($s->bookDepthUsd !== null && $s->bookDepthUsd < $intended * $depthMult) {
            return Verdict::reject($c, 'liquidity', sprintf('book depth within 1%% is $%.0f, need %.0fx the $%.0f ticket', $s->bookDepthUsd, $depthMult, $intended), $run);
        }

        // 3b. executable liquidity — side-aware: the ACTUAL proposed notional (not the 6% Kelly
        // proxy $intended is when a caller does not know its real ticket yet) walked against the
        // side we would actually trade, plus a conservative same-size check of the side we would
        // need to leave through. Backtests skip this explicitly (no real book ever existed for
        // them); live/paper can opt out via size.depth_gate but never silently pass.
        $gate = $this->vetExecutableLiquidity($c, $ctx, $intended);
        $run[] = $gate['check'];
        if ($gate['reject'] !== null) {
            return Verdict::reject($c, $gate['check'], $gate['reject'], $run, [], $gate['meta']);
        }

        // 4. one-buyer shape: price climbs while participation is flat.
        $run[] = 'participation';
        if ($this->participationGateApplies($c, $ctx)) {
            $trades = $s->buysH1 + $s->sellsH1;
            $buyShare = $trades > 0 ? $s->buysH1 / $trades : 0.0;
            if ($s->priceChangeH1Pct > (float) $ctx->param('scan.one_buyer_price_pct', 3.0) && $buyShare < (float) $ctx->param('scan.min_buy_share', 0.5)) {
                return Verdict::reject($c, 'participation', sprintf('price +%.1f%% in 1h with only %.0f%% buys — one buyer, not a crowd', $s->priceChangeH1Pct, $buyShare * 100), $run);
            }
        }

        // 5. attention. A flat tape is not a signal; rate of change is the whole point.
        $run[] = 'attention';
        $minSurge = (float) $ctx->param('vet.min_volume_surge_h1', 1.25);
        $surge = $s->volumeSurgeH1() ?? 0.0;
        if (! $isAdd && $surge < $minSurge) {
            return Verdict::reject($c, 'attention', sprintf('h1 volume %.2fx hourly average (need %.2fx) — nothing is happening here yet', $surge, $minSurge), $run);
        }

        // 6. already priced. A signal the market has moved on is noise.
        $run[] = 'already_priced';
        $maxH1 = (float) $ctx->param('vet.max_price_change_h1_pct', 12.0);
        if (abs($s->priceChangeH1Pct) > $maxH1) {
            return Verdict::reject($c, 'already_priced', sprintf('1h move %.1f%% already visible (max %.1f%%) — nothing left to take', $s->priceChangeH1Pct, $maxH1), $run);
        }

        // 7. story — only if the candidate carries a world claim (WorldMonitor context).
        if ($c->context !== null) {
            $run[] = 'story';
            if (empty($c->context['endpoint'])) {
                return Verdict::reject($c, 'story', 'world claim without a verifiable source', $run, [], $c->context);
            }
        } else {
            // no world claim at all: skip, that is not a failure (and does not make the verdict partial)
        }

        // 8. fee viability. Most small entries die here and should.
        $run[] = 'fee_viability';
        $breakEven = Fees::breakEvenMovePct($intended, (float) $ctx->param('fees.taker_rate', 0.006), (float) $ctx->param('fees.floor_usd', 0), (float) $ctx->param('paper.slippage_bps', 15));
        $maxBe = (float) $ctx->param('vet.max_breakeven_move_pct', 2.0);
        if ($breakEven > $maxBe) {
            return Verdict::reject($c, 'fee_viability', sprintf('break-even move %.2f%% > %.2f%% at $%.0f', $breakEven, $maxBe, $intended), $run);
        }

        // 9. age and shape: young and liquidity already falling is a rug shape.
        $run[] = 'age_and_shape';
        $minAge = (float) $ctx->param('scan.min_age_hours', 24);
        if ($s->ageHours !== null && $s->ageHours < $minAge * 3 && ($s->volumeAcceleration() ?? 1) < 0.7) {
            return Verdict::reject($c, 'age_and_shape', sprintf('%.0fh old with 24h volume at %.0f%% of the prior day', $s->ageHours, ($s->volumeAcceleration() ?? 0) * 100), $run);
        }

        if ($c->degraded) {
            $skipped[] = 'context';
        }

        return Verdict::pass($c, $run, $skipped, $isAdd ? 'retest add after confirmed move' : 'all checks passed', $c->degraded);
    }

    /** The ticket VET measures against: the Kelly ceiling on current equity. */
    protected function intendedTicket(Candidate $c, Bank $bank, DeskContext $ctx): float
    {
        $cap = (float) $ctx->param('size.kelly_cap_pct', 0.06);

        return max((float) $ctx->param('size.min_ticket_usd', 10), $bank->equity() * $cap);
    }

    /**
     * Override hook (short-entry-review #9): does the rising-market/low-buy-share rejection in
     * step 4 below apply to this candidate? A momentum-long strategy wants it on; a short-side
     * strategy may want weakening demand read as supporting evidence instead. Default: always on.
     */
    protected function participationGateApplies(Candidate $c, DeskContext $ctx): bool
    {
        return true;
    }

    /**
     * Side-aware executable-liquidity gate: a long ENTRY is approved against ASKS (buying) with a
     * conservative same-size check of BIDS (the eventual exit); a short ENTRY is approved against
     * BIDS (selling) with a same-size check of ASKS (the eventual cover). $notionalUsd is the
     * ACTUAL proposed ticket — callers with a real sizing model (a strategy that overrides vet()
     * and calls this directly) should pass their own ticket instead of relying on a caller's
     * proxy; vet() itself passes $intended (the 6% Kelly proxy) because that is all it has.
     *
     * Never a silent pass: missing or stale depth is REJECTED as "unavailable" whenever the gate
     * is enabled (size.depth_gate, default true). Backtests (DeskContext::$backtest) always skip
     * the gate explicitly — no historical book ever existed to check — and that skip is itself
     * recorded as a distinct check name so it is countable from checks_run, same as a real result.
     *
     * @return array{check:string, reject:?string, meta:?array} check is always appended to the
     *                                                          caller's checksRun; reject is null on pass, else the rejection reason.
     */
    protected function vetExecutableLiquidity(Candidate $c, DeskContext $ctx, float $notionalUsd): array
    {
        if ($ctx->backtest) {
            return ['check' => 'executable_liquidity_skipped_backtest', 'reject' => null, 'meta' => null];
        }
        if (! (bool) $ctx->param('size.depth_gate', true)) {
            return ['check' => 'executable_liquidity_disabled', 'reject' => null, 'meta' => null];
        }

        $check = 'executable_liquidity';
        $s = $c->stats;
        $maxAge = (float) $ctx->param('size.max_quote_age_sec', 10);
        if ($s->quoteAgeSec === null || $s->quoteAgeSec > $maxAge) {
            return ['check' => $check, 'reject' => sprintf(
                'depth unavailable — quote age %s exceeds %.0fs max',
                $s->quoteAgeSec === null ? 'unknown' : sprintf('%.1fs', $s->quoteAgeSec),
                $maxAge
            ), 'meta' => ['quote_age_sec' => $s->quoteAgeSec]];
        }

        $short = $c->isShort();
        $entrySide = $short ? 'SELL' : 'BUY';   // opening: short sells into bids, long buys from asks
        $exitSide = $short ? 'BUY' : 'SELL';    // the eventual close, checked at the same size
        $entryDepth = $short ? $s->bidDepthUsd : $s->askDepthUsd;
        $exitDepth = $short ? $s->askDepthUsd : $s->bidDepthUsd;

        if ($entryDepth === null || $exitDepth === null) {
            return ['check' => $check, 'reject' => sprintf(
                'depth unavailable — %s side has no book snapshot for this %s entry',
                $short ? 'bid' : 'ask', $short ? 'short' : 'long'
            ), 'meta' => null];
        }

        $entryCostBps = $s->costToTradeUsd($entrySide, $notionalUsd);
        if ($entryCostBps === null) {
            return ['check' => $check, 'reject' => sprintf(
                'book too shallow to fill $%.0f on the %s side', $notionalUsd, $entrySide
            ), 'meta' => ['entry_depth_usd' => $entryDepth]];
        }

        $exitCostBps = $s->costToTradeUsd($exitSide, $notionalUsd);
        if ($exitCostBps === null) {
            return ['check' => $check, 'reject' => sprintf(
                'same-size %s check too shallow — cannot leave a $%.0f position', $exitSide, $notionalUsd
            ), 'meta' => ['entry_cost_bps' => $entryCostBps, 'exit_depth_usd' => $exitDepth]];
        }

        $maxBps = (float) $ctx->param('size.max_slippage_bps', 50);
        if ($entryCostBps > $maxBps) {
            return ['check' => $check, 'reject' => sprintf(
                'entry cost %.1f bps > %.1f bps max', $entryCostBps, $maxBps
            ), 'meta' => ['entry_cost_bps' => $entryCostBps]];
        }
        if ($exitCostBps > $maxBps) {
            return ['check' => $check, 'reject' => sprintf(
                'same-size %s cost %.1f bps > %.1f bps max — position would be hard to leave', $exitSide, $exitCostBps, $maxBps
            ), 'meta' => ['entry_cost_bps' => $entryCostBps, 'exit_cost_bps' => $exitCostBps]];
        }

        return ['check' => $check, 'reject' => null, 'meta' => ['entry_cost_bps' => $entryCostBps, 'exit_cost_bps' => $exitCostBps]];
    }

    // =====================================================================
    // SIZE
    // =====================================================================

    public function size(Verdict $v, Bank $bank, DeskContext $ctx): SizeDecision
    {
        $c = $v->candidate;
        $s = $c->stats;
        $free = $bank->freeCash();
        $equity = $bank->equity();
        $minTicket = (float) $ctx->param('size.min_ticket_usd', 10);
        $cap = (float) $ctx->param('size.kelly_cap_pct', 0.06);

        if ($free < $minTicket) {
            return new SizeDecision($v, 0, 0, 0, false, false, sprintf('free cash $%.2f under minimum ticket $%.2f', $free, $minTicket));
        }

        // Kelly on the strategy's own edge estimate, then clamp. Six percent is the ceiling, not the target.
        $raw = Kelly::fraction($c->edgeProbability, $c->payoffRatio, (float) $ctx->param('size.kelly_fraction', 0.5), 1.0);
        $ceiling = $raw > $cap;
        $fraction = min($raw, $cap);
        $dollars = $equity * $fraction;
        $why = sprintf('kelly p=%.3f b=%.2f -> %.2f%% of equity', $c->edgeProbability, $c->payoffRatio, $fraction * 100);

        if ($v->verdict === Verdict::PASS_PARTIAL) {
            $dollars *= (float) $ctx->param('size.partial_multiplier', 0.5);
            $why .= '; PASS_PARTIAL cut';
        }

        // Size down as liquidity falls.
        $maxPctVol = (float) $ctx->param('vet.max_pct_of_volume_24h', 0.5) / 100;
        $liqCap = $s->volumeH24Usd * $maxPctVol;
        if ($s->bookDepthUsd !== null) {
            $liqCap = min($liqCap, $s->bookDepthUsd / (float) $ctx->param('vet.min_book_depth_multiple', 3.0));
        }
        $exitable = true;
        if ($dollars > $liqCap) {
            $dollars = $liqCap;
            $why .= sprintf('; cut to liquidity cap $%.0f', $liqCap);
        }
        if ($liqCap < $minTicket) {
            $exitable = false;
        }

        $dollars = min($dollars, $free);

        // Never size below what pays its own fees.
        $fee = Fees::effectiveRate($dollars, (float) $ctx->param('fees.taker_rate', 0.006), (float) $ctx->param('fees.floor_usd', 0));
        if ($dollars < $minTicket || $fee > (float) $ctx->param('fees.max_effective_fee_pct', 0.02) || ! $exitable) {
            return new SizeDecision($v, 0, 0, 0, $exitable, $ceiling, $why.'; below minimum viable ticket');
        }

        $dollars = floor($dollars * 100) / 100;

        return new SizeDecision(
            $v,
            $dollars,
            $free > 0 ? round($dollars / $free * 100, 4) : 0,
            $equity > 0 ? round($dollars / $equity * 100, 4) : 0,
            true,
            $ceiling,
            $why,
        );
    }

    // =====================================================================
    // RISK
    // =====================================================================

    public function risk(Position $p, ProductStats $s, DeskContext $ctx): RiskDecision
    {
        $avg6 = $s->volumeH24Usd / 4;
        $ratio = $avg6 > 0 ? $s->volumeH6Usd / $avg6 : null;
        $threshold = (float) $ctx->param('risk.volume_ratio_close', 0.20);
        $pnlPct = $p->unrealisedPnlPct($s->price);

        // THE RULE. Never widen the threshold because a position is almost recovering.
        if ($ratio !== null && $ratio < $threshold) {
            return RiskDecision::close('volume_dry', $s->volumeH6Usd, $avg6, $ratio, sprintf('6h volume %.3f of 24h avg < %.2f', $ratio, $threshold));
        }
        if ($ratio === null) {
            return RiskDecision::close('unmeasurable', $s->volumeH6Usd, $avg6, null, 'a position you cannot measure is a position you do not hold');
        }

        // Spot rails.
        $hardStop = (float) $ctx->param('risk.hard_stop_pct', -12.0);
        if ($pnlPct <= $hardStop) {
            return RiskDecision::close('hard_stop', $s->volumeH6Usd, $avg6, $ratio, sprintf('pnl %.2f%% <= %.2f%%', $pnlPct, $hardStop));
        }

        // Hold through +50%. Mark at +60% and let it run — but do not hand a runner back.
        $peak = max((float) ($p->peak_price ?? 0), $s->price);
        $peakPct = $p->entry_price > 0 ? ($peak / $p->entry_price - 1) * 100 : 0;
        $activate = (float) $ctx->param('risk.trail_activate_pct', 60.0);
        $giveback = (float) $ctx->param('risk.trail_giveback_pct', 25.0);
        if ($peakPct >= $activate && $pnlPct <= $peakPct - $giveback) {
            return RiskDecision::close('trail_giveback', $s->volumeH6Usd, $avg6, $ratio, sprintf('peak +%.1f%%, now +%.1f%% (giveback %.0f%%)', $peakPct, $pnlPct, $giveback));
        }

        $maxHold = (float) $ctx->param('risk.max_hold_hours', 72);
        $held = $p->opened_at->diffInMinutes($ctx->now()) / 60;
        if ($maxHold > 0 && $held >= $maxHold) {
            return RiskDecision::close('max_hold', $s->volumeH6Usd, $avg6, $ratio, sprintf('held %.1fh >= %.0fh', $held, $maxHold));
        }

        return RiskDecision::hold($s->volumeH6Usd, $avg6, $ratio, sprintf('ratio %.3f, pnl %.2f%%', $ratio, $pnlPct));
    }
}
