<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from "vue";
import { createRobotParticleScene } from "./robotParticleScene";
const props = defineProps({
    effect: { type: String, default: "nanites" },
    motion: { type: Boolean, default: true },
    pulse: { type: Number, default: 0 },
    rate: { type: Number, default: 0 },
    target: { type: Object, default: () => ({ x: 0.5, y: 0.17 }) },
    replay: { type: Number, default: 0 },
});
const emit = defineEmits(["assembled", "renderer"]);
const host = ref(null),
    backend = ref("loading"),
    assembled = ref(true);
const diagnostic = ref("");
const visible = ref(false),
    hidden = ref(false),
    reduced = ref(false);
const activeMotion = computed(
    () => props.motion && visible.value && !hidden.value && !reduced.value,
);
let engine, controller, media, observer;
let mounted = false,
    generation = 0;
const motionAllowed = () => activeMotion.value;
async function start() {
    const ticket = ++generation;
    controller?.abort();
    engine?.dispose();
    engine = null;
    controller = new AbortController();
    backend.value = "loading";
    diagnostic.value = "";
    assembled.value = true;
    emit("assembled", true);
    try {
        const next = await createRobotParticleScene(host.value, {
            effect: props.effect,
            motion: motionAllowed(),
            pulse: props.pulse,
            rate: props.rate,
            target: props.target,
            signal: controller.signal,
            onRenderer(value) {
                if (ticket === generation) {
                    backend.value = value;
                    emit("renderer", value);
                }
            },
            onAssembled(value) {
                if (ticket === generation) {
                    assembled.value = value;
                    emit("assembled", value);
                }
            },
            onDiagnostic(value) {
                if (ticket === generation) diagnostic.value = value;
            },
        });
        if (ticket !== generation || !mounted) next.dispose();
        else {
            engine = next;
            engine.setInputs({
                pulse: props.pulse,
                rate: props.rate,
                target: props.target,
            });
            engine.setMotion(motionAllowed());
        }
    } catch (error) {
        if (error.name !== "AbortError" && ticket === generation) {
            backend.value = "CSS";
            emit("renderer", "CSS");
            assembled.value = true;
            emit("assembled", true);
        }
    }
}
const syncMotion = () => engine?.setMotion(motionAllowed());
const visibilityChanged = () => {
    hidden.value = document.hidden;
};
const preferenceChanged = () => {
    reduced.value = media.matches;
};
watch(activeMotion, syncMotion);
watch(
    () => [props.pulse, props.rate, props.target?.x, props.target?.y],
    () =>
        engine?.setInputs({
            pulse: props.pulse,
            rate: props.rate,
            target: props.target,
        }),
);
watch(
    () => props.replay,
    () => engine?.replay(),
);
watch(
    () => props.effect,
    () => {
        if (mounted) void start();
    },
);
onMounted(() => {
    mounted = true;
    media = matchMedia("(prefers-reduced-motion: reduce)");
    preferenceChanged();
    visibilityChanged();
    media.addEventListener("change", preferenceChanged);
    document.addEventListener("visibilitychange", visibilityChanged);
    observer = new IntersectionObserver(([entry]) => {
        visible.value = entry.isIntersecting;
    });
    observer.observe(host.value);
    void start();
});
onUnmounted(() => {
    mounted = false;
    generation++;
    controller?.abort();
    engine?.dispose();
    observer?.disconnect();
    media?.removeEventListener("change", preferenceChanged);
    document.removeEventListener("visibilitychange", visibilityChanged);
});
</script>
<template>
    <span
        class="robot-particle-field"
        :class="[
            `robot-particle-field--${effect}`,
            { 'robot-particle-field--still': !activeMotion },
        ]"
        :data-renderer="backend"
        :data-assembled="assembled"
        :data-render-note="diagnostic || undefined"
        aria-hidden="true"
    >
        <span ref="host" class="robot-particle-field__canvas"></span>
        <span v-if="backend === 'CSS'" class="robot-particle-field__fallback">
            <i v-for="n in 28" :key="n" :style="{ '--particle': n }"></i>
        </span>
    </span>
</template>
<style scoped>
.robot-particle-field,
.robot-particle-field__canvas,
.robot-particle-field__fallback {
    position: absolute;
    inset: 0;
    display: block;
    pointer-events: none;
}
.robot-particle-field {
    overflow: visible;
}
.robot-particle-field__canvas {
    isolation: isolate;
}
/* Project against the original image box while giving the plume and halo
   their own transparent space beyond the portrait. */
.robot-particle-field--plasma .robot-particle-field__canvas,
.robot-particle-field--swarm .robot-particle-field__canvas {
    inset: -18% -12%;
}
.robot-particle-field--nanites .robot-particle-field__canvas {
    inset: -28% -32%;
}
.robot-particle-field__fallback {
    overflow: hidden;
    border-radius: 45%;
}
.robot-particle-field__fallback i {
    position: absolute;
    left: 50%;
    top: 45%;
    width: 2px;
    height: 2px;
    background: #7bd7ff;
    border-radius: 50%;
    box-shadow: 0 0 9px #1686ff;
    transform: rotate(calc(var(--particle) * 12.857deg))
        translateX(calc(70px + var(--particle) * 2px));
    animation: robot-fallback-orbit 11s linear infinite;
    animation-delay: calc(var(--particle) * -0.34s);
}
.robot-particle-field--plasma .robot-particle-field__fallback i {
    width: 3px;
    height: 25px;
    left: 25%;
    top: 40%;
    opacity: 0.45;
    background: linear-gradient(transparent, #65d4ff, transparent);
    animation: robot-fallback-plasma 3.2s ease-in-out infinite;
    animation-delay: calc(var(--particle) * -0.17s);
}
.robot-particle-field--plasma
    .robot-particle-field__fallback
    i:nth-child(even) {
    left: 75%;
}
.robot-particle-field--nanites .robot-particle-field__fallback i {
    animation: none;
    opacity: 0.18;
}
.robot-particle-field--still * {
    animation-play-state: paused !important;
}
@keyframes robot-fallback-orbit {
    to {
        transform: rotate(calc(var(--particle) * 12.857deg + 360deg))
            translateX(calc(70px + var(--particle) * 2px));
    }
}
@keyframes robot-fallback-plasma {
    0% {
        opacity: 0;
        transform: translate(0, 0) rotate(-15deg);
    }
    30% {
        opacity: 0.5;
    }
    100% {
        opacity: 0;
        transform: translate(calc((var(--particle) - 14) * 2px), -110px)
            rotate(35deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .robot-particle-field * {
        animation: none !important;
    }
}
</style>
