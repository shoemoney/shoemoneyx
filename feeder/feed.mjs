#!/usr/bin/env node
/**
 * shoemoneyx websocket feeder — keeps Redis and MySQL in sync with Coinbase in real time.
 *
 *   wss://advanced-trade-ws.coinbase.com  (public, no key)
 *     ticker         -> Redis hash  ws:price:{PID}   {price,bid,ask,vol24,chg24,ts}   (EX 60)
 *     market_trades  -> Redis list  ws:tape:{PID}    newest-first JSON trades, capped at 600
 *                    -> MySQL candles timeframe '1m' rolled from trades
 *     candles (5m)   -> MySQL candles timeframe '5m' as Coinbase publishes them
 *     heartbeats     -> Redis key   ws:alive = unix seconds  (the PHP side treats >60s as down)
 *
 * The product list comes from MySQL (`products.is_tracked` + open positions) and is
 * re-read every 60s; subscriptions follow it. Run: node feeder/feed.mjs  (or bin/desk feed)
 *
 * Candle coverage: trade-built bars (1m + sub-minute) accumulate in memory and only flush
 * every CANDLE_FLUSH_MS. A restart starts that memory empty, so *before* we ever subscribe (see
 * connect()) we seed the *currently forming* bucket for each product/timeframe from whatever
 * MySQL already has — awaited ahead of subscribing so a racing live trade can never create the
 * bucket first and pre-empt the seed. A seeded bar is `coverage: 'partial'` — we don't know its
 * true open, so it is merged into the DB row (GREATEST/LEAST highs/lows, open left untouched,
 * volume written as our absolute accumulated total via GREATEST — see writePartialBatch) rather
 * than overwriting it outright. A bar built entirely from trades this process has seen since its
 * first tick is `coverage: 'complete'` and is written as an authoritative replace, same as before.
 * Per-bucket trade-id dedup means a resubscribe snapshot (which resends recent trade history) can
 * be safely replayed into the accumulator to repair a reconnect gap without double-counting
 * anything already applied — but a snapshot can span buckets well in the past that were never
 * seeded (seedCurrentBuckets only loads the *forming* bucket), so any bar CREATED by a snapshot
 * replay is forced to `coverage: 'partial'` too (a bar the replay merely adds trades to keeps
 * whatever coverage it already had): otherwise a handful of replayed trades would fabricate a
 * narrow "complete" bar that then authoritatively replaces the real, fully-accounted-for row.
 *
 * Flush batching: each flush works from an immutable snapshot of the dirty bars (values captured
 * before the `await`, so a trade landing mid-write can't be silently folded into or lost from that
 * write), sorted by natural key (product_id, timeframe, candle_start) so concurrent writers touch
 * rows in the same order, and written in statements bounded to FLUSH_BATCH_SIZE rows, looping until
 * the snapshot is exhausted. Every bar carries a local monotonic `seq`, bumped on every trade it
 * absorbs; `lastAckedSeq` remembers the highest seq this process has durably written per bucket
 * (pruned when the bar itself is evicted from memory, so it can't grow unbounded), so a stale/
 * superseded batch (e.g. a slow retry racing a fresher successful write of the same bucket) is
 * skipped rather than overwriting `close` or any other absolute field with older data. Every write
 * — complete or partial — carries the absolute accumulated OHLCV for the bar, never a delta, so a
 * write whose acknowledgement is lost and gets retried is a no-op rather than double-counting.
 * After each batch actually lands, `candles:ver:{product}:{timeframe}` is INCRed once per flush per
 * product/timeframe so PHP's CandleStore can cheaply tell its cache is stale without polling MySQL.
 */
import 'dotenv/config';
import WebSocket from 'ws';
import Redis from 'ioredis';
import mysql from 'mysql2/promise';
import { realpathSync } from 'fs';
import { pathToFileURL } from 'url';

