<script setup>
import { publicDemo } from "../demoMode";
import PageHeading from "../components/PageHeading.vue";
import { ref, computed, onMounted, onUnmounted } from "vue";
import { api, fmt } from "../api";
import Pnl from "../components/Pnl.vue";
import PositionSpectrum from "../components/dashboard/PositionSpectrum.vue";
import { finiteNumber, sumPositionPnl } from "../components/dashboard/dashboardData";
import { pngUrlFor } from "../coinIcons";
import "../../css/market-workspace.css";

const tab = ref("open");
const rows = ref([]);
const fills = ref([]);
const detail = ref(null);
const error = ref("");
const search = ref("");
const selected = ref(null);
const loaded = ref(false);
const loading = ref(false);
const updatedAt = ref(null);
const reducedMotion = ref(false);
let requestId = 0;
let detailRequestId = 0;
let timer;
let motionQuery;
const visibleRows = computed(() => rows.value.filter((p) =>
    (!selected.value || selected.value === p.id) &&
    p.product_id.toLowerCase().includes(search.value.trim().toLowerCase()),
));
const totalPnl = computed(() => sumPositionPnl(rows.value, loaded.value));
const entryNotional = computed(() => {
    const values = rows.value.map((p) => finiteNumber(p.entry_usd));
    return !loaded.value || values.some((n) => n === null) ? null : values.reduce((a, b) => a + b, 0);
});
const longCount = computed(() => rows.value.filter((p) => p.side !== 'short').length);
function selectPosition(id) { selected.value = selected.value === id ? null : id; }
function syncMotion() { reducedMotion.value = motionQuery.matches; }

async function load() {
    const id = ++requestId;
    const requestedTab = tab.value;
    loading.value = true;
    try {
        const response = requestedTab === 'fills'
            ? await api.get('/fills?per_page=100')
            : await api.get(`/positions?status=${requestedTab}&limit=200`);
        if (id !== requestId) return;
        if (requestedTab === 'fills') fills.value = response.data;
        else rows.value = response;
        loaded.value = true;
        updatedAt.value = new Date();
        error.value = '';
        if (selected.value && !rows.value.some((p) => p.id === selected.value)) selected.value = null;
    } catch (e) {
        if (id !== requestId) return;
        error.value = e.message;
    } finally {
        if (id === requestId) loading.value = false;
    }
}
async function open(p) {
    const id = ++detailRequestId;
    try {
        const response = await api.get(`/positions/${p.id}`);
        if (id === detailRequestId) detail.value = response;
    } catch (e) { if (id === detailRequestId) error.value = e.message; }
}
async function closePos(p) {
    if (!confirm(`Close ${p.product_id} at market?`)) return;
    try {
        await api.post(`/positions/${p.id}/close`);
        await load();
    } catch (e) {
        error.value = e.message;
    }
}
function setTab(t) {
    if (tab.value === t) return;
    tab.value = t;
    detail.value = null;
    detailRequestId++;
    rows.value = [];
    fills.value = [];
    selected.value = null;
    loaded.value = false;
    updatedAt.value = null;
    load();
}
onMounted(() => {
    motionQuery = matchMedia('(prefers-reduced-motion: reduce)');
    syncMotion();
    motionQuery.addEventListener('change', syncMotion);
    load();
    timer = setInterval(load, 15000);
});
onUnmounted(() => {
    clearInterval(timer);
    requestId++;
    detailRequestId++;
    motionQuery?.removeEventListener('change', syncMotion);
});
</script>

