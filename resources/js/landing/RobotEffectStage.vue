<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from "vue";
import RobotParticleField from "./RobotParticleField.vue";
import { price, signedCash, compact, ticker } from "./format";
import { clockwiseCoins, coinScanFrame } from "./robotCoinScan";

const props = defineProps({
    effect: { type: String, default: "eye" },
    motion: { type: Boolean, default: true },
    pulse: { type: Number, default: 0 },
    rate: { type: Number, default: 0 },
    pair: { type: Object, default: null },
    pairs: { type: Array, default: () => [] },
    inspection: { type: Number, default: 0 },
    replay: { type: Number, default: 0 },
});
const emit = defineEmits(["inspect", "renderer", "scan", "gaze"]);
const root = ref(null);
const scanHead = ref(null),
    scanShade = ref(null);
const scanEye = ref(null);
let gazeElapsed = 0,
    gazeResize,
    gazeResizeRaf = 0;
const leftArm = ref(null),
    rightArm = ref(null);
const robotArtwork = computed(() =>
    props.effect === "nanites"
        ? "/brand/shoegpt-robot-typing-wide.webp"
        : "/brand/shoegpt-robot-armor.webp",
);
const typingKeys = Array.from({ length: 48 }, (_, index) => {
    const row = Math.floor(index / 16),
        column = index % 16;
    return {
        x: 160 + column * 67 - row * 12,
        y: 1030 + row * 25,
        width: 60 + row * 2,
        phase: index * -0.19,
    };
});
const scanCoins = computed(() => clockwiseCoins(props.pairs));
let scanRaf = 0,
    scanElapsed = 0,
    scanStamp = 0,
    scanId = null;
const mounted = ref(false),
    visible = ref(false),
    hidden = ref(false),
    reduced = ref(false);
const target = ref({ x: 0.82, y: 0.48 });
const assembled = ref(true),
    renderer = ref("CSS");
const headReady = ref(false);
let naniteBackendReady = false,
    naniteEntranceSeen = false,
    headTimer = 0,
    headDelayRemaining = 1400,
    headDelayStarted = 0;
const animations = new Set();
let observer,
    media,
    pointerFrame = 0,
    pendingPointer,
    lastPacket = -Infinity;
const animate = computed(
    () =>
        props.motion &&
        mounted.value &&
        visible.value &&
        !hidden.value &&
        !reduced.value,
);
const particleEffect = computed(() =>
    ["nanites", "plasma", "swarm"].includes(props.effect),
);
const hidePhoto = computed(
    () =>
        props.effect === "nanites" &&
        animate.value &&
        !assembled.value &&
        renderer.value !== "CSS",
);
const activePair = computed(() => props.pair || props.pairs[0] || null);
const crownPairs = computed(() => {
    if (!activePair.value) return [];
    const rest = props.pairs.filter((pair) => pair.id !== activePair.value.id);
    return [activePair.value, ...rest.slice(0, 2)];
});
const visualStyle = computed(() => ({
    "--robot-image": `url('${robotArtwork.value}')`,
    "--pointer-x": `${target.value.x * 100}%`,
    "--pointer-y": `${target.value.y * 100}%`,
    "--lean-x": `${(target.value.x - 0.5) * 20}px`,
    "--lean-y": `${(target.value.y - 0.5) * 12}px`,
    "--echo-x": `${(target.value.x - 0.5) * 36}px`,
    "--echo-y": `${(target.value.y - 0.5) * 20}px`,
}));
const beamTarget = computed(() => ({
    x: target.value.x * 440,
    y: target.value.y * 354,
}));
const eyeBeams = computed(() => {
    const { x, y } = beamTarget.value;
    return [-1, 0, 1].map(
        (side) =>
            `M220 60 C${220 + (x - 220) * 0.28} ${60 + side * 50},${x - (x - 220) * 0.2} ${y + side * 26},${x} ${y}`,
    );
});
const packetPaths = [
    "M12 94H66L120 149H177L220 190",
    "M428 94H374L320 149H263L220 190",
    "M7 241H94L129 207H182L220 190",
    "M433 241H346L311 207H258L220 190",
    "M68 320L106 278H163L220 190",
    "M372 320L334 278H277L220 190",
];
const hexagons = Array.from({ length: 5 }, (_, row) =>
    Array.from({ length: 7 }, (_, column) => {
        const x = 66 + column * 51 + (row % 2) * 25.5,
            y = 75 + row * 44;
        return {
            x,
            y,
            points: Array.from({ length: 6 }, (_, point) => {
                const angle = (Math.PI / 3) * point + Math.PI / 6;
                return `${x + Math.cos(angle) * 29},${y + Math.sin(angle) * 29}`;
            }).join(" "),
        };
    }),
).flat();
const litHex = computed(() => {
    let distance = Infinity,
        closest = 0;
    hexagons.forEach((hex, index) => {
        const next = Math.hypot(
            hex.x - beamTarget.value.x,
            hex.y - beamTarget.value.y,
        );
        if (next < distance) {
            distance = next;
            closest = index;
        }
    });
    return closest;
});

