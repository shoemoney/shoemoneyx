<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import { api, fmt } from "../../api";
const props = defineProps({ active: Boolean });
const events = ref([]),
    error = ref(""),
    loaded = ref(false),
    filter = ref("all"),
    expanded = ref(false);
const fresh = ref(new Set());
let timer,
    disposed = false,
    pending = false;
const filtered = computed(() =>
    events.value.filter(
        (event) =>
            filter.value === "all" ||
            (filter.value === "trades"
                ? event.level === "trade"
                : ["error", "warn"].includes(event.level)),
    ),
);
const shown = computed(() => filtered.value.slice(0, expanded.value ? 40 : 8));
async function load() {
    if (pending || disposed || document.hidden) return;
    pending = true;
    try {
        const rows = await api.get("/events?limit=40");
        if (disposed) return;
        if (!Array.isArray(rows))
            throw new Error("Event response unavailable.");
        const known = new Set(events.value.map((event) => event.id));
        fresh.value = new Set(
            loaded.value
                ? rows
                      .filter((event) => !known.has(event.id))
                      .map((event) => event.id)
                : [],
        );
        events.value = rows;
        loaded.value = true;
        error.value = "";
    } catch (failure) {
        if (!disposed) error.value = failure.message;
    } finally {
        pending = false;
    }
}
function visible() {
    if (!document.hidden) load();
}
onMounted(() => {
    load();
    timer = setInterval(load, 5000);
    document.addEventListener("visibilitychange", visible);
});
onUnmounted(() => {
    disposed = true;
    clearInterval(timer);
    document.removeEventListener("visibilitychange", visible);
});
</script>
<template>
    <div class="dc-signal-feed">
        <div class="dc-chart-toolbar">
            <div class="dc-segments" aria-label="Filter events">
                <button
                    v-for="item in ['all', 'trades', 'alerts']"
                    :key="item"
                    :aria-pressed="filter === item"
                    @click="filter = item"
                >
                    {{ item }}
                </button>
            </div>
            <span class="dc-chart-hint">Refreshes every 5s</span>
        </div>
        <p v-if="error" class="dc-error" role="status">
            Events unavailable: {{ error
            }}<span v-if="loaded">
                · Showing the last successful snapshot.</span
            >
        </p>
        <TransitionGroup
            name="dc-feed"
            tag="div"
            :css="active"
            class="dc-signal-rows"
        >
            <div
                v-for="event in shown"
                :key="event.id"
                class="dc-signal-row"
                :class="{ 'is-fresh': fresh.has(event.id) }"
                :data-level="event.level"
            >
                <span class="dc-signal-dot" aria-hidden="true"></span
                ><time :datetime="event.created_at">{{
                    fmt.ago(event.created_at)
                }}</time
                ><strong>{{ event.agent }}</strong
                ><span
                    :title="
                        event.payload
                            ? JSON.stringify(event.payload, null, 1)
                            : ''
                    "
                    >{{ event.message }}</span
                >
            </div>
        </TransitionGroup>
        <p v-if="!shown.length && !error" class="dc-spectrum-empty">
            {{
                !loaded
                    ? "Acquiring event stream…"
                    : filter === "all"
                      ? "No recent events."
                      : `No ${filter} in the latest 40 events.`
            }}
        </p>
        <button
            v-if="filtered.length > 8"
            class="dc-text-button"
            @click="expanded = !expanded"
        >
            {{
                expanded
                    ? "Show latest 8"
                    : `Show all ${filtered.length} events`
            }}
            <span aria-hidden="true">↗</span>
        </button>
    </div>
</template>
