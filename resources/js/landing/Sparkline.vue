<script setup>
import { computed } from "vue";
const props = defineProps({
    values: { type: Array, default: () => [] },
    area: Boolean,
});
const points = computed(() => {
    const values = props.values
        .filter((v) => Number.isFinite(Number(v)) && v != null)
        .map(Number);
    if (values.length < 2) return "";
    const low = Math.min(...values),
        range = Math.max(...values) - low || 1;
    return values
        .map(
            (v, i) =>
                `${(i / (values.length - 1)) * 400},${90 - ((v - low) / range) * 70}`,
        )
        .join(" ");
});
</script>
<template>
    <svg
        class="sparkline"
        viewBox="0 0 400 110"
        preserveAspectRatio="none"
        role="img"
        :aria-label="
            values.length > 1
                ? 'Recent observed values'
                : 'Waiting for chart history'
        "
    >
        <path
            v-if="area && points"
            :d="`M0,110 L${points.replaceAll(' ', ' L')} L400,110 Z`"
            fill="currentColor"
            opacity=".09"
        />
        <polyline
            v-if="points"
            :points="points"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            vector-effect="non-scaling-stroke"
            stroke-linejoin="round"
        />
        <line
            v-else
            x1="0"
            x2="400"
            y1="65"
            y2="65"
            stroke="currentColor"
            opacity=".2"
            stroke-dasharray="3 6"
        />
    </svg>
</template>
