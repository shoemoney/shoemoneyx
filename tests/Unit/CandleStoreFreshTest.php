<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Market\CandleStore;
use App\Exchange\Coinbase\CoinbaseMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CandleStoreFreshTest extends TestCase
{
    use RefreshDatabase;

    private function store(): CandleStore
    {
        return new CandleStore($this->createMock(CoinbaseMarketData::class));
    }

    private function insertCandle(string $productId, string $timeframe, \DateTimeInterface $updatedAt): void
    {
        DB::table('candles')->insert([
            'product_id' => $productId,
            'timeframe' => $timeframe,
            'candle_start' => gmdate('Y-m-d H:i:s', $updatedAt->getTimestamp()),
            'open' => 100.0, 'high' => 100.0, 'low' => 100.0, 'close' => 100.0,
            'volume' => 1.0,
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    public function test_fresh_when_the_newest_row_updated_10_seconds_ago(): void
    {
        $this->insertCandle('BTC-USD', '1H', now()->subSeconds(10));

        $this->assertTrue($this->store()->fresh('BTC-USD', '1H', 300));
    }

    public function test_not_fresh_when_the_newest_row_updated_10_minutes_ago(): void
    {
        $this->insertCandle('BTC-USD', '1H', now()->subMinutes(10));

        $this->assertFalse($this->store()->fresh('BTC-USD', '1H', 300));
    }

    public function test_not_fresh_with_no_rows(): void
    {
        $this->assertFalse($this->store()->fresh('ETH-USD', '1H', 300));
    }
}
