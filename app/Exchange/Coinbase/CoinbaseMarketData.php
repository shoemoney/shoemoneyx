<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Exchange\Contracts\MarketData;
use App\Services\Market\LiveFeed;
use App\Services\Market\MarketDataException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Coinbase Advanced Trade PUBLIC market endpoints — no API key required.
 * Powers SCAN, the candle store, and the TradingView datafeed, so paper mode
 * runs end-to-end without any credentials.
 */
class CoinbaseMarketData implements MarketData
{
    public const GRANULARITY = [
        '1m' => 'ONE_MINUTE', '5m' => 'FIVE_MINUTE', '15m' => 'FIFTEEN_MINUTE',
        '30m' => 'THIRTY_MINUTE', '1H' => 'ONE_HOUR', '6H' => 'SIX_HOUR', '1D' => 'ONE_DAY',
    ];

    public const MAX_CANDLES = 350;

    /** @return array<int, array<string, mixed>> canonical product rows, see MarketData::products() */
    public function products(): array
    {
        $rows = Cache::remember('cb:products', 60, function () {
            $json = $this->get('/products', ['product_type' => 'SPOT']);

            return $json['products'] ?? [];
        });

        return array_map($this->normalizeProduct(...), $rows);
    }

    public function product(string $productId): array
    {
        return $this->normalizeProduct($this->get('/products/'.$productId));
    }

    /** Coinbase's raw product row -> the canonical shape MarketData::products() documents. */
    private function normalizeProduct(array $row): array
    {
        $price = isset($row['price']) ? (float) $row['price'] : null;
        $volume = isset($row['volume_24h']) ? (float) $row['volume_24h'] : null;

        return [
            'product_id' => (string) ($row['product_id'] ?? ''),
            'base_currency' => strtoupper((string) ($row['base_currency_id'] ?? '')),
            'quote_currency' => strtoupper((string) ($row['quote_currency_id'] ?? '')),
            'status' => (string) ($row['status'] ?? 'online'),
            'price' => $price,
            'price_change_24h_pct' => isset($row['price_percentage_change_24h']) ? (float) $row['price_percentage_change_24h'] : null,
            'volume_24h' => $volume,
            'volume_24h_usd' => $price !== null && $volume !== null ? $price * $volume : null,
            'base_increment' => isset($row['base_increment']) ? (string) $row['base_increment'] : null,
            'quote_increment' => isset($row['quote_increment']) ? (string) $row['quote_increment'] : null,
            'base_min_size' => isset($row['base_min_size']) ? (float) $row['base_min_size'] : null,
            'quote_min_size' => isset($row['quote_min_size']) ? (float) $row['quote_min_size'] : null,
            // Coinbase spreads "can't trade this" across four flags; the desk only needs one bool.
            'trading_disabled' => (bool) ($row['trading_disabled'] ?? false)
                || (bool) ($row['cancel_only'] ?? false)
                || (bool) ($row['is_disabled'] ?? false)
                || (bool) ($row['view_only'] ?? false),
            'listed_at' => null,
            'raw' => $row,
        ];
    }

    /**
     * Candles oldest -> newest. Coinbase returns newest first and caps at 350 per call.
     *
     * @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public function candles(string $productId, string $timeframe, int $startUnix, int $endUnix): array
    {
        $g = self::GRANULARITY[$timeframe] ?? throw new \InvalidArgumentException("Unknown timeframe {$timeframe}");
        $json = $this->get("/products/{$productId}/candles", [
            'start' => (string) $startUnix,
            'end' => (string) $endUnix,
            'granularity' => $g,
        ]);

        $rows = array_map(fn ($c) => [
            'start' => (int) $c['start'],
            'open' => (float) $c['open'],
            'high' => (float) $c['high'],
            'low' => (float) $c['low'],
            'close' => (float) $c['close'],
            'volume' => (float) $c['volume'],
        ], $json['candles'] ?? []);

        usort($rows, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $rows;
    }

    /** Recent trades + best bid/ask. Websocket tape when the feeder is up, else REST (cached briefly). */
    public function ticker(string $productId, int $limit = 100): array
    {
        $feed = app(LiveFeed::class);
        $q = $feed->quote($productId);
        if ($q !== null) {
            $tape = $feed->tape($productId, max($limit, 600)) ?? [];
            if ($tape !== [] || $limit <= 1) {
                return ['best_bid' => $q['bid'] ?? 0.0, 'best_ask' => $q['ask'] ?? 0.0, 'trades' => $tape, 'source' => 'ws', 'ts' => (int) ($q['ts'] ?? time())];
            }
        }

        return Cache::remember("cb:ticker:{$productId}:{$limit}", 20, function () use ($productId, $limit) {
            $json = $this->get("/products/{$productId}/ticker", ['limit' => $limit]);

            return [
                'best_bid' => (float) ($json['best_bid'] ?? 0),
                'best_ask' => (float) ($json['best_ask'] ?? 0),
                'trades' => array_map(fn ($t) => [
                    'price' => (float) $t['price'],
                    'size' => (float) $t['size'],
                    'side' => strtolower($t['side'] ?? ''),
                    'time' => strtotime($t['time'] ?? 'now') ?: time(),
                ], $json['trades'] ?? []),
                'source' => 'rest',
                // Captured once, inside the cached closure, so a cache hit correctly reports the
                // age of the SERVED (possibly stale-by-up-to-20s) quote, not "now".
                'ts' => time(),
            ];
        });
    }

