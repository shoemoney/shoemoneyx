import test from 'node:test';
import assert from 'node:assert/strict';
import { createDemoEcho } from '../../../resources/js/demoEcho.js';

test('synthetic event stream pairs candidates, pauses when hidden and cleans up on navigation', () => {
    let tick, hidden = false, clears = 0, starts = 0;
    const echo = createDemoEcho({ clock: { setInterval(fn) { tick = fn; starts++; return 1; }, clearInterval() { clears++; } }, isHidden: () => hidden });
    const rows = [];
    echo.channel('optimizer').listen('.backtest.scored', event => rows.push(event));
    echo.channel('optimizer');
    tick();
    assert.equal(starts, 1);
    assert.equal(rows.length, 12);
    for (let i = 0; i < rows.length; i += 2) {
        assert.equal(rows[i].cand, rows[i + 1].cand);
        assert.deepEqual([rows[i].window, rows[i + 1].window], ['train', 'test']);
        assert.equal(rows[i].demo, true);
    }
    hidden = true; tick(); assert.equal(rows.length, 12);
    echo.leave('optimizer'); assert.equal(clears, 1);
    hidden = false; tick(); assert.equal(rows.length, 12);
    echo.disconnect(); assert.equal(clears, 1);
});
