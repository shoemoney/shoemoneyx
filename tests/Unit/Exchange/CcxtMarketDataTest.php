<?php

declare(strict_types=1);

namespace Tests\Unit\Exchange;

use App\Exchange\Ccxt\CcxtMarketData;
use App\Exchange\ExchangeException;
use Tests\Exchange\StubCcxtClient;
use Tests\TestCase;

class CcxtMarketDataTest extends TestCase
{
    private StubCcxtClient $client;

    private CcxtMarketData $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubCcxtClient([]);
        $this->market = new CcxtMarketData($this->client, 'stubex');
    }

    public function test_products_maps_active_spot_markets_only(): void
    {
        $this->client->stubMarkets = [
            'BTC/USD' => [
                'symbol' => 'BTC/USD', 'base' => 'BTC', 'quote' => 'USD', 'spot' => 1, 'active' => 1,
                'precision' => ['amount' => 1.0E-8, 'price' => 0.1],
                'limits' => ['amount' => ['min' => 5.0E-5], 'cost' => ['min' => 0.5]],
            ],
            'ETH/USDT' => [
                'symbol' => 'ETH/USDT', 'base' => 'ETH', 'quote' => 'USDT', 'spot' => true, 'active' => true,
                'precision' => ['amount' => 0.001, 'price' => 0.01],
                'limits' => ['amount' => ['min' => null], 'cost' => ['min' => null]],
            ],
            'DOGE/USD' => [
                'symbol' => 'DOGE/USD', 'base' => 'DOGE', 'quote' => 'USD', 'spot' => 1, 'active' => 0,
                'precision' => [], 'limits' => [],
            ],
            'BTC/USD:USD' => [
                'symbol' => 'BTC/USD:USD', 'base' => 'BTC', 'quote' => 'USD', 'spot' => 0, 'active' => 1,
                'precision' => [], 'limits' => [],
            ],
        ];

        $rows = $this->market->products();

        $this->assertCount(2, $rows);
        $this->assertSame([
            'product_id' => 'BTC-USD',
            'base_currency' => 'BTC',
            'quote_currency' => 'USD',
            'status' => 'online',
            'price' => null,
            'price_change_24h_pct' => null,
            'volume_24h' => null,
            'volume_24h_usd' => null,
            'base_increment' => '0.00000001',
            'quote_increment' => '0.1',
            'base_min_size' => 5.0E-5,
            'quote_min_size' => 0.5,
            'trading_disabled' => false,
            'listed_at' => null,
            'raw' => $this->client->stubMarkets['BTC/USD'],
        ], $rows[0]);

        $this->assertSame('ETH-USDT', $rows[1]['product_id']);
        $this->assertSame('0.001', $rows[1]['base_increment']);
        $this->assertNull($rows[1]['base_min_size']);
        $this->assertNull($rows[1]['quote_min_size']);
    }

    public function test_products_converts_decimal_places_precision_to_a_tick(): void
    {
        $this->client->precisionMode = \ccxt\DECIMAL_PLACES;
        $this->client->stubMarkets = [
            'BTC/USD' => [
                'symbol' => 'BTC/USD', 'base' => 'BTC', 'quote' => 'USD', 'spot' => 1, 'active' => 1,
                'precision' => ['amount' => 8, 'price' => 2],
                'limits' => [],
            ],
        ];

        $rows = $this->market->products();

        $this->assertSame('0.00000001', $rows[0]['base_increment']);
        $this->assertSame('0.01', $rows[0]['quote_increment']);
    }

    public function test_product_returns_the_single_market_row(): void
    {
        $raw = [
            'symbol' => 'ETH/USDT', 'id' => 'ETHUSDT', 'base' => 'ETH', 'quote' => 'USDT',
            'spot' => true, 'active' => true,
            'precision' => ['amount' => 0.001, 'price' => 0.01],
            'limits' => ['amount' => ['min' => 0.01], 'cost' => ['min' => 1.0]],
        ];
        $this->client->stubMarkets = ['ETH/USDT' => $raw];

        $row = $this->market->product('ETH-USDT');

        $this->assertSame('ETH-USDT', $row['product_id']);
        $this->assertSame('ETH', $row['base_currency']);
        $this->assertSame('USDT', $row['quote_currency']);
        $this->assertSame('online', $row['status']);
        $this->assertFalse($row['trading_disabled']);
        $this->assertSame($raw, $row['raw']);
    }

    public function test_product_throws_for_an_unknown_id(): void
    {
        $this->client->stubMarkets = [];

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage('Unknown product NOPE-USD on stubex');

        $this->market->product('NOPE-USD');
    }

    public function test_candles_sort_ascending_and_drop_rows_past_the_end(): void
    {
        $this->client->stubOhlcv = [
            [1_757_000_120_000, 3.0, 3.5, 2.5, 3.2, 30.0],
            [1_757_000_060_000, 2.0, 2.5, 1.5, 2.2, 20.0],
            [1_757_000_240_000, 9.0, 9.5, 8.5, 9.2, 90.0],
            [1_757_000_000_000, 1.0, 1.5, 0.5, 1.2, 10.0],
        ];

        $rows = $this->market->candles('BTC-USD', '1m', 1_757_000_000, 1_757_000_180);

        $this->assertSame([
            ['start' => 1_757_000_000, 'open' => 1.0, 'high' => 1.5, 'low' => 0.5, 'close' => 1.2, 'volume' => 10.0],
            ['start' => 1_757_000_060, 'open' => 2.0, 'high' => 2.5, 'low' => 1.5, 'close' => 2.2, 'volume' => 20.0],
            ['start' => 1_757_000_120, 'open' => 3.0, 'high' => 3.5, 'low' => 2.5, 'close' => 3.2, 'volume' => 30.0],
        ], $rows);

        $this->assertSame(['fetch_ohlcv', 'BTC/USD', '1m', 1_757_000_000_000, 4], $this->client->calls[0]);
    }

    public function test_candles_request_is_capped_at_the_contract_maximum(): void
    {
        $this->market->candles('BTC-USD', '1m', 0, 86_400);

        $this->assertSame(CcxtMarketData::MAX_CANDLES, $this->client->calls[0][4]);
    }

    public function test_candles_maps_the_desk_timeframe_vocabulary(): void
    {
        $this->market->candles('BTC-USD', '1H', 0, 3_600);

        $this->assertSame('1h', $this->client->calls[0][2]);
    }

    public function test_candles_rejects_an_unknown_timeframe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown timeframe 4H');

        $this->market->candles('BTC-USD', '4H', 0, 3_600);
    }

    public function test_ticker_maps_quote_and_tape(): void
    {
        $this->client->stubTicker = ['bid' => 111.25, 'ask' => 111.75, 'last' => 111.5];
        $this->client->stubTrades = [
            ['id' => 't1', 'price' => 111.4, 'amount' => 0.25, 'side' => 'buy', 'timestamp' => 1_757_000_000_123],
            ['id' => 't2', 'price' => 111.6, 'amount' => 0.5, 'side' => 'sell', 'timestamp' => 1_757_000_001_999],
        ];

        $ticker = $this->market->ticker('BTC-USD', 25);

        $this->assertSame(111.25, $ticker['best_bid']);
        $this->assertSame(111.75, $ticker['best_ask']);
        $this->assertSame('ccxt', $ticker['source']);
        $this->assertSame([
            ['price' => 111.4, 'size' => 0.25, 'side' => 'buy', 'time' => 1_757_000_000],
            ['price' => 111.6, 'size' => 0.5, 'side' => 'sell', 'time' => 1_757_000_001],
        ], $ticker['trades']);
        $this->assertSame(['fetch_trades', 'BTC/USD', null, 25], $this->client->calls[1]);
    }

    public function test_trades_are_trimmed_to_the_end_of_the_window(): void
    {
        $this->client->stubTrades = [
            ['id' => 'a', 'price' => 10.0, 'amount' => 1.0, 'side' => 'buy', 'timestamp' => 1_757_000_000_500],
            ['id' => 'b', 'price' => 11.0, 'amount' => 2.0, 'side' => 'sell', 'timestamp' => 1_757_000_060_000],
            ['id' => 'c', 'price' => 12.0, 'amount' => 3.0, 'side' => 'buy', 'timestamp' => 1_757_000_121_000],
        ];

        $rows = $this->market->trades('BTC-USD', 1_757_000_000, 1_757_000_120, 500);

        $this->assertSame([
            ['trade_id' => 'a', 'time' => 1_757_000_000, 'time_ms' => 1_757_000_000_500, 'price' => 10.0, 'size' => 1.0, 'side' => 'buy'],
            ['trade_id' => 'b', 'time' => 1_757_000_060, 'time_ms' => 1_757_000_060_000, 'price' => 11.0, 'size' => 2.0, 'side' => 'sell'],
        ], $rows);

        $this->assertSame(['fetch_trades', 'BTC/USD', 1_757_000_000_000, 500], $this->client->calls[0]);
    }

    public function test_trades_are_sorted_ascending_by_time_ms_regardless_of_venue_order(): void
    {
        $this->client->stubTrades = [
            ['id' => 'b', 'price' => 11.0, 'amount' => 2.0, 'side' => 'sell', 'timestamp' => 1_757_000_060_000],
            ['id' => 'a', 'price' => 10.0, 'amount' => 1.0, 'side' => 'buy', 'timestamp' => 1_757_000_000_500],
        ];

        $rows = $this->market->trades('BTC-USD', 1_757_000_000, 1_757_000_120, 500);

        $this->assertSame(['a', 'b'], array_column($rows, 'trade_id'));
    }

    public function test_book_casts_levels_to_floats(): void
    {
        $this->client->stubBook = [
            'bids' => [['100.5', '1.25'], ['100.4', '2.5']],
            'asks' => [['100.6', '0.75']],
        ];

        $book = $this->market->book('BTC-USD', 10);

        $this->assertSame(['bids' => [[100.5, 1.25], [100.4, 2.5]], 'asks' => [[100.6, 0.75]]], [
            'bids' => $book['bids'],
            'asks' => $book['asks'],
        ]);
        $this->assertIsInt($book['ts']);
    }

    public function test_book_is_cut_to_depth_locally_without_forwarding_a_limit(): void
    {
        $this->client->stubBook = [
            'bids' => [[9.0, 1.0], [8.0, 1.0], [7.0, 1.0]],
            'asks' => [[10.0, 1.0], [11.0, 1.0], [12.0, 1.0]],
        ];

        $book = $this->market->book('BTC-USD', 2);

        $this->assertSame([[9.0, 1.0], [8.0, 1.0]], $book['bids']);
        $this->assertSame([[10.0, 1.0], [11.0, 1.0]], $book['asks']);

        // KuCoin only accepts a limit of 20 or 100, so the depth never reaches the venue.
        $this->assertSame(['fetch_order_book', 'BTC/USD', null], $this->client->calls[0]);
    }

    public function test_price_returns_last_or_null(): void
    {
        $this->client->stubTicker = ['last' => 42.5];
        $this->assertSame(42.5, $this->market->price('BTC-USD'));

        $this->client->stubTicker = ['last' => 0];
        $this->assertNull($this->market->price('BTC-USD'));

        $this->client->stubTicker = [];
        $this->assertNull($this->market->price('BTC-USD'));
    }

    public function test_healthy_falls_back_to_fetch_time_when_status_is_unsupported(): void
    {
        $this->client->has['fetchStatus'] = false;

        $this->assertTrue($this->market->healthy());
        $this->assertSame(['fetch_time'], $this->client->calls[0]);
    }

    public function test_ccxt_failures_surface_as_exchange_exception(): void
    {
        $client = new class([]) extends \ccxt\Exchange
        {
            public function fetch_ticker(string $symbol, $params = [])
            {
                throw new \ccxt\NetworkError('connection reset');
            }
        };

        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage('stubex: connection reset');

        (new CcxtMarketData($client, 'stubex'))->price('BTC-USD');
    }
}
