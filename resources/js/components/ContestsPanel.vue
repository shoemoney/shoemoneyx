<script setup>
import { ref, computed, onMounted } from "vue";
import { api, fmt } from "../api";

const TABS = ["live", "upcoming", "settled"];

const tab = ref("live");
const contests = ref([]);
const loading = ref(false);
const error = ref("");

const expandedSlug = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const detailError = ref("");

const enterFor = ref(null); // contest slug the enter form is open for
const plugins = ref([]);
const pluginsLoaded = ref(false);
const versions = ref([]);
const versionsLoading = ref(false);
const enterForm = ref({ plugin_id: "", version: "" });
const entering = ref(false);
const enterError = ref("");

const withdrawing = ref(null);

const stateBadge = {
    upcoming: "border-sky-500/40 bg-sky-500/15 text-sky-200",
    live: "border-emerald-500/40 bg-emerald-500/15 text-emerald-200",
    settled: "border-zinc-600/40 bg-zinc-700/30 text-zinc-300",
};

const selectedPluginVersions = computed(() => versions.value);

async function loadContests() {
    loading.value = true;
    error.value = "";
    try {
        const res = await api.get(`/hub/contests?state=${tab.value}`);
        contests.value = res.contests ?? [];
    } catch (e) {
        error.value = e.message;
    }
    loading.value = false;
}

function switchTab(next) {
    if (tab.value === next) return;
    tab.value = next;
    expandedSlug.value = null;
    detail.value = null;
    loadContests();
}

async function toggleExpand(slug) {
    if (expandedSlug.value === slug) {
        expandedSlug.value = null;
        detail.value = null;
        return;
    }
    expandedSlug.value = slug;
    detail.value = null;
    detailError.value = "";
    detailLoading.value = true;
    try {
        detail.value = await api.get(`/hub/contests/${slug}`);
    } catch (e) {
        detailError.value = e.message;
    }
    detailLoading.value = false;
}

async function loadPlugins() {
    if (pluginsLoaded.value) return;
    try {
        const res = await api.get("/strategy-plugins");
        plugins.value = res.data ?? [];
        pluginsLoaded.value = true;
    } catch (e) {
        enterError.value = e.message;
    }
}

async function onPluginChange() {
    versions.value = [];
    enterForm.value.version = "";
    if (!enterForm.value.plugin_id) return;
    versionsLoading.value = true;
    try {
        const res = await api.get(
            `/strategy-plugins/${enterForm.value.plugin_id}/versions`,
        );
        versions.value = res.data ?? [];
    } catch (e) {
        enterError.value = e.message;
    }
    versionsLoading.value = false;
}

async function openEnter(slug) {
    enterFor.value = enterFor.value === slug ? null : slug;
    enterError.value = "";
    enterForm.value = { plugin_id: "", version: "" };
    versions.value = [];
    if (enterFor.value) await loadPlugins();
}

async function submitEnter(slug) {
    if (!enterForm.value.plugin_id || !enterForm.value.version) {
        enterError.value = "Pick a strategy and version.";
        return;
    }
    entering.value = true;
    enterError.value = "";
    try {
        await api.post(`/hub/contests/${slug}/enter`, {
            plugin_id: Number(enterForm.value.plugin_id),
            version: enterForm.value.version,
        });
        enterFor.value = null;
        if (expandedSlug.value === slug) {
            detail.value = await api.get(`/hub/contests/${slug}`);
        }
        await loadContests();
    } catch (e) {
        enterError.value = e.message;
    }
    entering.value = false;
}

async function withdraw(slug) {
    withdrawing.value = slug;
    try {
        await api.del(`/hub/contests/${slug}/enter`);
        if (expandedSlug.value === slug) {
            detail.value = await api.get(`/hub/contests/${slug}`);
        }
    } catch (e) {
        error.value = e.message;
    }
    withdrawing.value = null;
}

onMounted(loadContests);
</script>

