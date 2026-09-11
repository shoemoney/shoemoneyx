<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Desk\Data\ProductStats;
use App\Desk\Execution\Perps;
use App\Exchange\Contracts\MarketData;
use App\Models\Product;
use App\Services\Indicators\Indicators;

/**
 * Assembles a ProductStats row — the desk's "launch data" — from the candle
 * store (1H bars), the public ticker (recent trades, best bid/ask) and,
 * optionally, the order book.
 */
class ProductStatsBuilder
{
    public function __construct(
        private MarketData $market,
        private CandleStore $candles,
    ) {}

    /** Live stats for one product (1 ticker call, +1 book call when $withBook). */
    public function live(Product $product, bool $withBook = false): ProductStats
    {
        $pid = $product->product_id;
        $now = time();
        $bars = $this->candles->bars($pid, '1H', $now - 50 * 3600);
        $ticker = $this->market->ticker($pid, 100);

        $base = $this->fromBars($pid, $bars, $now, $product->ageHours());
        $a = $base->jsonSerialize();

        // Trade tape (a sample: the last 100 prints) -> buys/sells in the last hour and five minutes.
        $b1 = $s1 = $b5 = $s5 = 0;
        $bv = $sv = 0.0;
        $oldest = $now;
        foreach ($ticker['trades'] as $t) {
            $age = $now - $t['time'];
            $oldest = min($oldest, $t['time']);
            $usd = $t['price'] * $t['size'];
            if ($age <= 3600) {
                $t['side'] === 'buy' ? $b1++ : $s1++;
                $t['side'] === 'buy' ? $bv += $usd : $sv += $usd;
            }
            if ($age <= 300) {
                $t['side'] === 'buy' ? $b5++ : $s5++;
            }
        }
        $a['buys_h1'] = $b1;
        $a['sells_h1'] = $s1;
        $a['buys_m5'] = $b5;
        $a['sells_m5'] = $s5;
        $a['buy_volume_h1_usd'] = $bv;
        $a['sell_volume_h1_usd'] = $sv;
        $a['extra']['tape_span_seconds'] = $now - $oldest;
        $a['extra']['tape_trades'] = count($ticker['trades']);
        $a['extra']['tape_source'] = $ticker['source'] ?? 'rest';
        $a['tape_sample_size'] = count($ticker['trades']);
        $a['quote_age_sec'] = (float) max(0, $now - (int) ($ticker['ts'] ?? $now));

        if ($ticker['best_bid'] > 0 && $ticker['best_ask'] > 0) {
            $mid = ($ticker['best_bid'] + $ticker['best_ask']) / 2;
            $a['best_bid'] = $ticker['best_bid'];
            $a['best_ask'] = $ticker['best_ask'];
            $a['price'] = $mid;
            $a['spread_bps'] = round(($ticker['best_ask'] - $ticker['best_bid']) / $mid * 10_000, 2);
        } elseif (! empty($ticker['trades'])) {
            $a['price'] = $ticker['trades'][0]['price'];
        }

        if ($withBook) {
            $a = $this->attachBook($a, $pid, (float) $a['price']);
        }

        return ProductStats::fromArray($a);
    }

    /** Adds order-book depth (both sides, plus raw levels) to an existing row — used for the shortlist only, to spare rate limits. */
    public function withBook(ProductStats $s): ProductStats
    {
        return ProductStats::fromArray($this->attachBook($s->jsonSerialize(), $s->productId, $s->price));
    }

    /** Merges a fresh book snapshot's fields into a serialized ProductStats row. */
    private function attachBook(array $a, string $productId, float $mid): array
    {
        $detail = $this->fetchBookDetail($productId, $mid);
        $a['book_depth_usd'] = $detail['bid_depth_usd'] ?? null;   // compatibility alias — bids, as before
        $a['bid_depth_usd'] = $detail['bid_depth_usd'] ?? null;
        $a['ask_depth_usd'] = $detail['ask_depth_usd'] ?? null;
        $a['book_levels'] = $detail !== null ? ['bids' => $detail['bids'], 'asks' => $detail['asks'], 'ref_price' => $detail['ref_price']] : null;
        // quote_age_sec must reflect the STALER of the two inputs behind this row: the book fetch
        // itself (synchronous and uncached, so ~0s old at capture time) and the ticker quote that
        // produced $mid, which may have been captured well before this call (a cached REST ticker,
        // or simply time elapsed since scan). Overwriting to 0.0 on every successful book fetch
        // defeated the staleness gate by reporting a stale mid as fresh. A failed fetch, or a row
        // whose ticker age was never known at all, means "unavailable" and is left untouched — the
        // depth-gate already treats null as maximally stale.
        if ($detail !== null && isset($a['quote_age_sec'])) {
            $a['quote_age_sec'] = max((float) $a['quote_age_sec'], 0.0);
        }
        if ($detail !== null && $detail['basis_bps'] !== null) {
            $a['extra']['perps_basis_bps'] = $detail['basis_bps'];
            $a['extra']['perps_book_product_id'] = $detail['book_product_id'];
        }

        return $a;
    }