<template>
    <div class="smx-page smx-positions space-y-4">
        <PageHeading title="Positions" eyebrow="Exposure & fills" description="Every position. Every fill. One field of vision." />
        <div class="mw-control-rail">
            <div class="mw-segments" role="group" aria-label="Position history">
            <button
                v-for="t in ['open', 'closed', 'fills']"
                :key="t"
                class="btn"
                :class="tab === t ? 'btn-primary' : ''"
                :aria-pressed="tab === t"
                @click="setTab(t)"
            >
                {{ t }}
            </button>
            </div>
            <label class="mw-search" v-if="tab !== 'fills'">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input v-model="search" placeholder="Find a coin" aria-label="Find a coin" type="search" />
            </label>
            <span class="mw-sync">{{ loading ? 'Synchronizing…' : updatedAt ? `Updated ${updatedAt.toLocaleTimeString()}` : 'Awaiting position data' }}</span>
        </div>
        <div v-if="error" class="mw-error" role="alert">Position data could not be refreshed. {{ error }}<button class="btn" @click="load">Retry</button></div>

        <section v-if="tab === 'open'" class="mw-exposure-stage" aria-labelledby="exposure-title">
            <div class="mw-exposure-intro">
                <span class="mw-eyebrow">01 / THE EXPOSURE FIELD</span>
                <h2 id="exposure-title">Capital in<br/><em>every direction.</em></h2>
                <p>Follow the current mark. Select a coin or bar to bring its position into focus.</p>
                <div class="mw-exposure-total" :class="totalPnl !== null && totalPnl < 0 ? 'is-negative' : ''">
                    <span>Unrealised P&amp;L</span><strong>{{ fmt.usd(totalPnl) }}</strong>
                </div>
                <dl class="mw-mini-stats">
                    <div><dt>Entry notional</dt><dd>{{ fmt.usd(entryNotional) }}</dd></div>
                    <div><dt>Long / Short</dt><dd>{{ loaded ? `${longCount} / ${rows.length - longCount}` : '—' }}</dd></div>
                </dl>
            </div>
            <div class="mw-exposure-chart">
                <div class="mw-section-title"><span>POSITION P&amp;L / USD</span><button v-if="selected" class="btn" @click="selected = null">Clear focus</button></div>
                <PositionSpectrum :positions="rows" :selected="selected" :active="!reducedMotion" :loading="loading" :error="error" @select="selectPosition" />
                <div class="mw-coin-dock" aria-label="Focus a position">
                    <button v-for="p in rows" :key="p.id" :aria-pressed="selected === p.id" @click="selectPosition(p.id)">
                        <img v-if="pngUrlFor(p.product_id)" :src="pngUrlFor(p.product_id)" alt=""/><span>{{ p.product_id.split('-')[0] }}</span>
                    </button>
                </div>
            </div>
        </section>

        <div class="card" v-if="tab !== 'fills'">
            <div class="mw-section-title"><div><span class="mw-eyebrow">02 / POSITION LEDGER</span><h2>{{ tab === 'open' ? 'On the field.' : 'The completed record.' }}</h2></div><span>{{ loaded ? visibleRows.length : '—' }} positions</span></div>
            <div class="smx-table-scroll">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>product</th>
                            <th>mode</th>
                            <th>opened</th>
                            <th>closed</th>
                            <th>qty</th>
                            <th>entry</th>
                            <th>{{ tab === "open" ? "mark" : "exit" }}</th>
                            <th>cost</th>
                            <th>pnl</th>
                            <th>held</th>
                            <th>rule</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in visibleRows"
                            :key="p.id"
                            class="cursor-pointer"
                            @click="open(p)"
                        >
                            <td class="font-semibold"><button class="mw-product-button" @click.stop="open(p)"><img v-if="pngUrlFor(p.product_id)" :src="pngUrlFor(p.product_id)" alt=""/>{{ p.product_id }}</button></td>
                            <td>
                                <span class="badge bg-zinc-800 text-zinc-300">{{
                                    p.mode
                                }}</span>
                            </td>
                            <td class="num text-zinc-400">
                                {{ fmt.time(p.opened_at) }}
                            </td>
                            <td class="num text-zinc-400">
                                {{ fmt.time(p.closed_at) }}
                            </td>
                            <td class="num">{{ fmt.num(p.quantity, 6) }}</td>
                            <td class="num">{{ fmt.px(p.entry_price) }}</td>
                            <td class="num">
                                {{
                                    fmt.px(
                                        tab === "open"
                                            ? p.mark_price
                                            : p.exit_price,
                                    )
                                }}
                            </td>
                            <td class="num">{{ fmt.usd(p.entry_usd) }}</td>
                            <td>
                                <Pnl
                                    :usd="
                                        tab === 'open'
                                            ? p.unrealised_pnl
                                            : p.pnl_usd
                                    "
                                    :pct="
                                        tab === 'open'
                                            ? p.unrealised_pnl_pct
                                            : p.pnl_pct
                                    "
                                />
                            </td>
                            <td class="num">{{ fmt.mins(p.held_minutes) }}</td>
                            <td class="font-mono text-zinc-400">
                                {{ p.close_rule || "—" }}
                            </td>
                            <td>
                                <button
                                    v-if="p.status === 'open'"
                                    class="btn btn-danger !py-0.5"
                                    :disabled="publicDemo" @click.stop="closePos(p)"
                                >
                                    close
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td
                                colspan="12"
                                class="py-6 text-center text-zinc-600"
                            >
                                {{ loading ? 'Acquiring positions…' : error && !loaded ? 'Position data is currently unavailable.' : search || selected ? 'No positions match this focus.' : 'No positions in this view yet.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" v-else>
            <div class="mw-section-title"><div><span class="mw-eyebrow">02 / EXECUTION RECORD</span><h2>Every fill leaves a trace.</h2></div><span>{{ loaded ? fills.length : '—' }} fills</span></div>
            <div class="smx-table-scroll">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>time</th>
                            <th>product</th>
                            <th>side</th>
                            <th>kind</th>
                            <th>requested</th>
                            <th>filled</th>
                            <th>qty</th>
                            <th>decision</th>
                            <th>fill</th>
                            <th>slip</th>
                            <th>fee</th>
                            <th>status</th>
                            <th>note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!fills.length"><td colspan="13" class="mw-empty">{{ loading ? 'Acquiring fills…' : error ? 'Fill history is currently unavailable.' : 'No fills recorded yet.' }}</td></tr>
                        <tr v-for="f in fills" :key="f.id">
                            <td class="num text-zinc-400">
                                {{ fmt.time(f.created_at) }}
                            </td>
                            <td class="font-semibold">{{ f.product_id }}</td>
                            <td
                                :class="
                                    f.side === 'BUY'
                                        ? 'text-emerald-400'
                                        : 'text-red-400'
                                "
                            >
                                {{ f.side }}
                            </td>
                            <td>{{ f.kind }}</td>
                            <td class="num">{{ fmt.usd(f.requested_usd) }}</td>
                            <td class="num">{{ fmt.usd(f.filled_usd) }}</td>
                            <td class="num">{{ fmt.num(f.filled_qty, 6) }}</td>
                            <td class="num">{{ fmt.px(f.decision_price) }}</td>
                            <td class="num">{{ fmt.px(f.fill_price) }}</td>
                            <td class="num">{{ f.slippage_bps ?? "—" }}</td>
                            <td class="num">
                                {{ fmt.num((f.fee_pct || 0) * 100, 2) }}%
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="
                                        f.status === 'filled'
                                            ? 'bg-emerald-500/20 text-emerald-300'
                                            : 'bg-red-500/20 text-red-300'
                                    "
                                    >{{ f.status }}</span
                                >
                            </td>
                            <td class="text-zinc-500">{{ f.note }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mw-detail-panel" v-if="detail">
            <div
                class="mb-2 flex items-center justify-between text-sm text-zinc-500"
            >
                <span
                    >position #{{ detail.id }} · {{ detail.product_id }} ·
                    {{ detail.strategy }}</span
                ><button class="btn" @click="detail = null; detailRequestId++">close panel</button>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <div class="mb-1 text-sm uppercase text-zinc-500">
                        fills
                    </div>
                    <div class="smx-table-scroll">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>time</th>
                                    <th>side</th>
                                    <th>kind</th>
                                    <th>usd</th>
                                    <th>price</th>
                                    <th>slip</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="f in detail.fills" :key="f.id">
                                    <td class="num">
                                        {{ fmt.time(f.created_at) }}
                                    </td>
                                    <td>{{ f.side }}</td>
                                    <td>{{ f.kind }}</td>
                                    <td class="num">
                                        {{ fmt.usd(f.filled_usd) }}
                                    </td>
                                    <td class="num">
                                        {{ fmt.px(f.fill_price) }}
                                    </td>
                                    <td class="num">
                                        {{ f.slippage_bps ?? "—" }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="mb-1 text-sm uppercase text-zinc-500">
                        RISK checks (latest first)
                    </div>
                    <div class="max-h-72 overflow-auto">
                        <div class="smx-table-scroll">
                            <table class="grid">
                                <thead>
                                    <tr>
                                        <th>time</th>
                                        <th>action</th>
                                        <th>ratio 6h</th>
                                        <th>price</th>
                                        <th>pnl</th>
                                        <th>why</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="r in detail.risk_checks"
                                        :key="r.id"
                                    >
                                        <td class="num">
                                            {{ fmt.time(r.created_at) }}
                                        </td>
                                        <td
                                            :class="
                                                r.action === 'CLOSE'
                                                    ? 'text-red-400 font-semibold'
                                                    : ''
                                            "
                                        >
                                            {{ r.action
                                            }}<span
                                                v-if="r.rule_fired"
                                                class="ml-1 font-mono text-zinc-400"
                                                >{{ r.rule_fired }}</span
                                            >
                                        </td>
                                        <td class="num">
                                            {{
                                                r.ratio != null
                                                    ? Number(r.ratio).toFixed(3)
                                                    : "—"
                                            }}
                                        </td>
                                        <td class="num">
                                            {{ fmt.px(r.price) }}
                                        </td>
                                        <td>
                                            <Pnl
                                                :usd="r.pnl_usd"
                                                :pct="r.pnl_pct"
                                            />
                                        </td>
                                        <td class="text-zinc-500">
                                            {{ r.meta?.why }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
