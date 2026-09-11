<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { api, fmt } from "../api";

const props = defineProps({
    coin: { type: String, required: true },
    promotionsToday: { type: Number, default: null },
});

const positions = ref([]);
const fills = ref([]);
const lastExit = ref(null);
const price = ref(null);
const lastDir = ref(null); // 'up' | 'down' | null — the most recent REAL direction change; never reverts to flat
const priceTick = ref(0); // bumped only on an actual price change, so :key restarts the wash without repeat noise
const arrowFresh = ref(true); // the ▲/▼ colours the direction for ~1s after a real change, then fades to zinc-500
const newFillRow = ref({});
const sourceErrors = ref({ positions: "", fills: "", quote: "" });
const error = computed(() => Object.entries(sourceErrors.value).filter(([, value]) => value).map(([source, value]) => `${source}: ${value}`).join(" · "));
const positionsLoaded = ref(false);
const fillsLoaded = ref(false);
let disposed = false;
let priceRequest = 0;
const fillTimers = new Set();
let prevPriceVal = null;
let knownFillIds = null; // null until the first load, so we never flash the initial page of fills
let arrowFadeTimer;
let posTimer, fillTimer, priceTimer;

const prev = new Map();
const flashes = ref({}); // key -> { dir, tick } — tick changes every update so :key can force the wash to restart
const flashTimers = {};

function noteFlash(key, value) {
    if (value === null || value === undefined || Number.isNaN(Number(value)))
        return;
    const old = prev.get(key);
    if (old !== undefined && Number(old) !== Number(value)) {
        const dir = Number(value) > Number(old) ? "up" : "down";
        const tick = (flashes.value[key]?.tick ?? 0) + 1;
        flashes.value = { ...flashes.value, [key]: { dir, tick } };
        clearTimeout(flashTimers[key]);
        flashTimers[key] = setTimeout(() => {
            const f = { ...flashes.value };
            delete f[key];
            flashes.value = f;
        }, 600);
    }
    prev.set(key, Number(value));
}

const px6 = (n) =>
    n === null || n === undefined || Number.isNaN(Number(n))
        ? "—"
        : Number(n).toFixed(6);
const qty8 = (n) =>
    n === null || n === undefined || Number.isNaN(Number(n))
        ? "—"
        : Number(n).toFixed(8);
const usd4 = (n) =>
    n === null || n === undefined || Number.isNaN(Number(n))
        ? "—"
        : (Number(n) < 0 ? "-" : "") + "$" + Math.abs(Number(n)).toFixed(4);
function sig8(n) {
    if (n === null || n === undefined || Number.isNaN(Number(n))) return "—";
    const v = Number(n);
    const s = v.toPrecision(8);

    return s.includes("e") ? v.toFixed(8) : s;
}

/**
 * Splits a formatted number string into a bright head and a dim tail: the whole integer part
 * (or, for a sub-1 value, up to 4 significant digits starting at the first nonzero) plus two
 * decimals stays head; everything after that is trailing precision.
 */
function numParts(str) {
    if (str === "—" || str === null || str === undefined)
        return { head: "—", tail: "" };
    const neg = str.startsWith("-");
    const s = neg ? str.slice(1) : str;
    const dot = s.indexOf(".");
    if (dot === -1) return { head: (neg ? "-" : "") + s, tail: "" };
    const intPart = s.slice(0, dot);
    const frac = s.slice(dot + 1);
    if (intPart !== "0") {
        return {
            head: (neg ? "-" : "") + intPart + "." + frac.slice(0, 2),
            tail: frac.slice(2),
        };
    }
    const firstNonzero = frac.search(/[1-9]/);
    if (firstNonzero === -1) return { head: (neg ? "-" : "") + s, tail: "" };
    const cut = Math.min(frac.length, firstNonzero + 4);

    return {
        head: (neg ? "-" : "") + "0." + frac.slice(0, cut),
        tail: frac.slice(cut),
    };
}

async function loadPositions() {
    if (document.hidden || disposed) return;
    try {
        const rows = await api.get("/positions?status=open&limit=200");
        if (disposed) return;
        positionsLoaded.value = true;
        rows.forEach((p) => {
            noteFlash(`pos:${p.id}:mark`, p.mark_price);
            noteFlash(`pos:${p.id}:pnl`, p.unrealised_pnl);
        });
        positions.value = rows;
        if (!rows.length) {
            try {
                const closed = await api.get(
                    "/positions?status=closed&limit=1",
                );
                lastExit.value = closed[0]?.closed_at ?? null;
            } catch {
                /* the empty-state line just shows no last exit */
            }
        }
        sourceErrors.value.positions = "";
    } catch (e) {
        sourceErrors.value.positions = e.message;
    }
}

