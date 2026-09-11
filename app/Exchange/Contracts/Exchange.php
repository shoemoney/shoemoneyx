<?php

declare(strict_types=1);

namespace App\Exchange\Contracts;

use App\Desk\Execution\Executor;
use App\Exchange\Capabilities;

/** The adapter root: one implementation per venue, everything else behind it. */
interface Exchange
{
    /** Slug, e.g. 'coinbase'. */
    public function id(): string;

    public function name(): string;

    public function capabilities(): Capabilities;

    public function marketData(): MarketData;

    /** Null when no credentials are stored for this exchange. */
    public function account(): ?Account;

    /** The live executor for this venue; $perps picks the perpetuals path when true. */
    public function executor(bool $perps): Executor;

    public function credentials(): Credentials;

    /** @return array{product_id: string, contract_size: float}|null */
    public function perpSpec(string $spotProductId): ?array;
}
