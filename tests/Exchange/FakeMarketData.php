<?php

declare(strict_types=1);

namespace Tests\Exchange;

use App\Exchange\Contracts\MarketData;

/** In-memory MarketData: every method returns a caller-configurable canned value, no HTTP. */
final class FakeMarketData implements MarketData
{
    /** @var array<int, array<string, mixed>> */
    public array $productRows = [];

    /** @var array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    public array $candleRows = [];

    public array $tickerData = ['best_bid' => 0.0, 'best_ask' => 0.0, 'trades' => [], 'source' => 'fake', 'ts' => 0];

    /** @var array<int, array{trade_id:string,time:int,time_ms:int,price:float,size:float,side:string}> */
    public array $tradeRows = [];

    public array $bookData = ['bids' => [], 'asks' => [], 'ts' => 0];

    public ?float $priceValue = null;

    public bool $isHealthy = true;

    public function products(): array
    {
        return $this->productRows;
    }

    public function product(string $productId): array
    {
        foreach ($this->productRows as $row) {
            if (($row['product_id'] ?? null) === $productId) {
                return $row;
            }
        }

        return [];
    }

    public function candles(string $productId, string $timeframe, int $fromTs, int $toTs): array
    {
        return $this->candleRows;
    }

    public function ticker(string $productId, int $tradeLimit = 100): array
    {
        return $this->tickerData;
    }

    public function trades(string $productId, int $fromTs, int $toTs, int $limit = 1000): array
    {
        return $this->tradeRows;
    }

    public function book(string $productId, int $depth = 50): array
    {
        return $this->bookData;
    }

    public function price(string $productId): ?float
    {
        return $this->priceValue;
    }

    public function healthy(): bool
    {
        return $this->isHealthy;
    }
}
