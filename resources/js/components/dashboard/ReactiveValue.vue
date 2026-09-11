<script setup>
import { ref, watch, onUnmounted } from "vue";
import { fmt } from "../../api";
import { finiteNumber } from "./dashboardData";

const props = defineProps({
    value: [Number, String],
    active: Boolean,
    decimals: { type: Number, default: 2 },
});
const element = ref(null);
let frame = 0;
let rendered = null;
function write(value) {
    rendered = value;
    if (element.value)
        element.value.textContent = fmt.usd(value, props.decimals);
}
watch(
    () => [props.value, props.active],
    ([raw, active]) => {
        cancelAnimationFrame(frame);
        const target = finiteNumber(raw);
        const from = rendered;
        if (!active || from === null || target === null || target === from)
            return write(target);
        const start = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - start) / 700);
            write(from + (target - from) * (1 - Math.pow(1 - progress, 4)));
            if (progress < 1) frame = requestAnimationFrame(tick);
            else write(target);
        };
        frame = requestAnimationFrame(tick);
    },
    { flush: "post", immediate: true },
);
onUnmounted(() => cancelAnimationFrame(frame));
</script>

<template>
    <span :aria-label="fmt.usd(value, decimals)" class="dc-value"
        ><span ref="element" aria-hidden="true">{{
            fmt.usd(value, decimals)
        }}</span></span
    >
</template>
