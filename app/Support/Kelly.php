<?php

declare(strict_types=1);

namespace App\Support;

final class Kelly
{
    /**
     * Fractional Kelly. p = win probability, b = payoff ratio (win size / loss size).
     * Returns the fraction of the book to risk, clamped to [0, cap].
     */
    public static function fraction(float $p, float $b, float $fraction = 0.5, float $cap = 0.06): float
    {
        if ($b <= 0 || $p <= 0) {
            return 0.0;
        }
        $q = 1 - $p;
        $f = ($p * $b - $q) / $b;
        $f = max(0.0, $f * $fraction);

        return min($f, $cap);
    }
}
