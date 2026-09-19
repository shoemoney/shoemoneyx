// Pure mappers from the TradingView UDF wire format (still served by UdfController) to the
// shapes lightweight-charts wants. No DOM, no chart instance — safe to unit test directly.

export const UP_COLOR = '#22c55e';
export const DOWN_COLOR = '#ef4444';
const UP_VOLUME = 'rgba(34, 197, 94, 0.5)';
const DOWN_VOLUME = 'rgba(239, 68, 68, 0.5)';

// UDF 'history' response -> { candles, volumes } for a candlestick + histogram series pair.
// 's': 'ok' has parallel t/o/h/l/c/v arrays; 'no_data' and anything else map to empty arrays
// so callers can setData([]) rather than branch on payload shape themselves.
export function mapUdfHistory(payload) {
    if (!payload || payload.s !== 'ok' || !Array.isArray(payload.t)) return { candles: [], volumes: [] };
    const { t, o, h, l, c, v } = payload;
    const candles = [], volumes = [];
    for (let i = 0; i < t.length; i++) {
        const time = t[i], open = o[i], high = h[i], low = l[i], close = c[i];
        candles.push({ time, open, high, low, close });
        volumes.push({ time, value: v?.[i] ?? 0, color: close >= open ? UP_VOLUME : DOWN_VOLUME });
    }
    return { candles, volumes };
}

// UDF 'marks' response (parallel id/time/label arrays) -> lightweight-charts SeriesMarker[].
// createSeriesMarkers requires ascending time order, which fills-by-id and backtest
// entry/exit-interleaved-by-trade order don't guarantee.
export function mapUdfMarks(payload) {
    if (!payload || !Array.isArray(payload.id)) return [];
    const marks = payload.id.map((id, i) => {
        const isBuy = payload.label?.[i] === 'B';
        return {
            time: payload.time[i],
            position: isBuy ? 'belowBar' : 'aboveBar',
            color: isBuy ? UP_COLOR : DOWN_COLOR,
            shape: isBuy ? 'arrowUp' : 'arrowDown',
            text: payload.label?.[i] || '',
            id: String(id),
        };
    });
    return marks.sort((a, b) => a.time - b.time);
}

function mergeByTime(fresher, older) {
    const byTime = new Map();
    for (const point of older) byTime.set(point.time, point);
    for (const point of fresher) byTime.set(point.time, point); // fresher wins when a boundary bar overlaps
    return [...byTime.values()].sort((a, b) => a.time - b.time);
}

// Prepends an older-window 'history' response onto the candles/volumes already on the chart
// (backward pagination as the user pans past the loaded left edge). `requestedBars` is the
// countback the older-window request asked for; getting back fewer bars than that, or a
// non-'ok' status, means there is nothing further back to fetch — 'exhausted' tells the
// caller to stop subscribing to more left-edge pans for this series.
export function mergeOlderHistory(current, olderPayload, requestedBars) {
    const older = mapUdfHistory(olderPayload);
    const exhausted = olderPayload?.s !== 'ok' || older.candles.length === 0 || older.candles.length < requestedBars;
    if (older.candles.length === 0) return { candles: current.candles, volumes: current.volumes, exhausted };
    return {
        candles: mergeByTime(current.candles, older.candles),
        volumes: mergeByTime(current.volumes, older.volumes),
        exhausted,
    };
}

// Unions two SeriesMarker[] sets by id (never drops a marker already on the chart) and keeps
// the ascending time order createSeriesMarkers requires.
export function mergeMarks(existing, incoming) {
    const byId = new Map();
    for (const mark of existing) byId.set(mark.id, mark);
    for (const mark of incoming) byId.set(mark.id, mark);
    return [...byId.values()].sort((a, b) => a.time - b.time);
}
