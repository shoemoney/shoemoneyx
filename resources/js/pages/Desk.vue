<script setup>
import PageHeading from "../components/PageHeading.vue";
import { ref, onMounted, onUnmounted, watch, computed } from "vue";
import { useRouter } from "vue-router";
import { api, fmt } from "../api";
import CandidateTable from "../components/CandidateTable.vue";
import EventsFeed from "../components/EventsFeed.vue";
import "../../css/market-workspace.css";

const props = defineProps({ run: { type: String, default: "" } });
const router = useRouter();
const runs = ref([]);
const current = ref(null);
const report = ref(null);
const error = ref("");
const stageFocus = ref('SCAN');
const refreshing = ref(false);
let runRequest = 0;
let alive = true;
const pipeline = computed(() => [
    { name: 'SCAN', label: 'Market discovery', value: current.value?.products_scanned, unit: 'markets observed', description: 'The market universe, ranked by the signals recorded in this cycle.' },
    { name: 'VET', label: 'Conviction check', value: current.value?.passed, unit: 'candidates passed', description: 'The decision trail: accepted candidates, rejection checks, and the reasons behind them.' },
    { name: 'SIZE', label: 'Capital allocation', value: current.value?.candidates ? current.value.candidates.filter((c) => Number(c.size_usd) > 0).length : null, unit: 'sized candidates', description: 'Position sizes recorded after the desk applies its allocation rules.' },
    { name: 'FILLS', label: 'Execution record', value: current.value?.filled, unit: 'fills recorded', description: 'The execution evidence: requested size, actual fills, fees, and slippage.' },
]);
const focusedStage = computed(() => pipeline.value.find((stage) => stage.name === stageFocus.value));
async function refreshDesk() {
    if (refreshing.value) return;
    refreshing.value = true;
    error.value = '';
    await Promise.all([loadRuns(), loadRun(props.run), loadReport()]);
    if (alive) refreshing.value = false;
}

async function loadRuns() {
    try {
        runs.value = (await api.get("/runs?per_page=40")).data;
    } catch (e) {
        error.value = e.message;
    }
}
async function loadRun(id) {
    const request = ++runRequest;
    try {
        const response = id
            ? await api.get(`/runs/${id}`)
            : await api.get("/runs/latest");
        if (alive && request === runRequest) current.value = response;
    } catch (e) {
        if (alive && request === runRequest) error.value = e.message;
    }
}
async function loadReport() {
    try {
        report.value = (await api.get("/report")).report;
    } catch {}
}
const rejections = computed(() => {
    const m = {};
    for (const c of current.value?.candidates || [])
        if (c.verdict === "REJECT")
            m[c.failed_check] = (m[c.failed_check] || 0) + 1;
    return Object.entries(m).sort((a, b) => b[1] - a[1]);
});
watch(
    () => props.run,
    (r) => loadRun(r),
);
onMounted(refreshDesk);
onUnmounted(() => { alive = false; runRequest++; });
</script>

