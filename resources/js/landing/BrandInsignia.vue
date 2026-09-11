<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from "vue";
import "../../css/brand-insignia.css";

const props = defineProps({
    theme: { type: String, default: "gallery" },
    placement: { type: String, default: "masthead" },
    motion: { type: Boolean, default: true },
    pulse: { type: Number, default: 0 },
});

// The source artwork stays byte-for-byte unchanged. Every black scene
// screen-composites the insignia into its surrounding blue light.
const logoUrl = "/brand/shoemoney-blue.png";
const worlds = {
    pulse: {
        color: "#ff894c",
        second: "#17b8ee",
        path: "M4 86H27L34 62L43 111L52 77H69M131 83H151L161 63L170 109L180 84H196",
    },
    neural: {
        color: "#72b8ff",
        second: "#9b8cff",
        path: "M21 39L54 58L38 91L21 122M54 58L80 25M38 91L14 80M178 41L148 63L169 91L181 131M148 63L126 21M169 91L190 78",
    },
    terminal: {
        color: "#78f6a7",
        second: "#3dadff",
        path: "M12 67V24H62M138 24H188V67M12 132V176H62M138 176H188V132M9 49H30M171 49H191M9 151H30M170 151H191",
    },
    orbit: {
        color: "#c09bff",
        second: "#58d4ff",
        path: "M14 99C-1 61 52 24 108 40S195 132 178 161S93 172 43 134S22 47 83 27",
    },
    prism: {
        color: "#c1eb65",
        second: "#54baff",
        path: "M17 49L79 12L180 39L192 137L109 191L13 148ZM17 49L47 66M180 39L159 69M192 137L161 121M109 191L109 156M13 148L42 125",
    },
    supernova: {
        color: "#ff793a",
        second: "#ffddb0",
        path: "M101 9L112 31M164 22L156 47M192 76L170 82M184 148L162 135M132 190L125 166M53 181L63 158M10 118L32 114M25 47L44 63",
    },
    neon: {
        color: "#fa5be5",
        second: "#34e9f0",
        path: "M51 14H151L192 52V147L149 186H49L9 147V55ZM39 31H163M180 63V136M39 170H163M22 64V139",
    },
    reactor: {
        color: "#ffd446",
        second: "#ff8239",
        path: "M71 14H128L142 26H164L179 41V64L191 80V124L178 138V162L162 177H138L121 190H80L64 177H42L24 162V140L11 122V80L24 64V41L42 25H63Z",
    },
    liquid: {
        color: "#62eed6",
        second: "#45a8ff",
        path: "M7 82C28 13 74 15 111 32S194 17 195 79S158 162 111 178S26 172 12 127ZM22 81C48 40 89 44 112 48S175 44 180 87",
    },
    citadel: {
        color: "#b6f45e",
        second: "#72bdff",
        path: "M18 161V46L51 28V162M51 55L78 43M150 162V27L183 46V162M123 43L150 55M9 171H191M20 183H180M28 46H42M158 47H174M28 60H42M158 61H174",
    },
    redline: {
        color: "#ff5854",
        second: "#fff0d8",
        path: "M13 60L34 40H66M10 81H39M10 103H32M11 126H40M13 149L34 165H66M134 40H167L188 61M165 81H193M173 103H193M165 126H193M134 165H169L188 145",
    },
    synapse: {
        color: "#bd9cff",
        second: "#d9ff84",
        path: "M14 71L36 73L31 43L59 50L66 24M36 73L20 112L42 143L34 172M42 143L67 178M188 73L165 71L168 43L141 49L134 24M165 71L183 112L159 146L168 173M159 146L134 177",
    },
    spectrum: {
        color: "#77b6ff",
        second: "#ff76c9",
        path: "M15 143L47 21L147 15L188 89L139 183L53 179ZM47 21L70 50M147 15L129 50M188 89L164 100M139 183L123 152M53 179L72 151M15 143L44 122",
    },
    horizon: {
        color: "#f6d7ac",
        second: "#fd9450",
        path: "M6 114C10 47 181 16 195 82M12 139C20 69 168 43 189 95M22 153C51 95 153 73 179 117",
    },
    overdrive: {
        color: "#66afff",
        second: "#72f4c3",
        path: "M9 46H30V24H70M129 24H170V46H191M9 152H30V175H70M129 175H170V152H191M8 73H23V95H35M192 74H177V95H165M8 127H24V110M191 127H177V110",
    },
    "pulse-blue": {
        color: "#1abef5",
        second: "#79d7ff",
        path: "M36 22H164L189 51L179 126L100 187L21 126L11 51ZM43 32H157L175 55M174 130L101 176L28 130",
    },
    gallery: {
        color: "#1bbcf4",
        second: "#889dff",
        path: "M23 135L99 179L177 135M14 148L99 197L186 148M33 30L51 20M149 20L168 31M7 76V101M193 76V102",
    },
};
const editions = {
    "horizon-blue": { family: "horizon", color: "#55b5ff", second: "#1782ff" },
    "supernova-blue": {
        family: "supernova",
        color: "#55b5ff",
        second: "#1782ff",
    },
};
const hasTheme = (collection, key) =>
    Object.prototype.hasOwnProperty.call(collection, key);
const name = computed(() =>
    hasTheme(worlds, props.theme) || hasTheme(editions, props.theme)
        ? props.theme
        : "gallery",
);
const edition = computed(() => editions[name.value]);
const family = computed(() => edition.value?.family || name.value);
const world = computed(() =>
    edition.value
        ? { ...worlds[family.value], ...edition.value }
        : worlds[family.value],
);
const root = ref(null),
    echo = ref(null);
