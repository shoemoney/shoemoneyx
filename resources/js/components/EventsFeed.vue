<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { api, fmt } from "../api";
const props = defineProps({
    limit: { type: Number, default: 60 },
    agent: { type: String, default: "" },
});
const events = ref([]);
let timer;
async function load() {
    try {
        events.value = await api.get(
            `/events?limit=${props.limit}${props.agent ? "&agent=" + props.agent : ""}`,
        );
    } catch {}
}
onMounted(() => {
    load();
    timer = setInterval(load, 5000);
});
onUnmounted(() => clearInterval(timer));
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
