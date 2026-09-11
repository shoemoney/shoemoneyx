<script setup>
import { ref, onMounted } from "vue";
import PageHeading from "../components/PageHeading.vue";
import { api } from "../api";

const rows = ref([]);
const loading = ref(false);
const error = ref("");
const loaded = ref(false);

async function load() {
    loading.value = true;
    try {
        const res = await api.get("/exchanges");
        rows.value = res.data || [];
        error.value = "";
        loaded.value = true;
    } catch (e) {
        error.value = e.message || "Could not load the exchange directory.";
    } finally {
        loading.value = false;
    }
}

function capList(caps) {
    if (!caps) return [];
    return [
        ["spot", caps.spot],
        ["perps", caps.perps],
        ["websocket", caps.websocket],
        ["historical trades", caps.historical_trades],
        ["shorts", caps.shorts],
    ];
}

onMounted(load);
</script>

<template>
    <div class="smx-page space-y-4">
        <PageHeading
            title="Exchanges"
            eyebrow="Venue directory"
            description="Every adapter the desk can talk to — native and ccxt — with what it supports and whether its conformance suite passed."
        />

        <div v-if="error" class="mw-error" role="alert">
            Exchange directory could not load. {{ error }}
            <button class="btn" @click="load">Retry</button>
        </div>

        <div class="card">
            <div class="mw-section-title">
                <div>
                    <span class="mw-eyebrow">REGISTERED ADAPTERS</span>
                    <h2>Native and ccxt, side by side.</h2>
                </div>
                <span>{{ loaded ? rows.length : "—" }} adapters</span>
            </div>

            <div class="smx-table-scroll">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>exchange</th>
                            <th>kind</th>
                            <th>capabilities</th>
                            <th>conformance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            :class="row.active ? 'bg-emerald-500/5' : ''"
                        >
                            <td class="font-semibold">
                                {{ row.name }}
                                <span
                                    v-if="row.active"
                                    class="badge bg-emerald-500/20 text-emerald-300 ml-1"
                                    >active</span
                                >
                                <div class="text-xs text-zinc-500">{{ row.id }}</div>
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="row.kind === 'native' ? 'bg-sky-500/20 text-sky-300' : 'bg-zinc-800 text-zinc-300'"
                                    >{{ row.kind }}</span
                                >
                            </td>
                            <td>
                                <span v-if="!row.capabilities" class="text-zinc-500">{{ row.error ? "unavailable" : "—" }}</span>
                                <span v-else class="flex flex-wrap gap-1">
                                    <span
                                        v-for="[label, on] in capList(row.capabilities)"
                                        :key="label"
                                        class="badge"
                                        :class="on ? 'bg-emerald-500/20 text-emerald-300' : 'bg-zinc-800 text-zinc-500'"
                                        >{{ label }}</span
                                    >
                                </span>
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="row.conformance === 'passed' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'"
                                    >{{ row.conformance }}</span
                                >
                            </td>
                        </tr>
                        <tr v-if="loaded && rows.length === 0">
                            <td colspan="4" class="text-zinc-500">No exchange adapters registered.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-zinc-400 mt-4">
                <strong>How to add yours:</strong> implement <code>App\Exchange\Contracts\Exchange</code>,
                record fixtures with <code>php artisan exchange:record &lt;id&gt; &lt;product&gt;</code>,
                and add a conformance test extending <code>ExchangeConformanceTestCase</code>. An
                adapter is only badged <span class="badge bg-emerald-500/20 text-emerald-300">passed</span>
                once both are in the repo and CI runs them — see <code>docs/EXCHANGES.md</code> and the
                "Badge rules" section of <code>CONTRIBUTING.md</code> for the full walkthrough.
            </p>
        </div>
    </div>
</template>