const visible = ref(true),
    hidden = ref(false),
    reduced = ref(false);
const animate = computed(
    () => props.motion && visible.value && !hidden.value && !reduced.value,
);
let observer,
    media,
    frame = 0,
    burst,
    lastBurst = -Infinity;
let pointerX = 0,
    pointerY = 0;
function resetPointer() {
    cancelAnimationFrame(frame);
    frame = 0;
    root.value?.style.setProperty("--brand-tilt-x", "0deg");
    root.value?.style.setProperty("--brand-tilt-y", "0deg");
    root.value?.style.setProperty("--brand-light-x", "50%");
    root.value?.style.setProperty("--brand-light-y", "50%");
}
function point(event) {
    if (
        !animate.value ||
        props.placement === "masthead" ||
        event.pointerType === "touch"
    )
        return;
    const bounds = root.value.getBoundingClientRect();
    pointerX = (event.clientX - bounds.left) / Math.max(1, bounds.width) - 0.5;
    pointerY = (event.clientY - bounds.top) / Math.max(1, bounds.height) - 0.5;
    if (!frame)
        frame = requestAnimationFrame(() => {
            frame = 0;
            root.value?.style.setProperty(
                "--brand-tilt-x",
                `${pointerY * -10}deg`,
            );
            root.value?.style.setProperty(
                "--brand-tilt-y",
                `${pointerX * 12}deg`,
            );
            root.value?.style.setProperty(
                "--brand-light-x",
                `${50 + pointerX * 36}%`,
            );
            root.value?.style.setProperty(
                "--brand-light-y",
                `${50 + pointerY * 36}%`,
            );
        });
}
function pulse() {
    if (!animate.value || !echo.value) return;
    const now = performance.now();
    if (now - lastBurst < 1600) return;
    lastBurst = now;
    burst?.cancel();
    burst = echo.value.animate(
        [
            { opacity: 0, transform: "scale(0.88)" },
            { opacity: 0.48, transform: "scale(0.98)", offset: 0.22 },
            { opacity: 0, transform: "scale(1.3)" },
        ],
        { duration: 1400, easing: "cubic-bezier(.18,.6,.3,1)" },
    );
}
watch(
    () => props.pulse,
    (value, before) => {
        if (
            Number.isFinite(value) &&
            Number.isFinite(before) &&
            value !== before
        )
            pulse();
    },
);
watch(animate, (value) => {
    if (!value) {
        resetPointer();
        burst?.cancel();
    }
});
const onVisibility = () => {
    hidden.value = document.hidden;
};
const onMotion = () => {
    reduced.value = media.matches;
};
onMounted(() => {
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    onMotion();
    onVisibility();
    media.addEventListener("change", onMotion);
    document.addEventListener("visibilitychange", onVisibility);
    observer = new IntersectionObserver(([entry]) => {
        visible.value = entry.isIntersecting;
    });
    observer.observe(root.value);
});
onUnmounted(() => {
    cancelAnimationFrame(frame);
    burst?.cancel();
    observer?.disconnect();
    media?.removeEventListener("change", onMotion);
    document.removeEventListener("visibilitychange", onVisibility);
});
</script>

<template>
    <span
        ref="root"
        class="brand-insignia"
        :class="[
            `brand-insignia--${name}`,
            `brand-insignia--theme-${name}`,
            `brand-insignia--family-${family}`,
            family !== name ? `brand-insignia--${family}` : null,
            { 'brand-insignia--colorway-blue': !!edition },
            `brand-insignia--${placement}`,
            `brand-insignia--placement-${placement}`,
            { 'brand-insignia--still': !animate },
        ]"
        :style="{
            '--brand-accent': world.color,
            '--brand-second': world.second,
        }"
        aria-hidden="true"
        @pointermove="point"
        @pointerleave="resetPointer"
    >
        <span class="brand-insignia__bluefield"></span>
        <span class="brand-insignia__aura"></span>
        <span class="brand-insignia__plinth"></span>
        <span class="brand-insignia__orbit brand-insignia__orbit--one"
            ><i></i
        ></span>
        <span class="brand-insignia__orbit brand-insignia__orbit--two"
            ><i></i
        ></span>
        <span class="brand-insignia__fins">
            <i
                v-for="n in 12"
                :key="n"
                :style="{
                    '--brand-n': n,
                    '--brand-row': (n - 1) % 6,
                    '--brand-side': n % 2,
                    '--brand-alpha': 0.15 + (n % 3) * 0.1,
                }"
            ></i>
        </span>
        <svg
            class="brand-insignia__traces"
            viewBox="0 0 200 200"
            fill="none"
            focusable="false"
        >
            <path class="brand-insignia__trace-base" :d="world.path" />
            <path
                class="brand-insignia__trace-flow"
                :d="world.path"
                pathLength="100"
            />
            <path
                class="brand-insignia__trace-brand"
                :d="world.path"
                pathLength="100"
            />
            <g class="brand-insignia__nodes">
                <circle cx="26" cy="43" r="2" />
                <circle cx="175" cy="45" r="2" />
                <circle cx="15" cy="128" r="2" />
                <circle cx="184" cy="132" r="2" />
            </g>
        </svg>
        <span class="brand-insignia__emblem">
            <img
                class="brand-insignia__image"
                :src="logoUrl"
                alt=""
                width="1024"
                height="1024"
                draggable="false"
            />
        </span>
        <span class="brand-insignia__scan"></span>
        <span ref="echo" class="brand-insignia__echo"></span>
        <span class="brand-insignia__spark brand-insignia__spark--one"></span>
        <span class="brand-insignia__spark brand-insignia__spark--two"></span>
    </span>
</template>
