<?php

declare(strict_types=1);

namespace Tests\Exchange\Conformance;

use App\Desk\Execution\Executor;
use App\Exchange\Capabilities;
use App\Exchange\Contracts\Exchange;
use Tests\TestCase;

/**
 * Behavioral contract every exchange adapter must satisfy. Point a subclass at a real
 * (or recorded/fake) Exchange; every test below is a literal rule an adapter must pass,
 * not a smoke check, and every failure names the rule it broke.
 */
abstract class ExchangeConformanceTestCase extends TestCase
{
    abstract protected function exchange(): Exchange;

    /** A product id the fixture/adapter under test actually has data for. */
    abstract protected function sampleProductId(): string;

    // ---- manifest -------------------------------------------------------

    public function test_id_is_a_lowercase_slug(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9_-]+$/',
            $this->exchange()->id(),
            'id() must be a lowercase slug matching /^[a-z0-9_-]+$/'
        );
    }

    public function test_name_is_non_empty(): void
    {
        $this->assertNotSame('', trim($this->exchange()->name()), 'name() must be non-empty');
    }

    public function test_capabilities_returns_capabilities_dto(): void
    {
        $this->assertInstanceOf(
            Capabilities::class,
            $this->exchange()->capabilities(),
            'capabilities() must return a Capabilities DTO'
        );
    }

    public function test_credentials_fields_is_a_list_of_key_label_secret(): void
    {
        $fields = $this->exchange()->credentials()->fields();
        $this->assertIsList($fields, 'credentials()->fields() must be a list');

        foreach ($fields as $i => $field) {
            $this->assertIsArray($field, "credentials()->fields()[{$i}] must be an array");
            foreach (['key', 'label', 'secret'] as $required) {
                $this->assertArrayHasKey($required, $field, "credentials()->fields()[{$i}] must have key '{$required}'");
            }
        }
    }

    // ---- products ---------------------------------------------------------

    public function test_products_is_non_empty_with_required_fields(): void
    {
        $rows = $this->exchange()->marketData()->products();
        $this->assertNotEmpty($rows, 'marketData()->products() must return a non-empty list');

        foreach ($rows as $i => $row) {
            foreach (['product_id', 'base_currency', 'quote_currency'] as $required) {
                $this->assertArrayHasKey($required, $row, "products()[{$i}] must have key '{$required}'");
            }
            $this->assertNotSame('', (string) $row['base_currency'], "products()[{$i}].base_currency must be non-empty");
            $this->assertNotSame('', (string) $row['quote_currency'], "products()[{$i}].quote_currency must be non-empty");
        }
    }

    public function test_product_returns_matching_row(): void
    {
        $pid = $this->sampleProductId();
        $row = $this->exchange()->marketData()->product($pid);

        $this->assertSame($pid, $row['product_id'] ?? null, "product('{$pid}') must return a row whose product_id matches");
    }

    // ---- candles ------------------------------------------------------

    public function test_candles_are_well_formed(): void
    {
        $rows = $this->exchange()->marketData()->candles($this->sampleProductId(), '1m', 0, time());
        $this->assertNotEmpty($rows, 'candles() must return at least one row for the sample product');

        $seenStarts = [];
        $prevStart = null;

        foreach ($rows as $i => $c) {
            foreach (['start', 'open', 'high', 'low', 'close', 'volume'] as $key) {
                $this->assertArrayHasKey($key, $c, "candles()[{$i}] must have key '{$key}'");
            }
            foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                $this->assertIsNumeric($c[$key], "candles()[{$i}].{$key} must be numeric");
            }

            $this->assertLessThanOrEqual(
                min((float) $c['open'], (float) $c['close']),
                (float) $c['low'],
                "candles()[{$i}]: low must be <= min(open, close)"
            );
            $this->assertGreaterThanOrEqual(
                max((float) $c['open'], (float) $c['close']),
                (float) $c['high'],
                "candles()[{$i}]: high must be >= max(open, close)"
            );

            $this->assertSame(0, (int) $c['start'] % 60, "candles()[{$i}].start must be a multiple of 60");

            $this->assertArrayNotHasKey((string) $c['start'], $seenStarts, "candles(): duplicate start {$c['start']}");
            $seenStarts[(string) $c['start']] = true;

            if ($prevStart !== null) {
                $this->assertGreaterThan($prevStart, (int) $c['start'], 'candles() must be sorted ascending by start');
            }
            $prevStart = (int) $c['start'];
        }
    }

    // ---- ticker -------------------------------------------------------

    public function test_ticker_is_well_formed(): void
    {
        $t = $this->exchange()->marketData()->ticker($this->sampleProductId());

        $this->assertGreaterThan(0, $t['best_bid'] ?? 0, 'ticker().best_bid must be > 0');
        $this->assertGreaterThan(0, $t['best_ask'] ?? 0, 'ticker().best_ask must be > 0');
        $this->assertLessThanOrEqual($t['best_ask'], $t['best_bid'], 'ticker().best_bid must be <= best_ask');
        $this->assertNotSame('', (string) ($t['source'] ?? ''), 'ticker().source must be non-empty');

        $this->assertIsList($t['trades'] ?? null, 'ticker().trades must be a list');

        foreach ($t['trades'] as $i => $trade) {
            foreach (['price', 'size', 'side'] as $key) {
                $this->assertArrayHasKey($key, $trade, "ticker().trades[{$i}] must have key '{$key}'");
            }
            $this->assertContains($trade['side'], ['buy', 'sell'], "ticker().trades[{$i}].side must be 'buy' or 'sell'");
        }
    }

    // ---- trades -------------------------------------------------------

    public function test_trades_are_well_formed(): void
    {
        $rows = $this->exchange()->marketData()->trades($this->sampleProductId(), 0, time());

        $seenIds = [];
        $prevTimeMs = null;

        foreach ($rows as $i => $trade) {
            foreach (['trade_id', 'time_ms', 'price', 'size', 'side'] as $key) {
                $this->assertArrayHasKey($key, $trade, "trades()[{$i}] must have key '{$key}'");
            }
            $this->assertContains($trade['side'], ['buy', 'sell'], "trades()[{$i}].side must be 'buy' or 'sell'");

            $id = (string) $trade['trade_id'];
            $this->assertArrayNotHasKey($id, $seenIds, "trades(): duplicate trade_id {$id}");
            $seenIds[$id] = true;

            if ($prevTimeMs !== null) {
                $this->assertGreaterThanOrEqual($prevTimeMs, (int) $trade['time_ms'], 'trades() must be sorted ascending by time_ms');
            }
            $prevTimeMs = (int) $trade['time_ms'];
        }
    }

    // ---- book -----------------------------------------------------------

    public function test_book_is_well_formed(): void
    {
        $book = $this->exchange()->marketData()->book($this->sampleProductId(), 10);

        $this->assertArrayHasKey('bids', $book, 'book() must have key "bids"');
        $this->assertArrayHasKey('asks', $book, 'book() must have key "asks"');
        $this->assertArrayHasKey('ts', $book, 'book() must have key "ts"');
        $this->assertIsInt($book['ts'], 'book().ts must be an int');
        $this->assertNotEmpty($book['bids'], 'book().bids must be non-empty for the sample product');
        $this->assertNotEmpty($book['asks'], 'book().asks must be non-empty for the sample product');

        $prevBid = null;
        foreach ($book['bids'] as $i => $level) {
            $price = (float) $level[0];
            if ($prevBid !== null) {
                $this->assertLessThan($prevBid, $price, 'book().bids must be sorted descending by price');
            }
            $prevBid = $price;
        }

        $prevAsk = null;
        foreach ($book['asks'] as $i => $level) {
            $price = (float) $level[0];
            if ($prevAsk !== null) {
                $this->assertGreaterThan($prevAsk, $price, 'book().asks must be sorted ascending by price');
            }
            $prevAsk = $price;
        }

        $this->assertLessThan(
            (float) $book['asks'][0][0],
            (float) $book['bids'][0][0],
            'book(): best bid must be < best ask'
        );
    }

    // ---- price ----------------------------------------------------------

    public function test_price_is_a_positive_float_or_null(): void
    {
        $price = $this->exchange()->marketData()->price($this->sampleProductId());

        if ($price !== null) {
            $this->assertIsFloat($price, 'price() must return a float when non-null');
            $this->assertGreaterThan(0.0, $price, 'price() must be > 0 when non-null');
        } else {
            $this->assertNull($price, 'price() must be null or a positive float');
        }
    }

    // ---- healthy --------------------------------------------------------

    public function test_healthy_returns_bool(): void
    {
        $this->assertIsBool($this->exchange()->marketData()->healthy(), 'healthy() must return a bool');
    }

    // ---- executor ---------------------------------------------------------

    public function test_executor_contract_when_spot_supported(): void
    {
        if (! $this->exchange()->capabilities()->spot) {
            $this->markTestSkipped('exchange does not declare spot support');
        }

        $executor = $this->exchange()->executor(false);
        $this->assertInstanceOf(Executor::class, $executor, 'executor(false) must return an Executor');
        $this->assertContains($executor->mode(), ['live', 'paper'], "executor(false)->mode() must be 'live' or 'paper'");
    }

    // ---- perps ----------------------------------------------------------

    public function test_perp_spec_when_perps_supported(): void
    {
        if (! $this->exchange()->capabilities()->perps) {
            $this->markTestSkipped('exchange does not declare perps support');
        }

        $spec = $this->exchange()->perpSpec($this->sampleProductId());
        if ($spec === null) {
            $this->assertNull($spec);

            return;
        }

        $this->assertArrayHasKey('product_id', $spec, "perpSpec() must have key 'product_id' when non-null");
        $this->assertArrayHasKey('contract_size', $spec, "perpSpec() must have key 'contract_size' when non-null");
        $this->assertGreaterThan(0.0, (float) $spec['contract_size'], 'perpSpec().contract_size must be > 0 when non-null');
    }
}
