import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import {
    bars1m, barsSub, lastAckedSeq,
    rollTrade, onTrades,
    seedCurrentBuckets, flush,
    setClients, resetState,
    FLUSH_BATCH_SIZE,
} from './feed.mjs';

// ---------------------------------------------------------------------------
// fake MySQL client
// ---------------------------------------------------------------------------
// Simulates just enough of `INSERT ... ON DUPLICATE KEY UPDATE` for our two fixed flush SQL
// shapes (writeComplete: straight replace; writePartial: GREATEST/LEAST highs+lows, volume via
// GREATEST against the absolute accumulated total — retry-idempotent, not additive — open never
// touched on conflict) plus a scriptable SELECT for seedCurrentBuckets.
function makeFakeDb() {
    const table = new Map(); // `${pid}|${tf}|${startMs}` -> {open,high,low,close,volume}
    const calls = [];
    let selectResult = [];
    let insertQueue = []; // optional per-call outcomes: 'ok' | 'fail'

    // Keys are always normalised to whole seconds, matching how tests refer to bucket starts —
    // whether the caller hands us a Date (as the real INSERT params do) or a raw epoch second.
    function rowKey(pid, tf, start) {
        const sec = start instanceof Date ? Math.floor(start.getTime() / 1000) : start;
        return `${pid}|${tf}|${sec}`;
    }

    async function query(sql, params = []) {
        calls.push({ sql, params });
        if (/^\s*SELECT/i.test(sql)) {
            return [selectResult];
        }

        const outcome = insertQueue.length ? insertQueue.shift() : 'ok';
        if (outcome === 'fail') throw new Error('simulated mysql failure');

        const isPartial = /volume\s*=\s*GREATEST\(volume/.test(sql);
        const cols = 8; // pid, tf, start, o, h, l, c, v (created_at/updated_at are UTC_TIMESTAMP(), unbound)
        for (let i = 0; i < params.length; i += cols) {
            const [pid, tf, start, o, h, l, c, v] = params.slice(i, i + cols);
            const key = rowKey(pid, tf, start);
            const existing = table.get(key);
            if (!existing) {
                table.set(key, { open: o, high: h, low: l, close: c, volume: v });
            } else if (isPartial) {
                existing.high = Math.max(existing.high, h);
                existing.low = Math.min(existing.low, l);
                existing.close = c;
                existing.volume = Math.max(existing.volume, v); // v is the absolute total, merged via GREATEST — a byte-identical retry must be a no-op
            } else {
                existing.open = o;
                existing.high = h;
                existing.low = l;
                existing.close = c;
                existing.volume = v;
            }
        }
        return [{ affectedRows: Math.floor(params.length / cols) }];
    }

    return {
        query,
        calls,
        get: (pid, tf, start) => table.get(rowKey(pid, tf, start)),
        // Populates the DB row WITHOUT making seedCurrentBuckets' SELECT return it — unlike
        // seedSelect, which stubs both. Real SELECTs restrict to the currently-forming bucket per
        // timeframe; a past bucket can very much already exist in the table while that query
        // legitimately returns nothing for it.
        primeTable: (row) => {
            table.set(rowKey(row.product_id, row.timeframe, row.candle_start), {
                open: row.open, high: row.high, low: row.low, close: row.close, volume: row.volume,
            });
        },
        seedSelect: (rows) => {
            selectResult = rows;
            // Keep the fake self-consistent: a row the SELECT returns is a row that already
            // "exists" for the later INSERT ... ON DUPLICATE KEY UPDATE to merge onto.
            for (const r of rows) {
                table.set(rowKey(r.product_id, r.timeframe, r.candle_start), {
                    open: r.open, high: r.high, low: r.low, close: r.close, volume: r.volume,
                });
            }
        },
        queueInsertOutcome: (...outcomes) => { insertQueue = outcomes; },
    };
}

function makeFakeRedis() {
    const pipe = {
        hset: () => pipe, expire: () => pipe, del: () => pipe,
        lpush: () => pipe, ltrim: () => pipe,
        exec: async () => [],
    };
    const counters = new Map();
    const incrCalls = [];
    return {
        pipeline: () => pipe,
        set: async () => 'OK',
        incr: async (key) => {
            incrCalls.push(key);
            const next = (counters.get(key) ?? 0) + 1;
            counters.set(key, next);
            return next;
        },
        incrCalls,
        counters,
    };
}

function trade({ pid = 'BTC-USD', time, price, size, tradeId }) {
    return { product_id: pid, time, price: String(price), size: String(size), trade_id: tradeId };
}

// evictStale() compares each bar's bucket-start against the real wall clock (by design — it's
// pruning stale in-memory bars, not something a restart needs to fake), so tests anchor "now" to
// the real current time rather than an arbitrary fixed epoch, which would look 600s+ stale to it.
const NOW = Math.floor(Date.now() / 1000);
const BUCKET_1M_START = NOW - (NOW % 60);
const ISO = (sec) => new Date(sec * 1000).toISOString();

let fakeDb;
let fakeRedis;

beforeEach(() => {
    resetState();
    fakeDb = makeFakeDb();
    fakeRedis = makeFakeRedis();
    setClients({ db: fakeDb, redis: fakeRedis });
});

test('mid-bucket restart merges onto the existing DB row instead of replacing it', async () => {
    // A previous run (or a still-running one) already has a partial candle for this bucket.
    fakeDb.seedSelect([
        {
            product_id: 'BTC-USD', timeframe: '1m', candle_start: new Date(BUCKET_1M_START * 1000),
            open: 100, high: 105, low: 95, close: 102, volume: 10,
        },
    ]);

    await seedCurrentBuckets(['BTC-USD'], NOW);

    const key = `BTC-USD|${BUCKET_1M_START}`;
    const seeded = bars1m.get(key);
    assert.ok(seeded, 'seeded bar should exist');
    assert.equal(seeded.coverage, 'partial');
    assert.equal(seeded.o, 100);
    assert.equal(seeded.dirty, false);

    // A trade arrives after the restart, inside the same bucket.
    rollTrade(trade({ time: ISO(NOW), price: 110, size: 2, tradeId: 't1' }));

    const bar = bars1m.get(key);
    assert.equal(bar.o, 100, 'open must be preserved, not replaced by the post-restart trade');
    assert.equal(bar.h, 110);
    assert.equal(bar.l, 95);
    assert.equal(bar.c, 110);
    assert.equal(bar.v, 12);
    assert.equal(bar.vDelta, 2, 'only the delta since seeding should be pending');

    await flush();

    const row = fakeDb.get('BTC-USD', '1m', BUCKET_1M_START);
    assert.equal(row.open, 100, 'DB open must stay the pre-restart value');
    assert.equal(row.high, 110);
    assert.equal(row.low, 95);
    assert.equal(row.volume, 12, 'DB volume = pre-restart volume + only the new trade');
    assert.equal(bars1m.get(key).dirty, false);
    assert.equal(bars1m.get(key).vDelta, 0);
});

test('a failed flush keeps the bar dirty in the map and retries on the next flush', async () => {
    rollTrade(trade({ time: ISO(NOW), price: 50, size: 1, tradeId: 'a' }));
    const key = `BTC-USD|${BUCKET_1M_START}`;
    assert.ok(bars1m.has(key));

    fakeDb.queueInsertOutcome('fail');
    await flush();

    assert.ok(bars1m.has(key), 'bar must not be evicted after a failed write');
    assert.equal(bars1m.get(key).dirty, true, 'bar must stay dirty so it is retried');
    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START), undefined, 'nothing should have landed in the DB');

    // Next flush succeeds and writes the full accumulated bar.
    await flush();
    assert.equal(bars1m.get(key).dirty, false);
    const row = fakeDb.get('BTC-USD', '1m', BUCKET_1M_START);
    assert.equal(row.open, 50);
    assert.equal(row.volume, 1);
});

