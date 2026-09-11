<?php

declare(strict_types=1);

namespace App\Support;

class CandidateSummary
{
    /**
     * Abbreviated strategy param set for the optimizer firehose and candidate-space feeds. $p's
     * own dotted keys tell us which registered strategy produced it (the root before the first
     * "."); shorts read <root>.short.<key> first and fall back to <root>.<key> (same picker as
     * each strategy's own sp() helper).
     */
    public static function params(array $p, string $side): array
    {
        $root = self::detectRoot($p);
        $pick = fn (string $key) => $root === null ? null : ($p[$side === 'short' ? "{$root}.short.{$key}" : "{$root}.{$key}"] ?? $p["{$root}.{$key}"] ?? null);

        return [
            'tf' => $pick('timeframe'), 'x1' => $pick('engine.x1'), 'x2' => $pick('engine.x2'), 'base' => $pick('engine.base_minutes'),
            'tp' => $pick('tp_max_pct'), 'rungs' => $pick('tp_rungs'), 'stop' => $pick('short_stop_pct'), 'gate' => $pick('short_trend_ma'),
            'lev' => $pick('leverage'), 'qty' => $pick('qty_pct'), 'ma' => $pick('engine.ma_type'),
            'tsl' => $pick('tsl_pct'), 'ttp' => $pick('ttp_activate_pct'), 'ttpg' => $pick('ttp_giveback_pct'),
        ];
    }

    /** The registered strategy key that owns $p's dotted params, found from $p's own keys. */
    private static function detectRoot(array $p): ?string
    {
        foreach (array_keys((array) config('desk.strategies', [])) as $key) {
            foreach (array_keys($p) as $k) {
                if ($k === $key || str_starts_with($k, "{$key}.")) {
                    return $key;
                }
            }
        }

        return null;
    }
}
