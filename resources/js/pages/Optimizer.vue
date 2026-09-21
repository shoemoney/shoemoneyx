<script setup>
import { publicDemo } from "../demoMode";
import PageHeading from "../components/PageHeading.vue";
import { ref, onMounted, onUnmounted, computed, watch } from "vue";
import { api, fmt } from "../api";
import { echo, onConnectionState } from "../echo";
import { toast, toastMs } from "../toasts";
import OptimizerScatter from "../components/OptimizerScatter.vue";
import OptimizerHeatmap from "../components/OptimizerHeatmap.vue";
import FarmPanel from "../components/FarmPanel.vue";
import "../../css/research-theater.css";

const champions = ref([]);
const focus = ref({ coin: "BTC-USD", side: "long", tag: "" });
const points = ref([]); // paired candidates for the focused coin/side, from the API then live
const loaded = ref(false);
const pointsLoaded = ref(false);
const pointsError = ref("");
const refreshedAt = ref(null);
const feedMode = ref("all");
const feedPaused = ref(false);
const heldFeed = ref([]);
const visibleFeed = computed(() => (feedPaused.value ? heldFeed.value : feed.value).filter((r) => feedMode.value === "all" || (feedMode.value === "promotions" ? r.k === "promoted" : r.k === "round" || r.k === "error")));
function toggleFeed() {
    if (!feedPaused.value) heldFeed.value = [...feed.value];
    feedPaused.value = !feedPaused.value;
}
let focusRequest = 0;
let disposed = false;
const completedPoints = new Map();
const halves = new Map(); // batch|cand → partial point waiting for its other window
const focusChampion = computed(() => {
    const c = champions.value.find((c) => c.coin === focus.value.coin);
    return c?.rounds?.[focus.value.side] ?? null;
});
async function loadPoints() {
    const request = ++focusRequest;
    try {
        const q = new URLSearchParams({
            coin: focus.value.coin,
            side: focus.value.side,
            hours: "48",
        });
        if (focus.value.tag) q.set("tag", focus.value.tag);
        const r = await api.get(`/optimizer/candidates?${q.toString()}`);
        if (disposed || request !== focusRequest) return;
        const snapshot = new Map((r.points || []).map((point) => [String(point.batch) + "|" + String(point.cand), point]));
        for (const [key, point] of completedPoints) snapshot.set(key, point);
        points.value = [...snapshot.values()].slice(-6000);
        completedPoints.clear();
        pointsLoaded.value = true;
        pointsError.value = "";
        halves.clear();
    } catch (e) {
        if (disposed || request !== focusRequest) return;
        pointsError.value = e.message;
    }
}
/** A live backtest.scored for the focused coin/side: pair train+test by batch|cand, then it becomes a point. */
function absorb(e) {
    if (
        e.coin !== focus.value.coin ||
        e.side !== focus.value.side ||
        e.status !== "done" ||
        !e.batch ||
        !["train", "test"].includes(e.window)
    )
        return;
    const key = `${e.batch}|${e.cand}`;
    const h = halves.get(key) || {
        ...e.params,
        cand: e.cand,
        batch: e.batch,
        at: e.at,
        train: null,
        test: null,
        trades: null,
        pf: null,
        dd: null,
    };
    h[e.window] = e.ret;
    if (e.window === "train") {
        h.trades = e.trades;
        h.pf = e.pf;
        h.dd = e.dd;
    }
    if (h.train !== null && h.test !== null) {
        halves.delete(key);
        completedPoints.set(key, h);
        if (completedPoints.size > 6000) completedPoints.delete(completedPoints.keys().next().value);
    } else {
        halves.set(key, h);
        if (halves.size > 6000) halves.delete(halves.keys().next().value);
    }
}
const rounds = ref([]);
const error = ref("");
const filter = ref({ coin: "", side: "", promoted: false, cash: "", tag: "" });
const cashOptions = computed(() =>
    [...new Set(rounds.value.map((r) => Number(r.cash)))]
        .filter((v) => !Number.isNaN(v))
        .sort((a, b) => a - b),
);
const tagOptions = computed(() =>
    [...new Set(rounds.value.map((r) => r.tag).filter(Boolean))].sort(),
);
const link = ref("connecting");
const feed = ref([]); // the firehose ticker, newest first, capped
const rate = ref(0); // backtests / s over the last 5 s
let timer, rateTimer, disconnectState, refreshTimer;
let loadInFlight = false;
let loadAgain = false;
let pending = []; // bounded feed commits at most four times per second
let feedTimer = 0;
let stamps = [];