function trackAnimation(element, frames, options) {
    if (!animate.value || !element?.animate) return;
    const animation = element.animate(frames, options);
    animations.add(animation);
    animation.onfinish = () => {
        animations.delete(animation);
        animation.cancel();
    };
    animation.oncancel = () => animations.delete(animation);
}
function cancelAnimations() {
    [...animations].forEach((animation) => animation.cancel());
    animations.clear();
    if (pointerFrame) cancelAnimationFrame(pointerFrame);
    pointerFrame = 0;
}
function burst(reason = "arrival") {
    if (!animate.value || !root.value) return;
    // Inspection/replay rings are illustrative choreography, never market measurements.
    root.value.querySelectorAll(".robot-fx-shock").forEach((element, index) => {
        trackAnimation(
            element,
            [
                { opacity: 0, transform: "scale(.45)" },
                { opacity: 0.75, transform: "scale(.72)", offset: 0.2 },
                { opacity: 0, transform: "scale(1.38)" },
            ],
            {
                duration: props.effect === "gravity" ? 2800 : 1700,
                delay: index * 180,
                easing: "cubic-bezier(.15,.6,.35,1)",
            },
        );
    });
    root.value.querySelectorAll(".robot-fx-impact").forEach((element) => {
        trackAnimation(
            element,
            [
                { opacity: 0.15 },
                { opacity: 0.95, offset: 0.28 },
                { opacity: 0.25 },
            ],
            { duration: 1300, easing: "ease-out" },
        );
    });
    if (props.effect === "eye") {
        root.value
            .querySelectorAll(".robot-fx-eye-beam")
            .forEach((element, index) => {
                trackAnimation(
                    element,
                    [
                        { strokeDashoffset: 64, opacity: 0.25 },
                        { strokeDashoffset: 32, opacity: 1, offset: 0.35 },
                        { strokeDashoffset: 0, opacity: 0.65 },
                    ],
                    { duration: 1800, delay: index * 100, easing: "ease-out" },
                );
            });
    }
    if (["packet", "replay"].includes(reason) && props.effect === "reactor") {
        root.value
            .querySelectorAll(".robot-fx-packet")
            .forEach((element, index) => {
                trackAnimation(
                    element,
                    [
                        { strokeDashoffset: 100, opacity: 0 },
                        { strokeDashoffset: 75, opacity: 1, offset: 0.15 },
                        { strokeDashoffset: 0, opacity: 0 },
                    ],
                    {
                        duration: 1250,
                        delay: index * 45,
                        easing: "cubic-bezier(.3,.1,.35,1)",
                    },
                );
            });
    }
    if (props.effect === "chrome") {
        trackAnimation(
            root.value.querySelector(".robot-fx-chrome-sweep"),
            [
                { transform: "translateX(-25%)", opacity: 0.35 },
                { transform: "translateX(3%)", opacity: 1, offset: 0.5 },
                { transform: "translateX(34%)", opacity: 0.35 },
            ],
            { duration: 2600, easing: "cubic-bezier(.2,.5,.3,1)" },
        );
    }
    if (props.effect === "crown") {
        root.value
            .querySelectorAll(".robot-fx-crown-ray")
            .forEach((element, index) => {
                trackAnimation(
                    element,
                    [
                        { opacity: 0.15 },
                        { opacity: 1, offset: 0.3 },
                        { opacity: 0.45 },
                    ],
                    { duration: 1400, delay: index * 65, easing: "ease-out" },
                );
            });
        root.value
            .querySelectorAll(".robot-fx-crown-quote")
            .forEach((element, index) => {
                trackAnimation(
                    element,
                    [
                        { boxShadow: "0 0 0 #168aff00" },
                        { boxShadow: "0 0 28px #339fff70", offset: 0.35 },
                        { boxShadow: "0 0 20px #168aff18" },
                    ],
                    { duration: 1700, delay: index * 220, easing: "ease-out" },
                );
            });
    }
    if (props.effect === "echoes") {
        root.value
            .querySelectorAll(".robot-fx-ghost")
            .forEach((element, index) => {
                const direction = index % 2 ? 1 : -1;
                trackAnimation(
                    element,
                    [
                        {
                            transform: `translateX(${direction * 3}px)`,
                            opacity: 0.18,
                        },
                        {
                            transform: `translateX(${direction * (index + 1) * 19}px) scale(1.04)`,
                            opacity: 0.42,
                            offset: 0.45,
                        },
                        {
                            transform: `translateX(${direction * (index + 1) * 11}px)`,
                            opacity: 0.3,
                        },
                    ],
                    {
                        duration: 2800,
                        delay: index * 90,
                        easing: "cubic-bezier(.15,.55,.3,1)",
                    },
                );
            });
    }
}
function aimAtPair(pair) {
    if (!pair) return;
    const index = Math.max(
        0,
        props.pairs.findIndex((item) => item.id === pair.id),
    );
    const angle =
        -Math.PI / 2 +
        ((index + 0.7) / Math.max(props.pairs.length, 6)) * Math.PI * 2;
    target.value = {
        x: 0.5 + Math.cos(angle) * 0.43,
        y: 0.5 + Math.sin(angle) * 0.38,
    };
}
function pointerPosition(event) {
    const box = root.value?.getBoundingClientRect();
    if (!box?.width) return null;
    // All effects and the particle field share the natural image box, centered in the stage.
    const imageHeight = (box.width * 1126) / 1397;
    const imageTop = box.top + (box.height - imageHeight) / 2;
    return {
        x: Math.min(
            0.96,
            Math.max(0.04, (event.clientX - box.left) / box.width),
        ),
        y: Math.min(
            0.94,
            Math.max(0.06, (event.clientY - imageTop) / imageHeight),
        ),
    };
}
function onPointerMove(event) {
    if (!animate.value) return;
    pendingPointer = pointerPosition(event);
    if (!pendingPointer || pointerFrame) return;
    pointerFrame = requestAnimationFrame(() => {
        target.value = pendingPointer;
        pointerFrame = 0;
    });
}
function onPointerDown(event) {
    if (event.target?.closest("button")) return;
    const position = pointerPosition(event);
    if (position) target.value = position;
    burst("interaction");
}
function onKeydown(event) {
    if (event.target !== root.value) return;
    const directions = {
        ArrowLeft: [-0.12, 0],
        ArrowRight: [0.12, 0],
        ArrowUp: [0, -0.12],
        ArrowDown: [0, 0.12],
    };
    if (directions[event.key]) {
        event.preventDefault();
        const [x, y] = directions[event.key];
        target.value = {
            x: Math.min(0.94, Math.max(0.06, target.value.x + x)),
            y: Math.min(0.9, Math.max(0.1, target.value.y + y)),
        };
        burst("interaction");
    } else if (event.key === "Enter" && activePair.value?.id) {
        event.preventDefault();
        emit("inspect", activePair.value.id);
    }
}
function stopHeadDelay(reset = false) {
    if (headTimer) {
        clearTimeout(headTimer);
        headTimer = 0;
        headDelayRemaining = Math.max(
            0,
            headDelayRemaining - (performance.now() - headDelayStarted),
        );
    }
    if (reset) {
        headReady.value = false;
        headDelayRemaining = 1400;
        stopCoinScan(true);
    }
}
function drawCoinScan() {
    const pose = coinScanFrame(scanCoins.value, scanElapsed);
    if (scanHead.value) {
        scanHead.value.style.transform =
            `perspective(700px) translate(${pose.x * 1.35}%, ${-pose.y * 0.35}%) ` +
            `rotateY(${pose.x * 23}deg) rotateX(${pose.y * 7}deg) rotateZ(${-pose.x * 1.1}deg)`;
    }
    if (scanShade.value) {
        scanShade.value.style.opacity = String(Math.abs(pose.x) * 0.6);
        scanShade.value.style.transform = `scaleX(${pose.x > 0 ? -1 : 1})`;
    }
    // Alternating forearm taps share the gaze clock, so pause/visibility freezes
    // the entire character and movement begins only after formation finishes.
    const leftTap = scanElapsed ? (Math.sin(scanElapsed / 145) + 1) / 2 : 0;
    const rightTap = scanElapsed
        ? (Math.sin(scanElapsed / 181 + 2.4) + 1) / 2
        : 0;
    if (leftArm.value)
        leftArm.value.style.transform = `translateY(${leftTap * 0.22}%) rotate(${leftTap * 0.7}deg)`;
    if (rightArm.value)
        rightArm.value.style.transform = `translateY(${rightTap * 0.22}%) rotate(${-rightTap * 0.7}deg)`;
    if (pose.id !== scanId) {
        scanId = pose.id;
        emit("scan", scanId);
    }
    if (root.value) root.value.dataset.scanCoin = scanId || "";
    if (scanElapsed === 0 || scanElapsed - gazeElapsed >= 32) {
        gazeElapsed = scanElapsed;
        emitGaze(pose);
    }
}
function emitGaze(pose) {
    if (!scanEye.value) return;
    const eye = scanEye.value.getBoundingClientRect();
    scanEye.value.style.filter = `brightness(${1 + pose.light * 0.75}) drop-shadow(0 0 ${3 + pose.light * 5}px #74dcff)`;
    emit("gaze", {
        id: pose.id,
        light: pose.light,
        x: eye.left + eye.width / 2,
        y: eye.top + eye.height / 2,
    });
}
function stopCoinScan(reset = false) {
    cancelAnimationFrame(scanRaf);
    scanRaf = 0;
    scanStamp = 0;
    if (reset) {
        scanElapsed = 0;
        drawCoinScan();
    }
}
function startCoinScan() {
    if (
        scanRaf ||
        !animate.value ||
        !headReady.value ||
        props.effect !== "nanites" ||
        !scanCoins.value.length
    )
        return;
    const tick = (stamp) => {
        if (!animate.value || !headReady.value || props.effect !== "nanites") {
            stopCoinScan();
            return;
        }
        if (scanStamp) scanElapsed += Math.min(stamp - scanStamp, 50);
        scanStamp = stamp;
        drawCoinScan();
        scanRaf = requestAnimationFrame(tick);
    };
    scanRaf = requestAnimationFrame(tick);
}
function scheduleHeadTurn() {
    if (
        props.effect !== "nanites" ||
        !animate.value ||
        !assembled.value ||
        !naniteBackendReady ||
        (!naniteEntranceSeen && renderer.value !== "CSS") ||
        headReady.value ||
        headTimer
    )
        return;
    // The assembly callback precedes its particle fade by 1.35 seconds.
    // Keep both heads aligned until those final source-image particles disappear.
    headDelayStarted = performance.now();
    headTimer = window.setTimeout(() => {
        headTimer = 0;
        headDelayRemaining = 0;
        if (animate.value && assembled.value && props.effect === "nanites")
            headReady.value = true;
    }, headDelayRemaining);
}
function setAssembled(value) {
    assembled.value = value;
    if (props.effect !== "nanites") return;
    if (!value) {
        naniteEntranceSeen = true;
        stopHeadDelay(true);
    } else scheduleHeadTurn();
}
function setRenderer(value) {
    renderer.value = value;
    emit("renderer", value);
    if (props.effect === "nanites") {
        naniteBackendReady = true;
        scheduleHeadTurn();
    }
}
function syncVisibility() {
    hidden.value = document.hidden;
}
function syncMotion() {
    reduced.value = media.matches;
}
watch(
    () => props.pair?.id,
    () => aimAtPair(activePair.value),
);
watch(
    () => props.pulse,
    (value, previous) => {
        if (!animate.value || value <= previous) return;
        const now = performance.now();
        if (now - lastPacket < 1250) return;
        lastPacket = now;
        if (
            props.effect === "reactor" ||
            props.effect === "shield" ||
            props.effect === "eye"
        )
            burst("packet");
    },
);
watch(
    () => props.replay,
    () => {
        if (props.effect === "nanites") {
            stopHeadDelay(true);
            scheduleHeadTurn();
        }
        burst("replay");
    },
);
watch(
    () => props.inspection,
    () => burst("inspection"),
);
watch(
    () => props.effect,
    () => {
        cancelAnimations();
        stopHeadDelay(true);
        naniteBackendReady = false;
        naniteEntranceSeen = false;
        assembled.value = true;
        renderer.value = "CSS";
        if (!particleEffect.value) setRenderer("CSS / SVG");
        burst("arrival");
    },
    { flush: "post" },
);
watch(animate, (enabled) => {
    if (!enabled) {
        cancelAnimations();
        stopHeadDelay();
        stopCoinScan(reduced.value);
    } else {
        burst("arrival");
        scheduleHeadTurn();
        startCoinScan();
    }
});
watch(headReady, (ready) => (ready ? startCoinScan() : stopCoinScan(true)));
watch(
    () => props.pairs.map((pair) => pair.id).join(","),
    () => {
        stopCoinScan(true);
        startCoinScan();
    },
);
watch(reduced, (value) => {
    if (value) stopCoinScan(true);
});
onMounted(() => {
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    syncMotion();
    syncVisibility();
    media.addEventListener("change", syncMotion);
    document.addEventListener("visibilitychange", syncVisibility);
    observer = new IntersectionObserver(
        ([entry]) => {
            visible.value =
                entry.isIntersecting && entry.intersectionRatio >= 0.2;
        },
        { threshold: 0.2 },
    );
    observer.observe(root.value);
    gazeResize = new ResizeObserver(() => {
        cancelAnimationFrame(gazeResizeRaf);
        gazeResizeRaf = requestAnimationFrame(() =>
            emitGaze(coinScanFrame(scanCoins.value, scanElapsed)),
        );
    });
    gazeResize.observe(root.value);
    aimAtPair(activePair.value);
    mounted.value = true;
    if (!particleEffect.value) setRenderer("CSS / SVG");
});
onUnmounted(() => {
    cancelAnimations();
    stopHeadDelay(true);
    gazeResize?.disconnect();
    cancelAnimationFrame(gazeResizeRaf);
    observer?.disconnect();
    media?.removeEventListener("change", syncMotion);
    document.removeEventListener("visibilitychange", syncVisibility);
});
</script>

