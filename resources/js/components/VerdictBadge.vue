<script setup>
defineProps({ verdict: String });
const cls = { PASS: 'bg-emerald-500/20 text-emerald-300', PASS_PARTIAL: 'bg-amber-500/20 text-amber-300', REJECT: 'bg-red-500/20 text-red-300' };
</script>
<template><span class="badge" :class="cls[verdict] || 'bg-zinc-700 text-zinc-300'">{{ verdict || 'pending' }}</span></template>
