<?php

declare(strict_types=1);

namespace App\Desk\Data;

/**
 * The Coinbase-spot equivalent of the desk's "launch data" row.
 * Everything a strategy needs to rank / vet / risk-check one product.
 */
final class ProductStats implements \JsonSerializable
{
    public function __construct(
        public readonly string $productId,
        public readonly float $price,
        public readonly ?float $bestBid,
        public readonly ?float $bestAsk,
        public readonly ?float $ageHours,          // hours since first seen on Coinbase (null = unknown/old)
        public readonly float $volumeM5Usd,
        public readonly float $volumeH1Usd,
        public readonly float $volumeH6Usd,
        public readonly float $volumeH24Usd,
        public readonly float $volumePrevH24Usd,   // the 24h before that — for rate of change
        public readonly float $priceChangeM5Pct,
        public readonly float $priceChangeH1Pct,
        public readonly float $priceChangeH6Pct,
        public readonly float $priceChangeH24Pct,
        public readonly int $buysH1,
        public readonly int $sellsH1,
        public readonly int $buysM5,
        public readonly int $sellsM5,
        public readonly float $buyVolumeH1Usd,
        public readonly float $sellVolumeH1Usd,
        public readonly ?float $spreadBps,
        public readonly ?float $bookDepthUsd,      // BID-side depth within 1% of mid — kept for compatibility, equal to bidDepthUsd
        public readonly int $candlesH1Count,      // how many 1H candles backed this (data quality)
        public readonly array $extra = [],
        public readonly ?float $bidDepthUsd = null,    // USD you could sell into within 1% of mid without moving it
        public readonly ?float $askDepthUsd = null,    // USD you could buy into within 1% of mid without moving it
        public readonly ?float $quoteAgeSec = null,    // age of the book/quote snapshot behind the depth fields; null = unavailable (e.g. historical/backtest)
        public readonly ?int $tapeSampleSize = null,   // number of trade prints behind the buy-share stat; null = no real tape (approximated from candles)
        /** @var array{bids: array<int, array{0:float,1:float}>, asks: array<int, array{0:float,1:float}>, ref_price: float}|null Raw top-N book levels [price, size], oldest-side-first (best price first), for costToTradeUsd(). ref_price is the mid the levels were captured against — the perp contract's own mid when this row was mapped to a futures contract, spot mid otherwise. */
        public readonly ?array $bookLevels = null,
    ) {}

    /**
     * Estimated slippage in basis points to trade $notionalUsd on one side, walking the raw
     * book levels captured with this row (consumes asks to BUY, bids to SELL). Returns null
     * when levels are missing (unavailable) or too shallow to fill the requested size — never
     * a fabricated number past the edge of the book.
     */
    public function costToTradeUsd(string $side, float $notionalUsd): ?float
    {
        if ($this->bookLevels === null || $notionalUsd <= 0) {
            return null;
        }
        $refPrice = (float) ($this->bookLevels['ref_price'] ?? $this->price);
        if ($refPrice <= 0) {
            return null;
        }
        $buy = strtoupper($side) === 'BUY';
        $levels = $buy ? ($this->bookLevels['asks'] ?? []) : ($this->bookLevels['bids'] ?? []);
        if ($levels === []) {
            return null;
        }

        $remaining = $notionalUsd;
        $filledUsd = 0.0;
        $weightedPrice = 0.0;
        foreach ($levels as [$price, $size]) {
            if ($remaining <= 0) {
                break;
            }
            $levelUsd = $price * $size;
            $take = min($remaining, $levelUsd);
            $weightedPrice += $price * $take;
            $filledUsd += $take;
            $remaining -= $take;
        }

        if ($remaining > 0.01 || $filledUsd <= 0) {
            return null; // book too shallow to fill this size
        }

        $avgPrice = $weightedPrice / $filledUsd;
        $slippage = $buy ? ($avgPrice / $refPrice - 1) : (1 - $avgPrice / $refPrice);

        return round($slippage * 10_000, 2);
    }

    /** h6 / (h24 / 4) — the RISK ratio. */
    public function volumeRatio6h(): ?float
    {
        if ($this->volumeH24Usd <= 0) {
            return null;
        }

        return $this->volumeH6Usd / ($this->volumeH24Usd / 4);
    }

    public function buySellRatioH1(): ?float
    {
        if ($this->sellsH1 === 0) {
            return $this->buysH1 > 0 ? INF : null;
        }

        return $this->buysH1 / $this->sellsH1;
    }

    public function buySellRatioM5(): ?float
    {
        if ($this->sellsM5 === 0) {
            return $this->buysM5 > 0 ? INF : null;
        }

        return $this->buysM5 / $this->sellsM5;
    }

    /** 24h volume vs the previous 24h: >1 = accelerating. */
    public function volumeAcceleration(): ?float
    {
        if ($this->volumePrevH24Usd <= 0) {
            return null;
        }

        return $this->volumeH24Usd / $this->volumePrevH24Usd;
    }

    /** h1 volume vs the h24 hourly average: >1 = attention arriving now. */
    public function volumeSurgeH1(): ?float
    {
        if ($this->volumeH24Usd <= 0) {
            return null;
        }

        return $this->volumeH1Usd / ($this->volumeH24Usd / 24);
    }

