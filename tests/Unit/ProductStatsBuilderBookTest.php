<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Tests\TestCase;

/**
 * Reviewer BLOCKER (2026-09-05 side-aware-liquidity review, verified against the live Coinbase
 * book): fetchBookDetail() routes a perps-mapped product to its contract's own book, but perp
 * books quote `size` in CONTRACTS while spot books quote base units — no contract_size multiplier
 * was applied, so depth and slippage were wrong by 1/contract_size. Measured impact: DOGE-USD
 * depth $769k -> $118 (rejected every cycle since contract_size is 1000, i.e. levels were
 * understated 1000x), BTC-USD $1.25M -> $1.14B (contract_size 0.001 overstated levels 1000x,
 * effectively disabling the thin-book guard).
 *
 * fetchBookDetail() is protected specifically so these tests can subclass ProductStatsBuilder and
 * call it directly against a fake CoinbaseMarketData — no HTTP, no websocket feed.
 */
class ProductStatsBuilderBookTest extends TestCase
{
    /** A CoinbaseMarketData stand-in that returns a canned book instead of calling the API. */
    private function fakeMarket(array $bids, array $asks): CoinbaseMarketData
    {
        return new class($bids, $asks) extends CoinbaseMarketData
        {
            public function __construct(private array $bids, private array $asks) {}

            public function book(string $productId, int $limit = 50): array
            {
                return ['bids' => $this->bids, 'asks' => $this->asks];
            }
        };
    }

    private function builder(CoinbaseMarketData $market): ProductStatsBuilder
    {
        return new class($market, new CandleStore($market)) extends ProductStatsBuilder
        {
            public function testFetchBookDetail(string $spotProductId, float $mid): ?array
            {
                return $this->fetchBookDetail($spotProductId, $mid);
            }
        };
    }

    public function test_btc_style_contract_size_under_one_converts_contracts_to_base_units(): void
    {
        config([
            'desk.perps.enabled' => true,
            'desk.perps.map' => ['BTC-USD' => ['product_id' => 'BIP-20DEC30-CDE', 'contract_size' => 0.001]],
        ]);
        // 10 contracts @ 0.001 BTC/contract = 0.01 BTC. At $100,000/BTC that is $1,000 of
        // spot-equivalent depth — not $1,000,000 (10 contracts treated as 10 BTC).
        $builder = $this->builder($this->fakeMarket(
            bids: [[99_000.0, 10.0]],
            asks: [[101_000.0, 10.0]],
        ));

        $detail = $builder->testFetchBookDetail('BTC-USD', 100_000.0);

        $this->assertNotNull($detail);
        $this->assertEqualsWithDelta(0.01, $detail['bids'][0][1], 1e-9, 'stored level size must be base units, not raw contracts');
        $this->assertEqualsWithDelta(99_000.0 * 0.01, $detail['bid_depth_usd'], 0.01, 'depth must be the spot-equivalent USD, not 1000x it');
    }

    public function test_doge_style_contract_size_over_one_converts_contracts_to_base_units(): void
    {
        config([
            'desk.perps.enabled' => true,
            'desk.perps.map' => ['DOGE-USD' => ['product_id' => 'DOP-20DEC30-CDE', 'contract_size' => 1000.0]],
        ]);
        // 5 contracts @ 1000 DOGE/contract = 5,000 DOGE. At $0.10/DOGE that is $500 — not $0.50
        // (5 contracts treated as 5 DOGE).
        $builder = $this->builder($this->fakeMarket(
            bids: [[0.099, 5.0]],
            asks: [[0.101, 5.0]],
        ));

        $detail = $builder->testFetchBookDetail('DOGE-USD', 0.10);

        $this->assertNotNull($detail);
        $this->assertEqualsWithDelta(5_000.0, $detail['bids'][0][1], 1e-6, 'stored level size must be base units, not raw contracts');
        $this->assertEqualsWithDelta(0.099 * 5_000.0, $detail['bid_depth_usd'], 0.01, 'depth must be the spot-equivalent USD, not 1/1000th of it');
    }

