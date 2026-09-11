import test from "node:test";
import assert from "node:assert/strict";
import {
    mergeEvents,
    cash,
    percent,
} from "../../../resources/js/landing/format.js";
import { demoSnapshot, demoEvent } from "../../../resources/js/landing/demo.js";

test("firehose deduplicates reconnects, preserves chronology, and caps its buffer", () => {
    const make = (i) => ({
        key: `event-${i}`,
        at: new Date(i * 1000).toISOString(),
    });
    const prior = Array.from({ length: 80 }, (_, i) => make(i));
    const incoming = [make(79), make(80), make(81), make(2)];
    const merged = mergeEvents(prior, incoming);
    assert.equal(merged.length, 80);
    assert.equal(merged[0].key, "event-81");
    assert.equal(merged[79].key, "event-2");
    assert.equal(new Set(merged.map((e) => e.key)).size, 80);
});

test("unknown values are distinct from a real zero", () => {
    assert.equal(cash(null), "—");
    assert.equal(cash(undefined), "—");
    assert.equal(cash(0), "$0.00");
    assert.equal(percent(null), "—");
    assert.equal(percent(-1.25), "−1.25%");
});

test("expanded demo keeps totals consistent with all 18 markets while preserving the original ten", () => {
    assert.equal(demoSnapshot().pairs.length, 10);
    for (const step of [0, 1, 100, 999]) {
        const { pairs, metrics } = demoSnapshot(step, 18);
        assert.equal(pairs.length, 18);
        assert.equal(new Set(pairs.map((p) => p.id)).size, 18);
        assert.equal(metrics.open, pairs.length);
        assert.equal(
            metrics.notional,
            pairs.reduce((sum, p) => sum + p.notional, 0),
        );
        assert.equal(
            metrics.pnl,
            pairs.reduce((sum, p) => sum + p.pnl, 0),
        );
        assert.ok(
            Math.abs(metrics.pnl - metrics.realised - metrics.unrealised) <
                1e-8,
        );
        pairs.forEach((p) => {
            assert.ok(p.volume > 0);
            assert.ok(p.price > 0);
            assert.ok(p.history.every(Number.isFinite));
        });
    }
});

test("the expanded firehose covers its market universe without leaking extra pairs into the original demo", () => {
    const original = new Set(demoSnapshot().pairs.map((p) => p.id));
    const expanded = new Set(demoSnapshot(0, 18).pairs.map((p) => p.id));
    const seen = new Set();
    for (let i = 0; i < 180; i++) {
        assert.ok(original.has(demoEvent(i).coin));
        const event = demoEvent(i, 18);
        assert.ok(expanded.has(event.coin));
        seen.add(event.coin);
    }
    assert.equal(seen.size, 18);
});

test("simulated mark-to-market P&L follows long and short direction", () => {
    const initial = demoSnapshot(0, 18).pairs;
    const changed = demoSnapshot(13, 18).pairs;
    changed.forEach((pair, i) => {
        const before = initial[i];
        const markChange = pair.price / before.price - 1;
        const expected =
            before.notional * markChange * (pair.side === "short" ? -1 : 1);
        assert.ok(Math.abs(pair.pnl - before.pnl - expected) < 1e-8);
    });
});