    /**
     * Historical trades in [$start, $end] (unix), sorted ascending by time_ms, up to 1000 per call.
     *
     * @return array<int, array{trade_id:string,time:int,time_ms:int,price:float,size:float,side:string}>
     */
    public function trades(string $productId, int $start, int $end, int $limit = 1000): array
    {
        $json = $this->get("/products/{$productId}/ticker", ['limit' => $limit, 'start' => (string) $start, 'end' => (string) $end]);

        $rows = array_map(fn ($t) => [
            'trade_id' => (string) $t['trade_id'],
            'time' => strtotime($t['time']) ?: 0,
            'time_ms' => (int) round(((float) strtotime(substr($t['time'], 0, 19).'Z')) * 1000 + (float) ('0'.substr($t['time'], 19, 7)) * 1000),
            'price' => (float) $t['price'],
            'size' => (float) $t['size'],
            'side' => strtolower($t['side'] ?? ''),
        ], $json['trades'] ?? []);

        usort($rows, fn ($a, $b) => $a['time_ms'] <=> $b['time_ms']);

        return $rows;
    }

    /** Order book top-N levels: bids descending by price, asks ascending, as Coinbase returns them. */
    public function book(string $productId, int $limit = 50): array
    {
        $json = $this->get('/product_book', ['product_id' => $productId, 'limit' => $limit]);
        $pb = $json['pricebook'] ?? [];

        return [
            'bids' => array_map(fn ($l) => [(float) $l['price'], (float) $l['size']], $pb['bids'] ?? []),
            'asks' => array_map(fn ($l) => [(float) $l['price'], (float) $l['size']], $pb['asks'] ?? []),
            'ts' => time(),
        ];
    }

    /** Mid price with a 5s cache; used for marking positions. */
    public function price(string $productId): ?float
    {
        // Websocket feed first (sub-second), REST fallback.
        $q = app(LiveFeed::class)->quote($productId);
        if ($q !== null) {
            return $q['bid'] && $q['ask'] ? ($q['bid'] + $q['ask']) / 2 : $q['price'];
        }

        // Redis returns numerics as strings — cast on the way out.
        $v = Cache::remember("cb:price:{$productId}", 5, function () use ($productId) {
            $t = $this->ticker($productId, 1);
            if ($t['best_bid'] > 0 && $t['best_ask'] > 0) {
                return ($t['best_bid'] + $t['best_ask']) / 2;
            }

            return $t['trades'][0]['price'] ?? 0;
        });

        return $v > 0 ? (float) $v : null;
    }

    public function healthy(): bool
    {
        try {
            $r = Http::timeout(8)->get(config('coinbase.public_base_url').'/products', ['limit' => 1, 'product_type' => 'SPOT']);

            return $r->ok() && is_array($r->json());
        } catch (\Throwable) {
            return false;
        }
    }

    private function get(string $path, array $query = []): array
    {
        $started = microtime(true);
        $response = Http::acceptJson()
            ->timeout((int) config('coinbase.timeout', 30))
            ->connectTimeout((int) config('coinbase.connect_timeout', 10))
            ->retry(3, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->get(config('coinbase.public_base_url').$path, $query);
        $elapsed = microtime(true) - $started;
        if ($elapsed > 5.0 && $response->status() !== 429) {
            Log::warning("coinbase public {$path} took {$elapsed}s");
        }

        // Public rate limit: back off and retry rather than fail a long walk (trade backfills run for an hour).
        foreach ([2, 4, 8, 16, 30] as $wait) {
            if ($response->status() !== 429) {
                break;
            }
            $seconds = max($wait, (int) $response->header('Retry-After'));
            Log::warning("coinbase public {$path} 429, backing off {$seconds}s", [
                'path' => $path,
                'wait' => $seconds,
                'retry_after' => $response->header('Retry-After'),
            ]);
            sleep($seconds);
            $response = Http::acceptJson()->timeout(30)->get(config('coinbase.public_base_url').$path, $query);
        }

        if ($response->failed()) {
            throw new MarketDataException("Coinbase public {$path} failed: HTTP {$response->status()} ".substr((string) $response->body(), 0, 200), $response->status());
        }

        return $response->json() ?? [];
    }
}
