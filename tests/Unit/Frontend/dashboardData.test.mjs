import test from 'node:test';
import assert from 'node:assert/strict';
import { finiteNumber, historyPoints, windowHistory, sumPositionPnl, equityChange } from '../../../resources/js/components/dashboard/dashboardData.js';

test('financial values distinguish zero from missing, blank, and malformed input', () => {
    for (const value of [null, undefined, '', '  ', false, true, NaN, Infinity, '-Infinity', 'unknown', [], {}]) {
        assert.equal(finiteNumber(value), null, `invalid input ${String(value)}`);
    }
    assert.equal(finiteNumber(0), 0);
    assert.equal(finiteNumber('0.00'), 0);
    assert.equal(finiteNumber('-23.75'), -23.75);
});

test('equity history preserves missing-value gaps and sorts real timestamps', () => {
    const rows = [
        { taken_at: '2026-09-07T03:00:00Z', equity: '125.50' },
        { taken_at: '2026-09-07T01:00:00Z', equity: '100.00' },
        { taken_at: '2026-09-07T02:00:00Z', equity: null },
        { taken_at: 'invalid', equity: 9000 },
    ];
    assert.deepEqual(historyPoints(rows).map(point => point[1]), [100, null, 125.5]);
    assert.equal(rows[0].equity, '125.50', 'source snapshots are unchanged');
});

test('capital series uses the requested observed field, including genuine zero', () => {
    const rows = [{ taken_at: '2026-09-07T01:00:00Z', equity: 100, cash: '0.00' }];
    assert.deepEqual(historyPoints(rows, 'cash'), [[Date.parse(rows[0].taken_at), 0]]);
    assert.deepEqual(historyPoints(rows, 'positions_value'), [[Date.parse(rows[0].taken_at), null]]);
});

test('time ranges exclude old and future observations at exact UTC boundaries', () => {
    const now = Date.parse('2026-09-07T12:00:00Z');
    const rows = [-3_600_001, -3_600_000, -1, 0, 1].map(offset => ({ taken_at: new Date(now + offset).toISOString() }));
    assert.deepEqual(windowHistory(rows, 1, now), rows.slice(1, 4));
});

test('unrealised total requires every position mark and never concatenates numeric strings', () => {
    assert.equal(sumPositionPnl([{ unrealised_pnl: '25.50' }, { unrealised_pnl: '-30.75' }]), -5.25);
    assert.equal(sumPositionPnl([{ unrealised_pnl: 5 }, { unrealised_pnl: null }]), null);
    assert.equal(sumPositionPnl([], false), null, 'not loaded is not a flat account');
    assert.equal(sumPositionPnl([]), 0, 'a loaded flat account has no open P&L');
});

test('balance change uses observed endpoints without treating missing boundaries as zero', () => {
    const rows = [
        { taken_at: '2026-09-07T01:00:00Z', equity: '100' },
        { taken_at: '2026-09-07T02:00:00Z', equity: '90' },
    ];
    assert.equal(equityChange(rows), -10);
    assert.equal(equityChange(rows.slice(0, 1)), null);
    assert.equal(equityChange([{ ...rows[0], equity: null }, rows[1]]), null);
    assert.equal(equityChange([rows[0], { ...rows[1], equity: null }]), null);
});
