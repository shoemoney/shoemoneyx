<script setup>
import { publicDemo } from "../demoMode";
import PageHeading from "../components/PageHeading.vue";
import { ref, onMounted, onUnmounted, watch, computed } from "vue";
import { useRouter } from "vue-router";
import { api, fmt } from "../api";
import VChart from "vue-echarts";
import { use } from "echarts/core";
import { CanvasRenderer } from "echarts/renderers";
import { LineChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    DataZoomComponent,
    MarkLineComponent,
} from "echarts/components";
import "../../css/research-controls.css";
import Pnl from "../components/Pnl.vue";
use([
    CanvasRenderer,
    LineChart,
    GridComponent,
    TooltipComponent,
    DataZoomComponent,
    MarkLineComponent,
]);

const props = defineProps({ id: { type: String, default: "" } });
const router = useRouter();
const PAGE_SIZE = 50;
const list = ref([]);
const nextCursor = ref(null);
const hasMore = ref(false);
const loadingMore = ref(false);
const current = ref(null);
const settings = ref(null);
const products = ref([]);
const error = ref("");
const form = ref({
    strategy: "mr",
    days: 30,
    cash: 1000,
    products: "",
    params: "",
});
const busy = ref(false);
const initialLoading = ref(true);
const historyFilter = ref("");
const chart = ref(null);
const reducedMotion = ref(false);
const zoomLabel = ref("Full window");
let zoomStart = 0,
    zoomEnd = 100;
let motionMedia;
const onMotionChange = (event) => {
    reducedMotion.value = event.matches;
};
const historyRows = computed(() =>
    list.value.filter((run) =>
        [run.id, run.strategy, run.status].some((value) =>
            String(value)
                .toLowerCase()
                .includes(historyFilter.value.trim().toLowerCase()),
        ),
    ),
);
const universe = computed(() =>
    form.value.products.trim()
        ? form.value.products
              .split(",")
              .map((value) => value.trim().toUpperCase())
              .filter(Boolean)
        : [],
);
const percent = (value) =>
    value === null || value === undefined ? "—" : fmt.pct(value);
const withUnit = (value, unit) =>
    value === null || value === undefined ? "—" : value + unit;
let timer;

