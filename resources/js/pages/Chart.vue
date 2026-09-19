<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from "vue";
import { createChart, CandlestickSeries, HistogramSeries, CrosshairMode, createSeriesMarkers } from "lightweight-charts";
import { deskHeaders } from '../api.js';
import { useRouter, useRoute } from "vue-router";
import { api, fmt } from "../api";
import { mapUdfHistory, mapUdfMarks } from "../components/chart/udfMapping";
import Pnl from "../components/Pnl.vue";
import SmxPanel from "../components/SmxPanel.vue";
import ChartMarketDeck from "../components/chart/ChartMarketDeck.vue";
import ChartGuardian from "../components/chart/ChartGuardian.vue";
import { cursorTarget } from "../components/chart/marketLens";
import "../../css/market-workspace.css";
import "../../css/chart-observatory.css";

const props = defineProps({ symbol: { type: String, default: "" } });
const router = useRouter();
const route = useRoute();
const products = ref([]);
const symbol = ref(props.symbol || "BTC-USD");
const interval = ref("1");
const position = ref(null);
const candidates = ref([]);
const error = ref("");
const loading = ref(true);
const positionState = ref("loading");
const decisionState = ref("loading");
const range = ref(null);
const root = ref(null), guardian = ref(null), quote = ref(null), effects = ref(true), focused = ref(false);
const hidden = ref(document.hidden), reduced = ref(false);
const chartContainer = ref(null);
const effectsActive = computed(() => effects.value && !hidden.value && !reduced.value);
let motionPreference;
function visibility() { hidden.value = document.hidden; }
function motionChange(event) { reduced.value = event.matches; }
function keyboard(event) { if (event.key === 'Escape') focused.value = false; }
function toggleFocus() {
    focused.value = !focused.value;
    if (focused.value) nextTick(() => { if (alive) root.value?.scrollIntoView({ block: 'start', behavior: reduced.value ? 'auto' : 'smooth' }); });
}
let alive = true;
let sideRequest = 0;
const TF_MAP = { '15S': '15s', '1': '1m', '5': '5m' };
const SPAN_SECONDS = { '15S': 15, '1': 60, '5': 300 };
const BAR_WINDOW = 1500;
let chart = null;
let candleSeries = null;
let volumeSeries = null;
let markers = null;
let seriesRequest = 0;
let renderedSeries = '';
let lastBarTime = null;
let pollTimer = null;
const chartSubscriptions = [];

function seriesKey(product, resolution) {
    return String(product).replace(/^(?:COINBASE|DEMO):/i, '').toUpperCase() + ':' + resolution;
}
function selectedSeries() {
    return seriesKey(symbol.value, interval.value);
}

async function fetchHistory(sym, resolution, from, to) {
    const params = new URLSearchParams({ symbol: sym, resolution, from: String(from), to: String(to), countback: String(BAR_WINDOW) });
    const response = await fetch(`/api/udf/history?${params}`, { headers: deskHeaders() });
    if (!response.ok) throw new Error('Market history is temporarily unavailable.');
    return response.json();
}
async function fetchMarks(sym, from, to) {
    const params = new URLSearchParams({ symbol: sym, from: String(from), to: String(to) });
    const backtestId = route.query.backtest;
    if (backtestId) params.set('backtest_id', String(backtestId));
    const response = await fetch(`/api/udf/marks?${params}`, { headers: deskHeaders() });
    if (!response.ok) return null;
    return response.json();
}

async function loadMarks(id, sym, from, to) {
    try {
        const payload = await fetchMarks(sym, from, to);
        if (!alive || id !== seriesRequest || !markers) return;
        markers.setMarkers(mapUdfMarks(payload));
    } catch {
        // Marks are a supplement to the candles; a failed fetch leaves the chart usable.
    }
}

function pushRange(visible) {
    if (!alive || !visible || renderedSeries !== selectedSeries()) return;
    if (Number.isFinite(visible.from) && Number.isFinite(visible.to) && visible.to > visible.from) {
        range.value = { from: visible.from, to: visible.to };
    }
}

function trackCursor(param) {
    if (!alive || !chart || !candleSeries || renderedSeries !== selectedSeries() || !effectsActive.value) {
        guardian.value?.leave();
        return;
    }
    try {
        if (!param.point || param.time === undefined) { guardian.value?.leave(); return; }
        const price = candleSeries.coordinateToPrice(param.point.y);
        if (price === null) { guardian.value?.leave(); return; }
        const timeRange = chart.timeScale().getVisibleRange();
        const priceRange = chart.priceScale('right').getVisibleRange();
        const target = cursorTarget({ time: param.time, price }, timeRange, priceRange, false);
        if (target) guardian.value?.aim({ ...target, pixelX: param.point.x, pixelY: param.point.y, paneHeight: chartContainer.value?.clientHeight });
        else guardian.value?.leave();
    } catch { guardian.value?.leave(); }
}

