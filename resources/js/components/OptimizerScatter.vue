<script setup>
import { computed, ref, shallowRef, watch, onMounted, onBeforeUnmount } from "vue";
import VChart from "vue-echarts";
import { use } from "echarts/core";
import { CanvasRenderer } from "echarts/renderers";
import { ScatterChart, LineChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
    MarkLineComponent,
    MarkPointComponent,
    DataZoomComponent,
} from "echarts/components";

use([
    CanvasRenderer,
    ScatterChart,
    LineChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    MarkLineComponent,
    MarkPointComponent,
    DataZoomComponent,
]);

const props = defineProps({
    points: { type: Array, default: () => [] }, // [{train, test, tf, x1, base, tp, rungs, stop, gate, lev, qty, trades, ...}]
    champion: { type: Object, default: null }, // {train, test}
    target: { type: Number, default: 5 }, // promotion gate on the train axis
    side: { type: String, default: "long" },
});


// Keep every measurement, but spend chart work only where the instrument is visible.
const plotHost = ref(null);
const plotReady = ref(false);
const plotPoints = shallowRef([]);
const plotChampion = shallowRef(null);
let plotVisible = false;
let plotObserver;
let plotTimer = null;
let plotDisposed = false;
function syncPlot() {
    plotTimer = null;
    if (plotDisposed || !plotVisible || document.hidden) return;
    plotPoints.value = props.points;
    plotChampion.value = props.champion;
    plotReady.value = true;
}
function schedulePlot() {
    if (!plotDisposed && plotVisible && !document.hidden && !plotTimer) plotTimer = setTimeout(syncPlot, 500);
}
function plotVisibilityChanged() {
    if (document.hidden) { clearTimeout(plotTimer); plotTimer = null; }
    else syncPlot();
}
watch(() => [props.points, props.champion], schedulePlot);
onMounted(() => {
    document.addEventListener("visibilitychange", plotVisibilityChanged);
    if (typeof IntersectionObserver === "undefined") { plotVisible = true; syncPlot(); return; }
    plotObserver = new IntersectionObserver(([entry]) => {
        plotVisible = entry.isIntersecting;
        if (plotVisible) syncPlot();
        else { clearTimeout(plotTimer); plotTimer = null; }
    }, { rootMargin: "100px" });
    plotObserver.observe(plotHost.value);
});
onBeforeUnmount(() => {
    plotDisposed = true;
    clearTimeout(plotTimer);
    plotObserver?.disconnect();
    document.removeEventListener("visibilitychange", plotVisibilityChanged);
});

// Three timeframe classes: the scatter palette validates all-pairs only up to three categorical slots.
const CLASSES = [
    {
        key: "sub",
        label: "≤ 90 s",
        color: "#3987e5",
        test: (tf) => /s$/.test(tf),
    },
    {
        key: "min",
        label: "1–3 m",
        color: "#b494ff",
        test: (tf) => /^(1|2|3)m$/.test(tf),
    },
    {
        key: "multi",
        label: "4–15 m",
        color: "#68dac3",
        test: (tf) => /^(4|5|10|15)m$/.test(tf),
    },
];
const classOf = (tf) => CLASSES.find((c) => c.test(String(tf))) || CLASSES[1];
const line = (p) =>
    `${p.tf} · x1 ${p.x1} · rng ${p.base}m · TP ${p.tp}/${p.rungs} · stop ${p.stop ?? "–"} · gate ${p.gate ?? "–"} · lev ${p.lev} · qty ${p.qty}%`;

