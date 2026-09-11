<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Position;

/**
 * YOUR strategy. Selected with DESK_STRATEGY=custom (or from the dashboard).
 *
 * It starts as a copy of the base desk so the pipeline works on day one;
 * override any of scan() / vet() / size() / risk() below and the rest of the
 * system (data, execution, RISK timer, reporting, backtester, dashboard)
 * keeps working unchanged.
 *
 * Handy inputs on every ProductStats row:
 *   price, bestBid, bestAsk, spreadBps, bookDepthUsd
 *   volumeM5Usd / H1 / H6 / H24 / PrevH24, volumeSurgeH1(), volumeAcceleration(), volumeRatio6h()
 *   priceChangeM5Pct / H1 / H6 / H24
 *   buysH1 / sellsH1 / buysM5 / sellsM5, buyVolumeH1Usd / sellVolumeH1Usd
 *   extra['indicators'] => ['rsi14','ema9','ema21','ema50','atr14','macd','signal','hist'] on 1H candles
 *
 * Parameters: return them from defaults() and read with $ctx->param('scan.whatever').
 * They appear in the dashboard Settings page automatically.
 */
class CustomStrategy extends BaseDeskStrategy
{
    public function key(): string
    {
        return 'custom';
    }

    public function name(): string
    {
        return 'Custom (yours)';
    }

    public function defaults(): array
    {
        return array_replace_recursive(parent::defaults(), [
            'scan' => [
                // example: only look at coins with RSI under this on the 1H
                'max_rsi' => 70,
            ],
        ]);
    }

    /** @param array<int, ProductStats> $universe */
    public function scan(array $universe, DeskContext $ctx): array
    {
        // Example tweak: drop anything already overbought on the 1H before the base ranking runs.
        $maxRsi = (float) $ctx->param('scan.max_rsi', 70);
        $filtered = array_filter($universe, function (ProductStats $s) use ($maxRsi) {
            $rsi = $s->extra['indicators']['rsi14'] ?? null;

            return $rsi === null || $rsi <= $maxRsi;
        });

        return parent::scan(array_values($filtered), $ctx);
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return parent::vet($candidate, $bank, $ctx);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return parent::size($verdict, $bank, $ctx);
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return parent::risk($position, $stats, $ctx);
    }
}