test('duplicate trade ids do not double-count volume', () => {
    const key = `BTC-USD|${BUCKET_1M_START}`;

    rollTrade(trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 'dup-1' }));
    assert.equal(bars1m.get(key).v, 1);

    // Same trade id delivered again (e.g. replayed via a resubscribe snapshot).
    rollTrade(trade({ time: ISO(NOW), price: 999, size: 1, tradeId: 'dup-1' }));
    assert.equal(bars1m.get(key).v, 1, 'duplicate trade id must not add volume again');
    assert.equal(bars1m.get(key).h, 100, 'duplicate trade must not affect high/low/close either');

    rollTrade(trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 'dup-2' }));
    assert.equal(bars1m.get(key).v, 2, 'a genuinely new trade id still counts');
});

test('a resubscribe snapshot replay is deduped end to end through onTrades', () => {
    const key = `BTC-USD|${BUCKET_1M_START}`;

    // Live trade arrives normally.
    onTrades({ events: [{ type: 'update', trades: [trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 'x1' })] }] });
    assert.equal(bars1m.get(key).v, 1);

    // Reconnect: Coinbase resends recent history, including the trade we already applied plus
    // one genuinely new trade that happened during the gap.
    onTrades({
        events: [{
            type: 'snapshot',
            trades: [
                trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 'x1' }), // already applied
                trade({ time: ISO(NOW), price: 105, size: 3, tradeId: 'x2' }), // new — recovered
            ],
        }],
    });

    assert.equal(bars1m.get(key).v, 4, 'snapshot replay must add only the genuinely new trade');
    assert.equal(bars1m.get(key).h, 105);
});

