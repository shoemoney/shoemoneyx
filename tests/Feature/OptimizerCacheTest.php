<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DeskOptimize;
use App\Desk\Settings;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Candle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OptimizerCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_cached_row_is_reused_instead_of_dispatched(): void
    {
        Queue::fake();
        $key = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        $prior = Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'params' => ['mr.timeframe' => '2m'], 'cache_key' => $key,
            'status' => 'done', 'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
            'equity_curve' => [[0, 10000], [1, 10420]],
        ]);

        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000.0,
            'params' => ['mr.timeframe' => '2m', '_opt' => ['coin' => 'BTC-USD', 'window' => 'train', 'cand' => 0, 'side' => 'long', 'batch' => 'b1', 'tag' => null]],
            'cache_key' => $key, 'queue' => 'backtests',
        ], 12);

        $this->assertSame('done', $bt->status);
        $this->assertSame(4.2, $bt->stats['total_return_pct']);
        $this->assertSame([[0, 10000], [1, 10420]], $bt->equity_curve);
        $this->assertSame($prior->id, $bt->params['_opt']['cached_from']);
        Queue::assertNotPushed(RunBacktest::class);
    }

    public function test_an_expired_cache_row_is_not_reused(): void
    {
        Queue::fake();
        $key = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'params' => ['mr.timeframe' => '2m'], 'cache_key' => $key,
            'status' => 'done', 'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
            'created_at' => now()->subHours(13),
        ]);

        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000.0,
            'params' => ['mr.timeframe' => '2m', '_opt' => ['coin' => 'BTC-USD', 'window' => 'train', 'cand' => 0, 'side' => 'long', 'batch' => 'b1', 'tag' => null]],
            'cache_key' => $key, 'queue' => 'backtests',
        ], 12);

        $this->assertSame('queued', $bt->status);
        $this->assertArrayNotHasKey('cached_from', $bt->params['_opt']);
        Queue::assertPushed(RunBacktest::class, fn ($job) => $job->backtestId === $bt->id);
    }

    /**
     * Finding 18: a cache-hit clone used to stamp a fresh created_at, so a chain of reused rows could
     * look "just computed" forever even though the underlying simulation is many cache-hour windows
     * stale. The clone below inherited computed_at from a 13-hour-old original — a later round must
     * see it as stale, not as a row that happens to have a recent created_at.
     */
    public function test_a_cache_clone_does_not_look_fresher_than_its_original_computation(): void
    {
        Queue::fake();
        $key = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        $original = Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'params' => ['mr.timeframe' => '2m'], 'cache_key' => $key,
            'status' => 'done', 'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
            'created_at' => now()->subHours(13),
        ]);
        Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'cache_key' => $key, 'status' => 'done',
            'params' => ['mr.timeframe' => '2m', '_opt' => ['cached_from' => $original->id]],
            'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
            'computed_at' => $original->created_at,   // this is what the earlier reuse should have preserved
            // created_at intentionally left "now" — a clone's own bookkeeping timestamp, not its identity
        ]);

        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000.0,
            'params' => ['mr.timeframe' => '2m', '_opt' => ['coin' => 'BTC-USD', 'window' => 'train', 'cand' => 0, 'side' => 'long', 'batch' => 'b1', 'tag' => null]],
            'cache_key' => $key, 'queue' => 'backtests',
        ], 12);

        $this->assertSame('queued', $bt->status, 'the clone inherited computed_at 13h old — must not be reused as if freshly computed');
        Queue::assertPushed(RunBacktest::class);
    }

    /** Finding 18: a cache hit must return the same API contract as the row it reused — ending_equity
     *  and trades included — and must reference the source row plus preserve when it was truly computed. */
    public function test_a_cache_hit_copies_ending_equity_and_trades_and_preserves_the_original_computed_at(): void
    {
        Queue::fake();
        $key = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, ['mr.timeframe' => '2m']);
        $original = Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'params' => ['mr.timeframe' => '2m'], 'cache_key' => $key,
            'status' => 'done', 'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
            'ending_equity' => 10420.5, 'trades' => [['product_id' => 'BTC-USD', 'pnl_usd' => 10]],
        ]);

        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000.0,
            'params' => ['mr.timeframe' => '2m', '_opt' => ['coin' => 'BTC-USD', 'window' => 'train', 'cand' => 0, 'side' => 'long', 'batch' => 'b1', 'tag' => null]],
            'cache_key' => $key, 'queue' => 'backtests',
        ], 12);

        $this->assertSame(10420.5, $bt->ending_equity);
        $this->assertSame($original->trades, $bt->trades);
        $this->assertSame($original->id, $bt->params['_opt']['cached_from']);
        $this->assertNotNull($bt->completed_at, 'completed_at is when THIS round obtained a result');
        $this->assertTrue($original->created_at->eq($bt->computed_at), 'computed_at preserves the original computation moment');
        Queue::assertNotPushed(RunBacktest::class);
    }

    /** Finding 18: the key must include the full resolved parameter tree, not just the supplied
     *  overrides — a mutable DB setting the optimizer never overrode still changes what actually runs. */
    public function test_a_settings_change_misses_the_cache(): void
    {
        config(['cache.default' => 'array']);
        $overrides = ['mr.timeframe' => '2m'];

        $before = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, '2026-08-24');
        app(Settings::class)->set('mr.qty_pct', 99);
        $after = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, '2026-08-24');

        $this->assertNotSame(
            Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, $before),
            Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, $after),
        );
    }

    /** Finding 18: a candle repair (or backfill) must also miss the cache, even though no override or
     *  setting changed — the candle revision marker is the fallback CandleStore has no bump hook for. */
    public function test_a_candle_repair_misses_the_cache(): void
    {
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => '2026-08-01 00:00:00', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1, 'updated_at' => now()->subDay()]);
        $overrides = ['mr.timeframe' => '1H'];

        $before = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, '2026-08-24');
        Candle::where('product_id', 'BTC-USD')->update(['close' => 2, 'updated_at' => now()]);
        $after = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, '2026-08-24');

        $this->assertNotSame($before['_cache']['candles'], $after['_cache']['candles']);
        $this->assertSame($before['_cache']['engine'], $after['_cache']['engine'], 'only the candle revision moved');
    }

    /**
     * Reviewer blocker 1: Backtester::paramsFor() mirrors every non-`per_product.` override onto
     * `per_product.<pid>.*` too, so `_opt` (carrying the round's own batch UUID) reappeared at
     * `per_product.<pid>._opt` and Backtest::cacheKeyFor() only stripped it at the top level — the
     * result cache was permanently 0%. Two rounds sweeping the exact same candidate params under
     * different batch UUIDs must resolve to the identical cache key.
     */
    public function test_two_rounds_with_different_batch_uuids_share_a_cache_key_for_identical_params(): void
    {
        config(['cache.default' => 'array']);
        $overrides1 = DeskOptimize::candidateParams('BTC-USD', ['mr.timeframe' => '2m'], 'train', 3, 'long', 'batch-one', null, 'mr');
        $overrides2 = DeskOptimize::candidateParams('BTC-USD', ['mr.timeframe' => '2m'], 'train', 3, 'long', 'batch-two', null, 'mr');

        $identity1 = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides1, '2026-08-24');
        $identity2 = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides2, '2026-08-24');

        $key1 = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, $identity1);
        $key2 = Backtest::cacheKeyFor('mr', ['BTC-USD'], '2026-08-01', '2026-08-24', 10000.0, $identity2);

        $this->assertSame($key1, $key2, 'only the batch UUID differs — the cache key must be identical');

        // Prove it end to end too: a round's real reuseOrQueue() call must actually hit the cache.
        Backtest::create([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000, 'params' => $overrides1, 'cache_key' => $key1,
            'status' => 'done', 'stats' => ['total_return_pct' => 4.2, 'trades' => 30],
        ]);
        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => '2026-08-01', 'to' => '2026-08-24',
            'starting_cash' => 10000.0, 'params' => $overrides2, 'cache_key' => $key2, 'queue' => 'backtests',
        ], 12);
        $this->assertSame('done', $bt->status, 'the second round\'s different batch UUID must still hit the first round\'s cached result');
    }

    /**
     * Reviewer blocker 1: the candle-revision component used max(updated_at) with no time bound, so it
     * moved every time the tape's newest (still-open) candle ticked — even for a window that already
     * closed. Bounding it to candle_start < $to makes a completed window's identity, and therefore its
     * cache key, stable against a candle written after the window ends.
     */
    public function test_a_tail_candle_write_after_the_window_does_not_change_the_cache_identity(): void
    {
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => '2026-08-01 00:00:00', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1, 'updated_at' => now()->subDay()]);
        $overrides = ['mr.timeframe' => '1H'];
        $windowEnd = '2026-08-24 00:00:00';

        $before = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, $windowEnd);
        // A tail candle past the window end ticks a few seconds later, as the live feed keeps writing.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => '2026-08-24 01:00:00', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1, 'updated_at' => now()]);
        $after = DeskOptimize::cacheIdentity('mr', ['BTC-USD'], $overrides, $windowEnd);

        $this->assertSame($before['_cache']['candles'], $after['_cache']['candles'], 'a candle outside the window must never move a completed window\'s revision marker');
    }
}
