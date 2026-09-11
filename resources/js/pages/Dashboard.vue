<script setup>
import { publicDemo } from "../demoMode";
import { ref, inject, onMounted, onUnmounted, computed, watch } from "vue";
import { api, fmt } from "../api";
import { pngUrlFor } from "../coinIcons";
import PageHeading from "../components/PageHeading.vue";
import CandidateTable from "../components/CandidateTable.vue";
import CommandAtmosphere from "../components/dashboard/CommandAtmosphere.vue";
import EquityObservatory from "../components/dashboard/EquityObservatory.vue";
import PositionSpectrum from "../components/dashboard/PositionSpectrum.vue";
import ReactiveValue from "../components/dashboard/ReactiveValue.vue";
import SignalFeed from "../components/dashboard/SignalFeed.vue";
import {
    finiteNumber,
    windowHistory,
    sumPositionPnl,
    equityChange,
} from "../components/dashboard/dashboardData";
import "../components/dashboard/dashboard.css";
const status = inject("status", ref(null)),
    statusError = inject("statusError", ref(""));
const refreshStatus = inject("refreshStatus", async () => {});
const positions = ref([]),
    history = ref([]),
    latest = ref(null);
const loaded = ref({ positions: false, history: false, latest: false });
const errors = ref({ positions: "", history: "", latest: "" });
const loading = ref(false),
    busy = ref(""),
    msg = ref(""),
    messageError = ref(false);
const updatedAt = ref(null),
    now = ref(Date.now()),
    pulse = ref(0),
    selected = ref(null);
const hours = ref(168),
    query = ref(""),
    showDetails = ref(false);
const effects = ref(true),
    reduced = ref(false),
    hidden = ref(document.hidden),
    inView = ref(true),
    root = ref(null);
let timer,
    clockTimer,
    media,
    observer,
    disposed = false,
    request = 0;