const env = (k, d = '') => (process.env[k] ?? d);
const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const PREFIX = env('REDIS_PREFIX', slug(env('APP_NAME', 'laravel')) + '-database-');
const WS_URL = env('COINBASE_WS_URL', 'wss://advanced-trade-ws.coinbase.com');
const CANDLE_FLUSH_MS = 4000;
const PRODUCT_REFRESH_MS = 60000;
const TAPE_MAX = 600;
const TRADE_ID_CAP = 4000; // bounded per-bucket dedup set
const FLUSH_BATCH_SIZE = 500; // rows per INSERT statement

// db/redis are created for real in the isMain block below; tests inject fakes via setClients().
let redis = null;
let db = null;

const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);

// ---------------------------------------------------------------------------
// state
// ---------------------------------------------------------------------------
let products = [];                 // current subscription set
let ws = null;
let alive = false;
let reconnectDelay = 1000;
const bars1m = new Map();          // `${pid}|${start}` -> {pid,tf,start,o,h,l,c,v,vDelta,dirty,coverage,tradeIds}
const bars5m = new Map();
const SUB = { '13s': 13, '15s': 15, '20s': 20, '25s': 25, '30s': 30, '33s': 33, '41s': 41, '45s': 45, '49s': 49 };      // sub-minute bars rolled from the tape
const barsSub = new Map();         // `${tf}|${pid}|${start}` -> bar
let stats = { ticks: 0, trades: 0, candles: 0 };

function resetState() {
    bars1m.clear();
    bars5m.clear();
    barsSub.clear();
    lastAckedSeq.clear();
    stats = { ticks: 0, trades: 0, candles: 0 };
}

function setClients({ db: dbClient, redis: redisClient } = {}) {
    if (dbClient !== undefined) db = dbClient;
    if (redisClient !== undefined) redis = redisClient;
}

// ---------------------------------------------------------------------------
// products
// ---------------------------------------------------------------------------
async function loadProducts() {
    const [rows] = await db.query(
        `SELECT product_id FROM products WHERE is_tracked = 1
         UNION SELECT product_id FROM positions WHERE status = 'open'`
    );
    return rows.map(r => r.product_id).sort();
}

async function refreshProducts() {
    try {
        const next = await loadProducts();
        const added = next.filter(p => !products.includes(p));
        const removed = products.filter(p => !next.includes(p));
        if (added.length || removed.length) {
            log(`products: ${next.length} (+${added.length} -${removed.length})`);
            if (ws && ws.readyState === WebSocket.OPEN) {
                if (removed.length) send('unsubscribe', removed);
                if (added.length) send('subscribe', added);
            }
            products = next;
        }
    } catch (e) {
        log('product refresh failed:', e.message);
    }
}

// ---------------------------------------------------------------------------
// websocket
// ---------------------------------------------------------------------------
function send(type, ids) {
    for (const channel of ['ticker', 'market_trades', 'candles']) {
        // Coinbase accepts many product_ids per message; chunk to stay under frame limits.
        for (let i = 0; i < ids.length; i += 50) {
            ws.send(JSON.stringify({ type, channel, product_ids: ids.slice(i, i + 50) }));
        }
    }
    if (type === 'subscribe') ws.send(JSON.stringify({ type, channel: 'heartbeats' }));
}

function connect() {
    log('connecting', WS_URL);
    ws = new WebSocket(WS_URL);

    ws.on('open', async () => {
        reconnectDelay = 1000;
        alive = true;
        // A restart starts with an empty map; a mere reconnect may still be missing whatever
        // rolled off the map or never made it in. Either way, only fill gaps — never touch a
        // bucket we're already tracking in memory. Seeding is awaited BEFORE we subscribe so a
        // live trade racing in right after subscribe can never create the bucket first (as
        // `coverage: 'complete'`) and pre-empt the seed that was supposed to mark it partial.
        try {
            await seedCurrentBuckets(products);
        } catch (e) {
            log('seed buckets failed:', e.message);
        }
        log('open; subscribing', products.length, 'products');
        if (products.length) send('subscribe', products);
        redis.set('ws:alive', Math.floor(Date.now() / 1000), 'EX', 120).catch(() => {});
    });

    ws.on('message', (buf) => {
        let msg;
        try { msg = JSON.parse(buf.toString()); } catch { return; }
        if (msg.type === 'error') { log('ws error:', msg.message); return; }
        switch (msg.channel) {
            case 'ticker': return onTicker(msg);
            case 'market_trades': return onTrades(msg);
            case 'candles': return onCandles(msg);
            case 'heartbeats': return redis.set('ws:alive', Math.floor(Date.now() / 1000), 'EX', 120).catch(() => {});
            case 'subscriptions': return;
        }
    });

    ws.on('close', (code) => {
        alive = false;
        log('closed', code, `— reconnecting in ${reconnectDelay}ms`);
        setTimeout(connect, reconnectDelay);
        reconnectDelay = Math.min(reconnectDelay * 2, 30000);
    });
    ws.on('error', (e) => log('socket error:', e.message));
}

