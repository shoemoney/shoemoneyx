<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Exchange\Contracts\MarketData;
use App\Exchange\ExchangeException;

/**
 * Public market data through ccxt's unified REST API — no credentials needed.
 * Every venue call routes through call() so callers only ever catch ExchangeException.
 */
class CcxtMarketData implements MarketData
{
    /** Desk timeframe vocabulary -> ccxt unified timeframe. */
    public const TIMEFRAMES = [
        '1m' => '1m', '5m' => '5m', '15m' => '15m',
        '30m' => '30m', '1H' => '1h', '6H' => '6h', '1D' => '1d',
    ];

    /** Seconds per desk timeframe, used to size the candle request. */
    private const SECONDS = [
        '1m' => 60, '5m' => 300, '15m' => 900,
        '30m' => 1800, '1H' => 3600, '6H' => 21600, '1D' => 86400,
    ];

    public function __construct(private \ccxt\Exchange $client, private string $ccxtId) {}

    /**
     * Active spot markets mapped into the canonical row shape (see MarketData::products()).
     * Price and 24h volume stay null: filling them would cost one network call per product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function products(): array
    {
        $markets = $this->call(fn () => $this->client->load_markets());

        $rows = [];
        foreach ($markets as $m) {
            if (! ($m['spot'] ?? false) || ! ($m['active'] ?? false)) {
                continue;
            }

            $rows[] = $this->normalizeProductRow($m);
        }

        return $rows;
    }

    public function product(string $productId): array
    {
        $markets = $this->call(fn () => $this->client->load_markets());
        $symbol = Symbols::toCcxt($productId);
        $market = $markets[$symbol]
            ?? throw new ExchangeException("Unknown product {$productId} on {$this->ccxtId}");

        return $this->normalizeProductRow($market);
    }

    /** ccxt's unified market row -> the canonical shape MarketData::products() documents. */
    private function normalizeProductRow(array $m): array
    {
        return [
            'product_id' => Symbols::fromCcxt((string) ($m['symbol'] ?? '')),
            'base_currency' => strtoupper((string) ($m['base'] ?? '')),
            'quote_currency' => strtoupper((string) ($m['quote'] ?? '')),
            'status' => ($m['active'] ?? false) ? 'online' : 'offline',
            'price' => null,
            'price_change_24h_pct' => null,
            'volume_24h' => null,
            'volume_24h_usd' => null,
            'base_increment' => $this->increment($m['precision']['amount'] ?? null),
            'quote_increment' => $this->increment($m['precision']['price'] ?? null),
            'base_min_size' => $this->floatOrNull($m['limits']['amount']['min'] ?? null),
            'quote_min_size' => $this->floatOrNull($m['limits']['cost']['min'] ?? null),
            'trading_disabled' => ! ($m['active'] ?? false),
            'listed_at' => null,
            'raw' => $m,
        ];
    }

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    public function candles(string $productId, string $timeframe, int $fromTs, int $toTs): array
    {
        $tf = self::TIMEFRAMES[$timeframe] ?? throw new \InvalidArgumentException("Unknown timeframe {$timeframe}");
        $span = max(1, $toTs - $fromTs);
        $limit = min((int) ceil($span / self::SECONDS[$timeframe]) + 1, self::MAX_CANDLES);

        $raw = $this->call(fn () => $this->client->fetch_ohlcv(
            Symbols::toCcxt($productId),
            $tf,
            $fromTs * 1000,
            $limit,
        ));

        $rows = [];
        foreach ($raw as $c) {
            $start = (int) ((int) $c[0] / 1000);
            if ($start > $toTs) {
                continue;
            }
            $rows[] = [
                'start' => $start,
                'open' => (float) $c[1],
                'high' => (float) $c[2],
                'low' => (float) $c[3],
                'close' => (float) $c[4],
                'volume' => (float) $c[5],
            ];
        }

        usort($rows, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $rows;
    }

    /** @return array{best_bid:float,best_ask:float,trades:array<int, array{price:float,size:float,side:string,time:int}>,source:string,ts:int} */
    public function ticker(string $productId, int $tradeLimit = 100): array
    {
        $symbol = Symbols::toCcxt($productId);
        $t = $this->call(fn () => $this->client->fetch_ticker($symbol));
        $tape = $this->call(fn () => $this->client->fetch_trades($symbol, null, $tradeLimit));

        return [
            'best_bid' => (float) ($t['bid'] ?? 0),
            'best_ask' => (float) ($t['ask'] ?? 0),
            'trades' => array_map(fn ($x) => [
                'price' => (float) ($x['price'] ?? 0),
                'size' => (float) ($x['amount'] ?? 0),
                'side' => strtolower((string) ($x['side'] ?? '')),
                'time' => intdiv((int) ($x['timestamp'] ?? 0), 1000),
            ], $tape),
            'source' => 'ccxt',
            'ts' => time(),
        ];
    }

    /**
     * ccxt's since/limit pair has no upper bound, so the $toTs edge is trimmed here.
     * Sorted ascending by time_ms to satisfy the contract regardless of what the venue returned.
     *
     * @return array<int, array{trade_id:string,time:int,time_ms:int,price:float,size:float,side:string}>
     */
    public function trades(string $productId, int $fromTs, int $toTs, int $limit = 1000): array
    {
        $raw = $this->call(fn () => $this->client->fetch_trades(Symbols::toCcxt($productId), $fromTs * 1000, $limit));

        $rows = [];
        foreach ($raw as $t) {
            $ms = (int) ($t['timestamp'] ?? 0);
            $time = intdiv($ms, 1000);
            if ($time > $toTs) {
                continue;
            }
            $rows[] = [
                'trade_id' => (string) ($t['id'] ?? ''),
                'time' => $time,
                'time_ms' => $ms,
                'price' => (float) ($t['price'] ?? 0),
                'size' => (float) ($t['amount'] ?? 0),
                'side' => strtolower((string) ($t['side'] ?? '')),
            ];
        }

        usort($rows, fn ($a, $b) => $a['time_ms'] <=> $b['time_ms']);

        return $rows;
    }

    /**
     * Venues disagree on which depths they accept — KuCoin rejects anything but 20 or 100 — so
     * the depth is never forwarded. Ask for the venue's own book and cut it to $depth here.
     */
    public function book(string $productId, int $depth = 50): array
    {
        $b = $this->call(fn () => $this->client->fetch_order_book(Symbols::toCcxt($productId)));

        $side = fn ($levels) => array_map(
            fn ($l) => [(float) $l[0], (float) $l[1]],
            array_slice($levels, 0, $depth),
        );

        return [
            'bids' => $side($b['bids'] ?? []),
            'asks' => $side($b['asks'] ?? []),
            'ts' => time(),
        ];
    }

    public function price(string $productId): ?float
    {
        $last = (float) ($this->call(fn () => $this->client->fetch_ticker(Symbols::toCcxt($productId)))['last'] ?? 0);

        return $last > 0 ? $last : null;
    }

    public function healthy(): bool
    {
        try {
            if ($this->client->has['fetchStatus'] ?? false) {
                $this->client->fetch_status();
            } else {
                $this->client->fetch_time();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ccxt reports precision either as a tick size (a float increment, precisionMode TICK_SIZE)
     * or as a count of decimal places (an int). Both land here as the tick string the desk stores.
     */
    private function increment(mixed $precision): ?string
    {
        if ($precision === null || $precision === '') {
            return null;
        }

        if ($this->client->precisionMode === \ccxt\TICK_SIZE) {
            return $this->tickString((float) $precision);
        }

        return $this->tickString(10 ** -(int) $precision);
    }

    /** ccxt's own formatter, so tiny ticks land as 0.00000001 rather than 1.0E-8. */
    private function tickString(float $tick): ?string
    {
        return $tick > 0 ? \ccxt\Exchange::number_to_string($tick) : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /** Every ccxt failure becomes one exception type, so callers do not import ccxt's error tree. */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            throw new ExchangeException("{$this->ccxtId}: {$e->getMessage()}", 0, $e);
        }
    }
}