async function loadFills() {
    if (document.hidden || disposed) return;
    try {
        const r = await api.get("/fills?per_page=100");
        if (disposed) return;
        const rows = r.data ?? r;
        fillsLoaded.value = true;
        sourceErrors.value.fills = "";
        if (knownFillIds) {
            const fresh = rows.filter((f) => !knownFillIds.has(f.id));
            if (fresh.length) {
                const bump = {};
                fresh.forEach((f) => {
                    bump[f.id] = true;
                });
                newFillRow.value = { ...newFillRow.value, ...bump };
                const fillTimer = setTimeout(() => {
                    fillTimers.delete(fillTimer);
                    const next = { ...newFillRow.value };
                    fresh.forEach((f) => delete next[f.id]);
                    newFillRow.value = next;
                }, 650);
                fillTimers.add(fillTimer);
            }
        }
        knownFillIds = new Set(rows.map((f) => f.id));
        fills.value = rows;
    } catch (e) {
        sourceErrors.value.fills = e.message;
    }
}

// /quote is 1s-fresh off the websocket feed's Redis hash (falls back to the hourly Product row
// only when the feed has nothing yet). lastDir sticks to the last real up/down: an unchanged
// price never resets the arrow to flat, never re-fires the wash, and only the arrow's colour —
// not its glyph — fades after 1s. 'flat' (→) shows only before the first comparison is possible.
async function loadPrice() {
    if (document.hidden || disposed) return;
    const request = ++priceRequest;
    try {
        const row = await api.get(
            `/quote?product=${encodeURIComponent(props.coin)}`,
        );
        if (disposed || request !== priceRequest) return;
        if (!row || row.price === null || row.price === undefined) { price.value = null; sourceErrors.value.quote = "No price received"; return; }
        sourceErrors.value.quote = "";
        const val = Number(row.price);
        price.value = row;
        if (prevPriceVal === null || val === prevPriceVal) {
            prevPriceVal = val;
            return;
        }
        lastDir.value = val > prevPriceVal ? "up" : "down";
        prevPriceVal = val;
        priceTick.value++;
        arrowFresh.value = true;
        clearTimeout(arrowFadeTimer);
        arrowFadeTimer = setTimeout(() => {
            arrowFresh.value = false;
        }, 1000);
    } catch (e) {
        if (!disposed && request === priceRequest) sourceErrors.value.quote = e.message;
    }
}

const heldFor = (opened) =>
    opened
        ? fmt.mins(
              Math.round((Date.now() - new Date(opened).getTime()) / 60000),
          )
        : "—";
const justTime = (iso) =>
    iso
        ? new Date(iso).toLocaleTimeString(undefined, {
              hour: "numeric",
              minute: "2-digit",
          })
        : null;
const flashCls = (key) =>
    flashes.value[key]?.dir === "up"
        ? "flash-up"
        : flashes.value[key]?.dir === "down"
          ? "flash-down"
          : "";
const flashTick = (key) => flashes.value[key]?.tick ?? 0;
const hasQty = (f) => Number(f.filled_qty) > 0;

const priceArrow = computed(() =>
    lastDir.value === "up" ? "▲" : lastDir.value === "down" ? "▼" : "",
);
const priceArrowCls = computed(() =>
    arrowFresh.value
        ? lastDir.value === "up"
            ? "text-emerald-400"
            : lastDir.value === "down"
              ? "text-red-400"
              : "text-zinc-600"
        : "text-zinc-500",
);
const pricePulseCls = computed(() =>
    lastDir.value === "up"
        ? "flash-up-lg"
        : lastDir.value === "down"
          ? "flash-down-lg"
          : "",
);

watch(
    () => props.coin,
    () => {
        price.value = null;
        sourceErrors.value.quote = "";
        prevPriceVal = null;
        lastDir.value = null;
        loadPrice();
    },
);
onMounted(() => {
    loadPositions();
    loadFills();
    loadPrice();
    posTimer = setInterval(loadPositions, 2000);
    fillTimer = setInterval(loadFills, 2000);
    priceTimer = setInterval(loadPrice, 1000);
});
onUnmounted(() => {
    disposed = true;
    Object.values(flashTimers).forEach(clearTimeout);
    fillTimers.forEach(clearTimeout);
    clearInterval(posTimer);
    clearInterval(fillTimer);
    clearInterval(priceTimer);
    clearTimeout(arrowFadeTimer);
});
</script>