<template>
    <div
        ref="root"
        class="robot-effect-stage"
        :class="[
            `robot-effect-stage--${effect}`,
            { 'robot-effect-stage--still': !animate },
        ]"
        :style="visualStyle"
        :data-effect="effect"
        :data-renderer="renderer"
        :data-motion="animate ? 'running' : 'paused'"
        :data-assembled="assembled"
        :data-head-swivel="
            effect === 'nanites'
                ? headReady
                    ? animate
                        ? 'running'
                        : 'paused'
                    : 'waiting'
                : undefined
        "
        role="group"
        tabindex="0"
        :aria-label="`ShoeGPT ${effect} effect. Arrow keys aim the effect. Enter inspects ${activePair?.id || 'the selected market'}.`"
        @pointermove="onPointerMove"
        @pointerdown="onPointerDown"
        @pointerleave="aimAtPair(activePair)"
        @keydown="onKeydown"
    >
        <div class="robot-fx-image-box">
            <div class="robot-fx-atmosphere" aria-hidden="true"></div>

            <div
                v-if="effect === 'gravity'"
                class="robot-fx-gravity"
                aria-hidden="true"
            >
                <i
                    v-for="ring in 7"
                    :key="ring"
                    class="robot-fx-gravity-orbit"
                    :style="{ '--ring': ring }"
                ></i>
                <i
                    v-for="ring in 4"
                    :key="`shock-${ring}`"
                    class="robot-fx-shock robot-fx-gravity-shock"
                    :style="{ '--ring': ring }"
                ></i>
                <span class="robot-fx-gravity-lens"></span>
            </div>
            <div
                v-if="effect === 'echoes'"
                class="robot-fx-echoes"
                aria-hidden="true"
            >
                <img
                    v-for="echo in 4"
                    :key="echo"
                    class="robot-fx-ghost"
                    :style="{ '--echo': echo }"
                    :src="robotArtwork"
                    alt=""
                    width="1397"
                    height="1126"
                    draggable="false"
                />
                <i class="robot-fx-echo-orbit"></i>
                <i class="robot-fx-echo-orbit robot-fx-echo-orbit--second"></i>
            </div>
            <div
                v-if="effect === 'crown'"
                class="robot-fx-crown"
                aria-hidden="true"
            >
                <i
                    v-for="ray in 18"
                    :key="ray"
                    class="robot-fx-crown-ray"
                    :style="{ '--ray': ray }"
                ></i>
                <i class="robot-fx-crown-orbit"></i>
                <i class="robot-fx-crown-orbit robot-fx-crown-orbit--tilt"></i>
            </div>

            <div
                class="robot-fx-portrait"
                :class="{ 'robot-fx-portrait--assembling': hidePhoto }"
            >
                <template v-if="effect === 'nanites'">
                    <img
                        class="robot-fx-photo robot-fx-typing-underlay"
                        :src="'/brand/shoegpt-robot-armor.webp'"
                        alt=""
                        width="1397"
                        height="1126"
                        draggable="false"
                    />
                    <span
                        class="robot-fx-nanite-neck"
                        aria-hidden="true"
                    ></span>
                    <div class="robot-fx-typing-body">
                        <img
                            class="robot-fx-photo robot-fx-nanite-body"
                            :src="robotArtwork"
                            alt="ShoeGPT robot"
                            width="1397"
                            height="1126"
                            draggable="false"
                            decoding="async"
                        />
                    </div>
                    <svg
                        class="robot-fx-keyboard"
                        viewBox="0 0 1397 1126"
                        aria-hidden="true"
                    >
                        <path
                            class="robot-fx-keyboard-deck"
                            d="M135 1014H1262L1317 1120H80Z"
                        />
                        <rect
                            v-for="(key, index) in typingKeys"
                            :key="index"
                            :x="key.x"
                            :y="key.y"
                            :width="key.width"
                            height="19"
                            rx="4"
                            :style="{ '--key-phase': `${key.phase}s` }"
                        />
                    </svg>
                    <img
                        ref="leftArm"
                        class="robot-fx-photo robot-fx-typing-arm robot-fx-typing-arm--left"
                        :src="robotArtwork"
                        alt=""
                        width="1397"
                        height="1126"
                        draggable="false"
                    />
                    <img
                        ref="rightArm"
                        class="robot-fx-photo robot-fx-typing-arm robot-fx-typing-arm--right"
                        :src="robotArtwork"
                        alt=""
                        width="1397"
                        height="1126"
                        draggable="false"
                    />
                    <span
                        ref="scanHead"
                        class="robot-fx-nanite-head"
                        :class="{ 'robot-fx-nanite-head--awake': headReady }"
                        aria-hidden="true"
                    >
                        <img
                            class="robot-fx-photo"
                            :src="robotArtwork"
                            alt=""
                            width="1397"
                            height="1126"
                            draggable="false"
                            decoding="async"
                        />
                        <span ref="scanEye" class="robot-fx-eye-light"></span>
                        <span
                            ref="scanShade"
                            class="robot-fx-nanite-head-shade"
                        ></span>
                    </span>
                </template>
                <template v-else>
                    <img
                        class="robot-fx-photo"
                        :src="robotArtwork"
                        alt="ShoeGPT robot"
                        width="1397"
                        height="1126"
                        draggable="false"
                        decoding="async"
                    />
                    <span class="robot-fx-eye-light" aria-hidden="true"></span>
                    <template v-if="effect === 'chrome'">
                        <span class="robot-fx-chrome-surface" aria-hidden="true"
                            ><i class="robot-fx-chrome-sweep"></i
                        ></span>
                        <span
                            class="robot-fx-chrome-light"
                            aria-hidden="true"
                        ></span>
                    </template>
                </template>
            </div>

            <RobotParticleField
                v-if="particleEffect"
                :key="effect"
                class="robot-fx-particle-field"
                :effect="effect"
                :motion="animate"
                :pulse="pulse"
                :rate="rate"
                :target="target"
                :replay="replay"
                @assembled="setAssembled"
                @renderer="setRenderer"
            />

            <svg
                v-if="effect === 'eye'"
                class="robot-fx-vector robot-fx-eye"
                viewBox="0 0 440 354"
                fill="none"
                aria-hidden="true"
            >
                <circle class="robot-fx-eye-iris" cx="220" cy="60" r="31" />
                <circle
                    class="robot-fx-eye-iris robot-fx-eye-iris--outer"
                    cx="220"
                    cy="60"
                    r="45"
                />
                <path
                    v-for="(path, index) in eyeBeams"
                    :key="index"
                    class="robot-fx-eye-beam"
                    :class="{ 'robot-fx-eye-beam--core': index === 1 }"
                    :d="path"
                    pathLength="100"
                />
                <g
                    class="robot-fx-eye-target"
                    :transform="`translate(${beamTarget.x} ${beamTarget.y})`"
                >
                    <circle class="robot-fx-impact" r="19" />
                    <circle r="5" />
                    <path
                        d="M-29 0H-22M22 0H29M0-29V-22M0 22V29M-14-22H-22V-14M14 22H22V14"
                    />
                </g>
            </svg>

            <svg
                v-if="effect === 'reactor'"
                class="robot-fx-vector robot-fx-reactor"
                viewBox="0 0 440 354"
                fill="none"
                aria-hidden="true"
            >
                <path
                    v-for="(path, index) in packetPaths"
                    :key="`track-${index}`"
                    class="robot-fx-packet-track"
                    :d="path"
                />
                <path
                    v-for="(path, index) in packetPaths"
                    :key="`packet-${index}`"
                    class="robot-fx-packet"
                    :d="path"
                    pathLength="100"
                />
                <g class="robot-fx-reactor-wheel">
                    <circle cx="220" cy="190" r="66" />
                    <circle cx="220" cy="190" r="56" />
                    <path
                        v-for="segment in 12"
                        :key="segment"
                        :transform="`rotate(${segment * 30} 220 190)`"
                        d="M220 116V123M214 118H226"
                    />
                </g>
                <circle
                    class="robot-fx-reactor-inner robot-fx-impact"
                    cx="220"
                    cy="190"
                    r="38"
                />
                <circle
                    v-for="ring in 3"
                    :key="ring"
                    class="robot-fx-shock robot-fx-reactor-shock"
                    cx="220"
                    cy="190"
                    :r="64 + ring * 14"
                />
            </svg>
            <div v-if="effect === 'reactor'" class="robot-fx-packet-count">
                <span>FEED UPDATES</span><strong>{{ compact(pulse) }}</strong
                ><i></i>
            </div>

            <svg
                v-if="effect === 'shield'"
                class="robot-fx-vector robot-fx-shield"
                viewBox="0 0 440 354"
                fill="none"
                aria-hidden="true"
            >
                <path
                    class="robot-fx-shield-perimeter"
                    d="M220 15L383 88V217L332 271L220 340L108 271L57 217V88Z"
                />
                <polygon
                    v-for="(hex, index) in hexagons"
                    :key="index"
                    :points="hex.points"
                    class="robot-fx-hex"
                    :class="{
                        'robot-fx-hex--active': litHex === index,
                        'robot-fx-hex--adjacent':
                            Math.abs(litHex - index) === 1,
                    }"
                />
                <g
                    class="robot-fx-shield-intersection"
                    :transform="`translate(${hexagons[litHex].x} ${hexagons[litHex].y})`"
                >
                    <circle class="robot-fx-impact" r="35" />
                    <path d="M-44 0H44M0-44V44" />
                    <circle r="4" />
                </g>
            </svg>

            <div
                v-if="effect === 'chrome'"
                class="robot-fx-chrome-frame"
                aria-hidden="true"
            >
                <i></i><i></i><i></i>
            </div>
            <div v-if="effect === 'crown'" class="robot-fx-crown-quotes">
                <button
                    v-for="(market, index) in crownPairs"
                    :key="market.id"
                    type="button"
                    class="robot-fx-crown-quote"
                    :class="`robot-fx-crown-quote--${index}`"
                    :aria-label="`Inspect ${market.id}, price ${price(market.price)}, P and L ${signedCash(market.pnl)}`"
                    @click="emit('inspect', market.id)"
                >
                    <span
                        >{{ ticker(market.id) }} <small>/ USD</small
                        ><i
                            class="fa-solid fa-arrow-up-right"
                            aria-hidden="true"
                        ></i
                    ></span>
                    <strong>{{ price(market.price) }}</strong>
                    <em :class="{ 'robot-fx-negative': market.pnl < 0 }"
                        >{{ signedCash(market.pnl) }} <small>P&amp;L</small></em
                    >
                </button>
            </div>
            <div
                v-if="effect === 'echoes'"
                class="robot-fx-echo-traces"
                aria-hidden="true"
            >
                <i></i><i></i><i></i><i></i>
            </div>
        </div>
    </div>