test('a bar CREATED by a snapshot replay is partial, so it merges onto a real past bucket instead of replacing it', async () => {
    // Coinbase's resubscribe snapshot spans the last ~100 trades, which can reach well past the
    // *currently forming* bucket seedCurrentBuckets seeds. This is that gap: a fully-accounted-for
    // past bucket already sits in the DB, but seedCurrentBuckets' real query never asks about it
    // (only the forming bucket), so it is never loaded into memory before the replay runs.
    const pastStart = BUCKET_1M_START - 120;
    fakeDb.primeTable({
        product_id: 'BTC-USD', timeframe: '1m', candle_start: new Date(pastStart * 1000),
        open: 100, high: 150, low: 90, close: 140, volume: 1000,
    });

    await seedCurrentBuckets(['BTC-USD'], NOW);
    assert.ok(!bars1m.has(`BTC-USD|${pastStart}`), 'sanity: the past bucket must not be pre-seeded');

    // The replay's own trades, alone, would look like a plausible (but wildly wrong) "complete"
    // bar: open=120/high=121/low=120/vol=2 — exactly what used to authoritatively replace the row.
    onTrades({
        events: [{
            type: 'snapshot',
            trades: [
                trade({ time: ISO(pastStart + 10), price: 120, size: 1, tradeId: 'p1' }),
                trade({ time: ISO(pastStart + 20), price: 121, size: 1, tradeId: 'p2' }),
            ],
        }],
    });

    const bar = bars1m.get(`BTC-USD|${pastStart}`);
    assert.ok(bar, 'the snapshot must still create an in-memory bar for the past bucket');
    assert.equal(bar.coverage, 'partial', 'a bar CREATED purely by a snapshot replay must never be authoritative');

    await flush();

    const row = fakeDb.get('BTC-USD', '1m', pastStart);
    assert.equal(row.open, 100, 'the real open must survive — a snapshot-created bar never knows the true open');
    assert.equal(row.high, 150, 'high must not shrink to the narrow snapshot-only view');
    assert.equal(row.low, 90, 'low must not rise to the narrow snapshot-only view');
    assert.equal(row.volume, 1000, 'volume must not collapse to just the two replayed trades');
});

