import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mapUdfHistory, mapUdfMarks, UP_COLOR, DOWN_COLOR } from '../../../resources/js/components/chart/udfMapping.js';

test('history mapping pairs candle and volume arrays and colors volume by candle direction', () => {
    const payload = { s: 'ok', t: [100, 160, 220], o: [10, 12, 9], h: [13, 12.5, 9.5], l: [9, 11, 8], c: [12, 11, 9.2], v: [5, 0, '7'] };
    const { candles, volumes } = mapUdfHistory(payload);
    assert.deepEqual(candles, [
        { time: 100, open: 10, high: 13, low: 9, close: 12 },
        { time: 160, open: 12, high: 12.5, low: 11, close: 11 },
        { time: 220, open: 9, high: 9.5, low: 8, close: 9.2 },
    ]);
    assert.equal(volumes[0].value, 5); assert.equal(volumes[0].color, 'rgba(34, 197, 94, 0.5)');
    assert.equal(volumes[1].color, 'rgba(239, 68, 68, 0.5)'); // close === open counts as down
    assert.equal(volumes[2].value, '7'); assert.equal(volumes[2].color, 'rgba(34, 197, 94, 0.5)');
});
test('history mapping treats no_data and missing volume as empty, never throws', () => {
    assert.deepEqual(mapUdfHistory({ s: 'no_data', nextTime: 1 }), { candles: [], volumes: [] });
    assert.deepEqual(mapUdfHistory(null), { candles: [], volumes: [] });
    assert.deepEqual(mapUdfHistory({ s: 'ok', t: [5], o: [1], h: [1], l: [1], c: [1] }).volumes, [{ time: 5, value: 0, color: 'rgba(34, 197, 94, 0.5)' }]);
});
test('marks mapping renders buy/sell as arrow markers and sorts them into time order', () => {
    const payload = { id: [2, 1], time: [200, 100], color: ['red', 'green'], text: ['exit', 'entry'], label: ['S', 'B'], labelFontColor: ['white', 'white'], minSize: [14, 14] };
    const marks = mapUdfMarks(payload);
    assert.deepEqual(marks, [
        { time: 100, position: 'belowBar', color: UP_COLOR, shape: 'arrowUp', text: 'B', id: '1' },
        { time: 200, position: 'aboveBar', color: DOWN_COLOR, shape: 'arrowDown', text: 'S', id: '2' },
    ]);
});
test('marks mapping handles the empty feed', () => {
    assert.deepEqual(mapUdfMarks({ id: [], time: [], color: [], text: [], label: [], labelFontColor: [], minSize: [] }), []);
    assert.deepEqual(mapUdfMarks(null), []);
});
