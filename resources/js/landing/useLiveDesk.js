import { ref, computed, onMounted, onUnmounted } from "vue";
import { demoSnapshot, demoEvent } from "./demo";
import { mergeEvents } from "./format";
import { createEventLedger, roundEvent, snapshotEvents } from "./liveEvents";

export function useLiveDesk(
    demo,
    { demoPairs = 10, demoBatch = 2, demoInterval = 250 } = {},
) {
    const snapshot = ref(null),
        events = ref([]),
        error = ref(""),
        connection = ref("connecting");
    const lastUpdate = ref(0),
        now = ref(Date.now()),
        rate = ref(0),
        totalMessages = ref(0);
    const trails = ref({}),
        pnlHistory = ref([]),
        motion = ref(true),
        feedPaused = ref(false);
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    motion.value = !reducedMotion.matches;
    let disposed = false,
        loading = false,
        pollTimer,
        clockTimer,
        demoTimer,
        channel,
        detachConnection,
        subscribing = false,
        hasSnapshot = false;
    let frames = [],
        buffer = [],
        eventSequence = 20,
        demoTicks = 0,
        requestController;
    const receipts = [];
    const eventLedger = createEventLedger();
    const stale = computed(
        () =>
            !demo &&
            (!lastUpdate.value ||
                now.value - lastUpdate.value > 10000 ||
                !!error.value),
    );
    const pairs = computed(() =>
        (snapshot.value?.pairs || []).map((p) => ({
            ...p,
            live:
                p.live &&
                !stale.value &&
                (demo ||
                    (snapshot.value?.feed_alive &&
                        now.value / 1000 - p.quote_at < 60)),
            history: trails.value[p.id] || p.history || [],
        })),
    );
    const selected = ref(null);
    const selectedPair = computed(() =>
        pairs.value.find((p) => p.id === selected.value),
    );
    const statusText = computed(() =>
        demo
            ? "DEMO / SIMULATED"
            : stale.value
              ? lastUpdate.value
                  ? "DATA DELAYED"
                  : "AWAITING DATA"
              : !snapshot.value?.feed_alive
                ? "MARKET FEED OFFLINE"
                : "MARKET FEED LIVE",
    );
    function absorb(incoming, count = true) {
        const fresh = eventLedger.fresh(incoming);
        if (count) {
            fresh.forEach(() => receipts.push(Date.now()));
            totalMessages.value += fresh.length;
        }
        if (feedPaused.value) buffer = mergeEvents(buffer, incoming);
        else events.value = mergeEvents(events.value, incoming);
    }
    function toggleFeed() {
        feedPaused.value = !feedPaused.value;
        if (!feedPaused.value) {
            events.value = mergeEvents(events.value, buffer);
            buffer = [];
        }
    }
    function applySnapshot(data) {
        if (disposed) return;
        const initial = !snapshot.value || snapshot.value.mode !== data.mode;
        if (snapshot.value && snapshot.value.mode !== data.mode) {
            events.value = [];
            buffer = [];
            trails.value = {};
            pnlHistory.value = [];
            receipts.length = 0;
            rate.value = 0;
            totalMessages.value = 0;
            eventLedger.reset();
        }
        snapshot.value = data;
        lastUpdate.value = Date.now();
        const next = {};
        data.pairs.forEach((p) => {
            if (p.price == null) return;
            next[p.id] = [
                ...(trails.value[p.id] || (demo ? p.history : [])),
                p.price,
            ].slice(-60);
        });
        trails.value = next;
        if (data.metrics.pnl != null)
            pnlHistory.value = [...pnlHistory.value, data.metrics.pnl].slice(
                -120,
            );
        absorb(snapshotEvents(data), !initial);
        error.value = "";
    }
    // One request at a time; a failed API never silently becomes simulated data.
    async function refresh() {
        if (disposed || loading || demo || document.hidden) return;
        loading = true;
        requestController = new AbortController();
        const timeout = setTimeout(() => requestController?.abort(), 8000);
        try {
            const response = await fetch("/api/landing", {
                signal: requestController.signal,
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                throw new Error(
                    `Snapshot unavailable (${response.status}). Retrying automatically.`,
                );
            }
            const data = await response.json();
            if (!Array.isArray(data.pairs) || !data.metrics)
                throw new Error("The desk returned an incomplete snapshot.");
            applySnapshot(data);
            hasSnapshot = true;
            subscribe().catch(() => {
                connection.value = "unavailable";
            });
        } catch (e) {
            if (!disposed)
                error.value =
                    e.name === "AbortError"
                        ? "The desk is taking too long to respond. Retrying automatically."
                        : e.message;
        } finally {
            clearTimeout(timeout);
            loading = false;
        }
    }
    async function subscribe() {
        if (channel || subscribing || !hasSnapshot) return;
        if (!import.meta.env.VITE_REVERB_APP_KEY) {
            connection.value = "not configured";
            return;
        }
        subscribing = true;
        let transport;
        try {
            transport = await import("../echo");
        } finally {
            subscribing = false;
        }
        if (disposed || !hasSnapshot) return;
        const { echo, onConnectionState } = transport;
        detachConnection = onConnectionState((s) => {
            connection.value = s;
            if (s === "connected") refresh();
        });
        channel = echo.channel("optimizer");
        const scored = (e) =>
            absorb([
                {
                    key: `backtest-${e.id}`,
                    at: e.at,
                    agent: "AI",
                    kind: "optimizer",
                    coin: e.coin,
                    message: `${e.coin || "Candidate"} · ${e.side || "long"} ${e.window || "backtest"} · ${e.status === "error" ? "failed" : e.ret == null ? "scored" : `${Number(e.ret).toFixed(2)}% return`}`,
                },
            ]);
        const promoted = (e) => {
            absorb([
                {
                    key: `promotion-${e.coin}-${e.side}-${e.at}`,
                    at: e.at,
                    agent: "AI",
                    kind: "promotion",
                    coin: e.coin,
                    message: `${e.coin} · new ${e.side} champion promoted`,
                },
            ]);
            refresh();
        };
        const round = (e) => absorb([roundEvent(e)]);
        frames = [
            [".backtest.scored", scored],
            [".champion.promoted", promoted],
            [".round.scored", round],
        ];
        frames.forEach(([name, fn]) => channel.listen(name, fn));
    }
    const visibility = () => {
        if (!document.hidden) refresh();
    };
    const motionPreference = (e) => {
        motion.value = !e.matches;
    };
    onMounted(() => {
        if (demo) {
            applySnapshot(demoSnapshot(0, demoPairs));
            const demoPnl = snapshot.value.metrics.pnl;
            pnlHistory.value = Array.from(
                { length: 80 },
                (_, i) =>
                    demoPnl * (0.65 + (i / 79) * 0.35) +
                    Math.sin(i * 0.6) * 185 +
                    Math.cos(i * 1.7) * 65,
            );
            events.value = Array.from({ length: 20 }, (_, i) =>
                demoEvent(19 - i, demoPairs),
            );
            connection.value = "demo";
            demoTimer = setInterval(() => {
                if (document.hidden) return;
                demoTicks++;
                if (demoTicks % 4 === 0)
                    applySnapshot(demoSnapshot(demoTicks / 4, demoPairs));
                absorb(
                    Array.from({ length: demoBatch }, () => ({
                        ...demoEvent(++eventSequence, demoPairs),
                        at: new Date().toISOString(),
                    })),
                );
            }, demoInterval);
        } else {
            refresh();
            pollTimer = setInterval(refresh, 3000);
        }
        clockTimer = setInterval(() => {
            now.value = Date.now();
            while (receipts.length && receipts[0] < now.value - 5000)
                receipts.shift();
            rate.value = receipts.length / 5;
        }, 1000);
        document.addEventListener("visibilitychange", visibility);
        reducedMotion.addEventListener("change", motionPreference);
    });
    onUnmounted(() => {
        disposed = true;
        [pollTimer, clockTimer, demoTimer].forEach(clearInterval);
        requestController?.abort();
        frames.forEach(([name, fn]) => channel?.stopListening(name, fn));
        detachConnection?.();
        // The shared Echo connection belongs to the app; only remove our handlers.
        document.removeEventListener("visibilitychange", visibility);
        reducedMotion.removeEventListener("change", motionPreference);
    });
    return {
        snapshot,
        pairs,
        events,
        error,
        connection,
        lastUpdate,
        now,
        rate,
        totalMessages,
        stale,
        statusText,
        pnlHistory,
        motion,
        feedPaused,
        selected,
        selectedPair,
        toggleFeed,
        refresh,
    };
}
