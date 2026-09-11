<script setup>
// SMX panel: WT waves, VWAP wave, money-flow area, stoch K/D, and the dots — drawn as SVG
// under the TradingView chart and kept on the chart's visible time range when the widget exposes it.
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { api } from "../api";

const props = defineProps({
    symbol: String,
    tf: { type: String, default: "1H" },
    range: { type: Object, default: null },
    height: { type: Number, default: 180 },
});
const data = ref(null);
const error = ref("");
let timer;
let alive = true;
let requestId = 0;
let loading = false;

async function load(changed = false) {
    if (!alive) return;
    if (changed) {
        // Invalidate immediately, even when a hidden tab defers the next fetch.
        requestId++;
        loading = false;
        data.value = null;
        error.value = '';
    }
    if (!props.symbol || loading || document.hidden) return;
    const id = ++requestId;
    const requestedSymbol = props.symbol;
    const requestedTf = props.tf;
    loading = true;
    try {
        const snapshot = await api.get(
            `/smx?product=${encodeURIComponent(requestedSymbol)}&tf=${encodeURIComponent(requestedTf)}&bars=600`,
        );
        if (!alive || id !== requestId || requestedSymbol !== props.symbol || requestedTf !== props.tf) return;
        data.value = snapshot;
        error.value = "";
    } catch (e) {
        if (alive && id === requestId && requestedSymbol === props.symbol && requestedTf === props.tf) error.value = e.message;
    } finally {
        if (id === requestId) loading = false;
    }
}
watch(() => [props.symbol, props.tf], () => load(true));
function syncVisibility() {
    if (!document.hidden) load();
}
onMounted(() => {
    load();
    timer = setInterval(load, 30000);
    document.addEventListener('visibilitychange', syncVisibility);
});
onUnmounted(() => {
    alive = false;
    requestId++;
    clearInterval(timer);
    document.removeEventListener('visibilitychange', syncVisibility);
});

const W = 1000;
const view = computed(() => {
    const d = data.value;
    if (!d || !d.t.length) return null;
    let idx = d.t.map((_, i) => i);
    if (props.range?.from && props.range?.to) {
        const r = idx.filter(
            (i) => d.t[i] >= props.range.from && d.t[i] <= props.range.to,
        );
        if (r.length > 10) idx = r;
    } else idx = idx.slice(-200);
    const t0 = d.t[idx[0]],
        t1 = d.t[idx[idx.length - 1]] || t0 + 1;
    const H = props.height,
        pad = 6;
    const sx = (i) =>
        pad + ((d.t[i] - t0) / Math.max(1, t1 - t0)) * (W - pad * 2);
    const sy = (v) => H / 2 - (v / 110) * (H / 2 - pad); // WT scale −110..110
    const path = (series, scale = sy) =>
        idx
            .filter((i) => series[i] !== null)
            .map(
                (i, k) =>
                    (k ? "L" : "M") +
                    sx(i).toFixed(1) +
                    " " +
                    scale(series[i]).toFixed(1),
            )
            .join(" ");
    const area = (series, scale = sy) => {
        const p = path(series, scale);
        if (!p) return "";
        return (
            p +
            ` L${sx(idx[idx.length - 1]).toFixed(1)} ${scale(0).toFixed(1)} L${sx(idx[0]).toFixed(1)} ${scale(0).toFixed(1)} Z`
        );
    };
    const syStoch = (v) => H - pad - (v / 100) * (H - pad * 2);
    const dots = [];
    const S = d.signals;
    for (const i of idx) {
        if (S.gold_buy[i])
            dots.push({
                x: sx(i),
                y: sy(-106),
                r: 6,
                c: "#e2a400",
                t: "GOLD BUY",
            });
        else if (S.buy[i])
            dots.push({ x: sx(i), y: sy(-107), r: 5, c: "#3fff00", t: "BUY" });
        else if (S.buy_div[i])
            dots.push({
                x: sx(i),
                y: sy(-106),
                r: 4,
                c: "#1b5e20",
                t: "BUY div",
            });
        else if (S.small_green_dot[i])
            dots.push({
                x: sx(i),
                y: sy(d.wt2[i] ?? 0),
                r: 2.5,
                c: "#3fff00",
                t: "small green",
            });
        if (S.sell[i])
            dots.push({ x: sx(i), y: sy(105), r: 5, c: "#ff0000", t: "SELL" });
        else if (S.sell_div[i])
            dots.push({
                x: sx(i),
                y: sy(106),
                r: 4,
                c: "#9a0202",
                t: "SELL div",
            });
        else if (S.small_red_dot[i])
            dots.push({
                x: sx(i),
                y: sy(d.wt2[i] ?? 0),
                r: 2.5,
                c: "#ff3535",
                t: "small red",
            });
    }
    const L = d.levels;
    return {
        H,
        wt1: area(d.wt1),
        wt2: area(d.wt2),
        vwap: area(d.wt_vwap),
        mfi: area(d.rsi_mfi),
        k: path(d.stoch_k, syStoch),
        dd: path(d.stoch_d, syStoch),
        zero: sy(0),
        ob: sy(L.ob),
        ob2: sy(L.ob2),
        os: sy(L.os),
        os2: sy(L.os2),
        os3: sy(L.os3),
        dots,
    };
});
</script>