// ---------------------------------------------------------------------------
// handlers
// ---------------------------------------------------------------------------
function onTicker(msg) {
    const pipe = redis.pipeline();
    for (const ev of msg.events || []) {
        for (const t of ev.tickers || []) {
            stats.ticks++;
            const key = `ws:price:${t.product_id}`;
            pipe.hset(key, {
                price: t.price, bid: t.best_bid ?? '', ask: t.best_ask ?? '',
                vol24: t.volume_24_h ?? '', chg24: t.price_percent_chg_24_h ?? '',
                ts: Math.floor(Date.now() / 1000),
            });
            pipe.expire(key, 60);
        }
    }
    pipe.exec().catch(e => log('redis ticker:', e.message));
}

function onTrades(msg) {
    const pipe = redis.pipeline();
    for (const ev of msg.events || []) {
        if (ev.type === 'snapshot') {
            // Coinbase sends the last ~100 trades on subscribe — seed the tape once, oldest first.
            const byPid = new Map();
            for (const tr of ev.trades || []) (byPid.get(tr.product_id) ?? byPid.set(tr.product_id, []).get(tr.product_id)).push(tr);
            for (const [pid, list] of byPid) {
                list.sort((a, b) => new Date(a.time) - new Date(b.time));
                pipe.del(`ws:tape:${pid}`);
                for (const tr of list) pipe.lpush(`ws:tape:${pid}`, tapeRow(tr));
                pipe.ltrim(`ws:tape:${pid}`, 0, TAPE_MAX - 1);
                pipe.expire(`ws:tape:${pid}`, 7200);
                // Authoritative recent history from Coinbase — safe to replay into the candle
                // accumulator because rollTrade dedups by trade id per bucket, so trades already
                // applied via the live feed before a reconnect gap don't get double-counted, while
                // trades that happened *during* the gap get recovered instead of silently lost.
                // fromSnapshot=true: a snapshot can span buckets well in the past that
                // seedCurrentBuckets never loaded, so any bar this replay CREATES must be treated
                // as partial (unknown true open/volume), not authoritative — see rollInto.
                for (const tr of list) rollTrade(tr, true);
            }
            continue;
        }
        for (const tr of ev.trades || []) {
            stats.trades++;
            pipe.lpush(`ws:tape:${tr.product_id}`, tapeRow(tr));
            pipe.ltrim(`ws:tape:${tr.product_id}`, 0, TAPE_MAX - 1);
            pipe.expire(`ws:tape:${tr.product_id}`, 7200);
            rollTrade(tr);
        }
    }
    pipe.exec().catch(e => log('redis tape:', e.message));
}

function tapeRow(tr) {
    return JSON.stringify({ t: Math.floor(new Date(tr.time).getTime() / 1000), p: Number(tr.price), s: Number(tr.size), side: tr.side });
}

function rememberTrade(tradeIds, tradeId) {
    if (tradeId == null) return true; // nothing to dedup on — always apply
    if (tradeIds.has(tradeId)) return false;
    if (tradeIds.size >= TRADE_ID_CAP) tradeIds.delete(tradeIds.values().next().value); // drop oldest
    tradeIds.add(tradeId);
    return true;
}