async function loadSeries() {
    if (!alive || !candleSeries) return;
    const key = selectedSeries();
    const id = ++seriesRequest;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    range.value = null;
    error.value = '';
    loading.value = true;
    const requestedSymbol = symbol.value, requestedInterval = interval.value;
    const to = Math.floor(Date.now() / 1000);
    const from = to - BAR_WINDOW * (SPAN_SECONDS[requestedInterval] || 60);
    try {
        const payload = await fetchHistory(requestedSymbol, requestedInterval, from, to);
        if (!alive || id !== seriesRequest) return;
        const { candles, volumes } = mapUdfHistory(payload);
        candleSeries.setData(candles);
        volumeSeries.setData(volumes);
        renderedSeries = key;
        lastBarTime = candles.at(-1)?.time ?? null;
        loading.value = false;
        pushRange(chart?.timeScale().getVisibleRange());
        loadMarks(id, requestedSymbol, from, to);
        pollTimer = setInterval(() => refreshLatest(id, requestedSymbol, requestedInterval), 15000);
    } catch (e) {
        if (!alive || id !== seriesRequest) return;
        loading.value = false;
        error.value = e.message || 'The chart could not load the selected market and timeframe.';
    }
}

async function refreshLatest(id, requestedSymbol, requestedInterval) {
    if (!alive || id !== seriesRequest || !candleSeries) return;
    const to = Math.floor(Date.now() / 1000);
    const from = (lastBarTime ?? to) - SPAN_SECONDS[requestedInterval] * 2;
    try {
        const payload = await fetchHistory(requestedSymbol, requestedInterval, from, to);
        if (!alive || id !== seriesRequest) return;
        const { candles, volumes } = mapUdfHistory(payload);
        for (let i = 0; i < candles.length; i++) {
            if (candles[i].time < (lastBarTime ?? -Infinity)) continue;
            candleSeries.update(candles[i]);
            volumeSeries.update(volumes[i]);
            lastBarTime = candles[i].time;
        }
        loadMarks(id, requestedSymbol, from, to);
    } catch {
        // A missed poll tick just waits for the next one; it never surfaces as a page error.
    }
}

function chartOptions() {
    return {
        autoSize: true,
        layout: { background: { color: '#09090b' }, textColor: '#a1a1aa', fontSize: 12 },
        grid: { vertLines: { color: '#27272a' }, horzLines: { color: '#27272a' } },
        crosshair: {
            mode: CrosshairMode.Normal,
            vertLine: { color: '#f59e0b', labelBackgroundColor: '#f59e0b' },
            horzLine: { color: '#f59e0b', labelBackgroundColor: '#f59e0b' },
        },
        rightPriceScale: { borderColor: '#27272a' },
        timeScale: { borderColor: '#27272a', timeVisible: true, secondsVisible: interval.value === '15S' },
    };
}

function mount() {
    if (!alive || chart) return;
    try {
        chart = createChart(chartContainer.value, chartOptions());
        candleSeries = chart.addSeries(CandlestickSeries, {
            upColor: '#22c55e', downColor: '#ef4444',
            borderUpColor: '#22c55e', borderDownColor: '#ef4444',
            wickUpColor: '#22c55e', wickDownColor: '#ef4444',
        });
        volumeSeries = chart.addSeries(HistogramSeries, { priceFormat: { type: 'volume' }, priceScaleId: 'volume' });
        volumeSeries.priceScale().applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });
        markers = createSeriesMarkers(candleSeries, []);
        chart.subscribeCrosshairMove(trackCursor);
        chart.timeScale().subscribeVisibleTimeRangeChange(pushRange);
        chartSubscriptions.push(() => chart.unsubscribeCrosshairMove(trackCursor));
        chartSubscriptions.push(() => chart.timeScale().unsubscribeVisibleTimeRangeChange(pushRange));
    } catch (e) {
        error.value = e.message || 'The chart could not initialize.';
        return;
    }
    loadSeries();
}