    public function test_a_spot_product_with_no_perp_mapping_is_never_scaled(): void
    {
        config(['desk.perps.enabled' => true, 'desk.perps.map' => []]);
        $builder = $this->builder($this->fakeMarket(
            bids: [[99.0, 10.0]],
            asks: [[101.0, 10.0]],
        ));

        $detail = $builder->testFetchBookDetail('SOME-USD', 100.0);

        $this->assertEqualsWithDelta(10.0, $detail['bids'][0][1], 1e-9, 'no Perps::spec() entry -> already base units, must pass through unscaled');
        $this->assertEqualsWithDelta(990.0, $detail['bid_depth_usd'], 0.01);
    }

    public function test_perps_disabled_globally_never_scales_even_with_a_map_entry(): void
    {
        config([
            'desk.perps.enabled' => false,
            'desk.perps.map' => ['BTC-USD' => ['product_id' => 'BIP-20DEC30-CDE', 'contract_size' => 0.001]],
        ]);
        $builder = $this->builder($this->fakeMarket(
            bids: [[99_000.0, 10.0]],
            asks: [[101_000.0, 10.0]],
        ));

        $detail = $builder->testFetchBookDetail('BTC-USD', 100_000.0);

        $this->assertEqualsWithDelta(10.0, $detail['bids'][0][1], 1e-9, 'perps disabled -> spot book, no contract_size conversion');
    }

    /**
     * BLOCKER 3: quote_age_sec must reflect the STALER of the book fetch (~0s, synchronous) and
     * the ticker quote behind $mid — never unconditionally reset to 0.0, which defeated the
     * staleness gate by reporting a stale mid as fresh on every successful book fetch.
     */
    public function test_a_successful_book_fetch_does_not_reset_a_stale_ticker_age_to_zero(): void
    {
        $builder = $this->builder($this->fakeMarket(
            bids: [[99.0, 10.0]],
            asks: [[101.0, 10.0]],
        ));
        $stale = ProductStats::fromArray([
            'product_id' => 'SOME-USD', 'price' => 100.0, 'candles_h1_count' => 48,
            'quote_age_sec' => 7.5, // e.g. a cached REST ticker
        ]);

        $result = $builder->withBook($stale);

        $this->assertSame(7.5, $result->quoteAgeSec, 'the ticker age must survive a fresh (fast) book fetch, not collapse to 0');
    }

    public function test_a_successful_book_fetch_leaves_a_never_known_ticker_age_null(): void
    {
        $builder = $this->builder($this->fakeMarket(
            bids: [[99.0, 10.0]],
            asks: [[101.0, 10.0]],
        ));
        $unknown = ProductStats::fromArray([
            'product_id' => 'SOME-USD', 'price' => 100.0, 'candles_h1_count' => 48,
            // quote_age_sec omitted entirely -> null, "never measured"
        ]);

        $result = $builder->withBook($unknown);

        $this->assertNull($result->quoteAgeSec, 'a row with no ticker-age measurement stays unavailable, not falsely fresh');
    }

    /**
     * BLOCKER 2: book_levels (~200 levels/side, ~6 KB) must not appear in the serialized row —
     * Desk.php persists every candidate's jsonSerialize() into candidates.metrics every cycle.
     */
    public function test_book_levels_are_excluded_from_the_serialized_row(): void
    {
        $s = ProductStats::fromArray([
            'product_id' => 'SOME-USD', 'price' => 100.0, 'candles_h1_count' => 48,
            'book_levels' => ['bids' => [[99.0, 1.0]], 'asks' => [[101.0, 1.0]], 'ref_price' => 100.0],
        ]);

        $json = $s->jsonSerialize();

        $this->assertArrayNotHasKey('book_levels', $json, 'book_levels must not be persisted into candidates.metrics');
        $this->assertNotNull($s->costToTradeUsd('BUY', 50), 'the in-memory object must still serve costToTradeUsd() from the raw levels');
    }
}
