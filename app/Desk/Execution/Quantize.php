<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** Decimal-safe rounding down for order sizes: floor(0.29 * 100) is 28 in binary floats, so snap float noise first. */
final class Quantize
{
    public static function floorTo(float $value, int $decimals): float
    {
        if ($value <= 0 || $decimals < 0) {
            return 0.0;
        }
        $factor = 10.0 ** $decimals;
        $scaled = $value * $factor;
        $nearest = round($scaled);
        $units = abs($scaled - $nearest) <= abs($scaled) * 4 * PHP_FLOAT_EPSILON ? $nearest : floor($scaled);

        if ($units / $factor > $value) {
            $units -= 1;
        }

        return $units / $factor;
    }
}
