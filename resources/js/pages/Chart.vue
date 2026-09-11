<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, fmt } from "../api";
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
const positionState = ref("loading");
const decisionState = ref("loading");
const range = ref(null);
const root = ref(null), guardian = ref(null), quote = ref(null), effects = ref(true), focused = ref(false);
const hidden = ref(document.hidden), reduced = ref(false);
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
let widget = null;
let widgetReady = false;
let mountedSeries = '';
let applyingSeries = null;
let chartApi = null;
let volumeTask = null;
let cursorPixel = null;
const chartSubscriptions = [];

function historyError(reason) {
    if (typeof reason === 'string' && reason) return reason;
    if (typeof reason?.message === 'string' && reason.message) return reason.message;
    if (typeof reason?.errmsg === 'string' && reason.errmsg) return reason.errmsg;
    return 'Market history is temporarily unavailable.';
}
function createHistoryFeed() {
    const feed = new window.Datafeeds.UDFCompatibleDatafeed('/api/udf', 15000);
    const getBars = feed.getBars.bind(feed);
    // The bundled UDF adapter forwards rejected Error/undefined values directly.
    // TradingView's declared ErrorCallback requires a string (its study UI splits it).
    feed.getBars = (info, resolution, from, to, onResult, onError, first) => getBars(
        info, resolution, from, to,
        (bars, meta) => { if (alive) onResult(bars, meta); },
        (reason) => { if (alive) onError(historyError(reason)); }, first,
    );
    // ?backtest=N draws that backtest's simulated fills as B/S marks (strategy builder results).
    const backtestId = route.query.backtest;
    if (backtestId) {
        feed.getMarks = (symbolInfo, from, to, onDataCallback) => {
            const ticker = (symbolInfo.ticker || symbolInfo.name || '').replace(/^COINBASE:/, '');
            fetch(`/api/udf/marks?symbol=${encodeURIComponent(ticker)}&from=${from}&to=${to}&backtest_id=${encodeURIComponent(backtestId)}`)
                .then((r) => r.json()).then((marks) => { if (alive) onDataCallback(marks); }).catch(() => onDataCallback([]));
        };
    }
    return feed;
}
function removeVolumeStudy() {
    if (!chartApi) return;
    for (const study of chartApi.getAllStudies()) {
        if (study.name === 'Volume') chartApi.removeEntity(study.id);
    }
}
function ensureMinuteVolume() {
    if (!alive || !chartApi || applyingSeries || volumeTask || interval.value === '15S') return;
    if (chartApi.getAllStudies().some((study) => study.name === 'Volume')) return;
    const owner = chartApi;
    try {
        const task = Promise.resolve(owner.createStudy('Volume', false));
        volumeTask = task;
        task.then((id) => {
            if (alive && chartApi === owner && interval.value === '15S' && id !== null) owner.removeEntity(id);
        }).catch(() => {
            // Candles and their underlying volume remain usable if the optional study fails.
        }).finally(() => {
            if (volumeTask !== task) return;
            volumeTask = null;
            if (alive) updateChart();
        });
    } catch {}
}

function seriesKey(product, resolution) {
    return String(product).replace(/^(?:COINBASE|DEMO):/i, '').toUpperCase() + ':' + resolution;
}
function selectedSeries() {
    return seriesKey(symbol.value, interval.value);
}
function pushRange() {
    if (!alive || !chartApi || applyingSeries || mountedSeries !== selectedSeries()) return;
    try {
        // Range events can arrive while TradingView is replacing its main series.
        if (seriesKey(chartApi.symbol(), chartApi.resolution()) !== selectedSeries()) return;
        const currentRange = chartApi.getVisibleRange();
        if (Number.isFinite(currentRange?.from) && Number.isFinite(currentRange?.to) && currentRange.to > currentRange.from) {
            range.value = { from: currentRange.from, to: currentRange.to };
        }
    } catch {}
}

function trackCursor(event) {
    if (!alive || !chartApi || applyingSeries || mountedSeries !== selectedSeries() || !effectsActive.value) return;
    try {
        const pane = chartApi.getPanes()?.find(pane => pane.hasMainSeries());
        const scale = pane?.getMainSourcePriceScale();
        const target = cursorTarget(event, chartApi.getVisibleRange(), scale?.getVisiblePriceRange(), scale?.isInverted());
        if (target) guardian.value?.aim({ ...target, ...cursorPixel, paneHeight: pane?.getHeight() });
        else guardian.value?.leave();
    } catch { guardian.value?.leave(); }
}

