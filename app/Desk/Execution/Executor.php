<?php

declare(strict_types=1);

namespace App\Desk\Execution;

interface Executor
{
    public function mode(): string;

    /** Spend $usd on $productId at market (long entry / add). $decisionPrice is what SIZE saw. */
    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult;

    /**
     * Sell $qty base units at market (long exit / trim). $entryUsdShare is the gross entry_usd being
     * closed, so margin paper accounting can return margin back + PnL; venue executors ignore it.
     * $maker: the fill rested on the book (a TP rung) rather than crossing the spread; paper charges
     * the maker rate for it, live executors ignore the flag since the venue charges what it charges.
     */
    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult;

    /**
     * Open or add to a SHORT worth $usd of notional (perps only). Returns filledUsd = gross proceeds
     * (= notional), stored as the short's entry_usd; the fee is reported separately. Spot executors reject.
     */
    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult;

    /**
     * Buy back $qty base units of a SHORT. $entryUsdShare is the gross entry_usd being closed, so cash
     * accounting can return margin + locked proceeds − cost. filledUsd = gross cost + fee. $maker: see sell().
     */
    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult;

    /** Settled quote-currency cash available to the desk. */
    public function cash(): float;
}
