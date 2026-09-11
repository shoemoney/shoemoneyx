import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readQuote, observeQuote, cursorTarget } from '../../../resources/js/components/chart/marketLens.js';

const raw = { product_id: 'BTC-USD', price: '80000', ts: 1788800000, bid: '79999', ask: '80001', vol24: '3150', chg24: '-1.2', source: 'feed' };
test('quotes retain source units and distinguish missing data from zero', () => {
    const quote = readQuote(raw, 'BTC-USD');
    assert.equal(quote.volume, 3150); assert.equal(quote.volumeUnit, 'BTC'); assert.equal(quote.spreadBps, .25);
    assert.equal(readQuote({ ...raw, source: 'products' }, 'BTC-USD').volumeUnit, 'USD');
    assert.equal(readQuote({ ...raw, source: 'unknown' }, 'BTC-USD').volumeUnit, null);
    assert.equal(readQuote({ ...raw, bid: null, ask: '' }, 'BTC-USD').spread, null);
    assert.equal(readQuote({ ...raw, chg24: 0, vol24: 0 }, 'BTC-USD').volume, 0);
    assert.equal(readQuote({ ...raw, bid: 80001, ask: 79999 }, 'BTC-USD').spread, null);
    for (const price of [null, '', false, 'bad', Infinity, 0, -1]) assert.equal(readQuote({ ...raw, price }, 'BTC-USD'), null);
    assert.equal(readQuote(raw, 'ETH-USD'), null);
});
test('observed price history rejects old quotes, replaces revisions and stays bounded', () => {
    let points = [];
    for (let ts = 1; ts <= 100; ts++) points = observeQuote(points, { ts, price: ts });
    assert.equal(points.length, 64); assert.equal(points[0].ts, 37);
    assert.equal(observeQuote(points, { ts: 90, price: 500 }), points);
    assert.equal(observeQuote(points, { ts: 100, price: 100 }), points);
    const revised = observeQuote(points, { ts: 100, price: 101 });
    assert.equal(revised.length, 64); assert.equal(revised.at(-1).price, 101);
});
test('crosshair normalizes visible data ranges, respects inversion and rejects invalid coordinates', () => {
    const time = { from: 10, to: 30 }, price = { from: 100, to: 200 };
    assert.deepEqual(cursorTarget({ time: 15, price: 120 }, time, price), { x: .25, y: .8, time: 15, price: 120 });
    assert.equal(cursorTarget({ time: 15, price: 120 }, time, price, true).y, .2);
    assert.equal(cursorTarget({ time: 0, price: 400 }, time, price).x, 0);
    assert.equal(cursorTarget({ time: 0, price: 400 }, time, price).y, 0);
    assert.equal(cursorTarget({}, time, price), null);
    assert.equal(cursorTarget({ time: 15, price: 120 }, time, { from: 100, to: 100 }), null);
});
