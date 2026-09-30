import { publicDemo } from './demoMode.js';

// The browser authenticates to /api with its login session cookie (App\Http\Middleware\DeskToken);
// writes also carry the session's CSRF token, which app.blade.php renders into a meta tag.
// v0.2.1 and earlier kept the master password itself in localStorage, so scrub it on load.
try { localStorage.removeItem('desk_token'); } catch { /* storage blocked */ }

export function deskHeaders(extra = {}) {
    const csrf = globalThis.document?.querySelector('meta[name="csrf-token"]')?.content;
    return csrf ? { ...extra, 'X-CSRF-TOKEN': csrf } : { ...extra };
}

async function request(method, url, body) {
    if (publicDemo && method !== 'GET') throw new Error('This demo is read-only. No trades, jobs or settings are changed.');
    const res = await fetch('/api' + url, {
        method,
        headers: deskHeaders({ 'Accept': 'application/json', 'Content-Type': 'application/json' }),
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = text; }
    if (res.status === 401 && !publicDemo) {
        globalThis.location?.assign('/login');
    }
    // A login in another tab rotated the CSRF token this page rendered with.
    if (res.status === 419) {
        globalThis.location?.reload();
    }
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