    /**
     * Fetches the order book backing the executable-liquidity check for one product: the mapped
     * perp contract's own book when perps are enabled and this spot symbol has a Perps::spec()
     * entry, the spot book otherwise. Returns both sides' depth within 1% of the reference mid,
     * the raw top-N levels (for ProductStats::costToTradeUsd()), and — when a contract book was
     * used — the spot/contract basis in bps.
     *
     * Perp books quote `size` in CONTRACTS, not base units (spot books quote base units
     * directly) — a DOGE-PERP level of size 1 is 1 contract = 5,000 DOGE, not 1 DOGE. Every
     * level pulled from a mapped contract book is scaled by contract_size in {@see scaleLevels()}
     * before it is used for depth or stored for costToTradeUsd(); skipping that step understates
     * (or, for a sub-1 contract_size like BTC's 0.01, wildly overstates) USD depth and slippage.
     *
     * Protected (not private) so a test can subclass this builder, inject a fake MarketData
     * that returns a canned perp book (in contracts), and call this method directly to assert
     * the contract_size conversion without any HTTP call.
     *
     * @return array{bid_depth_usd:float, ask_depth_usd:float, bids:array, asks:array, ref_price:float, basis_bps:?float, book_product_id:string}|null
     */
    protected function fetchBookDetail(string $spotProductId, float $mid): ?array
    {
        if ($mid <= 0) {
            return null;
        }

        $bookProductId = $spotProductId;
        $spec = Perps::enabled() ? Perps::spec($spotProductId) : null;
        if ($spec !== null) {
            $bookProductId = $spec['product_id'];
        }

        try {
            $book = $this->market->book($bookProductId, 100);
        } catch (\Throwable) {
            return null;
        }

        $bids = $book['bids'] ?? [];
        $asks = $book['asks'] ?? [];

        if ($spec !== null) {
            $bids = $this->scaleLevels($bids, $spec['contract_size']);
            $asks = $this->scaleLevels($asks, $spec['contract_size']);
        }

        $refPrice = $mid;
        $basisBps = null;
        if ($spec !== null && $bids !== [] && $asks !== []) {
            $contractMid = ($bids[0][0] + $asks[0][0]) / 2;
            if ($contractMid > 0) {
                $refPrice = $contractMid;
                $basisBps = round(($contractMid - $mid) / $mid * 10_000, 2);
            }
        }

        return [
            'bid_depth_usd' => $this->depthWithinBand($bids, $refPrice * 0.99, isBid: true),
            'ask_depth_usd' => $this->depthWithinBand($asks, $refPrice * 1.01, isBid: false),
            'bids' => $bids,
            'asks' => $asks,
            'ref_price' => $refPrice,
            'basis_bps' => $basisBps,
            'book_product_id' => $bookProductId,
        ];
    }

    /** Converts perp book levels from CONTRACTS to base units (price unchanged, size *= contract_size). */
    private function scaleLevels(array $levels, float $contractSize): array
    {
        return array_map(fn ($l) => [$l[0], $l[1] * $contractSize], $levels);
    }

    /** USD depth of $levels within 1% of $bound — bids walked down to a floor, asks walked up to a ceiling. */
    private function depthWithinBand(array $levels, float $bound, bool $isBid): float
    {
        $depth = 0.0;
        foreach ($levels as [$price, $size]) {
            if ($isBid ? $price < $bound : $price > $bound) {
                break;
            }
            $depth += $price * $size;
        }

        return round($depth, 2);
    }

