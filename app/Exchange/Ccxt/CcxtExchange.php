<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Desk\Execution\Executor;
use App\Exchange\Capabilities;
use App\Exchange\Contracts\Account;
use App\Exchange\Contracts\Credentials;
use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;
use App\Exchange\ExchangeException;
use App\Models\ExchangeCredential;

/**
 * One adapter class for every REST venue ccxt wraps. The ccxt id IS this adapter's id,
 * so EXCHANGE=kraken resolves straight through exchanges.active.
 *
 * Spot only in v1: ccxt's unified swap surface needs per-venue market selection and a
 * position model this adapter does not have yet, so perps and shorts report false.
 */
class CcxtExchange implements Exchange
{
    private ?\ccxt\Exchange $client = null;

    /** @var array<string, string>|null */
    private ?array $stored = null;

    private bool $storedLoaded = false;

    public function __construct(private string $ccxtId) {}

    public function id(): string
    {
        return $this->ccxtId;
    }

    public function name(): string
    {
        $class = '\\ccxt\\'.$this->ccxtId;
        if (class_exists($class)) {
            $name = (new $class)->name ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return ucfirst($this->ccxtId);
    }

    public function capabilities(): Capabilities
    {
        $has = $this->client()->has;

        return new Capabilities(
            spot: true,
            perps: false,
            websocket: false,
            historicalTrades: (bool) ($has['fetchTrades'] ?? false),
            shorts: false,
        );
    }

    public function marketData(): MarketData
    {
        return new CcxtMarketData($this->client(), $this->ccxtId);
    }

    public function account(): ?Account
    {
        if (! $this->storedCredentials()) {
            return null;
        }

        return new CcxtAccountAdapter($this->client(), $this->ccxtId);
    }

    public function executor(bool $perps): Executor
    {
        if ($perps) {
            throw new \RuntimeException("ccxt adapter is spot-only in v1 — perps not supported for {$this->ccxtId}");
        }

        return new CcxtExecutor($this->client(), $this->ccxtId);
    }

    public function credentials(): Credentials
    {
        return new CcxtCredentials;
    }

    public function perpSpec(string $spotProductId): ?array
    {
        return null;
    }

    /**
     * One client per adapter instance, shared by market data, account, and executor.
     * Built keyless when no credentials are stored, which keeps public endpoints usable.
     */
    private function client(): \ccxt\Exchange
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $class = '\\ccxt\\'.$this->ccxtId;
        if (! class_exists($class)) {
            throw new ExchangeException("ccxt has no exchange named {$this->ccxtId}");
        }

        $options = ['enableRateLimit' => true];
        foreach ($this->storedCredentials() ?? [] as $key => $value) {
            if (in_array($key, ['apiKey', 'secret', 'password'], true) && (string) $value !== '') {
                $options[$key] = (string) $value;
            }
        }

        return $this->client = new $class($options);
    }

    /** @return array<string, string>|null */
    private function storedCredentials(): ?array
    {
        if (! $this->storedLoaded) {
            $row = ExchangeCredential::where('exchange', $this->ccxtId)->first();
            $this->stored = $row ? (array) $row->values : null;
            $this->storedLoaded = true;
        }

        return $this->stored;
    }
}
