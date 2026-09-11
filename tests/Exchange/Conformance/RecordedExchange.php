<?php

declare(strict_types=1);

namespace Tests\Exchange\Conformance;

use App\Desk\Execution\Executor;
use App\Exchange\Capabilities;
use App\Exchange\Contracts\Account;
use App\Exchange\Contracts\Credentials;
use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;

/**
 * Wraps a real Exchange adapter for its manifest, capabilities, credentials, and executor, but
 * swaps in RecordedMarketData so a conformance suite runs against frozen fixtures instead of the
 * live venue -- no credentials needed in CI. account() is always null: fixtures never carry auth.
 */
final class RecordedExchange implements Exchange
{
    private MarketData $marketData;

    public function __construct(private readonly Exchange $real, string $fixtureDir)
    {
        $this->marketData = new RecordedMarketData($fixtureDir);
    }

    public function id(): string
    {
        return $this->real->id();
    }

    public function name(): string
    {
        return $this->real->name();
    }

    public function capabilities(): Capabilities
    {
        return $this->real->capabilities();
    }

    public function marketData(): MarketData
    {
        return $this->marketData;
    }

    public function account(): ?Account
    {
        return null;
    }

    public function executor(bool $perps): Executor
    {
        return $this->real->executor($perps);
    }

    public function credentials(): Credentials
    {
        return $this->real->credentials();
    }

    public function perpSpec(string $spotProductId): ?array
    {
        return $this->real->perpSpec($spotProductId);
    }
}
