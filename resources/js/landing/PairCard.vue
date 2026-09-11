<script setup>
import Sparkline from "./Sparkline.vue";
import { pngUrlFor } from "../coinIcons";
import { price, percent, signedCash, ticker } from "./format";
import { ref, watch } from "vue";
const props = defineProps({
    pair: Object,
    index: Number,
    selected: Boolean,
    focused: Boolean,
    motion: Boolean,
});
const emit = defineEmits(["select", "highlight"]);
const card = ref(null);
function resetTilt() {
    card.value?.style.setProperty("--tilt-x", "0deg");
    card.value?.style.setProperty("--tilt-y", "0deg");
    card.value?.style.setProperty("--lens-x", "50%");
    card.value?.style.setProperty("--lens-y", "50%");
}
function tilt(event) {
    if (!props.motion || event.pointerType === "touch") return;
    const rect = card.value.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width;
    const y = (event.clientY - rect.top) / rect.height;
    card.value.style.setProperty("--tilt-x", `${(0.5 - y) * 6}deg`);
    card.value.style.setProperty("--tilt-y", `${(x - 0.5) * 8}deg`);
    card.value.style.setProperty("--lens-x", `${x * 100}%`);
    card.value.style.setProperty("--lens-y", `${y * 100}%`);
}
function leave() {
    resetTilt();
    emit("highlight", "");
}
watch(() => props.motion, resetTilt);
</script>
<template>
    <button
        ref="card"
        class="pair-card"
        :class="[
            { selected, 'pxo-focused': focused, negative: pair.pnl < 0 },
            `side-${pair.side}`,
        ]"
        :style="{ '--order': index }"
        @click="$emit('select', pair.id)"
        @pointerenter="$emit('highlight', pair.id)"
        @pointermove="tilt"
        @pointerleave="leave"
        @focus="$emit('highlight', pair.id)"
        @blur="leave"
        :aria-label="`Inspect ${pair.id}, ${pair.side}, P&L ${signedCash(pair.pnl)}`"
    >
        <span class="pxo-card-lens" aria-hidden="true"></span>
        <span class="pxo-card-target" aria-hidden="true"
            ><i></i><i></i><i></i><i></i
        ></span>
        <div class="pair-top">
            <span class="coin-avatar"
                ><img
                    v-if="pngUrlFor(pair.id)"
                    :src="pngUrlFor(pair.id)"
                    alt=""
                /><span v-else>{{ ticker(pair.id).slice(0, 2) }}</span></span
            ><span class="pair-name"
                >{{ ticker(pair.id) }}<small>/ USD</small></span
            ><span class="pair-side">{{ pair.side }}</span>
        </div>
        <div class="pair-price">
            <b class="price-tick" :key="pair.price">{{ price(pair.price) }}</b
            ><span :class="pair.change < 0 ? 'down' : 'up'">{{
                percent(pair.change)
            }}</span>
        </div>
        <Sparkline
            :values="pair.history"
            :class="pair.change < 0 ? 'down' : 'up'"
        />
        <div class="pair-bottom">
            <span
                ><i class="fa-solid fa-microchip-ai" aria-hidden="true"></i>
                {{ pair.live ? "FEED LIVE" : "CACHED" }}</span
            ><strong :class="pair.pnl < 0 ? 'down' : 'up'">{{
                signedCash(pair.pnl)
            }}</strong>
        </div>
    </button>
</template>
