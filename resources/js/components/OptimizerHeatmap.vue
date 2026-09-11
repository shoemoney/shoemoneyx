<script setup>
import { computed, ref, shallowRef, watch, onMounted, onBeforeUnmount } from "vue";
import VChart from "vue-echarts";
import { use } from "echarts/core";
import { SVGRenderer } from "echarts/renderers";
import { HeatmapChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    VisualMapComponent,
} from "echarts/components";

use([
    SVGRenderer,
    HeatmapChart,
    GridComponent,
    TooltipComponent,
    VisualMapComponent,
]);

/**
 * Mean TEST return over a parameter pair. Diverging colour (red ← gray → blue) because the value has a sign; the
 * midpoint is the neutral surface gray so "nothing" reads as nothing. Cells carry their count for the tooltip.
 */
const props = defineProps({
    points: { type: Array, default: () => [] },
    x: { type: String, required: true }, // point field on the x axis, e.g. 'tf'
    y: { type: String, required: true }, // point field on the y axis, e.g. 'base'
    xLabel: { type: String, default: "" },
    yLabel: { type: String, default: "" },
    metric: { type: String, default: "test" },
});


// Keep every measurement, but spend chart work only where the instrument is visible.
const plotHost = ref(null);
const plotReady = ref(false);
const plotPoints = shallowRef([]);
let plotVisible = false;
let plotObserver;
let plotTimer = null;
let plotDisposed = false;
function syncPlot() {
    plotTimer = null;
    if (plotDisposed || !plotVisible || document.hidden) return;
    plotPoints.value = props.points;
    plotReady.value = true;
}
function schedulePlot() {
    if (!plotDisposed && plotVisible && !document.hidden && !plotTimer) plotTimer = setTimeout(syncPlot, 500);
}
function plotVisibilityChanged() {
    if (document.hidden) { clearTimeout(plotTimer); plotTimer = null; }
    else syncPlot();
}
watch(() => props.points, schedulePlot);
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

const plotInitOptions = { renderer: "svg" };
const TF_ORDER = [
    "15s",
    "30s",
    "33s",
    "41s",
    "45s",
    "49s",
    "90s",
    "1m",
    "2m",
    "3m",
    "4m",
    "5m",
    "10m",
    "15m",
];
const sortVals = (field, vals) =>
    field === "tf"
        ? vals.sort((a, b) => TF_ORDER.indexOf(a) - TF_ORDER.indexOf(b))
        : vals.sort((a, b) => Number(a) - Number(b));

const grid = computed(() => {
    const cells = new Map();
    const xs = new Set();
    const ys = new Set();
    for (const p of plotPoints.value) {
        const xv = p[props.x],
            yv = p[props.y],
            v = p[props.metric];
        if (
            xv === null ||
            xv === undefined ||
            yv === null ||
            yv === undefined ||
            v === null ||
            v === undefined ||
            !Number.isFinite(Number(v))
        )
            continue;
        xs.add(String(xv));
        ys.add(String(yv));
        const k = xv + "|" + yv;
        const c = cells.get(k) || { sum: 0, n: 0, best: -Infinity };
        c.sum += Number(v);
        c.n += 1;
        c.best = Math.max(c.best, Number(v));
        cells.set(k, c);
    }
    const xa = sortVals(props.x, [...xs]);
    const ya = sortVals(props.y, [...ys]);
    const data = [];
    for (const [k, c] of cells) {
        const [xv, yv] = k.split("|");
        data.push([
            xa.indexOf(xv),
            ya.indexOf(yv),
            +(c.sum / c.n).toFixed(2),
            c.n,
            +c.best.toFixed(2),
        ]);
    }
    const lim = Math.max(1, ...data.map((d) => Math.abs(d[2])));
    return { xa, ya, data, lim };
});

const option = computed(() => ({
    backgroundColor: "#000000",
    animation: false,
    grid: { left: 60, right: 24, top: 28, bottom: 52 },
    tooltip: {
        confine: true,
        backgroundColor: "#04090e",
        borderColor: "#23465f",
        extraCssText:
            "max-width: min(360px, 85vw); white-space: normal; overflow-wrap: anywhere;",
        textStyle: {
            color: "#e4f4ff",
            fontSize: 13,
            fontFamily: "ui-monospace, monospace",
        },
        formatter: ({ value }) =>
            `${props.xLabel || props.x} ${grid.value.xa[value[0]]} · ${props.yLabel || props.y} ${grid.value.ya[value[1]]}<br/>mean ${props.metric} <b>${value[2]}%</b> · best ${value[4]}% · ${value[3]} candidates`,
    },
    xAxis: {
        type: "category",
        data: grid.value.xa,
        name: props.xLabel || props.x,
        nameLocation: "middle",
        nameGap: 30,
        nameTextStyle: { color: "#a9bfd2", fontSize: 13 },
        axisLabel: { color: "#a9bfd2", fontSize: 13 },
        axisLine: { lineStyle: { color: "#23465f" } },
        splitArea: { show: false },
    },
    yAxis: {
        type: "category",
        data: grid.value.ya,
        name: props.yLabel || props.y,
        nameTextStyle: { color: "#a9bfd2", fontSize: 13 },
        axisLabel: { color: "#a9bfd2", fontSize: 13 },
        axisLine: { lineStyle: { color: "#23465f" } },
    },
    visualMap: {
        show: false,
        dimension: 2,
        min: -grid.value.lim,
        max: grid.value.lim,
        inRange: { color: ["#e66767", "#383835", "#3987e5"] },
    },
    series: [
        {
            type: "heatmap",
            data: grid.value.data,
            itemStyle: {
                borderColor: "#04090e",
                borderWidth: 2,
                borderRadius: 3,
            },
            label: {
                show: true,
                color: "#ffffff",
                fontSize: 12,
                textBorderColor: "#04090e",
                textBorderWidth: 2,
                formatter: ({ value }) => (value[3] >= 3 ? value[2] : ""),
            },
            emphasis: { itemStyle: { borderColor: "#fff" } },
        },
    ],
}));
</script>

<template>
    <div
        ref="plotHost"
        class="smx-heatmap-scroll overflow-x-auto"
        tabindex="0"
        role="region"
        :aria-label="`${xLabel || x} by ${yLabel || y} heatmap`"
    >
        <VChart
            v-if="plotReady"
            :init-options="plotInitOptions"
            :option="option"
            autoresize
            class="w-full"
            :style="{
                minWidth: Math.max(460, grid.xa.length * 48 + 84) + 'px',
                height: Math.max(248, grid.ya.length * 26 + 80) + 'px',
            }"
        />
        <div v-else class="research-plot-placeholder" style="height:248px" aria-hidden="true"></div>
    </div>
</template>
