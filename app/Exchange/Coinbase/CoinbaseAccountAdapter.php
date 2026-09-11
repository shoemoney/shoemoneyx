<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Contracts\Account;
use App\Models\CoinbaseAccount;

/** Thin Account wrapper over the stored CoinbaseAccount model and the authenticated CoinbaseService facade. */
final class CoinbaseAccountAdapter implements Account
{
    public function __construct(private CoinbaseService $coinbase, private CoinbaseAccount $account) {}

    public function balances(): array
    {
        return $this->coinbase->balances($this->account);
    }

    public function availableBalance(string $currency): float
    {
        return $this->coinbase->availableBalance($this->account, $currency);
    }

    public function keyPermissions(): array
    {
        return $this->coinbase->keyPermissions($this->account);
    }

    public function futuresBalance(): array
    {
        return $this->coinbase->futuresBalance($this->account);
    }

    public function futuresPositions(): array
    {
        return $this->coinbase->futuresPositions($this->account);
    }
}
