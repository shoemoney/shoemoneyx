<?php

declare(strict_types=1);

namespace App\Services\Indicators;

use App\Desk\DeskContext;
use App\Desk\Strategies\IndicatorField;
use App\Models\Candle;

/**
 * Computes `ind.*` fields lazily off DeskContext::bars(), memoised per (product, timeframe,
 * indicator name+args, bar bucket) so the same rule checked twice in one pass — once as a
 * field, once as the `value: { field }` side of another rule, once per candidate in a scan,
 * once per step for as long as a bar stays the latest closed one — pays for the series once
 * per bar, not once per call. A strategy that never mentions an indicator never touches this.
 *
 * `previous()` (the bar before the latest closed one) is a separate, independently-memoised
 * accessor: only a crosses_* rule ever calls it, so an ordinary literal/field-ref rule never
 * pays to compute a series it will not read.
 *
 * Only bars closed strictly before `now` are used (`start + duration <= now`), matching the
 * house rule for sub-hour steps in Backtester::simulate() — an indicator never sees the bar
 * it is itself standing inside.
 */
final class IndicatorCache
{
    /** Bars of lookback fetched per computation — comfortably past the widest indicator (macd/adx need ~2x their longest period). */
    private const WARMUP_BARS = 400;

    /** Bounds each memo at products x timeframes x indicators x live bar buckets, not one entry per step for the life of a backtest. */
    private const MAX_ENTRIES = 512;

    /** @var array<string, array<string, float|bool|null>> */
    private static array $cache = [];

    /** @var array<string, array<string, float|bool|null>> */
    private static array $previousCache = [];

    /** Snapshot with the value on the latest closed bar, keyed by output name. */
    public static function current(DeskContext $ctx, string $productId, string $timeframe, IndicatorField $field, ?float $mark = null): array
    {
        [$tf, $dur, $bucket] = self::coords($ctx, $timeframe);
        $key = self::key($productId, $tf, $field, $bucket);

        if (! array_key_exists($key, self::$cache)) {
            if (count(self::$cache) > self::MAX_ENTRIES) {
                self::$cache = [];
            }
            // Every "now" inside [bucket, bucket + dur) sees the same closed bars, so the bucket's
            // own start is as good a cutoff as the real now — that is what makes bucketing safe.
            $bars = self::fetchBars($ctx, $productId, $tf, $dur, $bucket);
            self::$cache[$key] = self::valuesFor($bars, $field);
        }

        return self::withLiveAtrPct(self::$cache[$key], $field, $mark);
    }

    /** Snapshot on the bar before the latest closed one — [] when there is no such bar yet. */
    public static function previous(DeskContext $ctx, string $productId, string $timeframe, IndicatorField $field, ?float $mark = null): array
    {
        [$tf, $dur, $bucket] = self::coords($ctx, $timeframe);
        $key = self::key($productId, $tf, $field, $bucket);

        if (! array_key_exists($key, self::$previousCache)) {
            if (count(self::$previousCache) > self::MAX_ENTRIES) {
                self::$previousCache = [];
            }
            $bars = self::fetchBars($ctx, $productId, $tf, $dur, $bucket);
            self::$previousCache[$key] = count($bars) > 1 ? self::valuesFor(array_slice($bars, 0, -1), $field) : [];
        }

        return self::withLiveAtrPct(self::$previousCache[$key], $field, $mark);
    }

    /** Test isolation: the static cache lives outside the container and would otherwise leak between tests. */
    public static function forgetAll(): void
    {
        self::$cache = [];
        self::$previousCache = [];
    }

    /** @return array{0: string, 1: int, 2: int} normalised timeframe, its duration, and the start of the bucket `now` falls in. */
    private static function coords(DeskContext $ctx, string $timeframe): array
    {
        $tf = self::normalizeTimeframe($timeframe);
        $dur = Candle::DURATIONS[$tf] ?? 3600;
        $now = $ctx->now()->getTimestamp();

        return [$tf, $dur, intdiv($now, $dur) * $dur];
    }

    private static function key(string $productId, string $tf, IndicatorField $field, int $bucket): string
    {
        return strtoupper($productId).'|'.$tf.'|'.$field->seriesKey().'|'.$bucket;
    }

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    private static function fetchBars(DeskContext $ctx, string $productId, string $tf, int $dur, int $now): array
    {
        $raw = $ctx->bars($productId, $tf, $now - self::WARMUP_BARS * $dur, $now);

        return array_values(array_filter($raw, static fn (array $b): bool => ($b['start'] + $dur) <= $now));
    }

    /** @param array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> $bars @return array<string, float|bool|null> */
    private static function valuesFor(array $bars, IndicatorField $field): array
    {
        if ($field->name === 'smx') {
            $series = Smx::compute($bars);
            $out = Smx::latest($series, 0);
            if ($out === []) {
                return [];
            }
            $i = count($series['wt1']) - 1;
            $out['div_bull'] = (bool) ($series['div_bull'][$i] ?? false);
            $out['div_bear'] = (bool) ($series['div_bear'][$i] ?? false);

            return $out;
        }

        $closes = array_map(static fn (array $b): float => (float) $b['close'], $bars);
        $args = $field->args;

        return match ($field->name) {
            'rsi' => ['value' => Indicators::rsi($closes, (int) $args[0])],
            'sma' => ['value' => Indicators::sma($closes, (int) $args[0])],
            'ema' => ['value' => Indicators::ema($closes, (int) $args[0])],
            // .pct is never cached here — it is derived from the live mark at read time (withLiveAtrPct).
            'atr' => ['value' => Indicators::atr($bars, (int) $args[0])],
            'adx' => ['value' => Indicators::adx($bars, (int) $args[0])],
            'macd' => Indicators::macd($closes, (int) $args[0], (int) $args[1], (int) $args[2]),
            'bb' => Indicators::bb($closes, (int) $args[0], (float) $args[1]),
            'vwap' => ['value' => Indicators::vwap($bars)],
            'obv' => ['value' => Indicators::obv($bars)],
            default => [],
        };
    }

    /**
     * atr.pct is ATR ÷ the live mark, computed fresh on every read rather than cached: the mark
     * moves within a bar (many reads can share one bucket's cached raw ATR), so caching pct
     * against whichever mark happened to be current on the first read of the bucket would make
     * it stale for every read after.
     */
    private static function withLiveAtrPct(array $values, IndicatorField $field, ?float $mark): array
    {
        if ($field->name !== 'atr' || $values === []) {
            return $values;
        }
        $atr = $values['value'] ?? null;
        $values['pct'] = ($atr !== null && $mark !== null && $mark != 0.0) ? $atr / $mark * 100 : null;

        return $values;
    }

    /** Case-insensitive match against Candle::DURATIONS's canonical keys — the schema writes "1h"/"15m", the store keys candles "1H"/"15m". */
    private static function normalizeTimeframe(string $tf): string
    {
        return Candle::canonicalTimeframe($tf) ?? $tf;
    }
}
