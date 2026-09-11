<?php

declare(strict_types=1);

namespace App\Support;

final class Fees
{
    /** effective_fee = max(rate * dollars, floor) / dollars  (single leg) */
    public static function effectiveRate(float $dollars, float $rate, float $floorUsd): float
    {
        if ($dollars <= 0) {
            return INF;
        }

        return max($rate * $dollars, $floorUsd) / $dollars;
    }

    /** Percent the price must move just to break even after a round trip incl. expected slippage. */
    public static function breakEvenMovePct(float $dollars, float $takerRate, float $floorUsd, float $slippageBps): float
    {
        $leg = self::effectiveRate($dollars, $takerRate, $floorUsd);

        return (2 * $leg + 2 * $slippageBps / 10_000) * 100;
    }
}