</template>

<style scoped>
.robot-effect-stage {
    position: relative;
    display: block;
    width: min(440px, 100%);
    aspect-ratio: 1.15;
    flex: 0 0 auto;
    isolation: isolate;
    pointer-events: auto;
    touch-action: pan-y;
    color: #8dd8ff;
    outline: none;
    border-radius: 32%;
    -webkit-tap-highlight-color: transparent;
}
.robot-effect-stage:focus-visible {
    outline: 1px solid #77cfff;
    outline-offset: 6px;
}
.robot-fx-image-box {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    aspect-ratio: 1397 / 1126;
    transform: translateY(-50%);
}
.robot-fx-atmosphere {
    position: absolute;
    inset: -8% -4%;
    border-radius: 50%;
    background: radial-gradient(
        ellipse at 50% 42%,
        #1689ff28,
        #0047bc10 48%,
        transparent 70%
    );
    pointer-events: none;
}
.robot-fx-portrait {
    position: absolute;
    inset: 0;
    mask-image: linear-gradient(#000 0 76%, #000d 86%, transparent 100%);
    pointer-events: none;
    transition: opacity 0.45s;
}
.robot-fx-photo {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 0 13px #34a9ff36);
    user-select: none;
}
.robot-fx-portrait--assembling {
    opacity: 0;
}
.robot-effect-stage--nanites .robot-fx-portrait {
    isolation: isolate;
    mask-image: linear-gradient(#000 0 98%, transparent 100%);
}
.robot-fx-typing-underlay,
.robot-fx-typing-body,
.robot-fx-typing-arm,
.robot-fx-keyboard {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
}
.robot-fx-typing-underlay {
    z-index: 0;
    clip-path: polygon(32% 70%, 68% 70%, 68% 100%, 32% 100%);
}
.robot-fx-typing-body {
    z-index: 2;
    clip-path: inset(0 0 24% 0);
}
.robot-fx-typing-arm {
    /* The complete moving hand silhouette must occlude the keyboard. */
    z-index: 3;
    will-change: transform;
}
.robot-fx-typing-arm--left {
    clip-path: polygon(
        0 74%,
        26% 74%,
        26% 81%,
        31.5% 86%,
        36% 92%,
        36% 100%,
        0 100%
    );
    transform-origin: 15% 79%;
}
.robot-fx-typing-arm--right {
    clip-path: polygon(
        100% 74%,
        74% 74%,
        74% 81%,
        68.5% 86%,
        64% 92%,
        64% 100%,
        100% 100%
    );
    transform-origin: 85% 79%;
}
.robot-fx-keyboard {
    z-index: 1;
}
.robot-fx-keyboard-deck {
    fill: #02152ad9;
    stroke: #51c8ffc7;
    stroke-width: 3;
    filter: drop-shadow(0 0 12px #168aff66);
}
.robot-fx-keyboard rect {
    fill: #188eea24;
    stroke: #73d7ffb3;
    stroke-width: 2;
    animation: robot-fx-key-tap 2.9s ease-in-out infinite;
    animation-delay: var(--key-phase);
}
@keyframes robot-fx-key-tap {
    0%,
    65%,
    100% {
        fill: #188eea24;
    }
    80% {
        fill: #48c7ff80;
    }
}
/* Nanites alone split the supplied image at the jaw. The inverse cut keeps
   the shoulders, mechanical neck and original chest emblem completely still. */
.robot-fx-nanite-body {
    position: relative;
    clip-path: polygon(
        0 0,
        39.5% 0,
        39.5% 29.5%,
        42.5% 32%,
        46% 34.7%,
        54% 34.7%,
        57.5% 32%,
        60.5% 29.5%,
        60.5% 0,
        100% 0,
        100% 100%,
        0 100%
    );
}
.robot-fx-nanite-neck {
    position: absolute;
    z-index: 2;
    left: 43%;
    top: 29.6%;
    width: 14%;
    height: 7.3%;
    border-radius: 30% 30% 43% 43%;
    background:
        linear-gradient(
            90deg,
            #071423,
            #132b3a 12%,
            #07101a 28% 72%,
            #152c3b 88%,
            #071423
        ),
        #07101a;
    box-shadow: inset 0 4px 8px #020609;
}
.robot-fx-nanite-head {
    position: absolute;
    z-index: 4;
    inset: 0;
    clip-path: polygon(
        39.5% 0,
        60.5% 0,
        60.5% 29.5%,
        57.5% 32%,
        54% 34.7%,
        46% 34.7%,
        42.5% 32%,
        39.5% 29.5%
    );
    transform-origin: 50% 34.2%;
    transform: perspective(700px) rotateY(0deg);
    backface-visibility: hidden;
}
.robot-fx-nanite-head--awake {
    will-change: transform;
}
.robot-fx-nanite-head-shade {
    position: absolute;
    inset: 0;
    mask-image: var(--robot-image);
    mask-size: contain;
    mask-position: center;
    mask-repeat: no-repeat;
    background: linear-gradient(
        90deg,
        transparent 39%,
        #00152b59 43%,
        transparent 50%,
        #b9e7ff36 57%,
        transparent 61%
    );
    opacity: 0;
    pointer-events: none;
}
.robot-fx-eye-light {
    position: absolute;
    left: 50%;
    top: 17%;
    width: 8%;
    aspect-ratio: 1;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    background: radial-gradient(
        circle,
        #c9f8ff99,
        #48bfff66 20%,
        #147dff33 35%,
        transparent 70%
    );
    mix-blend-mode: screen;
    animation: robot-fx-eye-breathe 4.6s ease-in-out infinite;
}
.robot-fx-particle-field {
    position: absolute;
    inset: 0;
    pointer-events: none;
}
.robot-fx-vector {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: visible;
    pointer-events: none;
    stroke: #65c7ff;
    stroke-width: 1.1;
}
.robot-fx-shock {
    opacity: 0;
    transform-box: fill-box;
    transform-origin: center;
    pointer-events: none;
}
.robot-fx-impact {
    opacity: 0.45;
}

/* Eye: a lens, a directional three-strand beam, and a live focus reticle. */
.robot-effect-stage--eye .robot-fx-eye-light {
    width: 21%;
    background: radial-gradient(
        circle,
        #d5f8ffcc 0 3%,
        #4bc6ff70 7%,
        #177fff28 28%,
        transparent 65%
    );
}
.robot-fx-eye-iris {
    stroke: #69d8ffb3;
    stroke-dasharray: 15 7 2 7;
    transform-origin: 220px 60px;
    animation: robot-fx-turn 13s linear infinite;
    filter: drop-shadow(0 0 5px #007fff);
}
.robot-fx-eye-iris--outer {
    stroke: #2a90ff70;
    stroke-dasharray: 2 9;
    animation-direction: reverse;
    animation-duration: 19s;
}
.robot-fx-eye-beam {
    stroke: #38a7ff;
    stroke-width: 1.2;
    opacity: 0.65;
    filter: drop-shadow(0 0 4px #147dff);
}
.robot-fx-eye-beam--core {
    stroke: #b9f2ff;
    stroke-width: 2;
    stroke-dasharray: 9 3 1 3;
    opacity: 0.95;
    animation: robot-fx-beam-flow 3.2s linear infinite;
}
.robot-fx-eye-target {
    stroke: #77d5ff;
    filter: drop-shadow(0 0 4px #1c89ff);
}
.robot-fx-eye-target > circle:first-child {
    fill: #127dff12;
}
.robot-fx-eye-target > circle:nth-child(2) {
    fill: #b6f2ff;
    stroke: none;
}

/* Reactor: routes stay visible; traveling packets only fire on received updates. */
.robot-effect-stage--reactor .robot-fx-atmosphere {
    background: radial-gradient(ellipse at 50% 56%, #197dff3d, transparent 68%);
}
.robot-fx-packet-track {
    stroke: #329dfd6b;
    stroke-width: 1.5;
}
.robot-fx-packet {
    stroke: #c2f4ff;
    stroke-width: 3;
    stroke-dasharray: 8 92;
    opacity: 0;
    filter: drop-shadow(0 0 5px #25b0ff);
}
.robot-fx-reactor-wheel {
    stroke: #5bc9ffbd;
    transform-origin: 220px 190px;
    animation: robot-fx-turn 30s linear infinite;
    filter: drop-shadow(0 0 5px #168bff);
}
.robot-fx-reactor-wheel > circle:first-child {
    stroke-width: 3;
    stroke-dasharray: 34 10 6 10;
}
.robot-fx-reactor-wheel > circle:nth-child(2) {
    stroke: #beeaff;
    stroke-dasharray: 2 11;
}
.robot-fx-reactor-inner {
    stroke: #b9f2ff;
    stroke-width: 2;
    fill: #1469ff17;
    filter: drop-shadow(0 0 7px #219fff);
}
.robot-fx-reactor-shock {
    stroke: #77d5ff;
    stroke-width: 1.2;
}
.robot-fx-packet-count {
    position: absolute;
    left: 50%;
    bottom: 2%;
    transform: translateX(-50%);
    min-width: 144px;
    display: grid;
    grid-template-columns: 1fr auto;
    column-gap: 10px;
    align-items: center;
    padding: 7px 10px;
    border-top: 1px solid #4db7ff70;
    background: linear-gradient(90deg, #03091100, #030911e6 12% 88%, #03091100);
    pointer-events: none;
}
.robot-fx-packet-count > span {
    font: 600 10px/1.3 var(--font-mono, monospace);
    letter-spacing: 0.08em;
    color: #c5dff5;
}
.robot-effect-stage .robot-fx-packet-count > strong {
    font: 700 17px/1.2 var(--font-mono, monospace);
    color: #8edbff;
    letter-spacing: 0;
    text-shadow: none;
}
.robot-fx-packet-count > i {
    position: absolute;
    top: -2px;
    left: 35%;
    width: 30%;
    height: 2px;
    background: #a1e4ff;
    box-shadow: 0 0 12px #228cff;
}

/* Shield: a curved hex cage with pointer/pair intersections illuminated on its face. */
.robot-fx-shield {
    mask-image: radial-gradient(ellipse, #000 15% 65%, transparent 81%);
}
.robot-fx-shield-perimeter {
    stroke-width: 2.5;
    stroke: #6ccfffba;
    fill: #0a6cff0b;
    filter: drop-shadow(0 0 7px #2d9aff);
}
.robot-fx-hex {
    stroke: #64bcff49;
    stroke-width: 0.9;
    fill: #0673ff06;
    transition:
        fill 0.35s,
        stroke 0.35s;
}
.robot-fx-hex--active {
    stroke: #c5f4ff;
    stroke-width: 2;
    fill: #25a2ff47;
    filter: drop-shadow(0 0 9px #1aa6ff);
}
.robot-fx-hex--adjacent {
    stroke: #60caffb3;
    fill: #1584ff20;
}
.robot-fx-shield-intersection {
    stroke: #a8e8ff;
    stroke-width: 1;
    filter: drop-shadow(0 0 5px #349cff);
}
.robot-fx-shield-intersection > circle:last-child {
    fill: #d5f8ff;
}
.robot-effect-stage--shield .robot-fx-eye-light {
    width: 11%;
}

/* Chrome lighting is clipped by the actual supplied image alpha, including arm gaps. */
.robot-effect-stage--chrome .robot-fx-photo {
    filter: contrast(1.15) saturate(0.6) drop-shadow(0 0 12px #408bff50);
}
.robot-fx-chrome-surface,
.robot-fx-chrome-light {
    position: absolute;
    inset: 0;
    mask-image: var(--robot-image);
    mask-size: contain;
    mask-position: center;
    mask-repeat: no-repeat;
    mix-blend-mode: screen;
    pointer-events: none;
}
.robot-fx-chrome-surface {
    overflow: hidden;
    opacity: 0.5;
}
.robot-fx-chrome-sweep {
    position: absolute;
    inset: -40% -75%;
    background: linear-gradient(
        112deg,
        transparent 25%,
        #1448ff18 31%,
        #8ce5ff95 34%,
        #e8fcffc9 37%,
        transparent 42%,
        #ba8cff37 46%,
        transparent 53%
    );
    transform: translateX(-18%);
    animation: robot-fx-chrome-sweep 8s ease-in-out infinite alternate;
}
.robot-fx-chrome-light {
    background: radial-gradient(
        ellipse at var(--pointer-x) var(--pointer-y),
        #dbf8ffbd,
        #208fff40 18%,
        transparent 44%
    );
    opacity: 0.7;
}
.robot-fx-chrome-frame {
    position: absolute;
    inset: 3% 14% 13%;
    pointer-events: none;
}
.robot-fx-chrome-frame > i {
    position: absolute;
    inset: 0;
    border: 1px solid #8fc8ff50;
    border-radius: 44%;
    transform: rotate(-22deg);
    box-shadow:
        inset 0 0 22px #3987ff0d,
        0 0 14px #338bff13;
}
.robot-fx-chrome-frame > i:nth-child(2) {
    inset: 3% -9%;
    transform: rotate(27deg);
    border-color: #bdb5ff47;
}
.robot-fx-chrome-frame > i:nth-child(3) {
    inset: -7% 5%;
    transform: rotate(62deg);
    border-style: dashed;
    border-color: #addfff35;
}

/* Quote crown: three real markets orbit a radial coronet; every plaque is inspectable. */
.robot-fx-crown {
    position: absolute;
    inset: -3% 0 0;
    pointer-events: none;
}
.robot-fx-crown-ray {
    position: absolute;
    left: 50%;
    top: 25%;
    width: 2px;
    height: 15%;
    background: linear-gradient(#a8eaff, #259dff77, transparent);
    transform-origin: 0 0;
    transform: rotate(calc(var(--ray) * 20deg)) translateY(-112%);
    box-shadow: 0 0 10px #228fff40;
    opacity: 0.5;
}
.robot-fx-crown-ray:nth-child(3n) {
    height: 22%;
    width: 3px;
    opacity: 0.8;
}
.robot-fx-crown-orbit {
    position: absolute;
    left: 10%;
    right: 10%;
    top: 9%;
    height: 30%;
    border: 1px solid #7ecfffc2;
    border-radius: 50%;
    transform: rotate(-12deg);
    box-shadow:
        0 0 14px #1885ff32,
        inset 0 0 12px #238aff1c;
}
.robot-fx-crown-orbit--tilt {
    left: 16%;
    right: 16%;
    top: 0;
    height: 43%;
    transform: rotate(25deg);
    border-color: #398cffa8;
    border-style: dashed;
}
.robot-fx-crown-quotes {
    position: absolute;
    inset: 0;
    pointer-events: none;
}
.robot-fx-crown-quote {
    position: absolute;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    width: 137px;
    box-sizing: border-box;
    min-width: 0;
    padding: 9px 11px;
    color: #b8eaff;
    background: linear-gradient(130deg, #071a2beb, #020710f5);
    border: 1px solid #3c9ffa90;
    border-radius: 8px 2px 8px 2px;
    box-shadow:
        0 0 20px #168aff18,
        inset 0 1px #c1eeff24;
    text-align: left;
    pointer-events: auto;
    cursor: pointer;
    transition:
        border-color 0.2s,
        background 0.2s,
        box-shadow 0.2s;
}
.robot-fx-crown-quote:hover,
.robot-fx-crown-quote:focus-visible {
    border-color: #b6eaff;
    box-shadow: 0 0 24px #219cff38;
    background: #09223af5;
    outline: 2px solid #85d8ff;
    outline-offset: 3px;
}
.robot-fx-crown-quote--0 {
    left: 50%;
    bottom: -1%;
    transform: translateX(-50%);
    width: 156px;
    border-top: 2px solid #9ae2ff;
}
.robot-fx-crown-quote--1 {
    left: 1%;
    top: 18%;
    transform: rotate(-3deg);
}
.robot-fx-crown-quote--2 {
    right: 1%;
    top: 18%;
    transform: rotate(3deg);
}
.robot-effect-stage .robot-fx-crown-quote > span {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    font: 700 12px/1.2 var(--font-mono, monospace);
    color: #d8edff;
    letter-spacing: 0;
    text-shadow: none;
}
.robot-effect-stage .robot-fx-crown-quote > span > i {
    margin-left: auto;
    font-size: 10px;
    color: #6dcaff;
}
.robot-effect-stage .robot-fx-crown-quote small {
    display: inline;
    font: 500 10px/1.2 var(--mono, monospace);
    color: #a9bfd2;
    margin: 0;
    padding: 0;
    max-width: none;
    background: none;
    letter-spacing: 0;
    text-shadow: none;
}
.robot-effect-stage .robot-fx-crown-quote > strong {
    display: block;
    font-family: var(--mono, ui-monospace, monospace);
    font-size: 16px;
    font-weight: 700;
    font-style: normal;
    line-height: 1.2;
    color: #8ed7ff;
    letter-spacing: -0.025em;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    text-shadow: none;
    margin: 0;
    padding: 0;
}
.robot-effect-stage .robot-fx-crown-quote > em {
    display: flex;
    flex-wrap: wrap;
    column-gap: 4px;
    align-items: baseline;
    font: 500 12px/1.2 var(--font-mono, monospace);
    color: #8ed7ff;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
.robot-effect-stage .robot-fx-crown-quote > em.robot-fx-negative {
    color: #c5b5ff;
}

/* Gravity warps only a decorative field; quote typography never distorts. */
.robot-fx-gravity {
    position: absolute;
    inset: -5% -5%;
    pointer-events: none;
}
.robot-fx-gravity-orbit {
    position: absolute;
    left: 50%;
    top: 48%;
    width: calc(33% + var(--ring) * 9%);
    height: calc(24% + var(--ring) * 8%);
    border: 1px solid #328cff;
    border-radius: 48%;
    opacity: calc(0.58 - var(--ring) * 0.055);
    transform: translate(-50%, -50%) rotate(calc(var(--ring) * 17deg));
    box-shadow: 0 0 9px #1684ff38;
    animation: robot-fx-gravity-warp calc(12s + var(--ring) * 2s) ease-in-out
        infinite alternate;
}
.robot-fx-gravity-orbit:nth-child(2n) {
    border-color: #9de2ff;
}
.robot-fx-gravity-shock {
    position: absolute;
    inset: 3% 0;
    border: 2px solid #94ddff;
    border-radius: 50%;
    box-shadow:
        0 0 18px #229eff80,
        inset 0 0 12px #2177ff44;
}
.robot-fx-gravity-lens {
    position: absolute;
    inset: 12% 14% 17%;
    border-radius: 50%;
    background: conic-gradient(
        from 45deg,
        transparent 4%,
        #29a9ff29 10%,
        transparent 24% 49%,
        #b6eaff31 58%,
        transparent 77%
    );
    filter: blur(6px);
    animation: robot-fx-turn 23s linear infinite;
}
.robot-effect-stage--gravity .robot-fx-portrait {
    animation: robot-fx-float 7s ease-in-out infinite alternate;
}

/* Blue spectral copies spread behind the untouched robot; a feathered field bounds them. */
.robot-fx-echoes {
    position: absolute;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
    mask-image: linear-gradient(90deg, transparent, #000 7% 93%, transparent);
}
.robot-fx-ghost {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
    opacity: calc(0.44 - var(--echo) * 0.035);
    filter: brightness(0.82) sepia(1) saturate(5) hue-rotate(162deg)
        drop-shadow(0 0 8px #267eff);
    transform: translate(
            calc(var(--echo-x) * var(--echo) * -0.38 + var(--echo) * -11px),
            calc(var(--echo-y) * var(--echo) * -0.25)
        )
        scale(calc(1 + var(--echo) * 0.014));
    mask-image: linear-gradient(#000 0 69%, transparent 98%);
    transition: transform 0.7s cubic-bezier(0.12, 0.5, 0.25, 1);
}
.robot-fx-ghost:nth-child(2n) {
    transform: translate(
            calc(var(--echo-x) * var(--echo) * 0.36 + var(--echo) * 11px),
            calc(var(--echo-y) * var(--echo) * 0.25)
        )
        scale(calc(1 + var(--echo) * 0.014));
    filter: brightness(0.8) sepia(1) saturate(6) hue-rotate(185deg)
        drop-shadow(0 0 8px #367fff);
}
.robot-fx-echo-orbit {
    position: absolute;
    inset: 9% -7% 10%;
    border: 1px solid #318cff5c;
    border-radius: 48%;
    transform: skewY(-7deg);
    box-shadow: 0 0 15px #1687ff18;
}
.robot-fx-echo-orbit--second {
    inset: 3% -2% 16%;
    transform: skewY(10deg);
    border-color: #9fbfff44;
}
.robot-effect-stage--echoes .robot-fx-portrait {
    transform: translate(calc(var(--lean-x) * 0.2), calc(var(--lean-y) * 0.2));
    transition: transform 0.25s ease-out;
}
.robot-fx-echo-traces {
    position: absolute;
    inset: 10% -4%;
    pointer-events: none;
}
.robot-fx-echo-traces > i {
    position: absolute;
    width: 34%;
    height: 1px;
    top: 20%;
    left: 0;
    background: linear-gradient(90deg, transparent, #62bbff80, transparent);
    transform: rotate(-9deg);
}
.robot-fx-echo-traces > i:nth-child(2) {
    top: 60%;
    left: 72%;
    width: 28%;
}
.robot-fx-echo-traces > i:nth-child(3) {
    top: 80%;
    left: 2%;
    width: 28%;
}
.robot-fx-echo-traces > i:nth-child(4) {
    top: 6%;
    left: 68%;
    width: 26%;
}

.robot-effect-stage--still *,
.robot-effect-stage--still *::before,
.robot-effect-stage--still *::after {
    animation: none !important;
    transition: none !important;
}
.robot-effect-stage--still .robot-fx-ghost {
    transform: translateX(calc(var(--echo) * -9px));
}
.robot-effect-stage--still .robot-fx-ghost:nth-child(2n) {
    transform: translateX(calc(var(--echo) * 9px));
}
.robot-effect-stage--still .robot-fx-portrait {
    transform: none;
}
@keyframes robot-fx-turn {
    to {
        transform: rotate(360deg);
    }
}
@keyframes robot-fx-eye-breathe {
    0%,
    100% {
        opacity: 0.4;
    }
    50% {
        opacity: 0.9;
    }
}
@keyframes robot-fx-beam-flow {
    to {
        stroke-dashoffset: -32;
    }
}
@keyframes robot-fx-chrome-sweep {
    from {
        transform: translateX(-18%);
    }
    to {
        transform: translateX(24%);
    }
}
@keyframes robot-fx-gravity-warp {
    from {
        transform: translate(-50%, -50%) rotate(calc(var(--ring) * 17deg))
            scaleY(0.88);
    }
    to {
        transform: translate(-50%, -50%)
            rotate(calc(var(--ring) * 17deg + 50deg)) scaleY(1.03);
    }
}
@keyframes robot-fx-float {
    from {
        transform: translateY(1px);
    }
    to {
        transform: translateY(-4px);
    }
}
@media (max-width: 600px) {
    .robot-effect-stage {
        width: min(380px, 100%);
    }
    .robot-fx-crown-quote {
        width: 120px;
        padding: 7px 8px;
    }
    .robot-fx-crown-quote--0 {
        width: 145px;
        bottom: 0;
    }
    .robot-fx-crown-quote--1 {
        left: 0;
        top: 28%;
        transform: none;
    }
    .robot-fx-crown-quote--2 {
        right: 0;
        top: 28%;
        transform: none;
    }
    .robot-effect-stage .robot-fx-crown-quote > strong {
        font-size: 14px;
    }
    .robot-effect-stage .robot-fx-crown-quote > em {
        font-size: 11px;
    }
    .robot-effect-stage .robot-fx-crown-quote > span {
        font-size: 11px;
    }
    .robot-fx-crown-ray {
        opacity: 0.35;
    }
}
@media (max-width: 370px) {
    .robot-fx-crown-quote {
        width: 111px;
        padding: 7px;
    }
    .robot-fx-crown-quote--0 {
        width: 141px;
        bottom: 0;
    }
    .robot-fx-crown-quote--1 {
        left: 0;
        top: 31%;
    }
    .robot-fx-crown-quote--2 {
        right: 0;
        top: 31%;
    }
    .robot-effect-stage .robot-fx-crown-quote small {
        font-size: 10px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .robot-effect-stage *,
    .robot-effect-stage *::before,
    .robot-effect-stage *::after {
        animation: none !important;
        transition: none !important;
    }
    .robot-effect-stage .robot-fx-nanite-head--awake,
    .robot-effect-stage
        .robot-fx-nanite-head--awake
        .robot-fx-nanite-head-shade {
        animation: none !important;
        transform: none !important;
    }
}
</style>