function flush() {
    feedTimer = 0;
    if (document.hidden || disposed) return;
    if (!pending.length) return;
    const rows = new Map();
    for (const row of [...pending.reverse(), ...feed.value]) {
        const key = [row.k, row.id, row.at, row.coin, row.side, row.batch, row.cand, row.window].join(":");
        if (!rows.has(key)) rows.set(key, row);
        if (rows.size >= 400) break;
    }
    feed.value = [...rows.values()];
    pending = [];
}
function push(row) {
    pending.push(row);
    if (pending.length > 400) pending = pending.slice(-400);
    if (!feedTimer && !document.hidden) feedTimer = setTimeout(flush, 250);
}

// Toast lifetime: one slider drives every toast's ms via toastMs(kind, seconds); see toasts.js for the per-kind multipliers.
const toastSeconds = ref(
    Number(localStorage.getItem("optimizer.toastSeconds")) || 1.0,
);
watch(toastSeconds, (v) => localStorage.setItem("optimizer.toastSeconds", v));

// Champion-promotion voice announcement in Jeremy's cloned voice, generated by scripts/gen-champion-voice.sh.
const COIN_NAMES = {
    BTC: "Bitcoin",
    ETH: "Ethereum",
    SOL: "Solana",
    XRP: "XRP",
    LINK: "Chainlink",
    DOGE: "Dogecoin",
    ADA: "Cardano",
    AVAX: "Avalanche",
    SUI: "Sui",
    LTC: "Litecoin",
    ZEC: "Zcash",
    HYPE: "Hyperliquid",
    NEAR: "Near",
    BCH: "Bitcoin Cash",
    ENA: "Ethena",
    XLM: "Stellar",
    HBAR: "Hedera",
    ONDO: "Ondo",
};
const soundOn = ref(localStorage.getItem("optimizer.sound") === "1");
const audioQueue = [];
let queueBusy = false;
let currentAudio = null;
let currentUtterance = null;

function unlockAudio() {
    try {
        const a = new Audio(
            "data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=",
        );
        a.play().catch(() => {});
    } catch {}
}
function toggleSound() {
    soundOn.value = !soundOn.value;
    localStorage.setItem("optimizer.sound", soundOn.value ? "1" : "0");
    if (soundOn.value) unlockAudio();
    else stopAnnouncements();
}
function stopAnnouncements() {
    audioQueue.length = 0;
    if (currentAudio) { currentAudio.onended = null; currentAudio.onerror = null; currentAudio.pause(); currentAudio = null; }
    if (currentUtterance) { currentUtterance.onend = null; currentUtterance.onerror = null; window.speechSynthesis?.cancel(); currentUtterance = null; }
    queueBusy = false;
}
function finishAnnouncement() {
    currentAudio = null;
    currentUtterance = null;
    queueBusy = false;
    playQueue();
}
function speak(text) {
    if (!window.speechSynthesis || !soundOn.value || disposed) { finishAnnouncement(); return; }
    currentUtterance = new SpeechSynthesisUtterance(text);
    currentUtterance.onend = finishAnnouncement;
    currentUtterance.onerror = finishAnnouncement;
    window.speechSynthesis.speak(currentUtterance);
}
function playQueue() {
    if (disposed || !soundOn.value || queueBusy || !audioQueue.length) return;
    const { urls, text } = audioQueue.shift();
    queueBusy = true;
    const remaining = [...urls];
    const next = () => {
        if (disposed || !soundOn.value) return;
        const url = remaining.shift();
        if (!url) {
            speak(text);
            return;
        }
        const a = new Audio(url);
        currentAudio = a;
        a.onended = () => {
            currentAudio = null;
            queueBusy = false;
            playQueue();
        };
        a.onerror = next;
        a.play().catch(next);
    };
    next();
}
function announceChampion(e) {
    if (!soundOn.value) return;
    const ticker = (e.coin || "").replace(/-USD$/i, "").toUpperCase();
    const side = e.side === "short" ? "short" : "long";
    const slug = ticker.toLowerCase();
    const text = `OH HELL YA! ${COIN_NAMES[ticker] || ticker} ${side}, new champion!`;
    audioQueue.push({
        urls: [
            `/audio/champion/${slug}-${side}.mp3`,
            "/audio/champion/generic.mp3",
        ],
        text,
    });
    if (audioQueue.length > 20) audioQueue.splice(0, audioQueue.length - 20);
    playQueue();
}

