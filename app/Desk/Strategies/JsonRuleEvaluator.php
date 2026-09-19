<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Models\Position;
use App\Services\Indicators\IndicatorCache;

/**
 * Evaluates one strategy-plugin rule ({field, op, value}) against a live row.
 * A rule whose field is missing/null NEVER fires (fail-closed everywhere:
 * scan filters exclude, vet rules are skipped, risk-close rules don't fire).
 */
final class JsonRuleEvaluator
{
    /** Ops every rule grammar consumer (validators, exporters) can rely on. */
    public const OPS = ['<', '<=', '>', '>=', '==', '!=', 'between', 'in', 'not_in', 'crosses_above', 'crosses_below'];

    /**
     * @param  string  $tf  timeframe an `ind.*` field computes on; the caller resolves the
     *                      rule's own `tf` override / the strategy's meta.timeframe / '1h'.
     */
    public static function value(string $field, ProductStats $s, ?Position $p, DeskContext $ctx, string $tf = '1h'): mixed
    {
        if ($p !== null && str_starts_with($field, 'position.')) {
            return match ($field) {
                'position.pnl_pct' => $p->unrealisedPnlPct($s->price),
                'position.hold_hours' => $p->opened_at->diffInMinutes($ctx->now()) / 60,
                // v2 additions (docs/STRATEGY_SCHEMA_V2.md, "Field-to-field comparison and crosses"):
                // avg/peak_pct read the v2 engine's OWN avg (JsonPluginStrategy::avg()), not
                // $p->entry_price — Desk::bookAdd()/Backtester's bookAdd recompute entry_price as
                // entry_usd / quantity on every add, a fee-INCLUSIVE cost over a fee-adjusted
                // quantity that jumps by roughly one taker fee with no price move at all. Reading
                // entry_price here would let a rule (legal in stop.rules/take_profit.rules/
                // reentry.when) see a different, fee-polluted number than stop.pct_from_avg, the
                // ladder targets, and runnerTtpDecision() all read from the same position.
                'position.avg' => JsonPluginStrategy::avg($p),
                // Reads the ladder's own rebased peak (JsonPluginStrategy::runnerTtpDecision(),
                // docs/STRATEGY_SCHEMA_V2.md review round 2) — $p->peak_price is the position's
                // ALL-TIME high and is stale after an add that lowers avg without a new price move,
                // which would stale-arm a stop.rules/take_profit.rules/reentry.when rule reading
                // position.peak_pct the same way it once stale-armed the runner.
                'position.peak_pct' => ($avg = JsonPluginStrategy::avg($p)) > 0
                    ? $p->dir() * ((float) ($p->meta['v2']['ladder']['peak_price'] ?? $p->peak_price ?? $avg) / $avg - 1) * 100
                    : 0.0,
                'position.rungs_fired' => count($p->meta['v2']['ladder']['fired'] ?? []),
                'position.reentries' => count($p->meta['v2']['reentries'] ?? []),
                'position.adds_count' => (int) $p->adds_count,
                'position.trims_count' => (int) $p->trims_count,
                default => null,
            };
        }
        if ($p === null && str_starts_with($field, 'position.')) {
            return null;
        }
        if ($field === 'time.hour_utc') {
            return (int) $ctx->now()->format('G');
        }
        if ($field === 'time.weekday') {
            // PHP's `w`: 0 = Sunday … 6 = Saturday.
            return (int) $ctx->now()->format('w');
        }
        if (str_starts_with($field, 'ind.')) {
            return self::indicatorValue($field, $s, $ctx, $tf, false);
        }

        $row = $s->jsonSerialize();

        if (array_key_exists($field, $row)) {
            return $row[$field];
        }
        if (str_starts_with($field, 'indicators.')) {
            return $s->extra['indicators'][substr($field, strlen('indicators.'))] ?? null;
        }
        if (str_starts_with($field, 'extra.')) {
            $cur = $s->extra;
            foreach (explode('.', substr($field, strlen('extra.'))) as $seg) {
                if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                    return null;
                }
                $cur = $cur[$seg];
            }

            return is_scalar($cur) ? $cur : null;
        }

