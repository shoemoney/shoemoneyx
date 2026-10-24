import { publicDemo } from './demoMode.js';

// The browser authenticates to /api with its login session cookie (App\Http\Middleware\DeskToken);
// writes also carry the session's CSRF token, which app.blade.php renders into a meta tag.
// v0.2.1 and earlier kept the master password itself in localStorage, so scrub it on load.
try { localStorage.removeItem('desk_token'); } catch { /* storage blocked */ }

export function deskHeaders(extra = {}) {
    const csrf = globalThis.document?.querySelector('meta[name="csrf-token"]')?.content;
    return csrf ? { ...extra, 'X-CSRF-TOKEN': csrf } : { ...extra };
}

export class ApiError extends Error {
    constructor(message, { status, data } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

export const SESSION_EXPIRED_MESSAGE = 'Your session expired, so nothing was changed. Reload the page and try again.';

/**
 * The one fetch every desk call goes through: attaches the CSRF header at call time, sends a dead
 * session to /login (401), a first-login key to /onboarding (403 set_password_required), and turns a
 * 419 into an explicit message instead of a silent reload that hides whether a write happened.
 * Resolves to the parsed JSON (or a Blob with `as: 'blob'`); rejects with an ApiError carrying
 * `status` and the parsed body as `data`.
 */
export async function deskFetch(url, { method = 'GET', body, headers, signal, as = 'json' } = {}) {
    if (publicDemo && method !== 'GET') throw new Error('This demo is read-only. No trades, jobs or settings are changed.');
    const res = await fetch(url, {
        method,
        headers: deskHeaders({ Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...headers }),
        body: body === undefined ? undefined : JSON.stringify(body),
        signal,
    });
    if (res.ok && as === 'blob') return res.blob();
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = text; }
    if (res.status === 401 && !publicDemo) {
        globalThis.location?.assign('/login');
    }
    // Signed in with the one-time first-login key: only the set-password screen is open.
    if (res.status === 403 && data?.error === 'set_password_required' && !publicDemo
        && !globalThis.location?.pathname?.startsWith('/onboarding')) {
        globalThis.location?.assign('/onboarding');
    }
    if (!res.ok) {
        // A login in another tab rotated the CSRF token this page rendered with.
        const msg = res.status === 419
            ? SESSION_EXPIRED_MESSAGE
            : (data && (data.message || data.error)) || `${res.status} ${res.statusText}`;
        throw new ApiError(msg, { status: res.status, data });
    }
    return data;
}

function request(method, url, body) {
    return deskFetch('/api' + url, { method, body, headers: { 'Content-Type': 'application/json' } });
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body ?? {}),
    put: (url, body) => request('PUT', url, body),
    del: (url) => request('DELETE', url),
};

// null, undefined, '' and anything that is not a finite number (NaN, ±Infinity, 'garbage') have no value to show.
const toNum = (n) => (n === null || n === undefined || n === '' ? NaN : Number(n));
// Decided on the number, not the formatted text, so locales with non-Latin digits keep their minus sign.
const roundsToZero = (v, d) => Number(Math.abs(v).toFixed(Math.min(d, 100))) === 0;
// A negative that rounds to zero ("-0.00") is just zero.
const unsignedIfZero = (v, d) => (roundsToZero(v, d) ? Math.abs(v) : v);

export const fmt = {
    usd: (n, d = 2) => {
        const v = toNum(n);
        if (!Number.isFinite(v)) return '—';
        const body = Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
        return (v < 0 && !roundsToZero(v, d) ? '-' : '') + '$' + body;
    },
    pct: (n, d = 2) => {
        const v = toNum(n);
        if (!Number.isFinite(v)) return '—';
        const zero = roundsToZero(v, d);
        return (v > 0 && !zero ? '+' : '') + unsignedIfZero(v, d).toFixed(d) + '%';
    },
    num: (n, d = 2) => {
        const v = toNum(n);
        return Number.isFinite(v) ? unsignedIfZero(v, d).toLocaleString(undefined, { maximumFractionDigits: d }) : '—';
    },
    px: (n) => {
        const v = toNum(n);
        if (!Number.isFinite(v)) return '—';
        const d = v >= 1000 ? 2 : v >= 1 ? 4 : v >= 0.01 ? 6 : 8;
        return unsignedIfZero(v, d).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
    },
    ago: (iso) => {
        const t = iso ? new Date(iso).getTime() : NaN;
        if (!Number.isFinite(t)) return '—';
        const s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 60) return Math.round(s) + 's';
        if (s < 3600) return Math.round(s / 60) + 'm';
        if (s < 86400) return (s / 3600).toFixed(1) + 'h';
        return (s / 86400).toFixed(1) + 'd';
    },
    time: (iso) => {
        const d = iso ? new Date(iso) : null;
        return d && Number.isFinite(d.getTime()) ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
    },
    mins: (m) => {
        const v = toNum(m);
        return !Number.isFinite(v) ? '—' : v < 60 ? v + 'm' : v < 1440 ? (v / 60).toFixed(1) + 'h' : (v / 1440).toFixed(1) + 'd';
    },
};
