<script setup>
import { computed, useId } from "vue";
// Minimal dependency-free SVG line chart: points = [[x, y], ...]
const props = defineProps({
    points: { type: Array, default: () => [] },
    height: { type: Number, default: 120 },
    baseline: { type: Number, default: null },
});
const gradientId = useId();

const view = computed(() => {
    const pts = props.points.filter((p) => p && p[1] !== null && !isNaN(p[1]));
    if (pts.length < 2) return null;
    const xs = pts.map((p) => p[0]),
        ys = pts.map((p) => p[1]);
    const x0 = Math.min(...xs),
        x1 = Math.max(...xs);
    let y0 = Math.min(...ys),
        y1 = Math.max(...ys);
    if (props.baseline !== null) {
        y0 = Math.min(y0, props.baseline);
        y1 = Math.max(y1, props.baseline);
    }
    if (y1 === y0) {
        y1 += 1;
        y0 -= 1;
    }
    const W = 600,
        H = props.height,
        pad = 4;
    const sx = (x) => pad + ((x - x0) / (x1 - x0 || 1)) * (W - pad * 2);
    const sy = (y) => H - pad - ((y - y0) / (y1 - y0)) * (H - pad * 2);
    const d = pts
        .map(
            (p, i) =>
                (i ? "L" : "M") +
                sx(p[0]).toFixed(1) +
                " " +
                sy(p[1]).toFixed(1),
        )
        .join(" ");
    const last = pts[pts.length - 1][1],
        first = pts[0][1];
    return {
        d,
        W,
        H,
        up: last >= (props.baseline ?? first),
        base: props.baseline !== null ? sy(props.baseline) : null,
        area: d + ` L${sx(x1).toFixed(1)} ${H} L${sx(x0).toFixed(1)} ${H} Z`,
    };
});
</script>

<template>
    <svg
        v-if="view"
        :viewBox="`0 0 ${view.W} ${view.H}`"
        preserveAspectRatio="none"
        class="w-full"
        :style="{ height: height + 'px' }"
        role="img"
        aria-label="Value history"
    >
        <defs>
            <linearGradient
                :id="`${gradientId}-up`"
                x1="0"
                y1="0"
                x2="0"
                y2="1"
            >
                <stop offset="0" stop-color="#75e0bc" stop-opacity="0.25" />
                <stop offset="1" stop-color="#75e0bc" stop-opacity="0" />
            </linearGradient>
            <linearGradient
                :id="`${gradientId}-down`"
                x1="0"
                y1="0"
                x2="0"
                y2="1"
            >
                <stop offset="0" stop-color="#ff8495" stop-opacity="0.25" />
                <stop offset="1" stop-color="#ff8495" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path
            :d="view.area"
            :fill="`url(#${gradientId}-${view.up ? 'up' : 'down'})`"
        />
        <line
            v-if="view.base !== null"
            x1="0"
            :y1="view.base"
            :x2="view.W"
            :y2="view.base"
            stroke="#486377"
            stroke-dasharray="3 3"
            stroke-width="1"
        />
        <path
            :d="view.d"
            fill="none"
            :stroke="view.up ? '#75e0bc' : '#ff8495'"
            stroke-width="2"
            vector-effect="non-scaling-stroke"
        />
    </svg>
    <div
        v-else
        class="flex items-center justify-center text-xs text-zinc-600"
        :style="{ height: height + 'px' }"
    >
        not enough data yet
    </div>
</template>
