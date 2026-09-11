<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from "vue";

const props = defineProps({
    motion: { type: Boolean, default: true },
    active: { type: Boolean, default: true },
    pulse: { type: Number, default: 0 },
});
const root = ref(null),
    echo = ref(null);
const visible = ref(true),
    hidden = ref(false),
    reduced = ref(false);
const animate = computed(
    () =>
        props.motion &&
        props.active &&
        visible.value &&
        !hidden.value &&
        !reduced.value,
);
let observer,
    media,
    burst,
    lastBurst = -Infinity;
const syncVisibility = () => {
    hidden.value = document.hidden;
};
const syncMotion = () => {
    reduced.value = media.matches;
};

watch(
    () => props.pulse,
    (value, previous) => {
        if (!animate.value || value === previous || !echo.value) return;
        const now = performance.now();
        if (now - lastBurst < 1800) return;
        lastBurst = now;
        burst?.cancel();
        burst = echo.value.animate(
            [
                { opacity: 0, transform: "scale(.9)" },
                { opacity: 0.24, transform: "scale(1)", offset: 0.3 },
                { opacity: 0, transform: "scale(1.22)" },
            ],
            { duration: 1500, easing: "cubic-bezier(.2,.6,.3,1)" },
        );
    },
);
watch(animate, (enabled) => {
    if (!enabled) burst?.cancel();
});
onMounted(() => {
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    syncMotion();
    syncVisibility();
    media.addEventListener("change", syncMotion);
    document.addEventListener("visibilitychange", syncVisibility);
    observer = new IntersectionObserver(([entry]) => {
        visible.value = entry.isIntersecting;
    });
    observer.observe(root.value);
});
onUnmounted(() => {
    burst?.cancel();
    observer?.disconnect();
    media?.removeEventListener("change", syncMotion);
    document.removeEventListener("visibilitychange", syncVisibility);
});
</script>

<template>
    <span
        ref="root"
        class="horizon-guardian"
        :class="{ 'horizon-guardian--still': !animate }"
        role="img"
        aria-label="ShoeGPT robot"
        :aria-hidden="!active || undefined"
    >
        <span class="horizon-guardian__halo" aria-hidden="true"></span>
        <span
            ref="echo"
            class="horizon-guardian__echo"
            aria-hidden="true"
        ></span>
        <svg
            class="horizon-guardian__circuits"
            viewBox="0 0 320 360"
            fill="none"
            aria-hidden="true"
            focusable="false"
        >
            <path
                class="horizon-guardian__circuit-base"
                d="M9 219H31L47 201V141L67 119V85L91 61M229 61L253 85V119L273 141V201L289 219H311M19 273H50L75 298H106M301 273H270L245 298H214"
            />
            <path
                class="horizon-guardian__circuit-flow"
                pathLength="100"
                d="M9 219H31L47 201V141L67 119V85L91 61M229 61L253 85V119L273 141V201L289 219H311M19 273H50L75 298H106M301 273H270L245 298H214"
            />
            <g class="horizon-guardian__nodes">
                <circle cx="91" cy="61" r="2" />
                <circle cx="229" cy="61" r="2" />
                <circle cx="9" cy="219" r="2" />
                <circle cx="311" cy="219" r="2" />
                <circle cx="106" cy="298" r="2" />
                <circle cx="214" cy="298" r="2" />
            </g>
        </svg>
        <span class="horizon-guardian__portrait" aria-hidden="true">
            <img
                :src="'/brand/shoegpt-robot-armor.webp'"
                alt=""
                width="1397"
                height="1126"
                draggable="false"
                decoding="async"
            />
            <span class="horizon-guardian__visor"></span>
            <span class="horizon-guardian__eye-light"></span>
            <span class="horizon-guardian__chest-sheen"></span>
        </span>
        <span class="horizon-guardian__plinth" aria-hidden="true"></span>
    </span>
</template>

