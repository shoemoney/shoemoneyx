<script setup>
import { publicDemo } from "../demoMode";
import PageHeading from "../components/PageHeading.vue";
import Pnl from "../components/Pnl.vue";
import { ref, onMounted, onUnmounted } from "vue";
import { api, fmt } from "../api";
import "../../css/market-workspace.css";

const seats = ref([]);
const builtinStrategies = ref([]);
const plugins = ref([]);
const loaded = ref(false);
const loading = ref(false);
const error = ref("");
const busySeat = ref(null);

const showAddForm = ref(false);
const addKind = ref("plugin"); // plugin | builtin
const addLabel = ref("");
const addPluginId = ref("");
const addStrategyKey = ref("");
const addCash = ref(1000);
const addError = ref("");
const adding = ref(false);

let timer;

async function loadSeats() {
    loading.value = true;
    try {
        const r = await api.get("/arena");
        seats.value = r.seats;
        builtinStrategies.value = r.builtin_strategies;
        loaded.value = true;
        error.value = "";
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function loadPlugins() {
    try {
        const r = await api.get("/strategy-plugins");
        plugins.value = r.data;
    } catch (e) {
        // non-fatal — the built-in strategy option still works without this
    }
}

async function addSeat() {
    addError.value = "";
    if (!addLabel.value.trim()) {
        addError.value = "give the seat a label";
        return;
    }
    const body = { label: addLabel.value.trim(), cash: Number(addCash.value) || 1000 };
    if (addKind.value === "plugin") {
        if (!addPluginId.value) {
            addError.value = "pick a strategy plugin";
            return;
        }
        body.strategy_plugin_id = Number(addPluginId.value);
    } else {
        if (!addStrategyKey.value) {
            addError.value = "pick a built-in strategy";
            return;
        }
        body.strategy_key = addStrategyKey.value;
    }
    adding.value = true;
    try {
        await api.post("/arena/seats", body);
        addLabel.value = "";
        addPluginId.value = "";
        addStrategyKey.value = "";
        addCash.value = 1000;
        showAddForm.value = false;
        await loadSeats();
    } catch (e) {
        addError.value = e.message;
    } finally {
        adding.value = false;
    }
}

async function promote(seat) {
    if (!confirm(`Make "${seat.label}" the champion?`)) return;
    busySeat.value = seat.id;
    try {
        await api.post(`/arena/seats/${seat.id}/promote`);
        await loadSeats();
    } catch (e) {
        error.value = e.message;
    } finally {
        busySeat.value = null;
    }
}

async function retire(seat) {
    if (!confirm(`Retire "${seat.label}"? It stops trading on the next cycle.`)) return;
    busySeat.value = seat.id;
    try {
        await api.post(`/arena/seats/${seat.id}/retire`);
        await loadSeats();
    } catch (e) {
        error.value = e.message;
    } finally {
        busySeat.value = null;
    }
}

async function destroySeat(seat) {
    if (!confirm(`Delete "${seat.label}" and its whole trading history? This cannot be undone.`)) return;
    busySeat.value = seat.id;
    try {
        await api.del(`/arena/seats/${seat.id}`);
        await loadSeats();
    } catch (e) {
        error.value = e.message;
    } finally {
        busySeat.value = null;
    }
}

onMounted(() => {
    loadSeats();
    loadPlugins();
    timer = setInterval(loadSeats, 10000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div class="smx-page smx-arena space-y-4">
        <div class="smx-page-toolbar">
            <PageHeading title="Arena" eyebrow="Live arena" description="Every seat trades the same live tape in paper, on its own account, against the current champion." />
            <button class="btn btn-primary" :disabled="publicDemo" @click="showAddForm = !showAddForm">{{ showAddForm ? 'cancel' : '+ add seat' }}</button>
        </div>

        <div v-if="error" class="mw-error" role="alert">Arena data could not be refreshed. {{ error }}<button class="btn" @click="loadSeats">Retry</button></div>

        <div class="card" v-if="showAddForm">
            <div class="mw-section-title"><h2>Add a seat</h2></div>
            <div v-if="addError" class="mw-error" role="alert">{{ addError }}</div>
            <div class="flex gap-2" role="group" aria-label="Seat strategy source">
                <button type="button" class="btn" :class="addKind === 'plugin' ? 'btn-primary' : ''" :aria-pressed="addKind === 'plugin'" @click="addKind = 'plugin'">Saved strategy</button>
                <button type="button" class="btn" :class="addKind === 'builtin' ? 'btn-primary' : ''" :aria-pressed="addKind === 'builtin'" @click="addKind = 'builtin'">Built-in strategy</button>
            </div>
            <div class="grid gap-3 md:grid-cols-4 mt-3">
                <label class="flex flex-col gap-1"><span class="text-sm text-zinc-500">Label</span><input v-model="addLabel" placeholder="e.g. mean-reversion v2" /></label>
                <label class="flex flex-col gap-1" v-if="addKind === 'plugin'"><span class="text-sm text-zinc-500">Strategy plugin (current version)</span>
                    <select v-model="addPluginId">
                        <option value="" disabled>choose a plugin</option>
                        <option v-for="p in plugins" :key="p.id" :value="p.id">{{ p.name || p.key }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1" v-else><span class="text-sm text-zinc-500">Built-in strategy</span>
                    <select v-model="addStrategyKey">
                        <option value="" disabled>choose a strategy</option>
                        <option v-for="k in builtinStrategies" :key="k" :value="k">{{ k }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1"><span class="text-sm text-zinc-500">Starting cash</span><input type="number" min="1" step="1" v-model="addCash" /></label>
            </div>
            <div class="mt-3"><button class="btn btn-primary" :disabled="adding || publicDemo" @click="addSeat">{{ adding ? 'adding…' : 'add seat' }}</button></div>
        </div>

        <div class="card">
            <div class="mw-section-title"><div><h2>Scoreboard</h2></div><span>{{ loaded ? seats.length : '—' }} seat{{ seats.length === 1 ? '' : 's' }}</span></div>
            <div class="smx-table-scroll">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>seat</th>
                            <th>strategy</th>
                            <th>status</th>
                            <th>equity</th>
                            <th>pnl</th>
                            <th>vs champion</th>
                            <th>drawdown</th>
                            <th>win rate</th>
                            <th>trades</th>
                            <th>open</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!seats.length">
                            <td colspan="11" class="py-6 text-center text-zinc-600">
                                {{ loading ? 'Acquiring seats…' : error && !loaded ? 'Arena data is currently unavailable.' : 'No seats yet — add one to start a live paper split test.' }}
                            </td>
                        </tr>
                        <tr v-for="seat in seats" :key="seat.id" :class="{ 'bg-amber-500/10': seat.is_champion }">
                            <td class="font-semibold">
                                {{ seat.label }}
                                <span v-if="seat.is_champion" class="badge bg-amber-500/20 text-amber-300 ml-1">champion</span>
                            </td>
                            <td class="font-mono text-zinc-400">{{ seat.strategy_label }}</td>
                            <td><span class="badge" :class="seat.status === 'active' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-zinc-800 text-zinc-400'">{{ seat.status }}</span></td>
                            <td class="num">{{ fmt.usd(seat.equity) }}</td>
                            <td><Pnl :usd="seat.pnl_usd" :pct="seat.pnl_pct" /></td>
                            <td class="num" :class="seat.vs_champion_pct == null ? 'text-zinc-500' : seat.vs_champion_pct >= 0 ? 'text-emerald-400' : 'text-red-400'">{{ seat.vs_champion_pct == null ? '—' : fmt.pct(seat.vs_champion_pct) }}</td>
                            <td class="num text-red-400">{{ fmt.pct(-seat.drawdown_pct) }}</td>
                            <td class="num">{{ seat.win_rate == null ? '—' : seat.win_rate.toFixed(1) + '%' }}</td>
                            <td class="num">{{ seat.trade_count }}</td>
                            <td class="num">{{ seat.open_positions }}</td>
                            <td class="flex gap-1">
                                <button v-if="!seat.is_champion && seat.status === 'active'" class="btn !py-0.5" :disabled="busySeat === seat.id || publicDemo" @click="promote(seat)">promote</button>
                                <button v-if="seat.status === 'active'" class="btn !py-0.5" :disabled="busySeat === seat.id || publicDemo" @click="retire(seat)">retire</button>
                                <button v-if="seat.status === 'retired'" class="btn btn-danger !py-0.5" :disabled="busySeat === seat.id || publicDemo" @click="destroySeat(seat)">delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
