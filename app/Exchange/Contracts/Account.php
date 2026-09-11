<?php

declare(strict_types=1);

namespace App\Exchange\Contracts;

/** Authenticated account access: balances, permissions, futures state. Only what current callers use. */
interface Account
{
    /** @return array<string, float> non-zero balances keyed by currency */
    public function balances(): array;

    public function availableBalance(string $currency): float;

    public function keyPermissions(): array;

    public function futuresBalance(): array;

    /** @return array<int, array<string, mixed>> */
    public function futuresPositions(): array;
}
