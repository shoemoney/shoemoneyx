import { reactive } from 'vue';

/**
 * Firehose toasts. `kind` picks icon + colour; `ttl` is milliseconds. Callers on the Optimizer page
 * derive `ttl` from the user's toast-lifetime slider via `toastMs()` so every toast shares one master
 * duration, scaled per kind by `KIND_MULT`.
 */
export const toasts = reactive({ list: [], seq: 0 });

/** Per-kind lifetime multiplier applied to the master slider value (seconds) by `toastMs()`. */
export const KIND_MULT = {
    backtest: 0.6,
    round: 1,
    kept: 0.6,
    promoted: 2,
    short: 2,
    error: 1.5,
    link: 1,
};

/** ms lifetime for `kind` at the given master `seconds` value. */
export function toastMs(kind, seconds) {
    return Math.round((KIND_MULT[kind] ?? 1) * seconds * 1000);
}

export const KINDS = {
    backtest: { icon: 'fa-solid fa-bolt', glyph: '⚡', cls: 'border-zinc-700 text-zinc-300', ttl: 700 },
    round: { icon: 'fa-solid fa-flag-checkered', glyph: '🏁', cls: 'border-sky-700 text-sky-200', ttl: 1500 },
    kept: { icon: 'fa-solid fa-shield-halved', glyph: '🛡', cls: 'border-zinc-600 text-zinc-300', ttl: 1000 },
    promoted: { icon: 'fa-solid fa-trophy', glyph: '🏆', cls: 'border-emerald-600 text-emerald-200 bg-emerald-500/10', ttl: 3000 },
    short: { icon: 'fa-solid fa-arrow-trend-down', glyph: '↘', cls: 'border-red-700 text-red-200', ttl: 1500 },
    error: { icon: 'fa-solid fa-triangle-exclamation', glyph: '⚠', cls: 'border-red-600 text-red-200 bg-red-500/10', ttl: 3000 },
    link: { icon: 'fa-solid fa-plug', glyph: '⏚', cls: 'border-amber-700 text-amber-200', ttl: 1500 },
};

export function toast(kind, title, detail = '', ttl = null) {
    const k = KINDS[kind] || KINDS.backtest;
    const id = ++toasts.seq;
    toasts.list.push({ id, kind, title, detail, icon: k.icon, glyph: k.glyph, cls: k.cls });
    if (toasts.list.length > 8) toasts.list.splice(0, toasts.list.length - 8);   // never a wall of toasts
    setTimeout(() => {
        const i = toasts.list.findIndex(t => t.id === id);
        if (i >= 0) toasts.list.splice(i, 1);
    }, Math.max(200, ttl ?? k.ttl));
}