function updateChart() {
    if (!alive || !widgetReady || !widget) return;
    const series = selectedSeries();
    if (series !== mountedSeries) range.value = null;
    // Keep one widget operation in flight and coalesce rapid choices to the latest.
    if (applyingSeries || volumeTask) return;
    if (series === mountedSeries) return;
    const owner = widget;
    const request = { series, symbol: symbol.value, interval: interval.value };
    applyingSeries = request;
    try {
        // This bundled chart engine cannot calculate the built-in Volume study
        // on second bars. Keep raw bar volume; restore its study on minute views.
        if (request.interval === '15S') removeVolumeStudy();
        owner.setSymbol(request.symbol, request.interval, () => {
            if (!alive || widget !== owner || applyingSeries !== request) return;
            applyingSeries = null;
            try {
                mountedSeries = seriesKey(chartApi.symbol(), chartApi.resolution());
                if (selectedSeries() !== request.series) {
                    updateChart();
                    pushRange();
                } else if (mountedSeries !== request.series) {
                    // A rejected/unknown series must not create an endless retry loop.
                    error.value = 'The chart could not load the selected market and timeframe.';
                } else {
                    pushRange();
                    ensureMinuteVolume();
                }
            } catch (e) {
                error.value = e.message || 'The chart could not switch markets.';
            }
        });
    } catch (e) {
        if (!alive || widget !== owner || applyingSeries !== request) return;
        applyingSeries = null;
        error.value = e.message || 'The chart could not switch markets.';
    }
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

function mount() {
    if (!alive || widget) return;
    if (!window.TradingView || !window.Datafeeds) {
        error.value =
            "The chart could not load. Refresh this page to try again.";
        return;
    }
    mountedSeries = selectedSeries();
    try {
    widget = new window.TradingView.widget({
        symbol: symbol.value,
        interval: interval.value,
        container_id: "tv_chart",
        datafeed: createHistoryFeed(),
        library_path: "/charting_library/",
        locale: "en",
        theme: "Dark",
        autosize: true,
        timezone: "Etc/UTC",
        enabled_features: ["seconds_resolution"],
        time_frames: [],
        loading_screen: { backgroundColor: "#000000", foregroundColor: "#59bfff" },
        disabled_features: [
            "use_localstorage_for_settings",
            "header_symbol_search",
            "header_compare",
            "timeframes_toolbar",
            "create_volume_indicator_by_default",
        ],
        overrides: {
            "paneProperties.background": "#000000",
            "paneProperties.vertGridProperties.color": "#102331",
            "paneProperties.horzGridProperties.color": "#102331",
            "scalesProperties.textColor": "#b4cee0",
            "scalesProperties.fontSize": 14,
            "mainSeriesProperties.candleStyle.upColor": "#59d5ff",
            "mainSeriesProperties.candleStyle.downColor": "#c887ee",
            "mainSeriesProperties.candleStyle.borderUpColor": "#80e5ff",
            "mainSeriesProperties.candleStyle.borderDownColor": "#db9cfa",
            "mainSeriesProperties.candleStyle.wickUpColor": "#59d5ff",
            "mainSeriesProperties.candleStyle.wickDownColor": "#c887ee",
        },
        studies_overrides: {},
    });
    } catch (e) {
        error.value = e.message || 'The chart could not initialize.';
        return;
    }
    const owner = widget;
    range.value = null;
    widget.onChartReady?.(() => {
        if (!alive || widget !== owner) return;
        try {
            chartApi = owner.chart();
            // Use visible pointer coordinates for exact alignment, including log scales
            // and resized study panes. The library supplies the price/time readout.
            const frameDocument = document.querySelector?.('#tv_chart iframe')?.contentDocument;
            if (frameDocument) {
                const move = event => { cursorPixel = { pixelX: event.clientX, pixelY: event.clientY }; };
                const leave = () => { cursorPixel = null; guardian.value?.leave(); };
                frameDocument.addEventListener('mousemove', move, true);
                frameDocument.addEventListener('mouseleave', leave);
                chartSubscriptions.push(() => {
                    frameDocument.removeEventListener('mousemove', move, true);
                    frameDocument.removeEventListener('mouseleave', leave);
                });
            }
            // This bundled API owns the crosshair callback until widget.remove().
            chartApi.crossHairMoved?.(trackCursor);
            mountedSeries = seriesKey(chartApi.symbol(), chartApi.resolution());
            const subscribe = (event, handler) => {
                event.subscribe(null, handler);
                chartSubscriptions.push(() => event.unsubscribe(null, handler));
            };
            subscribe(chartApi.onVisibleRangeChanged(), pushRange);
            subscribe(chartApi.onDataLoaded(), pushRange);
            subscribe(chartApi.onIntervalChanged(), (next) => {
                if (!alive || widget !== owner || applyingSeries || !TF_MAP[next]) return;
                // An older programmatic interval event must not reverse a newer click.
                if (selectedSeries() !== mountedSeries || chartApi.resolution() !== next) return;
                if (seriesKey(chartApi.symbol(), next) !== seriesKey(symbol.value, next)) return;
                mountedSeries = seriesKey(symbol.value, next);
                interval.value = next;
                if (next === '15S') removeVolumeStudy();
                else ensureMinuteVolume();
                range.value = null;
                pushRange();
            });
            widgetReady = true;
            updateChart();
            pushRange();
            ensureMinuteVolume();
        } catch (e) {
            if (alive && widget === owner) error.value = e.message || 'The chart could not initialize.';
        }
    });
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
    updateChart();
    loadSide();
});
watch(interval, updateChart);

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
    if (!alive) return;

});
onBeforeUnmount(() => {
    alive = false;
    motionPreference?.removeEventListener('change', motionChange);
    document.removeEventListener('visibilitychange', visibility);
    window.removeEventListener?.('keydown', keyboard);
    sideRequest++;
    applyingSeries = null;
    for (const unsubscribe of chartSubscriptions.splice(0)) {
        try { unsubscribe(); } catch {}
    }
    if (widget) {
        try {
            widget.remove();
        } catch {}
    }
    widget = null;
    chartApi = null;
    widgetReady = false;
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
                    <div id="tv_chart" class="min-h-0 flex-1"></div>
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
