export const cash = (n, digits = 2) =>
    n == null || !Number.isFinite(Number(n))
        ? "—"
        : `${n < 0 ? "−" : ""}$${Math.abs(n).toLocaleString("en-US", { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
export const signedCash = (n) =>
    n == null
        ? "—"
        : `${n >= 0 ? "+" : "−"}$${Math.abs(n).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
export const percent = (n) =>
    n == null ? "—" : `${n >= 0 ? "+" : "−"}${Math.abs(n).toFixed(2)}%`;
export const price = (n) => cash(n, n != null && n < 1 ? 5 : 2);
export const compact = (n) =>
    n == null
        ? "—"
        : Intl.NumberFormat("en-US", {
              notation: "compact",
              maximumFractionDigits: 1,
          }).format(n);
export const clockTime = (n) =>
    new Date(n).toLocaleTimeString("en-GB", { hour12: false });
export const ticker = (id) => id.split("-")[0];

export function mergeEvents(existing, incoming, limit = 80) {
    const events = new Map(existing.map((e) => [e.key, e]));
    incoming.forEach((e) => events.set(e.key, e));
    return [...events.values()]
        .sort((a, b) => new Date(b.at) - new Date(a.at))
        .slice(0, limit);
}
