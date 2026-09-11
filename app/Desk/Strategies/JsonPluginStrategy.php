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
                return SchemaMigrator::migrate($version->definition);
            }
        }

        $key = $ctx->param('json.plugin_key');
        if (! is_string($key) || $key === '') {
            return null;
        }
        $plugin = StrategyPlugin::where('key', $key)->first();

        return $plugin === null ? null : SchemaMigrator::migrate($plugin->definition);
    }

    /** @param array<int, ProductStats> $universe */
    public function scan(array $universe, DeskContext $ctx): array
    {
        $def = $this->definition($ctx);
        if ($def === null) {
            return parent::scan($universe, $ctx);
        }

        $rules = [...($def['setup']['rules'] ?? []), ...($def['trigger']['rules'] ?? [])];
        $filtered = array_values(array_filter(
            $universe,
            fn (ProductStats $s) => array_all($rules, fn ($rule) => JsonRuleEvaluator::fires($rule, $s, null, $ctx)),
        ));

        $max = $def['trigger']['max_candidates'] ?? null;
        $out = parent::scan($filtered, $ctx);

        return is_int($max) && $max > 0 ? array_slice($out, 0, $max) : $out;
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

        foreach ($def['entry']['confirm'] ?? [] as $rule) {
            // A rule on missing data is skipped here (fail-open): scan already
            // excluded unmeasurable rows, and vet must not reject on unknown data.
            $field = (string) ($rule['field'] ?? '');
            if (JsonRuleEvaluator::value($field, $candidate->stats, null, $ctx) === null) {
                continue;
            }
            if (! JsonRuleEvaluator::fires($rule, $candidate->stats, null, $ctx)) {
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

        $size = parent::size($v, $bank, $ctx);
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

        foreach ($def['exit']['stop']['rules'] ?? [] as $rule) {
            if (JsonRuleEvaluator::fires($rule, $stats, $position, $ctx)) {
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
                if (is_array($trigger) && JsonRuleEvaluator::fires($trigger, $stats, $position, $ctx)) {
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