test('a snapshot replay only adding trades to an already-tracked bar leaves its coverage alone', async () => {
    const key = `BTC-USD|${BUCKET_1M_START}`;

    // A live trade creates the bar normally — complete, since its first tick IS the true open.
    onTrades({ events: [{ type: 'update', trades: [trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 'l1' })] }] });
    assert.equal(bars1m.get(key).coverage, 'complete');

    // A snapshot replay later adds a new trade to that SAME bucket — it must not downgrade a bar
    // it merely adds to, only a bar it creates.
    onTrades({
        events: [{
            type: 'snapshot',
            trades: [trade({ time: ISO(NOW), price: 105, size: 2, tradeId: 'l2' })],
        }],
    });

    assert.equal(bars1m.get(key).coverage, 'complete', 'a pre-existing bar keeps its coverage regardless of fromSnapshot');
    assert.equal(bars1m.get(key).v, 3);
});

test('a complete bucket is never overwritten by a partial one', async () => {
    // An existing DB row represents data already fully accounted for (e.g. from a continuous
    // prior run) — this is exactly the row a naive restart would clobber with a partial bar.
    fakeDb.seedSelect([
        {
            product_id: 'BTC-USD', timeframe: '1m', candle_start: new Date(BUCKET_1M_START * 1000),
            open: 100, high: 120, low: 90, close: 115, volume: 50,
        },
    ]);
    await seedCurrentBuckets(['BTC-USD'], NOW);

    // A trade arrives post-restart with a price inside the existing high/low range.
    rollTrade(trade({ time: ISO(NOW), price: 95, size: 5, tradeId: 'z1' }));

    await flush();

    const row = fakeDb.get('BTC-USD', '1m', BUCKET_1M_START);
    assert.equal(row.open, 100, 'the confirmed open must survive untouched');
    assert.equal(row.high, 120, 'high must not shrink to the partial view');
    assert.equal(row.low, 90, 'low must not rise to the partial view');
    assert.equal(row.volume, 55, 'volume adds the new trade on top of the confirmed total');

    // Structural guarantee: the partial UPDATE clause must never assign to `open` at all, so a
    // conflict can never stomp it regardless of values.
    const insertCall = fakeDb.calls.find(c => /volume\s*=\s*GREATEST\(volume/.test(c.sql));
    assert.ok(insertCall, 'expected the partial merge SQL to have run');
    assert.doesNotMatch(insertCall.sql, /open\s*=\s*VALUES\(open\)/i);
});

// ---------------------------------------------------------------------------
// rec 12: batching, ordering, staleness guard, and Redis version bump
// ---------------------------------------------------------------------------

function completeInsertCalls() {
    // The "complete" (straight-replace) SQL shape, as opposed to the "partial" merge shape.
    return fakeDb.calls.filter(c => /open=VALUES\(open\)/.test(c.sql) && !/volume\s*=\s*GREATEST\(volume/.test(c.sql));
}

function rowsFromParams(params, cols = 8) {
    const rows = [];
    for (let i = 0; i < params.length; i += cols) rows.push(params.slice(i, i + cols));
    return rows;
}

function putBar(map, { pid, tf, start, o = 100, h = 100, l = 100, c = 100, v = 1, seq = 1 }) {
    const key = tf === '1m' ? `${pid}|${start}` : `${tf}|${pid}|${start}`;
    map.set(key, { pid, tf, start, o, h, l, c, v, vDelta: v, dirty: true, coverage: 'complete', tradeIds: new Set(), seq });
    return key;
}

test('a flush writes the dirty set sorted by natural key (product_id, timeframe, candle_start)', async () => {
    // Inserted deliberately out of order.
    putBar(bars1m, { pid: 'ZED-USD', tf: '1m', start: BUCKET_1M_START });
    putBar(bars1m, { pid: 'MID-USD', tf: '1m', start: BUCKET_1M_START });
    putBar(bars1m, { pid: 'AAA-USD', tf: '1m', start: BUCKET_1M_START });
    putBar(bars1m, { pid: 'AAA-USD', tf: '1m', start: BUCKET_1M_START - 60 });

    await flush();

    const calls = completeInsertCalls();
    assert.equal(calls.length, 1);
    const rows = rowsFromParams(calls[0].params);
    const order = rows.map(r => `${r[0]}@${r[2].getTime() / 1000}`);
    assert.deepEqual(order, [
        `AAA-USD@${BUCKET_1M_START - 60}`,
        `AAA-USD@${BUCKET_1M_START}`,
        `MID-USD@${BUCKET_1M_START}`,
        `ZED-USD@${BUCKET_1M_START}`,
    ]);
});

test('a flush bounds each statement to FLUSH_BATCH_SIZE rows and loops for the rest', async () => {
    const total = FLUSH_BATCH_SIZE + 1;
    for (let i = 0; i < total; i++) {
        putBar(bars1m, { pid: `P-${String(i).padStart(4, '0')}`, tf: '1m', start: BUCKET_1M_START });
    }

    await flush();

    const calls = completeInsertCalls();
    assert.equal(calls.length, 2, 'expected exactly two INSERT statements for one row over the limit');
    const sizes = calls.map(c => rowsFromParams(c.params).length).sort((a, b) => b - a);
    assert.deepEqual(sizes, [FLUSH_BATCH_SIZE, 1]);
});

test('an older, superseded batch never overwrites a newer bar\'s close/absolute fields', async () => {
    const key = `BTC-USD|${BUCKET_1M_START}`;

    rollTrade(trade({ time: ISO(NOW), price: 100, size: 1, tradeId: 't1' })); // seq -> 1
    await flush();
    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START).close, 100);

    rollTrade(trade({ time: ISO(NOW), price: 200, size: 2, tradeId: 't2' })); // seq -> 2
    await flush();
    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START).close, 200);
    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START).volume, 3);
    assert.equal(completeInsertCalls().length, 2);

    // Simulate a delayed/retried batch surfacing with a stale seq and stale absolute values —
    // exactly the shape of an older write racing in after a fresher one already landed.
    const bar = bars1m.get(key);
    bar.seq = 1; // this process already durably wrote seq 2 for this bucket
    bar.c = 999;
    bar.dirty = true;

    await flush();

    const row = fakeDb.get('BTC-USD', '1m', BUCKET_1M_START);
    assert.equal(row.close, 200, 'the stale batch must not overwrite the newer close');
    assert.equal(row.volume, 3, 'nor any other absolute field');
    assert.equal(completeInsertCalls().length, 2, 'the superseded batch must not even issue a write');
    assert.equal(bars1m.get(key).dirty, false, 'stale data is dropped, not retried forever');
});

