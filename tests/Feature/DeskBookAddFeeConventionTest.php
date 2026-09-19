<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review round 2 (docs/STRATEGY_SCHEMA_V2.md, `Desk::bookAdd()`/`Desk::trim()`): the fee-convention
 * inference in `bookAdd()` and the `meta.v2.last_trim_fill_price` stamp in `trim()` are the LIVE/
 * paper feeds for `JsonPluginStrategy::reconcileV2Avg()` — a duplicated heuristic on the live money
 * path that no test drove through `Desk` itself (JsonPluginStrategyV2EngineTest hand-writes the
 * meta instead). `bookAdd`/`reconcileV2Avg` are private; both are exercised here through reflection,
 * the same way DeskExecutionBoundaryTest reaches `mutateLockSeconds`.
 */
class DeskBookAddFeeConventionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    /** A fresh position with a single fill at $100, no v2 state yet — `reconcileV2Avg()` establishes the baseline on the first call below. */
    private function freshPosition(): Position
    {
        return Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'adds_count' => 0, 'trims_count' => 0,
            'opened_at' => now(), 'meta' => [],
        ]);
    }

    private function bookAdd(Position $p, OrderResult $result): void
    {
        $m = new \ReflectionMethod(Desk::class, 'bookAdd');
        $m->setAccessible(true);
        $m->invoke(app(Desk::class), $p, $result, null, null);
    }

    private function reconcile(Position $p): float
    {
        $m = new \ReflectionMethod(JsonPluginStrategy::class, 'reconcileV2Avg');
        $m->setAccessible(true);

        return $m->invoke(new JsonPluginStrategy, $p);
    }

    public function test_book_add_excludes_no_fee_on_the_plain_cash_notional_spot_convention_and_avg_recovers_the_raw_fill_price(): void
    {
        $p = $this->freshPosition();
        $this->reconcile($p);   // baseline pass: v2.avg = 100, avg_qty = 1

        // Spot convention (Backtester.php: "$usd is the whole ticket, fee comes out of qty"):
        // filledUsd is the requested $110 ticket, fee already inside it, so filledQty is shy of
        // filledUsd/fillPrice by the fee. filledUsd - filledQty*fillPrice == feeUsd, NOT ~0.
        $fillPrice = 110.0;
        $feeUsd = 0.11;
        $filledUsd = 110.0;
        $filledQty = ($filledUsd - $feeUsd) / $fillPrice;   // 0.999
        $result = new OrderResult('filled', $filledUsd, $filledUsd, $filledQty, $fillPrice, $fillPrice, $feeUsd);

        $this->bookAdd($p, $result);

        $this->assertSame(0.0, (float) ($p->meta['entry_fee_excluded'] ?? 0.0), 'the fee is already inside entry_usd on the spot convention — nothing to exclude');

        $this->reconcile($p);

        // Quantity-weighted average of the two RAW fills: 1 unit @ 100, 0.999 units @ 110.
        $expected = (1.0 * 100.0 + $filledQty * $fillPrice) / (1.0 + $filledQty);
        $this->assertEqualsWithDelta($expected, JsonPluginStrategy::avg($p), 1e-6);
    }

    public function test_book_add_excludes_the_fee_on_the_whole_contract_margin_convention_and_avg_still_recovers_the_raw_fill_price(): void
    {
        $p = $this->freshPosition();
        $this->reconcile($p);   // baseline pass: v2.avg = 100, avg_qty = 1

        // Whole-contract/margin convention (Backtester.php: "the fee is paid separately from posted
        // collateral, so it never comes out of qty"): filledUsd IS filledQty*fillPrice exactly, the
        // fee is booked only into fees_usd.
        $fillPrice = 110.0;
        $filledQty = 1.0;
        $filledUsd = $filledQty * $fillPrice;
        $feeUsd = 0.11;
        $result = new OrderResult('filled', $filledUsd, $filledUsd, $filledQty, $fillPrice, $fillPrice, $feeUsd);

        $this->bookAdd($p, $result);

        $this->assertEqualsWithDelta($feeUsd, (float) ($p->meta['entry_fee_excluded'] ?? 0.0), 1e-9, 'the fee never touched entry_usd on this convention — all of it is excluded');

        $this->reconcile($p);

        $expected = (1.0 * 100.0 + $filledQty * $fillPrice) / (1.0 + $filledQty);
        $this->assertEqualsWithDelta($expected, JsonPluginStrategy::avg($p), 1e-6);
    }

    public function test_trim_stamps_last_trim_fill_price_from_the_actual_fill_not_the_rung_target(): void
    {
        $this->app->instance(MarketData::class, new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 105.0, 'best_ask' => 105.0, 'trades' => []];
            }
        });
        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);

        $p = Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 2.0, 'entry_price' => 100.0, 'entry_usd' => 200.0, 'fees_usd' => 0.0,
            'peak_price' => 105.0, 'last_price' => 105.0, 'adds_count' => 0, 'trims_count' => 0,
            'opened_at' => now(), 'meta' => [],
        ]);

        $ex = app(PaperExecutor::class);
        $fill = app(Desk::class)->trim($p, 0.5, 'take_profit.ladder.0', 105.0, $ex);

        $this->assertNotNull($fill);
        $this->assertSame('filled', $fill->status);
        $this->assertEqualsWithDelta(105.0, (float) $fill->fill_price, 1e-9);

        $p->refresh();
        $this->assertEqualsWithDelta((float) $fill->fill_price, (float) $p->meta['v2']['last_trim_fill_price'], 1e-9);
    }
}
