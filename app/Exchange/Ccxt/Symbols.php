<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

/**
 * Desk product ids are BASE-QUOTE; ccxt unified symbols are BASE/QUOTE.
 * Split on the first separator only, so multi-character quotes (ETH-USDT) and
 * dashed base assets survive the round trip.
 */
final class Symbols
{
    public static function toCcxt(string $productId): string
    {
        return self::swap($productId, '-', '/');
    }

    public static function fromCcxt(string $symbol): string
    {
        return self::swap($symbol, '/', '-');
    }

    private static function swap(string $value, string $from, string $to): string
    {
        $at = strpos($value, $from);

        return $at === false ? $value : substr($value, 0, $at).$to.substr($value, $at + 1);
    }
}