test('a successful flush INCRs candles:ver:{product}:{timeframe} once per pair, even with multiple buckets', async () => {
    putBar(bars1m, { pid: 'BTC-USD', tf: '1m', start: BUCKET_1M_START });
    putBar(bars1m, { pid: 'BTC-USD', tf: '1m', start: BUCKET_1M_START - 60 }); // same pid/tf, different bucket
    putBar(barsSub, { pid: 'ETH-USD', tf: '13s', start: BUCKET_1M_START });

    await flush();

    const btcCalls = fakeRedis.incrCalls.filter(k => k === 'candles:ver:BTC-USD:1m');
    assert.equal(btcCalls.length, 1, 'two dirty buckets for the same pid/tf must still INCR only once');
    assert.equal(fakeRedis.counters.get('candles:ver:BTC-USD:1m'), 1);
    assert.equal(fakeRedis.counters.get('candles:ver:ETH-USD:13s'), 1);
});

test('a flush succeeds and writes to MySQL even with no usable Redis client', async () => {
    setClients({ redis: null });
    putBar(bars1m, { pid: 'BTC-USD', tf: '1m', start: BUCKET_1M_START, c: 123, v: 4 });

    await assert.doesNotReject(flush());

    const row = fakeDb.get('BTC-USD', '1m', BUCKET_1M_START);
    assert.equal(row.close, 123, 'the MySQL write must still happen without a Redis client');
});

