<?php

declare(strict_types=1);

namespace App\Support;

/** Return-series moments off an equity curve. Pure, no framework dependency. */
class Returns
{
    /** Simple returns between consecutive equity points; $curve rows are [ts, equity]. */
    public static function fromCurve(array $curve): array
    {
        $out = [];
        for ($i = 1; $i < count($curve); $i++) {
            $prev = (float) $curve[$i - 1][1];
            $cur = (float) $curve[$i][1];
            if ($prev != 0.0) {
                $out[] = $cur / $prev - 1;
            }
        }

        return $out;
    }

    /** Mean / population stdev of the returns; null if fewer than 3 or stdev is 0. */
    public static function sharpe(array $r): ?float
    {
        if (count($r) < 3) {
            return null;
        }
        $sd = self::stdev($r);

        return $sd > 0 ? array_sum($r) / count($r) / $sd : null;
    }

    /** Sample skewness (method-of-moments g1); 0.0 when undefined (fewer than 3 points or stdev is 0). */
    public static function skew(array $r): float
    {
        $sd = self::stdev($r);
        if (count($r) < 3 || $sd == 0.0) {
            return 0.0;
        }
        $mean = array_sum($r) / count($r);

        return (array_sum(array_map(fn ($x) => ($x - $mean) ** 3, $r)) / count($r)) / $sd ** 3;
    }

    /** Non-excess kurtosis (method-of-moments g2); defaults to 3.0 (normal) when undefined. */
    public static function kurt(array $r): float
    {
        $sd = self::stdev($r);
        if (count($r) < 3 || $sd == 0.0) {
            return 3.0;
        }
        $mean = array_sum($r) / count($r);

        return (array_sum(array_map(fn ($x) => ($x - $mean) ** 4, $r)) / count($r)) / $sd ** 4;
    }

    private static function stdev(array $r): float
    {
        $n = count($r);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($r) / $n;

        return sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $r)) / $n);
    }
}