function rollInto(map, key, pid, tf, start, p, s, tradeId, fromSnapshot = false) {
    let b = map.get(key);
    if (!b) {
        // Never seeded from the DB — but if this bar is being CREATED by a snapshot replay rather
        // than the live feed, we still don't actually know its true open/full volume: the replay
        // only carries whatever trades Coinbase happened to include, not necessarily every trade
        // in the bucket. Treat it exactly like a DB-seeded bar (partial, merge-only) so it can
        // never authoritatively replace a real row. A bar whose first trade arrives live IS the
        // bucket's true open and stays `complete`.
        b = { pid, tf, start, o: p, h: p, l: p, c: p, v: 0, vDelta: 0, dirty: false, coverage: fromSnapshot ? 'partial' : 'complete', tradeIds: new Set(), seq: 0 };
        map.set(key, b);
    }
    // else: bar already exists (live-tracked or DB-seeded) — its coverage is left exactly as is,
    // regardless of fromSnapshot; only a bar's creation, never a later merge, sets coverage.
    if (!rememberTrade(b.tradeIds, tradeId)) return; // duplicate trade id for this bucket — skip
    b.h = Math.max(b.h, p);
    b.l = Math.min(b.l, p);
    b.c = p;
    b.v += s;
    b.vDelta += s;
    b.seq += 1; // local monotonic revision — lets a flush detect "this bar moved since I read it"
    b.dirty = true;
}

function rollTrade(tr, fromSnapshot = false) {
    const ts = Math.floor(new Date(tr.time).getTime() / 1000);
    const p = Number(tr.price), s = Number(tr.size);
    const tradeId = tr.trade_id ?? null;
    rollInto(bars1m, `${tr.product_id}|${ts - (ts % 60)}`, tr.product_id, '1m', ts - (ts % 60), p, s, tradeId, fromSnapshot);
    for (const [tf, dur] of Object.entries(SUB)) {
        const start = ts - (ts % dur);
        rollInto(barsSub, `${tf}|${tr.product_id}|${start}`, tr.product_id, tf, start, p, s, tradeId, fromSnapshot);
    }
}

function onCandles(msg) {
    for (const ev of msg.events || []) {
        for (const c of ev.candles || []) {
            stats.candles++;
            const start = Number(c.start);
            // Coinbase's own 5m candle channel always sends the full, authoritative bar — a
            // straight replace is correct here, unlike the trade-built timeframes above.
            const prev = bars5m.get(`${c.product_id}|${start}`);
            bars5m.set(`${c.product_id}|${start}`, {
                pid: c.product_id, tf: '5m', start,
                o: Number(c.open), h: Number(c.high), l: Number(c.low), c: Number(c.close), v: Number(c.volume),
                dirty: true, coverage: 'complete', seq: (prev?.seq ?? 0) + 1,
            });
        }
    }
}

// ---------------------------------------------------------------------------
// restart / reconnect recovery
// ---------------------------------------------------------------------------
// Seed the in-memory accumulator for the *currently forming* bucket of every product/timeframe
// from whatever MySQL already has, so the first flush after a restart merges onto it instead of
// replacing it with only the trades seen since restart. Only fills gaps: a bucket already
// tracked in memory (e.g. a plain reconnect, not a process restart) is left alone.
async function seedCurrentBuckets(productList, now = Math.floor(Date.now() / 1000)) {
    if (!db || !productList || !productList.length) return;

    const timeframes = [['1m', 60], ...Object.entries(SUB)];
    const conditions = [];
    const condParams = [];
    for (const [tf, dur] of timeframes) {
        const start = now - (now % dur);
        conditions.push('(timeframe = ? AND candle_start = ?)');
        condParams.push(tf, new Date(start * 1000));
    }
    const placeholders = productList.map(() => '?').join(',');
    const sql = `SELECT product_id, timeframe, candle_start, open, high, low, close, volume
                 FROM candles
                 WHERE product_id IN (${placeholders}) AND (${conditions.join(' OR ')})`;

    let rows;
    try {
        [rows] = await db.query(sql, [...productList, ...condParams]);
    } catch (e) {
        log('seed buckets query failed:', e.message);
        return;
    }

    for (const r of rows || []) {
        const map = r.timeframe === '1m' ? bars1m : barsSub;
        const start = Math.floor(new Date(r.candle_start).getTime() / 1000);
        const key = r.timeframe === '1m' ? `${r.product_id}|${start}` : `${r.timeframe}|${r.product_id}|${start}`;
        if (map.has(key)) continue; // already tracked live in memory — never downgrade it
        map.set(key, {
            pid: r.product_id, tf: r.timeframe, start,
            o: Number(r.open), h: Number(r.high), l: Number(r.low), c: Number(r.close), v: Number(r.volume),
            vDelta: 0, dirty: false, coverage: 'partial', tradeIds: new Set(), seq: 0,
        });
    }
}

