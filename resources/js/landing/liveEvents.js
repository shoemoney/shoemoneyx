import { cash } from "./format.js";

// The same keys are used by the durable snapshot and the optimizer socket so
// reload/reconnect reconciliation never creates a second copy of a round.
export function roundEvent(event) {
    return {
        key: `round-${event.id}`,
        at: event.at || event.created_at,
        agent: "AI",
        kind: "optimizer",
        coin: event.coin || event.product_id,
        scope: "optimizer",
        message: `${event.coin || event.product_id} · round completed · ${event.candidates} candidates · ${event.promoted ? "promoted" : "champion held"}`,
    };
}

export function snapshotEvents(snapshot) {
    return [
        ...(snapshot.events || []).map((event) => ({
            key: `desk-${event.id}`,
            at: event.created_at,
            agent: event.agent,
            kind: "desk",
            scope: event.scope || "system",
            message: event.message,
        })),
        ...(snapshot.fills || []).map((fill) => ({
            key: `fill-${fill.id}`,
            at: fill.created_at,
            agent: "FILLS",
            kind: "fill",
            coin: fill.product_id,
            scope: snapshot.mode,
            message: `${fill.product_id} · ${fill.side} ${fill.kind} · ${fill.status}${fill.status === "filled" ? ` · ${cash(fill.filled_usd)} @ ${cash(fill.fill_price, fill.fill_price < 1 ? 5 : 2)}` : ""}`,
        })),
        ...(snapshot.optimizer_rounds || []).map(roundEvent),
    ];
}

// Snapshot rows repeat on every poll. Keep recently observed keys hot even if
// their original timestamps are old, while bounding a long-running firehose.
export function createEventLedger(limit = 2000) {
    const seen = new Map();
    return {
        fresh(incoming) {
            const fresh = [];
            for (const event of incoming) {
                if (!seen.has(event.key)) fresh.push(event);
                seen.delete(event.key);
                seen.set(event.key, true);
            }
            while (seen.size > limit) seen.delete(seen.keys().next().value);
            return fresh;
        },
        reset() {
            seen.clear();
        },
    };
}
