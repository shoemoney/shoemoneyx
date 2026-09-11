<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Models\Position;

/**
 * Evaluates one strategy-plugin rule ({field, op, value}) against a live row.
 * A rule whose field is missing/null NEVER fires (fail-closed everywhere:
 * scan filters exclude, vet rules are skipped, risk-close rules don't fire).
 */
final class JsonRuleEvaluator
{
    /** Ops every rule grammar consumer (validators, exporters) can rely on. */
    public const OPS = ['<', '<=', '>', '>=', '==', '!=', 'between', 'in', 'not_in'];

    public static function value(string $field, ProductStats $s, ?Position $p, DeskContext $ctx): mixed
    {
        if ($field === 'position.pnl_pct' && $p !== null) {
            return $p->unrealisedPnlPct($s->price);
        }
        if ($field === 'position.hold_hours' && $p !== null) {
            return $p->opened_at->diffInMinutes($ctx->now()) / 60;
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

    /** True when the rule fires (field present and comparison holds). */
    public static function fires(array $rule, ProductStats $s, ?Position $p, DeskContext $ctx): bool
    {
        $actual = self::value((string) ($rule['field'] ?? ''), $s, $p, $ctx);
        if ($actual === null) {
            return false;
        }
        $expected = $rule['value'] ?? null;
        if ($expected === null) {
            return false;
        }

        return match ($rule['op'] ?? null) {
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
}