// ---------------------------------------------------------------------------
// MySQL flush
// ---------------------------------------------------------------------------
// Flushes are serialised — a chain of promises rather than fire-and-forget — so an interval tick
// that fires while the previous flush is still awaiting MySQL never runs concurrently with it and
// scrambles the same bar objects.
let flushChain = Promise.resolve();

// Highest `seq` this process has durably written per bucket (key: `${pid}|${tf}|${start}`).
// In-memory only — it protects against a stale batch from *this* process (e.g. a slow retry that
// got superseded by a fresher successful write of the same bucket) landing after a newer one, not
// against another process entirely. Cleared by resetState() for tests.
const lastAckedSeq = new Map();

function barKey(b) {
    return `${b.pid}|${b.tf}|${b.start}`;
}

function flush() {
    flushChain = flushChain.then(doFlush, (e) => log('flush chain error:', e?.message));
    return flushChain;
}

async function doFlush() {
    const dirty = [];
    for (const map of [bars1m, bars5m, barsSub]) {
        for (const b of map.values()) {
            if (b.dirty) dirty.push(b);
        }
    }

    const writtenPairs = new Set(); // `${pid}:${tf}` actually persisted this flush
    if (dirty.length) {
        // Immutable snapshot: capture the values we're about to write right now, synchronously,
        // before any `await` — a trade landing on these bars while MySQL is in flight mutates the
        // *bar*, not this list, so it can neither be silently folded into this write nor lost from
        // the next one (see the seq bookkeeping in each write*Batch below).
        const snapshot = dirty.map(b => ({
            b, key: barKey(b), pid: b.pid, tf: b.tf, start: b.start,
            o: b.o, h: b.h, l: b.l, c: b.c, v: b.v, vDelta: b.vDelta,
            coverage: b.coverage, seq: b.seq,
        }));
        // Natural-key order so concurrent writers touch the same rows in the same sequence.
        snapshot.sort((x, y) => x.pid.localeCompare(y.pid) || x.tf.localeCompare(y.tf) || x.start - y.start);

        const complete = snapshot.filter(e => e.coverage !== 'partial');
        const partial = snapshot.filter(e => e.coverage === 'partial');
        for (let i = 0; i < complete.length; i += FLUSH_BATCH_SIZE) {
            for (const p of await writeCompleteBatch(complete.slice(i, i + FLUSH_BATCH_SIZE))) writtenPairs.add(p);
        }
        for (let i = 0; i < partial.length; i += FLUSH_BATCH_SIZE) {
            for (const p of await writePartialBatch(partial.slice(i, i + FLUSH_BATCH_SIZE))) writtenPairs.add(p);
        }
    }

    if (writtenPairs.size) await bumpVersions(writtenPairs);

    // Only now — after any write has been acknowledged (or failed and left dirty) — is it safe to
    // drop old bars from memory. A bar left dirty by a failed write is never evicted, so it's
    // retried on the next flush instead of vanishing with no way back in. lastAckedSeq is keyed
    // independently of these maps (see barKey), so an evicted bar's entry must be pruned here too —
    // otherwise it sits forever, growing unbounded across the process's whole runtime.
    const now = Math.floor(Date.now() / 1000);
    for (const map of [bars1m, bars5m, barsSub]) {
        for (const [key, b] of map) {
            if (now - b.start > 600 && !b.dirty) {
                map.delete(key);
                lastAckedSeq.delete(barKey(b));
            }
        }
    }
}

