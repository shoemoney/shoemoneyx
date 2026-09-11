<?php

declare(strict_types=1);

namespace Tests\Exchange\Conformance;

use App\Exchange\Coinbase\CoinbaseExchange;
use App\Exchange\Contracts\Exchange;

/**
 * Runs the exchange conformance kit against Coinbase using fixtures recorded from the real
 * public API (tests/Exchange/Fixtures/coinbase/, captured via `php artisan exchange:record
 * coinbase BTC-USD`). No network calls, no credentials.
 */
final class CoinbaseConformanceTest extends ExchangeConformanceTestCase
{
    protected function exchange(): Exchange
    {
        return new RecordedExchange(
            app(CoinbaseExchange::class),
            base_path('tests/Exchange/Fixtures/coinbase')
        );
    }

    protected function sampleProductId(): string
    {
        return 'BTC-USD';
    }
}
