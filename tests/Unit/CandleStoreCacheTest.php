<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Market\CandleStore;
use App\Exchange\Coinbase\CoinbaseMarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class CandleStoreCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['desk.candles.cache_store' => 'array', 'desk.candles.cache_enabled' => true]);
    }

    private function store(): CandleStore
    {
        return new CandleStore($this->createMock(CoinbaseMarketData::class));
    }

    /** version()/bumpVersion() now go straight through Redis::connection('default') (matching the
     *  feeder's own writes) instead of the Cache facade, so `desk.candles.cache_store => 'array'`
     *  no longer isolates them -- fake the connection exactly like DeskRoundsWatchTest does, so
     *  these tests stay hermetic (deterministic, no real network Redis) instead of reading/writing
     *  literal `candles:ver:*` keys on the shared fleet Redis across test runs. */
    private function fakeVersionRedis(): void
    {
        $store = [];
        $connection = Mockery::mock();
        // Both must close over `$store` BY REFERENCE (not an arrow fn's by-value auto-capture) --
        // otherwise `get` keeps reading the empty array it was created with and never sees an
        // `incr` that ran afterward.
        $connection->shouldReceive('get')->andReturnUsing(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $connection->shouldReceive('incr')->andReturnUsing(function (string $key) use (&$store) {
            $store[$key] = ($store[$key] ?? 0) + 1;

            return $store[$key];
        });
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);
    }

    private function insertCandle(string $productId, string $timeframe, int $start, float $close = 100.0): void
    {
        DB::table('candles')->insert([
            'product_id' => $productId,
            'timeframe' => $timeframe,
            'candle_start' => gmdate('Y-m-d H:i:s', $start),
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
            'volume' => 1.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_closed_window_hits_the_database_once_across_two_calls(): void
    {
        $pid = 'CACHE-CLOSED';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;   // well past the 2h "closed" cutoff
        $this->insertCandle($pid, '1m', $from + 60);
        $this->insertCandle($pid, '1m', $from + 120);

        $store = $this->store();

        DB::enableQueryLog();
        $first = $store->bars($pid, '1m', $from, $to);
        $afterFirst = count(DB::getQueryLog());
        $second = $store->bars($pid, '1m', $from, $to);
        $afterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame($afterFirst, $afterSecond, 'second call for a closed window must not touch the database');
    }

    public function test_cached_result_is_byte_identical_to_a_fresh_query(): void
    {
        $pid = 'CACHE-IDENTICAL';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $this->insertCandle($pid, '1m', $from + 60, 123.45);
        $this->insertCandle($pid, '1m', $from + 120, 234.56);

        $store = $this->store();
        $cached = $store->bars($pid, '1m', $from, $to);   // primes the cache

        config(['desk.candles.cache_enabled' => false]);
        $fresh = $store->bars($pid, '1m', $from, $to);   // forces a real query, cache bypassed

        $this->assertSame($fresh, $cached);
        $this->assertSame(
            ['start', 'open', 'high', 'low', 'close', 'volume'],
            array_keys($fresh[0])
        );
    }

    public function test_an_open_windows_key_changes_when_a_newer_bar_lands(): void
    {
        $pid = 'CACHE-OPEN';
        $from = time() - 3600;
        $this->insertCandle($pid, '1m', $from);

        $store = $this->store();
        $first = $store->bars($pid, '1m', $from, null);
        $this->assertCount(1, $first);

        $this->insertCandle($pid, '1m', time() - 60);

        // The latest-bar/freshness probe is memoized for a few seconds so a burst of callers shares
        // one query; advance past that window (15s default probe TTL) so the new bar becomes visible.
        Carbon::setTestNow(now()->addSeconds(16));
        $second = $store->bars($pid, '1m', $from, null);
        Carbon::setTestNow();

        $this->assertCount(2, $second);
    }

    public function test_the_kill_switch_bypasses_the_cache(): void
    {
        config(['desk.candles.cache_enabled' => false]);

        $pid = 'CACHE-KILLSWITCH';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $this->insertCandle($pid, '1m', $from + 60);

        $store = $this->store();

        DB::enableQueryLog();
        $store->bars($pid, '1m', $from, $to);
        $afterFirst = count(DB::getQueryLog());
        $store->bars($pid, '1m', $from, $to);
        $afterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan($afterFirst, $afterSecond, 'each call must re-query with the cache disabled');
    }

    public function test_a_corrupted_cached_payload_falls_back_to_the_query(): void
    {
        $pid = 'CACHE-CORRUPT';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;   // "closed" window, deterministic stamp
        $this->insertCandle($pid, '1m', $from + 60, 55.5);

        $key = 'candles:v1:'.sha1($pid.'|1m|'.$from.'|'.$to.'|closed|v0');
        Cache::store('array')->put($key, 'not a gzip payload', 3600);

        $rows = $this->store()->bars($pid, '1m', $from, $to);

        $this->assertCount(1, $rows);
        $this->assertSame(55.5, $rows[0]['close']);
    }

    public function test_the_timeframe_candle_start_index_migration_ran(): void
    {
        $indexes = array_column(DB::select("PRAGMA index_list('candles')"), 'name');

        $this->assertContains('candles_timeframe_candle_start_index', $indexes);
    }

    /** Finding 17's exact reproduction: a historical correction to a "closed" (long-TTL) window
     *  must invalidate the cached slice even though neither the requested range nor any "latest
     *  bar" marker changed. */
    public function test_upsert_correcting_a_closed_windows_candle_invalidates_the_cache(): void
    {
        $pid = 'CACHE-CORRECTION';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $start = $from + 60;
        $this->insertCandle($pid, '1m', $start, 100.0);

        $store = $this->store();
        $first = $store->bars($pid, '1m', $from, $to);
        $this->assertSame(100.0, $first[0]['close']);

        $store->upsert($pid, '1m', [[
            'start' => $start, 'open' => 200.0, 'high' => 200.0, 'low' => 200.0, 'close' => 200.0, 'volume' => 1.0,
        ]]);

        $second = $store->bars($pid, '1m', $from, $to);
        $this->assertSame(200.0, $second[0]['close'], 'a correction to an already-cached closed window must not serve the stale slice');
    }

    /** An "open" window's cache key only tracked max(candle_start), so correcting a bar that is
     *  NOT the newest in the window left the old stamp -- and the stale cache -- in place. The
     *  data version must catch this too. */
    public function test_upsert_correcting_a_non_latest_bar_in_an_open_window_invalidates_the_cache(): void
    {
        $pid = 'CACHE-OPEN-CORRECTION';
        $older = time() - 3600;
        $newer = time() - 60;
        $this->insertCandle($pid, '1m', $older, 100.0);
        $this->insertCandle($pid, '1m', $newer, 50.0);

        $store = $this->store();
        $first = $store->bars($pid, '1m', $older, null);
        $this->assertSame(100.0, $first[0]['close']);

        // Correct the OLDER bar; max(candle_start) -- part of the open-window key -- is unchanged.
        // Advance past the freshness probe's memo window (15s default) so the correction's
        // updated_at is visible.
        Carbon::setTestNow(now()->addSeconds(16));
        $store->upsert($pid, '1m', [[
            'start' => $older, 'open' => 999.0, 'high' => 999.0, 'low' => 999.0, 'close' => 999.0, 'volume' => 1.0,
        ]]);

        $second = $store->bars($pid, '1m', $older, null);
        Carbon::setTestNow();
        $this->assertSame(999.0, $second[0]['close'], 'correcting a non-latest bar must still bust the cache');
    }

    /** version() only bumps for a write that touches the closed range -- otherwise every tail
     *  refresh (a new bar landing every few seconds) would evict every closed-window slice for
     *  the pair, defeating that slice's whole 24h TTL. */
    public function test_version_starts_at_zero_and_increments_only_on_closed_range_writes(): void
    {
        $this->fakeVersionRedis();
        $pid = 'CACHE-VERSION';
        $this->assertSame(0, CandleStore::version($pid, '1m'));

        $store = $this->store();
        $old = time() - 10 * 86400;
        $store->upsert($pid, '1m', [[
            'start' => $old, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(1, CandleStore::version($pid, '1m'));

        $store->upsert($pid, '1m', [[
            'start' => $old + 60, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(2, CandleStore::version($pid, '1m'));
    }

    public function test_a_tail_only_upsert_does_not_bump_the_history_version(): void
    {
        $this->fakeVersionRedis();
        $pid = 'CACHE-VERSION-TAIL';
        $store = $this->store();

        $store->upsert($pid, '1m', [[
            'start' => time(), 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);

        $this->assertSame(0, CandleStore::version($pid, '1m'), 'a write confined to the recent/open range must not evict every closed-window slice');
    }

    /** The exact key the feeder writes (feed.mjs bumpVersions(): `candles:ver:{pid}:{tf}`, no
     *  case-folding) -- this is what actually made version() read 0 forever against a live feeder
     *  before the fix (it was reading a completely different Redis DB/prefix via the Cache facade,
     *  and uppercasing the product id on top of that). */
    public function test_version_key_matches_the_feeders_literal_key_format(): void
    {
        $this->assertSame('candles:ver:BTC-USD:1m', CandleStore::versionKey('BTC-USD', '1m'));
        $this->assertSame(
            'candles:ver:eth-usd:1m',
            CandleStore::versionKey('eth-usd', '1m'),
            'no case-folding — the feeder never uppercases the product id either'
        );
    }

    /** version()/bumpVersion() must talk to the same Redis connection ('default') the feeder uses,
     *  not the Cache facade's own store/connection -- otherwise a bump the feeder makes (or a bump
     *  this class makes) can silently land somewhere the other side never reads from. */
    public function test_version_reads_and_bump_version_writes_through_the_default_redis_connection(): void
    {
        $pid = 'CACHE-VERSION-REDIS-CONN';
        $connection = Mockery::mock();
        $connection->shouldReceive('get')->once()->with('candles:ver:'.$pid.':1m')->andReturn('7');
        $connection->shouldReceive('incr')->once()->with('candles:ver:'.$pid.':1m');
        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $this->assertSame(7, CandleStore::version($pid, '1m'));

        // A closed-range write must bump the SAME key on the SAME connection.
        $this->store()->upsert($pid, '1m', [[
            'start' => time() - 10 * 86400, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
    }

    /** CLOSED_AGE_SECONDS is a flat 30 minutes for 1m, but a coarse timeframe's own bar is still
     *  legitimately "forming" long past that -- closedAgeSeconds() widens the cutoff to 2x the
     *  timeframe's duration so an ordinary tail write on 1H/1D doesn't get mistaken for a
     *  historical correction and bump the version on every single write. */
    public function test_closed_age_scales_with_timeframe_duration_for_1H(): void
    {
        $this->fakeVersionRedis();
        $pid = 'CACHE-VERSION-1H';
        $store = $this->store();

        // 40 minutes old: past the flat 30-minute floor, but well inside 2x an hourly bar's own
        // duration (2h) -- must NOT count as closed for a 1H bar.
        $store->upsert($pid, '1H', [[
            'start' => time() - 40 * 60, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(0, CandleStore::version($pid, '1H'), 'a bar inside 2x the 1H duration must not bump the history version');

        // Comfortably past 2x the 1H duration (2h) -- must count as closed.
        $store->upsert($pid, '1H', [[
            'start' => time() - 5 * 3600, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(1, CandleStore::version($pid, '1H'));
    }

    public function test_closed_age_scales_with_timeframe_duration_for_1D(): void
    {
        $this->fakeVersionRedis();
        $pid = 'CACHE-VERSION-1D';
        $store = $this->store();

        // 12 hours old: past the flat 30-minute floor, but well inside 2x a daily bar's own
        // duration (48h) -- must NOT count as closed for a 1D bar.
        $store->upsert($pid, '1D', [[
            'start' => time() - 12 * 3600, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(0, CandleStore::version($pid, '1D'), 'a bar inside 2x the 1D duration must not bump the history version');

        // Comfortably past 2x the 1D duration (48h) -- must count as closed.
        $store->upsert($pid, '1D', [[
            'start' => time() - 3 * 86400, 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 1.0,
        ]]);
        $this->assertSame(1, CandleStore::version($pid, '1D'));
    }

    /** The exact regression this fix targets: a normal tail refresh (a new, recent bar landing --
     *  what sync()/the feeder do constantly) must not evict an already-cached closed-window slice. */
    public function test_a_tail_refresh_does_not_evict_a_cached_closed_window(): void
    {
        $pid = 'CACHE-TAIL-VS-CLOSED';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $this->insertCandle($pid, '1m', $from + 60, 11.0);

        $store = $this->store();

        DB::enableQueryLog();
        $first = $store->bars($pid, '1m', $from, $to);
        $afterFirst = count(DB::getQueryLog());

        $store->upsert($pid, '1m', [[
            'start' => time(), 'open' => 2.0, 'high' => 2.0, 'low' => 2.0, 'close' => 2.0, 'volume' => 1.0,
        ]]);
        $beforeSecond = count(DB::getQueryLog());   // the upsert's own write legitimately adds a query

        $second = $store->bars($pid, '1m', $from, $to);
        $afterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame($beforeSecond, $afterSecond, 'a tail-only write must not evict a cached closed-window slice');
    }

    /** The feeder writes candles directly in Node/MySQL, never through CandleStore::upsert(), so it
     *  never bumps version() -- only the MAX(updated_at) component of the open-window key can catch
     *  a feeder-style correction to an existing bar. */
    public function test_a_direct_db_correction_bypassing_upsert_invalidates_an_open_window_after_the_probe_ttl(): void
    {
        $pid = 'CACHE-FEEDER-STYLE';
        $newer = time() - 60;
        $this->insertCandle($pid, '1m', $newer, 5.0);

        $store = $this->store();
        $first = $store->bars($pid, '1m', $newer, null);
        $this->assertSame(5.0, $first[0]['close']);

        Carbon::setTestNow(now()->addSeconds(16));
        DB::table('candles')->where('product_id', $pid)->where('timeframe', '1m')->update(['close' => 9.0, 'updated_at' => now()]);

        $second = $store->bars($pid, '1m', $newer, null);
        Carbon::setTestNow();

        $this->assertSame(9.0, $second[0]['close']);
    }

    public function test_the_freshness_probe_is_memoized_so_a_burst_of_calls_shares_one_query(): void
    {
        $pid = 'CACHE-PROBE-MEMO';
        $newer = time() - 60;
        $this->insertCandle($pid, '1m', $newer, 1.0);
        $store = $this->store();

        DB::enableQueryLog();
        $store->bars($pid, '1m', $newer, null);
        $afterFirst = count(DB::getQueryLog());
        $store->bars($pid, '1m', $newer, null);
        $afterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($afterFirst, $afterSecond, 'a second call within the probe TTL must not repeat the freshness query');
    }

    public function test_probe_ttl_defaults_to_15_seconds(): void
    {
        $pid = 'CACHE-PROBE-TTL-DEFAULT';
        $newer = time() - 60;
        $this->insertCandle($pid, '1m', $newer, 1.0);
        $store = $this->store();

        DB::enableQueryLog();
        $store->bars($pid, '1m', $newer, null);
        $afterFirst = count(DB::getQueryLog());

        // Comfortably past the old 5s TTL but inside the new 15s default -- must still be memoized.
        Carbon::setTestNow(now()->addSeconds(10));
        $store->bars($pid, '1m', $newer, null);
        $afterSecond = count(DB::getQueryLog());
        Carbon::setTestNow();
        DB::disableQueryLog();

        $this->assertSame($afterFirst, $afterSecond, 'the freshness probe must stay memoized for the new 15s default TTL');
    }

    public function test_probe_ttl_seconds_is_configurable(): void
    {
        config(['desk.candles.probe_ttl_seconds' => 2]);

        $pid = 'CACHE-PROBE-TTL-CONFIG';
        $newer = time() - 60;
        $this->insertCandle($pid, '1m', $newer, 1.0);
        $store = $this->store();

        DB::enableQueryLog();
        $store->bars($pid, '1m', $newer, null);
        $afterFirst = count(DB::getQueryLog());

        Carbon::setTestNow(now()->addSeconds(3));
        $store->bars($pid, '1m', $newer, null);
        $afterSecond = count(DB::getQueryLog());
        Carbon::setTestNow();
        DB::disableQueryLog();

        $this->assertGreaterThan($afterFirst, $afterSecond, 'a shorter configured probe TTL must force a fresh probe query');
    }

    /** Simulates a concurrent winner: it already acquired (and released) the slice lock and left the
     *  correct payload cached before this call ever tries to acquire it -- the follower's post-lock
     *  cache re-check must return that payload without a second database read. */
    public function test_a_follower_reuses_a_lock_holders_result_without_its_own_query(): void
    {
        $pid = 'CACHE-LOCK-SHARE';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $rows = [['start' => $from + 60, 'open' => 77.0, 'high' => 77.0, 'low' => 77.0, 'close' => 77.0, 'volume' => 1.0]];

        $key = 'candles:v1:'.sha1($pid.'|1m|'.$from.'|'.$to.'|closed|v0');
        $cache = Cache::store('array');

        $winner = $cache->lock('candles:slice-lock:'.$key, 10);
        $this->assertTrue($winner->get());
        $cache->put($key, gzcompress(serialize($rows), 1), 3600);
        $winner->release();

        $store = $this->store();
        $method = new \ReflectionMethod($store, 'loadAndCacheSlice');
        $method->setAccessible(true);

        DB::enableQueryLog();
        $result = $method->invoke($store, $pid, '1m', $from, $to, $key, $cache, 3600);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($rows, $result);
        $this->assertSame(0, $queries, 'the follower must reuse the winners cached slice instead of re-querying');
    }

    /** A holder that acquired the lock and then died mid-fetch (no release, no cached payload) must
     *  not wedge a follower forever -- only the lock's own short TTL, not the follower's full
     *  configured wait, should gate how long it takes to recover. */
    public function test_a_dead_lock_holder_does_not_deadlock_a_follower(): void
    {
        config(['desk.candles.lock_wait_seconds' => 3]);

        $pid = 'CACHE-LOCK-DEAD';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $this->insertCandle($pid, '1m', $from + 60, 42.0);

        $key = 'candles:v1:'.sha1($pid.'|1m|'.$from.'|'.$to.'|closed|v0');
        $dead = Cache::store('array')->lock('candles:slice-lock:'.$key, 1);
        $this->assertTrue($dead->get());   // acquired, then abandoned: never released

        $started = microtime(true);
        $result = $this->store()->bars($pid, '1m', $from, $to);
        $elapsed = microtime(true) - $started;

        $this->assertSame(42.0, $result[0]['close']);
        $this->assertLessThan(3.0, $elapsed, 'a dead holder must not force the full configured wait, let alone hang');
    }

    /** The in-process LRU never expired at all, so a long-lived process could keep serving a slice
     *  well past what the shared store's own TTL would have allowed. Simulate that: prime the local
     *  cache, then remove the row's only trace elsewhere (the shared cache entry AND -- via a raw
     *  update that bypasses CandleStore::upsert -- the database), so the stale local copy would
     *  survive forever without its own expiry. */
    public function test_local_cache_entries_expire_independently_of_the_shared_store(): void
    {
        $pid = 'CACHE-LOCAL-TTL';
        $from = time() - 10 * 86400;
        $to = time() - 5 * 86400;
        $start = $from + 60;
        $this->insertCandle($pid, '1m', $start, 100.0);

        $store = $this->store();
        $primed = $store->bars($pid, '1m', $from, $to);
        $this->assertSame(100.0, $primed[0]['close']);

        // Wipe the shared cache entry and mutate the DB directly (no CandleStore::upsert, so the
        // data version does not change) -- only the in-process copy can still be masking this.
        Cache::store('array')->flush();
        DB::table('candles')->where('product_id', $pid)->where('timeframe', '1m')->update(['close' => 200.0]);

        $stillLocal = $store->bars($pid, '1m', $from, $to);
        $this->assertSame(100.0, $stillLocal[0]['close'], 'sanity check: the unexpired local entry is what is masking the change');

        Carbon::setTestNow(now()->addSeconds(61));
        $afterExpiry = $store->bars($pid, '1m', $from, $to);
        Carbon::setTestNow();

        $this->assertSame(200.0, $afterExpiry[0]['close'], 'a local entry must expire instead of outliving the shared store forever');
    }
}
