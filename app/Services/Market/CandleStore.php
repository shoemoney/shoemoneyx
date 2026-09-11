<?php

declare(strict_types=1);

namespace App\Services\Market;

use App\Exchange\Contracts\MarketData;
use App\Models\Candle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Keeps `candles` current for tracked products. Coinbase caps each request at
 * 350 candles, so backfills walk backwards in windows.
 */
class CandleStore
{
    private const CACHE_PREFIX = 'candles:v1:';

    private const VERSION_PREFIX = 'candles:ver:';

    private const TTL_CLOSED = 24 * 3600;

    private const TTL_OPEN = 600;

    private const LOCAL_LIMIT = 4;

    /** Per-process cache entries never outlive this, even for a "closed" (24h Redis TTL) slice --
     *  a long-lived process (optimizer, worker) must eventually go back to the shared store instead
     *  of trusting its own copy forever. */
    private const LOCAL_TTL_SECONDS = 60;

    /** Safety-net TTL on the per-slice lock: if the holder dies mid-fetch without releasing, the
     *  next caller doesn't wait past this even if it also misses its own bounded block(). */
    private const LOCK_TTL_SECONDS = 10;

    /** Default bound a follower will wait for the lock holder to fill a missed slice before giving
     *  up and reading the database directly itself. Overridable (tests shrink it) via
     *  desk.candles.lock_wait_seconds. */
    private const LOCK_WAIT_SECONDS = 5;

    /** How long the open-window freshness probe (MAX(updated_at)) is trusted before re-querying --
     *  long enough that a burst of callers (many optimizer candidates, many worker processes) share
     *  one query, short enough that a feeder correction (which bypasses this class entirely, so
     *  never bumps version()) still surfaces quickly. Until migration 2026_09_06_000400 (an index
     *  on product_id, timeframe, updated_at) lands, this probe is a range scan -- 15s keeps it rare
     *  without hiding a correction for long. Overridable via desk.candles.probe_ttl_seconds. */
    private const PROBE_TTL_SECONDS = 15;

    /** How far in the past a window's `to` (or the store's latest bar) must be to count as "closed". 30 minutes: optimizer
     *  windows end on the hour, and a 2 h margin kept every test-window slice "open" (re-keyed per new candle) for the
     *  whole hour it was current. Closed bars on Coinbase are effectively final well inside 30 minutes. This is a floor,
     *  not a flat value -- closedAgeSeconds() widens it to 2x the timeframe's own duration, since a flat 30 minutes made
     *  every tail write on 1H/6H/1D bump the history version (their bars are still "forming" well past 30 minutes old). */
    private const CLOSED_AGE_SECONDS = 30 * 60;

    /** Per-process LRU of the most recently decoded slices, oldest first. */
    private static array $local = [];

    public function __construct(private MarketData $market) {}

    /**
     * Ensure we hold candles for [$fromUnix, now]. Fetches only the missing tail.
     *
     * @return int candles upserted
     */
    public function sync(string $productId, string $timeframe, int $fromUnix): int
    {
        if (isset(Candle::DERIVED[$timeframe])) {
            return $this->sync($productId, Candle::DERIVED[$timeframe], $fromUnix);
        }
        if (in_array($timeframe, Candle::FROM_TRADES, true)) {
            return 0;   // sub-minute bars come from the tape: feeder (live) or market:backfill-trades (history)
        }
        $dur = Candle::DURATIONS[$timeframe] ?? throw new \InvalidArgumentException($timeframe);
        $fromUnix -= $fromUnix % $dur;
        $q = Candle::for($productId, $timeframe);
        $earliest = $q->clone()->min('candle_start');
        $latest = $q->clone()->max('candle_start');
        $total = 0;

        if ($earliest === null) {
            return $this->fetchRange($productId, $timeframe, $fromUnix, time());
        }

        // Head gap: history before what we hold.
        $earliestTs = strtotime((string) $earliest.' UTC');
        if ($fromUnix < $earliestTs - $dur) {
            $total += $this->fetchRange($productId, $timeframe, $fromUnix, $earliestTs - $dur);
        }

        // Tail: refresh the last two bars (the newest is still forming) and anything after.
        $latestTs = strtotime((string) $latest.' UTC');
        $total += $this->fetchRange($productId, $timeframe, max($fromUnix, $latestTs - $dur * 2), time());

        return $total;
    }

