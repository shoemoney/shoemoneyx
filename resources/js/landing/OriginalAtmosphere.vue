<script setup>
import { onMounted, onUnmounted, ref, watch } from "vue";

const props = defineProps({
    theme: String,
    pairs: Array,
    event: Object,
    motion: Boolean,
    focus: String,
});
const surface = ref(null);
let context,
    frame = 0,
    resize,
    visibility,
    pointerHost,
    width = 1,
    height = 1,
    visible = true;
let clock = 0,
    previous = 0,
    impulse = 0,
    pointer = { x: 0.6, y: 0.5 };
const seeds = Array.from({ length: 160 }, (_, i) => ({
    x: ((i * 73.73) % 101) / 101,
    y: ((i * 37.19) % 97) / 97,
    phase: i * 2.39996,
    speed: 0.2 + (i % 11) / 15,
}));
const colors = {
    pulse: "255,118,59",
    "pulse-blue": "48,182,255",
    neural: "73,188,255",
    terminal: "108,255,165",
    orbit: "177,137,255",
    prism: "74,99,225",
};
const line = (
    points,
    alpha = 0.25,
    weight = 1,
    color = colors[props.theme],
) => {
    context.strokeStyle = `rgba(${color},${alpha})`;
    context.lineWidth = weight;
    context.beginPath();
    points.forEach(([x, y], i) =>
        i ? context.lineTo(x, y) : context.moveTo(x, y),
    );
    context.stroke();
};
function circle(
    x,
    y,
    radius,
    alpha,
    fill = false,
    color = colors[props.theme],
) {
    context.beginPath();
    context.arc(x, y, Math.max(0.2, radius), 0, Math.PI * 2);
    if (fill) {
        context.fillStyle = `rgba(${color},${alpha})`;
        context.fill();
    } else {
        context.strokeStyle = `rgba(${color},${alpha})`;
        context.lineWidth = 1;
        context.stroke();
    }
}
function draw() {
    if (!context || !visible || document.hidden) return;
    context.clearRect(0, 0, width, height);
    const t = clock;
    const cx = width * (0.62 + (pointer.x - 0.5) * 0.025),
        cy = height * 0.52;
    if (props.theme === "pulse" || props.theme === "pulse-blue") {
        // Price traces are real; the travelling shockwaves are artistic accents.
        (props.pairs || []).slice(0, 10).forEach((pair, row) => {
            const values = (pair.history || []).filter(Number.isFinite);
            if (values.length < 2) return;
            const min = Math.min(...values),
                span = Math.max(...values) - min || 1;
            const points = values.map((v, i) => [
                width * 0.37 + (i / (values.length - 1)) * width * 0.63,
                height * 0.13 +
                    row * height * 0.083 +
                    (0.5 - (v - min) / span) * 28,
            ]);
            line(
                points,
                pair.id === props.focus ? 0.85 : 0.14 + row * 0.02,
                pair.id === props.focus ? 2 : 1,
            );
        });
        for (let i = 0; i < 5; i++) {
            const phase = (t * 0.11 + i / 5) % 1;
            circle(
                cx,
                cy,
                phase * width * 0.38,
                (1 - phase) * (0.22 + impulse * 0.22),
            );
        }
        for (let i = 0; i < 58; i++) {
            const s = seeds[i],
                x = ((s.x + t * 0.03 * s.speed) % 1) * width;
            line(
                [
                    [x - 14, s.y * height],
                    [x, s.y * height],
                ],
                0.18 + impulse * 0.3,
            );
        }
    } else if (props.theme === "neural") {
        const nodes = seeds.slice(0, 66).map((s, i) => ({
            x: width * (0.35 + s.x * 0.68) + Math.sin(t * 0.22 + s.phase) * 12,
            y: s.y * height + Math.cos(t * 0.18 + s.phase) * 9,
            i,
        }));
        nodes.forEach((a, i) => {
            nodes.slice(i + 1).forEach((b) => {
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                if (d > width * 0.115) return;
                line(
                    [
                        [a.x, a.y],
                        [b.x, b.y],
                    ],
                    (1 - d / (width * 0.115)) * 0.3,
                );
                if (i % 4 === 0) {
                    const p = (t * 0.32 + seeds[i].x) % 1;
                    circle(
                        a.x + (b.x - a.x) * p,
                        a.y + (b.y - a.y) * p,
                        1.8 + impulse,
                        0.7,
                        true,
                    );
                }
            });
            circle(a.x, a.y, 2 + (i % 3), 0.2 + impulse * 0.3, true);
            if (i % 9 === 0) circle(a.x, a.y, 12 + Math.sin(t + i) * 4, 0.22);
        });
    } else if (props.theme === "terminal") {
        const labels = (props.pairs || []).map(
            (p) => `${p.id} ${p.price == null ? "—" : p.price.toFixed(2)}`,
        );
        context.font = "10px monospace";
        for (let column = 0; column < Math.ceil(width / 85); column++) {
            for (let row = 0; row < 7; row++) {
                const y =
                    ((row / 7 + t * 0.028 * (1 + (column % 3))) % 1) * height;
                context.fillStyle = `rgba(108,255,165,${column < 5 ? 0.045 : 0.13 + (row === column % 7 ? 0.17 : 0)})`;
                context.fillText(
                    labels[(column + row) % Math.max(labels.length, 1)] ||
                        "AWAIT_SIGNAL",
                    column * 85,
                    y,
                );
            }
        }
        const sy = ((t * 0.12) % 1) * height;
        line(
            [
                [0, sy],
                [width, sy],
            ],
            0.18 + impulse * 0.3,
        );
        for (let x = width * 0.5; x < width; x += 32)
            line(
                [
                    [x, 0],
                    [x, height],
                ],
                0.035,
            );
    } else if (props.theme === "orbit") {
        seeds.forEach((s, i) => {
            circle(
                s.x * width,
                s.y * height,
                i % 11 === 0 ? 1.6 : 0.65,
                0.2 + (Math.sin(t * 0.4 + i) + 1) * 0.12,
                true,
            );
        });
        context.save();
        context.translate(cx, cy);
        context.rotate(-0.24);
        for (let i = 0; i < 6; i++) {
            const radius = 55 + i * 30;
            context.beginPath();
            context.ellipse(
                0,
                0,
                radius * 2.1,
                radius * 0.5,
                0,
                0,
                Math.PI * 2,
            );
            context.strokeStyle = `rgba(177,137,255,${0.09 + i * 0.016})`;
            context.stroke();
            const angle = t * (0.12 + i * 0.016) + i * 1.7;
            circle(
                Math.cos(angle) * radius * 2.1,
                Math.sin(angle) * radius * 0.5,
                3 + impulse,
                0.7,
                true,
            );
            for (let tail = 1; tail < 9; tail++)
                circle(
                    Math.cos(angle - tail * 0.028) * radius * 2.1,
                    Math.sin(angle - tail * 0.028) * radius * 0.5,
                    2,
                    (1 - tail / 9) * 0.3,
                    true,
                );
        }
        context.restore();
    } else {
        const palette = [
            "87,121,252",
            "235,108,164",
            "83,192,191",
            "194,219,73",
            "159,119,242",
        ];
        for (let ribbon = 0; ribbon < 13; ribbon++) {
            const points = Array.from({ length: 62 }, (_, i) => {
                const x = (i / 61) * width;
                return [
                    x,
                    height * 0.56 +
                        Math.sin(i * 0.058 + t * 0.22 + ribbon * 0.15) *
                            height *
                            0.29 +
                        ribbon * 5 +
                        (pointer.y - 0.5) * 10,
                ];
            });
            line(points, 0.12, 11, palette[ribbon % palette.length]);
            line(
                points,
                0.3 + impulse * 0.12,
                0.7,
                palette[ribbon % palette.length],
            );
        }
        for (let i = 0; i < 12; i++) {
            const s = seeds[i];
            const x = width * (0.38 + s.x * 0.65),
                y = s.y * height;
            line(
                [
                    [x - 10, y],
                    [x, y - 18],
                    [x + 10, y],
                    [x, y + 18],
                    [x - 10, y],
                ],
                0.25,
                1,
                palette[i % palette.length],
            );
        }
    }
}
function tick(time) {
    frame = 0;
    const dt = Math.min((time - previous) / 1000 || 0, 0.05);
    previous = time;
    clock += dt;
    impulse = Math.max(0, impulse - dt * 0.7);
    draw();
    if (props.motion && visible && !document.hidden)
        frame = requestAnimationFrame(tick);
}
function schedule() {
    cancelAnimationFrame(frame);
    frame = 0;
    previous = performance.now();
    if (!context) return;
    draw();
    if (props.motion && visible && !document.hidden)
        frame = requestAnimationFrame(tick);
}
function move(event) {
    const rect = surface.value?.getBoundingClientRect();
    if (rect && props.motion)
        pointer = {
            x: (event.clientX - rect.left) / rect.width,
            y: (event.clientY - rect.top) / rect.height,
        };
}
onMounted(() => {
    context = surface.value?.getContext("2d");
    if (!context) return;
    resize = new ResizeObserver(([entry]) => {
        width = entry.contentRect.width || 1;
        height = entry.contentRect.height || 1;
        const ratio = Math.min(window.devicePixelRatio || 1, 1.5);
        surface.value.width = width * ratio;
        surface.value.height = height * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        schedule();
    });
    resize.observe(surface.value);
    visibility = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        schedule();
    });
    visibility.observe(surface.value);
    pointerHost = surface.value.parentElement;
    pointerHost.addEventListener("pointermove", move, { passive: true });
    document.addEventListener("visibilitychange", schedule);
});
watch(() => props.motion, schedule);
watch(
    () => props.event?.key,
    () => {
        if (props.motion) impulse = 1;
    },
);
watch(
    () => props.theme,
    () => {
        clock = 0;
        schedule();
    },
);
onUnmounted(() => {
    cancelAnimationFrame(frame);
    resize?.disconnect();
    visibility?.disconnect();
    pointerHost?.removeEventListener("pointermove", move);
    document.removeEventListener("visibilitychange", schedule);
});
</script>

<template>
    <canvas ref="surface" class="pxo-atmosphere" aria-hidden="true"></canvas>
</template>