// Interactive history list only: paginated, farm/optimizer rows excluded server-side. Polling the
// selected job (loadCurrent) never re-fetches this.
async function loadList() {
    try {
        const res = await api.get(`/backtests?limit=${PAGE_SIZE}`);
        list.value = res.data;
        nextCursor.value = res.next_cursor;
        hasMore.value = res.has_more;
    } catch (e) {
        error.value = e.message;
    }
}
async function loadMore() {
    if (!hasMore.value || loadingMore.value) return;
    loadingMore.value = true;
    try {
        const res = await api.get(
            `/backtests?limit=${PAGE_SIZE}&cursor=${nextCursor.value}`,
        );
        list.value = list.value.concat(res.data);
        nextCursor.value = res.next_cursor;
        hasMore.value = res.has_more;
    } catch (e) {
        error.value = e.message;
    }
    loadingMore.value = false;
}
async function loadCurrent() {
    if (!props.id) {
        current.value = null;
        return;
    }
    try {
        current.value = await api.get(`/backtests/${props.id}`);
    } catch (e) {
        error.value = e.message;
    }
}
async function load() {
    error.value = "";
    await Promise.all([loadList(), loadCurrent()]);
    initialLoading.value = false;
}
async function submit() {
    busy.value = true;
    error.value = "";
    try {
        const params = {};
        for (const line of form.value.params.split("\n")) {
            const eq = line.indexOf("=");
            if (eq === -1) continue;
            const k = line.slice(0, eq).trim();
            const rawVal = line.slice(eq + 1).trim();
            // Send the raw string untouched — App\Support\ParamNormalizer on the server knows each
            // knob's real type from its default (bool/int/float/string) and types it there. Guessing
            // client-side too would send an already-typed JSON bool/number for round strings like
            // "1", which the server treats as pre-typed and never re-checks against the knob's
            // actual default — exactly how mr.timeframe=1 used to end up as int/bool 1 instead of
            // the string '1'. rawVal can also legitimately be '' — an empty string is the
            // documented value for several string-typed knobs — which the server accepts
            // for a string-typed knob and rejects for a bool/numeric one, so don't drop the line.
            if (k) params[k] = rawVal;
        }
        const body = {
            strategy: form.value.strategy,
            days: Number(form.value.days),
            cash: Number(form.value.cash),
            params,
        };
        if (form.value.products.trim())
            body.products = form.value.products
                .split(",")
                .map((s) => s.trim().toUpperCase())
                .filter(Boolean);
        const bt = await api.post("/backtests", body);
        router.push(`/backtests/${bt.id}`);
        await load();
    } catch (e) {
        error.value = e.message;
    }
    busy.value = false;
}
const curve = computed(() =>
    (current.value?.equity_curve || []).map((p) => [p[0] * 1000, p[1]]),
);
const chartOption = computed(() => ({
    animation: !reducedMotion.value,
    animationDuration: 700,
    backgroundColor: "transparent",
    grid: { left: 10, right: 20, top: 30, bottom: 68, containLabel: true },
    tooltip: {
        trigger: "axis",
        confine: true,
        backgroundColor: "#030c14",
        borderColor: "#278abb",
        textStyle: { color: "#dbeeff", fontSize: 14 },
        axisPointer: { type: "cross", label: { backgroundColor: "#17374d" } },
        valueFormatter: (value) => fmt.usd(value),
    },
    xAxis: {
        type: "time",
        axisLine: { lineStyle: { color: "#28475c" } },
        axisTick: { show: false },
        axisLabel: { color: "#adc4d6", fontSize: 14, hideOverlap: true },
        splitLine: { show: false },
    },
    yAxis: {
        type: "value",
        scale: true,
        splitNumber: 4,
        axisLabel: {
            color: "#adc4d6",
            fontSize: 14,
            formatter: (value) =>
                Math.abs(value) >= 1000
                    ? "$" +
                      (value / 1000).toLocaleString(undefined, {
                          maximumFractionDigits: 1,
                      }) +
                      "k"
                    : fmt.usd(value, 0),
        },
        splitLine: { lineStyle: { color: "#21415866", type: "dashed" } },
    },
    dataZoom: [
        {
            type: "inside",
            start: zoomStart,
            end: zoomEnd,
            filterMode: "none",
            zoomOnMouseWheel: "ctrl",
            moveOnMouseWheel: false,
        },
        {
            type: "slider",
            start: zoomStart,
            end: zoomEnd,
            height: 27,
            bottom: 3,
            handleSize: 30,
            showDetail: false,
            borderColor: "#234b63",
            backgroundColor: "#020b13",
            fillerColor: "#1d9dff24",
            handleStyle: { color: "#248ec5", borderColor: "#93deff" },
            textStyle: { color: "#adc4d6" },
            dataBackground: {
                lineStyle: { color: "#248ec5" },
                areaStyle: { color: "#1679b333" },
            },
        },
    ],
    series: [
        {
            name: "Equity",
            type: "line",
            data: curve.value,
            showSymbol: curve.value.length === 1,
            symbolSize: 7,
            smooth: false,
            sampling: "lttb",
            lineStyle: {
                color: "#50c6ff",
                width: 2.5,
                shadowBlur: 12,
                shadowColor: "#159cff66",
            },
            itemStyle: { color: "#67d2ff" },
            areaStyle: {
                color: {
                    type: "linear",
                    x: 0,
                    y: 0,
                    x2: 0,
                    y2: 1,
                    colorStops: [
                        { offset: 0, color: "#219fff55" },
                        { offset: 1, color: "#219fff00" },
                    ],
                },
            },
            markLine:
                current.value?.starting_cash != null &&
                Number.isFinite(Number(current.value.starting_cash))
                    ? {
                          silent: true,
                          symbol: "none",
                          label: { show: false },
                          lineStyle: {
                              color: "#adc4d6",
                              type: "dashed",
                              opacity: 0.6,
                          },
                          data: [
                              {
                                  yAxis: Number(current.value.starting_cash),
                                  name: "Starting capital",
                              },
                          ],
                      }
                    : undefined,
        },
    ],
}));
function rememberZoom(event) {
    const range = event.batch?.[0] || event;
    if (Number.isFinite(range.start)) zoomStart = range.start;
    if (Number.isFinite(range.end)) zoomEnd = range.end;
    zoomLabel.value =
        zoomEnd - zoomStart >= 99.9
            ? "Full window"
            : Math.round(zoomEnd - zoomStart) + "% of window";
}
function zoom(direction) {
    const span =
        direction === 0
            ? 100
            : Math.min(
                  100,
                  Math.max(
                      2,
                      (zoomEnd - zoomStart) * (direction > 0 ? 0.6 : 1.65),
                  ),
              );
    const nextStart = Math.max(
        0,
        Math.min(100 - span, (zoomStart + zoomEnd - span) / 2),
    );
    chart.value?.dispatchAction({
        type: "dataZoom",
        start: nextStart,
        end: nextStart + span,
    });
    rememberZoom({ start: nextStart, end: nextStart + span });
}
watch(
    () => props.id,
    () => {
        zoomStart = 0;
        zoomEnd = 100;
        zoomLabel.value = "Full window";
        loadCurrent();
    },
);
onMounted(async () => {
    motionMedia = matchMedia("(prefers-reduced-motion: reduce)");
    reducedMotion.value = motionMedia.matches;
    motionMedia.addEventListener("change", onMotionChange);
    load();
    // Poll only the selected job's own status row — re-fetching the whole history list every 4s
    // while a job runs was the point of finding 19.
    timer = setInterval(() => {
        if (
            current.value &&
            ["queued", "running"].includes(current.value.status)
        )
            loadCurrent();
    }, 4000);
    try {
        settings.value = await api.get("/settings");
        form.value.strategy = settings.value.strategy;
        products.value = await api.get("/products");
    } catch {}
});
onUnmounted(() => {
    clearInterval(timer);
    motionMedia?.removeEventListener("change", onMotionChange);
});
</script>