    /** Walk a range in 350-candle windows (Coinbase's cap) and upsert. */
    public function fetchRange(string $productId, string $timeframe, int $start, int $end): int
    {
        $dur = Candle::DURATIONS[$timeframe];
        $total = 0;
        $cursor = $start;
        while ($cursor < $end) {
            $windowEnd = min($end, $cursor + $dur * (MarketData::MAX_CANDLES - 1));
            $rows = $this->market->candles($productId, $timeframe, $cursor, $windowEnd);
            $total += $this->upsert($productId, $timeframe, $rows);
            $cursor = $windowEnd + $dur;
        }

        return $total;
    }

    /** Backfill $days of history in one go (used by market:backfill and the backtester). */
    public function backfill(string $productId, string $timeframe, int $days): int
    {
        return $this->sync($productId, $timeframe, time() - $days * 86400);
    }

    /**
     * True when (product, timeframe) already has a candle row updated within the last
     * $maxAgeSeconds — the desk loop keeps every tracked product's 1H/1m candles fresh every
     * minute, so a caller (the backtest worker) can skip its own redundant sync() for those.
     * Backed by candles_product_id_timeframe_updated_at_index.
     */
    public function fresh(string $productId, string $timeframe, int $maxAgeSeconds): bool
    {
        $updatedAt = Candle::for($productId, $timeframe)->max('updated_at');
        if ($updatedAt === null) {
            return false;
        }

        return strtotime((string) $updatedAt.' UTC') >= time() - $maxAgeSeconds;
    }

