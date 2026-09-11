<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Exchange\Contracts\Account;
use App\Exchange\ExchangeException;

/** Authenticated balances through ccxt. Spot only in v1, so the futures calls stay empty. */
class CcxtAccountAdapter implements Account
{
    public function __construct(private \ccxt\Exchange $client, private string $ccxtId) {}

    /** @return array<string, float> */
    public function balances(): array
    {
        $totals = $this->call(fn () => $this->client->fetch_balance())['total'] ?? [];

        $out = [];
        foreach ($totals as $ccy => $amount) {
            if ((float) $amount > 0) {
                $out[(string) $ccy] = (float) $amount;
            }
        }

        return $out;
    }

    public function availableBalance(string $currency): float
    {
        return (float) ($this->call(fn () => $this->client->fetch_balance())['free'][$currency] ?? 0.0);
    }

    /**
     * ccxt has no unified permissions endpoint, so there is nothing honest to report here.
     * Callers that need real key scopes should use a native adapter.
     */
    public function keyPermissions(): array
    {
        return [];
    }

    public function futuresBalance(): array
    {
        return [];
    }

    public function futuresPositions(): array
    {
        return [];
    }

    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            throw new ExchangeException("{$this->ccxtId}: {$e->getMessage()}", 0, $e);
        }
    }
}
