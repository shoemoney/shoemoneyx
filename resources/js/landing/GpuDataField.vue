<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from "vue";
import { createDataScene } from "./dataScene";
import { pngUrlFor } from "../coinIcons";
import { coinOrbit } from "./robotCoinScan";
const props = defineProps({
    design: Object,
    pairs: Array,
    rate: Number,
    motion: Boolean,
    focusId: { type: String, default: null },
    coinNodes: { type: Boolean, default: false },
    scanId: { type: String, default: null },
});
const emit = defineEmits(["select"]);
const host = ref(null),
    failed = ref(false);
const hidden = ref(false),
    onScreen = ref(true),
    missingIcons = ref(new Set());
let engine, intersection;
const visibilityChanged = () => {
    hidden.value = document.hidden;
};
const fallbackCoins = computed(() =>
    (props.pairs || []).map((pair, index, all) => {
        const angle = (index / Math.max(all.length, 1)) * Math.PI * 2;
        const orbit = coinOrbit(index, all.length);
        const x = 50 + orbit.x * 38;
        const y = 50 - orbit.y * 35;
        return {
            id: pair.id,
            ticker: String(pair.id).split("-")[0],
            url: missingIcons.value.has(pair.id) ? null : pngUrlFor(pair.id),
            index,
            x,
            y,
            path: `M50 50 Q${(50 + x) / 2 + Math.sin(angle) * 4} ${(50 + y) / 2 - 6} ${x} ${y}`,
        };
    }),
);
function missingIcon(id) {
    missingIcons.value = new Set([...missingIcons.value, id]);
}
const update = () => engine?.setPairs(props.pairs, props.rate);
function getCoinClientPoint(id) {
    const point = failed.value
        ? fallbackCoins.value.find((coin) => coin.id === id)
        : engine?.getCoinPoint(id);
    if (!point || !host.value) return null;
    const box = host.value.getBoundingClientRect();
    const divisor = failed.value ? 100 : 1;
    return {
        x: box.left + (point.x / divisor) * box.width,
        y: box.top + (point.y / divisor) * box.height,
    };
}
defineExpose({ getCoinClientPoint });
onMounted(() => {
    visibilityChanged();
    document.addEventListener("visibilitychange", visibilityChanged);
    intersection = new IntersectionObserver(([entry]) => {
        onScreen.value = entry.isIntersecting;
    });
    intersection.observe(host.value);
    // Deliberate read-only preview for fallback review on any browser/device.
    if (new URLSearchParams(window.location.search).get("renderer") === "2d") {
        failed.value = true;
        return;
    }
    try {
        engine = createDataScene(
            host.value,
            props.design,
            (id) => emit("select", id),
            () => {
                failed.value = true;
            },
            { coinNodes: props.coinNodes },
        );
        update();
        engine.setMotion(props.motion);
        engine.setFocus(props.focusId);
        engine.setScan(props.scanId);
    } catch {
        engine?.dispose();
        engine = undefined;
        failed.value = true;
    }
});
watch(() => props.pairs, update);
watch(() => props.rate, update);
watch(
    () => props.focusId,
    (id) => engine?.setFocus(id),
);
watch(
    () => props.scanId,
    (id) => engine?.setScan(id),
);
watch(
    () => props.motion,
    (value) => engine?.setMotion(value),
);
onUnmounted(() => {
    engine?.dispose();
    intersection?.disconnect();
    document.removeEventListener("visibilitychange", visibilityChanged);
});
</script>
<template>
    <div
        ref="host"
        class="dx-gpu"
        :class="{
            'dx-gpu-fallback': failed,
            'dx-gpu-coins': coinNodes,
            'dx-gpu-coins-still': !motion || hidden || !onScreen,
        }"
        :data-node-style="coinNodes ? 'coins' : 'dots'"
        :data-coin-count="coinNodes ? pairs?.length || 0 : undefined"
        :data-scan-coin="scanId || undefined"
        aria-hidden="true"
    >
        <div v-if="failed" class="dx-fallback-field">
            <template v-if="coinNodes">
                <svg
                    class="dx-fallback-coin-pipes"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                >
                    <path
                        v-for="coin in fallbackCoins"
                        :key="coin.id"
                        :d="coin.path"
                    />
                </svg>
                <div
                    v-for="coin in fallbackCoins"
                    :key="coin.id"
                    class="dx-fallback-coin"
                    :class="{
                        'dx-fallback-coin-focused':
                            focusId === coin.id || scanId === coin.id,
                    }"
                    :data-pair-id="coin.id"
                    :style="{
                        left: `${coin.x}%`,
                        top: `${coin.y}%`,
                        '--coin-delay': `${coin.index * -0.28}s`,
                    }"
                >
                    <img
                        v-if="coin.url"
                        :src="coin.url"
                        alt=""
                        draggable="false"
                        @error="missingIcon(coin.id)"
                    />
                    <b v-else>{{ coin.ticker }}</b>
                </div>
            </template>
            <template v-else>
                <span v-for="n in 24" :key="n" :style="{ '--ray': n }"></span>
                <i class="fa-solid fa-brain"></i>
            </template>
        </div>
    </div>
    <span v-if="failed" class="dx-render-note">2D visual mode</span>
</template>

<style scoped>
.dx-fallback-coin-pipes {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    fill: none;
    stroke: #42adff;
    stroke-width: 1;
    opacity: 0.35;
    pointer-events: none;
}
.dx-fallback-coin-pipes path {
    vector-effect: non-scaling-stroke;
}
.dx-fallback-coin {
    position: absolute;
    width: 32px;
    height: 32px;
    transform: translate(-50%, -50%);
    pointer-events: none;
    z-index: 2;
}
.dx-fallback-coin::before {
    content: "";
    position: absolute;
    inset: -5px;
    border-radius: 50%;
    border: 1px solid #67caff91;
    box-shadow:
        0 0 10px #1987ff80,
        0 0 24px #1677ff38;
    animation: coin-node-breathe 3.7s ease-in-out infinite;
    animation-delay: var(--coin-delay);
}
.dx-fallback-coin img,
.dx-fallback-coin b {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 50%;
    animation: coin-art-breathe 3.7s ease-in-out infinite;
    animation-delay: var(--coin-delay);
}
.dx-fallback-coin b {
    display: grid;
    place-items: center;
    background: #06172c;
    color: #bbebff;
    font: 700 10px sans-serif;
}
.dx-fallback-coin-focused {
    filter: drop-shadow(0 0 9px #6fd2ff);
}
.dx-fallback-coin-focused::before {
    border-color: #bcecff;
}
.dx-gpu-coins-still .dx-fallback-coin::before,
.dx-gpu-coins-still .dx-fallback-coin img,
.dx-gpu-coins-still .dx-fallback-coin b {
    animation-play-state: paused;
}
@keyframes coin-node-breathe {
    0%,
    100% {
        opacity: 0.65;
        transform: scale(0.96);
    }
    50% {
        opacity: 1;
        transform: scale(1.12);
    }
}
@keyframes coin-art-breathe {
    0%,
    100% {
        transform: scale(0.97);
    }
    50% {
        transform: scale(1.035);
    }
}
@media (max-width: 600px) {
    .dx-fallback-coin {
        width: 28px;
        height: 28px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .dx-fallback-coin::before,
    .dx-fallback-coin img,
    .dx-fallback-coin b {
        animation: none;
    }
}
</style>
