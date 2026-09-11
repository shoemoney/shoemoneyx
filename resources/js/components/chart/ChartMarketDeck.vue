<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { fmt } from '../../api';
import { pngUrlFor } from '../../coinIcons';
import { readQuote, observeQuote } from './marketLens';
import ReactiveValue from '../dashboard/ReactiveValue.vue';

const props = defineProps({ symbol: String, products: Array, active: Boolean });
const emit = defineEmits(['select', 'quote']);
const quote = ref(null), points = ref([]), error = ref(''), now = ref(Date.now()), pulse = ref(0);
const root = ref(null), visible = ref(true);
const motionActive = computed(() => props.active && visible.value);
let timer, controller, observer, generation = 0, disposed = false;
const stale = computed(() => !quote.value || now.value / 1000 - quote.value.ts > 60);
const status = computed(() => error.value ? 'Quote unavailable' : !quote.value ? 'Acquiring quote' : stale.value ? 'Stored snapshot' : quote.value.source === 'demo' ? 'Simulated feed' : quote.value.source === 'feed' ? 'Market feed' : 'Stored snapshot');
const delta = computed(() => points.value.length > 1 ? points.value.at(-1).price - points.value.at(-2).price : null);
const dock = computed(() => {
    const selected = props.products?.find(p => p.product_id === props.symbol);
    return [selected, ...(props.products || []).slice(0, 8)].filter((p, i, all) => p && all.findIndex(row => row?.product_id === p.product_id) === i).slice(0, 8);
});
const trail = computed(() => {
    if (points.value.length < 2) return null;
    const rows = points.value, low = Math.min(...rows.map(p => p.price)), high = Math.max(...rows.map(p => p.price));
    const span = Math.max(high - low, high * .00002);
    return rows.map((p, i) => `${i ? 'L' : 'M'}${(i / (rows.length - 1) * 440 + 4).toFixed(1)},${(76 - (p.price - low) / span * 62).toFixed(1)}`).join(' ');
});
async function refresh() {
    now.value = Date.now();
    if (disposed || document.hidden || controller) return;
    const id = generation, symbol = props.symbol;
    const request = new AbortController(); controller = request;
    const timeout = setTimeout(() => request.abort(), 7000);
    try {
        const response = await fetch(`/api/quote?product=${encodeURIComponent(symbol)}`, { signal: request.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw Error('Quote unavailable');
        const next = readQuote(await response.json(), symbol);
        if (disposed || id !== generation) return;
        if (!next) throw Error('Quote unavailable');
        if (quote.value && next.ts < quote.value.ts) return;
        if (quote.value?.price !== next.price) pulse.value++;
        quote.value = next; points.value = observeQuote(points.value, next); error.value = '';
        emit('quote', next);
    } catch {
        if (!disposed && id === generation) { error.value = 'Quote unavailable'; emit('quote', null); }
    } finally { clearTimeout(timeout); if (controller === request) controller = null; }
}
function reset() {
    generation++; controller?.abort(); controller = null;
    quote.value = null; points.value = []; error.value = ''; emit('quote', null); refresh();
}
function visibility() { if (document.hidden) { generation++; controller?.abort(); controller = null; } else refresh(); }
watch(() => props.symbol, reset);
onMounted(() => {
    refresh(); timer = setInterval(refresh, 2500); document.addEventListener('visibilitychange', visibility);
    if (root.value) { observer = new IntersectionObserver(([entry]) => { visible.value = entry.isIntersecting; }); observer.observe(root.value); }
});
onUnmounted(() => { disposed = true; generation++; controller?.abort(); observer?.disconnect(); clearInterval(timer); document.removeEventListener('visibilitychange', visibility); });
</script>

<template>
    <section ref="root" class="cl-market-deck" :class="{ 'cl-is-still': !motionActive, 'cl-market-down': delta < 0 }" aria-label="Market observatory">
        <div class="cl-market-core">
            <div class="cl-market-label"><span class="cl-status-dot" :class="{ 'is-live': quote?.source === 'feed' && !stale && !error }"></span>{{ status }}<span class="cl-market-age">{{ quote ? new Date(quote.ts * 1000).toLocaleTimeString() : '—' }}</span></div>
            <div class="cl-price-row">
                <div class="cl-coin-seal"><span></span><img v-if="pngUrlFor(symbol)" :src="pngUrlFor(symbol)" alt="" /><b v-else>{{ symbol?.split('-')[0] }}</b></div>
                <div><h2>{{ symbol }}</h2><strong class="cl-live-price" :key="symbol"><ReactiveValue :value="error ? null : quote?.price" :active="motionActive" :decimals="quote?.price < 1 ? 5 : 2" /></strong></div>
                <span v-if="delta !== null && !error" class="cl-tick-direction">{{ delta > 0 ? '↗' : delta < 0 ? '↘' : '—' }}</span>
            </div>
            <div class="cl-price-trail">
                <svg v-if="trail" viewBox="0 0 450 84" preserveAspectRatio="none" role="img" aria-label="Observed price samples since selecting this market"><path :d="trail" class="cl-trail-glow"/><path :d="trail"/><circle cx="444" :cy="trail.split(',').at(-1)" r="3"/></svg>
                <span v-else>Building an observed price trail…</span>
                <small>SESSION PRICE TRACE</small>
            </div>
            <span class="cl-quote-wave" :key="pulse" aria-hidden="true"></span>
        </div>
        <div class="cl-market-stats">
            <div><span>24h movement</span><strong :class="{ 'is-negative': quote?.change < 0 }">{{ fmt.pct(error ? null : quote?.change) }}</strong></div>
            <div><span>24h volume <small>{{ quote?.volumeUnit }}</small></span><strong>{{ error || !quote?.volumeUnit ? '—' : quote.volumeUnit === 'USD' ? fmt.usd(quote.volume, 0) : fmt.num(quote.volume, 2) }}</strong></div>
            <div class="cl-spread"><span>Bid / ask</span><strong>{{ fmt.px(error ? null : quote?.bid) }} <i>/</i> {{ fmt.px(error ? null : quote?.ask) }}</strong><small>{{ quote?.spreadBps !== null && quote?.spreadBps !== undefined && !error ? quote.spreadBps.toFixed(2) + ' bps spread' : 'Spread unavailable' }}</small></div>
        </div>
        <nav class="cl-market-dock" aria-label="Quick market selection">
            <button v-for="p in dock" :key="p.product_id" :aria-pressed="symbol === p.product_id" :aria-label="`Focus ${p.product_id}`" @click="emit('select', p.product_id)"><img v-if="pngUrlFor(p.product_id)" :src="pngUrlFor(p.product_id)" alt=""/><span>{{ p.product_id.split('-')[0] }}</span><i aria-hidden="true"></i></button>
        </nav>
    </section>
</template>