    /**
     * BID-side USD depth within 1% of mid, in base-unit-equivalent (contract-size-converted) terms
     * for a perp-mapped product. Historically this returned SPOT depth for a spot $productId; now
     * that {@see fetchBookDetail()} routes a perps-mapped symbol to its contract book, this returns
     * that contract's converted depth instead. No known caller remains in this codebase (grepped
     * clean) — kept only for external/back-compat callers, who must treat the return value as
     * "whatever book fetchBookDetail() would have used for this product" rather than assuming spot.
     * Prefer withBook()/live($withBook=true), which populate both sides on the ProductStats row.
     */
    public function bookDepthUsd(string $productId, float $mid): ?float
    {
        return $this->fetchBookDetail($productId, $mid)['bid_depth_usd'] ?? null;
    }

    /**
     * Candle-only stats "as of" $at (unix). Used live as the base row and by the
     * backtester, where buys/sells are approximated from candle direction.
     *
     * @param  array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>  $bars1H  oldest -> newest, must end at or before $at
     */
    public function fromBars(string $productId, array $bars1H, int $at, ?float $ageHours = null, bool $approximateTape = false): ProductStats
    {
        $bars = array_values(array_filter($bars1H, fn ($b) => $b['start'] <= $at));
        $n = count($bars);
        $last = $n ? $bars[$n - 1] : null;
        $price = $last ? $last['close'] : 0.0;

        $volUsd = fn (array $slice) => array_sum(array_map(fn ($b) => $b['volume'] * (($b['open'] + $b['close']) / 2), $slice));
        // The newest 1H bar is usually still forming: blend it with the prior bar so "h1" means the trailing 60 minutes.
        $elapsed = $last ? max(0, min(3600, $at - $last['start'])) : 3600;
        $h1Usd = $last ? $volUsd([$last]) : 0.0;
        if ($n >= 2 && $elapsed < 3600) {
            $h1Usd += $volUsd([$bars[$n - 2]]) * (1 - $elapsed / 3600);
        }
        $h6 = array_slice($bars, -6);
        $h24 = array_slice($bars, -24);
        $prev = array_slice($bars, -48, 24);

        $chg = function (int $hoursAgo) use ($bars, $n, $price): float {
            $i = $n - 1 - $hoursAgo;
            if ($i < 0 || $price <= 0) {
                return 0.0;
            }
            $ref = $bars[$i]['close'];

            return $ref > 0 ? ($price / $ref - 1) * 100 : 0.0;
        };

        $b1 = $s1 = $b5 = $s5 = 0;
        $bv = $sv = 0.0;
        if ($approximateTape) {
            // Up bars count as buying pressure, down bars as selling. Crude but consistent for backtests.
            foreach ($h6 as $b) {
                $usd = $b['volume'] * (($b['open'] + $b['close']) / 2);
                if ($b['close'] >= $b['open']) {
                    $b1++;
                    $bv += $usd;
                } else {
                    $s1++;
                    $sv += $usd;
                }
            }
            $b5 = $last && $last['close'] >= $last['open'] ? 1 : 0;
            $s5 = 1 - $b5;
        }

        $extra = ['indicators' => $n >= 30 ? Indicators::bundle($bars) : null];

        return new ProductStats(
            productId: $productId,
            price: $price,
            bestBid: null,
            bestAsk: null,
            ageHours: $ageHours,
            volumeM5Usd: $last ? $volUsd([$last]) / 12 : 0.0,
            volumeH1Usd: $h1Usd,
            volumeH6Usd: $volUsd($h6),
            volumeH24Usd: $volUsd($h24),
            volumePrevH24Usd: $volUsd($prev),
            priceChangeM5Pct: $last && $last['open'] > 0 ? ($last['close'] / $last['open'] - 1) * 100 / 12 : 0.0,
            priceChangeH1Pct: $chg(1),
            priceChangeH6Pct: $chg(6),
            priceChangeH24Pct: $chg(24),
            buysH1: $b1,
            sellsH1: $s1,
            buysM5: $b5,
            sellsM5: $s5,
            buyVolumeH1Usd: $bv,
            sellVolumeH1Usd: $sv,
            spreadBps: $approximateTape ? 5.0 : null,
            bookDepthUsd: null,
            candlesH1Count: $n,
            extra: $extra,
        );
    }
}
