<?php

declare(strict_types=1);

namespace App\Services\Indicators;

/** Compact, allocation-light indicator math over closes/highs/lows arrays (oldest -> newest). */
final class Indicators
{
    /** @param array<float> $v */
    public static function sma(array $v, int $n): ?float
    {
        if (count($v) < $n || $n <= 0) {
            return null;
        }

        return array_sum(array_slice($v, -$n)) / $n;
    }

    /** @param array<float> $v @return array<float|null> */
    public static function emaSeries(array $v, int $n): array
    {
        $out = [];
        $k = 2 / ($n + 1);
        $ema = null;
        foreach ($v as $i => $x) {
            if ($i < $n - 1) {
                $out[] = null;

                continue;
            }
            if ($ema === null) {
                $ema = array_sum(array_slice($v, 0, $n)) / $n;
            } else {
                $ema = $x * $k + $ema * (1 - $k);
            }
            $out[] = $ema;
        }

        return $out;
    }

    /** @param array<float> $v */
    public static function ema(array $v, int $n): ?float
    {
        $s = self::emaSeries($v, $n);

        return $s === [] ? null : end($s);
    }

    /** Wilder RSI. @param array<float> $closes */
    public static function rsi(array $closes, int $n = 14): ?float
    {
        $c = count($closes);
        if ($c <= $n) {
            return null;
        }
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $d >= 0 ? $gain += $d : $loss -= $d;
        }
        $ag = $gain / $n;
        $al = $loss / $n;
        for ($i = $n + 1; $i < $c; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $ag = ($ag * ($n - 1) + max($d, 0)) / $n;
            $al = ($al * ($n - 1) + max(-$d, 0)) / $n;
        }
        if ($al == 0.0) {
            return 100.0;
        }

        return 100 - 100 / (1 + $ag / $al);
    }

    /** @return array{macd: float|null, signal: float|null, hist: float|null} */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $sig = 9): array
    {
        $f = self::emaSeries($closes, $fast);
        $s = self::emaSeries($closes, $slow);
        $line = [];
        foreach ($closes as $i => $_) {
            if ($f[$i] === null || $s[$i] === null) {
                continue;
            }
            $line[] = $f[$i] - $s[$i];
        }
        if ($line === []) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }
        $signal = self::ema($line, $sig);
        $macd = end($line);

        return ['macd' => $macd, 'signal' => $signal, 'hist' => $signal === null ? null : $macd - $signal];
    }

    /** Average true range. @param array<array{high:float,low:float,close:float}> $bars */
    public static function atr(array $bars, int $n = 14): ?float
    {
        $c = count($bars);
        if ($c <= $n) {
            return null;
        }
        $trs = [];
        for ($i = 1; $i < $c; $i++) {
            $h = $bars[$i]['high'];
            $l = $bars[$i]['low'];
            $pc = $bars[$i - 1]['close'];
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        $atr = array_sum(array_slice($trs, 0, $n)) / $n;
        for ($i = $n; $i < count($trs); $i++) {
            $atr = ($atr * ($n - 1) + $trs[$i]) / $n;
        }

        return $atr;
    }

    /**
     * Wilder's ADX (Average Directional Index) over the last bars, oldest -> newest. Needs at least
     * 2n bars: n price changes to seed the first smoothed +DM/-DM/TR, n more DX values to seed the
     * first ADX (a simple average of DX), matching the standard hand-computable definition. Returns
     * null if short.
     *
     * @param  array<array{high:float,low:float,close:float}>  $bars
     */
    public static function adx(array $bars, int $n = 14): ?float
    {
        $c = count($bars);
        if ($n < 1 || $c < 2 * $n) {
            return null;
        }
        $bars = array_values($bars);
        $plusDm = [];
        $minusDm = [];
        $tr = [];
        for ($i = 1; $i < $c; $i++) {
            $upMove = $bars[$i]['high'] - $bars[$i - 1]['high'];
            $downMove = $bars[$i - 1]['low'] - $bars[$i]['low'];
            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $tr[] = max(
                $bars[$i]['high'] - $bars[$i]['low'],
                abs($bars[$i]['high'] - $bars[$i - 1]['close']),
                abs($bars[$i]['low'] - $bars[$i - 1]['close']),
            );
        }

        $wilderSmooth = static function (array $v, int $n): array {
            $out = [];
            $s = array_sum(array_slice($v, 0, $n));
            $out[] = $s;
            for ($i = $n; $i < count($v); $i++) {
                $s = $s - $s / $n + $v[$i];
                $out[] = $s;
            }

            return $out;
        };

        $smTr = $wilderSmooth($tr, $n);
        $smPlus = $wilderSmooth($plusDm, $n);
        $smMinus = $wilderSmooth($minusDm, $n);

        $dx = [];
        for ($i = 0; $i < count($smTr); $i++) {
            if ($smTr[$i] == 0.0) {
                $dx[] = 0.0;

                continue;
            }
            $plusDi = 100 * $smPlus[$i] / $smTr[$i];
            $minusDi = 100 * $smMinus[$i] / $smTr[$i];
            $sum = $plusDi + $minusDi;
            $dx[] = $sum == 0.0 ? 0.0 : 100 * abs($plusDi - $minusDi) / $sum;
        }
        if (count($dx) < $n) {
            return null;
        }

        $adx = array_sum(array_slice($dx, 0, $n)) / $n;
        for ($i = $n; $i < count($dx); $i++) {
            $adx = ($adx * ($n - 1) + $dx[$i]) / $n;
        }

        return $adx;
    }

    /** Everything the strategies might want, from 1H bars oldest->newest. */
    public static function bundle(array $bars): array
    {
        $closes = array_map(fn ($b) => (float) $b['close'], $bars);
        $m = self::macd($closes);

        return [
            'rsi14' => self::rsi($closes, 14) !== null ? round(self::rsi($closes, 14), 2) : null,
            'ema9' => self::ema($closes, 9),
            'ema21' => self::ema($closes, 21),
            'ema50' => self::ema($closes, 50),
            'atr14' => self::atr($bars, 14),
            'macd' => $m['macd'],
            'signal' => $m['signal'],
            'hist' => $m['hist'],
        ];
    }
}