function subscribe() {
    disconnectState = onConnectionState((s) => {
        link.value = s;
        if (s === "connected")
            toast(
                "link",
                "firehose connected",
                "",
                toastMs("link", toastSeconds.value),
            );
        else if (s === "unavailable" || s === "failed")
            toast(
                "error",
                "firehose lost",
                s,
                toastMs("error", toastSeconds.value),
            );
    });
    echo.channel("optimizer")
        .listen(".backtest.scored", (e) => {
            stamps.push(Date.now());
            stamps = stamps.filter((t) => t > Date.now() - 5000);
            rate.value = +(stamps.length / 5).toFixed(1);
            push({ k: e.status === "error" ? "error" : "backtest", ...e });
            absorb(e);
            if (e.status === "error")
                toast(
                    "error",
                    `${e.coin} ${e.side} backtest failed`,
                    e.error || "",
                    toastMs("error", toastSeconds.value),
                );
        })
        .listen(".round.scored", (e) => {
            push({ k: "round", ...e });
            const line = `${e.coin} ${e.side} · champ ${num(e.champion.train)}/${num(e.champion.test)} · best ${num(e.best.train)}/${num(e.best.test)}`;
            const kind = e.promoted ? "round" : "kept";
            toast(
                kind,
                `${e.coin} ${e.side} round: ${e.promoted ? "promoted" : "kept champion"}`,
                line,
                toastMs(kind, toastSeconds.value),
            );
            scheduleRefresh();
        })
        .listen(".champion.promoted", (e) => {
            push({ k: "promoted", ...e });
            const diff = Object.entries(e.diff)
                .map(
                    ([k, [a, b]]) =>
                        `${k.replace(/^[a-z]+\./, "")} ${a ?? "—"}→${b}`,
                )
                .join(" · ");
            const kind = e.side === "short" ? "short" : "promoted";
            toast(
                kind,
                `${e.coin} ${e.side} promoted ${num(e.train)} / ${num(e.test)}`,
                diff,
                toastMs(kind, toastSeconds.value),
            );
            announceChampion(e);
            scheduleRefresh();
        });
}

function commitCandidates() {
    if (document.hidden || disposed || !completedPoints.size) return;
    const combined = new Map(points.value.map((point) => [String(point.batch) + "|" + String(point.cand), point]));
    for (const [key, point] of completedPoints) combined.set(key, point);
    points.value = [...combined.values()].slice(-6000);
    completedPoints.clear();
}
function scheduleRefresh() {
    if (refreshTimer || disposed || document.hidden) return;
    refreshTimer = setTimeout(() => { refreshTimer = null; load(); }, 2000);
}
function visibilityChanged() {
    if (document.hidden) {
        clearTimeout(feedTimer); feedTimer = 0;
    } else { flush(); commitCandidates(); scheduleRefresh(); }
}
async function load() {
    if (disposed || document.hidden) return;
    if (loadInFlight) { loadAgain = true; return; }
    loadInFlight = true;
    try {
        const c = await api.get("/optimizer/champions");
        if (disposed) return;
        champions.value = c.coins;
        const q = new URLSearchParams({ limit: "150" });
        if (filter.value.coin) q.set("coin", filter.value.coin);
        if (filter.value.side) q.set("side", filter.value.side);
        if (filter.value.promoted) q.set("promoted", "1");
        if (filter.value.cash) q.set("cash", filter.value.cash);
        if (filter.value.tag) q.set("tag", filter.value.tag);
        const loadedRounds = await api.get("/optimizer/rounds?" + q.toString());
        if (disposed) return;
        rounds.value = loadedRounds;
        loaded.value = true;
        refreshedAt.value = new Date().toLocaleTimeString();
        error.value = "";
    } catch (e) {
        if (!disposed) error.value = e.message;
    } finally {
        loadInFlight = false;
        if (loadAgain) { loadAgain = false; scheduleRefresh(); }
    }
}
const withShorts = computed(
    () => champions.value.filter((c) => c.short).length,
);
const promotedToday = computed(
    () => rounds.value.filter((r) => r.promoted).length,
);
const num = (v, d = 2) =>
    v === null || v === undefined ? "—" : Number(v).toFixed(d);
