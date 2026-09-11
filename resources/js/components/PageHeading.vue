<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import RobotChapterScene from "./experience/RobotChapterScene.vue";
import { chapterFor } from "./experience/chapters.js";

const props = defineProps({
    title: { type: String, default: "Workspace" },
    eyebrow: { type: String, default: "ShoeMoney AI" },
    description: { type: String, default: "" },
    chapter: { type: String, default: "" },
    variant: { type: String, default: "" },
    signals: { type: Array, default: () => [] },
});
const root = ref(null);
const paused = ref(false);
const reduced = ref(false);
const visible = ref(true);
const hidden = ref(false);
const focused = ref(false);
const profile = computed(() => chapterFor(props.variant || props.title));
const active = computed(() => !paused.value && !reduced.value && visible.value && !hidden.value);
const pointer = { x: 0, y: 0 };
let observer, media, pointerFrame = 0;
function syncVisibility() { hidden.value = document.hidden; }
function syncMotion() { reduced.value = media.matches; }
function move(event) {
    if (!active.value || event.pointerType === "touch") return;
    const rect = root.value.getBoundingClientRect();
    pointer.x = Math.max(-1, Math.min(1, ((event.clientX - rect.left) / rect.width - 0.5) * 2));
    pointer.y = Math.max(-1, Math.min(1, ((event.clientY - rect.top) / rect.height - 0.5) * 2));
    if (!pointerFrame) pointerFrame = requestAnimationFrame(() => {
        pointerFrame = 0;
        root.value?.style.setProperty("--chapter-x", pointer.x);
        root.value?.style.setProperty("--chapter-y", pointer.y);
        root.value?.style.setProperty("--chapter-light-x", `${50 + pointer.x * 50}%`);
        root.value?.style.setProperty("--chapter-light-y", `${50 + pointer.y * 50}%`);
    });
}
function leave() {
    cancelAnimationFrame(pointerFrame);
    pointerFrame = 0;
    pointer.x = pointer.y = 0;
    root.value?.style.setProperty("--chapter-x", 0);
    root.value?.style.setProperty("--chapter-y", 0);
    root.value?.style.setProperty("--chapter-light-x", "65%");
    root.value?.style.setProperty("--chapter-light-y", "35%");
}
watch(active, (value) => { if (!value) leave(); });
onMounted(() => {
    media = window.matchMedia("(prefers-reduced-motion: reduce)");
    syncMotion();
    syncVisibility();
    media.addEventListener("change", syncMotion);
    document.addEventListener("visibilitychange", syncVisibility);
    observer = new IntersectionObserver(([entry]) => { visible.value = entry.isIntersecting; }, { threshold: 0.02 });
    observer.observe(root.value);
});
onUnmounted(() => {
    cancelAnimationFrame(pointerFrame);
    observer?.disconnect();
    media?.removeEventListener("change", syncMotion);
    document.removeEventListener("visibilitychange", syncVisibility);
});
</script>

<template>
    <header
        ref="root"
        class="smx-page-heading smx-experience"
        :class="{ 'smx-experience--still': !active, 'smx-experience--focused': focused }"
        :data-chapter="profile.key"
        @pointermove="move"
        @pointerleave="leave"
        @focusin="focused = true"
        @focusout="focused = false"
    >
        <div class="smx-experience__mesh" aria-hidden="true"></div>
        <div class="smx-experience__light" aria-hidden="true"></div>
        <div class="smx-experience__rail" aria-hidden="true"><span></span></div>
        <div class="smx-experience__copy">
            <div class="smx-experience__eyebrow"><span aria-hidden="true"></span>{{ eyebrow }}</div>
            <h1>{{ title }}</h1>
            <p class="smx-experience__description">{{ description || profile.description }}</p>
            <div class="smx-experience__signature">
                <span class="smx-experience__chapter">{{ chapter || profile.chapter }}</span>
                <span class="smx-experience__rule" aria-hidden="true"></span>
                <span>{{ profile.signature }}</span>
            </div>
            <div v-if="signals.length" class="smx-experience__signals">
                <span v-for="(signal, index) in signals" :key="index">{{ signal }}</span>
            </div>
        </div>
        <RobotChapterScene :active="active" :visible="visible" :profile="profile" :pointer="pointer" :focused="focused" />
        <button
            class="smx-experience__motion"
            type="button"
            :aria-pressed="!paused && !reduced"
            :aria-label="reduced ? 'Hero uses reduced motion' : paused ? 'Resume hero effects' : 'Pause hero effects'"
            :disabled="reduced"
            @click="paused = !paused"
        >
            <svg v-if="!paused && !reduced" viewBox="0 0 16 16" aria-hidden="true"><path d="M5 3v10M11 3v10" /></svg>
            <svg v-else viewBox="0 0 16 16" aria-hidden="true"><path d="m5 3 7 5-7 5Z" /></svg>
            {{ reduced ? "Reduced motion" : paused ? "Effects paused" : "Effects on" }}
        </button>
        <div class="smx-experience__edge" aria-hidden="true"><span></span><span></span><span></span></div>
    </header>
</template>
