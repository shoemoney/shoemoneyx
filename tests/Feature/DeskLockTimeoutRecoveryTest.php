<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use App\Models\Product;
use App\Models\RiskCheck;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\CloseOnCallStrategy;
use Tests\Feature\Fixtures\DeskWithFakeLockTimeouts;
use Tests\Feature\Fixtures\TwoEntryHaltStrategy;
use Tests\TestCase;

/**
 * Reviewer blocker 2: no Illuminate\Contracts\Cache\LockTimeoutException was caught in Desk.php; a
 * block(5) timeout on ONE position's/candidate's mutate lock aborted the ENTIRE riskSweep()/cycle(),
 * leaving everything after it unmanaged for that pass. Desk now catches it per position in
 * riskSweep() (and around the liquidation/deleverage closes ahead of that loop) and per candidate in
 * cycle(), logs a warning, and moves on — the stuck position/candidate simply gets another turn on
 * the next pass. DeskWithFakeLockTimeouts substitutes a lock that throws on chosen calls in place of
 * Cache::lock(), so this is provable without real concurrency or a genuine 5s block() wait.
 */
class DeskLockTimeoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 100.0, 'best_ask' => 100.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_risk_sweep_survives_one_positions_lock_timeout_and_still_visits_the_rest(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Product::create(['product_id' => 'ETH-USD', 'base_currency' => 'ETH', 'quote_currency' => 'USD']);

        $this->app->bind(ProductStatsBuilder::class, fn () => new class(app(MarketData::class), app(CandleStore::class)) extends ProductStatsBuilder
        {
            public function live(Product $product, bool $withBook = false): ProductStats
            {
                return ProductStats::fromArray(['product_id' => $product->product_id, 'price' => 100.0]);
            }
        });
        config(['desk.strategies.close_on_call_test' => CloseOnCallStrategy::class, 'desk.strategy' => 'close_on_call_test']);
        $this->app->instance(CloseOnCallStrategy::class, new CloseOnCallStrategy(1000.0, 'BTC-USD', 'long', closeOnCall: 1));

        // BTC-USD (opened first, so visited first) is decided CLOSE on the very first risk call;
        // ETH-USD just holds. Only the FIRST mutateLock() attempt (BTC-USD's close()) times out.
        $first = Position::create(['mode' => 'paper', 'strategy' => 'close_on_call_test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open', 'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0, 'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now()->subMinute(), 'meta' => []]);
        $second = Position::create(['mode' => 'paper', 'strategy' => 'close_on_call_test', 'product_id' => 'ETH-USD', 'side' => 'long', 'status' => 'open', 'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0, 'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => []]);

        $desk = app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [1]]);
        $out = $desk->riskSweep();

        $this->assertCount(2, $out, 'both positions must be visited — a lock timeout on the first must not abort the sweep');
        $this->assertSame('BTC-USD', $out[0]['position']);
        $this->assertSame('CLOSE', $out[0]['action'], 'the risk DECISION is still recorded even though the close itself could not go through');
        $this->assertSame('ETH-USD', $out[1]['position']);
        $this->assertSame('HOLD', $out[1]['action']);

        // The close never actually completed — the lock never let it in — so nothing changed.
        $first->refresh();
        $this->assertSame('open', $first->status, 'a lock timeout must leave the position exactly as it was; it gets another try next sweep');
        $second->refresh();
        $this->assertSame('open', $second->status);

        $this->assertSame(1, RiskCheck::where('position_id', $first->id)->count());
        $this->assertSame(1, RiskCheck::where('position_id', $second->id)->count(), 'the second position must still get its own risk check this sweep');
    }

    public function test_cycle_survives_one_candidates_lock_timeout_and_still_reports_done(): void
    {
        config(['desk.strategies.two_entry_halt_test' => TwoEntryHaltStrategy::class, 'desk.strategy' => 'two_entry_halt_test']);
        $this->app->instance(TwoEntryHaltStrategy::class, new TwoEntryHaltStrategy(
            usd: 100.0,
            productIds: ['BTC-USD', 'ETH-USD'],
        ));

        // The first candidate's enter() acquires the lock normally (attempt #1); the second's times out.
        $desk = app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [2]]);
        $run = $desk->cycle();

        $this->assertSame('done', $run->status, 'a candidate lock timeout must not be treated as a cycle-ending error');
        $this->assertSame(1, $run->filled, 'only the candidate whose lock did not time out should have entered');
        $this->assertSame(1, Position::count());
        $this->assertSame('BTC-USD', Position::sole()->product_id);
    }
}