<template>
    <div class="smx-arena-book space-y-3">
        <div class="flex items-center justify-between text-sm text-zinc-500">
            <h2>Live desk book</h2>
            <span v-if="error" role="status" class="text-red-400">{{ error }}</span>
        </div>

        <div class="card flex items-center justify-between !py-2">
            <div class="text-sm uppercase tracking-widest text-zinc-500">
                {{ coin }}
            </div>
            <div
                :key="priceTick"
                class="min-w-[10ch] rounded-sm border border-zinc-800 bg-zinc-900 px-3 py-1 text-right"
                :class="pricePulseCls"
            >
                <span class="num text-3xl font-bold tabular-nums">
                    <span class="font-medium text-zinc-50">{{
                        numParts(sig8(price?.price)).head
                    }}</span
                    ><span class="text-zinc-600">{{
                        numParts(sig8(price?.price)).tail
                    }}</span>
                </span>
                <span
                    class="ml-2 inline-block w-6 text-center align-middle text-2xl transition-colors duration-700"
                    :class="priceArrowCls"
                    >{{ priceArrow }}</span
                >
            </div>
        </div>

        <div class="card !p-2">
            <div
                class="mb-1 px-1 text-sm uppercase tracking-widest text-zinc-500"
            >
                open positions · {{ positionsLoaded ? positions.length : '—' }}
            </div>
            <div class="max-h-64 overflow-auto">
                <table class="grid text-sm">
                    <thead>
                        <tr>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                product
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                side
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                qty
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                entry
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                last
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                unrealised $
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                unrealised %
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                margin
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                peak
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                held
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in positions" :key="p.id">
                            <td class="font-mono">{{ p.product_id }}</td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        p.side === 'short'
                                            ? 'bg-red-500/20 text-red-300'
                                            : 'bg-sky-500/20 text-sky-300'
                                    "
                                    >{{ p.side ?? "long" }}</span
                                >
                            </td>
                            <td class="num text-right">
                                <span class="font-medium text-zinc-50">{{
                                    numParts(qty8(p.quantity)).head
                                }}</span
                                ><span class="text-zinc-600">{{
                                    numParts(qty8(p.quantity)).tail
                                }}</span>
                            </td>
                            <td class="num text-right">
                                <span class="font-medium text-zinc-50">{{
                                    numParts(px6(p.entry_price)).head
                                }}</span
                                ><span class="text-zinc-600">{{
                                    numParts(px6(p.entry_price)).tail
                                }}</span>
                            </td>
                            <td
                                :key="flashTick(`pos:${p.id}:mark`)"
                                class="num text-right"
                                :class="flashCls(`pos:${p.id}:mark`)"
                            >
                                <span class="font-medium text-zinc-50">{{
                                    numParts(px6(p.mark_price)).head
                                }}</span
                                ><span class="text-zinc-600">{{
                                    numParts(px6(p.mark_price)).tail
                                }}</span>
                            </td>
                            <td
                                :key="flashTick(`pos:${p.id}:pnl`)"
                                class="num text-right"
                                :class="[
                                    p.unrealised_pnl >= 0
                                        ? 'text-emerald-400'
                                        : 'text-red-400',
                                    flashCls(`pos:${p.id}:pnl`),
                                ]"
                            >
                                {{ usd4(p.unrealised_pnl) }}
                            </td>
                            <td
                                class="num text-right"
                                :class="
                                    p.unrealised_pnl_pct >= 0
                                        ? 'text-emerald-400'
                                        : 'text-red-400'
                                "
                            >
                                {{ fmt.pct(p.unrealised_pnl_pct, 4) }}
                            </td>
                            <td class="num text-right">
                                {{ usd4(p.meta?.margin_usd) }}
                            </td>
                            <td class="num text-right">
                                <span class="font-medium text-zinc-50">{{
                                    numParts(px6(p.peak_price)).head
                                }}</span
                                ><span class="text-zinc-600">{{
                                    numParts(px6(p.peak_price)).tail
                                }}</span>
                            </td>
                            <td class="num text-right">
                                {{ heldFor(p.opened_at) }}
                            </td>
                        </tr>
                        <tr v-if="!positions.length">
                            <td
                                colspan="10"
                                class="arena-book-empty py-1.5 text-center font-mono text-sm text-zinc-600"
                            >
                                <span v-if="!positionsLoaded">{{ sourceErrors.positions ? 'Position data unavailable' : 'Acquiring positions' }}</span>
                                <template v-else>flat · last exit
                                {{ justTime(lastExit) ?? "—" }} ·
                                <span
                                    :class="
                                        promotionsToday > 0
                                            ? 'text-amber-300'
                                            : ''
                                    "
                                    >{{ promotionsToday ?? '—' }}</span
                                >
                                promotions today</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card !p-2">
            <div
                class="mb-1 px-1 text-sm uppercase tracking-widest text-zinc-500"
            >
                fills · {{ fillsLoaded ? fills.length : '—' }}
            </div>
            <div class="max-h-64 overflow-auto">
                <table class="grid text-sm">
                    <thead>
                        <tr>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                time
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                product
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                side
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                kind
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                qty
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                price
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm text-right"
                            >
                                fee
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                status
                            </th>
                            <th
                                class="sticky top-0 z-10 bg-zinc-950/80 backdrop-blur-sm"
                            >
                                note
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in fills"
                            :key="f.id"
                            :class="[
                                f.status !== 'filled'
                                    ? 'border-l-2 border-red-500/60 text-red-400/70'
                                    : '',
                                newFillRow[f.id] ? 'bg-zinc-800/70' : '',
                            ]"
                        >
                            <td class="font-mono text-zinc-400">
                                {{ fmt.time(f.created_at) }}
                            </td>
                            <td class="font-mono">{{ f.product_id }}</td>
                            <td>
                                <span
                                    :class="
                                        f.side === 'SELL'
                                            ? 'text-red-300'
                                            : 'text-emerald-300'
                                    "
                                    >{{ f.side }}</span
                                >
                            </td>
                            <td>{{ f.kind }}</td>
                            <td class="num text-right">
                                <template v-if="hasQty(f)"
                                    ><span class="font-medium text-zinc-50">{{
                                        numParts(qty8(f.filled_qty)).head
                                    }}</span
                                    ><span class="text-zinc-600">{{
                                        numParts(qty8(f.filled_qty)).tail
                                    }}</span></template
                                >
                                <template v-else>—</template>
                            </td>
                            <td class="num text-right">
                                <span class="font-medium text-zinc-50">{{
                                    numParts(
                                        sig8(f.fill_price ?? f.decision_price),
                                    ).head
                                }}</span
                                ><span class="text-zinc-600">{{
                                    numParts(
                                        sig8(f.fill_price ?? f.decision_price),
                                    ).tail
                                }}</span>
                            </td>
                            <td class="num text-right">
                                {{ usd4(f.fee_usd) }}
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        f.status === 'filled'
                                            ? 'bg-zinc-800 text-zinc-300'
                                            : 'bg-red-500/20 text-red-300'
                                    "
                                    >{{ f.status }}</span
                                >
                            </td>
                            <td class="max-w-[16rem] truncate" :title="f.note">
                                {{ f.status !== "filled" ? f.note : "" }}
                            </td>
                        </tr>
                        <tr v-if="!fills.length">
                            <td
                                colspan="9"
                                class="arena-book-empty py-1.5 text-center font-mono text-sm text-zinc-600"
                            >
                                {{ fillsLoaded ? 'No fills returned' : sourceErrors.fills ? 'Fill data unavailable' : 'Acquiring fill history' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes flash-up-lg {
    from {
        background-color: rgba(16, 185, 129, 0.15);
        color: #6ee7b7;
        box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.5);
    }
    to {
        background-color: transparent;
        color: #f4f4f5;
        box-shadow: inset 0 0 0 1px transparent;
    }
}
@keyframes flash-down-lg {
    from {
        background-color: rgba(239, 68, 68, 0.15);
        color: #fca5a5;
        box-shadow: inset 0 0 0 1px rgba(239, 68, 68, 0.5);
    }
    to {
        background-color: transparent;
        color: #f4f4f5;
        box-shadow: inset 0 0 0 1px transparent;
    }
}
.flash-up-lg {
    animation: flash-up-lg 0.5s ease-out;
}
.flash-down-lg {
    animation: flash-down-lg 0.5s ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .flash-up-lg,
    .flash-down-lg {
        animation: none;
        background-color: transparent;
        color: #f4f4f5;
        box-shadow: inset 0 0 0 1px transparent;
    }
}
</style>
