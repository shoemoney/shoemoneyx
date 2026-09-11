<script setup>
import { computed, ref, onUnmounted, watch } from "vue";
import { pngUrlFor } from "../coinIcons";
import { price, percent, signedCash, ticker, compact } from "./format";
import Sparkline from "./Sparkline.vue";
const props = defineProps({
    pair: Object,
    index: Number,
    motion: Boolean,
    focused: Boolean,
});
const emit = defineEmits(["select", "focus-market"]);
const card = ref(null);
let pointerFrame = 0,
    pointerX = 0.5,
    pointerY = 0.5;
function point(event) {
    if (!props.motion || event.pointerType === "touch") return;
    const bounds = card.value.getBoundingClientRect();
    pointerX = (event.clientX - bounds.left) / bounds.width;
    pointerY = (event.clientY - bounds.top) / bounds.height;
    if (!pointerFrame)
        pointerFrame = requestAnimationFrame(() => {
            pointerFrame = 0;
            card.value?.style.setProperty("--focus-x", `${pointerX * 100}%`);
            card.value?.style.setProperty("--focus-y", `${pointerY * 100}%`);
            card.value?.style.setProperty(
                "--tilt-x",
                `${(pointerY - 0.5) * -4}deg`,
            );
            card.value?.style.setProperty(
                "--tilt-y",
                `${(pointerX - 0.5) * 5}deg`,
            );
        });
}
function resetTilt() {
    cancelAnimationFrame(pointerFrame);
    pointerFrame = 0;
    card.value?.style.setProperty("--tilt-x", "0deg");
    card.value?.style.setProperty("--tilt-y", "0deg");
}
function leave() {
    resetTilt();
    if (document.activeElement !== card.value) emit("focus-market", null);
}
watch(
    () => props.motion,
    (value) => {
        if (!value) resetTilt();
    },
);
onUnmounted(() => cancelAnimationFrame(pointerFrame));
const icon = computed(() => pngUrlFor(props.pair.id));
</script>
<template>
    <button
        ref="card"
        class="dx-pair"
        :class="{
            'dx-pair-negative': pair.pnl < 0,
            'dx-pair-watching': !pair.open,
            'dx-pair-focused': focused,
        }"
        :style="{
            '--i': index,
            '--strength': Math.min(Math.abs(pair.change || 0) / 10, 0.85),
        }"
        :aria-label="`Inspect ${pair.id}`"
        @click="$emit('select', pair.id)"
        @pointerenter="emit('focus-market', pair.id)"
        @pointermove="point"
        @pointerleave="leave"
        @focus="emit('focus-market', pair.id)"
        @blur="emit('focus-market', null)"
    >
        <span class="dx-pair-material" aria-hidden="true"
            ><i></i><i></i><i></i
        ></span>
        <span class="dx-pair-rank">{{
            String(index + 1).padStart(2, "0")
        }}</span>
        <span class="dx-pair-identity"
            ><img v-if="icon" :src="icon" alt="" width="26" height="26" /><span
                v-else
                class="dx-coin-fallback"
                >{{ ticker(pair.id).slice(0, 2) }}</span
            ><span
                ><strong>{{ ticker(pair.id) }}<small>/ USD</small></strong
                ><em :class="{ 'dx-short': pair.side === 'short' }">{{
                    pair.side
                }}</em></span
            ></span
        >
        <span class="dx-pair-price"
            ><b :key="motion ? pair.price : 'static'">{{ price(pair.price) }}</b
            ><small :class="pair.change < 0 ? 'dx-down' : 'dx-up'">{{
                percent(pair.change)
            }}</small></span
        >
        <Sparkline class="dx-pair-chart" :values="pair.history" />
        <span class="dx-pair-pnl"
            ><small>P&L</small
            ><b :class="pair.pnl < 0 ? 'dx-down' : 'dx-up'">{{
                signedCash(pair.pnl)
            }}</b></span
        >
        <span class="dx-pair-depth" aria-hidden="true"
            ><i
                :style="{
                    width: `${Math.min(100, Math.abs(pair.change || 0) * 10)}%`,
                }"
            ></i
        ></span>
        <span class="dx-pair-foot"
            ><span
                ><i class="dx-dot" :class="{ 'dx-dot-off': !pair.live }"></i
                >{{ pair.live ? "STREAMING" : "AWAITING QUOTE" }}</span
            ><span>{{ compact(pair.notional) }} USD</span></span
        >
    </button>
</template>