const chart = ref(null);
const plotInitOptions = { devicePixelRatio: Math.min(window.devicePixelRatio || 1, 1.5) };
let zoomWindow = { start: 0, end: 100 };
function onZoom(event) {
    const range = event.batch?.[0] ?? event;
    if (Number.isFinite(range.start) && Number.isFinite(range.end)) zoomWindow = { start: range.start, end: range.end };
}
function changeZoom(direction) {
    const mid = (zoomWindow.start + zoomWindow.end) / 2;
    const width = Math.max(10, Math.min(100, zoomWindow.end - zoomWindow.start + direction * 20));
    const start = Math.max(0, Math.min(100 - width, mid - width / 2));
    chart.value?.dispatchAction({ type: "dataZoom", start, end: start + width });
}
function resetZoom() { chart.value?.dispatchAction({ type: "dataZoom", start: 0, end: 100 }); }
const option = computed(() => {
    const series = CLASSES.map((c) => ({
        name: c.label,
        type: "scatter",
        large: plotPoints.value.length > 2000,
        largeThreshold: 2000,
        symbolSize: (v) => Math.max(6, Math.min(18, 6 + (v[2] || 0) / 8)), // trades → size, 6..18 px
        itemStyle: {
            color: c.color,
            opacity: 0.75,
            borderColor: "#18181b",
            borderWidth: 1,
        },
        emphasis: { itemStyle: { opacity: 1, borderColor: "#fff" } },
        data: plotPoints.value
            .filter((p) => classOf(p.tf).key === c.key)
            .map((p) => [p.train, p.test, p.trades || 0, p]),
    }));
    const marks = {
        type: "scatter",
        name: "champion",
        symbol: "diamond",
        symbolSize: 20,
        z: 10,
        itemStyle: { color: "#fff", borderColor: "#18181b", borderWidth: 2 },
        label: {
            show: true,
            formatter: "champion",
            position: "top",
            color: "#e4f4ff",
            fontSize: 13,
        },
        data:
            plotChampion.value && plotChampion.value.train !== null
                ? [
                      [
                          plotChampion.value.train,
                          plotChampion.value.test,
                          0,
                          { champion: true },
                      ],
                  ]
                : [],
        markLine: {
            silent: true,
            symbol: "none",
            lineStyle: { color: "#87a0b5", type: "dashed", width: 1 },
            label: {
                color: "#a9bfd2",
                fontSize: 13,
                position: "insideEndTop",
                formatter: ({ value, name }) => name,
            },
            data: [
                { xAxis: props.target, name: `train ≥ ${props.target}%` },
                { yAxis: 0, name: "test > 0" },
            ],
        },
    };
    return {
        backgroundColor: "#000000",
        animation: false,
        grid: { left: 58, right: 24, top: 44, bottom: 52 },
        legend: {
            top: 0,
            right: 0,
            textStyle: { color: "#a9bfd2", fontSize: 13 },
            itemWidth: 12,
            itemHeight: 12,
            data: CLASSES.map((c) => c.label),
        },
        tooltip: {
            trigger: "item",
            confine: true,
            backgroundColor: "#04090e",
            borderColor: "#23465f",
            extraCssText:
                "max-width: min(360px, 85vw); white-space: normal; overflow-wrap: anywhere;",
            textStyle: {
                color: "#e4f4ff",
                fontSize: 14,
                fontFamily: "ui-monospace, monospace",
            },
            formatter: ({ value }) => {
                const p = value[3] || {};
                return p.champion
                    ? "current champion"
                    : `train <b>${value[0]?.toFixed(2)}%</b> · test <b>${value[1]?.toFixed(2)}%</b> · ${value[2]} trades<br/>${line(p)}`;
            },
        },
        xAxis: {
            name: "train %",
            nameLocation: "middle",
            nameGap: 30,
            nameTextStyle: { color: "#a9bfd2", fontSize: 13 },
            axisLine: { lineStyle: { color: "#23465f" } },
            axisLabel: { color: "#a9bfd2", fontSize: 13 },
            splitLine: { lineStyle: { color: "#182b3b" } },
        },
        yAxis: {
            name: "test %",
            nameTextStyle: { color: "#a9bfd2", fontSize: 13 },
            axisLine: { lineStyle: { color: "#23465f" } },
            axisLabel: { color: "#a9bfd2", fontSize: 13 },
            splitLine: { lineStyle: { color: "#182b3b" } },
        },
        dataZoom: [{ type: "inside", filterMode: "none" }],
        series: [...series, marks],
    };
});
</script>

<template>
    <div ref="plotHost" class="research-scatter"><div class="research-scatter-controls"><span>Train × test return</span><div role="group" aria-label="Candidate chart zoom"><button type="button" class="research-button" aria-label="Zoom in candidate chart" @click="changeZoom(-1)">+</button><button type="button" class="research-button" aria-label="Zoom out candidate chart" @click="changeZoom(1)">−</button><button type="button" class="research-button" @click="resetZoom">Reset</button></div></div><VChart v-if="plotReady" ref="chart" :init-options="plotInitOptions" :option="option" @datazoom="onZoom" autoresize class="w-full" style="height: 23rem" /><div v-else class="research-plot-placeholder" style="height:23rem" aria-hidden="true"></div><p class="research-chart-caption">Each dot is a paired candidate. Hover to inspect its parameters. Larger dots contain more trades.</p></div>
</template>
