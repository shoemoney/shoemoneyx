import { publicDemo } from './demoMode.js';

async function request(method, url, body) {
    if (publicDemo && method !== 'GET') throw new Error('This demo is read-only. No trades, jobs or settings are changed.');
    const res = await fetch('/api' + url, {
        method,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = text; }
    if (!res.ok) {
        const msg = (data && (data.message || data.error)) || `${res.status} ${res.statusText}`;
        throw new Error(msg);
    }
    return data;
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body ?? {}),
    put: (url, body) => request('PUT', url, body),
    del: (url) => request('DELETE', url),
};

export const fmt = {
    usd: (n, d = 2) => n === null || n === undefined || isNaN(n) ? '—' : (n < 0 ? '-' : '') + '$' + Math.abs(Number(n)).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d }),
    pct: (n, d = 2) => n === null || n === undefined || isNaN(n) ? '—' : (Number(n) > 0 ? '+' : '') + Number(n).toFixed(d) + '%',
    num: (n, d = 2) => n === null || n === undefined || isNaN(n) ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: d }),
    px: (n) => {
        if (n === null || n === undefined || isNaN(n)) return '—';
        n = Number(n);
        const d = n >= 1000 ? 2 : n >= 1 ? 4 : n >= 0.01 ? 6 : 8;
        return n.toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
    },
    ago: (iso) => {
        if (!iso) return '—';
        const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) return Math.round(s) + 's';
        if (s < 3600) return Math.round(s / 60) + 'm';
        if (s < 86400) return (s / 3600).toFixed(1) + 'h';
        return (s / 86400).toFixed(1) + 'd';
    },
    time: (iso) => iso ? new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—',
    mins: (m) => m === null || m === undefined ? '—' : m < 60 ? m + 'm' : m < 1440 ? (m / 60).toFixed(1) + 'h' : (m / 1440).toFixed(1) + 'd',
};
