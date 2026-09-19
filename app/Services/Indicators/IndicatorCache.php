<?php

declare(strict_types=1);

namespace App\Services\Indicators;

use App\Desk\DeskContext;
use App\Desk\Strategies\IndicatorField;
use App\Models\Candle;

/**
 * Computes `ind.*` fields lazily off DeskContext::bars(), memoised per (product, timeframe,
 * indicator name+args, as-of instant) so the same rule checked twice in one pass — once as a
 * field, once as the `value: { field }` side of another rule, once per candidate in a scan —
 * pays for the series once. A strategy that never mentions an indicator never touches this.
 *
 * Only bars closed strictly before `now` are used (`start + duration <= now`), matching the
 * house rule for sub-hour steps in Backtester::simulate() — an indicator never sees the bar
 * it is itself standing inside.
 */
final class IndicatorCache
{
    /** Bars of lookback fetched per computation — comfortably past the widest indicator (macd/adx need ~2x their longest period). */
    private const WARMUP_BARS = 400;

    /** @var array<string, array{current: array<string, float|bool|null>, previous: array<string, float|bool|null>}> */
    private static array $cache = [];

    /** The `now` instant the cache above was built for — a change evicts it, bounding the map at products x timeframes x indicators for the current bar instead of growing one entry per step for the life of a backtest. */
    private static ?int $asOf = null;

    /** Snapshot with both the value on the latest closed bar and on the one before it, keyed by output name. */
    public static function snapshot(DeskContext $ctx, string $productId, string $timeframe, IndicatorField $field, ?float $mark = null): array
    {
        $tf = self::normalizeTimeframe($timeframe);
        $now = $ctx->now()->getTimestamp();
        if (self::$asOf !== $now) {
            self::$cache = [];
            self::$asOf = $now;
        }
        $key = strtoupper($productId).'|'.$tf.'|'.$field->seriesKey();

        return self::$cache[$key] ??= self::compute($ctx, $productId, $tf, $field, $mark);
    }

    /** Test isolation: the static cache lives outside the container and would otherwise leak between tests. */
    public static function forgetAll(): void
    {
        self::$cache = [];
        self::$asOf = null;
    }

    /** @return array{current: array<string, float|bool|null>, previous: array<string, float|bool|null>} */
    private static function compute(DeskContext $ctx, string $productId, string $tf, IndicatorField $field, ?float $mark): array
    {
        $dur = Candle::DURATIONS[$tf] ?? 3600;
        $now = $ctx->now()->getTimestamp();
        $raw = $ctx->bars($productId, $tf, $now - self::WARMUP_BARS * $dur, $now);
        $bars = array_values(array_filter($raw, static fn (array $b): bool => ($b['start'] + $dur) <= $now));

        if ($field->name === 'smx') {
            $series = Smx::compute($bars);
            $withDivergence = static function (array $series, int $offset): array {
                $out = Smx::latest($series, $offset);
                if ($out === []) {
                    return [];
                }
                $i = count($series['wt1']) - 1 - $offset;
                $out['div_bull'] = (bool) ($series['div_bull'][$i] ?? false);
                $out['div_bear'] = (bool) ($series['div_bear'][$i] ?? false);

                return $out;
            };

            return ['current' => $withDivergence($series, 0), 'previous' => $withDivergence($series, 1)];
        }

        return [
            'current' => self::outputs($bars, $field, $mark),
            'previous' => count($bars) > 1 ? self::outputs(array_slice($bars, 0, -1), $field, $mark) : [],
        ];
    }

    /** @param array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> $bars @return array<string, float|null> */
    private static function outputs(array $bars, IndicatorField $field, ?float $mark): array
    {
        $closes = array_map(static fn (array $b): float => (float) $b['close'], $bars);
        $args = $field->args;

        return match ($field->name) {
            'rsi' => ['value' => Indicators::rsi($closes, (int) $args[0])],
            'sma' => ['value' => Indicators::sma($closes, (int) $args[0])],
            'ema' => ['value' => Indicators::ema($closes, (int) $args[0])],
            'atr' => self::atrOutputs($bars, $closes, (int) $args[0], $mark),
            'adx' => ['value' => Indicators::adx($bars, (int) $args[0])],
            'macd' => Indicators::macd($closes, (int) $args[0], (int) $args[1], (int) $args[2]),
            'bb' => Indicators::bb($closes, (int) $args[0], (float) $args[1]),
            'vwap' => ['value' => Indicators::vwap($bars)],
            'obv' => ['value' => Indicators::obv($bars)],
            default => [],
        };
    }

    /** @param array<int, array{high:float,low:float,close:float}> $bars @param array<float> $closes */
    private static function atrOutputs(array $bars, array $closes, int $n, ?float $mark): array
    {
        $atr = Indicators::atr($bars, $n);
        $lastClose = $closes === [] ? null : end($closes);
        $price = ($mark !== null && $mark != 0.0) ? $mark : $lastClose;

        return [
            'value' => $atr,
            'pct' => ($atr !== null && $price) ? $atr / $price * 100 : null,
        ];
    }

    /** Case-insensitive match against Candle::DURATIONS's canonical keys — the schema writes "1h"/"15m", the store keys candles "1H"/"15m". */
    private static function normalizeTimeframe(string $tf): string
    {
        foreach (array_keys(Candle::DURATIONS) as $canonical) {
            if (strcasecmp($canonical, $tf) === 0) {
                return $canonical;
            }
        }

        return $tf;
    }
}
