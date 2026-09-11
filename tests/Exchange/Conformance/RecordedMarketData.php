<?php

declare(strict_types=1);

namespace Tests\Exchange\Conformance;

use App\Exchange\Contracts\MarketData;

/**
 * Replays a fixture directory captured by `php artisan exchange:record` as a MarketData
 * implementation, so a conformance suite exercises real recorded payloads with zero network
 * calls and no credentials. Fixture files: products.json, product.json, candles.json,
 * ticker.json, trades.json, book.json -- see ExchangeRecordCommand for the capture format.
 */
final class RecordedMarketData implements MarketData
{
    /** @var array<int, array<string, mixed>> */
    private array $products;

    /** @var array<string, mixed> */
    private array $product;

    /** @var array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    private array $candles;

    /** @var array{best_bid:float,best_ask:float,trades:array<int,array<string,mixed>>,source:string,ts:int} */
    private array $ticker;

    /** @var array<int, array<string, mixed>> */
    private array $trades;

    /** @var array{bids: array<int, array{0: float, 1: float}>, asks: array<int, array{0: float, 1: float}>} */
    private array $book;

    public function __construct(private readonly string $fixtureDir)
    {
        $this->products = $this->load('products.json');
        $this->product = $this->load('product.json');
        $this->candles = $this->load('candles.json');
        $this->ticker = $this->load('ticker.json');
        $this->trades = $this->load('trades.json');
        $this->book = $this->load('book.json');
    }

    public function products(): array
    {
        return $this->products;
    }

    public function product(string $productId): array
    {
        return $this->product;
    }

    public function candles(string $productId, string $timeframe, int $fromTs, int $toTs): array
    {
        return $this->candles;
    }

    public function ticker(string $productId, int $tradeLimit = 100): array
    {
        return $this->ticker;
    }

    public function trades(string $productId, int $fromTs, int $toTs, int $limit = 1000): array
    {
        return $this->trades;
    }

    public function book(string $productId, int $depth = 50): array
    {
        return $this->book;
    }

    /** Mid of the recorded ticker's best bid/ask for the recorded product; null for anything else. */
    public function price(string $productId): ?float
    {
        if (($this->product['product_id'] ?? null) !== $productId) {
            return null;
        }

        $bid = (float) ($this->ticker['best_bid'] ?? 0);
        $ask = (float) ($this->ticker['best_ask'] ?? 0);

        return $bid > 0 && $ask > 0 ? ($bid + $ask) / 2 : null;
    }

    /** Fixtures are frozen and known-good by construction; replay is always "healthy". */
    public function healthy(): bool
    {
        return true;
    }

    private function load(string $file): array
    {
        $path = $this->fixtureDir.'/'.$file;
        if (! is_file($path)) {
            throw new \RuntimeException("Missing fixture {$path}. Run: php artisan exchange:record <id> <product>");
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
