<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Ccxt\CcxtExchange;
use App\Exchange\Coinbase\CoinbaseExchange;
use App\Exchange\ExchangeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRegistryCcxtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['exchanges.ccxt.enabled' => 'binance,kraken']);
    }

    public function test_all_lists_native_and_enabled_ccxt_ids(): void
    {
        $this->assertSame(['coinbase', 'binance', 'kraken'], app(ExchangeRegistry::class)->all());
    }

    public function test_all_ignores_blank_entries(): void
    {
        config(['exchanges.ccxt.enabled' => ' kraken , , okx ,']);

        $this->assertSame(['coinbase', 'kraken', 'okx'], app(ExchangeRegistry::class)->all());
    }

    public function test_make_resolves_an_enabled_ccxt_id(): void
    {
        $exchange = app(ExchangeRegistry::class)->make('kraken');

        $this->assertInstanceOf(CcxtExchange::class, $exchange);
        $this->assertSame('kraken', $exchange->id());
        $this->assertSame('Kraken', $exchange->name());
    }

    public function test_native_driver_wins_over_a_same_named_ccxt_id(): void
    {
        config(['exchanges.ccxt.enabled' => 'coinbase,kraken']);

        $registry = app(ExchangeRegistry::class);

        $this->assertInstanceOf(CoinbaseExchange::class, $registry->make('coinbase'));
        $this->assertSame(['coinbase', 'kraken'], $registry->all());
    }

    public function test_a_ccxt_id_that_is_not_enabled_still_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown exchange: nope-unknown');

        app(ExchangeRegistry::class)->make('nope-unknown');
    }

    public function test_a_real_ccxt_id_left_out_of_the_enabled_list_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown exchange: okx');

        app(ExchangeRegistry::class)->make('okx');
    }

    public function test_capabilities_report_spot_only(): void
    {
        $exchange = app(ExchangeRegistry::class)->make('kraken');
        $caps = $exchange->capabilities();

        $this->assertTrue($caps->spot);
        $this->assertFalse($caps->perps);
        $this->assertFalse($caps->websocket);
        $this->assertTrue($caps->historicalTrades);
        $this->assertFalse($caps->shorts);
        $this->assertNull($exchange->perpSpec('BTC-USD'));
    }

    public function test_account_is_null_until_credentials_are_stored(): void
    {
        $this->assertNull(app(ExchangeRegistry::class)->make('kraken')->account());
    }

    public function test_asking_for_a_perps_executor_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ccxt adapter is spot-only in v1 — perps not supported for kraken');

        app(ExchangeRegistry::class)->make('kraken')->executor(true);
    }
}
