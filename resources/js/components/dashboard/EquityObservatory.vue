<script setup>
import { computed, ref, watch } from "vue";
import VChart from "vue-echarts";
import { use } from "echarts/core";
import { CanvasRenderer } from "echarts/renderers";
import { LineChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    DataZoomComponent,
    MarkPointComponent,
} from "echarts/components";
import { fmt } from "../../api";
import { historyPoints } from "./dashboardData";

use([
    CanvasRenderer,
    LineChart,
    GridComponent,
    TooltipComponent,
    DataZoomComponent,
    MarkPointComponent,
]);
const props = defineProps({
    rows: { type: Array, default: () => [] },
    active: Boolean,
    loading: Boolean,
    error: String,
    windowHours: Number,
});
const field = ref("equity");
const chart = ref(null);
// Store the selected interval outside reactivity so dragging never restarts setOption.
let zoomStart = 0,
    zoomEnd = 100;
const zoomLabel = ref("Full window");
function rememberZoom(event) {
    const range = event.batch?.[0] || event;
    if (typeof range.start === "number") zoomStart = range.start;
    if (typeof range.end === "number") zoomEnd = range.end;
    zoomLabel.value =
        zoomEnd - zoomStart >= 99.9
            ? "Full window"
            : Math.round(zoomEnd - zoomStart) + "% of window";
}
function zoom(direction) {
    const center = (zoomStart + zoomEnd) / 2;
    const span =
        direction === 0
            ? 100
            : Math.max(
                  2,
                  Math.min(
                      100,
                      (zoomEnd - zoomStart) * (direction > 0 ? 0.65 : 1.55),
                  ),
              );
    zoomStart = Math.max(0, Math.min(100 - span, center - span / 2));
    zoomEnd = zoomStart + span;
    chart.value?.dispatchAction({
        type: "dataZoom",
        start: zoomStart,
        end: zoomEnd,
    });
    rememberZoom({ start: zoomStart, end: zoomEnd });
}
watch(
    () => props.windowHours,
    () => zoom(0),
);
const fields = [
    { id: "equity", label: "Equity", color: "#48c5ff" },
    { id: "cash", label: "Cash", color: "#b2dcff" },
    { id: "positions_value", label: "Working", color: "#957aff" },
];
const current = computed(() => fields.find((item) => item.id === field.value));
const points = computed(() => historyPoints(props.rows, field.value));
const valid = computed(() => points.value.filter((point) => point[1] !== null));
const option = computed(() => ({
    animation: props.active,
    animationDuration: 950,
    animationDurationUpdate: 600,
    animationEasingUpdate: "cubicOut",
    backgroundColor: "transparent",
    grid: { left: 12, right: 20, top: 35, bottom: 52, containLabel: true },
    tooltip: {
        trigger: "axis",
        confine: true,
        backgroundColor: "#020c15",
        borderColor: "#278ec4",
        padding: [12, 16],
        textStyle: { color: "#e4f4ff", fontSize: 14 },
        axisPointer: {
            type: "cross",
            lineStyle: { color: "#5fcbff", type: "dashed" },
            crossStyle: { color: "#3481ac" },
            label: { backgroundColor: "#123f5c", fontSize: 14 },
        },
        formatter: (items) => {
            const point = items[0]?.value;
            return point
                ? `${new Date(point[0]).toLocaleString()}<br/>${current.value.label} <b>${fmt.usd(point[1])}</b>`
                : "";
        },
    },
    xAxis: {
        type: "time",
        axisLine: { lineStyle: { color: "#1f4964" } },
        axisTick: { show: false },
        axisLabel: { color: "#9ebfd4", fontSize: 14, hideOverlap: true },
        splitLine: { show: false },
    },
    yAxis: {
        type: "value",
        scale: true,
        splitNumber: 4,
        axisLabel: {
            color: "#9ebfd4",
            fontSize: 14,
            formatter: (value) =>
                Math.abs(value) >= 1000
                    ? `$${(value / 1000).toLocaleString(undefined, { maximumFractionDigits: 1 })}k`
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
            bottom: 0,
            height: 25,
            handleSize: 30,
            moveHandleSize: 9,
            borderColor: "#1b4661",
            backgroundColor: "#02121f",
            fillerColor: "#149deb20",
            dataBackground: {
                lineStyle: { color: "#3aa3df" },
                areaStyle: { color: "#1782ff38" },
            },
            selectedDataBackground: {
                lineStyle: { color: "#51c8ff" },
                areaStyle: { color: "#1682ff4a" },
            },
            handleStyle: { color: "#178bd0", borderColor: "#80d7ff" },
            textStyle: { color: "#a2c6de", fontSize: 14 },
            showDetail: false,
        },
    ],
    series: [
        {
            name: current.value.label,
            type: "line",
            data: points.value,
            smooth: false,
            connectNulls: false,
            showSymbol: valid.value.length === 1,
            symbol: "circle",
            symbolSize: 8,
            sampling: "lttb",
            lineStyle: {
                color: current.value.color,
                width: 2.5,
                shadowColor: current.value.color,
                shadowBlur: 10,
            },
            itemStyle: {
                color: current.value.color,
                borderColor: "#fff",
                borderWidth: 1,
            },
            areaStyle: {
                color: {
                    type: "linear",
                    x: 0,
                    y: 0,
                    x2: 0,
                    y2: 1,
                    colorStops: [
                        { offset: 0, color: `${current.value.color}36` },
                        { offset: 1, color: `${current.value.color}00` },
                    ],
                },
            },
            emphasis: { scale: true },
            markPoint: valid.value.length
                ? {
                      silent: true,
                      symbol: "circle",
                      symbolSize: 9,
                      label: { show: false },
                      itemStyle: {
                          color: "#d9f4ff",
                          shadowBlur: 18,
                          shadowColor: current.value.color,
                      },
                      data: [{ coord: valid.value.at(-1) }],
                  }
                : undefined,
        },
    ],
}));
</script>