async function loadSide() {
    if (!alive) return;
    const id = ++sideRequest;
    const requestedSymbol = symbol.value;
    try {
        const [positionResult, candidateResult] = await Promise.allSettled([
            api.get("/positions?status=all&limit=200"),
            api.get(`/candidates?product=${requestedSymbol}&per_page=8`),
        ]);
        if (!alive || id !== sideRequest) return;
        if (positionResult.status === 'fulfilled') {
        const pos = positionResult.value;
        position.value =
            pos.find(
                (p) => p.product_id === requestedSymbol && p.status === "open",
            ) ||
            pos.find((p) => p.product_id === requestedSymbol) ||
            null;
        positionState.value = 'ready';
        } else {
            positionState.value = 'unavailable';
        }
        if (candidateResult.status === 'fulfilled') {
            candidates.value = candidateResult.value.data || [];
            decisionState.value = 'ready';
        } else {
            decisionState.value = 'unavailable';
        }
        const failures = [positionResult, candidateResult].filter((result) => result.status === 'rejected');
        if (failures.length) error.value = failures.map((result) => result.reason.message).join(' · ');
    } catch (e) {
        if (alive && id === sideRequest) error.value = e.message;
    }
}

function go(sym) {
    symbol.value = sym;
    router.replace(`/chart/${sym}`);
}

watch(
    () => props.symbol,
    (next) => {
        const value = next || products.value[0]?.product_id || "BTC-USD";
        if (symbol.value !== value) symbol.value = value;
    },
);
watch(symbol, () => {
    quote.value = null;
    guardian.value?.leave();
    position.value = null;
    candidates.value = [];
    positionState.value = 'loading';
    decisionState.value = 'loading';
    error.value = '';
    loadSeries();
    loadSide();
});
watch(interval, loadSeries);

onMounted(async () => {
    motionPreference = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    reduced.value = motionPreference?.matches || false;
    motionPreference?.addEventListener('change', motionChange);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener?.('keydown', keyboard);
    mount();
    loadSide();
    try {
        const result = await api.get("/products");
        if (alive) products.value = result;
    } catch (e) {
        if (alive) error.value = e.message;
    }
});
onBeforeUnmount(() => {
    alive = false;
    motionPreference?.removeEventListener('change', motionChange);
    document.removeEventListener('visibilitychange', visibility);
    window.removeEventListener?.('keydown', keyboard);
    sideRequest++;
    seriesRequest++;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    for (const unsubscribe of chartSubscriptions.splice(0)) {
        try { unsubscribe(); } catch {}
    }
    if (chart) {
        try { chart.remove(); } catch {}
    }
    chart = null;
    candleSeries = null;
    volumeSeries = null;
    markers = null;
});
</script>