<template>
    <div class="smx-contests-panel space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex gap-1">
                <button
                    v-for="t in TABS"
                    :key="t"
                    class="btn"
                    :class="tab === t ? 'btn-primary' : ''"
                    @click="switchTab(t)"
                >
                    {{ t }}
                </button>
            </div>
            <span v-if="loading" class="text-xs text-zinc-500">loading…</span>
        </div>

        <div v-if="error" class="card border-red-500/40 text-sm text-red-300">
            {{ error }}
        </div>

        <div
            v-else-if="!loading && contests.length === 0"
            class="card text-sm text-zinc-500"
        >
            No {{ tab }} contests right now.
        </div>

        <div v-else class="space-y-3">
            <div v-for="c in contests" :key="c.slug" class="card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span
                                class="badge border"
                                :class="stateBadge[tab]"
                                >{{ tab }}</span
                            >
                            <strong class="truncate">{{ c.name }}</strong>
                            <span class="font-mono text-xs text-zinc-500">{{
                                c.slug
                            }}</span>
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">
                            {{ c.exchange }} ·
                            {{ (c.assets || []).join(", ") }} ·
                            {{ c.entrants ?? 0 }} entrants · starting
                            {{ fmt.usd(c.starting_cash, 0) }}
                        </div>
                        <div class="mt-1 text-xs text-zinc-600">
                            {{ fmt.time(c.starts_at) }} →
                            {{ fmt.time(c.ends_at) }}
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button
                            class="btn"
                            @click="toggleExpand(c.slug)"
                        >
                            {{
                                expandedSlug === c.slug
                                    ? "Hide leaderboard"
                                    : "Leaderboard"
                            }}
                        </button>
                        <button
                            v-if="tab !== 'settled'"
                            class="btn btn-ok"
                            @click="openEnter(c.slug)"
                        >
                            {{ enterFor === c.slug ? "Cancel" : "Enter" }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="enterFor === c.slug"
                    class="mt-3 flex flex-wrap items-end gap-2 border-t border-zinc-800 pt-3"
                >
                    <label class="flex flex-col gap-1 text-xs text-zinc-400"
                        >Strategy
                        <select
                            v-model="enterForm.plugin_id"
                            @change="onPluginChange"
                        >
                            <option value="" disabled>Select…</option>
                            <option
                                v-for="p in plugins"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name || p.key }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs text-zinc-400"
                        >Version
                        <select
                            v-model="enterForm.version"
                            :disabled="!enterForm.plugin_id || versionsLoading"
                        >
                            <option value="" disabled>
                                {{
                                    versionsLoading
                                        ? "loading…"
                                        : "Select…"
                                }}
                            </option>
                            <option
                                v-for="v in selectedPluginVersions"
                                :key="v.id"
                                :value="v.version"
                            >
                                {{ v.version }}
                            </option>
                        </select>
                    </label>
                    <button
                        class="btn btn-primary"
                        :disabled="entering"
                        @click="submitEnter(c.slug)"
                    >
                        {{ entering ? "Entering…" : "Confirm entry" }}
                    </button>
                    <span v-if="enterError" class="text-xs text-red-300">{{
                        enterError
                    }}</span>
                </div>

                <div
                    v-if="expandedSlug === c.slug"
                    class="mt-3 border-t border-zinc-800 pt-3"
                >
                    <div
                        v-if="detailLoading"
                        class="text-xs text-zinc-500"
                    >
                        loading leaderboard…
                    </div>
                    <div
                        v-else-if="detailError"
                        class="text-xs text-red-300"
                    >
                        {{ detailError }}
                    </div>
                    <template v-else-if="detail">
                        <div
                            v-if="detail.me"
                            class="mb-2 flex items-center justify-between text-xs text-zinc-400"
                        >
                            <span
                                >You're entered — rank
                                {{ detail.me.rank ?? "—" }}, equity
                                {{ fmt.usd(detail.me.equity) }}</span
                            >
                            <button
                                class="btn btn-danger"
                                :disabled="withdrawing === c.slug"
                                @click="withdraw(c.slug)"
                            >
                                {{
                                    withdrawing === c.slug
                                        ? "Withdrawing…"
                                        : "Withdraw"
                                }}
                            </button>
                        </div>
                        <div class="smx-table-scroll">
                            <table class="grid">
                                <thead>
                                    <tr>
                                        <th>rank</th>
                                        <th>handle</th>
                                        <th class="text-right">equity</th>
                                        <th class="text-right">return</th>
                                        <th class="text-right">drawdown</th>
                                        <th class="text-right">trades</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in detail.leaderboard || []"
                                        :key="row.rank"
                                    >
                                        <td class="num">{{ row.rank }}</td>
                                        <td class="font-mono">
                                            {{ row.handle }}
                                        </td>
                                        <td class="num text-right">
                                            {{ fmt.usd(row.equity) }}
                                        </td>
                                        <td class="num text-right">
                                            {{ fmt.pct(row.return_pct) }}
                                        </td>
                                        <td class="num text-right">
                                            {{ fmt.pct(row.drawdown_pct) }}
                                        </td>
                                        <td class="num text-right">
                                            {{ row.trades }}
                                        </td>
                                    </tr>
                                    <tr v-if="!(detail.leaderboard || []).length">
                                        <td
                                            colspan="6"
                                            class="text-center text-zinc-500"
                                        >
                                            No entrants yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
