<script setup>
import { computed } from "vue";
import VChart from "vue-echarts";
import { use } from "echarts/core";
import { CanvasRenderer } from "echarts/renderers";
import { BarChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    MarkLineComponent,
} from "echarts/components";
import { finiteNumber } from "./dashboardData";
import { fmt } from "../../api";
use([
    CanvasRenderer,
    BarChart,
    GridComponent,
    TooltipComponent,
    MarkLineComponent,
]);
const props = defineProps({
    positions: { type: Array, default: () => [] },
    active: Boolean,
    loading: Boolean,
    error: String,
    selected: [Number, String],
});
const emit = defineEmits(["select"]);
const ranked = computed(() =>
    props.positions
        .filter((p) => finiteNumber(p.unrealised_pnl) !== null)
        .sort(
            (a, b) =>
                Math.abs(Number(b.unrealised_pnl)) -
                Math.abs(Number(a.unrealised_pnl)),
        )
        .slice(0, 12),
);
const escape = (value) =>
    String(value).replace(
        /[&<>"']/g,
        (char) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[char],
    );
const option = computed(() => ({
    animation: props.active,
    animationDuration: 700,
    animationDurationUpdate: 550,
    grid: { left: 4, right: 52, top: 8, bottom: 30, containLabel: true },
    tooltip: {
        trigger: "item",
        confine: true,
        backgroundColor: "#030d17",
        borderColor: "#278ec4",
        textStyle: { color: "#e4f4ff", fontSize: 14 },
        formatter: (item) =>
            `${escape(item.name)}<br/>Unrealised <b>${fmt.usd(item.value)}</b><br/>${fmt.pct(ranked.value[item.dataIndex]?.unrealised_pnl_pct)}`,
    },
    xAxis: {
        type: "value",
        scale: false,
        min: (range) => Math.min(0, range.min),
        max: (range) => Math.max(0, range.max),
        axisLabel: {
            color: "#9bbdd5",
            fontSize: 14,
            formatter: (value) => fmt.usd(value, 0),
        },
        splitNumber: 4,
        splitLine: { lineStyle: { color: "#163449", type: "dashed" } },
    },
    yAxis: {
        type: "category",
        inverse: true,
        data: ranked.value.map((p) => p.product_id),
        axisTick: { show: false },
        axisLine: { show: false },
        axisLabel: {
            color: "#c6e9ff",
            fontSize: 14,
            width: 120,
            overflow: "truncate",
        },
    },
    series: [
        {
            type: "bar",
            barMaxWidth: 22,
            roundCap: true,
            data: ranked.value.map((p) => ({
                value: Number(p.unrealised_pnl),
                itemStyle: {
                    color: Number(p.unrealised_pnl) < 0 ? "#ff8297" : "#39bfff",
                    borderColor:
                        props.selected === p.id ? "#e9f9ff" : "transparent",
                    borderWidth: 2,
                    borderRadius: 4,
                    shadowColor:
                        Number(p.unrealised_pnl) < 0
                            ? "#ff628b60"
                            : "#128eff70",
                    shadowBlur: props.selected === p.id ? 20 : 8,
                },
            })),
            label: { show: false },
            emphasis: { itemStyle: { color: "#b6eaff", shadowBlur: 24 } },
            markLine: {
                silent: true,
                symbol: "none",
                label: { show: false },
                lineStyle: { color: "#91c4e1", width: 1 },
                data: [{ xAxis: 0 }],
            },
        },
    ],
}));
</script>
<template>
    <VChart
        v-if="ranked.length"
        :option="option"
        autoresize
        :style="{
            height: `${Math.max(200, Math.min(460, ranked.length * 39 + 54))}px`,
        }"
        aria-label="Unrealised P and L by position. Click a bar to focus its position."
        @click="(event) => emit('select', ranked[event.dataIndex]?.id)"
    />
    <div v-else class="dc-spectrum-empty">
        {{
            error
                ? "Position snapshot unavailable."
                : loading
                  ? "Acquiring current positions…"
                  : positions.length
                    ? "Waiting for position marks to calculate P&L."
                    : "No open positions. The spectrum lights up when the desk opens a trade."
        }}
    </div>
</template>
