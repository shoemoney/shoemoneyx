<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { deskFetch, fmt } from "../api";
const props = defineProps({
    limit: { type: Number, default: 60 },
    agent: { type: String, default: "" },
});
const events = ref([]);
// Set while polls are failing, so a frozen feed is labelled instead of looking quiet.
const staleSince = ref(null);
let timer;
let controller = null;
// A stalled request must not block later polls or hide the stale badge, so each one has a deadline.
const REQUEST_TIMEOUT_MS = 8000;
async function load() {
    if (controller || document.hidden) return;
    const request = new AbortController();
    controller = request;
    const deadline = setTimeout(() => request.abort(), REQUEST_TIMEOUT_MS);
    try {
        events.value = await deskFetch(
            `/api/events?limit=${props.limit}${props.agent ? "&agent=" + props.agent : ""}`,
            { signal: request.signal },
        );
        staleSince.value = null;
    } catch {
        if (staleSince.value === null && !request.unmounted) staleSince.value = Date.now();
    } finally {
        clearTimeout(deadline);
        if (controller === request) controller = null;
    }
}
function onVisible() {
    if (!document.hidden) load();
}
onMounted(() => {
    load();
    timer = setInterval(load, 5000);
    document.addEventListener("visibilitychange", onVisible);
});
onUnmounted(() => {
    if (controller) {
        controller.unmounted = true;
        controller.abort();
    }
    clearInterval(timer);
    document.removeEventListener("visibilitychange", onVisible);
});
const agentColor = {
    CHIEF: "text-amber-300",
    SCAN: "text-sky-300",
    VET: "text-fuchsia-300",
    SIZE: "text-indigo-300",
    FILLS: "text-emerald-300",
    RISK: "text-red-300",
};
const levelDot = {
    info: "bg-zinc-500",
    warn: "bg-amber-400",
    error: "bg-red-500",
    trade: "bg-emerald-400",
};
</script>
<template>
    <div class="smx-events space-y-1 font-mono text-sm leading-relaxed">
        <div v-if="staleSince" class="text-amber-300" role="status">
            STALE · feed not updating since {{ new Date(staleSince).toLocaleTimeString() }}
        </div>
        <div v-for="e in events" :key="e.id" class="smx-event-row">
            <span
                class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                :class="levelDot[e.level]"
            ></span>
            <span class="shrink-0 text-zinc-600">{{
                fmt.ago(e.created_at)
            }}</span>
            <span class="font-semibold" :class="agentColor[e.agent]">{{
                e.agent
            }}</span>
            <span
                class="smx-event-message text-zinc-300"
                :title="e.payload ? JSON.stringify(e.payload, null, 1) : ''"
                >{{ e.message }}</span
            >
        </div>
        <div v-if="!events.length" class="text-zinc-600">
            no events yet — run a cycle
        </div>
    </div>
</template>
