import { finiteNumber } from '../dashboard/dashboardData.js';

export function readQuote(raw, symbol) {
    if (!raw || raw.product_id !== symbol) return null;
    const price = finiteNumber(raw.price), ts = finiteNumber(raw.ts);
    if (price === null || price <= 0 || ts === null || ts <= 0) return null;
    const bid = finiteNumber(raw.bid), ask = finiteNumber(raw.ask);
    const spread = bid !== null && ask !== null && bid > 0 && ask >= bid ? ask - bid : null;
    return { symbol, price, ts, bid, ask, spread, spreadBps: spread === null ? null : spread / price * 10000,
        change: finiteNumber(raw.chg24), volume: finiteNumber(raw.vol24),
        volumeUnit: raw.source === 'feed' ? symbol.split('-')[0] : ['products', 'demo'].includes(raw.source) ? 'USD' : null,
        source: raw.source };
}

export function observeQuote(points, quote) {
    if (!quote) return points;
    const last = points.at(-1);
    if (last && quote.ts < last.ts) return points;
    if (last && quote.ts === last.ts) return last.price === quote.price ? points : [...points.slice(0, -1), quote];
    return [...points, quote].slice(-64);
}

export function cursorTarget(event, timeRange, priceRange, inverted = false) {
    if (![event?.time, event?.price, timeRange?.from, timeRange?.to, priceRange?.from, priceRange?.to].every(Number.isFinite)) return null;
    if (timeRange.to <= timeRange.from || priceRange.to <= priceRange.from) return null;
    const clamp = n => Math.min(1, Math.max(0, n));
    const y = clamp((event.price - priceRange.from) / (priceRange.to - priceRange.from));
    return { x: clamp((event.time - timeRange.from) / (timeRange.to - timeRange.from)), y: inverted ? y : 1 - y,
        time: event.time, price: event.price };
}
