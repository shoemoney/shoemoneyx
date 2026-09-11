<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { api, fmt } from "../api";

const farm = ref({
    hosts: [],
    queues: {},
    throughput: {},
    optimizers: [],
    watch: { at: null, lines: [] },
});
const error = ref("");
const loaded = ref(false);
let timer;

async function load() {
    if (document.hidden) return;
    try {
        farm.value = await api.get("/farm");
        loaded.value = true;
        error.value = "";
    } catch (e) {
        error.value = e.message;
    }
}

/** emerald when the pool is basically full, amber while it's filling, red when an expected host is dark, zinc for an unknown/unexpected host at zero. */
function hostTier(h) {
    if (h.expected && h.workers_est >= 0.8 * h.expected)
        return "border-emerald-500/40 text-emerald-300";
    if (h.workers_est > 0) return "border-amber-500/40 text-amber-300";
    return h.expected
        ? "border-red-500/40 text-red-400"
        : "border-zinc-800 text-zinc-600";
}
const oldestTier = (s) =>
    s === null || s === undefined
        ? "text-zinc-500"
        : s > 5400
          ? "text-red-400"
          : s > 1800
            ? "text-amber-400"
            : "text-zinc-300";
const roundTier = (iso) => {
    if (!iso) return "text-red-400";
    const m = (Date.now() - new Date(iso).getTime()) / 60000;
    return m < 10
        ? "text-emerald-400"
        : m < 30
          ? "text-amber-400"
          : "text-red-400";
};

onMounted(() => {
    load();
    timer = setInterval(load, 10000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div class="card smx-farm">
        <div
            class="mb-2 flex items-center justify-between text-sm text-zinc-500"
        >
            <span
                ><i class="fa-solid fa-server mr-1 text-sky-400"></i>Research compute ·
                {{ loaded ? farm.hosts.length + ' hosts' : 'awaiting telemetry' }}</span
            >
            <span v-if="error" class="text-red-400">{{ error }}</span>
            <span v-else-if="loaded" class="font-mono">{{ fmt.ago(farm.at) }} ago</span>
        </div>
        <div v-if="!loaded" class="research-empty"><div class="research-empty-orbit" aria-hidden="true"><i class="fa-solid fa-server"></i></div><strong>{{ error ? 'Compute telemetry unavailable' : 'Connecting to research compute' }}</strong><p>Worker capacity, queue depth and optimizer activity appear after the first successful snapshot.</p></div>
        <div v-else class="smx-farm-grid grid gap-4 xl:grid-cols-3">
            <div>
                <div
                    class="mb-1 text-sm uppercase tracking-widest text-zinc-500"
                >
                    hosts
                </div>
                <div class="research-hosts">
                    <article
                        v-for="h in farm.hosts"
                        :key="h.ip"
                        class="research-host border font-mono normal-case" tabindex="0"
                        :class="hostTier(h)"
                        :title="`${h.ip} · ${h.connections} connections · ${h.role}`"
                    >
                        <div><i class="fa-solid fa-microchip" aria-hidden="true"></i><strong>{{ h.label }}</strong><span>{{ h.workers_est }}/{{ h.expected ?? "?" }}</span></div>
                        <div class="research-host-capacity" role="img" :aria-label="h.workers_est + ' workers; expected ' + (h.expected ?? 'unknown')"><i :style="{ width: h.expected ? Math.min(100, Math.max(0, h.workers_est / h.expected * 100)) + '%' : '0%' }"></i></div><small>{{ h.role }} · {{ h.connections }} connections</small>
                    </article>
                </div>
            </div>

            <div>
                <div
                    class="mb-1 text-sm uppercase tracking-widest text-zinc-500"
                >
                    queues &amp; throughput
                </div>
                <div class="smx-table-scroll">
                    <table class="grid w-full text-sm">
                        <thead>
                            <tr>
                                <th>queue</th>
                                <th class="text-right">queued</th>
                                <th class="text-right">reserved</th>
                                <th class="text-right">delayed</th>
                                <th class="text-right">oldest</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(q, name) in farm.queues" :key="name">
                                <td class="font-mono text-zinc-300">
                                    {{ name }}
                                </td>
                                <td class="num text-right">{{ q.queued }}</td>
                                <td class="num text-right">{{ q.reserved }}</td>
                                <td class="num text-right">{{ q.delayed }}</td>
                                <td
                                    class="num text-right"
                                    :class="oldestTier(q.oldest_reserved_s)"
                                >
                                    {{
                                        q.oldest_reserved_s === null
                                            ? "—"
                                            : fmt.mins(
                                                  Math.round(
                                                      q.oldest_reserved_s / 60,
                                                  ),
                                              )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="mt-2 flex flex-wrap gap-x-4 gap-y-1 font-mono text-sm text-zinc-400"
                >
                    <span
                        >done/min
                        <b class="text-zinc-200">{{
                            farm.throughput.done_1m
                        }}</b></span
                    >
                    <span
                        >running
                        <b class="text-zinc-200">{{
                            farm.throughput.running
                        }}</b></span
                    >
                    <span
                        >failed 1h
                        <b
                            :class="
                                farm.throughput.failed_1h > 0
                                    ? 'text-red-400'
                                    : 'text-zinc-200'
                            "
                            >{{ farm.throughput.failed_1h }}</b
                        ></span
                    >
                    <span
                        >under-1-contract 5m
                        <b class="text-zinc-200">{{
                            farm.throughput.under_one_contract_5m
                        }}</b></span
                    >
                </div>
            </div>

            <div>
                <div
                    class="mb-1 text-sm uppercase tracking-widest text-zinc-500"
                >
                    optimizers
                </div>
                <div
                    class="smx-farm-processes space-y-1 text-sm"
                    tabindex="0"
                    role="region"
                    aria-label="Optimizer processes"
                >
                    <div
                        v-for="o in farm.optimizers"
                        :key="o.name"
                        class="smx-farm-process font-mono"
                    >
                        <span class="text-zinc-300">{{ o.name }}</span>
                        <span :class="roundTier(o.last_round_at)">{{
                            o.last_round_at
                                ? fmt.ago(o.last_round_at) + " ago"
                                : "never"
                        }}</span>
                        <span class="text-zinc-500"
                            >{{ o.rounds_1h }}/h ·
                            {{ o.promoted_1h }} promoted</span
                        >
                    </div>
                    <div v-if="!farm.optimizers.length" class="text-zinc-600">
                        no rounds in the last hour
                    </div>
                </div>
                <div class="mt-3 border-t border-zinc-800 pt-2">
                    <div
                        class="mb-1 flex items-center gap-2 text-sm uppercase tracking-widest text-zinc-500"
                    >
                        <span>rounds-watch</span>
                        <span class="normal-case">{{
                            farm.watch.at
                                ? fmt.ago(farm.watch.at) + " ago"
                                : "no log yet"
                        }}</span>
                    </div>
                    <div
                        class="smx-farm-watch space-y-0.5 font-mono text-sm leading-6"
                    >
                        <div
                            v-for="(l, i) in farm.watch.lines"
                            :key="i"
                            :class="
                                l.startsWith('[FAIL]')
                                    ? 'text-red-400'
                                    : 'text-zinc-500'
                            "
                        >
                            {{ l }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