<template>
    <div class="smx-page smx-backtests rc-page rc-research">
        <PageHeading
            class="smx-layout-heading"
            title="Backtests"
            eyebrow="Strategy research"
            description="Replay the market. Interrogate every decision."
        />
        <div class="rc-state-rail rc-full-width">
            <span class="rc-overline">RESEARCH / EXPERIMENT LAB</span
            ><span><i></i>Historical simulation</span
            ><span>{{ list.length }} loaded runs</span
            ><span v-if="current">Experiment #{{ current.id }}</span
            ><span v-else>No experiment selected</span>
        </div>
        <div
            v-if="error"
            class="rc-notice rc-notice-error rc-full-width"
            role="alert"
        >
            <span class="rc-notice-symbol" aria-hidden="true">!</span>
            <div>
                <strong>Research request failed</strong>
                <p>{{ error }}</p>
            </div>
            <button class="btn" @click="load">Retry</button>
        </div>
        <aside class="rc-research-sidebar">
            <section
                class="rc-panel rc-experiment-form"
                aria-labelledby="rc-experiment-title"
            >
                <div class="rc-panel-heading">
                    <span class="rc-section-no">01</span>
                    <div>
                        <span class="rc-overline">Define the hypothesis</span>
                        <h2 id="rc-experiment-title">New experiment</h2>
                    </div>
                </div>
                <label class="rc-field"
                    >Strategy<select v-model="form.strategy">
                        <option
                            v-if="!settings?.strategies?.length"
                            :value="form.strategy"
                        >
                            {{ form.strategy }}
                        </option>
                        <option
                            v-for="s in settings?.strategies || []"
                            :key="s.key"
                            :value="s.key"
                        >
                            {{ s.key }} — {{ s.name }}
                        </option>
                    </select></label
                >
                <div class="rc-form-duo">
                    <label class="rc-field"
                        >Lookback <span class="rc-field-unit">days</span
                        ><input
                            v-model="form.days"
                            type="number"
                            min="1"
                            max="365" /></label
                    ><label class="rc-field"
                        >Starting cash <span class="rc-field-unit">USD</span
                        ><input v-model="form.cash" type="number" min="10"
                    /></label>
                </div>
                <label class="rc-field"
                    >Product universe<input
                        v-model="form.products"
                        placeholder="BTC-USD,ETH-USD,SOL-USD"
                    /><small
                        >Leave blank for the top 20 tracked products.</small
                    ></label
                >
                <div
                    v-if="universe.length"
                    class="rc-product-tags"
                    aria-label="Selected products"
                >
                    <span v-for="(product, index) in universe" :key="index">{{
                        product
                    }}</span>
                </div>
                <label class="rc-field"
                    >Parameter overrides<textarea
                        v-model="form.params"
                        rows="4"
                        placeholder="risk.volume_ratio_close=0.3&#10;size.kelly_cap_pct=0.04"
                    ></textarea
                    ><small>One key=value per line.</small></label
                >
                <button
                    class="btn btn-primary rc-launch"
                    :disabled="publicDemo || busy"
                    @click="submit"
                >
                    <span aria-hidden="true">{{ busy ? "◌" : "▶" }}</span
                    >{{ busy ? "Queueing experiment…" : "Run backtest"
                    }}<span aria-hidden="true">↗</span>
                </button>
                <p class="rc-help">
                    Historical candles are prepared automatically. Follow
                    progress here while your experiment runs.
                </p>
            </section>
            <section
                class="rc-panel rc-history-panel"
                aria-labelledby="rc-history-title"
            >
                <div class="rc-panel-heading">
                    <span class="rc-section-no">02</span>
                    <div>
                        <span class="rc-overline">Research archive</span>
                        <h2 id="rc-history-title">Saved experiments</h2>
                    </div>
                    <span class="rc-count">{{ list.length }}</span>
                </div>
                <label v-if="list.length" class="rc-field rc-history-search"
                    ><span class="sr-only">Search experiment history</span
                    ><input
                        v-model="historyFilter"
                        type="search"
                        placeholder="Find strategy, status or ID…"
                /></label>
                <div class="rc-history-list">
                    <button
                        v-for="b in historyRows"
                        :key="b.id"
                        @click="router.push('/backtests/' + b.id)"
                        class="rc-run-button"
                        :class="{ 'rc-run-selected': current?.id === b.id }"
                        :aria-current="
                            current?.id === b.id ? 'true' : undefined
                        "
                    >
                        <span class="rc-run-top"
                            ><span class="rc-run-id">#{{ b.id }}</span
                            ><span
                                class="rc-status"
                                :class="'rc-status-' + b.status"
                                >{{ b.status }}</span
                            ></span
                        >
                        <strong>{{ b.strategy }}</strong
                        ><span class="rc-run-bottom"
                            ><span
                                >{{ b.products.length }} products ·
                                {{ withUnit(b.stats?.days, "d") }}</span
                            ><b
                                v-if="b.stats"
                                :class="
                                    b.stats.total_return_pct >= 0
                                        ? 'text-emerald-400'
                                        : 'text-red-400'
                                "
                                >{{ percent(b.stats.total_return_pct) }}</b
                            ></span
                        >
                    </button>
                    <p v-if="!historyRows.length" class="rc-history-empty">
                        {{
                            initialLoading
                                ? "Retrieving saved experiments…"
                                : error
                                  ? "History unavailable for this session."
                                  : historyFilter
                                    ? "No experiments match this search."
                                    : "Your completed and running experiments will appear here."
                        }}
                    </p>
                </div>
                <button
                    v-if="hasMore"
                    class="btn rc-load-more"
                    :disabled="loadingMore"
                    @click="loadMore"
                >
                    {{ loadingMore ? "Loading…" : "Load more experiments" }}
                </button>
            </section>
        </aside>
        <div class="smx-results rc-results">
            <template v-if="current">
                <header class="rc-panel rc-run-heading">
                    <div>
                        <span class="rc-overline"
                            >EXPERIMENT /
                            {{ String(current.id).padStart(4, "0") }}</span
                        >
                        <h2>
                            {{ current.strategy
                            }}<span
                                class="rc-status"
                                :class="'rc-status-' + current.status"
                                >{{ current.status }}</span
                            >
                        </h2>
                        <p>
                            {{ fmt.time(current.from) }}
                            <span class="rc-blue">→</span>
                            {{ fmt.time(current.to) }}
                        </p>
                    </div>
                    <div class="rc-product-tags">
                        <span
                            v-for="product in current.products"
                            :key="product"
                            >{{ product }}</span
                        >
                    </div>
                </header>
                <div
                    v-if="current.error"
                    class="rc-notice rc-notice-error"
                    role="alert"
                >
                    <span class="rc-notice-symbol" aria-hidden="true">!</span>
                    <div>
                        <strong>Experiment failed</strong>
                        <p>{{ current.error }}</p>
                    </div>
                </div>
                <div
                    v-if="['queued', 'running'].includes(current.status)"
                    class="rc-panel rc-job-progress"
                    role="status"
                >
                    <span class="rc-processing-orbit" aria-hidden="true"></span>
                    <div>
                        <span class="rc-overline">{{
                            current.status === "queued"
                                ? "AWAITING WORKER"
                                : "SIMULATION IN PROGRESS"
                        }}</span>
                        <h3>
                            {{
                                current.status === "queued"
                                    ? "Experiment queued"
                                    : "Replaying market history"
                            }}
                        </h3>
                        <p>
                            The selected experiment refreshes every four
                            seconds. Results appear when the worker reports
                            them.
                        </p>
                    </div>
                </div>
                <template v-if="current.stats">
                    <section
                        class="rc-panel rc-equity-panel"
                        aria-labelledby="rc-equity-title"
                    >
                        <div class="rc-equity-heading">
                            <div>
                                <span class="rc-overline"
                                    >CAPITAL / TRAJECTORY</span
                                >
                                <h2 id="rc-equity-title">Equity observatory</h2>
                                <p>
                                    Starting capital
                                    <strong>{{
                                        fmt.usd(current.starting_cash)
                                    }}</strong>
                                    <span class="rc-baseline-key"></span>
                                </p>
                            </div>
                            <div class="rc-equity-return">
                                <span>Total return</span
                                ><strong
                                    :class="
                                        current.stats.total_return_pct >= 0
                                            ? 'text-emerald-400'
                                            : 'text-red-400'
                                    "
                                    >{{
                                        percent(current.stats.total_return_pct)
                                    }}</strong
                                ><small
                                    >Ending equity
                                    {{ fmt.usd(current.ending_equity) }}</small
                                >
                            </div>
                        </div>
                        <div class="rc-chart-toolbar">
                            <span
                                >Hover to inspect ·
                                <span role="status">{{ zoomLabel }}</span></span
                            >
                            <div>
                                <button
                                    class="btn"
                                    @click="zoom(1)"
                                    :disabled="!curve.length"
                                    aria-label="Zoom in on backtest equity"
                                >
                                    + Zoom</button
                                ><button
                                    class="btn"
                                    @click="zoom(-1)"
                                    :disabled="!curve.length"
                                    aria-label="Zoom out on backtest equity"
                                >
                                    − Zoom</button
                                ><button
                                    class="btn"
                                    @click="zoom(0)"
                                    :disabled="!curve.length"
                                >
                                    Reset
                                </button>
                            </div>
                        </div>
                        <VChart
                            v-if="curve.length"
                            ref="chart"
                            :option="chartOption"
                            autoresize
                            class="rc-equity-chart"
                            aria-label="Interactive backtest equity over time, with dollar and timestamp tooltips"
                            @datazoom="rememberZoom"
                        />
                        <div v-else class="rc-chart-empty">
                            This experiment has not supplied an equity curve.
                        </div>
                    </section>
                    <section
                        class="rc-metrics-grid"
                        aria-label="Experiment statistics"
                    >
                        <article class="rc-panel rc-metric">
                            <span>Trades</span
                            ><strong>{{ current.stats.trades ?? "—" }}</strong
                            ><small
                                >{{ current.stats.wins ?? "—" }}W /
                                {{ current.stats.losses ?? "—" }}L</small
                            >
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Win rate</span
                            ><strong>{{
                                withUnit(current.stats.win_rate, "%")
                            }}</strong>
                            <div
                                class="rc-metric-meter"
                                v-if="current.stats.win_rate != null"
                                aria-hidden="true"
                            >
                                <i
                                    :style="{
                                        width:
                                            Math.min(
                                                100,
                                                Math.max(
                                                    0,
                                                    Number(
                                                        current.stats.win_rate,
                                                    ),
                                                ),
                                            ) + '%',
                                    }"
                                ></i>
                            </div>
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Profit factor</span
                            ><strong>{{
                                current.stats.profit_factor ?? "—"
                            }}</strong
                            ><small>Gross profit / gross loss</small>
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Max drawdown</span
                            ><strong class="text-red-400">{{
                                withUnit(current.stats.max_drawdown_pct, "%")
                            }}</strong
                            ><small>Peak to trough</small>
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Average win / loss</span
                            ><strong class="rc-metric-pair"
                                ><span class="text-emerald-400">{{
                                    percent(current.stats.avg_win_pct)
                                }}</span
                                ><span>/</span
                                ><span class="text-red-400">{{
                                    percent(current.stats.avg_loss_pct)
                                }}</span></strong
                            >
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Average hold</span
                            ><strong>{{
                                withUnit(current.stats.avg_hold_hours, "h")
                            }}</strong
                            ><small>Time in position</small>
                        </article>
                        <article class="rc-panel rc-metric">
                            <span>Candidates</span
                            ><strong>{{
                                current.stats.candidates ?? "—"
                            }}</strong
                            ><small
                                >Sized zero:
                                {{ current.stats.sized_zero ?? "—" }}</small
                            >
                        </article>
                    </section>
                    <section
                        class="rc-diagnostics-grid"
                        aria-label="Execution diagnostics"
                    >
                        <article class="rc-panel">
                            <div class="rc-panel-heading">
                                <span class="rc-section-no">03</span>
                                <div>
                                    <span class="rc-overline"
                                        >Position outcomes</span
                                    >
                                    <h3>Exit rules</h3>
                                </div>
                            </div>
                            <div
                                v-for="(n, k) in current.stats.exit_rules"
                                :key="k"
                                class="rc-rule-row"
                            >
                                <code>{{ k }}</code
                                ><strong>{{ n }}</strong>
                            </div>
                            <p
                                v-if="
                                    !Object.keys(current.stats.exit_rules || {})
                                        .length
                                "
                                class="rc-help"
                            >
                                No exit rules recorded.
                            </p>
                        </article>
                        <article class="rc-panel">
                            <div class="rc-panel-heading">
                                <span class="rc-section-no">04</span>
                                <div>
                                    <span class="rc-overline"
                                        >Decision boundaries</span
                                    >
                                    <h3>VET rejections</h3>
                                </div>
                            </div>
                            <div
                                v-for="(n, k) in current.stats.rejections"
                                :key="k"
                                class="rc-rule-row"
                            >
                                <code class="text-red-300">{{ k }}</code
                                ><strong>{{ n }}</strong>
                            </div>
                            <p
                                v-if="
                                    !Object.keys(current.stats.rejections || {})
                                        .length
                                "
                                class="rc-help"
                            >
                                No rejections recorded.
                            </p>
                        </article>
                    </section>
                    <details
                        v-if="
                            current.params && Object.keys(current.params).length
                        "
                        class="rc-panel rc-overrides-detail"
                    >
                        <summary>
                            Experiment overrides
                            <span
                                >{{
                                    Object.keys(current.params).length
                                }}
                                parameters</span
                            >
                        </summary>
                        <pre>{{ JSON.stringify(current.params, null, 2) }}</pre>
                    </details>
                </template>
                <section
                    class="rc-panel rc-trade-ledger"
                    v-if="current.trades?.length"
                >
                    <div class="rc-panel-heading">
                        <span class="rc-section-no">05</span>
                        <div>
                            <span class="rc-overline">Every execution</span>
                            <h2>Trade ledger</h2>
                        </div>
                        <span class="rc-count">{{
                            current.trades.length
                        }}</span>
                    </div>
                    <div class="smx-table-scroll rc-ledger-scroll">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Opened</th>
                                    <th>Closed</th>
                                    <th>Held</th>
                                    <th>USD</th>
                                    <th>Entry</th>
                                    <th>Exit</th>
                                    <th>P&amp;L</th>
                                    <th>Rule</th>
                                    <th>Scan reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(t, i) in current.trades" :key="i">
                                    <td class="font-semibold">
                                        {{ t.product }}
                                    </td>
                                    <td class="num text-zinc-400">
                                        {{ fmt.time(t.opened_at) }}
                                    </td>
                                    <td class="num text-zinc-400">
                                        {{ fmt.time(t.closed_at) }}
                                    </td>
                                    <td class="num">{{ t.held_hours }}h</td>
                                    <td class="num">{{ fmt.usd(t.usd) }}</td>
                                    <td class="num">{{ fmt.px(t.entry) }}</td>
                                    <td class="num">{{ fmt.px(t.exit) }}</td>
                                    <td>
                                        <Pnl
                                            :usd="t.pnl_usd"
                                            :pct="t.pnl_pct"
                                        />
                                    </td>
                                    <td class="font-mono text-zinc-400">
                                        {{ t.rule }}
                                    </td>
                                    <td class="text-zinc-500">{{ t.why }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
            <section
                v-else
                class="rc-panel rc-standby rc-research-standby"
                :aria-busy="initialLoading"
            >
                <div class="rc-orbital" aria-hidden="true">
                    <i></i><i></i><span>⌁</span><b></b>
                </div>
                <span class="rc-overline">THE NEXT HYPOTHESIS STARTS HERE</span>
                <h2>
                    {{
                        initialLoading
                            ? "Opening the research archive"
                            : error
                              ? "Research connection unavailable"
                              : "Turn conviction into evidence."
                    }}
                </h2>
                <p>
                    {{
                        initialLoading
                            ? "Retrieving your experiments and their recorded results."
                            : error
                              ? "The research service did not return data. Retry the connection to load saved experiments."
                              : "Choose a saved experiment to inspect its equity curve and every execution. Or define a new simulation in the experiment panel."
                    }}
                </p>
                <div class="rc-research-stages">
                    <div>
                        <b>01</b><strong>Configure</strong
                        ><span>Strategy, universe & capital</span>
                    </div>
                    <div>
                        <b>02</b><strong>Simulate</strong
                        ><span>Replay historical candles</span>
                    </div>
                    <div>
                        <b>03</b><strong>Investigate</strong
                        ><span>Equity, risk & trade outcomes</span>
                    </div>
                </div>
                <div class="rc-draft-readout">
                    <span class="rc-overline">CURRENT DRAFT</span
                    ><strong>{{ form.strategy }}</strong
                    ><span>{{ form.days }} days</span
                    ><span>{{ fmt.usd(Number(form.cash)) }}</span
                    ><span>{{
                        universe.length
                            ? universe.length + " products"
                            : "Top 20 tracked products"
                    }}</span>
                </div>
            </section>
        </div>
    </div>
</template>
