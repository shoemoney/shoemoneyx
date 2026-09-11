<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Models\Position;

/** Builds a paper PerpsSession from the desk's own state — the same shape live reads off CFM, no API call. */
final class MarginBook
{
    /**
     * The one valuation contract every margin-aware view reads from: posted collateral, unrealised
     * PnL, and current notional across a set of positions. BankService uses this same method (or
     * trusts CFM's own initial_margin for live) so the bank and the margin session can never disagree
     * about what a margin position is worth again.
     *
     * @param  iterable<Position>  $positions
     * @return array{collateral: float, unrealised: float, notional: float}
     */
    public static function valuation(iterable $positions, callable $priceOf): array
    {
        $notionalNow = 0.0;
        $marginPosted = 0.0;
        $unrealised = 0.0;
        foreach ($positions as $p) {
            $price = (float) $priceOf($p);
            $notionalNow += $p->quantity * $price;
            $marginPosted += (float) ($p->meta['margin_usd'] ?? 0);
            $unrealised += $p->unrealisedPnl($price);
        }

        return ['collateral' => $marginPosted, 'unrealised' => $unrealised, 'notional' => $notionalNow];
    }

    /** @param  iterable<Position>  $positions */
    public static function fromPositions(float $cash, iterable $positions, callable $priceOf, float $initialRate, float $maintenanceRate): PerpsSession
    {
        ['collateral' => $marginPosted, 'unrealised' => $unrealised, 'notional' => $notionalNow] = self::valuation($positions, $priceOf);

        $equity = $cash + $marginPosted + $unrealised;
        $initialMargin = $notionalNow * $initialRate;
        $maintenance = $notionalNow * $maintenanceRate;

        return new PerpsSession(
            window: MarginWindow::Overnight,
            windowEndsAt: null,
            futuresBuyingPower: max(0.0, ($equity - $initialMargin) / max($initialRate, 1e-12)),
            initialMargin: $initialMargin,
            availableMargin: $cash,
            liquidationBufferPct: $maintenance > 0 ? ($equity - $maintenance) / $maintenance * 100 : 1000.0,
        );
    }

    public static function liquidated(PerpsSession $s): bool
    {
        return $s->liquidationBufferPct !== null && $s->liquidationBufferPct <= 0;
    }
}
