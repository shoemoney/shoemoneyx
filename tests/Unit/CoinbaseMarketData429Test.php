<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Coinbase\CoinbaseMarketData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CoinbaseMarketData429Test extends TestCase
{
    /** One 429 (with Retry-After: 2) then a 200 -- accepts the real 2s sleep the back-off performs. */
    public function test_one_warning_is_logged_per_back_off(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'rate limited'], 429, ['Retry-After' => '2'])
                ->push(['candles' => []], 200),
        ]);
        Log::spy();

        (new CoinbaseMarketData())->candles('BTC-USD', '1H', 0, 100);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []) => str_contains($message, '429')
                && ($context['wait'] ?? null) === 2
                && ($context['retry_after'] ?? null) === '2'
        );
    }
}
