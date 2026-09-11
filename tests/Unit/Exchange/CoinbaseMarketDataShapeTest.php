<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Services\Market\LiveFeed;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feeds CoinbaseMarketData a canned raw Coinbase wire payload and asserts the exact normalized
 * output, so a future change that lets a raw venue field (base_currency_id, uppercase BUY/SELL,
 * a missing book ts) leak through the adapter boundary fails here, not three layers downstream.
 */
class CoinbaseMarketDataShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ticker() prefers the websocket feed; force the REST path so this test never depends on Redis.
        $this->app->instance(LiveFeed::class, new class extends LiveFeed
        {
            public function quote(string $productId): ?array
            {
                return null;
            }
        });
    }

    public function test_products_normalizes_raw_coinbase_rows_to_the_canonical_shape(): void
    {
        $raw = [
            'product_id' => 'BTC-USD',
            'base_currency_id' => 'BTC',
            'quote_currency_id' => 'USD',
            'status' => 'online',
            'price' => '77119.39',
            'price_percentage_change_24h' => '-1.70235231751411',
            'volume_24h' => '5632.28320864',
            'base_increment' => '0.00000001',
            'quote_increment' => '0.01',
            'base_min_size' => '0.00000001',
            'quote_min_size' => '1',
            'trading_disabled' => false,
            'cancel_only' => false,
            'is_disabled' => false,
            'view_only' => false,
            'product_type' => 'SPOT',
        ];
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/products*' => Http::response(['products' => [$raw]])]);

        $rows = (new CoinbaseMarketData)->products();

        $this->assertSame([
            'product_id' => 'BTC-USD',
            'base_currency' => 'BTC',
            'quote_currency' => 'USD',
            'status' => 'online',
            'price' => 77119.39,
            'price_change_24h_pct' => -1.70235231751411,
            'volume_24h' => 5632.28320864,
            'volume_24h_usd' => 77119.39 * 5632.28320864,
            'base_increment' => '0.00000001',
            'quote_increment' => '0.01',
            'base_min_size' => 0.00000001,
            'quote_min_size' => 1.0,
            'trading_disabled' => false,
            'listed_at' => null,
            'raw' => $raw,
        ], $rows[0]);
    }

    public function test_products_combines_every_coinbase_disablement_flag_into_one_bool(): void
    {
        $raw = ['product_id' => 'X-USD', 'base_currency_id' => 'X', 'quote_currency_id' => 'USD', 'trading_disabled' => false, 'cancel_only' => false, 'is_disabled' => false, 'view_only' => true];
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/products*' => Http::response(['products' => [$raw]])]);

        $rows = (new CoinbaseMarketData)->products();

        $this->assertTrue($rows[0]['trading_disabled']);
    }

    public function test_product_normalizes_a_single_raw_row(): void
    {
        $raw = ['product_id' => 'ETH-USD', 'base_currency_id' => 'ETH', 'quote_currency_id' => 'USD', 'status' => 'online', 'price' => '3000', 'volume_24h' => '100'];
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/products/ETH-USD' => Http::response($raw)]);

        $row = (new CoinbaseMarketData)->product('ETH-USD');

        $this->assertSame('ETH-USD', $row['product_id']);
        $this->assertSame('ETH', $row['base_currency']);
        $this->assertSame('USD', $row['quote_currency']);
        $this->assertSame(300000.0, $row['volume_24h_usd']);
        $this->assertSame($raw, $row['raw']);
    }

    public function test_ticker_lowercases_trade_sides(): void
    {
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/products/BTC-USD/ticker*' => Http::response([
            'best_bid' => '79990', 'best_ask' => '80010',
            'trades' => [['price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => '2026-09-10T12:00:00Z']],
        ])]);

        $ticker = (new CoinbaseMarketData)->ticker('BTC-USD');

        $this->assertSame('buy', $ticker['trades'][0]['side']);
        $this->assertSame(79990.0, $ticker['best_bid']);
        $this->assertSame(80010.0, $ticker['best_ask']);
    }

    public function test_trades_lowercases_sides_and_sorts_ascending_by_time_ms(): void
    {
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/products/BTC-USD/ticker*' => Http::response([
            'trades' => [
                ['trade_id' => '2', 'price' => '80100', 'size' => '0.2', 'side' => 'SELL', 'time' => '2026-09-10T12:00:01.000000Z'],
                ['trade_id' => '1', 'price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => '2026-09-10T12:00:00.000000Z'],
            ],
        ])]);

        $rows = (new CoinbaseMarketData)->trades('BTC-USD', 0, time());

        $this->assertSame(['1', '2'], array_column($rows, 'trade_id'));
        $this->assertSame(['buy', 'sell'], array_column($rows, 'side'));
    }

    public function test_book_casts_levels_to_floats_and_adds_ts(): void
    {
        Http::fake(['api.coinbase.com/api/v3/brokerage/market/product_book*' => Http::response([
            'pricebook' => [
                'bids' => [['price' => '79990.1', 'size' => '0.5']],
                'asks' => [['price' => '80010.2', 'size' => '0.3']],
            ],
        ])]);

        $book = (new CoinbaseMarketData)->book('BTC-USD', 10);

        $this->assertSame([[79990.1, 0.5]], $book['bids']);
        $this->assertSame([[80010.2, 0.3]], $book['asks']);
        $this->assertIsInt($book['ts']);
    }
}
