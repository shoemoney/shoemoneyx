<?php

declare(strict_types=1);

namespace App\Exchange\Contracts;

/**
 * Public (no-credential) market data: products, candles, ticker/trade tape, book, mark price.
 * Every exchange adapter's MarketData implementation is safe to call with no stored account.
 *
 * One canonical shape per method, normalized by the adapter — never the caller. Every venue's
 * own field names, casing, and quirks (Coinbase's base_currency_id, uppercase BUY/SELL, book
 * levels as keyed arrays; ccxt's own unified-but-different vocabulary) are translated at the
 * adapter boundary. A caller that reads these shapes never needs to know which venue answered.
 *
 * Product row: {product_id:string, base_currency:string, quote_currency:string, status:string,
 * price:?float, price_change_24h_pct:?float, volume_24h:?float, volume_24h_usd:?float,
 * base_increment:?string, quote_increment:?string, base_min_size:?float, quote_min_size:?float,
 * trading_disabled:bool, listed_at:?string (ISO 8601 or null), raw:array<string,mixed>}. `raw`
 * carries the untranslated venue row so a caller that needs a venue-specific field can still
 * reach it without every adapter inventing new top-level keys for one-off needs.
 */
interface MarketData
{
    /** Coinbase caps a single candles() call at this many rows; other venues may differ. */
    public const MAX_CANDLES = 350;

    /** @return array<int, array{product_id:string, base_currency:string, quote_currency:string, status:string, price:?float, price_change_24h_pct:?float, volume_24h:?float, volume_24h_usd:?float, base_increment:?string, quote_increment:?string, base_min_size:?float, quote_min_size:?float, trading_disabled:bool, listed_at:?string, raw:array<string,mixed>}> */
    public function products(): array;

    /** @return array{product_id:string, base_currency:string, quote_currency:string, status:string, price:?float, price_change_24h_pct:?float, volume_24h:?float, volume_24h_usd:?float, base_increment:?string, quote_increment:?string, base_min_size:?float, quote_min_size:?float, trading_disabled:bool, listed_at:?string, raw:array<string,mixed>} */
    public function product(string $productId): array;

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> oldest -> newest, unique start */
    public function candles(string $productId, string $timeframe, int $fromTs, int $toTs): array;

    /** @return array{best_bid:float,best_ask:float,trades:array<int, array{price:float,size:float,side:'buy'|'sell',time:int}>,source:string,ts:int} trades' side is always lowercase 'buy'|'sell' */
    public function ticker(string $productId, int $tradeLimit = 100): array;

    /** @return array<int, array{trade_id:string,time:int,time_ms:int,price:float,size:float,side:'buy'|'sell'}> ascending by time_ms, side always lowercase */
    public function trades(string $productId, int $fromTs, int $toTs, int $limit = 1000): array;

    /** @return array{bids:array<int, array{0:float,1:float}>, asks:array<int, array{0:float,1:float}>, ts:int} bids [price,size] sorted descending by price, asks ascending */
    public function book(string $productId, int $depth = 50): array;

    public function price(string $productId): ?float;

    public function healthy(): bool;
}