// ---------------------------------------------------------------------------
// rec: idempotent volume, UTC timestamps, and lastAckedSeq pruning
// ---------------------------------------------------------------------------

test('a partial write is retry-idempotent: replaying the exact same INSERT does not double-count volume', async () => {
    fakeDb.seedSelect([
        { product_id: 'BTC-USD', timeframe: '1m', candle_start: new Date(BUCKET_1M_START * 1000), open: 100, high: 100, low: 100, close: 100, volume: 10 },
    ]);
    await seedCurrentBuckets(['BTC-USD'], NOW);
    rollTrade(trade({ time: ISO(NOW), price: 105, size: 5, tradeId: 'r1' }));

    await flush();
    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START).volume, 15);

    // Simulate the underlying INSERT having actually landed, but its acknowledgement getting lost
    // over the wire — the driver/caller retries the byte-identical statement.
    const lastPartialCall = [...fakeDb.calls].reverse().find(c => /volume\s*=\s*GREATEST\(volume/.test(c.sql));
    assert.ok(lastPartialCall, 'expected a GREATEST-based partial merge to have run');
    await fakeDb.query(lastPartialCall.sql, lastPartialCall.params);

    assert.equal(fakeDb.get('BTC-USD', '1m', BUCKET_1M_START).volume, 15, 'replaying the same write must not double-count volume');
});

test('every flush SQL statement stamps timestamps with UTC_TIMESTAMP(), never NOW()', async () => {
    // A complete (straight-replace) write...
    putBar(bars1m, { pid: 'BTC-USD', tf: '1m', start: BUCKET_1M_START });
    // ...and a partial (merge) write, in the same flush.
    fakeDb.seedSelect([
        { product_id: 'ETH-USD', timeframe: '1m', candle_start: new Date((BUCKET_1M_START - 60) * 1000), open: 1, high: 1, low: 1, close: 1, volume: 1 },
    ]);
    await seedCurrentBuckets(['ETH-USD'], NOW - 60);
    rollTrade(trade({ pid: 'ETH-USD', time: ISO(NOW - 60), price: 2, size: 1, tradeId: 'u1' }));

    await flush();

    const insertCalls = fakeDb.calls.filter(c => /^\s*INSERT/i.test(c.sql));
    assert.ok(insertCalls.length >= 2, 'expected both a complete and a partial INSERT this flush');
    for (const call of insertCalls) {
        assert.match(call.sql, /UTC_TIMESTAMP\(\)/, 'every timestamp-stamping statement must use UTC_TIMESTAMP()');
        assert.doesNotMatch(call.sql, /\bNOW\(\)/, 'NOW() resolves to the MariaDB session/SYSTEM timezone (CDT here), not UTC — must never appear');
    }
});

test('lastAckedSeq is pruned when its bar is evicted, but retained for a bar still in memory', async () => {
    const staleStart = BUCKET_1M_START - 700; // already past the 600s eviction window at creation
    putBar(bars1m, { pid: 'STALE-USD', tf: '1m', start: staleStart, seq: 1 });
    putBar(bars1m, { pid: 'FRESH-USD', tf: '1m', start: BUCKET_1M_START, seq: 1 });

    await flush();

    assert.ok(!bars1m.has(`STALE-USD|${staleStart}`), 'the stale, clean bar must be evicted');
    assert.ok(bars1m.has(`FRESH-USD|${BUCKET_1M_START}`), 'a recent bar must stay in memory');
    assert.ok(!lastAckedSeq.has(`STALE-USD|1m|${staleStart}`), 'lastAckedSeq must be pruned along with its evicted bar');
    assert.ok(lastAckedSeq.has(`FRESH-USD|1m|${BUCKET_1M_START}`), 'lastAckedSeq stays for a bar that remains in memory');
});