<template>
    <div class="dc-observatory">
        <div class="dc-chart-toolbar">
            <div class="dc-segments" aria-label="Chart measurement">
                <button
                    v-for="item in fields"
                    :key="item.id"
                    type="button"
                    :aria-pressed="field === item.id"
                    @click="field = item.id"
                >
                    {{ item.label }}
                </button>
            </div>
            <div class="dc-chart-zoom" aria-label="Chart zoom controls">
                <button
                    type="button"
                    :disabled="!valid.length"
                    @click="zoom(1)"
                    aria-label="Zoom in on account history"
                >
                    + <span>Zoom in</span>
                </button>
                <button
                    type="button"
                    :disabled="!valid.length"
                    @click="zoom(-1)"
                    aria-label="Zoom out on account history"
                >
                    − <span>Zoom out</span>
                </button>
                <button
                    type="button"
                    :disabled="!valid.length"
                    @click="zoom(0)"
                    aria-label="Reset account history zoom"
                >
                    Reset
                </button>
            </div>
        </div>
        <div class="dc-chart-window-status">
            <span>Drag the timeline to explore</span
            ><span role="status">{{ zoomLabel }}</span>
        </div>
        <VChart
            v-if="valid.length"
            ref="chart"
            :option="option"
            autoresize
            class="dc-equity-chart"
            :aria-label="`${current.label} history. Interactive chart with timestamp and dollar tooltips.`"
            @datazoom="rememberZoom"
        />
        <div v-else class="dc-chart-empty" role="status">
            <span class="dc-empty-orbit" aria-hidden="true"></span
            ><strong>{{
                loading
                    ? "Acquiring account history"
                    : error
                      ? "History unavailable"
                      : "No snapshots in this window"
            }}</strong
            ><span>{{
                error || "The chart will appear when account snapshots arrive."
            }}</span>
        </div>
    </div>
</template>
