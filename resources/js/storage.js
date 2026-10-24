// localStorage throws in Safari private mode and when site data is blocked; a preference that cannot
// be saved must never break the feature that tried to save it.
export function readStored(key, fallback = null) {
    try {
        const value = globalThis.localStorage?.getItem(key);
        return value === null || value === undefined ? fallback : value;
    } catch {
        return fallback;
    }
}

export function writeStored(key, value) {
    try {
        globalThis.localStorage?.setItem(key, String(value));
    } catch { /* storage blocked */ }
}

export function removeStored(key) {
    try {
        globalThis.localStorage?.removeItem(key);
    } catch { /* storage blocked */ }
}