<template>
    <div class="smx-panel border-t border-zinc-800 bg-black">
        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-3 text-xs uppercase tracking-wide text-zinc-400"
        >
            <span class="font-semibold text-zinc-300"
                >SMX</span
            ><span>{{ symbol }} · {{ tf }}</span>
            <template v-if="data?.latest">
                <span
                    >wt1
                    <b class="num text-sky-300">{{ data.latest.wt1 }}</b></span
                >
                <span
                    >wt2
                    <b class="num text-blue-400">{{ data.latest.wt2 }}</b></span
                >
                <span
                    >mfi
                    <b
                        class="num"
                        :class="
                            (data.latest.rsi_mfi ?? 0) > 0
                                ? 'text-emerald-400'
                                : 'text-red-400'
                        "
                        >{{ data.latest.rsi_mfi }}</b
                    ></span
                >
                <span
                    >rsi <b class="num">{{ data.latest.rsi }}</b></span
                >
                <span
                    >stoch
                    <b class="num"
                        >{{ data.latest.stoch_k }}/{{ data.latest.stoch_d }}</b
                    ></span
                >
                <span
                    >stc <b class="num">{{ data.latest.stc }}</b></span
                >
                <span
                    v-if="data.latest.gold_buy"
                    class="badge bg-amber-500/30 text-amber-200"
                    >GOLD BUY</span
                >
                <span
                    v-else-if="data.latest.buy"
                    class="badge bg-emerald-500/30 text-emerald-200"
                    >BUY</span
                >
                <span
                    v-else-if="data.latest.buy_div"
                    class="badge bg-emerald-500/20 text-emerald-300"
                    >BUY DIV</span
                >
                <span
                    v-if="data.latest.sell"
                    class="badge bg-red-500/30 text-red-200"
                    >SELL</span
                >
                <span
                    v-else-if="data.latest.sell_div"
                    class="badge bg-red-500/20 text-red-300"
                    >SELL DIV</span
                >
            </template>
            <span v-if="error" class="text-red-400 normal-case">{{
                error
            }}</span>
        </div>
        <svg
            v-if="view"
            :viewBox="`0 0 ${W} ${view.H}`"
            preserveAspectRatio="none"
            class="block w-full"
            :style="{ height: height + 'px' }"
        >
            <line
                x1="0"
                :y1="view.zero"
                :x2="W"
                :y2="view.zero"
                stroke="#486377"
                stroke-width="1"
            />
            <line
                v-for="y in [view.ob2, view.os2]"
                :key="y"
                x1="0"
                :y1="y"
                :x2="W"
                :y2="y"
                stroke="#23465f"
                stroke-dasharray="4 4"
                stroke-width="1"
            />
            <line
                v-for="y in [view.ob, view.os, view.os3]"
                :key="'b' + y"
                x1="0"
                :y1="y"
                :x2="W"
                :y2="y"
                stroke="#182b3b"
                stroke-dasharray="2 6"
                stroke-width="1"
            />
            <path :d="view.mfi" fill="#3ee145" fill-opacity="0.25" />
            <path :d="view.wt1" fill="#90caf9" fill-opacity="0.85" />
            <path :d="view.wt2" fill="#0d47a1" fill-opacity="0.8" />
            <path :d="view.vwap" fill="#ffe500" fill-opacity="0.45" />
            <path
                :d="view.k"
                fill="none"
                stroke="#21baf3"
                stroke-width="1.2"
                vector-effect="non-scaling-stroke"
            />
            <path
                :d="view.dd"
                fill="none"
                stroke="#673ab7"
                stroke-width="1"
                vector-effect="non-scaling-stroke"
                opacity="0.7"
            />
            <circle
                v-for="(d, i) in view.dots"
                :key="i"
                :cx="d.x"
                :cy="d.y"
                :r="d.r"
                :fill="d.c"
            >
                <title>{{ d.t }}</title>
            </circle>
        </svg>
        <div v-else class="px-3 py-4 text-sm text-zinc-400">
            {{ error ? 'Indicators are unavailable.' : data ? 'No stored candles for these indicators yet.' : 'Loading indicators…' }}
        </div>
    </div>
</template>
