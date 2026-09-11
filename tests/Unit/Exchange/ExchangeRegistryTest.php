<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Coinbase\CoinbaseExchange;
use App\Exchange\ExchangeRegistry;
use Tests\TestCase;

class ExchangeRegistryTest extends TestCase
{
    public function test_unknown_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown exchange: nope');

        app(ExchangeRegistry::class)->make('nope');
    }

    public function test_active_resolves_to_coinbase_exchange(): void
    {
        config(['exchanges.active' => 'coinbase']);

        $exchange = app(ExchangeRegistry::class)->active();

        $this->assertInstanceOf(CoinbaseExchange::class, $exchange);
        $this->assertSame('coinbase', $exchange->id());
    }

    public function test_capabilities_correct(): void
    {
        $caps = app(ExchangeRegistry::class)->active()->capabilities();

        $this->assertTrue($caps->spot);
        $this->assertTrue($caps->perps);
        $this->assertTrue($caps->websocket);
        $this->assertTrue($caps->historicalTrades);
        $this->assertTrue($caps->shorts);
    }

    public function test_all_lists_registered_ids(): void
    {
        $this->assertSame(['coinbase'], app(ExchangeRegistry::class)->all());
    }
}