const cls = (v) =>
    v === null || v === undefined
        ? "text-zinc-500"
        : Number(v) >= 0
          ? "text-emerald-400"
          : "text-red-400";
const tier = (v, hi, lo) =>
    v === null || v === undefined
        ? "text-zinc-500"
        : Number(v) >= hi
          ? "text-emerald-400"
          : Number(v) >= lo
            ? "text-amber-400"
            : "text-red-400";
const setLine = (s) =>
    s
        ? `${s.timeframe} · x1 ${s["engine.x1"]} · rng ${s["engine.base_minutes"]}m · TP ${s.tp_max_pct}%/${s.tp_rungs} · qty ${s.qty_pct}% · lev ${s.leverage}${s.tsl_pct > 0 ? ` · tsl ${s.tsl_pct}%` : ""}${s.ttp_activate_pct > 0 ? ` · ttp ${s.ttp_activate_pct}%/${s.ttp_giveback_pct}` : ""}`
        : "—";
const shortLine = (s) =>
    s
        ? `${setLine(s)} · stop ${s.short_stop_pct}% · gate ${s.short_trend_ma}`
        : "—";

const feedLine = (r) =>
    r.k === "backtest" || r.k === "error"
        ? `${r.window ?? ""} #${r.cand ?? ""} ${r.params?.tf ?? ""} x1 ${r.params?.x1 ?? ""} rng ${r.params?.base ?? ""} tp ${r.params?.tp ?? ""}/${r.params?.rungs ?? ""} stop ${r.params?.stop ?? "–"} gate ${r.params?.gate ?? "–"} lev ${r.params?.lev ?? ""} tsl ${r.params?.tsl ?? 0} ttp ${r.params?.ttp ?? 0}/${r.params?.ttpg ?? 0} → ${num(r.ret)}% ${r.trades ?? ""}t`
        : r.k === "round"
          ? `round ${r.candidates} cands · champ ${num(r.champion?.train)}/${num(r.champion?.test)} · best ${num(r.best?.train)}/${num(r.best?.test)} · calmar ${num(r.best?.calmar)} · dsr ${num(r.best?.dsr, 3)} · ${r.note}${r.cash && r.cash != 10000 ? ` · $${Math.round(r.cash / 1000)}k` : ""}${r.cache_hits ? ` · cache ${r.cache_hits}` : ""}${r.tag ? ` · #${r.tag}` : ""}`
          : `PROMOTED ${num(r.train)}/${num(r.test)} · ${Object.keys(r.diff || {}).length} keys changed`;

watch(focus, () => { points.value = []; pointsLoaded.value = false; halves.clear(); completedPoints.clear(); loadPoints(); }, { deep: true });
onMounted(() => {
    load();
    loadPoints();
    subscribe();
    document.addEventListener("visibilitychange", visibilityChanged);
    timer = setInterval(load, 30000);
    rateTimer = setInterval(() => {
        commitCandidates();
        stamps = stamps.filter((t) => t > Date.now() - 5000);
        rate.value = +(stamps.length / 5).toFixed(1);
    }, 1000);
});
onUnmounted(() => {
    disposed = true;
    stopAnnouncements();
    clearInterval(timer);
    clearInterval(rateTimer);
    disconnectState?.();
    echo.leave("optimizer");
    clearTimeout(feedTimer);
    clearTimeout(refreshTimer);
    document.removeEventListener("visibilitychange", visibilityChanged);
    completedPoints.clear(); halves.clear();
});
</script>

