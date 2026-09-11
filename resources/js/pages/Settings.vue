<script setup>
import { publicDemo } from "../demoMode";
import PageHeading from "../components/PageHeading.vue";
import { ref, onMounted, inject, computed, watch } from "vue";
import { api } from "../api";
import "../../css/research-controls.css";

const s = ref(null);
const error = ref("");
const msg = ref("");
const filter = ref("");
const activeGroup = ref("all");
const loading = ref(true);
const PARAMETER_BATCH = 100;
const visibleLimit = ref(PARAMETER_BATCH);
const edits = ref({});
const refreshStatus = inject("refreshStatus");

async function load() {
    loading.value = true;
    error.value = "";
    try {
        s.value = await api.get("/settings");
        edits.value = {};
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}
async function save(key, value) {
    msg.value = "";
    error.value = "";
    try {
        await api.put("/settings", { key, value });
        msg.value = `saved ${key}`;
        await load();
        await refreshStatus();
    } catch (e) {
        error.value = e.message;
    }
}
async function reset(key) {
    try {
        await api.del(`/settings/${key}`);
        msg.value = `reset ${key}`;
        await load();
        await refreshStatus();
    } catch (e) {
        error.value = e.message;
    }
}
async function act(action) {
    msg.value = "";
    error.value = "";
    if (
        action === "paper-reset" &&
        !confirm("Wipe all paper positions and the paper ledger?")
    )
        return;
    try {
        await api.post(`/desk/${action}`);
        msg.value = `${action} ok`;
        await refreshStatus();
    } catch (e) {
        error.value = e.message;
    }
}
const allGroups = computed(() => {
    if (!s.value) return [];
    const g = {};
    for (const [k, v] of Object.entries(s.value.params)) {
        if (
            ["mode", "strategy"].includes(k) ||
            k.startsWith("strategies") ||
            k.startsWith("universe.quote") ||
            k.startsWith("universe.stable") ||
            k.startsWith("universe.exclude")
        )
            continue;
        const grp = k.split(".")[0];
        (g[grp] ||= []).push({
            key: k,
            value: v,
            override: s.value.overrides[k] !== undefined,
        });
    }
    return Object.entries(g);
});
const matchingGroups = computed(() =>
    allGroups.value
        .filter(
            ([name]) =>
                activeGroup.value === "all" || activeGroup.value === name,
        )
        .map(([name, items]) => [
            name,
            items.filter((item) =>
                item.key
                    .toLowerCase()
                    .includes(filter.value.trim().toLowerCase()),
            ),
        ])
        .filter(([, items]) => items.length),
);
const parameterCount = computed(() =>
    allGroups.value.reduce((total, [, items]) => total + items.length, 0),
);
const overrideCount = computed(() =>
    allGroups.value.reduce(
        (total, [, items]) =>
            total + items.filter((item) => item.override).length,
        0,
    ),
);
// Keep the full configuration searchable without mounting thousands of inputs.
const groups = computed(() => {
    let remaining = visibleLimit.value;
    return matchingGroups.value
        .map(([name, items]) => {
            const visible = items.slice(0, remaining);
            remaining -= visible.length;
            return [name, visible];
        })
        .filter(([, items]) => items.length);
});
const matchingCount = computed(() =>
    matchingGroups.value.reduce((total, [, items]) => total + items.length, 0),
);
watch([filter, activeGroup], () => {
    visibleLimit.value = PARAMETER_BATCH;
});
const visibleCount = computed(() =>
    groups.value.reduce((total, [, items]) => total + items.length, 0),
);
const pendingCount = computed(
    () =>
        Object.entries(edits.value).filter(
            ([key, value]) => value != s.value?.params[key],
        ).length,
);
function clearFilters() {
    filter.value = "";
    activeGroup.value = "all";
}
onMounted(load);
</script>

<template>
    <div class="smx-page smx-settings rc-page">
        <PageHeading
            title="Settings"
            eyebrow="Desk configuration"
            description="Tune the intelligence. Define the boundaries."
        />
        <div v-if="error" class="rc-notice rc-notice-error" role="alert">
            <span class="rc-notice-symbol" aria-hidden="true">!</span>
            <div>
                <strong>Configuration request failed</strong>
                <p>{{ error }}</p>
            </div>
            <button class="btn" :disabled="loading" @click="load">Retry</button>
        </div>
        <Transition name="rc-notice"
            ><div v-if="msg" class="rc-notice rc-notice-success" role="status">
                <span aria-hidden="true">✓</span><strong>{{ msg }}</strong>
            </div></Transition
        >

        <template v-if="s">
            <div class="rc-state-rail">
                <span class="rc-overline">SYSTEM / CONFIGURATION</span>
                <span
                    ><i :class="s.mode === 'live' ? 'rc-led-danger' : ''"></i
                    >{{ s.mode }} mode</span
                >
                <span>{{ s.strategy }}</span>
                <span>{{ parameterCount }} parameters</span>
                <span>{{ overrideCount }} overrides</span>
                <span v-if="pendingCount" class="rc-pending-count" role="status"
                    >{{ pendingCount }} unsaved</span
                >
            </div>
            <section class="rc-command-grid" aria-label="Desk controls">
                <article
                    class="rc-panel rc-mode-panel"
                    :class="{ 'rc-is-live': s.mode === 'live' }"
                >
                    <div class="rc-panel-heading">
                        <span class="rc-section-no">01</span>
                        <div>
                            <span class="rc-overline"
                                >Execution environment</span
                            >
                            <h2>Operating mode</h2>
                        </div>
                    </div>
                    <div class="rc-mode-switch">
                        <button
                            class="btn"
                            :class="s.mode === 'paper' ? 'btn-primary' : ''"
                            :aria-pressed="s.mode === 'paper'"
                            :disabled="publicDemo" @click="save('mode', 'paper')"
                        >
                            <span class="rc-mode-dot"></span
                            ><strong>Paper</strong
                            ><small>Simulated execution</small>
                        </button>
                        <button
                            class="btn rc-live-choice"
                            :class="s.mode === 'live' ? 'btn-danger' : ''"
                            :aria-pressed="s.mode === 'live'"
                            :disabled="publicDemo" @click="save('mode', 'live')"
                        >
                            <span class="rc-mode-dot"></span
                            ><strong>Live</strong
                            ><small>Real order execution</small>
                        </button>
                    </div>
                    <p
                        v-if="s.mode === 'live' && !s.live_confirm"
                        class="rc-inline-danger"
                    >
                        Live execution is not confirmed. No orders will be sent
                        to Coinbase.
                    </p>
                    <p class="rc-help">
                        Paper simulates orders. Live sends real orders after
                        account verification and execution confirmation.
                    </p>
                </article>
                <article class="rc-panel">
                    <div class="rc-panel-heading">
                        <span class="rc-section-no">02</span>
                        <div>
                            <span class="rc-overline">Decision engine</span>
                            <h2>Strategy profile</h2>
                        </div>
                    </div>
                    <label class="rc-field"
                        >Active strategy<select
                            :value="s.strategy"
                            :disabled="publicDemo" @change="save('strategy', $event.target.value)"
                        >
                            <option
                                v-for="st in s.strategies"
                                :key="st.key"
                                :value="st.key"
                            >
                                {{ st.key }} — {{ st.name }}
                            </option>
                        </select></label
                    >
                    <div class="rc-profile-line" aria-hidden="true">
                        <span></span><span></span><span></span><span></span
                        ><span></span><span></span><span></span><span></span
                        ><span></span>
                    </div>
                    <p class="rc-help">
                        Select the decision rules used by your trading desk.
                        Review their parameters in the workbench below.
                    </p>
                </article>
                <article class="rc-panel">
                    <div class="rc-panel-heading">
                        <span class="rc-section-no">03</span>
                        <div>
                            <span class="rc-overline">Desk operations</span>
                            <h2>Command controls</h2>
                        </div>
                    </div>
                    <div class="rc-control-buttons">
                        <button class="btn btn-ok" :disabled="publicDemo" @click="act('start')">
                            Start loop
                        </button>
                        <button class="btn" :disabled="publicDemo" @click="act('stop')">
                            Stop loop
                        </button>
                        <button class="btn btn-danger" :disabled="publicDemo" @click="act('halt')">
                            Halt entries
                        </button>
                        <button class="btn" :disabled="publicDemo" @click="act('resume')">
                            Resume entries
                        </button>
                        <button
                            class="btn btn-danger rc-paper-reset"
                            :disabled="publicDemo" @click="act('paper-reset')"
                        >
                            Reset paper book
                        </button>
                    </div>
                    <p class="rc-help">
                        Start and stop control the desk loop. Halt blocks new
                        entries; RISK continues managing open positions.
                    </p>
                </article>
            </section>

            <section
                class="rc-workbench rc-panel"
                aria-labelledby="rc-parameters-title"
            >
                <div class="rc-workbench-heading">
                    <div>
                        <span class="rc-overline">PRECISION / CONTROL</span>
                        <h2 id="rc-parameters-title">
                            Parameter workbench<span class="rc-count">{{
                                parameterCount
                            }}</span>
                        </h2>
                        <p class="rc-help">
                            Config defaults → strategy defaults →
                            <span class="rc-blue">your overrides</span>
                        </p>
                    </div>
                    <div class="rc-search-wrap">
                        <label for="rc-parameter-search"
                            >Find a parameter</label
                        >
                        <div>
                            <span aria-hidden="true">⌕</span
                            ><input
                                id="rc-parameter-search"
                                v-model="filter"
                                type="search"
                                placeholder="Search risk, size, universe…"
                            /><button
                                v-if="filter"
                                @click="filter = ''"
                                aria-label="Clear parameter search"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                </div>
                <div class="rc-workbench-layout">
                    <nav class="rc-group-nav" aria-label="Parameter sections">
                        <button
                            :aria-pressed="activeGroup === 'all'"
                            @click="activeGroup = 'all'"
                        >
                            <span>All parameters</span
                            ><b>{{ parameterCount }}</b>
                        </button>
                        <button
                            v-for="([group, items], index) in allGroups"
                            :key="group"
                            :aria-pressed="activeGroup === group"
                            @click="activeGroup = group"
                        >
                            <small>{{
                                String(index + 1).padStart(2, "0")
                            }}</small
                            ><span>{{ group }}</span
                            ><b>{{ items.length }}</b>
                        </button>
                    </nav>
                    <div class="rc-parameter-body">
                        <div class="rc-result-caption" role="status">
                            <span
                                >Showing {{ visibleCount }} of
                                {{ matchingCount }} matching parameters</span
                            ><span>{{ publicDemo ? "Sample configuration · read-only" : "Save each change to apply" }}</span>
                        </div>
                        <TransitionGroup
                            name="rc-group"
                            tag="div"
                            class="rc-parameter-groups"
                        >
                            <article
                                v-for="[grp, items] in groups"
                                :key="grp"
                                class="rc-parameter-group"
                            >
                                <header>
                                    <span class="rc-section-no">{{
                                        String(
                                            allGroups.findIndex(
                                                ([name]) => name === grp,
                                            ) + 1,
                                        ).padStart(2, "0")
                                    }}</span>
                                    <h3>{{ grp }}</h3>
                                    <span class="rc-count">{{
                                        items.length
                                    }}</span>
                                </header>
                                <div
                                    v-for="it in items"
                                    :key="it.key"
                                    class="rc-parameter-row"
                                    :class="{
                                        'rc-overridden': it.override,
                                        'rc-edited':
                                            edits[it.key] !== undefined &&
                                            edits[it.key] != it.value,
                                    }"
                                >
                                    <div class="rc-parameter-label">
                                        <label
                                            :for="'rc-param-' + it.key"
                                            :title="it.key"
                                            >{{
                                                it.key.slice(grp.length + 1)
                                            }}</label
                                        ><span
                                            v-if="it.override"
                                            class="rc-override-label"
                                            >Override</span
                                        >
                                    </div>
                                    <input
                                        v-if="typeof it.value !== 'object'"
                                        :id="'rc-param-' + it.key"
                                        :aria-label="it.key"
                                        :readonly="publicDemo" :value="edits[it.key] ?? it.value"
                                        @input="
                                            edits[it.key] = $event.target.value
                                        "
                                        @keyup.enter="
                                            save(
                                                it.key,
                                                edits[it.key] ?? it.value,
                                            )
                                        "
                                    />
                                    <code v-else class="rc-object-value">{{
                                        JSON.stringify(it.value)
                                    }}</code>
                                    <div class="rc-parameter-actions">
                                        <button
                                            class="btn btn-primary"
                                            v-if="
                                                !publicDemo && edits[it.key] !== undefined &&
                                                edits[it.key] != it.value
                                            "
                                            @click="save(it.key, edits[it.key])"
                                            :aria-label="'Save ' + it.key"
                                        >
                                            Save</button
                                        ><button
                                            class="btn"
                                            v-if="it.override"
                                            @click="reset(it.key)"
                                            :aria-label="
                                                'Remove override for ' + it.key
                                            "
                                            title="Remove override"
                                        >
                                            ↺
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </TransitionGroup>
                        <div
                            v-if="visibleCount < matchingCount"
                            class="rc-more-parameters"
                        >
                            <p>
                                {{ matchingCount - visibleCount }} more matching
                                parameters. Search or choose a section to narrow
                                the list.
                            </p>
                            <button
                                class="btn btn-primary"
                                @click="visibleLimit += PARAMETER_BATCH"
                            >
                                Show next
                                {{
                                    Math.min(
                                        PARAMETER_BATCH,
                                        matchingCount - visibleCount,
                                    )
                                }}
                                parameters
                            </button>
                        </div>
                        <div v-if="!groups.length" class="rc-search-empty">
                            <span aria-hidden="true">⌕</span>
                            <h3>No matching parameters</h3>
                            <p>Try a different name or show every section.</p>
                            <button class="btn" @click="clearFilters">
                                Clear filters
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </template>
        <section v-else class="rc-panel rc-standby" :aria-busy="loading">
            <div class="rc-orbital" aria-hidden="true">
                <i></i><i></i><span>⚙</span>
            </div>
            <span class="rc-overline">CONTROL INTERFACE</span>
            <h2>
                {{
                    loading
                        ? "Connecting to configuration"
                        : "Configuration unavailable"
                }}
            </h2>
            <p>
                {{
                    loading
                        ? "Retrieving the desk’s active strategy and parameters."
                        : "The desk has not supplied configuration for this session. Retry when access is available."
                }}
            </p>
            <div class="rc-capability-strip">
                <span>Execution mode</span><span>Strategy profile</span
                ><span>Risk parameters</span>
            </div>
            <button v-if="!loading" class="btn btn-primary" @click="load">
                Retry configuration
            </button>
        </section>
    </div>
</template>
