import test from "node:test";
import assert from "node:assert/strict";
import {
    clockwiseCoins,
    coinScanFrame,
    COIN_SCAN_STEP_MS,
    COIN_SCAN_TURN_MS,
} from "../../../resources/js/landing/robotCoinScan.js";

test("visits the visible orbit clockwise from the top, exactly once per cycle", () => {
    const pairs = ["right", "top", "left", "bottom"].map((id) => ({ id }));
    const coins = clockwiseCoins(pairs);
    assert.deepEqual(
        coins.map((c) => c.id),
        ["top", "right", "bottom", "left"],
    );
    assert.deepEqual(
        coins.map(
            (_, i) =>
                coinScanFrame(coins, i * COIN_SCAN_STEP_MS + COIN_SCAN_TURN_MS)
                    .id,
        ),
        ["top", "right", "bottom", "left"],
    );
    assert.equal(
        coinScanFrame(coins, 4 * COIN_SCAN_STEP_MS + COIN_SCAN_TURN_MS).id,
        "top",
    );
});
test("a full 18-market cycle includes every actual market", () => {
    const pairs = Array.from({ length: 18 }, (_, i) => ({ id: `coin-${i}` }));
    const coins = clockwiseCoins(pairs);
    assert.equal(new Set(coins.map((c) => c.id)).size, 18);
    assert.deepEqual(
        coins.map((c) => c.id),
        [4, 3, 2, 1, 0, 17, 16, 15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5].map(
            (i) => `coin-${i}`,
        ),
    );
});
test("starts facing forward and holds the target during the analysis pause", () => {
    const coins = clockwiseCoins([{ id: "BTC" }]);
    assert.deepEqual(coinScanFrame(coins, 0), {
        x: 0,
        y: 0,
        id: null,
        light: 0,
    });
    assert.deepEqual(
        coinScanFrame(coins, COIN_SCAN_TURN_MS + 50),
        coinScanFrame(coins, COIN_SCAN_STEP_MS - 500),
    );
    const midpoint = coinScanFrame(coins, COIN_SCAN_TURN_MS / 2);
    assert.equal(midpoint.x, 0.5);
    assert.equal(midpoint.id, null);
});
test("cycle boundaries have no pose jump", () => {
    const coins = clockwiseCoins(
        Array.from({ length: 18 }, (_, i) => ({ id: String(i) })),
    );
    for (let step = 1; step < 38; step++) {
        const before = coinScanFrame(coins, step * COIN_SCAN_STEP_MS - 0.001);
        const after = coinScanFrame(coins, step * COIN_SCAN_STEP_MS);
        assert.ok(
            Math.hypot(before.x - after.x, before.y - after.y) < 0.000001,
        );
        assert.equal(before.id, after.id);
    }
});
test("empty market lists remain neutral", () => {
    assert.deepEqual(coinScanFrame([], 50000), {
        x: 0,
        y: 0,
        id: null,
        light: 0,
    });
});
test("eye light fades in on arrival and fades out before changing coins", () => {
    const coins = clockwiseCoins([{ id: "BTC" }, { id: "ETH" }]);
    assert.equal(coinScanFrame(coins, COIN_SCAN_TURN_MS * 0.5).light, 0);
    assert.ok(coinScanFrame(coins, COIN_SCAN_TURN_MS * 0.9).light > 0);
    assert.equal(coinScanFrame(coins, COIN_SCAN_TURN_MS).light, 1);
    assert.ok(coinScanFrame(coins, COIN_SCAN_STEP_MS - 10).light < 0.01);
    assert.equal(coinScanFrame(coins, COIN_SCAN_STEP_MS).light, 0);
});
