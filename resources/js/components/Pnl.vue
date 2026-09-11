<script setup>
import { fmt } from '../api';
const props = defineProps({ usd: Number, pct: Number });
</script>
<template>
    <span class="num" :class="(usd ?? pct ?? 0) >= 0 ? 'text-emerald-400' : 'text-red-400'">
        <template v-if="usd !== undefined && usd !== null">{{ fmt.usd(usd) }}</template>
        <template v-if="pct !== undefined && pct !== null"> <span class="opacity-80">({{ fmt.pct(pct) }})</span></template>
    </span>
</template>
