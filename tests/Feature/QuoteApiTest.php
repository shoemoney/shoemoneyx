<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class QuoteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_serves_the_live_websocket_quote_when_the_feed_is_fresh(): void
    {
        Redis::shouldReceive('hgetall')->once()->with('ws:price:BTC-USD')->andReturn([
            'price' => '79980.98', 'bid' => '79980.98', 'ask' => '79980.99', 'vol24' => '2497.9', 'chg24' => '0.45', 'ts' => (string) time(),
        ]);

        $this->getJson('/api/quote?product=BTC-USD')
            ->assertOk()
            ->assertJsonPath('source', 'feed')
            ->assertJsonPath('price', 79980.98)
            ->assertJsonPath('ask', 79980.99);
    }

    public function test_falls_back_to_the_products_row_when_the_feed_is_stale(): void
    {
        Redis::shouldReceive('hgetall')->once()->with('ws:price:BTC-USD')->andReturn([
            'price' => '1', 'bid' => '', 'ask' => '', 'vol24' => '', 'chg24' => '', 'ts' => (string) (time() - 3600),
        ]);
        Product::query()->create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD', 'status' => 'online', 'price' => 80003.24, 'is_tracked' => true, 'trading_disabled' => false]);

        $this->getJson('/api/quote?product=BTC-USD')
            ->assertOk()
            ->assertJsonPath('source', 'products')
            ->assertJsonPath('price', 80003.24);
    }

    public function test_returns_null_for_an_unknown_product(): void
    {
        Redis::shouldReceive('hgetall')->once()->andReturn([]);

        $this->getJson('/api/quote?product=NOPE-USD')->assertOk()->assertExactJson([]);
    }
}
