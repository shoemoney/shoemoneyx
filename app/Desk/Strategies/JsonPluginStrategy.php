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
use App\Models\BankSnapshot;
use App\Models\Position;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use App\Support\Fees;
use App\Support\Kelly;
use Carbon\CarbonImmutable;

/**
 * Runs a saved strategy-plugin JSON inside the real pipeline (backtests,
 * and paper/live when explicitly selected). The base desk pipeline's rails
 * always stay on: the JSON narrows scan, adds vet rejections, and adds
 * risk-close rules.
 *
 * Selected with strategy=json + the `json.plugin_key` param (the builder's
 * backtest trigger sets both). Without a plugin key it behaves as the plain
 * base desk pipeline. A backtest that pinned an exact version also sets
 * `json.plugin_version_id`, which takes priority — the definition is always
 * read from the immutable version row, never the mutable plugin, once pinned.
 *
 * `definition()` always returns the canonical schema_version:1 shape (see
 * SchemaMigrator): legacy flat-shape definitions are mapped forward on read
 * so every plugin ever saved keeps running unchanged.
 */
class JsonPluginStrategy extends BaseDeskStrategy
{
    public function key(): string
    {
        return 'json';
    }

    public function name(): string
    {
        return 'JSON plugin';
    }

    public function defaults(): array
    {
        return array_replace_recursive(parent::defaults(), [
            'json' => ['plugin_key' => null, 'plugin_version_id' => null],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function definition(DeskContext $ctx): ?array
    {
        $versionId = $ctx->param('json.plugin_version_id');
        if (is_numeric($versionId)) {
            $version = StrategyPluginVersion::find((int) $versionId);
            if ($version !== null) {
                return self::forRuntime(SchemaMigrator::migrate($version->definition));
            }
        }

        $key = $ctx->param('json.plugin_key');
        if (! is_string($key) || $key === '') {
            return null;
        }
        $plugin = StrategyPlugin::where('key', $key)->first();

        return $plugin === null ? null : self::forRuntime(SchemaMigrator::migrate($plugin->definition));
    }

    /**
     * v2 only: resolve `$name` param references to their declared defaults (ParamSubstitutor),
     * the same substitution StrategySchemaValidator applies before checking a v2 definition —
     * so a formula or a rule value written as `$fail_safe_pct` runs as the number it validated
     * against, not the literal string. v1 has no such references; left untouched.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private static function forRuntime(array $def): array
    {
        return self::isV2($def) ? ParamSubstitutor::apply($def) : $def;
    }

    /** @param array<int, ProductStats> $universe */
    public function scan(array $universe, DeskContext $ctx): array
    {
        $def = $this->definition($ctx);
        if ($def === null) {
            return parent::scan($universe, $ctx);
        }
        if (self::isV2($def)) {
            return $this->scanV2($universe, $def, $ctx);
        }

        $rules = [...($def['setup']['rules'] ?? []), ...($def['trigger']['rules'] ?? [])];
        $tf = self::defaultTf($def);
        $filtered = array_values(array_filter(
            $universe,
            fn (ProductStats $s) => array_all($rules, fn ($rule) => JsonRuleEvaluator::fires($rule, $s, null, $ctx, $tf)),
        ));

        $max = $def['trigger']['max_candidates'] ?? null;
        $out = parent::scan($filtered, $ctx);

        return is_int($max) && $max > 0 ? array_slice($out, 0, $max) : $out;
    }

    /**
     * v2 SCAN (docs/STRATEGY_SCHEMA_V2.md, "Evaluation order"): every signal name in
     * `entry.when` must hold — its `all` rules all hold, and its `any` rules (if present)
     * hold at least one. The base pipeline's own scan() still ranks and truncates on top,
     * exactly as the v1 branch above does.
     *
     * @param  array<int, ProductStats>  $universe
     * @return array<int, Candidate>
     */
    private function scanV2(array $universe, array $def, DeskContext $ctx): array
    {
        $signals = is_array($def['signals'] ?? null) ? $def['signals'] : [];
        $when = is_array($def['entry']['when'] ?? null) ? $def['entry']['when'] : [];
        $tf = self::defaultTf($def);

        $filtered = array_values(array_filter(
            $universe,
            fn (ProductStats $s) => array_all($when, fn ($name) => self::evalSignal($signals[$name] ?? null, $s, null, $ctx, $tf)),
        ));

        $max = $def['entry']['max_candidates'] ?? null;
        $out = parent::scan($filtered, $ctx);

        return is_int($max) && $max > 0 ? array_slice($out, 0, $max) : $out;
    }

    /** One named `{all, any}` signal group: every `all` rule holds, and at least one `any` rule holds when `any` is non-empty. */
    private static function evalSignal(mixed $group, ProductStats $s, ?Position $p, DeskContext $ctx, string $tf): bool
    {
        if (! is_array($group)) {
            return false;
        }
        $all = is_array($group['all'] ?? null) ? $group['all'] : [];
        if (! array_all($all, fn ($rule) => JsonRuleEvaluator::fires($rule, $s, $p, $ctx, $tf))) {
            return false;
        }
        $any = is_array($group['any'] ?? null) ? $group['any'] : [];

        return $any === [] || array_any($any, fn ($rule) => JsonRuleEvaluator::fires($rule, $s, $p, $ctx, $tf));
    }

    private static function isV2(array $def): bool
    {
        return ($def['schema_version'] ?? 1) === 2;
    }

    /**
     * The ticket VET's liquidity/book-depth/executable-depth/fee-viability gates measure against.
     * BaseDeskStrategy's own default is a 6%-of-equity Kelly proxy that happens to equal v1's real
     * ticket (parent::size() is Kelly-capped at that same size.kelly_cap_pct) but has no relation
     * to a v2 sizing object — a v2 `entry.size` of a flat $500,000 or 50% of equity would still be
     * vetted as a $6,000 ticket. Resolving the real v2 sizing object here (falling back to the base
     * Kelly proxy for v1 or no plugin) makes those gates measure what will actually be sent
     * (docs/STRATEGY_SCHEMA_V2.md review round 3).
     */
    protected function intendedTicket(Candidate $c, Bank $bank, DeskContext $ctx): float
    {
        $def = $this->definition($ctx);
        if ($def !== null && self::isV2($def) && is_array($def['entry']['size'] ?? null)) {
            $dollars = $this->sizingDollars($def['entry']['size'], 'entry', $ctx, $def, $bank, $c->stats->price, null, $c);
            if ($dollars !== null && $dollars > 0) {
                return $dollars;
            }
        }

        return parent::intendedTicket($c, $bank, $ctx);
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        $verdict = parent::vet($candidate, $bank, $ctx);
        if (! $verdict->passed()) {
            return $verdict;
        }

        $def = $this->definition($ctx);
        if ($def === null) {
            return $verdict;
        }

        $tf = self::defaultTf($def);
        foreach ($def['entry']['confirm'] ?? [] as $rule) {
            $field = (string) ($rule['field'] ?? '');
            $ruleTf = is_string($rule['tf'] ?? null) && $rule['tf'] !== '' ? $rule['tf'] : $tf;
            if (JsonRuleEvaluator::value($field, $candidate->stats, null, $ctx, $ruleTf) === null) {
                // A v1 stats field on missing data is skipped (fail-open): scan already
                // excluded unmeasurable rows. An `ind.*` field never went through that scan
                // filter, so an unmeasurable confirmation blocks the entry instead (fail closed).
                // v2 fails closed on ANY missing confirm field (docs/STRATEGY_SCHEMA_V2.md, design
                // rule 4 — "a rule on a missing value never fires"): a v2 confirm field need not
                // appear in any entry.when signal, so the scan-already-excluded-it justification
                // that lets v1 skip doesn't hold for it.
                if (! self::isV2($def) && ! str_starts_with($field, 'ind.')) {
                    continue;
                }

                return Verdict::reject(
                    $candidate,
                    'json.'.$field,
                    (string) ($rule['reason'] ?? "unmeasurable: {$field}"),
                    [...$verdict->checksRun, 'json'],
                );
            }
            if (! JsonRuleEvaluator::fires($rule, $candidate->stats, null, $ctx, $tf)) {
                return Verdict::reject(
                    $candidate,
                    'json.'.$field,
                    (string) ($rule['reason'] ?? "plugin rule failed: {$field}"),
                    [...$verdict->checksRun, 'json'],
                );
            }
        }

        return $verdict;
    }

    public function size(Verdict $v, Bank $bank, DeskContext $ctx): SizeDecision
    {
        $def = $this->definition($ctx);
        if ($def !== null) {
            $isAdd = $ctx->hasOpenPosition($v->candidate->productId());

            $maxPositions = $def['risk']['max_positions'] ?? null;
            if (! $isAdd && is_int($maxPositions) && $maxPositions > 0 && count($ctx->openPositions) >= $maxPositions) {
                return new SizeDecision($v, 0, 0, 0, false, false, sprintf(
                    'risk.max_positions cap reached (%d open, max %d)', count($ctx->openPositions), $maxPositions
                ));
            }

            $capPct = $def['risk']['daily_loss_cap_pct'] ?? null;
            if (is_numeric($capPct) && $capPct > 0) {
                $pnlPct = $this->dailyPnlPct($ctx);
                if ($pnlPct !== null && $pnlPct <= -(float) $capPct) {
                    return new SizeDecision($v, 0, 0, 0, false, false, sprintf(
                        'risk.daily_loss_cap_pct %.2f%% breached (today %.2f%%) — no new entries', (float) $capPct, $pnlPct
                    ));
                }
            }
        }

        $size = ($def !== null && self::isV2($def)) ? $this->sizeV2($v, $bank, $ctx, $def) : parent::size($v, $bank, $ctx);
        if ($def === null || $size->zero()) {
            return $size;
        }

        $levCap = $def['risk']['leverage_cap'] ?? null;
        if (! is_numeric($levCap) || $levCap <= 0) {
            return $size;
        }

        // Clamp so total exposure (existing positions + this ticket) never exceeds
        // leverage_cap x equity. $bank->exposure is every open position's notional already.
        $room = max(0.0, $bank->equity() * (float) $levCap - $bank->exposure);
        if ($size->dollars <= $room) {
            return $size;
        }

        $clamped = floor($room * 100) / 100;
        $minTicket = (float) $ctx->param('size.min_ticket_usd', 10);
        if ($clamped < $minTicket) {
            return new SizeDecision($v, 0, 0, 0, false, false, $size->why.sprintf(
                '; risk.leverage_cap %.2fx leaves no room ($%.2f)', (float) $levCap, $room
            ));
        }

        return new SizeDecision(
            $v,
            $clamped,
            $bank->freeCash() > 0 ? round($clamped / $bank->freeCash() * 100, 4) : 0,
            $bank->equity() > 0 ? round($clamped / $bank->equity() * 100, 4) : 0,
            $size->exitable,
            true,
            $size->why.sprintf('; clamped to risk.leverage_cap %.2fx ($%.2f room)', (float) $levCap, $room),
        );
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        $def = $this->definition($ctx);
        if ($def === null) {
            return parent::risk($position, $stats, $ctx);
        }

        $capPct = $def['risk']['daily_loss_cap_pct'] ?? null;
        if (is_numeric($capPct) && $capPct > 0) {
            $pnlPct = $this->dailyPnlPct($ctx, $position, $stats);
            if ($pnlPct !== null && $pnlPct <= -(float) $capPct) {
                return RiskDecision::close(
                    'risk.daily_loss_cap_pct',
                    $stats->volumeH6Usd,
                    $stats->volumeH24Usd / 4,
                    $stats->volumeRatio6h(),
                    sprintf('daily PnL %.2f%% <= -%.2f%% cap — closing all', $pnlPct, (float) $capPct),
                );
            }
        }

        if (self::isV2($def)) {
            return $this->riskV2($position, $stats, $def, $ctx);
        }

        $tf = self::defaultTf($def);
        foreach ($def['exit']['stop']['rules'] ?? [] as $rule) {
            if (JsonRuleEvaluator::fires($rule, $stats, $position, $ctx, $tf)) {
                $avg6 = $stats->volumeH24Usd / 4;

                return RiskDecision::close(
                    'json.'.($rule['field'] ?? 'rule'),
                    $stats->volumeH6Usd,
                    $avg6,
                    $avg6 > 0 ? $stats->volumeH6Usd / $avg6 : null,
                    sprintf('plugin rule fired: %s %s %s', $rule['field'] ?? '?', $rule['op'] ?? '?', json_encode($rule['value'] ?? null)),
                );
            }
        }

        $mgmt = is_array($def['management'] ?? null) ? $def['management'] : [];

        $trailing = $mgmt['trailing'] ?? null;
        if (is_array($trailing)) {
            $trailPct = (float) ($trailing['trail_pct'] ?? 0);
            if ($trailPct > 0) {
                $activate = (float) ($trailing['activate_pct'] ?? 0);
                $peakPct = $this->peakPct($position);
                $pnlPct = $position->unrealisedPnlPct($stats->price);
                if ($peakPct >= $activate && $pnlPct <= $peakPct - $trailPct) {
                    return RiskDecision::close(
                        'management.trailing',
                        $stats->volumeH6Usd,
                        $stats->volumeH24Usd / 4,
                        $stats->volumeRatio6h(),
                        sprintf('peak %+.2f%%, now %+.2f%% (trail %.2f%%)', $peakPct, $pnlPct, $trailPct),
                    );
                }
            }
        }

        // Partial take-profit rungs fire once each, in declaration order: management.partials[trims_count]
        // is the next rung due; trims_count only advances when Desk::trim() actually books a fill.
        $partials = $mgmt['partials'] ?? [];
        if (is_array($partials) && $partials !== []) {
            $idx = (int) $position->trims_count;
            $rung = $partials[$idx] ?? null;
            if (is_array($rung)) {
                $pct = (float) ($rung['pct'] ?? 0);
                $fraction = (float) ($rung['fraction'] ?? 0);
                $pnlPct = $position->unrealisedPnlPct($stats->price);
                if ($fraction > 0 && $pnlPct >= $pct) {
                    return RiskDecision::trim(
                        "management.partials.{$idx}",
                        $fraction,
                        null,
                        sprintf('pnl %.2f%% >= partial rung %d at %.2f%%, selling %.0f%%', $pnlPct, $idx, $pct, $fraction * 100),
                    );
                }
            }
        }

        // Adds fire once each, in declaration order: management.adds[adds_count] is the next rung
        // due. count(adds) is the max-adds cap — once adds_count reaches it there is no rung left.
        // size_pct is a percent of the position's initial_cost_usd (its true, never-overwritten
        // opening cost basis — see Desk::doEnter()), not the current, already-added-to entry_usd.
        $adds = $mgmt['adds'] ?? [];
        if (is_array($adds) && $adds !== []) {
            $idx = (int) $position->adds_count;
            $rung = $adds[$idx] ?? null;
            if (is_array($rung)) {
                $trigger = $rung['trigger'] ?? null;
                if (is_array($trigger) && JsonRuleEvaluator::fires($trigger, $stats, $position, $ctx, $tf)) {
                    $basis = (float) ($position->meta['initial_cost_usd'] ?? $position->entry_usd);
                    $sizePct = (float) ($rung['size_pct'] ?? 0);
                    $dollars = $basis * $sizePct / 100;
                    if ($dollars > 0) {
                        return RiskDecision::add(
                            "management.adds.{$idx}",
                            $dollars,
                            $stats->price,
                            sprintf('add rung %d triggered — +%.2f%% of initial cost ($%.2f)', $idx, $sizePct, $dollars),
                        );
                    }
                }
            }
        }

        return parent::risk($position, $stats, $ctx);
    }

    // ── v2 (docs/STRATEGY_SCHEMA_V2.md) ─────────────────────────────────

    /** entry.size, one of the four sizing-object modes, clamped to min-ticket/cash exactly like the v1 (Kelly) path above does before the shared leverage_cap clamp runs. */
    private function sizeV2(Verdict $v, Bank $bank, DeskContext $ctx, array $def): SizeDecision
    {
        $sizing = $def['entry']['size'] ?? null;
        $minTicket = (float) $ctx->param('size.min_ticket_usd', 10);
        $free = $bank->freeCash();
        $equity = $bank->equity();

        if (! is_array($sizing)) {
            return new SizeDecision($v, 0, 0, 0, false, false, 'entry.size is missing');
        }
        if ($free < $minTicket) {
            return new SizeDecision($v, 0, 0, 0, false, false, sprintf('free cash $%.2f under minimum ticket $%.2f', $free, $minTicket));
        }

        $price = $v->candidate->stats->price;
        $dollars = $this->sizingDollars($sizing, 'entry', $ctx, $def, $bank, $price, null, $v->candidate);
        $why = sprintf('entry.size mode=%s', $sizing['mode'] ?? '?');
        if ($dollars === null || $dollars <= 0) {
            return new SizeDecision($v, 0, 0, 0, false, false, $why.' produced no order');
        }
        $why .= sprintf(' -> $%.2f', $dollars);

        $dollars = min($dollars, $free);

        // Same liquidity/book-depth cut BaseDeskStrategy::size() applies (see there) — v2 sizing
        // modes (usd, formula, an outsized pct_equity) have no relationship to that Kelly ceiling,
        // so without this a v2 ticket sails past 24h volume/book depth straight into an unexitable
        // fill (docs/STRATEGY_SCHEMA_V2.md review round 3).
        $s = $v->candidate->stats;
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

        $fee = Fees::effectiveRate($dollars, (float) $ctx->param('fees.taker_rate', 0.006), (float) $ctx->param('fees.floor_usd', 0));
        if ($dollars < $minTicket || $fee > (float) $ctx->param('fees.max_effective_fee_pct', 0.02) || ! $exitable) {
            return new SizeDecision($v, 0, 0, 0, $exitable, false, $why.'; below minimum viable ticket');
        }

        $dollars = floor($dollars * 100) / 100;

        return new SizeDecision(
            $v, $dollars,
            $free > 0 ? round($dollars / $free * 100, 4) : 0,
            $equity > 0 ? round($dollars / $equity * 100, 4) : 0,
            true, false, $why,
        );
    }

    /**
     * A sizing object's dollar notional — always dollars, for every section including
     * `reentry` (a caller there converts back to a quantity via `/ $price`; see
     * reentryArmDecision()). `$bank` is only available at SIZE time (entry): `pct_equity`
     * and `kelly` fall back to null (no order) for `adds`/`reentry`, evaluated from RISK,
     * which the Strategy contract never hands a Bank — the acceptance strategy only needs
     * `usd` and `formula` there, and both already fail closed on a missing variable rather
     * than guess an equity figure.
     */
    private function sizingDollars(array $sizing, string $section, DeskContext $ctx, array $def, ?Bank $bank, float $price, ?Position $position, ?Candidate $candidate = null, array $extraVars = []): ?float
    {
        return match ($sizing['mode'] ?? null) {
            'pct_equity' => $bank === null || ! is_numeric($sizing['value'] ?? null) || $sizing['value'] <= 0
                ? null : $bank->equity() * (float) $sizing['value'] / 100,
            'usd' => is_numeric($sizing['value'] ?? null) && $sizing['value'] > 0 ? (float) $sizing['value'] : null,
            'kelly' => $this->kellyDollars($sizing, $bank, $candidate),
            'formula' => $this->formulaDollars($sizing, $section, $ctx, $def, $bank, $price, $position, $extraVars),
            default => null,
        };
    }

    private function kellyDollars(array $sizing, ?Bank $bank, ?Candidate $candidate): ?float
    {
        if ($bank === null) {
            return null;
        }
        $fraction = is_numeric($sizing['fraction'] ?? null) ? (float) $sizing['fraction'] : 0.5;
        $cap = isset($sizing['max_pct_book']) && is_numeric($sizing['max_pct_book']) ? (float) $sizing['max_pct_book'] / 100 : 0.06;
        $frac = $candidate !== null
            ? Kelly::fraction($candidate->edgeProbability, $candidate->payoffRatio, $fraction, $cap)
            : min($fraction, $cap);

        return $frac > 0 ? $bank->equity() * $frac : null;
    }

    /** A formula's own result is a QUANTITY for `reentry`, a dollar notional for `entry`/`adds` (docs/STRATEGY_SCHEMA_V2.md, "Formulas") — converted to dollars here either way, since sizingDollars() always returns dollars. */
    private function formulaDollars(array $sizing, string $section, DeskContext $ctx, array $def, ?Bank $bank, float $price, ?Position $position, array $extraVars): ?float
    {
        $expr = $sizing['expr'] ?? null;
        if (! is_string($expr) || trim($expr) === '') {
            return null;
        }
        try {
            $ast = Formula::parse($expr);
        } catch (FormulaParseError) {
            return null;
        }
        $vars = $this->formulaVars($section, $def, $ctx, $bank, $price, $position, $extraVars);
        $result = Formula::evaluate($ast, $vars);
        if ($result === null) {
            return null;
        }

        return $section === 'reentry' ? $result * $price : $result;
    }

    /** @return array<string, float> */
    private function formulaVars(string $section, array $def, DeskContext $ctx, ?Bank $bank, float $price, ?Position $position, array $extra = []): array
    {
        $vars = ['price' => $price, 'pi' => M_PI, 'fees_rt_pct' => $this->feesRoundTripPct($def, $ctx)];
        if ($bank !== null) {
            $vars['equity'] = $bank->equity();
            $vars['cash'] = $bank->freeCash();
        }
        if ($position !== null) {
            $vars['avg'] = self::avg($position);
            $vars['position_usd'] = (float) $position->quantity * $price;
            $vars['initial_cost_usd'] = (float) ($position->meta['initial_cost_usd'] ?? $position->entry_usd);
        }

        return $vars + $extra;
    }

    /**
     * The taker fee percent (e.g. 0.6, not 0.006) a v2 strategy's own `meta.fees.taker_pct`
     * overrides, else the desk's configured rate. No v2 strategy declares itself post-only
     * today (there is no such runtime toggle — see docs/STRATEGY_SCHEMA_V2.md, "Fees"), so
     * both legs of every round trip use this same rate.
     */
    private function takerFeePct(array $def, DeskContext $ctx): float
    {
        $fees = is_array($def['meta']['fees'] ?? null) ? $def['meta']['fees'] : [];
        if (isset($fees['taker_pct']) && is_numeric($fees['taker_pct'])) {
            return (float) $fees['taker_pct'];
        }

        return (float) $ctx->param('fees.taker_rate', 0.006) * 100;
    }

    private function feesRoundTripPct(array $def, DeskContext $ctx): float
    {
        return $this->takerFeePct($def, $ctx) * 2;
    }

    /**
     * `(price - p_in) x q > fee(p_in x q) + fee(price x q)` for a long, mirrored for a short
     * (docs/STRATEGY_SCHEMA_V2.md, "Fees"). Public and static so it is directly unit-testable
     * without a live position/desk — the one number `reentry.cash_out` decides on.
     */
    public static function greenAfterFees(Position $position, float $exitPrice, float $entryPrice, float $qty, float $takerFraction): bool
    {
        if ($qty <= 0 || $entryPrice <= 0 || $exitPrice <= 0) {
            return false;
        }
        $pnl = $position->dir() * ($exitPrice - $entryPrice) * $qty;
        $fees = ($entryPrice + $exitPrice) * $qty * $takerFraction;

        return $pnl > $fees;
    }

    /**
     * v2 RISK, first match wins (docs/STRATEGY_SCHEMA_V2.md, "Evaluation order" #2-7);
     * risk.daily_loss_cap_pct (#1) already ran above, and #8 (base-strategy rails) is
     * parent::risk() at the very end, exactly as the v1 branch falls through to it too.
     */
    private function riskV2(Position $position, ProductStats $stats, array $def, DeskContext $ctx): RiskDecision
    {
        $this->ensureV2EntryPriceRecorded($position);
        $this->reconcilePendingReentry($position);
        $this->reconcilePendingAddsRung($position);
        $this->reconcilePendingRung($position);
        $this->reconcilePendingCashOut($position);
        $this->reconcileV2Avg($position);
        $takeProfit = is_array($def['take_profit'] ?? null) ? $def['take_profit'] : [];
        $ladder = $this->rebuildLadderState($position, $takeProfit);
        $tf = self::defaultTf($def);

        $stop = is_array($def['stop'] ?? null) ? $def['stop'] : [];
        if (($d = $this->stopDecision($position, $stats, $stop, $ctx, $tf)) !== null) {
            return $d;
        }

        if (($d = $this->takeProfitRulesDecision($position, $stats, $takeProfit, $ctx, $tf)) !== null) {
            return $d;
        }

        $reentry = is_array($def['reentry'] ?? null) ? $def['reentry'] : null;
        if ($reentry !== null && ($d = $this->cashOutDecision($position, $stats, $reentry, $def, $ctx)) !== null) {
            return $d;
        }

        if (($d = $this->ladderDecision($position, $stats, $takeProfit, $ladder, $stop)) !== null) {
            return $d;
        }

        if ($reentry !== null && ($d = $this->reentryArmDecision($position, $stats, $def, $reentry, $takeProfit, $ladder, $ctx, $tf)) !== null) {
            return $d;
        }

        $adds = is_array($def['adds'] ?? null) ? $def['adds'] : [];
        if (($d = $this->addsDecisionV2($position, $stats, $adds, $def, $ctx, $tf)) !== null) {
            return $d;
        }

        if (($d = $this->runnerTtpDecision($position, $stats, $takeProfit, $ladder)) !== null) {
            return $d;
        }

        return parent::risk($position, $stats, $ctx);
    }

    /**
     * `stop.anchor: "entry"` needs the position's ORIGINAL fill price, which `position.entry_price`
     * stops being once an add moves it — recorded once, the first time v2 RISK ever sees this
     * position (docs/STRATEGY_SCHEMA_V2.md, "stop"). Backtester positions already carry a more
     * precise `first_entry_price` (set at the fill that opened them, for entry-quality diagnostics);
     * preferred here when present, else the current (still un-added-to) running average.
     */
    private function ensureV2EntryPriceRecorded(Position $position): void
    {
        $meta = $position->meta ?? [];
        if (isset($meta['v2']['entry_price'])) {
            return;
        }
        $meta['v2']['entry_price'] = (float) ($meta['first_entry_price'] ?? $position->entry_price);
        $position->meta = $meta;
    }

    /**
     * The v2 engine's own `avg` (docs/STRATEGY_SCHEMA_V2.md, "avg" — "the position's current cost
     * basis after every add"), read back after reconcileV2Avg() has updated it for this call.
     * `position->entry_price` is NOT this number once any add has filled: Desk::doEnter() sets it to
     * the raw (fee-exclusive) fill price at open, but Desk::bookAdd() recomputes it as
     * entry_usd / quantity on every add — a fee-INCLUSIVE cost divided by a fee-adjusted quantity —
     * which jumps by roughly one taker fee with no price move at all. Falls back to entry_price only
     * for a position reconcileV2Avg() has never touched (riskV2() always calls it first).
     *
     * Public and static so JsonRuleEvaluator's `position.avg`/`position.peak_pct` read the exact
     * same number RISK's own ladder/stop/runner math reads, instead of a second, fee-polluted
     * definition drifting from this one (docs/STRATEGY_SCHEMA_V2.md review round 3).
     */
    public static function avg(Position $position): float
    {
        return is_numeric($position->meta['v2']['avg'] ?? null) ? (float) $position->meta['v2']['avg'] : (float) $position->entry_price;
    }

    /**
     * Maintains `position.meta.v2.avg`, a weighted average priced entirely off each add's own
     * fee-EXCLUSIVE fill (unlike `position->entry_price` — see avg()'s docblock). Detected from the
     * position's own quantity/entry_usd/fees_usd deltas since the last call: a quantity INCREASE is
     * an add (an `adds` rung or a `reentry` buy — both go through Desk::bookAdd()), priced at
     * `(entry_usd delta - fees_usd delta) / quantity delta` and folded into the running average; a
     * quantity decrease (a trim — Desk::bookExit() reduces quantity and entry_usd by the same
     * fraction) never moves it, matching how `position->entry_price` itself is untouched by trims.
     *
     * `entry_usd` is fee-INCLUSIVE on the plain spot/cash-notional fill path (Desk/Backtester book
     * the full requested dollars, fee already inside) but fee-EXCLUSIVE on whole-contract perps and
     * on margin (they book `Lot::notional`/`OrderResult::filledUsd`, which never carried the fee —
     * it is booked separately into `fees_usd` only). Subtracting the whole `fees_usd` delta on that
     * second convention would strip a fee that was never in the basis, understating every add's fill
     * price by ~one taker fee. `meta.entry_fee_excluded` (set by Desk::bookAdd()/Backtester's own
     * bookAdd) is the running total of fee already excluded from `entry_usd`; subtracting only the
     * part of the `fees_usd` delta that `entry_usd` does not already carry recovers the true fill
     * price on both conventions.
     */
    private function reconcileV2Avg(Position $position): float
    {
        $meta = $position->meta ?? [];
        $v2 = is_array($meta['v2'] ?? null) ? $meta['v2'] : [];
        $qty = (float) $position->quantity;
        $entryUsd = (float) $position->entry_usd;
        $feesUsd = (float) $position->fees_usd;
        $feeExcl = (float) ($meta['entry_fee_excluded'] ?? 0);

        $avg = is_numeric($v2['avg'] ?? null) ? (float) $v2['avg'] : null;
        $prevQty = is_numeric($v2['avg_qty'] ?? null) ? (float) $v2['avg_qty'] : null;

        if ($avg === null || $prevQty === null) {
            $avg = (float) $position->entry_price;
        } elseif ($qty > $prevQty + 1e-12) {
            $prevUsd = is_numeric($v2['avg_entry_usd'] ?? null) ? (float) $v2['avg_entry_usd'] : $entryUsd;
            $prevFees = is_numeric($v2['avg_fees_usd'] ?? null) ? (float) $v2['avg_fees_usd'] : $feesUsd;
            $prevFeeExcl = is_numeric($v2['avg_fee_excl'] ?? null) ? (float) $v2['avg_fee_excl'] : $feeExcl;
            $qtyDelta = $qty - $prevQty;
            $fillPrice = ($entryUsd - $prevUsd - (($feesUsd - $prevFees) - ($feeExcl - $prevFeeExcl))) / $qtyDelta;
            $avg = ($avg * $prevQty + $fillPrice * $qtyDelta) / $qty;
        }

        $meta['v2']['avg'] = $avg;
        $meta['v2']['avg_qty'] = $qty;
        $meta['v2']['avg_entry_usd'] = $entryUsd;
        $meta['v2']['avg_fees_usd'] = $feesUsd;
        $meta['v2']['avg_fee_excl'] = $feeExcl;
        $position->meta = $meta;

        return $avg;
    }

    /**
     * Rebuilds `position.meta.v2.ladder` whenever `avg` (position.entry_price) has moved since it
     * was last stored — the one check that implements both `reset_on_add: true` (a fresh ladder,
     * re-based on the current quantity) and `false` (fired rungs and `original_qty` stay; only the
     * stored `avg` used to price the remaining rungs moves). Absent `reset_on_add` defaults to true,
     * matching the shipped SMX π example and "an add starts a new ladder" being the more intuitive
     * v2 default (v1->v2 migration always writes `false` explicitly, matching v1's own "fired rungs
     * stay fired" behaviour — see SchemaMigrator::partialsToLadder(), not a fills-equivalence claim).
     *
     * @return array{avg: float, original_qty: float, fired: array<int, int>, sold: array<int, array{qty: float, price: float}>}
     */
    private function rebuildLadderState(Position $position, array $takeProfit): array
    {
        $meta = $position->meta ?? [];
        $ladder = is_array($meta['v2']['ladder'] ?? null) ? $meta['v2']['ladder'] : null;
        $avg = self::avg($position);
        $resetOnAdd = ! array_key_exists('reset_on_add', $takeProfit) || (bool) $takeProfit['reset_on_add'];
        $mark = (float) ($position->last_price ?: $avg);

        if ($ladder === null) {
            $ladder = ['avg' => $avg, 'original_qty' => (float) $position->quantity, 'fired' => [], 'sold' => [], 'peak_price' => $mark];
        } elseif (abs($avg - (float) $ladder['avg']) > abs($avg) * 1e-9) {
            $ladder = $resetOnAdd
                // A true rearm (reset_on_add: true, the branch that clears fired/sold) re-bases the
                // runner's own peak to the current mark too — otherwise an `adds` rung that lowers
                // avg raises peakPct with no price move, since position->peak_price is an ALL-TIME
                // high that survives the rearm (review round 2, runnerTtpDecision()).
                ? ['avg' => $avg, 'original_qty' => (float) $position->quantity, 'fired' => [], 'sold' => [], 'peak_price' => $mark]
                : ['avg' => $avg, 'original_qty' => (float) $ladder['original_qty'], 'fired' => $ladder['fired'], 'sold' => $ladder['sold'], 'peak_price' => $ladder['peak_price'] ?? $mark];
        }

        $priorPeak = (float) ($ladder['peak_price'] ?? $mark);
        $ladder['peak_price'] = $priorPeak <= 0 ? $mark : ($position->isShort() ? min($priorPeak, $mark) : max($priorPeak, $mark));

        $meta['v2']['ladder'] = $ladder;
        $position->meta = $meta;

        return $ladder;
    }

    /**
     * A pending re-entry lot (docs/STRATEGY_SCHEMA_V2.md, "reentry" mechanics) is confirmed once
     * `position.adds_count` has advanced past what it was when the lot was recorded — the add it
     * asked for actually filled — or dropped when a later RISK call finds it still hasn't (the fill
     * was rejected: fee floor, capital, a halt). Runs once per RISK call, before anything else reads
     * `meta.v2.reentries`, so at most one lot is ever mid-flight at a time.
     */
    private function reconcilePendingReentry(Position $position): void
    {
        $meta = $position->meta ?? [];
        $list = $meta['v2']['reentries'] ?? null;
        if (! is_array($list) || $list === []) {
            return;
        }
        $lastIdx = array_key_last($list);
        $last = $list[$lastIdx];
        if (! is_array($last) || ($last['confirmed'] ?? true) !== false) {
            return;
        }
        if ((int) $position->adds_count > (int) ($last['adds_count_at_emit'] ?? -1)) {
            // The lot's real fill: the position's own post-add qty/cost delta, which already carries
            // slippage (bookAdd() fills at the slipped mark) and the fee-adjusted quantity — not the
            // pre-fill price/qty this lot was recorded with when RISK emitted the ADD. $usdFilled
            // carries the fee only on the plain spot/cash-notional path; on whole-contract perps and
            // margin it does not (see reconcileV2Avg()'s docblock), so `entry_fee_excluded`'s own
            // delta is subtracted back out before the fee delta is removed, on both conventions.
            $qtyFilled = (float) $position->quantity - (float) ($last['qty_at_emit'] ?? 0.0);
            $usdFilled = (float) $position->entry_usd - (float) ($last['entry_usd_at_emit'] ?? 0.0);
            $feesFilled = (float) $position->fees_usd - (float) ($last['fees_usd_at_emit'] ?? 0.0);
            $feeExclFilled = (float) ($meta['entry_fee_excluded'] ?? 0) - (float) ($last['entry_fee_excl_at_emit'] ?? 0.0);
            if ($qtyFilled > 0) {
                $list[$lastIdx]['qty'] = $qtyFilled;
                $list[$lastIdx]['price'] = ($usdFilled - ($feesFilled - $feeExclFilled)) / $qtyFilled;
                $list[$lastIdx]['confirmed'] = true;
            } else {
                // A zero-quantity "fill" (adds_count advanced for some unrelated reason, or an
                // offsetting trim in the same window) is never promoted to confirmed carrying the
                // stale pre-fill intent — nothing may read it (see class docblock) — so it is
                // dropped exactly like the never-filled branch below.
                unset($list[$lastIdx]);
                $list = array_values($list);
            }
        } else {
            unset($list[$lastIdx]);
            $list = array_values($list);
        }
        $meta['v2']['reentries'] = $list;
        $position->meta = $meta;
    }

    /**
     * A pending `adds` rung shares `position.adds_count` with a confirmed re-entry buy — both go
     * through Desk::bookAdd() — so `adds_fired` (the counter addsDecisionV2() actually indexes by,
     * fixing the round-1 finding that a confirmed re-entry silently skipped an `adds` rung) is only
     * advanced here, once `adds_count` has moved past what it was when THIS rung's ADD was emitted.
     * Confirmed the same way reconcilePendingReentry()/reconcilePendingRung() confirm theirs: dropped
     * (not advanced) when the counter never moved, so a fill that was rejected re-arms the same rung
     * next call instead of being silently marked fired.
     */
    private function reconcilePendingAddsRung(Position $position): void
    {
        $meta = $position->meta ?? [];
        $pending = $meta['v2']['adds_pending'] ?? null;
        if (! is_array($pending)) {
            return;
        }
        if ((int) $position->adds_count > (int) ($pending['adds_count_at_emit'] ?? -1)) {
            $meta['v2']['adds_fired'] = max((int) ($meta['v2']['adds_fired'] ?? 0), (int) $pending['idx'] + 1);
        }
        unset($meta['v2']['adds_pending']);
        $position->meta = $meta;
    }

    /**
     * A pending ladder rung (docs/STRATEGY_SCHEMA_V2.md, take_profit "Engine state") is promoted to
     * `fired`/`sold` only once `position.trims_count` has advanced past what it was when RISK
     * emitted the TRIM — the fill actually happened — or dropped so the same rung re-fires next
     * call. Without this, a trim that Desk/Backtester silently drops (a sub-contract skip, a failed
     * close/trim, a swallowed lock timeout) would permanently consume the rung and feed a phantom
     * `sold` qty into the re-entry formula.
     *
     * `sold.qty` is the REAL filled quantity (`qty_at_emit - position.quantity`), not the intended
     * `$sellQty` the rung asked for: on a whole-contract product, Desk::trim() floors to whole
     * contracts (Lot::forQty()), so a rung asking for 3.7 contracts can fill only 3 — recording 3.7
     * would let the re-entry formula buy back more than was actually sold.
     *
     * `sold.price` is likewise the REAL fill price (`meta.v2.last_trim_fill_price`, stamped by
     * Desk::trim()/Backtester from the same fill the position's own books were updated with), not
     * the rung's computed target: live/paper fills at market, not at the target, so pricing
     * `retrace_pct`/`sold_usd` off the target overstates the retrace on any slip between the two.
     * Falls back to the target only for a fill that predates this fix (no stamp recorded).
     */
    private function reconcilePendingRung(Position $position): void
    {
        $meta = $position->meta ?? [];
        $pending = $meta['v2']['ladder']['pending'] ?? null;
        if (! is_array($pending)) {
            return;
        }
        if ((int) $position->trims_count > (int) ($pending['trims_count_at_emit'] ?? -1)) {
            $idx = (int) $pending['idx'];
            $qtyAtEmit = (float) ($pending['qty_at_emit'] ?? $pending['qty']);
            $actualQty = max(0.0, min((float) $pending['qty'], $qtyAtEmit - (float) $position->quantity));
            // 0.0 IS numeric: a stamp of exactly zero (CoinbaseExecutor can report that on a poll
            // with filled_size > 0 but no filled_value) must fall back to the target price too, or
            // sold[idx]['price'] records 0 and reentryArmDecision() bails forever on $salePrice <= 0.
            $stampedFill = $meta['v2']['last_trim_fill_price'] ?? null;
            $fillPrice = (is_numeric($stampedFill) && (float) $stampedFill > 0)
                ? (float) $stampedFill : (float) $pending['price'];
            // Consumed once, right here: left in place, a stamp from THIS rung's fill would still
            // be sitting in meta the next time a DIFFERENT rung's pending gets reconciled (e.g.
            // this rung's own fill was guarded out to a zero/missing stamp) and get inherited as
            // that rung's sale price (round-4 review — proven: rung 1 recorded rung 0's 110.0
            // instead of falling back to its own 120.0 target).
            unset($meta['v2']['last_trim_fill_price']);
            $meta['v2']['ladder']['fired'][] = $idx;
            $meta['v2']['ladder']['sold'][$idx] = ['qty' => $actualQty, 'price' => $fillPrice];
        }
        unset($meta['v2']['ladder']['pending']);
        $position->meta = $meta;
    }

    /**
     * A pending cash-out (docs/STRATEGY_SCHEMA_V2.md, reentry "Engine state") is promoted to
     * `cashed_out: true` only once `position.trims_count` has advanced past what it was when RISK
     * emitted the TRIM — the same fill-confirmation gap reconcilePendingRung() closes for ladder
     * rungs: cashOutDecision() used to set `cashed_out` the instant it emitted the TRIM, so a trim
     * that never fills (whole-contract flooring, a rejected order) permanently retired the lot's
     * risk with nothing sold. An unconfirmed cash-out is dropped (not re-tried blindly) so the next
     * call re-evaluates green-after-fees fresh, exactly like a dropped rung re-fires.
     *
     * The REAL filled quantity (`qty_at_emit - position.quantity`, mirroring
     * reconcilePendingRung()) can fall short of the intended `sell_qty` on a whole-contract product
     * (Desk::trim()/Backtester's Lot::forQty floor) — the shortfall is what silently joined
     * `cash_out.remainder` instead of being sold. The lot still retires on any fill (mirrors
     * reconcilePendingRung(): a rung that floors short of its target is accepted as fired, not
     * chased) — re-deriving a fresh `sell_pct_of_reentry` off a shrinking remainder every call
     * converges toward a sub-contract dust amount Lot::forQty can never fill, which would starve
     * ladderDecision()/reentryArmDecision()/runnerTtpDecision() forever (cashOutDecision() has
     * evaluation priority over all of them). What's fixed here is the accounting: the
     * `remainder: "ladder"` fold below used the INTENDED `sell_qty`, silently crediting the ladder's
     * `original_qty` with less than what actually stayed in the position whenever a fill came up
     * short — it now folds in the REAL unsold amount (`lot_qty - soldQty`).
     */
    private function reconcilePendingCashOut(Position $position): void
    {
        $meta = $position->meta ?? [];
        $list = $meta['v2']['reentries'] ?? null;
        if (! is_array($list)) {
            return;
        }
        $changed = false;
        foreach ($list as $i => $lot) {
            $pending = is_array($lot) ? ($lot['cash_out_pending'] ?? null) : null;
            if (! is_array($pending)) {
                continue;
            }
            if ((int) $position->trims_count > (int) ($pending['trims_count_at_emit'] ?? -1)) {
                // Hoisted above the branch and guarded the same way on both reads (round-5 review,
                // BLOCKER): a legacy record written before qty_at_emit/sell_qty both existed can
                // carry neither, and reading $pending['sell_qty'] unguarded on every confirmed
                // cash-out threw "Undefined array key" — which escapes riskV2() into
                // Desk::riskSweep() outside its own try, so one poisoned position's meta stopped
                // every later position in the sweep from being managed at all. Falls back to
                // lot_qty (a cash-out with no recorded intent sold the whole lot, the pre-qty_at_emit
                // behaviour), never null.
                $sellQty = (float) ($pending['sell_qty'] ?? $pending['lot_qty'] ?? 0.0);
                $lotQty = (float) ($pending['lot_qty'] ?? 0.0);
                // Clamped to the INTENDED sell_qty the same way reconcilePendingRung() clamps its
                // own actualQty: an out-of-band trim between emit and reconcile (a separate rung, a
                // manual close) can shrink position.quantity by more than this cash-out ever asked
                // for, and an unclamped delta went negative sold amounts into the ladder fold below
                // (round-4 review — proven: a 4.0 intended sell computed as an 8.0 real shrink,
                // driving ladder.original_qty from 10.0 to 6.0). `qty_at_emit` missing at all means
                // this is a pre-fix in-flight record with no snapshot to diff against — fall back to
                // trusting the intended sell_qty outright, the behaviour before that snapshot existed.
                $soldQty = array_key_exists('qty_at_emit', $pending)
                    ? max(0.0, min($sellQty, (float) $pending['qty_at_emit'] - (float) $position->quantity))
                    : $sellQty;
                $list[$i]['cashed_out'] = true;
                if (($pending['remainder'] ?? 'runner') === 'ladder' && ! ($pending['reset_on_add'] ?? true)) {
                    $meta['v2']['ladder']['original_qty'] = (float) ($meta['v2']['ladder']['original_qty'] ?? 0)
                        + ($lotQty - $soldQty);
                }
            }
            unset($list[$i]['cash_out_pending']);
            $changed = true;
        }
        if ($changed) {
            $meta['v2']['reentries'] = $list;
            $position->meta = $meta;
        }
    }

    /** stop.rules -> stop.pct_from_avg -> stop.time_hours, first match wins. */
    private function stopDecision(Position $position, ProductStats $stats, array $stop, DeskContext $ctx, string $tf): ?RiskDecision
    {
        if ($stop === []) {
            return null;
        }
        foreach ($stop['rules'] ?? [] as $rule) {
            if (JsonRuleEvaluator::fires($rule, $stats, $position, $ctx, $tf)) {
                $avg6 = $stats->volumeH24Usd / 4;

                return RiskDecision::close(
                    'stop.'.($rule['field'] ?? 'rule'), $stats->volumeH6Usd, $avg6, $avg6 > 0 ? $stats->volumeH6Usd / $avg6 : null,
                    sprintf('stop rule fired: %s %s %s', $rule['field'] ?? '?', $rule['op'] ?? '?', json_encode($rule['value'] ?? null)),
                );
            }
        }
        if (isset($stop['pct_from_avg']) && is_numeric($stop['pct_from_avg'])) {
            $anchor = $stop['anchor'] ?? 'avg';
            $anchorPrice = $anchor === 'entry' ? (float) ($position->meta['v2']['entry_price'] ?? $position->entry_price) : self::avg($position);
            $dir = $position->dir();
            // Bar-range aware, mirroring the ladder's own high/low check (docs/STRATEGY_SCHEMA_V2.md,
            // "Evaluation order" — "bar high/low aware in backtests"): live stats carry no bar_high/
            // bar_low, so $extreme falls back to $stats->price and this is byte-identical to the old
            // close-only check there.
            $stopPrice = $anchorPrice > 0 ? $anchorPrice * (1 - $dir * (float) $stop['pct_from_avg'] / 100) : null;
            $extreme = (float) ($dir === 1 ? ($stats->extra['bar_low'] ?? $stats->price) : ($stats->extra['bar_high'] ?? $stats->price));
            $touched = $stopPrice !== null && ($dir === 1 ? $extreme <= $stopPrice : $extreme >= $stopPrice);
            if ($touched) {
                $pnlPct = $anchorPrice > 0 ? $dir * ($stats->price / $anchorPrice - 1) * 100 : 0.0;

                // Carries the stop level through so a backtest fills AT the stop (or worse, if the bar
                // closed through it) instead of at the bar's close — see Backtester::simulate()'s
                // $stopMeta clamp, which reads this same key.
                return RiskDecision::close(
                    'stop.pct_from_avg', $stats->volumeH6Usd, $stats->volumeH24Usd / 4, $stats->volumeRatio6h(),
                    sprintf('%s %.6f touched stop %.6f (close pnl %.2f%% from %s)', $dir === 1 ? 'bar low' : 'bar high', $extreme, $stopPrice, $pnlPct, $anchor),
                    ['stop_price' => $stopPrice],
                );
            }
        }
        if (isset($stop['time_hours']) && is_numeric($stop['time_hours'])) {
            $held = $position->opened_at->diffInMinutes($ctx->now()) / 60;
            if ($held >= (float) $stop['time_hours']) {
                return RiskDecision::close(
                    'stop.time_hours', $stats->volumeH6Usd, $stats->volumeH24Usd / 4, $stats->volumeRatio6h(),
                    sprintf('held %.1fh >= %.0fh', $held, (float) $stop['time_hours']),
                );
            }
        }

        return null;
    }

    /** v1-compat close-only rules kept on migration (SchemaMigrator::v1ToV2(), "Migration v1 -> v2": `exit.take_profit.rules` -> `take_profit.rules`) — validated by StrategySchemaValidator but otherwise dead until this reads them. */
    private function takeProfitRulesDecision(Position $position, ProductStats $stats, array $takeProfit, DeskContext $ctx, string $tf): ?RiskDecision
    {
        foreach ($takeProfit['rules'] ?? [] as $rule) {
            if (JsonRuleEvaluator::fires($rule, $stats, $position, $ctx, $tf)) {
                $avg6 = $stats->volumeH24Usd / 4;

                return RiskDecision::close(
                    'take_profit.rules.'.($rule['field'] ?? 'rule'), $stats->volumeH6Usd, $avg6, $avg6 > 0 ? $stats->volumeH6Usd / $avg6 : null,
                    sprintf('take_profit rule fired: %s %s %s', $rule['field'] ?? '?', $rule['op'] ?? '?', json_encode($rule['value'] ?? null)),
                );
            }
        }

        return null;
    }

    /**
     * The oldest confirmed re-bought lot not yet cashed out: closed (via TRIM, sized as a fraction
     * of the CURRENT quantity) once it is green after fees. Only the oldest is ever considered per
     * call — a newer lot already being green does not jump the FIFO queue.
     */
    private function cashOutDecision(Position $position, ProductStats $stats, array $reentry, array $def, DeskContext $ctx): ?RiskDecision
    {
        $cashOut = is_array($reentry['cash_out'] ?? null) ? $reentry['cash_out'] : null;
        if ($cashOut === null) {
            return null;
        }
        $takeProfit = is_array($def['take_profit'] ?? null) ? $def['take_profit'] : [];
        $list = $position->meta['v2']['reentries'] ?? null;
        if (! is_array($list)) {
            return null;
        }
        foreach ($list as $i => $lot) {
            if (! is_array($lot) || ($lot['confirmed'] ?? true) === false || ! empty($lot['cashed_out'])) {
                continue;
            }
            if (is_array($lot['cash_out_pending'] ?? null)) {
                return null;   // waiting on the last TRIM to confirm or drop (reconcilePendingCashOut)
            }

            $takerFraction = $this->takerFeePct($def, $ctx) / 100;
            if (! self::greenAfterFees($position, $stats->price, (float) $lot['price'], (float) $lot['qty'], $takerFraction)) {
                return null;
            }

            $sellPct = is_numeric($cashOut['sell_pct_of_reentry'] ?? null) ? (float) $cashOut['sell_pct_of_reentry'] : 100.0;
            $lotQty = (float) $lot['qty'];
            $sellQty = min($lotQty, $lotQty * $sellPct / 100);
            $fraction = $position->quantity > 0 ? min(1.0, $sellQty / $position->quantity) : 0.0;
            if ($fraction <= 0) {
                return null;
            }

            // Mirrors the ladder rung mechanism (reconcilePendingRung): don't retire the lot's risk
            // (`cashed_out: true`) until reconcilePendingCashOut() sees trims_count actually advance —
            // a TRIM this emits can still fail to fill (whole-contract flooring, a rejected order),
            // which must re-arm the same lot rather than permanently drop its remaining risk.
            $resetOnAdd = ! array_key_exists('reset_on_add', $takeProfit) || (bool) $takeProfit['reset_on_add'];
            $meta = $position->meta ?? [];
            $meta['v2']['reentries'][$i]['cash_out_pending'] = [
                'trims_count_at_emit' => (int) $position->trims_count,
                'qty_at_emit' => (float) $position->quantity,
                'lot_qty' => $lotQty, 'sell_qty' => $sellQty,
                'remainder' => $cashOut['remainder'] ?? 'runner', 'reset_on_add' => $resetOnAdd,
            ];
            $position->meta = $meta;

            return RiskDecision::trim(
                "reentry.cash_out.{$i}", $fraction, null,
                sprintf('re-bought lot #%d green after fees (in @ %.6f, now @ %.6f) — selling %.0f%%', $i, (float) $lot['price'], $stats->price, $sellPct),
            );
        }

        return null;
    }

    /** The next unfired ladder rung, if the bar reached it (bar high/low aware in backtests — $stats->extra['bar_high'/'bar_low']). */
    private function ladderDecision(Position $position, ProductStats $stats, array $takeProfit, array $ladder, array $stop = []): ?RiskDecision
    {
        $rungs = is_array($takeProfit['ladder'] ?? null) ? $takeProfit['ladder'] : [];
        $nextIdx = count($ladder['fired']);
        $rung = $rungs[$nextIdx] ?? null;
        if ($rung === null) {
            return null;
        }

        $avg = self::avg($position);
        $dir = $position->dir();
        $atPct = (float) $rung['at_pct'];
        $target = $avg * (1 + $dir * $atPct / 100);
        $high = (float) ($stats->extra['bar_high'] ?? $stats->price);
        $low = (float) ($stats->extra['bar_low'] ?? $stats->price);
        $reached = $dir === 1 ? $high >= $target : $low <= $target;
        if (! $reached) {
            return null;
        }

        $sellPct = (float) ($rung['sell_pct_of_original'] ?? 0);
        $sellQty = min((float) $ladder['original_qty'] * $sellPct / 100, (float) $position->quantity);
        if ($sellQty <= 0 || $position->quantity <= 0) {
            return null;
        }

        $meta = $position->meta ?? [];
        $meta['v2']['ladder']['pending'] = [
            'idx' => $nextIdx, 'qty' => $sellQty, 'price' => $target, 'trims_count_at_emit' => (int) $position->trims_count,
            'qty_at_emit' => (float) $position->quantity,
        ];
        $position->meta = $meta;

        // Attach the fail-safe's price level (not a decision — Backtester's own conservative touch
        // policy decides whether it beat this rung to the punch intrabar) so a bar whose low pierced
        // the stop but closed above it doesn't silently bank the rung's profit instead of the loss.
        $decisionMeta = [];
        if (isset($stop['pct_from_avg']) && is_numeric($stop['pct_from_avg'])) {
            $anchor = $stop['anchor'] ?? 'avg';
            $anchorPrice = $anchor === 'entry' ? (float) ($position->meta['v2']['entry_price'] ?? $position->entry_price) : $avg;
            $decisionMeta['stop_price'] = $anchorPrice * (1 - $dir * (float) $stop['pct_from_avg'] / 100);
        }

        return RiskDecision::trim(
            "take_profit.ladder.{$nextIdx}", $sellQty / $position->quantity, $target,
            sprintf('rung %d reached (%+.2f%% from avg) — selling %.0f%% of the original position', $nextIdx, $atPct, $sellPct),
            $decisionMeta,
        );
    }

    /** Arms and buys a re-entry once a rung has fired, price has retraced enough, spacing clears fees, the signal (if any) holds, and the position hasn't hit max_per_position. */
    private function reentryArmDecision(Position $position, ProductStats $stats, array $def, array $reentry, array $takeProfit, array $ladder, DeskContext $ctx, string $tf): ?RiskDecision
    {
        $reentries = is_array($position->meta['v2']['reentries'] ?? null) ? $position->meta['v2']['reentries'] : [];
        if (array_any($reentries, fn ($r) => is_array($r) && ($r['confirmed'] ?? true) === false)) {
            return null;   // still waiting on the last one to confirm or drop
        }
        if (is_array($position->meta['v2']['adds_pending'] ?? null)) {
            // Mirrors the guard in addsDecisionV2(): an unconfirmed `adds` rung is waiting on this
            // same adds_count counter (reconcilePendingAddsRung()) — arming a re-entry here would
            // advance it for the wrong reason. Same defence-in-depth: reconcile already runs first
            // each call, so this window is normally already closed.
            return null;
        }
        $maxPer = self::isPositiveInt($reentry['max_per_position'] ?? null) ? (int) $reentry['max_per_position'] : 3;
        if (count($reentries) >= $maxPer) {
            return null;
        }

        $afterRungs = self::isPositiveInt($reentry['after_rungs'] ?? null) ? (int) $reentry['after_rungs'] : 1;
        $fired = $ladder['fired'];
        if (count($fired) < $afterRungs) {
            return null;
        }

        $rungs = is_array($takeProfit['ladder'] ?? null) ? $takeProfit['ladder'] : [];
        $lastFiredIdx = $fired[count($fired) - 1];
        $lastRung = $rungs[$lastFiredIdx] ?? null;
        $sold = $ladder['sold'][$lastFiredIdx] ?? null;
        if ($lastRung === null || $sold === null) {
            return null;
        }
        $salePrice = (float) $sold['price'];
        $soldQty = (float) $sold['qty'];
        if ($salePrice <= 0 || $soldQty <= 0) {
            return null;
        }

        $prevAtPct = count($fired) >= 2 ? (float) ($rungs[$fired[count($fired) - 2]]['at_pct'] ?? 0) : 0.0;
        $spacingPct = (float) $lastRung['at_pct'] - $prevAtPct;
        if ($spacingPct <= 0) {
            return null;
        }

        $retrace = is_array($reentry['retrace'] ?? null) ? $reentry['retrace'] : [];
        $retraceOf = $retrace['of'] ?? 'rung_spacing';
        $retraceMin = is_numeric($retrace['min'] ?? null) ? (float) $retrace['min'] : 0.0;
        $requiredRetracePct = $retraceOf === 'pct' ? $retraceMin : $retraceMin * $spacingPct;

        $avg = self::avg($position);
        $dir = $position->dir();
        $price = $stats->price;
        $retracedPct = $avg > 0 ? $dir * ($salePrice - $price) / $avg * 100 : 0.0;
        if ($retracedPct < $requiredRetracePct) {
            return null;
        }

        $stayAboveAvg = ! array_key_exists('stay_above_avg', $retrace) || (bool) $retrace['stay_above_avg'];
        if ($stayAboveAvg && ! ($dir === 1 ? $price > $avg : $price < $avg)) {
            return null;
        }

        $minSpacingXFees = is_numeric($reentry['min_spacing_x_fees'] ?? null) ? (float) $reentry['min_spacing_x_fees'] : 3.0;
        if ($minSpacingXFees > 0 && $spacingPct < $minSpacingXFees * $this->feesRoundTripPct($def, $ctx)) {
            return null;
        }

        if (! $this->reentrySignalHolds($reentry['when'] ?? 'entry', $def, $stats, $position, $ctx, $tf)) {
            return null;
        }

        $sizing = is_array($reentry['size'] ?? null) ? $reentry['size'] : null;
        if ($sizing === null) {
            return null;
        }
        $dollars = $this->sizingDollars($sizing, 'reentry', $ctx, $def, null, $price, $position, null, [
            'spacing_pct' => $spacingPct, 'retrace_pct' => $retracedPct,
            'sold_qty' => $soldQty, 'sold_usd' => $soldQty * $salePrice,
        ]);
        if ($dollars === null || $dollars <= 0) {
            return null;
        }
        // size.min_ticket_usd applies here exactly as it does at entry (docs/STRATEGY_SCHEMA_V2.md,
        // "Sizing objects"); risk.leverage_cap does not — clamping it needs the book's equity and
        // exposure (Bank), which the Strategy contract never hands to risk() (see sizingDollars()'s
        // own docblock on that same gap).
        if ($dollars < (float) $ctx->param('size.min_ticket_usd', 10)) {
            return null;
        }
        $qty = $dollars / $price;   // sizingDollars() always returns dollars; the pending-lot record keeps the quantity

        $meta = $position->meta ?? [];
        $list = is_array($meta['v2']['reentries'] ?? null) ? $meta['v2']['reentries'] : [];
        $list[] = [
            // 'qty'/'price' are the INTENDED fill, informational only until reconcilePendingReentry()
            // overwrites them from the position's actual post-fill state (real slippage + fee-adjusted
            // qty) — nothing reads them while 'confirmed' is false.
            'qty' => $qty, 'price' => $price, 'fees_usd' => $qty * $price * ($this->takerFeePct($def, $ctx) / 100),
            'cashed_out' => false, 'rung' => $lastFiredIdx, 'adds_count_at_emit' => (int) $position->adds_count,
            'qty_at_emit' => (float) $position->quantity, 'entry_usd_at_emit' => (float) $position->entry_usd,
            'fees_usd_at_emit' => (float) $position->fees_usd,
            'entry_fee_excl_at_emit' => (float) ($meta['entry_fee_excluded'] ?? 0),
            'confirmed' => false,
        ];
        $meta['v2']['reentries'] = $list;
        $position->meta = $meta;

        return RiskDecision::add(
            'reentry', $dollars, $price,
            sprintf('re-entry armed: retraced %.4f%% (need %.4f%%) — buying %.8f units', $retracedPct, $requiredRetracePct, $qty),
        );
    }

    /** @param mixed $when "entry" (reuse entry.when), an array of signal names, or null (price only) */
    private function reentrySignalHolds(mixed $when, array $def, ProductStats $stats, Position $position, DeskContext $ctx, string $tf): bool
    {
        if ($when === null) {
            return true;
        }
        $names = $when === 'entry'
            ? (is_array($def['entry']['when'] ?? null) ? $def['entry']['when'] : [])
            : (is_array($when) ? $when : []);
        $signals = is_array($def['signals'] ?? null) ? $def['signals'] : [];

        return array_all($names, fn ($name) => self::evalSignal($signals[$name] ?? null, $stats, $position, $ctx, $tf));
    }

    /**
     * `adds`, as v1: the next rung, indexed by its own `meta.v2.adds_fired` counter, not the shared
     * `position.adds_count` a confirmed re-entry buy also advances (both go through
     * Desk::bookAdd()) — indexing by the shared counter let every confirmed re-entry silently
     * consume (skip) one `adds` rung.
     */
    private function addsDecisionV2(Position $position, ProductStats $stats, array $adds, array $def, DeskContext $ctx, string $tf): ?RiskDecision
    {
        if ($adds === []) {
            return null;
        }
        $reentries = is_array($position->meta['v2']['reentries'] ?? null) ? $position->meta['v2']['reentries'] : [];
        if (array_any($reentries, fn ($r) => is_array($r) && ($r['confirmed'] ?? true) === false)) {
            // An unconfirmed re-entry lot is waiting on THIS SAME adds_count counter to advance
            // (reconcilePendingReentry()). Firing an unrelated `adds` rung here would advance it for
            // the wrong reason, muddying which pending record the next confirm belongs to —
            // reconcilePendingAddsRung()/reconcilePendingReentry() cannot tell the two apart from
            // the counter alone. In practice this window is already closed by the time this runs
            // (reconcile runs first each call), kept as a second line of defence against reordering
            // either function.
            return null;
        }
        if (is_array($position->meta['v2']['adds_pending'] ?? null)) {
            return null;   // still waiting on the last adds rung to confirm or drop
        }
        $idx = (int) ($position->meta['v2']['adds_fired'] ?? 0);
        $rung = $adds[$idx] ?? null;
        if (! is_array($rung)) {
            return null;
        }
        $trigger = $rung['trigger'] ?? null;
        if (! is_array($trigger) || ! JsonRuleEvaluator::fires($trigger, $stats, $position, $ctx, $tf)) {
            return null;
        }

        $dollars = null;
        if (isset($rung['size_pct']) && is_numeric($rung['size_pct'])) {
            $basis = (float) ($position->meta['initial_cost_usd'] ?? $position->entry_usd);
            $dollars = $basis * (float) $rung['size_pct'] / 100;
        } elseif (is_array($rung['size'] ?? null)) {
            $dollars = $this->sizingDollars($rung['size'], 'adds', $ctx, $def, null, $stats->price, $position);
        }
        if ($dollars === null || $dollars <= 0) {
            return null;
        }
        // size.min_ticket_usd applies here exactly as it does at entry — see the matching comment in
        // reentryArmDecision() for why risk.leverage_cap does not.
        if ($dollars < (float) $ctx->param('size.min_ticket_usd', 10)) {
            return null;
        }

        $meta = $position->meta ?? [];
        $meta['v2']['adds_pending'] = ['idx' => $idx, 'adds_count_at_emit' => (int) $position->adds_count];
        $position->meta = $meta;

        return RiskDecision::add("adds.{$idx}", $dollars, $stats->price, sprintf('add rung %d triggered — $%.2f', $idx, $dollars));
    }

    /** Once the ladder is exhausted or peak pnl has reached activate_pct (last rung's at_pct, by default), close the remainder on a giveback_pct pullback from the peak — the same peak/pnl idiom MeanReversionStrategy's own trailing stop uses. */
    private function runnerTtpDecision(Position $position, ProductStats $stats, array $takeProfit, array $ladder): ?RiskDecision
    {
        $runner = is_array($takeProfit['runner']['ttp'] ?? null) ? $takeProfit['runner']['ttp'] : null;
        if ($runner === null) {
            return null;
        }
        $givebackPct = is_numeric($runner['giveback_pct'] ?? null) ? (float) $runner['giveback_pct'] : 0.0;
        if ($givebackPct <= 0) {
            return null;
        }

        $rungs = is_array($takeProfit['ladder'] ?? null) ? $takeProfit['ladder'] : [];
        $defaultActivate = $rungs !== [] ? (float) end($rungs)['at_pct'] : 0.0;
        $activatePct = is_numeric($runner['activate_pct'] ?? null) ? (float) $runner['activate_pct'] : $defaultActivate;

        $avg = self::avg($position);
        // The ladder's own peak (rebuildLadderState(), rebased on every reset_on_add:true rearm) —
        // $position->peak_price is only the seed for a ladder that has never been rebuilt yet.
        $peak = (float) ($ladder['peak_price'] ?? $position->peak_price ?? $avg);
        $peakPct = $avg > 0 ? $position->dir() * ($peak / $avg - 1) * 100 : 0.0;
        if ($peakPct < $activatePct) {
            return null;
        }

        // Priced off the SAME $avg as $peakPct — Position::unrealisedPnlPct() divides by entry_usd,
        // which is fee-INCLUSIVE on the spot path (see avg()'s docblock), so comparing it against a
        // fee-EXCLUSIVE peakPct silently shrinks (or reverses the sign of) the effective giveback.
        $pnlPct = $avg > 0 ? $position->dir() * ($stats->price / $avg - 1) * 100 : 0.0;
        if ($pnlPct > $peakPct - $givebackPct) {
            return null;
        }

        return RiskDecision::close(
            'take_profit.runner.ttp', $stats->volumeH6Usd, $stats->volumeH24Usd / 4, $stats->volumeRatio6h(),
            sprintf('runner peak %+.2f%%, now %+.2f%% (giveback %.2f%%)', $peakPct, $pnlPct, $givebackPct),
        );
    }

    private static function isPositiveInt(mixed $v): bool
    {
        return is_int($v) && $v > 0;
    }

    /** `ind.*` fields default to the strategy's own meta.timeframe, falling back to 1h when unset. */
    private static function defaultTf(array $def): string
    {
        $tf = $def['meta']['timeframe'] ?? null;

        return is_string($tf) && $tf !== '' ? $tf : '1h';
    }

    private function peakPct(Position $p): float
    {
        return $p->entry_price > 0
            ? $p->dir() * ((float) ($p->peak_price ?? $p->entry_price) / $p->entry_price - 1) * 100
            : 0.0;
    }

    /**
     * risk.daily_loss_cap_pct's numerator/denominator: realised PnL on positions closed since UTC
     * midnight, plus unrealised PnL marked on every currently open position (the position risk() is
     * being called for uses $currentStats's fresh price; every other open position uses its last
     * known mark), as a percentage of the first bank snapshot taken today. Returns null when no
     * snapshot exists yet today — nothing to compare against, so the cap does not fire (fail-open,
     * not fail-closed: there is no "today" baseline until the first cycle snapshots one).
     *
     * Live/paper only. Backtester keeps positions and snapshots entirely in memory (never writes
     * them to the database — same reasoning as MeanReversionStrategy::inCooldown()), so this always
     * returns null in a backtest and the cap is never enforced there.
     */
    private function dailyPnlPct(DeskContext $ctx, ?Position $current = null, ?ProductStats $currentStats = null): ?float
    {
        $todayStart = CarbonImmutable::instance($ctx->now())->utc()->startOfDay();

        $startingEquity = (float) (BankSnapshot::where('mode', $ctx->mode)
            ->where('taken_at', '>=', $todayStart)
            ->orderBy('taken_at')
            ->value('equity') ?? 0);
        if ($startingEquity <= 0) {
            return null;
        }

        $realised = (float) Position::where('mode', $ctx->mode)
            ->where('status', 'closed')
            ->where('closed_at', '>=', $todayStart)
            ->sum('pnl_usd');

        $unrealised = 0.0;
        foreach ($ctx->openPositions as $p) {
            $price = ($current?->id !== null && $currentStats !== null && $p->id === $current->id)
                ? $currentStats->price
                : (float) ($p->last_price ?? $p->entry_price);
            $unrealised += $p->unrealisedPnl($price);
        }

        return ($realised + $unrealised) / $startingEquity * 100;
    }
}
