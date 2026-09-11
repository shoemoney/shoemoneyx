<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Exchange\Contracts\Exchange;

/**
 * Spot-symbol → perp mapping. Delegates to the active exchange (config/desk.php → perps.map for
 * Coinbase). The desk thinks in spot symbols (signals come from the spot tape); executors translate.
 */
final class Perps
{
    public static function enabled(): bool
    {
        return (bool) config('desk.perps.enabled', false);
    }

    /** @return array{product_id: string, contract_size: float}|null */
    public static function spec(string $spotProductId): ?array
    {
        return app(Exchange::class)->perpSpec($spotProductId);
    }

    /** Whole contracts for a dollar notional at a price; the true floor, 0 when under one contract. */
    public static function contractsFor(string $spotProductId, float $usd, float $price): int
    {
        $spec = self::spec($spotProductId);
        if ($spec === null || $price <= 0) {
            return 0;
        }

        return (int) floor($usd / ($spec['contract_size'] * $price));
    }

    /** Whole contracts for a base-unit quantity (rounded, never below one when qty > 0). */
    public static function contractsForQty(string $spotProductId, float $qty): int
    {
        $spec = self::spec($spotProductId);
        if ($spec === null || $qty <= 0) {
            return 0;
        }

        return max(1, (int) round($qty / $spec['contract_size']));
    }

    /** Base units for a number of contracts. */
    public static function qtyFor(string $spotProductId, int $contracts): float
    {
        $spec = self::spec($spotProductId);

        return $spec ? $contracts * $spec['contract_size'] : 0.0;
    }
}
