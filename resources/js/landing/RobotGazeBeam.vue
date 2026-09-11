<script setup>
import { ref, useId } from "vue";
const surface = ref(null),
    wash = ref(null),
    ray = ref(null),
    spot = ref(null),
    gradient = ref(null);
const gradientId = `robot-gaze-${useId().replace(/[^a-z0-9-]/gi, "")}`;
function paint(gaze, coin) {
    if (!surface.value) return;
    const light = coin && gaze?.id ? gaze.light : 0;
    surface.value.style.opacity = String(light || 0);
    surface.value.dataset.targetCoin = gaze?.id || "";
    surface.value.dataset.light = String(light || 0);
    if (!light) return;
    const box = surface.value.getBoundingClientRect();
    if (!box.width || !box.height) return;
    const eye = { x: gaze.x - box.left, y: gaze.y - box.top };
    const end = { x: coin.x - box.left, y: coin.y - box.top };
    const length = Math.max(1, Math.hypot(end.x - eye.x, end.y - eye.y));
    const nx = (-(end.y - eye.y) / length) * 22,
        ny = ((end.x - eye.x) / length) * 22;
    const x = (value) => (value / box.width) * 100,
        y = (value) => (value / box.height) * 100;
    wash.value.setAttribute(
        "d",
        `M${x(eye.x)} ${y(eye.y)} L${x(end.x + nx)} ${y(end.y + ny)} Q${x(end.x)} ${y(end.y)} ${x(end.x - nx)} ${y(end.y - ny)} Z`,
    );
    for (const element of [ray.value, gradient.value]) {
        element.setAttribute("x1", x(eye.x));
        element.setAttribute("y1", y(eye.y));
        element.setAttribute("x2", x(end.x));
        element.setAttribute("y2", y(end.y));
    }
    spot.value.setAttribute("cx", x(end.x));
    spot.value.setAttribute("cy", y(end.y));
    spot.value.setAttribute("rx", x(20));
    spot.value.setAttribute("ry", y(20));
}
defineExpose({ paint });
</script>
<template>
    <svg
        ref="surface"
        class="robot-gaze-beam"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient
                ref="gradient"
                :id="gradientId"
                gradientUnits="userSpaceOnUse"
            >
                <stop offset="0" stop-color="#ddfaff" stop-opacity="1" />
                <stop offset=".55" stop-color="#32b7ff" stop-opacity=".75" />
                <stop offset="1" stop-color="#a0eaff" stop-opacity="1" />
            </linearGradient>
        </defs>
        <path
            ref="wash"
            class="robot-gaze-wash"
            :fill="`url(#${gradientId})`"
        />
        <line
            ref="ray"
            class="robot-gaze-ray"
            :stroke="`url(#${gradientId})`"
            vector-effect="non-scaling-stroke"
        />
        <ellipse
            ref="spot"
            class="robot-gaze-spot"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
<style scoped>
.robot-gaze-beam {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 6;
    pointer-events: none;
    opacity: 0;
    overflow: visible;
    mix-blend-mode: screen;
}
.robot-gaze-wash {
    opacity: 0.62;
    filter: blur(2px);
}
.robot-gaze-ray {
    stroke-width: 2;
    opacity: 0.95;
    filter: drop-shadow(0 0 6px #159dff);
}
.robot-gaze-spot {
    fill: #62d6ff16;
    stroke: #8ee5ff;
    stroke-width: 1.2;
    opacity: 0.72;
    filter: drop-shadow(0 0 7px #178fff);
}
@media (prefers-reduced-motion: reduce) {
    .robot-gaze-beam {
        display: none;
    }
}
</style>
