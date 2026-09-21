<script setup>
defineProps({
    strategy: String,
    running: Boolean,
    count: Number,
    halted: Boolean,
    activity: [String, Number],
    focus: String,
});
</script>
<template>
    <div class="ai-core" :class="{ 'core-idle': !running || halted }">
        <span :key="activity" class="pxo-core-emission" aria-hidden="true"
            ><i></i><i></i
        ></span>
        <div class="core-orbits" aria-hidden="true">
            <span></span><span></span><span></span><b></b>
        </div>
        <div class="core-symbol">
            <i class="fa-solid fa-brain" aria-hidden="true"></i>
        </div>
        <div class="core-title">
            {{ (strategy || "AI").toUpperCase() }}<span>INTELLIGENCE</span>
        </div>
        <div class="core-state">
            <span class="signal-dot"></span
            >{{ halted ? "HALTED" : running ? "GUIDING THE DESK" : "STANDBY" }}
        </div>
        <div class="core-count">{{ count }} PAIRS CONNECTED</div>
        <div v-if="focus" class="pxo-core-focus">UPLINK / {{ focus }}</div>
    </div>
</template>