        return null;
    }

    /**
     * True when the rule fires (field present and comparison holds).
     *
     * @param  string  $defaultTf  the strategy's meta.timeframe (or '1h'); overridden by the rule's own "tf".
     */
    public static function fires(array $rule, ProductStats $s, ?Position $p, DeskContext $ctx, string $defaultTf = '1h'): bool
    {
        $field = (string) ($rule['field'] ?? '');
        $tf = is_string($rule['tf'] ?? null) && $rule['tf'] !== '' ? $rule['tf'] : $defaultTf;

        $actual = self::value($field, $s, $p, $ctx, $tf);
        if ($actual === null) {
            return false;
        }

        // {value: {field: "..."}} — compare against another field instead of a literal (v2 rules grammar).
        $raw = $rule['value'] ?? null;
        $isFieldRef = is_array($raw) && is_string($raw['field'] ?? null);
        $expected = $isFieldRef ? self::value($raw['field'], $s, $p, $ctx, $tf) : $raw;
        if ($expected === null) {
            return false;
        }

        $op = $rule['op'] ?? null;

        if ($op === 'crosses_above' || $op === 'crosses_below') {
            return self::crosses($op, $field, $isFieldRef ? $raw['field'] : null, $actual, $expected, $s, $ctx, $tf);
        }

        return match ($op) {
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            // {value: [lo, hi]}, inclusive — session-hour / regime-band filters.
            'between' => is_array($expected) && count($expected) === 2 && is_numeric($actual)
                && $actual >= $expected[0] && $actual <= $expected[1],
            // {value: [...]} — categorical/regime membership.
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && $expected !== [] && ! in_array($actual, $expected, false),
            default => false,
        };
    }

    /**
     * crosses_above: field was <= value on the previous bar and is > value now; crosses_below
     * mirrors it. "Previous" only exists for `ind.*` fields (they alone have a bar series
     * behind them) — a rule against anything else, or a strategy with no previous bar yet
     * (its first evaluation), never fires: fail-closed, per the class-level contract.
     */
    private static function crosses(string $op, string $field, ?string $expectedField, mixed $actual, mixed $expected, ProductStats $s, DeskContext $ctx, string $tf): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }
        $prevActual = self::previousValue($field, $ctx, $tf, $s);
        $prevExpected = $expectedField !== null ? self::previousValue($expectedField, $ctx, $tf, $s) : $expected;
        if (! is_numeric($prevActual) || ! is_numeric($prevExpected)) {
            return false;
        }

        return $op === 'crosses_above'
            ? ((float) $prevActual <= (float) $prevExpected && (float) $actual > (float) $expected)
            : ((float) $prevActual >= (float) $prevExpected && (float) $actual < (float) $expected);
    }

    /** The same field's value as of the bar before the latest closed one — null (never fires) for anything but an `ind.*` field. */
    private static function previousValue(string $field, DeskContext $ctx, string $tf, ProductStats $s): mixed
    {
        return str_starts_with($field, 'ind.') ? self::indicatorValue($field, $s, $ctx, $tf, true) : null;
    }

    private static function indicatorValue(string $field, ProductStats $s, DeskContext $ctx, string $tf, bool $previous): mixed
    {
        try {
            $parsed = IndicatorField::parse($field);
        } catch (\InvalidArgumentException) {
            return null;   // malformed/unknown ind.* field: fail closed, never a runtime guess
        }
        if ($parsed === null) {
            return null;
        }

        $values = $previous
            ? IndicatorCache::previous($ctx, $s->productId, $tf, $parsed, $s->price)
            : IndicatorCache::current($ctx, $s->productId, $tf, $parsed, $s->price);

        return $values[$parsed->output] ?? null;
    }
}