// Skip entries this process has already durably written a newer (or equal) revision of — an older
// batch must never overwrite a newer bar's `close` or other absolute fields. Anything skipped that
// hasn't moved on again since its snapshot was taken is just clean, stale data — mark it clean
// rather than retrying it forever.
function dropSuperseded(entries) {
    const keep = [];
    for (const e of entries) {
        const acked = lastAckedSeq.get(e.key);
        if (acked !== undefined && e.seq <= acked) {
            if (e.b.seq === e.seq) e.b.dirty = false;
            continue;
        }
        keep.push(e);
    }
    return keep;
}

// After a write succeeds: record the durable high-water seq, and only clear `dirty` (and consume
// the flushed volume delta) if the bar hasn't moved on since the snapshot was taken — otherwise a
// trade that landed mid-write would be silently marked "already flushed" when it wasn't.
function ackWritten(entries, { consumeVDelta }) {
    const pairs = new Set();
    for (const e of entries) {
        lastAckedSeq.set(e.key, Math.max(lastAckedSeq.get(e.key) ?? -1, e.seq));
        pairs.add(`${e.pid}:${e.tf}`);
        if (consumeVDelta) {
            e.b.vDelta -= e.vDelta;
            if (e.b.vDelta < 0) e.b.vDelta = 0; // defensive; shouldn't go negative
        }
        if (e.b.seq === e.seq) e.b.dirty = false;
        // else: more trades landed during the await — left dirty, retried with fresh values next flush
    }
    return pairs;
}

// Bars built entirely from trades seen since their first tick: authoritative, straight replace.
async function writeCompleteBatch(entries) {
    const toWrite = dropSuperseded(entries);
    if (!toWrite.length) return [];

    const values = toWrite.map(e => [e.pid, e.tf, new Date(e.start * 1000), e.o, e.h, e.l, e.c, e.v]);
    try {
        await db.query(
            `INSERT INTO candles (product_id, timeframe, candle_start, open, high, low, close, volume, created_at, updated_at)
             VALUES ${values.map(() => '(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())').join(',')}
             ON DUPLICATE KEY UPDATE open=VALUES(open), high=VALUES(high), low=VALUES(low), close=VALUES(close), volume=VALUES(volume), updated_at=UTC_TIMESTAMP()`,
            values.flat()
        );
        return [...ackWritten(toWrite, { consumeVDelta: true })];
    } catch (e) {
        log('mysql flush failed (complete):', e.message);
        for (const entry of toWrite) entry.b.dirty = true; // stays in its map; retried next flush
        return [];
    }
}

// Bars seeded from an existing DB row (unknown true open), or created fresh by a snapshot replay
// (unknown true volume): merge, never replace. High/low widen via GREATEST/LEAST; volume writes
// this process's absolute accumulated total (seeded volume + every deduped trade applied since)
// via GREATEST rather than an additive `volume + VALUES(volume)` — additive isn't retry-safe: if
// the write lands but its acknowledgement is lost (so the caller retries the identical statement),
// additive double-counts the same volume, while GREATEST makes a byte-identical retry a no-op.
// open is left out of the UPDATE clause entirely so a conflict never touches whatever's already
// there — insert-only (no prior row) is the sole path where our own open value is used.
async function writePartialBatch(entries) {
    const toWrite = dropSuperseded(entries);
    if (!toWrite.length) return [];

    const values = toWrite.map(e => [e.pid, e.tf, new Date(e.start * 1000), e.o, e.h, e.l, e.c, e.v]);
    try {
        await db.query(
            `INSERT INTO candles (product_id, timeframe, candle_start, open, high, low, close, volume, created_at, updated_at)
             VALUES ${values.map(() => '(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())').join(',')}
             ON DUPLICATE KEY UPDATE
                high = GREATEST(high, VALUES(high)),
                low = LEAST(low, VALUES(low)),
                close = VALUES(close),
                volume = GREATEST(volume, VALUES(volume)),
                updated_at = UTC_TIMESTAMP()`,
            values.flat()
        );
        return [...ackWritten(toWrite, { consumeVDelta: true })];
    } catch (e) {
        log('mysql flush failed (partial):', e.message);
        for (const entry of toWrite) entry.b.dirty = true; // stays in its map; retried next flush
        return [];
    }
}

