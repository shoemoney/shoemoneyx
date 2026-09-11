<?php

declare(strict_types=1);

namespace Tests\Exchange;

use App\Desk\Execution\Executor;
use App\Exchange\Capabilities;
use App\Exchange\Contracts\Account;
use App\Exchange\Contracts\Credentials;
use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;

/** In-memory Exchange for tests and future conformance-suite runs: no network, everything configurable. */
final class FakeExchange implements Exchange
{
    public Capabilities $capabilitiesValue;

    /** @var array<string, array{product_id: string, contract_size: float}> */
    public array $perpSpecs = [];

    public function __construct(
        private string $exchangeId = 'fake',
        private ?MarketData $marketData = null,
        private ?Account $account = null,
        private ?Executor $liveExecutor = null,
        private ?Executor $perpsExecutor = null,
    ) {
        $this->marketData ??= new FakeMarketData;
        $this->capabilitiesValue = new Capabilities(
            spot: true,
            perps: true,
            websocket: false,
            historicalTrades: true,
            shorts: true,
        );
    }

    public function id(): string
    {
        return $this->exchangeId;
    }

    public function name(): string
    {
        return ucfirst($this->exchangeId);
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilitiesValue;
    }

    public function marketData(): MarketData
    {
        return $this->marketData;
    }

    public function account(): ?Account
    {
        return $this->account;
    }

    public function executor(bool $perps): Executor
    {
        $executor = $perps ? $this->perpsExecutor : $this->liveExecutor;

        return $executor ?? throw new \RuntimeException('FakeExchange: no '.($perps ? 'perps' : 'live').' executor configured');
    }

    public function credentials(): Credentials
    {
        return new class implements Credentials
        {
            public function fields(): array
            {
                return [];
            }

            public function validate(array $values): array
            {
                return [];
            }
        };
    }

    public function perpSpec(string $spotProductId): ?array
    {
        return $this->perpSpecs[$spotProductId] ?? null;
    }
}
