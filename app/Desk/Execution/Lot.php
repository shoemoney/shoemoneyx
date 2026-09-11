<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/**
 * One fill's size and cost, the way the venue will actually book it. Coinbase US perps trade in whole
 * nano contracts (BTC 0.01, ETH 0.1 …) and charge max(notional × taker, contracts × per-contract), so a
 * $50 ticket is either one $800 contract or nothing. Paper, backtest and live all size through here so
 * the optimizer tunes against the same floor the live executor enforces.
 */
final readonly class Lot
{
    public function __construct(
        public int $contracts,
        public float $qty,
        public float $notional,
        public float $feeUsd,
    ) {}

    /** Entry sizing for a dollar ticket. Null when whole contracts apply and the ticket is under one. */
    public static function forUsd(string $spotPid, float $usd, float $price, float $rate, float $perContractUsd, bool $wholeContracts): ?self
    {
        if ($usd <= 0 || $price <= 0) {
            return null;
        }
        $spec = $wholeContracts ? Perps::spec($spotPid) : null;
        if ($spec === null) {
            return new self(0, $usd / $price, $usd, self::fee($usd, 0, $rate, $perContractUsd));
        }
        $contracts = (int) floor($usd / ($spec['contract_size'] * $price));
        if ($contracts < 1) {
            return null;
        }
        $qty = $contracts * $spec['contract_size'];
        $notional = $qty * $price;

        return new self($contracts, $qty, $notional, self::fee($notional, $contracts, $rate, $perContractUsd));
    }

    /**
     * Exit sizing for a base quantity: whole contracts, floored so a fill never exceeds what is held (a
     * half-trim of 3 contracts requests 1.5 and sells exactly 1, not 1.5). $legacyFractional says the
     * quantity being closed is a genuinely fractional holding — one opened before whole-contract sizing
     * existed, never itself a whole number of contracts — and only then does it exit at the requested
     * quantity exactly rather than being floored to a contract it may not have. A merely-fractional
     * REQUEST against a normal whole-contract holding (a trim asking for 1.5 of a 3-contract position)
     * is not legacy: it floors like any other exit, and a request under one contract floors to zero,
     * which the caller must treat as "skip this rung" rather than a fractional fallback fill.
     */
    public static function forQty(string $spotPid, float $qty, float $price, float $rate, float $perContractUsd, bool $wholeContracts, bool $legacyFractional = false): self
    {
        $qty = max($qty, 0.0);
        $spec = $wholeContracts ? Perps::spec($spotPid) : null;
        if ($spec === null) {
            return new self(0, $qty, $qty * $price, self::fee($qty * $price, 0, $rate, $perContractUsd));
        }
        if ($legacyFractional) {
            return new self(0, $qty, $qty * $price, self::fee($qty * $price, 0, $rate, $perContractUsd));
        }
        $contracts = (int) floor($qty / $spec['contract_size'] + 1e-9);
        if ($contracts < 1) {
            return new self(0, 0.0, 0.0, 0.0);
        }
        $lotQty = $contracts * $spec['contract_size'];
        $notional = $lotQty * $price;

        return new self($contracts, $lotQty, $notional, self::fee($notional, $contracts, $rate, $perContractUsd));
    }

    /** max(notional × rate, contracts × per-contract); the per-contract floor only bites on tiny tickets. Callers pass the taker or maker rate depending on how the fill was made. */
    public static function fee(float $notional, int $contracts, float $rate, float $perContractUsd): float
    {
        return max($notional * $rate, $contracts * $perContractUsd);
    }
}