<template>
    <div class="smx-page smx-optimizer research-theater space-y-4">
        <div class="smx-page-toolbar research-heading">
            <PageHeading title="Optimizer" eyebrow="Continuous research" description="Explore the possibility space. Follow every candidate from hypothesis to champion." />
        </div>
        <section class="research-telemetry" aria-label="Research telemetry">
            <article class="research-metric"><span class="research-kicker">01 / Coverage</span><strong>{{ loaded ? champions.length : '—' }}<small>coins</small></strong><span>{{ loaded ? withShorts + ' with a short set' : 'Awaiting research snapshot' }}</span></article>
            <article class="research-metric"><span class="research-kicker">02 / Throughput</span><strong>{{ link === 'connected' ? rate : '—' }}<small>/ sec</small></strong><span class="research-connection" :class="{ 'is-connected': link === 'connected' }"><i></i>{{ link }} · observed last 5 seconds</span></article>
            <article class="research-metric"><span class="research-kicker">03 / Selection</span><strong>{{ loaded ? promotedToday : '—' }}<small>promoted</small></strong><span>{{ loaded ? 'Across ' + rounds.length + ' loaded rounds' : 'Waiting for round evidence' }}</span></article>
            <article class="research-metric"><span class="research-kicker">04 / Candidate field</span><strong>{{ pointsLoaded ? points.length.toLocaleString() : '—' }}</strong><span>{{ focus.coin }} · {{ focus.side }} · last 48 hours</span></article>
        </section>
        <div v-if="error || pointsError" class="research-alert" role="status"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i><div><strong>Research data {{ loaded ? 'refresh interrupted' : 'unavailable' }}</strong><p>{{ error || pointsError }}{{ loaded ? ' · Showing the last received snapshot.' : '' }}</p></div><button type="button" class="research-button" @click="load(); loadPoints()">Retry connection</button></div>
        <nav class="research-waypoints" aria-label="Optimizer sections"><a href="#candidate-field">Candidate field <span>↗</span></a><a href="#research-stream">Event firehose <span>↗</span></a><a href="#research-champions">Champions <span>↗</span></a><a href="#research-rounds">Round evidence <span>↗</span></a><span>Snapshot {{ refreshedAt || 'pending' }}</span></nav>

        <FarmPanel />

        <section id="research-stream" class="card research-stream !p-2">
            <div
                class="mb-1 flex flex-wrap items-center justify-between px-1 text-sm text-zinc-500"
            >
                <span
                    ><i
                        class="fa-solid fa-fire-flame-curved mr-1 text-sky-400"
                    ></i
                    >Event firehose — every score. Every selection.</span
                >
                <div class="flex flex-wrap items-center gap-3">
                    <select v-model="feedMode" aria-label="Filter event firehose"><option value="all">All events</option><option value="rounds">Rounds &amp; errors</option><option value="promotions">Promotions</option></select>
                    <button type="button" class="research-button" :aria-pressed="feedPaused" @click="toggleFeed">{{ feedPaused ? 'Resume stream' : 'Pause view' }}</button>
                    <button
                        type="button"
                        class="research-button" :aria-label="soundOn ? 'Mute champion announcements' : 'Enable champion announcements'" :aria-pressed="soundOn"
                        :title="
                            soundOn
                                ? 'champion voice: on'
                                : 'champion voice: off'
                        "
                        @click="toggleSound"
                    >
                        <i
                            v-if="soundOn"
                            key="sound-on"
                            class="fa-solid fa-volume-high text-emerald-400"
                        ></i>
                        <i
                            v-else
                            key="sound-off"
                            class="fa-solid fa-volume-xmark"
                        ></i>
                    </button>
                    <label class="flex items-center gap-1.5">
                        <span>toasts</span>
                        <input
                            type="range"
                            min="0.5"
                            max="5"
                            step="0.5"
                            v-model.number="toastSeconds"
                            class="h-1 w-20 accent-sky-400"
                        />
                        <span class="w-8 font-mono"
                            >{{ toastSeconds.toFixed(1) }}s</span
                        >
                    </label>
                    <span class="font-mono">{{ feed.length }} events</span>
                </div>
            </div>
            <div
                class="smx-firehose h-56 overflow-auto font-mono text-sm leading-6"
            >
                <div
                    v-for="r in visibleFeed"
                    :key="[r.k, r.id, r.at, r.coin, r.batch, r.cand, r.window].join(':')"
                    class="flex gap-2 whitespace-nowrap border-b border-zinc-900 px-1"
                    :class="
                        r.k === 'promoted'
                            ? 'bg-emerald-500/10 text-emerald-200'
                            : r.k === 'round'
                              ? 'text-sky-200'
                              : r.k === 'error'
                                ? 'text-red-300'
                                : 'text-zinc-400'
                    "
                >
                    <span class="w-20 shrink-0 text-zinc-600">{{
                        (r.at || "").slice(11, 19)
                    }}</span>
                    <span
                        class="w-28 shrink-0"
                        :class="
                            r.side === 'short'
                                ? 'text-red-300'
                                : 'text-zinc-200'
                        "
                        >{{ r.coin }} {{ r.side }}</span
                    >
                    <span class="truncate">{{ feedLine(r) }}</span>
                </div>
                <div
                    v-if="!visibleFeed.length"
                    class="research-empty"
                >
                    <div class="research-empty-orbit" aria-hidden="true"><i class="fa-solid fa-satellite-dish"></i></div><strong>{{ feedPaused ? 'Stream view paused' : 'Listening for research events' }}</strong><p>{{ publicDemo ? 'Simulated research scores arrive every second.' : link === 'connected' ? 'New scores and promotions appear here as they arrive.' : 'The event connection is ' + link + '. No activity is being simulated.' }}</p>
                </div>
            </div>
        </section>

        <div id="candidate-field" class="card research-field">
            <div
                class="mb-2 flex flex-wrap items-center gap-3 text-sm text-zinc-500"
            >
                <span
                    ><i class="fa-solid fa-chart-diagram mr-1 text-sky-400"></i
                    >Candidate field</span
                >
                <select aria-label="Candidate coin" v-model="focus.coin" class="text-sm">
                    <option
                        v-for="c in champions"
                        :key="c.coin"
                        :value="c.coin"
                    >
                        {{ c.coin }}
                    </option>
                </select>
                <select aria-label="Candidate direction" v-model="focus.side" class="text-sm">
                    <option value="long">long</option>
                    <option value="short">short</option>
                </select>
                <select aria-label="Candidate tag" v-model="focus.tag" class="text-sm">
                    <option value="">all tags</option>
                    <option v-for="t in tagOptions" :key="t" :value="t">
                        #{{ t }}
                    </option>
                </select>
                <span class="font-mono"
                    >{{ points.length }} candidates · last 48 h · dot size =
                    trades · diamond = champion</span
                >
            </div>
            <div v-if="!points.length" class="research-empty research-empty--field"><div class="research-empty-orbit" aria-hidden="true"><i class="fa-solid fa-chart-diagram"></i></div><strong>{{ pointsError ? 'Candidate evidence unavailable' : pointsLoaded ? 'An open field of possibility' : 'Acquiring candidate evidence' }}</strong><p>{{ pointsError ? 'Reconnect to load the actual train and test scores.' : pointsLoaded ? 'No paired candidates match this selection. Try another coin, direction or tag.' : 'Each point will represent a real paired train and test result.' }}</p></div>
            <div v-if="points.length" class="grid gap-3 xl:grid-cols-[3fr_2fr]">
                <OptimizerScatter
                    :points="points"
                    :champion="focusChampion"
                    :target="5"
                    :side="focus.side"
                />
                <div class="grid gap-2">
                    <div>
                        <div
                            class="text-sm uppercase tracking-widest text-zinc-500"
                        >
                            mean test % · timeframe × range filter
                        </div>
                        <OptimizerHeatmap
                            :points="points"
                            x="tf"
                            y="base"
                            x-label="timeframe"
                            y-label="range min"
                        />
                    </div>
                </div>
            </div>
            <div v-if="points.length" class="mt-3 grid gap-3 md:grid-cols-2">
                <div>
                    <div
                        class="text-sm uppercase tracking-widest text-zinc-500"
                    >
                        mean test % · take-profit ladder × rungs
                    </div>
                    <OptimizerHeatmap
                        :points="points"
                        x="tp"
                        y="rungs"
                        x-label="TP %"
                        y-label="rungs"
                    />
                </div>
                <div v-if="focus.side === 'short'">
                    <div
                        class="text-sm uppercase tracking-widest text-zinc-500"
                    >
                        mean test % · short stop × trend gate
                    </div>
                    <OptimizerHeatmap
                        :points="points"
                        x="stop"
                        y="gate"
                        x-label="stop %"
                        y-label="gate bars"
                    />
                </div>
                <div v-else>
                    <div
                        class="text-sm uppercase tracking-widest text-zinc-500"
                    >
                        mean test % · x1 × leverage
                    </div>
                    <OptimizerHeatmap
                        :points="points"
                        x="x1"
                        y="lev"
                        x-label="x1"
                        y-label="leverage"
                    />
                </div>
                <div>
                    <div
                        class="text-sm uppercase tracking-widest text-zinc-500"
                    >
                        mean test % · trailing stop × TTP arm
                    </div>
                    <OptimizerHeatmap
                        :points="points"
                        x="tsl"
                        y="ttp"
                        x-label="TSL %"
                        y-label="TTP arm %"
                    />
                </div>
            </div>
        </div>

        <div id="research-champions" class="card research-evidence">
            <div class="research-panel-heading">
                <span class="research-kicker">The selected few</span><h2>Champion archive</h2><p>Latest walk-forward train / test windows for each side.</p>
            </div>
            <div v-if="!champions.length" class="research-empty"><strong>{{ loaded ? 'No champions returned' : 'Champion evidence pending' }}</strong><p>Selected strategies will appear here when their data is available.</p></div>
            <div v-else class="overflow-auto">
                <div class="smx-table-scroll">
                    <table class="grid text-sm">
                        <thead>
                            <tr>
                                <th>coin</th>
                                <th>long set</th>
                                <th class="text-right">train</th>
                                <th class="text-right">test</th>
                                <th>short set</th>
                                <th class="text-right">train</th>
                                <th class="text-right">test</th>
                                <th>shorts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in champions" :key="c.coin">
                                <td class="font-mono">{{ c.coin }}</td>
                                <td class="font-mono text-sm text-zinc-300">
                                    {{ setLine(c.long) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(c.rounds.long?.train)"
                                >
                                    {{ num(c.rounds.long?.train) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(c.rounds.long?.test)"
                                >
                                    {{ num(c.rounds.long?.test) }}
                                </td>
                                <td
                                    class="font-mono text-sm"
                                    :class="
                                        c.short
                                            ? 'text-zinc-300'
                                            : 'text-zinc-600'
                                    "
                                >
                                    {{ shortLine(c.short) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(c.rounds.short?.train)"
                                >
                                    {{ num(c.rounds.short?.train) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(c.rounds.short?.test)"
                                >
                                    {{ num(c.rounds.short?.test) }}
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            c.shorts_enabled
                                                ? 'bg-emerald-500/20 text-emerald-300'
                                                : 'bg-zinc-700/40 text-zinc-400'
                                        "
                                        >{{
                                            c.shorts_enabled ? "on" : "off"
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="research-rounds" class="card research-evidence">
            <div
                class="mb-2 flex flex-wrap items-center gap-3 text-sm text-zinc-500"
            >
                <h2>Round evidence</h2>
                <select aria-label="Round coin filter" v-model="filter.coin" @change="load" class="text-sm">
                    <option value="">all coins</option>
                    <option
                        v-for="c in champions"
                        :key="c.coin"
                        :value="c.coin"
                    >
                        {{ c.coin }}
                    </option>
                </select>
                <select aria-label="Round side filter" v-model="filter.side" @change="load" class="text-sm">
                    <option value="">both sides</option>
                    <option value="long">long</option>
                    <option value="short">short</option>
                </select>
                <label class="flex items-center gap-1"
                    ><input
                        type="checkbox"
                        v-model="filter.promoted"
                        @change="load"
                    />
                    promoted only</label
                >
                <select aria-label="Round cash filter" v-model="filter.cash" @change="load" class="text-sm">
                    <option value="">all cash</option>
                    <option v-for="cv in cashOptions" :key="cv" :value="cv">
                        ${{ Math.round(cv / 1000) }}k
                    </option>
                </select>
                <select aria-label="Round tag filter" v-model="filter.tag" @change="load" class="text-sm">
                    <option value="">all tags</option>
                    <option v-for="t in tagOptions" :key="t" :value="t">
                        #{{ t }}
                    </option>
                </select>
            </div>
            <div v-if="!rounds.length" class="research-empty"><strong>{{ loaded ? 'No rounds match your filters' : 'Round evidence pending' }}</strong><p>Completed comparisons and promotion decisions will appear here.</p></div>
            <div v-else class="max-h-[32rem] overflow-auto">
                <div class="smx-table-scroll">
                    <table class="grid text-sm">
                        <thead>
                            <tr>
                                <th>when</th>
                                <th>coin</th>
                                <th>side</th>
                                <th class="text-right">cands</th>
                                <th class="text-right">champ train</th>
                                <th class="text-right">champ test</th>
                                <th class="text-right">best train</th>
                                <th class="text-right">best test</th>
                                <th class="text-right">calmar</th>
                                <th class="text-right">DSR</th>
                                <th class="text-right">plateau</th>
                                <th></th>
                                <th>note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in rounds"
                                :key="r.id"
                                :class="r.promoted ? 'bg-emerald-500/5' : ''"
                            >
                                <td class="whitespace-nowrap text-zinc-500">
                                    {{ fmt.time(r.created_at) }}
                                </td>
                                <td class="font-mono">{{ r.product_id }}</td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            r.side === 'short'
                                                ? 'bg-red-500/20 text-red-300'
                                                : 'bg-sky-500/20 text-sky-300'
                                        "
                                        >{{ r.side }}</span
                                    >
                                </td>
                                <td class="num text-right">
                                    {{ r.candidates }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(r.champion_train)"
                                >
                                    {{ num(r.champion_train) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(r.champion_test)"
                                >
                                    {{ num(r.champion_test) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(r.best_train)"
                                >
                                    {{ num(r.best_train) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(r.best_test)"
                                >
                                    {{ num(r.best_test) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="cls(r.best_calmar)"
                                >
                                    {{ num(r.best_calmar) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="tier(r.best_dsr, 0.95, 0.8)"
                                >
                                    {{ num(r.best_dsr, 3) }}
                                </td>
                                <td
                                    class="num text-right"
                                    :class="tier(r.best_plateau, 0.75, 0.5)"
                                >
                                    {{ num(r.best_plateau) }}
                                </td>
                                <td>
                                    <span
                                        v-if="r.promoted"
                                        class="badge bg-emerald-500/20 text-emerald-300"
                                        >promoted</span
                                    >
                                    <span
                                        v-if="Number(r.cash) !== 10000"
                                        class="badge bg-amber-500/20 text-amber-300"
                                        >${{ Math.round(r.cash / 1000) }}k</span
                                    >
                                    <span
                                        v-if="r.tag"
                                        class="badge bg-zinc-700/40 text-zinc-300"
                                        >#{{ r.tag }}</span
                                    >
                                </td>
                                <td
                                    class="max-w-[28rem] truncate text-zinc-400"
                                    :title="r.note"
                                >
                                    {{ r.note }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