    /**
     * Aggregate finer bars into $dur-second buckets aligned to the epoch (3m, 4m…).
     *
     * @param  array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>  $bars  oldest -> newest
     * @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public static function resample(array $bars, int $dur): array
    {
        $out = [];
        $cur = null;
        foreach ($bars as $b) {
            $bucket = $b['start'] - $b['start'] % $dur;
            if ($cur === null || $cur['start'] !== $bucket) {
                if ($cur !== null) {
                    $out[] = $cur;
                }
                $cur = ['start' => $bucket, 'open' => $b['open'], 'high' => $b['high'], 'low' => $b['low'], 'close' => $b['close'], 'volume' => $b['volume']];
            } else {
                $cur['high'] = max($cur['high'], $b['high']);
                $cur['low'] = min($cur['low'], $b['low']);
                $cur['close'] = $b['close'];
                $cur['volume'] += $b['volume'];
            }
        }
        if ($cur !== null) {
            $out[] = $cur;
        }

        return $out;
    }

    /** @param array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> $rows */
    public function upsert(string $productId, string $timeframe, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $now = now();
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = [
                'product_id' => strtoupper($productId),
                'timeframe' => $timeframe,
                'candle_start' => gmdate('Y-m-d H:i:s', $r['start']),
                'open' => $r['open'], 'high' => $r['high'], 'low' => $r['low'], 'close' => $r['close'],
                'volume' => $r['volume'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('candles')->upsert($chunk, ['product_id', 'timeframe', 'candle_start'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        }

        // Only a write that touches the "closed" range bumps version() -- an ordinary tail refresh
        // (new bar, or the last couple of still-forming bars) writes exclusively recent candles and
        // must NOT invalidate every long-TTL closed-window slice for this pair on every sync. A
        // correction reaching back into history (a repair, or a backfill patching a hole) does.
        $cutoff = time() - self::closedAgeSeconds($timeframe);
        foreach ($rows as $r) {
            if ($r['start'] < $cutoff) {
                self::bumpVersion($productId, $timeframe);
                break;
            }
        }

        return count($batch);
    }

    /**
     * Oldest -> newest bars from the store. Backed by a Redis slice cache: every candidate in an
     * optimizer round asks for the same (product, timeframe, window), so this is the difference
     * between one query per round and one query per candidate. A window whose `to` (or, for an
     * open-ended request, the store's own latest bar) is more than two hours old is "closed" and
     * cached for a day, keyed on version() so only a write touching that history evicts it; anything
     * newer is "open" and cached for ten minutes, keyed on the current max(candle_start) (a fresh
     * bar changes the key on its own) plus a short-lived MAX(updated_at) freshness probe that also
     * catches a correction to an existing bar -- including one written by the feeder, which never
     * goes through this class. Concurrent misses for the same key share one database read via a
     * per-slice lock instead of each racing the query.
     *
     * @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    public function bars(string $productId, string $timeframe, int $fromUnix, ?int $toUnix = null): array
    {
        if (isset(Candle::DERIVED[$timeframe])) {
            $base = Candle::DERIVED[$timeframe];
            $dur = Candle::DURATIONS[$timeframe];

            return self::resample($this->bars($productId, $base, $fromUnix - $fromUnix % $dur, $toUnix), $dur);
        }

        if (! self::cacheEnabled()) {
            return $this->barsFromDb($productId, $timeframe, $fromUnix, $toUnix);
        }

        $now = time();
        $closedAge = self::closedAgeSeconds($timeframe);
        if ($toUnix !== null && $toUnix < $now - $closedAge) {
            $closed = true;
        } else {
            // Recent or open-ended: needs the store's latest bar either way, so probe once
            // (memoized) instead of running cacheStamp's old unconditional, unmemoized query.
            $probe = $this->probe($productId, $timeframe);
            $closed = $toUnix === null && ($probe['latest'] === null || $probe['latest'] < $now - $closedAge);
        }

        $ttl = $closed ? self::TTL_CLOSED : self::TTL_OPEN;
        $key = $closed
            ? self::CACHE_PREFIX.sha1($productId.'|'.$timeframe.'|'.$fromUnix.'|'.$toUnix.'|closed|v'.self::version($productId, $timeframe))
            : self::CACHE_PREFIX.sha1($productId.'|'.$timeframe.'|'.$fromUnix.'|'.$toUnix.'|open|l'.$probe['latest'].'|f'.$probe['updatedAt']);

        $cached = self::localGet($key);
        if ($cached !== null) {
            return $cached;
        }

        $store = self::cacheStore();
        $cache = Cache::store($store);

        try {
            $packed = $cache->get($key);
        } catch (Throwable) {
            $packed = null;
        }

        $rows = is_string($packed) ? self::unpack($packed) : null;

        if ($rows !== null) {
            self::bump('hits');
            self::localPut($key, $rows);

            return $rows;
        }

        self::bump('misses');
        $rows = $this->loadAndCacheSlice($productId, $timeframe, $fromUnix, $toUnix, $key, $cache, $ttl);
        self::localPut($key, $rows);

        return $rows;
    }

    /**
     * Fills a missed slice under a per-key lock so N simultaneous callers (every candidate in an
     * optimizer round starting at once) share one database read instead of each running it. A
     * follower blocks up to lock_wait_seconds() for the holder, re-checks the cache first (the
     * holder may already have filled it), and — if the holder is slow, dead, or the store simply
     * doesn't support locks — falls back to reading the database directly rather than waiting
     * indefinitely or failing the request.
     *
     * @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function loadAndCacheSlice(string $productId, string $timeframe, int $fromUnix, ?int $toUnix, string $key, $cache, int $ttl): array
    {
        try {
            $lock = $cache->lock('candles:slice-lock:'.$key, self::LOCK_TTL_SECONDS);
        } catch (Throwable) {
            $lock = null;   // this cache driver doesn't support locks — fall through unlocked
        }

        if ($lock === null) {
            return $this->fetchAndCache($productId, $timeframe, $fromUnix, $toUnix, $key, $cache, $ttl);
        }

        try {
            return $lock->block(self::lockWaitSeconds(), function () use ($productId, $timeframe, $fromUnix, $toUnix, $key, $cache, $ttl) {
                try {
                    $packed = $cache->get($key);
                } catch (Throwable) {
                    $packed = null;
                }
                $rows = is_string($packed) ? self::unpack($packed) : null;
                if ($rows !== null) {
                    self::bump('hits');

                    return $rows;
                }

                return $this->fetchAndCache($productId, $timeframe, $fromUnix, $toUnix, $key, $cache, $ttl);
            });
        } catch (Throwable) {
            // block() timed out (the holder is slow or died mid-fetch) or the lock store errored --
            // never make the request wait indefinitely: read straight from the database ourselves.
            return $this->barsFromDb($productId, $timeframe, $fromUnix, $toUnix);
        }
    }

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function fetchAndCache(string $productId, string $timeframe, int $fromUnix, ?int $toUnix, string $key, $cache, int $ttl): array
    {
        $rows = $this->barsFromDb($productId, $timeframe, $fromUnix, $toUnix);

        try {
            $cache->put($key, self::pack($rows), $ttl);
        } catch (Throwable) {
            // best effort: the query already ran, an unwritable cache should not fail the request
        }

        return $rows;
    }

    private static function lockWaitSeconds(): int
    {
        return (int) (config('desk.candles.lock_wait_seconds') ?? self::LOCK_WAIT_SECONDS);
    }

    /** How long a bar of this timeframe must be in the past to count as "closed" -- never less than
     *  CLOSED_AGE_SECONDS, but widened to 2x the timeframe's own duration for coarse timeframes
     *  (1H/6H/1D...) so an ordinary tail write on them doesn't get treated as a historical
     *  correction just because it's older than a flat 30 minutes. */
    private static function closedAgeSeconds(string $timeframe): int
    {
        $dur = Candle::DURATIONS[$timeframe] ?? null;

        return $dur === null ? self::CLOSED_AGE_SECONDS : max(self::CLOSED_AGE_SECONDS, $dur * 2);
    }

    private static function probeTtlSeconds(): int
    {
        return (int) (config('desk.candles.probe_ttl_seconds') ?? self::PROBE_TTL_SECONDS);
    }

    /**
     * History version for (product, timeframe): bumped only by a write that touches the closed
     * range (see upsert()), so it belongs in a closed-window cache key without a tail refresh
     * evicting it. Public so an external cache key (the optimizer's own backtest-result cache) can
     * be built from the same signal — the whole point is a stable number that only moves when data
     * relevant to a backtest actually changed.
     *
     * Read/written straight through Redis::connection('default') — the same connection bump()
     * uses via the Redis facade's default proxy — rather than through the Cache facade. The feeder
     * (feed.mjs) INCRs this exact key (`candles:ver:{pid}:{tf}`, no case-folding) on that same
     * connection after every flush; going through Cache::store(cacheStore()) instead landed on a
     * different Redis logical DB under a different key prefix (the 'redis' cache store's own
     * connection/prefix), so this never coincided with what the feeder actually wrote and read 0
     * forever against a live feeder.
     */
    public static function version(string $productId, string $timeframe): int
    {
        if (isset(Candle::DERIVED[$timeframe])) {
            $timeframe = Candle::DERIVED[$timeframe];
        }

        try {
            $v = Redis::connection('default')->get(self::versionKey($productId, $timeframe));
        } catch (Throwable) {
            $v = null;
        }

        return is_numeric($v) ? (int) $v : 0;
    }

    /** Exactly the key the feeder writes (see feed.mjs bumpVersions()) -- no case-folding, since the
     *  feeder never uppercases the product id either. Public so a test can assert the literal string
     *  without a real (or mocked) Redis round trip. */
    public static function versionKey(string $productId, string $timeframe): string
    {
        return self::VERSION_PREFIX.$productId.':'.$timeframe;
    }

    private static function bumpVersion(string $productId, string $timeframe): void
    {
        try {
            Redis::connection('default')->incr(self::versionKey($productId, $timeframe));
        } catch (Throwable) {
            // best effort: a missed bump costs one extra stale read window, not silent corruption --
            // the next successful write bumps it and the Redis/local TTLs still bound the staleness.
        }
    }

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function barsFromDb(string $productId, string $timeframe, int $fromUnix, ?int $toUnix): array
    {
        $q = Candle::for($productId, $timeframe)->where('candle_start', '>=', gmdate('Y-m-d H:i:s', $fromUnix));
        if ($toUnix !== null) {
            $q->where('candle_start', '<=', gmdate('Y-m-d H:i:s', $toUnix));
        }

        return $q->orderBy('candle_start')->get(['candle_start', 'open', 'high', 'low', 'close', 'volume'])
            ->map(fn (Candle $c) => [
                'start' => $c->candle_start->getTimestamp(),
                'open' => $c->open, 'high' => $c->high, 'low' => $c->low, 'close' => $c->close, 'volume' => $c->volume,
            ])->all();
    }

    /**
     * Latest-bar timestamp and update fingerprint for (product, timeframe), memoized in the shared
     * cache store for a few seconds. bars() uses this both to decide open-vs-closed and to build
     * the open-window cache key: `latest` (max candle_start) moves whenever a new bar lands, exactly
     * like the old cacheStamp() signal; `updatedAt` (max updated_at) additionally moves when an
     * existing bar's OHLCV is corrected in place -- an UPDATE that leaves candle_start unchanged --
     * including a correction written by the feeder, which writes candles directly in Node and so
     * never touches version(). Memoizing this is what lets a local-cache hit in bars() skip a
     * database round trip entirely, and what lets a burst of callers (many optimizer candidates,
     * many worker processes) share one query instead of one per call.
     *
     * @return array{latest: ?int, updatedAt: string}
     */
    private function probe(string $productId, string $timeframe): array
    {
        $probeKey = 'candles:probe:'.strtoupper($productId).':'.$timeframe;

        try {
            return Cache::store(self::cacheStore())->remember($probeKey, self::probeTtlSeconds(), function () use ($productId, $timeframe) {
                $row = Candle::for($productId, $timeframe)->selectRaw('MAX(candle_start) as latest, MAX(updated_at) as updated')->first();
                $latestTs = $row?->latest !== null ? strtotime(((string) $row->latest).' UTC') : false;

                return [
                    'latest' => $latestTs !== false ? $latestTs : null,
                    'updatedAt' => $row?->updated !== null ? (string) $row->updated : 'none',
                ];
            });
        } catch (Throwable) {
            return ['latest' => null, 'updatedAt' => 'none'];
        }
    }

    private static function pack(array $rows): string
    {
        return (string) gzcompress(serialize($rows), 1);
    }

    private static function unpack(string $packed): ?array
    {
        $raw = @gzuncompress($packed);
        if ($raw === false) {
            return null;
        }
        $rows = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($rows) ? $rows : null;
    }

    private static function localGet(string $key): ?array
    {
        if (! array_key_exists($key, self::$local)) {
            return null;
        }
        $entry = self::$local[$key];
        unset(self::$local[$key]);
        if ($entry['expires'] < now()->getTimestamp()) {
            return null;   // outlived the in-process TTL: fall through to the shared store
        }
        self::$local[$key] = $entry;   // move to the end (most recently used)

        return $entry['rows'];
    }

    private static function localPut(string $key, array $rows): void
    {
        unset(self::$local[$key]);
        self::$local[$key] = ['rows' => $rows, 'expires' => now()->getTimestamp() + self::LOCAL_TTL_SECONDS];
        if (count(self::$local) > self::LOCAL_LIMIT) {
            array_shift(self::$local);
        }
    }

    private static function cacheEnabled(): bool
    {
        return (bool) (config('desk.candles.cache_enabled') ?? env('DESK_CANDLE_CACHE', true));
    }

    /** Test isolation: $local survives an app rebuild between test methods, since it lives outside the
     *  container. Two tests proposing the same (product, timeframe, window) -- easy to do by accident with
     *  a shared fixture date -- must not see each other's candles just because they ran in one process. */
    public static function forgetLocal(): void
    {
        self::$local = [];
    }

    private static function cacheStore(): string
    {
        // The app's own default, not a hardcoded 'redis': tests set cache.default to 'array' precisely
        // so a backtest's candle fetch doesn't round-trip a real shared Redis (and, worse, collide there
        // with another test using the same product/timeframe/window -- keys carry no test identity).
        return config('desk.candles.cache_store') ?? config('cache.default') ?? 'redis';
    }

    private static function bump(string $counter): void
    {
        try {
            Redis::incr('candles:'.$counter);
        } catch (Throwable) {
            // metrics are best effort, never block on them
        }
    }
}
