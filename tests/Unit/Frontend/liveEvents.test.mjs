import test from "node:test";
import assert from "node:assert/strict";
import {
    createEventLedger,
    roundEvent,
    snapshotEvents,
} from "../../../resources/js/landing/liveEvents.js";

test("snapshot fills and decisions share durable keys with socket rounds", () => {
    const snapshot = {
        mode: "paper",
        events: [
            {
                id: 9,
                created_at: "2026-09-06T13:00:00Z",
                agent: "SCAN",
                message: "BTC signal",
                scope: "paper",
            },
        ],
        fills: [
            {
                id: 4,
                product_id: "BTC-USD",
                side: "BUY",
                kind: "entry",
                status: "filled",
                filled_usd: 200,
                fill_price: 100,
                created_at: "2026-09-06T13:00:01Z",
            },
        ],
        optimizer_rounds: [
            {
                id: 7,
                product_id: "BTC-USD",
                candidates: 5,
                promoted: false,
                created_at: "2026-09-06T13:00:02Z",
            },
        ],
    };
    const records = snapshotEvents(snapshot);
    assert.equal(records.length, 3);
    assert.equal(records[0].scope, "paper");
    assert.equal(
        records[1].message,
        "BTC-USD · BUY entry · filled · $200.00 @ $100.00",
    );
    assert.equal(records[2].scope, "optimizer");
    const socket = roundEvent({
        id: 7,
        coin: "BTC-USD",
        candidates: 5,
        promoted: false,
        at: "2026-09-06T13:00:03Z",
    });
    assert.equal(records[2].key, socket.key);
    const ledger = createEventLedger();
    assert.equal(ledger.fresh(records).length, 3);
    assert.equal(ledger.fresh([...records, socket]).length, 0);
    assert.equal(ledger.fresh([{ key: "desk-10" }, ...records]).length, 1);
});

test("repeated snapshots do not inflate message rates while a paused feed buffers arrivals", () => {
    const ledger = createEventLedger();
    const snapshot = [{ key: "desk-3" }, { key: "fill-7" }];
    ledger.fresh(snapshot); // Initial history is displayed but not counted as new arrivals.
    let received = 0;
    for (let i = 0; i < 5; i++) received += ledger.fresh(snapshot).length;
    assert.equal(received, 0);
    received += ledger.fresh([{ key: "desk-4" }, ...snapshot]).length;
    received += ledger.fresh([{ key: "desk-4" }]).length;
    assert.equal(received, 1);
    ledger.reset(); // Mode changes start a new ledger.
    assert.equal(ledger.fresh(snapshot).length, 2);
});

test("a bounded ledger retains old snapshot rows that are still polled", () => {
    const ledger = createEventLedger(4);
    ledger.fresh([
        { key: "old-desk-row" },
        { key: "socket-1" },
        { key: "socket-2" },
    ]);
    assert.equal(ledger.fresh([{ key: "old-desk-row" }]).length, 0);
    ledger.fresh([{ key: "socket-3" }, { key: "socket-4" }]);
    assert.equal(ledger.fresh([{ key: "old-desk-row" }]).length, 0);
    assert.equal(ledger.fresh([{ key: "socket-1" }]).length, 1);
});