<style scoped>
.horizon-guardian {
    --guardian-width: 350px;
    position: relative;
    display: block;
    width: min(var(--guardian-width), 100%);
    aspect-ratio: 1397 / 1126;
    flex: 0 0 auto;
    isolation: isolate;
    pointer-events: none;
    margin-bottom: -6px;
}
.horizon-guardian__halo {
    position: absolute;
    inset: 0 -17% 5%;
    border-radius: 50%;
    background: radial-gradient(
        ellipse at 50% 37%,
        #3aabff1c 5%,
        #1681ff12 35%,
        transparent 68%
    );
    filter: blur(8px);
    mix-blend-mode: screen;
}
.horizon-guardian__echo {
    position: absolute;
    inset: 9% 0 12%;
    border: 1px solid #5bcaff;
    border-radius: 50%;
    opacity: 0;
    box-shadow:
        0 0 20px #168aff40,
        inset 0 0 20px #168aff1f;
}
.horizon-guardian__circuits {
    position: absolute;
    inset: -2% -7% 0;
    width: 114%;
    height: 102%;
    overflow: visible;
    stroke: #54c8ff;
    stroke-width: 1;
}
.horizon-guardian__circuit-base {
    opacity: 0.22;
}
.horizon-guardian__circuit-flow {
    stroke: #a0e9ff;
    stroke-dasharray: 3 23;
    opacity: 0.65;
    animation: guardian-circuit 10s linear infinite;
}
.horizon-guardian__nodes {
    fill: #69d8ff;
    stroke: none;
    opacity: 0.65;
}
.horizon-guardian__portrait {
    position: absolute;
    inset: 0;
    mask-image: linear-gradient(
        to bottom,
        #000 0 76%,
        #000e 85%,
        transparent 100%
    );
    animation: guardian-float 7s ease-in-out infinite alternate;
}
.horizon-guardian__portrait img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    object-position: center bottom;
    filter: drop-shadow(0 0 11px #40baff29) drop-shadow(0 10px 15px #0008);
}
.horizon-guardian__visor {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(
        ellipse 5% 6% at 50% 17%,
        #54dfff4d,
        transparent 85%
    );
    mix-blend-mode: screen;
    opacity: 0.4;
    animation: guardian-eye-halo 4.8s ease-in-out infinite;
}
.horizon-guardian__eye-light {
    position: absolute;
    left: 50%;
    top: 17%;
    width: 2.6%;
    aspect-ratio: 1;
    border-radius: 50%;
    background: radial-gradient(
        circle,
        #d8f7ffb3,
        #51ceff80 35%,
        transparent 72%
    );
    box-shadow: 0 0 8px #25b5ff66;
    mix-blend-mode: screen;
    transform: translate(-50%, -50%);
    opacity: 0.32;
    animation: guardian-eye-light 4.8s ease-in-out infinite;
}
.horizon-guardian__chest-sheen {
    position: absolute;
    left: 36%;
    top: 43%;
    width: 28%;
    height: 27%;
    overflow: hidden;
    clip-path: polygon(50% 0, 86% 6%, 100% 30%, 50% 100%, 0 30%, 14% 6%);
    mix-blend-mode: screen;
}
.horizon-guardian__chest-sheen::before {
    content: "";
    position: absolute;
    inset: -15% auto -15% 0;
    width: 40%;
    background: linear-gradient(90deg, transparent, #b4ecff80, transparent);
    transform: translateX(-180%) skewX(-18deg);
    opacity: 0;
    animation: guardian-chest-sheen 11.8s ease-in-out infinite;
}
.horizon-guardian__plinth {
    position: absolute;
    width: 92%;
    height: 14%;
    left: 4%;
    bottom: 5%;
    border: 1px solid #41b6ff38;
    border-radius: 50%;
    background: radial-gradient(ellipse, #208fff19, transparent 65%);
    box-shadow: 0 0 16px #1d8fff1a;
    transform: rotate(-9deg);
}
.horizon-guardian--still .horizon-guardian__portrait,
.horizon-guardian--still .horizon-guardian__circuit-flow,
.horizon-guardian--still .horizon-guardian__visor,
.horizon-guardian--still .horizon-guardian__eye-light,
.horizon-guardian--still .horizon-guardian__chest-sheen::before {
    animation: none;
}
@keyframes guardian-circuit {
    to {
        stroke-dashoffset: -104;
    }
}
@keyframes guardian-float {
    from {
        transform: translateY(1px);
    }
    to {
        transform: translateY(-4px);
    }
}
@keyframes guardian-eye-halo {
    0%,
    100% {
        opacity: 0.25;
    }
    50% {
        opacity: 0.75;
    }
}
@keyframes guardian-eye-light {
    0%,
    100% {
        opacity: 0.28;
    }
    50% {
        opacity: 0.7;
    }
}
@keyframes guardian-chest-sheen {
    0%,
    64% {
        transform: translateX(-180%) skewX(-18deg);
        opacity: 0;
    }
    69% {
        opacity: 0.26;
    }
    80%,
    100% {
        transform: translateX(340%) skewX(-18deg);
        opacity: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .horizon-guardian__portrait,
    .horizon-guardian__circuit-flow,
    .horizon-guardian__visor,
    .horizon-guardian__eye-light,
    .horizon-guardian__chest-sheen::before {
        animation: none;
    }
}
</style>