const active = computed(
    () => effects.value && !reduced.value && !hidden.value && inView.value,
);
const health = computed(() => status.value?.health);
const bank = computed(() =>
    status.value?.bank_error ? null : status.value?.bank,
);
const bankNumber = (key) => finiteNumber(bank.value?.[key]);
const rows = computed(() => windowHistory(history.value, hours.value));
const change = computed(() => equityChange(rows.value));
const partial = computed(
    () =>
        finiteNumber(status.value?.open_positions) !== null &&
        status.value.open_positions > positions.value.length,
);
const unreal = computed(() =>
    sumPositionPnl(positions.value, loaded.value.positions && !partial.value),
);
const age = computed(() =>
    updatedAt.value
        ? Math.max(0, Math.floor((now.value - updatedAt.value) / 1000))
        : null,
);
const stale = computed(
    () =>
        Object.values(errors.value).some(Boolean) ||
        !!statusError.value ||
        !!status.value?.bank_error ||
        (age.value !== null && age.value > 30),
);
const deskLabel = computed(() =>
    !health.value
        ? "Awaiting desk"
        : health.value.halted
          ? "Desk halted"
          : health.value.running
            ? "Desk running"
            : "Desk idle",
);
const agents = computed(() =>
    Object.entries(health.value?.heartbeats || {}).map(([name, age]) => ({
        name,
        age: finiteNumber(age),
    })),
);
const shownPositions = computed(() =>
    positions.value.filter((p) =>
        p.product_id.toLowerCase().includes(query.value.trim().toLowerCase()),
    ),
);
const selectedPosition = computed(() =>
    positions.value.find((p) => p.id === selected.value),
);
const candidates = computed(() =>
    Array.isArray(latest.value?.candidates) ? latest.value.candidates : null,
);
const metrics = computed(() => [
    {
        label: "Cash balance",
        value: bankNumber("cash"),
        icon: "fa-layer-group",
        detail: "Account cash",
    },
    {
        label: "Free cash",
        value: bankNumber("free_cash"),
        icon: "fa-bolt",
        detail: "Locked " + fmt.usd(bankNumber("locked")),
    },
    {
        label: "Working capital",
        value: bankNumber("positions_value"),
        icon: "fa-circle-nodes",
        detail: status.value
            ? status.value.open_positions + " open positions"
            : "Positions unavailable",
    },
    {
        label: "Unrealised P&L",
        value: unreal.value,
        icon: "fa-wave-pulse",
        detail: partial.value
            ? "Incomplete position snapshot"
            : !loaded.value.positions
              ? "Awaiting position marks"
              : positions.value.some(
                      (p) => finiteNumber(p.unrealised_pnl) === null,
                  )
                ? "Some position marks unavailable"
                : "Open positions · current marks",
        pnl: true,
    },
    {
        label: "Realised today",
        value: finiteNumber(status.value?.realised_today),
        icon: "fa-flag-checkered",
        detail: "All time " + fmt.usd(status.value?.realised_total),
        pnl: true,
    },
]);
async function load() {
    if (loading.value || disposed || document.hidden) return;
    const id = ++request;
    loading.value = true;
    const results = await Promise.allSettled([
        api.get("/positions?status=open"),
        api.get("/bank/history?hours=168"),
        api.get("/runs/latest"),
    ]);
    if (disposed || request !== id) return;
    const keys = ["positions", "history", "latest"],
        targets = [positions, history, latest];
    results.forEach((result, i) => {
        const key = keys[i];
        if (
            result.status === "fulfilled" &&
            (i === 2 || Array.isArray(result.value))
        ) {
            targets[i].value = result.value;
            loaded.value[key] = true;
            errors.value[key] = "";
        } else
            errors.value[key] =
                result.status === "rejected"
                    ? result.reason.message
                    : "Unexpected response. Last successful snapshot retained.";
    });
    if (!Object.values(errors.value).some(Boolean)) {
        updatedAt.value = Date.now();
        pulse.value++;
    }
    loading.value = false;
}
async function refreshAll() {
    await Promise.all([load(), refreshStatus()]);
}
async function act(action) {
    if (busy.value) return;
    busy.value = action;
    msg.value = "";
    messageError.value = false;
    try {
        const result = await api.post(`/desk/${action}`);
        msg.value =
            action === "cycle"
                ? `Cycle #${result.run.id}: ${Array.isArray(result.run.candidates) ? result.run.candidates.length : result.run.candidates} candidates, ${result.run.passed} passed, ${result.run.rejected} rejected, ${result.run.filled} filled`
                : `${action} ok`;
        await refreshAll();
    } catch (error) {
        msg.value = error.message;
        messageError.value = true;
    } finally {
        busy.value = "";
    }
}
async function closePos(position) {
    if (busy.value || !confirm(`Close ${position.product_id} at market?`))
        return;
    busy.value = "close-" + position.id;
    msg.value = "";
    messageError.value = false;
    try {
        await api.post(`/positions/${position.id}/close`);
        msg.value = `Closed ${position.product_id}.`;
        await refreshAll();
    } catch (error) {
        msg.value = error.message;
        messageError.value = true;
    } finally {
        busy.value = "";
    }
}
function heartbeatText(age) {
    return age === null
        ? "Never reported"
        : age < 60
          ? age + "s ago"
          : Math.round(age / 60) + "m ago";
}
function selectPosition(id) {
    selected.value = selected.value === id ? null : id;
}
function visibility() {
    hidden.value = document.hidden;
    if (!document.hidden) {
        now.value = Date.now();
        refreshAll();
    }
}
function motionChange(event) {
    reduced.value = event.matches;
}
function tilt(event) {
    if (!active.value || event.pointerType === "touch") return;
    const box = event.currentTarget.getBoundingClientRect(),
        x = (event.clientX - box.left) / box.width,
        y = (event.clientY - box.top) / box.height;
    event.currentTarget.style.setProperty("--mx", x * 100 + "%");
    event.currentTarget.style.setProperty("--my", y * 100 + "%");
    event.currentTarget.style.setProperty("--rx", (0.5 - y) * 3 + "deg");
    event.currentTarget.style.setProperty("--ry", (x - 0.5) * 3 + "deg");
}
function untilt(event) {
    event.currentTarget.style.setProperty("--rx", "0deg");
    event.currentTarget.style.setProperty("--ry", "0deg");
}
watch(
    () => status.value?.server_time,
    () => {
        if (active.value) pulse.value++;
    },
);
onMounted(() => {
    try {
        effects.value = localStorage.getItem("smx-dashboard-effects") !== "off";
    } catch {}
    media = matchMedia("(prefers-reduced-motion: reduce)");
    reduced.value = media.matches;
    media.addEventListener("change", motionChange);
    observer = new IntersectionObserver(([entry]) => {
        inView.value = entry.isIntersecting;
    });
    observer.observe(root.value);
    load();
    timer = setInterval(load, 10000);
    clockTimer = setInterval(() => {
        if (!document.hidden) now.value = Date.now();
    }, 1000);
    document.addEventListener("visibilitychange", visibility);
});
watch(effects, (enabled) => {
    try {
        localStorage.setItem("smx-dashboard-effects", enabled ? "on" : "off");
    } catch {}
});
onUnmounted(() => {
    disposed = true;
    request++;
    clearInterval(timer);
    clearInterval(clockTimer);
    media?.removeEventListener("change", motionChange);
    observer?.disconnect();
    document.removeEventListener("visibilitychange", visibility);
});
</script>
<template>
    <div
        ref="root"
        class="smx-page smx-dashboard dc-dashboard"
        :class="{ 'dc-still': !active }"
    >
        <div class="dc-command-heading">
            <PageHeading
                title="Command center"
                eyebrow="ShoeMoney AI / dashboard"
            />
            <div class="dc-command-controls">
                <span
                    class="dc-state"
                    :data-state="
                        health?.halted || stale
                            ? 'warning'
                            : health?.running
                              ? 'ok'
                              : 'idle'
                    "
                    ><span></span>{{ deskLabel }}</span
                ><button
                    class="dc-effects btn"
                    :aria-pressed="effects"
                    @click="effects = !effects"
                >
                    <i
                        :class="[
                            'fa-solid',
                            effects ? 'fa-wave-pulse' : 'fa-pause',
                        ]"
                        aria-hidden="true"
                    ></i
                    >{{
                        reduced && effects
                            ? "Reduced motion"
                            : effects
                              ? "Effects on"
                              : "Effects paused"
                    }}</button
                ><button class="btn" :disabled="loading" @click="refreshAll">
                    <i
                        class="fa-solid fa-clock-rotate-left"
                        aria-hidden="true"
                    ></i
                    >{{ loading ? "Syncing" : "Refresh" }}
                </button>
            </div>
        </div>
        <div
            v-if="statusError || status?.bank_error"
            class="dc-error"
            role="status"
        >
            {{
                status?.bank_error
                    ? "Bank unavailable: " + status.bank_error
                    : "Status update failed: " + statusError
            }}<span v-if="statusError && status">
                · Account values show the last successful status.</span
            >
        </div>
        <div
            v-if="msg"
            class="dc-notice"
            :class="{ 'dc-error': messageError }"
            role="status"
        >
            {{ msg }}
        </div>
        <section class="dc-reactor dc-panel" aria-labelledby="dc-capital-title">
            <CommandAtmosphere :active="active" :pulse="pulse" />
            <div :key="pulse" class="dc-data-wave" aria-hidden="true"></div>
            <div class="dc-reactor-top">
                <div>
                    <span class="dc-kicker" id="dc-capital-title"
                        >Account equity</span
                    >
                    <div class="dc-equity-value">
                        <ReactiveValue
                            :value="bankNumber('equity')"
                            :active="active"
                        />
                    </div>
                    <div class="dc-equity-caption">
                        <span v-if="health?.mode" class="dc-mode"
                            >{{ health.mode }} execution</span
                        ><span>{{
                            status?.server_time
                                ? "As of " +
                                  new Date(
                                      status.server_time,
                                  ).toLocaleTimeString()
                                : "Awaiting account status"
                        }}</span>
                    </div>
                </div>
                <div class="dc-window">
                    <span class="dc-kicker">Observation window</span>
                    <div class="dc-segments" aria-label="Equity history window">
                        <button
                            v-for="window in [
                                { h: 24, text: '24H' },
                                { h: 72, text: '72H' },
                                { h: 168, text: '7D' },
                            ]"
                            :key="window.h"
                            :aria-pressed="hours === window.h"
                            @click="hours = window.h"
                        >
                            {{ window.text }}
                        </button>
                    </div>
                    <span class="dc-window-change"
                        >{{ fmt.usd(change) }} <span>balance change</span></span
                    >
                </div>
            </div>
            <div class="dc-reactor-grid">
                <div class="dc-equity-module">
                    <EquityObservatory
                        :rows="rows"
                        :window-hours="hours"
                        :active="active"
                        :loading="!loaded.history && loading"
                        :error="errors.history"
                    />
                    <p class="dc-footnote">
                        Account snapshots ·
                        {{ rows.length.toLocaleString() }} observations ·
                        Balance changes include cash movements.
                    </p>
                    <p v-if="errors.history && loaded.history" class="dc-error">
                        History update failed: {{ errors.history }} · Showing
                        last successful history.
                    </p>
                </div>
                <aside class="dc-core-module" aria-label="Desk agent health">
                    <div class="dc-core-art" aria-hidden="true">
                        <div class="dc-core-ring"></div>
                        <div class="dc-core-ring dc-core-ring-two"></div>
                        <div class="dc-core-grid"></div>
                        <div class="dc-robot-viewport">
                            <img
                                :src="'/brand/shoegpt-robot-armor.webp'"
                                alt=""
                            />
                        </div>
                        <div class="dc-eye-flare"></div>
                        <span class="dc-orbit-label dc-orbit-label-left"
                            >AI</span
                        ><span class="dc-orbit-label dc-orbit-label-right"
                            >3.8</span
                        >
                    </div>
                    <div class="dc-core-title">
                        <i
                            class="fa-solid fa-microchip-ai"
                            aria-hidden="true"
                        ></i
                        >Agent network <span>{{ agents.length }}</span>
                    </div>
                    <div v-if="!agents.length" class="dc-muted">
                        Awaiting agent health.
                    </div>
                    <div class="dc-agent-grid">
                        <div
                            v-for="agent in agents"
                            :key="agent.name"
                            class="dc-agent"
                            :data-state="
                                agent.age === null
                                    ? 'unknown'
                                    : agent.age < 600
                                      ? 'ok'
                                      : 'warning'
                            "
                        >
                            <span class="dc-agent-led" aria-hidden="true"></span
                            ><strong>{{ agent.name }}</strong
                            ><span>{{ heartbeatText(agent.age) }}</span>
                        </div>
                    </div>
                    <div class="dc-cycle-status">
                        <span>Last cycle</span
                        ><strong>{{
                            status?.last_run
                                ? "#" +
                                  status.last_run.id +
                                  " · " +
                                  status.last_run.status
                                : "No cycle recorded"
                        }}</strong
                        ><span v-if="status?.last_run"
                            >{{ fmt.ago(status.last_run.started_at) }} ago</span
                        >
                    </div>
                </aside>
            </div>
            <div class="dc-snapshot-rail" :class="{ 'is-stale': stale }">
                <span class="dc-snapshot-indicator"></span
                ><strong>{{
                    stale
                        ? "Snapshot needs attention"
                        : loading
                          ? "Acquiring snapshot"
                          : age === null
                            ? "Awaiting snapshot"
                            : "Snapshot synchronized"
                }}</strong
                ><span>{{ age === null ? "—" : age + "s ago" }}</span
                ><span class="dc-rail-note"
                    >Account + positions refresh every 10s</span
                >
                <div
                    class="dc-countdown"
                    :style="{
                        '--progress':
                            (age === null ? 0 : Math.min(100, age * 10)) + '%',
                    }"
                    aria-hidden="true"
                ></div>
            </div>
        </section>
        <section class="dc-metrics" aria-label="Account metrics">
            <article
                v-for="(metric, index) in metrics"
                :key="metric.label"
                class="dc-metric dc-panel"
                :class="{ 'is-negative': metric.pnl && metric.value < 0 }"
                :style="{ '--i': index }"
                @pointermove="tilt"
                @pointerleave="untilt"
            >
                <div class="dc-metric-top">
                    <span>{{ metric.label }}</span
                    ><i
                        :class="['fa-solid', metric.icon]"
                        aria-hidden="true"
                    ></i>
                </div>
                <ReactiveValue :value="metric.value" :active="active" />
                <p>{{ metric.detail }}</p>
                <div
                    :key="pulse"
                    class="dc-metric-sweep"
                    aria-hidden="true"
                ></div>
                <span class="dc-metric-corner" aria-hidden="true"></span>
            </article>
        </section>
        <section
            class="dc-panel dc-positions"
            aria-labelledby="dc-positions-title"
        >
            <div class="dc-section-heading">
                <div>
                    <span class="dc-kicker">Position spectrum</span>
                    <h2 id="dc-positions-title">
                        Capital in motion<span class="dc-count">{{
                            loaded.positions ? positions.length : "—"
                        }}</span>
                    </h2>
                </div>
                <router-link to="/positions" class="dc-text-button"
                    >All positions
                    <i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i
                ></router-link>
            </div>
            <p v-if="errors.positions" class="dc-error" role="status">
                Positions unavailable: {{ errors.positions
                }}<span v-if="loaded.positions">
                    · Showing last successful snapshot.</span
                >
            </p>
            <p v-if="partial" class="dc-error">
                Showing {{ positions.length }} of
                {{ status.open_positions }} open positions. Total unrealised
                P&amp;L is unavailable until every position is loaded.
            </p>
            <div class="dc-position-analysis">
                <div>
                    <div class="dc-chart-label">
                        Unrealised P&amp;L · USD
                        <span>{{
                            positions.length > 12
                                ? "12 largest absolute values"
                                : "Current position marks"
                        }}</span>
                    </div>
                    <PositionSpectrum
                        :loading="!loaded.positions"
                        :error="errors.positions"
                        :positions="positions"
                        :selected="selected"
                        :active="active"
                        @select="selectPosition"
                    />
                </div>
                <div class="dc-position-focus">
                    <i class="fa-solid fa-crosshairs" aria-hidden="true"></i
                    ><span class="dc-kicker">{{
                        selectedPosition
                            ? "Position in focus"
                            : "Interactive positions"
                    }}</span
                    ><template v-if="selectedPosition"
                        ><strong>{{ selectedPosition.product_id }}</strong
                        ><ReactiveValue
                            :value="
                                finiteNumber(selectedPosition.unrealised_pnl)
                            "
                            :active="active"
                        /><span
                            >Mark
                            {{ fmt.px(selectedPosition.mark_price) }}</span
                        ><router-link
                            :to="'/chart/' + selectedPosition.product_id"
                            >Open chart ↗</router-link
                        ><button
                            class="dc-text-button"
                            @click="selected = null"
                        >
                            Clear focus
                        </button></template
                    ><template v-else
                        ><strong>Follow the signal.</strong>
                        <p>
                            Select a bar or coin below to isolate its position
                            and inspect the current mark.
                        </p>
                        <span>{{
                            loaded.positions
                                ? positions.length + " positions observed"
                                : "Awaiting positions"
                        }}</span></template
                    >
                </div>
            </div>
            <div class="dc-position-toolbar">
                <label class="dc-position-search"
                    ><i
                        class="fa-solid fa-magnifying-glass"
                        aria-hidden="true"
                    ></i
                    ><input
                        v-model="query"
                        type="search"
                        placeholder="Find a position"
                        aria-label="Find a position" /></label
                ><button
                    class="dc-text-button"
                    :aria-expanded="showDetails"
                    aria-controls="dc-position-table"
                    @click="showDetails = !showDetails"
                >
                    {{ showDetails ? "Hide" : "Show" }} full position details
                    <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                </button>
            </div>
            <div class="dc-position-cards">
                <article
                    v-for="position in shownPositions"
                    :key="position.id"
                    class="dc-coin-card"
                    :class="{
                        'is-selected': selected === position.id,
                        'is-negative': position.unrealised_pnl < 0,
                    }"
                >
                    <button
                        class="dc-coin-select"
                        :aria-pressed="selected === position.id"
                        @click="selectPosition(position.id)"
                    >
                        <span class="dc-coin-icon"
                            ><img
                                v-if="pngUrlFor(position.product_id)"
                                :src="pngUrlFor(position.product_id)"
                                alt=""
                            /><span v-else>{{
                                position.product_id.split("-")[0]
                            }}</span></span
                        ><span
                            ><strong>{{ position.product_id }}</strong
                            ><small
                                >{{ position.side || "long" }} ·
                                {{ fmt.mins(position.held_minutes) }}</small
                            ></span
                        ><i
                            class="fa-solid fa-crosshairs"
                            aria-hidden="true"
                        ></i>
                    </button>
                    <div class="dc-coin-pnl">
                        <ReactiveValue
                            :value="finiteNumber(position.unrealised_pnl)"
                            :active="active"
                        /><span>{{
                            fmt.pct(position.unrealised_pnl_pct)
                        }}</span>
                    </div>
                    <div class="dc-coin-measures">
                        <span
                            >Mark<strong>{{
                                fmt.px(position.mark_price)
                            }}</strong></span
                        ><span
                            >Entry<strong>{{
                                fmt.px(position.entry_price)
                            }}</strong></span
                        >
                    </div>
                    <div class="dc-coin-footer">
                        <router-link :to="'/chart/' + position.product_id"
                            >Explore chart ↗</router-link
                        ><span>{{ position.adds_count }} adds</span>
                    </div>
                </article>
            </div>
            <p v-if="!shownPositions.length" class="dc-spectrum-empty">
                {{
                    !loaded.positions
                        ? "Loading positions…"
                        : query
                          ? "No positions match your search."
                          : "The desk is flat. Open positions will appear here."
                }}
            </p>
            <div
                v-show="showDetails"
                id="dc-position-table"
                class="smx-table-scroll dc-position-table"
            >
                <table class="grid">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Entry</th>
                            <th>Mark</th>
                            <th>Cost</th>
                            <th>Unrealised P&amp;L</th>
                            <th>Peak</th>
                            <th>Held</th>
                            <th>Adds</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="position in shownPositions"
                            :key="position.id"
                            :class="{
                                'dc-row-focus': selected === position.id,
                            }"
                        >
                            <td>
                                <router-link
                                    :to="'/chart/' + position.product_id"
                                    >{{ position.product_id }}</router-link
                                >
                            </td>
                            <td class="num">
                                {{ fmt.num(position.quantity, 6) }}
                            </td>
                            <td class="num">
                                {{ fmt.px(position.entry_price) }}
                            </td>
                            <td class="num">
                                {{ fmt.px(position.mark_price) }}
                            </td>
                            <td class="num">
                                {{ fmt.usd(position.entry_usd) }}
                            </td>
                            <td
                                class="num"
                                :class="{
                                    'dc-negative': position.unrealised_pnl < 0,
                                }"
                            >
                                {{ fmt.usd(position.unrealised_pnl)
                                }}<span class="dc-muted">
                                    {{
                                        fmt.pct(position.unrealised_pnl_pct)
                                    }}</span
                                >
                            </td>
                            <td class="num">
                                {{ fmt.px(position.peak_price) }}
                            </td>
                            <td class="num">
                                {{ fmt.mins(position.held_minutes) }}
                            </td>
                            <td class="num">{{ position.adds_count }}</td>
                            <td>
                                <button
                                    class="btn btn-danger"
                                    :disabled="publicDemo || !!busy"
                                    @click="closePos(position)"
                                >
                                    {{
                                        busy === "close-" + position.id
                                            ? "Closing…"
                                            : "Close"
                                    }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="dc-panel dc-desk-controls" aria-label="Desk controls">
            <div>
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i
                ><span
                    ><strong>Execution controls</strong
                    ><small
                        >{{
                            health?.mode
                                ? health.mode + " execution mode"
                                : "Execution status unavailable"
                        }}
                        · {{ deskLabel }}</small
                    ></span
                >
            </div>
            <div class="dc-action-buttons">
                <button
                    class="btn btn-primary"
                    :disabled="publicDemo || !!busy"
                    @click="act('cycle')"
                >
                    <i class="fa-solid fa-play" aria-hidden="true"></i
                    >{{
                        busy === "cycle" ? "Running cycle…" : "Run cycle now"
                    }}</button
                ><button class="btn" :disabled="publicDemo || !!busy" @click="act('risk')">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i
                    >{{ busy === "risk" ? "Sweeping…" : "Risk sweep" }}</button
                ><button
                    v-if="health?.halted"
                    class="btn btn-ok"
                    :disabled="publicDemo || !!busy"
                    @click="act('resume')"
                >
                    Resume desk</button
                ><button
                    v-else-if="health"
                    class="btn btn-danger"
                    :disabled="publicDemo || !!busy"
                    @click="act('halt')"
                >
                    Halt desk
                </button>
            </div>
        </section>
        <section
            class="dc-panel dc-cycle-panel"
            aria-labelledby="dc-cycle-title"
        >
            <div class="dc-section-heading">
                <div>
                    <span class="dc-kicker">Decision pipeline</span>
                    <h2 id="dc-cycle-title">
                        Latest cycle
                        <span v-if="latest" class="dc-count"
                            >#{{ latest.id }}</span
                        >
                    </h2>
                </div>
                <router-link
                    :to="latest ? '/desk/' + latest.id : '/desk'"
                    class="dc-text-button"
                    >Open desk ↗</router-link
                >
            </div>
            <p v-if="errors.latest" class="dc-error" role="status">
                Latest cycle unavailable: {{ errors.latest }}
            </p>
            <template v-if="latest"
                ><div class="dc-pipeline">
                    <div
                        v-for="(stage, i) in [
                            {
                                label: 'Scanned',
                                value: latest.products_scanned,
                                icon: 'fa-satellite-dish',
                            },
                            {
                                label: 'Passed',
                                value: latest.passed,
                                icon: 'fa-circle-check',
                            },
                            {
                                label: 'Rejected',
                                value: latest.rejected,
                                icon: 'fa-shield-halved',
                            },
                            {
                                label: 'Filled',
                                value: latest.filled,
                                icon: 'fa-bolt',
                            },
                        ]"
                        :key="stage.label"
                        :style="{ '--i': i }"
                    >
                        <i
                            :class="['fa-solid', stage.icon]"
                            aria-hidden="true"
                        ></i
                        ><span
                            >{{ stage.label
                            }}<strong>{{
                                finiteNumber(stage.value) ?? "—"
                            }}</strong></span
                        >
                        <div class="dc-pipeline-beam" aria-hidden="true"></div>
                    </div>
                </div>
                <div class="dc-cycle-meta">
                    <span
                        >{{ fmt.time(latest.started_at) }} ·
                        {{ latest.status }}</span
                    ><strong v-if="latest.degraded" class="dc-warning"
                        >Degraded cycle</strong
                    >
                </div>
                <p v-if="latest.error" class="dc-error">{{ latest.error }}</p>
                <details class="dc-candidate-details">
                    <summary>
                        Candidate decisions
                        <span>{{
                            candidates
                                ? candidates.length + " candidates"
                                : "Details"
                        }}</span>
                    </summary>
                    <CandidateTable
                        v-if="candidates"
                        :candidates="candidates"
                    />
                    <p v-else class="dc-muted">
                        Candidate details are unavailable in this snapshot. Open
                        the desk to inspect this cycle.
                    </p>
                </details></template
            >
            <p v-else-if="!errors.latest" class="dc-spectrum-empty">
                {{
                    loaded.latest
                        ? "No cycle recorded yet."
                        : "Acquiring the latest cycle…"
                }}
            </p>
        </section>
        <section class="dc-panel dc-feed-panel" aria-labelledby="dc-feed-title">
            <div class="dc-section-heading">
                <div>
                    <span class="dc-kicker">Live event telemetry</span>
                    <h2 id="dc-feed-title">The signal stream.</h2>
                </div>
                <i
                    class="fa-solid fa-wave-pulse dc-section-icon"
                    aria-hidden="true"
                ></i>
            </div>
            <SignalFeed :active="active" />
        </section>
    </div>
</template>
