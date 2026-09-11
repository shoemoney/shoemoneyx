<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Desk\Execution\Executor;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Capabilities;
use App\Exchange\Contracts\Account;
use App\Exchange\Contracts\Credentials;
use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;
use App\Models\CoinbaseAccount;

/** Coinbase Advanced Trade / Coinbase Financial Markets adapter root. */
final class CoinbaseExchange implements Exchange
{
    public function id(): string
    {
        return 'coinbase';
    }

    public function name(): string
    {
        return 'Coinbase';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            spot: true,
            perps: true,
            websocket: true,
            historicalTrades: true,
            shorts: true,
        );
    }

    public function marketData(): MarketData
    {
        return app(CoinbaseMarketData::class);
    }

    public function account(): ?Account
    {
        $account = CoinbaseAccount::active();

        return $account ? new CoinbaseAccountAdapter(app(CoinbaseService::class), $account) : null;
    }

    public function executor(bool $perps): Executor
    {
        return $perps ? app(CoinbasePerpsExecutor::class) : app(CoinbaseExecutor::class);
    }

    public function credentials(): Credentials
    {
        return new CoinbaseCredentials;
    }

    /** The spot -> perp symbol map stays in config/desk.php; this is the only place it's read from. */
    public function perpSpec(string $spotProductId): ?array
    {
        $m = config('desk.perps.map', [])[$spotProductId] ?? null;

        return $m ? ['product_id' => (string) $m['product_id'], 'contract_size' => (float) $m['contract_size']] : null;
    }
}