<template>
    <div ref="root" class="smx-page smx-chart cl-observatory flex flex-col" :class="{ 'cl-focus': focused, 'cl-is-still': !effectsActive }">
        <header class="cl-heading"><div><span class="cl-eyebrow">04 / MARKET INTELLIGENCE</span><h1>Read the <em>field.</em></h1><p>Every candle. Every move. In your sights.</p></div><span class="cl-heading-mark" aria-hidden="true"><i></i><i></i><b>CHART<br>OBSERVATORY</b></span></header>
        <ChartMarketDeck :symbol="symbol" :products="products" :active="effectsActive && !focused" @select="go" @quote="quote = $event" />
        <div
            class="smx-chart-toolbar flex flex-wrap items-center gap-2 border-b border-zinc-800 px-4 py-2"
        >
            <select :value="symbol" aria-label="Select market" @change="go($event.target.value)">
                <option
                    v-for="p in products"
                    :key="p.product_id"
                    :value="p.product_id"
                >
                    {{ p.product_id }} · {{ fmt.usd(p.volume_24h_usd, 0) }} ·
                    {{ fmt.pct(p.price_change_24h_pct, 1) }}
                </option>
                <option
                    v-if="!products.some((p) => p.product_id === symbol)"
                    :value="symbol"
                >
                    {{ symbol }}
                </option>
            </select>
            <div class="smx-chart-timeframes flex flex-wrap gap-1">
                <button
                    v-for="i in [['15S', '15s'], ['1', '1m'], ['5', '5m']]"
                    :key="i[0]"
                    class="btn !px-2"
                    :class="interval === i[0] ? 'btn-primary' : ''"
                    :aria-pressed="interval === i[0]"
                    @click="interval = i[0]"
                >
                    {{ i[1] }}
                </button>
            </div>
            <div class="cl-chart-actions">
                <button class="cl-effect-toggle" :aria-pressed="effects" @click="effects = !effects"><i class="fa-solid fa-bolt" aria-hidden="true"></i>{{ effects ? (reduced ? 'Flare · still' : 'Flare 50') : 'Flare off' }}</button>
                <button :aria-pressed="focused" @click="toggleFocus"><i :class="focused ? 'fa-solid fa-compress' : 'fa-solid fa-expand'" aria-hidden="true"></i>{{ focused ? 'Exit focus' : 'Focus chart' }}</button>
            </div>
        </div>
        <div v-if="error" class="mw-error" role="alert">
            Some market information is unavailable. {{ error }}
        </div>
        <div class="smx-chart-layout">
            <div class="smx-chart-main flex min-w-0 flex-1 flex-col">
                <div class="cl-stage-label"><span><i></i>{{ symbol }} <b>/ {{ TF_MAP[interval] }}</b></span><span>B / S marks show your fills</span></div>
                <div class="cl-chart-viewport" @pointerleave="guardian?.leave()">
                    <div v-if="loading" class="cl-chart-loading">Loading chart…</div>
                    <div ref="chartContainer" class="min-h-0 flex-1"></div>
                    <ChartGuardian ref="guardian" :symbol="symbol" :active="effectsActive" :quote="quote" :class="{ 'cl-guardian-muted': !effects }" />
                </div>
                <SmxPanel
                    :symbol="symbol"
                    :tf="TF_MAP[interval] || '1m'"
                    :range="range"
                    :height="170"
                />
            </div>
            <aside
                class="smx-chart-side w-72 shrink-0 space-y-3 overflow-auto border-l border-zinc-800 p-3 text-sm"
            >
                <div class="card !p-3 mw-position-instrument">
                    <div class="mb-1 text-sm uppercase text-zinc-500">
                        01 / Position intelligence
                    </div>
                    <template v-if="position">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">status</span
                            ><span
                                class="badge"
                                :class="
                                    position.status === 'open'
                                        ? 'bg-emerald-500/20 text-emerald-300'
                                        : 'bg-zinc-700 text-zinc-300'
                                "
                                >{{ position.status }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">qty</span
                            ><span class="num">{{
                                fmt.num(position.quantity, 6)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">entry</span
                            ><span class="num">{{
                                fmt.px(position.entry_price)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">cost</span
                            ><span class="num">{{
                                fmt.usd(position.entry_usd)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">pnl</span
                            ><Pnl
                                :usd="
                                    position.status === 'open'
                                        ? position.unrealised_pnl
                                        : position.pnl_usd
                                "
                                :pct="
                                    position.status === 'open'
                                        ? position.unrealised_pnl_pct
                                        : position.pnl_pct
                                "
                            />
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">held</span
                            ><span class="num">{{
                                fmt.mins(position.held_minutes)
                            }}</span>
                        </div>
                        <div
                            v-if="position.close_rule"
                            class="flex justify-between"
                        >
                            <span class="text-zinc-500">closed by</span
                            ><span class="font-mono">{{
                                position.close_rule
                            }}</span>
                        </div>
                    </template>
                    <div v-else class="text-zinc-600">
                        {{ positionState === 'loading' ? 'Loading position…' : positionState === 'unavailable' ? 'Position information is unavailable.' : `No position in ${symbol}.` }}
                    </div>
                </div>
                <div class="card !p-3">
                    <div class="mb-1 text-sm uppercase text-zinc-500">
                        02 / Recent decisions
                    </div>
                    <div
                        v-for="c in candidates"
                        :key="c.id"
                        class="border-b border-zinc-800/60 py-1.5 last:border-0"
                    >
                        <div class="flex justify-between">
                            <span class="text-zinc-500"
                                >{{ fmt.ago(c.created_at) }} ago · #{{
                                    c.rank
                                }}</span
                            ><span
                                class="badge"
                                :class="
                                    c.verdict === 'REJECT'
                                        ? 'bg-red-500/20 text-red-300'
                                        : 'bg-emerald-500/20 text-emerald-300'
                                "
                                >{{ c.verdict || "—" }}</span
                            >
                        </div>
                        <div class="text-zinc-400">
                            {{
                                c.failed_check
                                    ? "[" + c.failed_check + "] "
                                    : ""
                            }}{{ c.why || c.rank_reason }}
                        </div>
                    </div>
                    <div v-if="!candidates.length" class="text-zinc-600">
                        {{ decisionState === 'loading' ? 'Loading decisions…' : decisionState === 'unavailable' ? 'Decision history is unavailable.' : 'No recent decisions for this market.' }}
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.cl-chart-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #a1a1aa;
    font-size: 0.875rem;
    pointer-events: none;
    z-index: 1;
}
</style>
