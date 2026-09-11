<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use Tests\TestCase;

/**
 * ProductStats::costToTradeUsd() walks the raw book levels captured with a row and returns the
 * estimated slippage in bps to fill a given notional on one side (BUY consumes asks, SELL
 * consumes bids) — or null when the levels are missing or too shallow to fill the size.
 */
class ProductStatsCostToTradeTest extends TestCase
{
    private function statsWithBook(array $bookLevels, float $price = 100.0): ProductStats
    {
        return ProductStats::fromArray([
            'product_id' => 'TEST-USD', 'price' => $price, 'candles_h1_count' => 48,
            'book_levels' => $bookLevels,
        ]);
    }

    private function book(): array
    {
        return [
            'bids' => [[99.5, 10.0], [99.0, 10.0]],  // $995, $990
            'asks' => [[100.5, 10.0], [101.0, 10.0]], // $1005, $1010
            'ref_price' => 100.0,
        ];
    }

    public function test_buy_within_the_top_level_costs_only_that_levels_slippage(): void
    {
        $s = $this->statsWithBook($this->book());

        $this->assertEqualsWithDelta(50.0, $s->costToTradeUsd('BUY', 500), 0.01, '$500 fills fully in the $1005 top ask -> flat 100.5 avg -> 50 bps');
    }

    public function test_buy_that_walks_two_levels_blends_their_prices(): void
    {
        $s = $this->statsWithBook($this->book());

        // $1500 = all of level 1 ($1005 @ 100.5) + $495 of level 2 (@ 101):
        // avg = (100.5*1005 + 101*495) / 1500 = 100.665 -> 66.5 bps
        $this->assertEqualsWithDelta(66.5, $s->costToTradeUsd('BUY', 1500), 0.01);
    }

    public function test_sell_consumes_bids_not_asks(): void
    {
        $s = $this->statsWithBook($this->book());

        $this->assertEqualsWithDelta(50.0, $s->costToTradeUsd('SELL', 500), 0.01, '$500 fills in the $995 top bid @ 99.5 -> 50 bps below mid');
    }

    public function test_side_is_case_insensitive(): void
    {
        $s = $this->statsWithBook($this->book());

        $this->assertEqualsWithDelta($s->costToTradeUsd('BUY', 500), $s->costToTradeUsd('buy', 500), 0.001);
    }

    public function test_a_size_bigger_than_the_whole_book_returns_null(): void
    {
        $s = $this->statsWithBook($this->book());

        $this->assertNull($s->costToTradeUsd('BUY', 3000), 'only $2015 of asks exist — never fabricate a fill past the book edge');
        $this->assertNull($s->costToTradeUsd('SELL', 2500), 'only $1985 of bids exist');
    }

    public function test_missing_book_levels_returns_null(): void
    {
        $s = ProductStats::fromArray(['product_id' => 'TEST-USD', 'price' => 100.0]);

        $this->assertNull($s->costToTradeUsd('BUY', 100));
        $this->assertNull($s->costToTradeUsd('SELL', 100));
    }

    public function test_an_empty_side_returns_null(): void
    {
        $s = $this->statsWithBook(['bids' => [[99.5, 10.0]], 'asks' => [], 'ref_price' => 100.0]);

        $this->assertNull($s->costToTradeUsd('BUY', 100), 'no asks at all to walk');
        $this->assertNotNull($s->costToTradeUsd('SELL', 100));
    }

    public function test_uses_the_captured_ref_price_not_the_signal_price(): void
    {
        // price (spot signal) is 90, but the book (e.g. a mapped perp contract) was captured at ref_price 100.
        $s = $this->statsWithBook($this->book(), price: 90.0);

        $this->assertEqualsWithDelta(50.0, $s->costToTradeUsd('BUY', 500), 0.01, 'slippage must be measured off the book it was captured against, not the spot signal price');
    }

    public function test_zero_or_negative_notional_returns_null(): void
    {
        $s = $this->statsWithBook($this->book());

        $this->assertNull($s->costToTradeUsd('BUY', 0));
        $this->assertNull($s->costToTradeUsd('BUY', -10));
    }
}
