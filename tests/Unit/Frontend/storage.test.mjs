import test from 'node:test';
import assert from 'node:assert/strict';
import { readStored, writeStored, removeStored } from '../../../resources/js/storage.js';

test('storage helpers survive a blocked localStorage (Safari private mode)', (t) => {
    const blocked = () => { throw new DOMException('denied', 'SecurityError'); };
    globalThis.localStorage = { getItem: blocked, setItem: blocked, removeItem: blocked };
    t.after(() => { delete globalThis.localStorage; });

    assert.equal(readStored('k', 'fallback'), 'fallback');
    assert.doesNotThrow(() => writeStored('k', 'v'));
    assert.doesNotThrow(() => removeStored('k'));
});

test('storage helpers read and write when storage works', (t) => {
    const map = new Map();
    globalThis.localStorage = { getItem: (k) => map.get(k) ?? null, setItem: (k, v) => map.set(k, v), removeItem: (k) => map.delete(k) };
    t.after(() => { delete globalThis.localStorage; });

    assert.equal(readStored('k', 'd'), 'd');
    writeStored('k', 3);
    assert.equal(readStored('k'), '3');
    removeStored('k');
    assert.equal(readStored('k'), null);
});