// One INCR per product/timeframe actually written this flush, so PHP's CandleStore can detect a
// stale cache cheaply. Best-effort: a Redis hiccup here must never fail (or retry) the flush.
async function bumpVersions(pairs) {
    if (!redis || typeof redis.incr !== 'function') {
        log('candle version bump skipped: no usable redis client');
        return;
    }
    for (const pair of pairs) {
        const [pid, tf] = pair.split(':');
        try {
            await redis.incr(`candles:ver:${pid}:${tf}`);
        } catch (e) {
            log('candle version incr failed:', pair, e.message);
        }
    }
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
// A plain `file://${argv[1]}` string compare breaks the moment argv[1] is a relative path, a
// symlink (e.g. a `bin/desk feed` launcher), or contains characters that need URL-encoding —
// import.meta.url is always a fully resolved, encoded file:// URL, so the comparison must go
// through the same normalization: resolve symlinks with realpathSync, then encode with
// pathToFileURL. A missing/unreadable argv[1] just means "not run directly", not a crash.
function computeIsMain() {
    // pm2 fork mode runs us through ProcessContainerFork.js, so argv[1] is never this file; pm2 always
    // sets pm_id in the child env. FEEDER_MAIN=1 is the explicit override for any other supervisor.
    if (process.env.FEEDER_MAIN === '1' || process.env.pm_id !== undefined) return true;
    if (!process.argv[1]) return false;
    try {
        return import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href;
    } catch {
        return false;
    }
}
const isMain = computeIsMain();

if (isMain) {
    redis = new Redis({
        host: env('REDIS_HOST', '127.0.0.1'),
        port: Number(env('REDIS_PORT', 6379)),
        password: env('REDIS_PASSWORD') || undefined,
        username: env('REDIS_USERNAME') || undefined,
        db: Number(env('REDIS_DB', 0)),
        keyPrefix: PREFIX,
        lazyConnect: false,
    });

    db = await mysql.createPool({
        host: env('DB_HOST', '127.0.0.1'),
        port: Number(env('DB_PORT', 3306)),
        user: env('DB_USERNAME', 'root'),
        password: env('DB_PASSWORD', ''),
        database: env('DB_DATABASE', 'shoemoneyx'),
        waitForConnections: true,
        connectionLimit: 3,
        timezone: 'Z',
    });

    products = await loadProducts();
    log(`redis prefix "${PREFIX}", ${products.length} products`);
    connect();
    setInterval(refreshProducts, PRODUCT_REFRESH_MS);
    setInterval(flush, CANDLE_FLUSH_MS);
    setInterval(() => {
        log(`alive=${alive} ticks=${stats.ticks} trades=${stats.trades} candles=${stats.candles} bars1m=${bars1m.size} sub=${barsSub.size}`);
        stats = { ticks: 0, trades: 0, candles: 0 };
    }, 60000);

    for (const sig of ['SIGINT', 'SIGTERM']) {
        process.on(sig, async () => { log('stopping'); try { await flush(); } catch {} ws?.close(); await redis.quit(); await db.end(); process.exit(0); });
    }
} else {
    // A silent no-start here (e.g. a broken symlink target, or argv[1] resolving unexpectedly)
    // used to look exactly like a hung process — nothing in the logs to say the feeder never even
    // reached connect(). Always leave a trace when this module loaded but didn't take the main path.
    log(`feed.mjs loaded but isMain=false — not starting (import.meta.url=${import.meta.url}, argv[1]=${process.argv[1] ?? 'undefined'})`);
}

export {
    // state (Maps are shared references — tests read/mutate them directly)
    bars1m, bars5m, barsSub, SUB, TAPE_MAX, FLUSH_BATCH_SIZE, lastAckedSeq,
    // pure-ish helpers
    tapeRow, rollInto, rollTrade,
    // handlers
    onTicker, onTrades, onCandles,
    // restart/reconnect recovery + flush
    seedCurrentBuckets, flush,
    // test harness
    setClients, resetState,
};