    public function jsonSerialize(): array
    {
        return [
            'product_id' => $this->productId,
            'price' => $this->price,
            'best_bid' => $this->bestBid,
            'best_ask' => $this->bestAsk,
            'age_hours' => $this->ageHours,
            'volume_m5_usd' => round($this->volumeM5Usd, 2),
            'volume_h1_usd' => round($this->volumeH1Usd, 2),
            'volume_h6_usd' => round($this->volumeH6Usd, 2),
            'volume_h24_usd' => round($this->volumeH24Usd, 2),
            'volume_prev_h24_usd' => round($this->volumePrevH24Usd, 2),
            'price_change_m5_pct' => round($this->priceChangeM5Pct, 4),
            'price_change_h1_pct' => round($this->priceChangeH1Pct, 4),
            'price_change_h6_pct' => round($this->priceChangeH6Pct, 4),
            'price_change_h24_pct' => round($this->priceChangeH24Pct, 4),
            'buys_h1' => $this->buysH1,
            'sells_h1' => $this->sellsH1,
            'buys_m5' => $this->buysM5,
            'sells_m5' => $this->sellsM5,
            'buy_volume_h1_usd' => round($this->buyVolumeH1Usd, 2),
            'sell_volume_h1_usd' => round($this->sellVolumeH1Usd, 2),
            'spread_bps' => $this->spreadBps,
            'book_depth_usd' => $this->bookDepthUsd,
            'bid_depth_usd' => $this->bidDepthUsd,
            'ask_depth_usd' => $this->askDepthUsd,
            'quote_age_sec' => $this->quoteAgeSec,
            'tape_sample_size' => $this->tapeSampleSize,
            'volume_ratio_6h' => $this->volumeRatio6h() !== null ? round($this->volumeRatio6h(), 4) : null,
            'volume_surge_h1' => $this->volumeSurgeH1() !== null ? round($this->volumeSurgeH1(), 4) : null,
            'volume_acceleration' => $this->volumeAcceleration() !== null ? round($this->volumeAcceleration(), 4) : null,
            'buy_sell_ratio_h1' => is_infinite($this->buySellRatioH1() ?? 0) ? 999 : $this->buySellRatioH1(),
            'candles_h1_count' => $this->candlesH1Count,
            'extra' => $this->extra,
            // book_levels deliberately excluded: ~200 levels/side (~6 KB) per row, and this
            // serialization is what Desk.php persists into candidates.metrics every cycle for
            // every candidate. costToTradeUsd() reads $this->bookLevels directly (not this array),
            // so nothing live loses access — round-tripping through fromArray()/jsonSerialize()
            // (e.g. a cache hit) is the only path that loses it, which is why fromArray() must be
            // fed the original array (with book_levels) rather than a re-serialized one when the
            // raw levels are still needed downstream.
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            productId: $a['product_id'],
            price: (float) $a['price'],
            bestBid: isset($a['best_bid']) ? (float) $a['best_bid'] : null,
            bestAsk: isset($a['best_ask']) ? (float) $a['best_ask'] : null,
            ageHours: isset($a['age_hours']) ? (float) $a['age_hours'] : null,
            volumeM5Usd: (float) ($a['volume_m5_usd'] ?? 0),
            volumeH1Usd: (float) ($a['volume_h1_usd'] ?? 0),
            volumeH6Usd: (float) ($a['volume_h6_usd'] ?? 0),
            volumeH24Usd: (float) ($a['volume_h24_usd'] ?? 0),
            volumePrevH24Usd: (float) ($a['volume_prev_h24_usd'] ?? 0),
            priceChangeM5Pct: (float) ($a['price_change_m5_pct'] ?? 0),
            priceChangeH1Pct: (float) ($a['price_change_h1_pct'] ?? 0),
            priceChangeH6Pct: (float) ($a['price_change_h6_pct'] ?? 0),
            priceChangeH24Pct: (float) ($a['price_change_h24_pct'] ?? 0),
            buysH1: (int) ($a['buys_h1'] ?? 0),
            sellsH1: (int) ($a['sells_h1'] ?? 0),
            buysM5: (int) ($a['buys_m5'] ?? 0),
            sellsM5: (int) ($a['sells_m5'] ?? 0),
            buyVolumeH1Usd: (float) ($a['buy_volume_h1_usd'] ?? 0),
            sellVolumeH1Usd: (float) ($a['sell_volume_h1_usd'] ?? 0),
            spreadBps: isset($a['spread_bps']) ? (float) $a['spread_bps'] : null,
            bookDepthUsd: isset($a['book_depth_usd']) ? (float) $a['book_depth_usd'] : null,
            candlesH1Count: (int) ($a['candles_h1_count'] ?? 0),
            extra: $a['extra'] ?? [],
            bidDepthUsd: isset($a['bid_depth_usd']) ? (float) $a['bid_depth_usd'] : null,
            askDepthUsd: isset($a['ask_depth_usd']) ? (float) $a['ask_depth_usd'] : null,
            quoteAgeSec: isset($a['quote_age_sec']) ? (float) $a['quote_age_sec'] : null,
            tapeSampleSize: isset($a['tape_sample_size']) ? (int) $a['tape_sample_size'] : null,
            bookLevels: $a['book_levels'] ?? null,
        );
    }
}