<template>
    <div class="smx-page smx-desk grid grid-cols-[260px_1fr]">
        <PageHeading
            class="smx-layout-heading"
            title="Desk"
            eyebrow="Execution pipeline"
            description="From a market signal to a measured decision. Follow every step."
        />
        <aside class="smx-history overflow-auto border-r border-zinc-800">
            <div
                class="px-3 py-3 text-sm uppercase tracking-widest text-zinc-500"
            >
                <span class="mw-eyebrow">CYCLE ARCHIVE</span><h2>Decision history.</h2>
            </div>
            <div v-if="!runs.length" class="mw-history-empty">{{ refreshing ? 'Acquiring cycles…' : error ? 'Cycle history is unavailable.' : 'Your cycle archive will appear here.' }}</div>
            <button
                v-for="r in runs"
                :key="r.id"
                @click="router.push(`/desk/${r.id}`)"
                class="flex w-full flex-col gap-0.5 border-b border-zinc-800/60 px-3 py-2 text-left text-sm hover:bg-zinc-900"
                :class="current?.id === r.id ? 'smx-history-active' : ''"
                :aria-current="current?.id === r.id ? 'true' : undefined"
            >
                <div class="flex justify-between">
                    <span class="font-mono">#{{ r.id }}</span
                    ><span class="text-zinc-500"
                        >{{ fmt.ago(r.started_at) }} ago</span
                    >
                </div>
                <div class="flex gap-2 text-zinc-500">
                    <span :class="r.status === 'error' ? 'text-red-400' : ''">{{
                        r.status
                    }}</span>
                    <span>{{ r.products_scanned }} scanned</span>
                    <span class="text-emerald-400">{{ r.passed }}P</span>
                    <span class="text-red-400">{{ r.rejected }}R</span>
                    <span class="text-amber-300">{{ r.filled }}F</span>
                </div>
            </button>
        </aside>
        <div class="smx-results space-y-4">
            <div v-if="error" class="mw-error" role="alert">Some desk information is unavailable. {{ error }}</div>
            <div class="mw-section-title">
                <div><span class="mw-eyebrow">01 / THE DECISION ENGINE</span><h2>Intelligence, in sequence.</h2></div>
                <button class="btn" :disabled="refreshing" @click="refreshDesk">{{ refreshing ? 'Synchronizing…' : 'Refresh snapshot' }}</button>
            </div>
            <section class="mw-pipeline" aria-label="Explore the decision pipeline">
                <button v-for="(stage, index) in pipeline" :key="stage.name" :aria-pressed="stageFocus === stage.name" @click="stageFocus = stage.name">
                    <span class="mw-pipeline-index">0{{ index + 1 }}</span><span class="mw-pipeline-name">{{ stage.name }}</span><span>{{ stage.label }}</span><strong>{{ fmt.num(stage.value, 0) }}</strong><small>{{ stage.unit }}</small>
                </button>
            </section>
            <div class="mw-pipeline-context"><span>{{ focusedStage.name }}</span><p>{{ focusedStage.description }}</p><small>RISK operates independently.</small></div>

            <div class="grid gap-3 md:grid-cols-4" v-if="report">
                <div class="tile">
                    <div class="text-sm uppercase text-zinc-500">
                        today · cycles
                    </div>
                    <div class="num text-lg">{{ report.cycles }}</div>
                </div>
                <div class="tile">
                    <div class="text-sm uppercase text-zinc-500">
                        candidates / passed / rejected
                    </div>
                    <div class="num text-lg">
                        {{ report.candidates }} /
                        <span class="text-emerald-400">{{
                            report.passed
                        }}</span>
                        /
                        <span class="text-red-400">{{
                            report.rejections_total
                        }}</span>
                    </div>
                    <div
                        v-if="report.rejections_total < 10 && report.cycles > 6"
                        class="text-sm text-amber-400"
                    >
                        under 10 rejections — filters may be misconfigured
                    </div>
                </div>
                <div class="tile">
                    <div class="text-sm uppercase text-zinc-500">
                        closes today
                    </div>
                    <div class="num text-lg">
                        {{ report.wins }}W / {{ report.losses }}L
                    </div>
                </div>
                <div class="tile">
                    <div class="text-sm uppercase text-zinc-500">
                        realised vs working
                    </div>
                    <div
                        class="num text-lg"
                        :class="
                            report.realised_pnl_usd >= 0
                                ? 'text-emerald-400'
                                : 'text-red-400'
                        "
                    >
                        {{ fmt.usd(report.realised_pnl_usd) }}
                    </div>
                    <div class="text-sm text-zinc-500">
                        {{
                            report.pnl_pct_of_working != null
                                ? report.pnl_pct_of_working +
                                  "% of " +
                                  fmt.usd(report.working_usd)
                                : "nothing closed yet"
                        }}
                    </div>
                </div>
            </div>

            <div class="card" v-if="current">
                <div
                    class="mb-2 flex flex-wrap items-center gap-3 text-sm text-zinc-500"
                >
                    <span class="font-mono text-zinc-300"
                        >cycle #{{ current.id }}</span
                    >
                    <span>{{ fmt.time(current.started_at) }}</span>
                    <span
                        class="badge"
                        :class="
                            current.status === 'error'
                                ? 'bg-red-500/20 text-red-300'
                                : 'bg-zinc-700 text-zinc-300'
                        "
                        >{{ current.status }}</span
                    >
                    <span class="badge bg-zinc-800 text-zinc-300"
                        >{{ current.mode }} · {{ current.strategy }}</span
                    >
                    <span
                        v-if="current.degraded"
                        class="badge bg-amber-500/20 text-amber-300"
                        >degraded</span
                    >
                    <span class="ml-auto" v-if="rejections.length"
                        >rejections:
                        <span
                            v-for="[k, n] in rejections"
                            :key="k"
                            class="mr-2 font-mono text-red-300"
                            >{{ k }} {{ n }}</span
                        ></span
                    >
                </div>
                <div
                    v-if="current.error"
                    class="mb-2 rounded bg-red-500/10 px-3 py-2 text-sm text-red-300"
                >
                    {{ current.error }}
                </div>
                <CandidateTable :candidates="current.candidates" />
                <div v-if="current.fills?.length" class="mt-4">
                    <div class="mb-1 text-sm uppercase text-zinc-500">
                        fills this cycle
                    </div>
                    <div class="smx-table-scroll">
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>product</th>
                                    <th>side</th>
                                    <th>kind</th>
                                    <th>requested</th>
                                    <th>filled</th>
                                    <th>decision</th>
                                    <th>fill</th>
                                    <th>slip</th>
                                    <th>fee</th>
                                    <th>status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="f in current.fills" :key="f.id">
                                    <td class="font-semibold">
                                        {{ f.product_id }}
                                    </td>
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
                                    <td class="num">
                                        {{ fmt.usd(f.requested_usd) }}
                                    </td>
                                    <td class="num">
                                        {{ fmt.usd(f.filled_usd) }}
                                    </td>
                                    <td class="num">
                                        {{ fmt.px(f.decision_price) }}
                                    </td>
                                    <td class="num">
                                        {{ fmt.px(f.fill_price) }}
                                    </td>
                                    <td
                                        class="num"
                                        :class="
                                            (f.slippage_bps ?? 0) > 50
                                                ? 'text-red-400'
                                                : ''
                                        "
                                    >
                                        {{ f.slippage_bps ?? "—" }} bps
                                    </td>
                                    <td class="num">
                                        {{
                                            fmt.num((f.fee_pct || 0) * 100, 2)
                                        }}%
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
                                        <span
                                            v-if="f.partial"
                                            class="badge bg-amber-500/20 text-amber-300"
                                            >partial</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div v-else class="card mw-empty">
                {{ refreshing ? 'Acquiring the current cycle…' : error ? 'The current cycle is unavailable. Your controls and saved history remain unchanged.' : 'Waiting for the first decision cycle. Completed decisions will appear here.' }}
            </div>

            <div class="card">
                <div class="mw-section-title"><div><span class="mw-eyebrow">03 / DESK TELEMETRY</span><h2>The decision trail.</h2></div></div>
                <EventsFeed :limit="80" />
            </div>
        </div>
    </div>
</template>
