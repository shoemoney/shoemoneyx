<script setup>
import { publicDemo } from "../demoMode";
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from "vue";
import { useRoute } from "vue-router";
import { useLiveDesk } from "../landing/useLiveDesk";
import {
    cash,
    signedCash,
    compact,
    clockTime,
    percent,
    price,
    ticker,
} from "../landing/format";
import { pngUrlFor } from "../coinIcons";
import Sparkline from "../landing/Sparkline.vue";
import PairCard from "../landing/PairCard.vue";
import AiCore from "../landing/AiCore.vue";
import OriginalAtmosphere from "../landing/OriginalAtmosphere.vue";
import OriginalInstrument from "../landing/OriginalInstrument.vue";
import BrandInsignia from "../landing/BrandInsignia.vue";
import "../../css/landing.css";

const route = useRoute();
const variants = [
    {
        id: "pulse",
        number: "01",
        title: "Pulse",
        icon: "wave-pulse",
        desc: "Signal. Speed. Conviction.",
    },
    {
        id: "pulse-blue",
        number: "16",
        family: "pulse",
        title: "Pulse Blue",
        icon: "wave-pulse",
        desc: "ShoeMoney intelligence. In full signal.",
    },
    {
        id: "neural",
        number: "02",
        title: "Neural",
        icon: "circle-nodes",
        desc: "One intelligence. Every connection.",
    },
    {
        id: "terminal",
        number: "03",
        title: "Terminal",
        icon: "terminal",
        desc: "An unfair amount of information.",
    },
    {
        id: "orbit",
        number: "04",
        title: "Orbit",
        icon: "globe",
        desc: "The entire market in your orbit.",
    },
    {
        id: "prism",
        number: "05",
        title: "Prism",
        icon: "grid-2",
        desc: "See the signal in everything.",
    },
];
const version = computed(
    () => variants.find((v) => v.id === route.params.version) || variants[0],
);
const designFamily = computed(() => version.value.family || version.value.id);
const brandCaption = computed(
    () =>
        ({
            pulse: "SIGNAL ORIGIN",
            "pulse-blue": "THE SIGNAL ORIGIN",
            terminal: "COMMAND SIGNATURE",
            prism: "INTELLIGENCE, IN COLOR",
        })[version.value.id] || "SHOEMONEY INTELLIGENCE",
);
// Changing presentation never restarts the subscription. Demo is explicit and reloads when toggled.
const demo = publicDemo || new URLSearchParams(location.search).get("demo") === "1";
const {
    snapshot,
    pairs,
    events,
    error,
    connection,
    now,
    rate,
    totalMessages,
    stale,
    statusText,
    pnlHistory,
    motion,
    feedPaused,
    selected,
    selectedPair,
    toggleFeed,
    refresh,
} = useLiveDesk(demo);
const query = ref(""),
    filter = ref("all"),
    eventFilter = ref("all");
const detail = ref(null),
    closeButton = ref(null);
let previousFocus;
const metrics = computed(() => snapshot.value?.metrics || {});
const highlightedPair = ref("");
const markDelta = ref(null);
const latestEvent = computed(() => events.value[0]);
const latestAgentEvents = computed(() =>
    Object.fromEntries(
        agents.map((agent) => [
            agent.name,
            events.value.find((event) => event.agent === agent.name),
        ]),
    ),
);
watch(
    () => metrics.value.pnl,
    (value, previous) => {
        markDelta.value =
            Number.isFinite(value) && Number.isFinite(previous)
                ? value - previous
                : null;
    },
);
const visiblePairs = computed(() =>
    pairs.value.filter(
        (p) =>
            p.id.toLowerCase().includes(query.value.toLowerCase()) &&
            (filter.value === "all" ||
                (filter.value === "open" && p.open > 0) ||
                (filter.value === "gaining" && p.pnl > 0)),
    ),
);
const visibleEvents = computed(() =>
    events.value
        .filter(
            (e) =>
                eventFilter.value === "all" ||
                (eventFilter.value === "ai"
                    ? e.agent === "AI"
                    : e.kind === "desk"),
        )
        .slice(0, 12),
);
const agents = [
    { name: "SCAN", icon: "crosshairs", label: "Find the move" },
    { name: "VET", icon: "shield-halved", label: "Validate the signal" },
    { name: "SIZE", icon: "chart-scatter", label: "Allocate the capital" },
    { name: "FILLS", icon: "bolt", label: "Execute the trade" },
    { name: "RISK", icon: "wave-pulse", label: "Protect the position" },
];
const bigPnl = computed(() => signedCash(metrics.value.pnl).split("."));
const longCount = computed(
    () => pairs.value.filter((p) => p.side === "long").length,
);
const shortCount = computed(
    () => pairs.value.filter((p) => p.side === "short").length,
);
const linkTo = (id) => ({
    path: `/landing/${id}`,
    query: demo ? { demo: "1" } : {},
});
const orbitStyle = (index) => {
    const angle =
        (index / Math.max(visiblePairs.value.length, 1)) * Math.PI * 2 -
        Math.PI / 2;
    return {
        left: `${50 + Math.cos(angle) * 38}%`,
        top: `${50 + Math.sin(angle) * 39}%`,
        "--order": index,
    };
};
const orbitPoint = (index) => {
    const angle =
        (index / Math.max(visiblePairs.value.length, 1)) * Math.PI * 2 -
        Math.PI / 2;
    return [500 + Math.cos(angle) * 380, 310 + Math.sin(angle) * 242];
};
function selectPair(id) {
    previousFocus = document.activeElement;
    selected.value = id;
}
watch(selected, async (value) => {
    await nextTick();
    if (value) {
        detail.value?.showModal();
        closeButton.value?.focus();
    } else {
        detail.value?.close();
        previousFocus?.focus();
    }
});
watch(
    () => version.value.id,
    () => {
        document.title = `${version.value.title} · ShoeMoneyX`;
        selected.value = null;
        highlightedPair.value = "";
        query.value = "";
        filter.value = "all";
    },
    { immediate: true },
);
onMounted(() => {
    document.title = `${version.value.title} · ShoeMoneyX`;
});
onUnmounted(() => {
    detail.value?.close();
    document.title = "ShoeMoneyX — desk";
});
</script>

<template>
    <div
        class="landing"
        :class="[
            `theme-${designFamily}`,
            `theme-${version.id}`,
            { 'motion-off': !motion, 'is-stale': stale },
        ]"
    >
        <a class="skip-link" href="#markets">Skip to trading pairs</a>
        <div class="design-bar">
            <router-link to="/designs" class="design-label">← ALL DESIGNS</router-link>
            <nav class="design-options" aria-label="Landing page designs">
                <router-link
                    v-for="v in variants"
                    :key="v.id"
                    :to="linkTo(v.id)"
                    :class="{ chosen: version.id === v.id }"
                    :aria-current="version.id === v.id ? 'page' : undefined"
                    ><span>{{ v.number }}</span
                    >{{ v.title }}</router-link
                >
                <router-link :to="linkTo('supernova')" class="more-designs"
                    >06—15 <span>Data explosion →</span></router-link
                >
            </nav>
            <span class="design-edition">{{
                demo ? "SIMULATED PREVIEW" : "LIVE DESK"
            }}</span>
        </div>
        <header class="landing-header">
            <router-link
                :to="linkTo(version.id)"
                class="brand"
                aria-label="ShoeMoneyX home"
                ><BrandInsignia
                    :theme="version.id"
                    placement="masthead"
                    :motion="motion"
                    :pulse="totalMessages"
                    class="signature-masthead"
                /><span
                    >ShoeMoney<b>X</b
                    ><small>AUTONOMOUS TRADING DESK</small></span
                ></router-link
            >
            <nav class="main-nav" aria-label="Application">
                <a href="#markets" class="active">Overview</a
                ><router-link to="/optimizer">Intelligence</router-link
                ><router-link to="/arena">Arena</router-link>
            </nav>
            <div class="header-right">
                <span class="mode-label">{{
                    demo
                        ? "DEMO"
                        : (snapshot?.mode || "CONNECTING").toUpperCase()
                }}</span
                ><router-link to="/dashboard" class="open-desk"
                    >Open desk
                    <i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i
                ></router-link>
            </div>
        </header>
        <div class="market-ticker" aria-label="Market price ticker">
            <div class="ticker-label">
                <span class="signal-dot"></span
                >{{ demo ? "DEMO TAPE" : "MARKET TAPE" }}
            </div>
            <div class="ticker-window">
                <div class="ticker-track">
                    <template v-for="copy in 2" :key="copy"
                        ><span
                            v-for="p in pairs"
                            :key="`${copy}-${p.id}`"
                            class="ticker-item"
                            :aria-hidden="copy === 2 ? true : undefined"
                            ><b>{{ ticker(p.id) }}</b
                            ><span>{{ price(p.price) }}</span
                            ><em :class="p.change < 0 ? 'down' : 'up'">{{
                                percent(p.change)
                            }}</em></span
                        ></template
                    ><span v-if="!pairs.length" class="ticker-item"
                        >Waiting for market data</span
                    >
                </div>
            </div>
        </div>

        <main class="landing-main">
            <div class="connection-row">
                <span
                    :class="{
                        'connection-warning': stale || !snapshot?.feed_alive,
                    }"
                    ><span class="signal-dot"></span>{{ statusText }}</span
                ><span>{{
                    demo
                        ? "Example figures · not actual performance"
                        : "Prices + P&L sync every 3 seconds"
                }}</span
                ><button
                    @click="motion = !motion"
                    class="motion-button"
                    :aria-pressed="!motion"
                >
                    <i
                        :class="`fa-solid fa-${motion ? 'pause' : 'play'}`"
                        aria-hidden="true"
                    ></i
                    >{{ motion ? "Pause motion" : "Resume motion" }}</button
                ><time>{{ clockTime(now) }} LOCAL</time>
            </div>
            <div v-if="error" class="data-warning" role="status">
                <i
                    class="fa-solid fa-triangle-exclamation"
                    aria-hidden="true"
                ></i
                ><span>{{ error }} Last received values remain visible.</span
                ><button @click="refresh">Retry</button>
            </div>
            <div v-if="snapshot?.halted" class="data-warning" role="status">
                DESK HALTED · {{ snapshot.halted.reason }}
            </div>

            <section
                class="hero"
                :aria-label="`${version.title} trading overview`"
            >
                <OriginalAtmosphere
                    :theme="version.id"
                    :pairs="pairs"
                    :event="latestEvent"
                    :motion="motion"
                    :focus="highlightedPair"
                />
                <div class="hero-copy">
                    <div
                        v-if="version.id !== 'neural' && version.id !== 'orbit'"
                        class="signature-hero-brand"
                        aria-hidden="true"
                    >
                        <BrandInsignia
                            :theme="version.id"
                            placement="hero"
                            :motion="motion"
                            :pulse="totalMessages"
                        />
                        <span class="signature-brand-caption">{{
                            brandCaption
                        }}</span>
                    </div>
                    <div class="eyebrow">
                        <span class="section-index"
                            >{{ version.number }} /</span
                        >
                        {{ version.desc }}
                    </div>
                    <h1 v-if="designFamily === 'pulse'">
                        Every pair.<br /><span>One intelligence.</span>
                    </h1>
                    <h1 v-else-if="version.id === 'neural'">
                        Connected.<br /><span>By intelligence.</span>
                    </h1>
                    <h1 v-else-if="version.id === 'terminal'">
                        THE EDGE<br /><span
                            >IS ALWAYS ON<span class="cursor">_</span></span
                        >
                    </h1>
                    <h1 v-else-if="version.id === 'orbit'">
                        A universe of signals.<br /><span
                            >One center of gravity.</span
                        >
                    </h1>
                    <h1 v-else>
                        Intelligence<br /><span>in full color.</span>
                    </h1>
                    <p>
                        Every signal. Every position. Every move. Your entire
                        AI-guided desk, happening now.
                    </p>
                    <div class="hero-pills">
                        <span
                            ><i
                                class="fa-solid fa-circle-nodes"
                                aria-hidden="true"
                            ></i
                            >{{ pairs.length }} connected pairs</span
                        ><span
                            ><i
                                class="fa-solid fa-microchip-ai"
                                aria-hidden="true"
                            ></i
                            >{{
                                (snapshot?.strategy || "AI").toUpperCase()
                            }}
                            strategy</span
                        ><span
                            class="desk-running"
                            :class="{
                                inactive:
                                    !snapshot?.running || snapshot?.halted,
                            }"
                            ><span class="signal-dot"></span
                            >{{
                                snapshot?.halted
                                    ? "Desk halted"
                                    : snapshot?.running
                                      ? "Desk running"
                                      : "Desk standby"
                            }}</span
                        >
                    </div>
                </div>
                <div
                    class="pnl-stage"
                    :class="{
                        negative: metrics.pnl < 0,
                        'large-total': bigPnl[0].length > 10,
                    }"
                >
                    <span
                        :key="metrics.pnl"
                        class="pxo-pnl-impact"
                        aria-hidden="true"
                        ><i></i><i></i><i></i
                    ></span>
                    <div class="pnl-top">
                        <span
                            >TRADING P&L
                            <small>ALL TIME · BEFORE FEES</small></span
                        ><i
                            :class="`fa-solid fa-arrow-trend-${metrics.pnl < 0 ? 'down' : 'up'}`"
                            aria-hidden="true"
                        ></i>
                    </div>
                    <div
                        class="pnl-amount"
                        :class="metrics.pnl < 0 ? 'down' : 'up'"
                    >
                        <span :key="metrics.pnl" class="pxo-money-tick">{{
                            bigPnl[0]
                        }}</span
                        ><span class="pnl-cents">{{
                            bigPnl[1] ? "." + bigPnl[1] : ""
                        }}</span>
                    </div>
                    <div class="pnl-breakdown">
                        <span
                            >Realised
                            <b>{{ signedCash(metrics.realised) }}</b></span
                        ><span
                            >Open
                            <b>{{ signedCash(metrics.unrealised) }}</b></span
                        >
                    </div>
                    <div
                        class="pxo-mark-delta"
                        :class="markDelta < 0 ? 'down' : 'up'"
                    >
                        <span class="pxo-delta-glyph" aria-hidden="true">{{
                            version.id === "terminal"
                                ? "›_"
                                : version.id === "orbit"
                                  ? "◌"
                                  : version.id === "prism"
                                    ? "◇"
                                    : "↗"
                        }}</span
                        ><span :key="metrics.pnl">{{
                            markDelta == null
                                ? "AWAITING NEXT MARK"
                                : `${signedCash(markDelta)} LAST MARK Δ`
                        }}</span>
                    </div>
                    <Sparkline :values="pnlHistory" area class="pnl-chart" />
                    <div class="chart-axis">
                        <span>{{
                            demo
                                ? "ILLUSTRATIVE PERFORMANCE"
                                : "OBSERVED THIS SESSION"
                        }}</span
                        ><span
                            ><span class="signal-dot"></span
                            >{{ stale ? "DELAYED" : "NOW" }}</span
                        >
                    </div>
                </div>
            </section>

            <div class="stats-strip">
                <div>
                    <span
                        ><i
                            class="fa-solid fa-chart-scatter"
                            aria-hidden="true"
                        ></i
                        >OPEN POSITIONS</span
                    ><strong
                        >{{ metrics.open ?? "—"
                        }}<small
                            >{{ longCount }} L / {{ shortCount }} S</small
                        ></strong
                    >
                </div>
                <div>
                    <span
                        ><i class="fa-solid fa-trophy" aria-hidden="true"></i
                        >CLOSED WIN RATE</span
                    ><strong
                        >{{
                            metrics.win_rate == null
                                ? "—"
                                : metrics.win_rate.toFixed(1) + "%"
                        }}<small
                            >{{ compact(metrics.trades) }} closed trades</small
                        ></strong
                    >
                </div>
                <div>
                    <span
                        ><i
                            class="fa-solid fa-circle-nodes"
                            aria-hidden="true"
                        ></i
                        >OPEN NOTIONAL</span
                    ><strong
                        >{{ cash(metrics.notional, 0)
                        }}<small>Current exposure</small></strong
                    >
                </div>
                <div>
                    <span
                        ><i
                            class="fa-solid fa-satellite-dish"
                            aria-hidden="true"
                        ></i
                        >AI FIREHOSE</span
                    ><strong
                        >{{ rate.toFixed(1)
                        }}<small
                            >events / sec ·
                            {{ demo ? "simulated" : connection }}</small
                        ></strong
                    >
                </div>
            </div>

            <OriginalInstrument
                :theme="designFamily"
                :pairs="pairs"
                :events="events"
                :focus="highlightedPair"
                :motion="motion"
                @select="selectPair"
                @highlight="highlightedPair = $event"
            />

            <section
                class="intelligence-strip"
                aria-label="AI trading pipeline"
            >
                <div
                    class="intelligence-label"
                    :class="{ 'pxo-ai-receiving': latestEvent?.agent === 'AI' }"
                >
                    <i class="fa-solid fa-brain-circuit" aria-hidden="true"></i
                    ><span
                        >{{
                            (snapshot?.strategy || "AI").toUpperCase()
                        }}
                        INTELLIGENCE<small>THE DECISION PIPELINE</small></span
                    >
                </div>
                <div class="pipeline-stages">
                    <div
                        v-for="(agent, i) in agents"
                        :key="agent.name"
                        class="pipeline-stage"
                        :class="{
                            'agent-active':
                                snapshot?.heartbeats?.[agent.name] != null &&
                                snapshot.heartbeats[agent.name] < 120,
                            'pxo-transmitting':
                                latestEvent?.agent === agent.name,
                        }"
                    >
                        <span
                            :key="latestAgentEvents[agent.name]?.key"
                            class="pxo-agent-relay"
                            aria-hidden="true"
                        ></span>
                        <span class="agent-icon"
                            ><i
                                :class="`fa-solid fa-${agent.icon}`"
                                aria-hidden="true"
                            ></i
                        ></span>
                        <div>
                            <b>{{ agent.name }}</b
                            ><small>{{ agent.label }}</small>
                        </div>
                        <i
                            v-if="i < agents.length - 1"
                            class="fa-solid fa-chevron-right stage-arrow"
                            aria-hidden="true"
                        ></i>
                    </div>
                </div>
                <div class="pxo-pipeline-receipt">
                    <span
                        :key="latestEvent?.key"
                        class="pxo-receipt-light"
                        aria-hidden="true"
                    ></span
                    ><b>{{ latestEvent?.agent || "AI" }} <span>→</span></b
                    ><span>{{
                        latestEvent?.message ||
                        "Listening for the next decision"
                    }}</span
                    ><time v-if="latestEvent">{{
                        clockTime(latestEvent.at)
                    }}</time>
                </div>
            </section>

            <section id="markets" class="markets-section">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">{{
                            version.id === "terminal"
                                ? "// MARKET MATRIX"
                                : "THE ENTIRE PLAYING FIELD"
                        }}</span>
                        <h2>
                            {{
                                version.id === "neural"
                                    ? "The intelligence network"
                                    : version.id === "orbit"
                                      ? "Everything in orbit"
                                      : version.id === "prism"
                                        ? "A market of possibilities."
                                        : "Every pair. In play."
                            }}<span>{{
                                visiblePairs.length.toString().padStart(2, "0")
                            }}</span>
                        </h2>
                    </div>
                    <div class="market-tools">
                        <div
                            class="filter-tabs"
                            aria-label="Filter trading pairs"
                        >
                            <button
                                v-for="f in [
                                    { id: 'all', label: 'All pairs' },
                                    { id: 'open', label: 'Open' },
                                    { id: 'gaining', label: 'Gaining' },
                                ]"
                                :key="f.id"
                                @click="filter = f.id"
                                :class="{ active: filter === f.id }"
                                :aria-pressed="filter === f.id"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                        <label class="pair-search"
                            ><i
                                class="fa-solid fa-magnifying-glass"
                                aria-hidden="true"
                            ></i
                            ><input
                                v-model="query"
                                placeholder="Find a pair"
                                aria-label="Find a trading pair"
                        /></label>
                    </div>
                </div>

                <div
                    v-if="version.id === 'neural'"
                    class="neural-network"
                    :class="{ 'pxo-network-focused': !!highlightedPair }"
                    :style="{
                        '--network-rows': Math.ceil(visiblePairs.length / 2),
                    }"
                >
                    <svg
                        class="neural-wires"
                        viewBox="0 0 1000 600"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <template v-for="(p, i) in visiblePairs" :key="p.id">
                            <path
                                :class="{
                                    'pxo-wire-focused':
                                        highlightedPair === p.id,
                                }"
                                :d="`M500 300 C${i % 2 ? 690 : 310} 300, ${i % 2 ? 600 : 400} ${((Math.floor(i / 2) + 0.5) / Math.ceil(visiblePairs.length / 2)) * 600}, ${i % 2 ? 820 : 180} ${((Math.floor(i / 2) + 0.5) / Math.ceil(visiblePairs.length / 2)) * 600}`"
                            />
                            <path
                                class="wire-particle"
                                :class="{
                                    'pxo-wire-focused':
                                        highlightedPair === p.id,
                                }"
                                :style="{ animationDelay: `${i * -0.28}s` }"
                                :d="`M500 300 C${i % 2 ? 690 : 310} 300, ${i % 2 ? 600 : 400} ${((Math.floor(i / 2) + 0.5) / Math.ceil(visiblePairs.length / 2)) * 600}, ${i % 2 ? 820 : 180} ${((Math.floor(i / 2) + 0.5) / Math.ceil(visiblePairs.length / 2)) * 600}`"
                            />
                        </template>
                    </svg>
                    <AiCore
                        :strategy="snapshot?.strategy"
                        :running="snapshot?.running"
                        :halted="!!snapshot?.halted"
                        :count="pairs.length"
                        :activity="latestEvent?.key"
                        :focus="highlightedPair"
                    />
                    <BrandInsignia
                        :theme="version.id"
                        placement="core"
                        :motion="motion"
                        :pulse="totalMessages"
                        class="signature-core-seal"
                    />
                    <PairCard
                        v-for="(p, i) in visiblePairs"
                        :key="p.id"
                        :pair="p"
                        :index="i"
                        :focused="highlightedPair === p.id"
                        :motion="motion"
                        :style="{
                            gridColumn: i % 2 ? 3 : 1,
                            gridRow: Math.floor(i / 2) + 1,
                        }"
                        @select="selectPair"
                        @highlight="highlightedPair = $event"
                    />
                </div>

                <div
                    v-else-if="version.id === 'orbit'"
                    class="orbital-system"
                    :class="{
                        'many-pairs': visiblePairs.length > 12,
                        'pxo-network-focused': !!highlightedPair,
                    }"
                >
                    <div class="orbit-grid" aria-hidden="true">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <svg
                        class="orbital-wires"
                        viewBox="0 0 1000 620"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <template v-for="(p, i) in visiblePairs" :key="p.id">
                            <line
                                :class="{
                                    'pxo-wire-focused':
                                        highlightedPair === p.id,
                                }"
                                x1="500"
                                y1="310"
                                :x2="orbitPoint(i)[0]"
                                :y2="orbitPoint(i)[1]"
                            />
                            <line
                                class="wire-particle"
                                :class="{
                                    'pxo-wire-focused':
                                        highlightedPair === p.id,
                                }"
                                :style="{ animationDelay: `${i * -0.38}s` }"
                                x1="500"
                                y1="310"
                                :x2="orbitPoint(i)[0]"
                                :y2="orbitPoint(i)[1]"
                            />
                        </template>
                    </svg>
                    <AiCore
                        :strategy="snapshot?.strategy"
                        :running="snapshot?.running"
                        :halted="!!snapshot?.halted"
                        :count="pairs.length"
                        :activity="latestEvent?.key"
                        :focus="highlightedPair"
                    />
                    <BrandInsignia
                        :theme="version.id"
                        placement="core"
                        :motion="motion"
                        :pulse="totalMessages"
                        class="signature-core-seal"
                    />
                    <button
                        v-for="(p, i) in visiblePairs"
                        :key="p.id"
                        class="orbit-pair"
                        :class="{ 'pxo-focused': highlightedPair === p.id }"
                        :style="orbitStyle(i)"
                        @click="selectPair(p.id)"
                        @pointerenter="highlightedPair = p.id"
                        @pointerleave="highlightedPair = ''"
                        @focus="highlightedPair = p.id"
                        @blur="highlightedPair = ''"
                    >
                        <span class="orbit-coin"
                            ><img
                                v-if="pngUrlFor(p.id)"
                                :src="pngUrlFor(p.id)"
                                alt=""
                            /><span v-else>{{ ticker(p.id) }}</span></span
                        ><b>{{ ticker(p.id) }}<small>/ USD</small></b
                        ><strong :class="p.pnl < 0 ? 'down' : 'up'">{{
                            signedCash(p.pnl)
                        }}</strong
                        ><span class="orbit-price"
                            >{{ price(p.price) }}
                            <em :class="p.change < 0 ? 'down' : 'up'">{{
                                percent(p.change)
                            }}</em></span
                        >
                    </button>
                    <div class="orbit-caption">
                        <span class="signal-dot"></span>AI AT THE CENTER. EVERY
                        PAIR CONNECTED.
                    </div>
                </div>

                <div
                    v-else-if="version.id === 'terminal'"
                    class="terminal-market"
                >
                    <div class="terminal-title">
                        <span
                            ><span class="signal-dot"></span> PAIR_STREAM /
                            {{ snapshot?.mode || "connecting" }}</span
                        ><span>{{ visiblePairs.length }} INSTRUMENTS</span>
                    </div>
                    <div class="market-table-wrap">
                        <table class="market-table">
                            <thead>
                                <tr>
                                    <th>PAIR / USD</th>
                                    <th>MARK PRICE</th>
                                    <th>24H Δ</th>
                                    <th>PRICE TRACE</th>
                                    <th>POSITION</th>
                                    <th>TRADING P&L</th>
                                    <th>AI PIPE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(p, i) in visiblePairs"
                                    :key="p.id"
                                    :class="{
                                        'pxo-row-targeted':
                                            highlightedPair === p.id,
                                    }"
                                    @pointerenter="highlightedPair = p.id"
                                    @pointerleave="highlightedPair = ''"
                                    @focusin="highlightedPair = p.id"
                                    @focusout="highlightedPair = ''"
                                >
                                    <td>
                                        <button @click="selectPair(p.id)">
                                            <span class="row-index">{{
                                                String(i + 1).padStart(2, "0")
                                            }}</span
                                            ><img
                                                v-if="pngUrlFor(p.id)"
                                                :src="pngUrlFor(p.id)"
                                                alt=""
                                            />{{ ticker(p.id)
                                            }}<i
                                                class="fa-solid fa-arrow-up-right"
                                                aria-hidden="true"
                                            ></i>
                                        </button>
                                    </td>
                                    <td>{{ price(p.price) }}</td>
                                    <td :class="p.change < 0 ? 'down' : 'up'">
                                        {{ percent(p.change) }}
                                    </td>
                                    <td>
                                        <Sparkline
                                            :values="p.history"
                                            :class="
                                                p.change < 0 ? 'down' : 'up'
                                            "
                                        />
                                    </td>
                                    <td>
                                        <span
                                            class="position-tag"
                                            :class="p.side"
                                            >{{ p.side }}</span
                                        >
                                    </td>
                                    <td
                                        class="table-pnl"
                                        :class="p.pnl < 0 ? 'down' : 'up'"
                                    >
                                        {{ signedCash(p.pnl) }}
                                    </td>
                                    <td>
                                        <span class="table-pipe"></span
                                        ><i
                                            class="fa-solid fa-microchip-ai"
                                            aria-hidden="true"
                                        ></i
                                        ><span class="table-live">{{
                                            p.live ? "LIVE" : "CACHED"
                                        }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="pair-field">
                    <div class="pair-bus" aria-hidden="true">
                        <span
                            ><i class="fa-solid fa-microchip-ai"></i
                            >{{ (snapshot?.strategy || "AI").toUpperCase() }} /
                            CONNECTED TO EVERY PAIR</span
                        >
                    </div>
                    <div class="pair-grid">
                        <PairCard
                            v-for="(p, i) in visiblePairs"
                            :key="p.id"
                            :pair="p"
                            :index="i"
                            :focused="highlightedPair === p.id"
                            :motion="motion"
                            @select="selectPair"
                            @highlight="highlightedPair = $event"
                        />
                    </div>
                </div>
                <div v-if="!visiblePairs.length" class="empty-market">
                    <i
                        class="fa-solid fa-satellite-dish"
                        aria-hidden="true"
                    ></i>
                    <h3>
                        {{
                            pairs.length
                                ? "No matching pairs"
                                : "Waiting for your first signal"
                        }}
                    </h3>
                    <p>
                        {{
                            pairs.length
                                ? "Try another symbol or show all pairs."
                                : "Your configured trading pairs will appear when the desk is available."
                        }}
                    </p>
                    <button
                        v-if="pairs.length"
                        @click="
                            query = '';
                            filter = 'all';
                        "
                    >
                        Show all pairs</button
                    ><button v-else @click="refresh">Refresh connection</button>
                </div>
            </section>

            <section class="bottom-grid">
                <div class="firehose-panel">
                    <div class="panel-heading">
                        <div>
                            <span class="signal-dot"></span>
                            <h2>The firehose</h2>
                            <span class="event-rate"
                                >{{ rate.toFixed(1) }} / SEC</span
                            >
                        </div>
                        <button
                            @click="toggleFeed"
                            :aria-pressed="feedPaused"
                            :aria-label="
                                feedPaused
                                    ? 'Resume event feed'
                                    : 'Pause event feed'
                            "
                        >
                            <i
                                :class="`fa-solid fa-${feedPaused ? 'play' : 'pause'}`"
                                aria-hidden="true"
                            ></i
                            >{{ feedPaused ? "Resume" : "Pause" }}
                        </button>
                    </div>
                    <div class="feed-toolbar">
                        <span>{{
                            feedPaused
                                ? "DISPLAY PAUSED · NEW EVENTS BUFFERED"
                                : "EVERY DECISION LEAVES A TRACE"
                        }}</span>
                        <div>
                            <button
                                v-for="f in [
                                    { id: 'all', label: 'All' },
                                    { id: 'desk', label: 'Desk' },
                                    { id: 'ai', label: 'AI' },
                                ]"
                                :key="f.id"
                                @click="eventFilter = f.id"
                                :class="{ active: eventFilter === f.id }"
                                :aria-pressed="eventFilter === f.id"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>
                    <div
                        class="event-list"
                        aria-label="Recent desk and optimizer events"
                        tabindex="0"
                    >
                        <TransitionGroup name="event"
                            ><div
                                v-for="e in visibleEvents"
                                :key="e.key"
                                class="event-row"
                                :class="`event-${e.agent.toLowerCase()}`"
                            >
                                <time>{{ clockTime(e.at) }}</time
                                ><span class="event-agent">{{ e.agent }}</span
                                ><span>{{ e.message }}</span
                                ><i
                                    class="fa-solid fa-arrow-up-right"
                                    aria-hidden="true"
                                ></i></div
                        ></TransitionGroup>
                        <div v-if="!visibleEvents.length" class="empty-feed">
                            {{
                                events.length
                                    ? "No events in this filter yet."
                                    : "Listening for desk activity and optimizer events…"
                            }}
                        </div>
                    </div>
                    <div class="feed-footer">
                        <span>{{
                            demo
                                ? "SIMULATED EVENT STREAM"
                                : `OPTIMIZER ${connection.toUpperCase()} · DESK EVENTS SYNC EVERY 3S`
                        }}</span
                        ><span>{{ compact(totalMessages) }} RECEIVED</span>
                    </div>
                </div>
                <aside class="intelligence-panel">
                    <div class="eyebrow">
                        <i
                            class="fa-solid fa-brain-circuit"
                            aria-hidden="true"
                        ></i
                        >THE INTELLIGENCE LAYER
                    </div>
                    <h2>The next move<br />starts <span>here.</span></h2>
                    <p>
                        Signals become decisions. Decisions become positions.
                        Follow the strategy behind every pair.
                    </p>
                    <div class="intelligence-visual" aria-hidden="true">
                        <BrandInsignia
                            :theme="version.id"
                            placement="core"
                            :motion="motion"
                            :pulse="totalMessages"
                            class="signature-panel-seal"
                        />
                        <span
                            v-for="i in 16"
                            :key="i"
                            class="intelligence-meter-bar"
                            :style="{ '--i': i }"
                        ></span>
                    </div>
                    <router-link to="/optimizer"
                        >Explore the intelligence
                        <i
                            class="fa-solid fa-arrow-up-right"
                            aria-hidden="true"
                        ></i
                    ></router-link>
                </aside>
            </section>
        </main>
        <footer class="landing-footer">
            <span class="footer-brand"
                >ShoeMoney<b>X</b><small>INTELLIGENCE IN MOTION.</small></span
            ><span>{{
                demo
                    ? "DEMO · ALL FIGURES ARE SIMULATED"
                    : `${(snapshot?.mode || "unknown").toUpperCase()} MODE · P&L BEFORE FEES`
            }}</span
            ><a v-if="demo" :href="`/landing/${version.id}`"
                >Connect to desk
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a
            ><span v-else
                >COINBASE / {{ version.title.toUpperCase() }} EDITION</span
            >
        </footer>

        <dialog
            ref="detail"
            class="pair-dialog"
            @cancel.prevent="selected = null"
            @click="
                (e) => {
                    if (e.target === detail) selected = null;
                }
            "
            aria-labelledby="pair-detail-title"
        >
            <div v-if="selectedPair" class="pair-detail">
                <button
                    ref="closeButton"
                    @click="selected = null"
                    class="close-detail"
                    aria-label="Close pair details"
                >
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
                <div class="eyebrow">
                    PAIR INTELLIGENCE / {{ snapshot?.mode }}
                </div>
                <h2 id="pair-detail-title">{{ selectedPair.id }}</h2>
                <span class="detail-side"
                    >{{ selectedPair.side.toUpperCase() }} ·
                    {{ selectedPair.open }} OPEN POSITIONS</span
                >
                <div class="detail-price">
                    {{ price(selectedPair.price)
                    }}<span :class="selectedPair.change < 0 ? 'down' : 'up'"
                        >{{ percent(selectedPair.change) }} / 24H</span
                    >
                </div>
                <Sparkline
                    :values="selectedPair.history"
                    area
                    class="detail-chart"
                />
                <dl>
                    <div>
                        <dt>Trading P&L · before fees</dt>
                        <dd :class="selectedPair.pnl < 0 ? 'down' : 'up'">
                            {{ signedCash(selectedPair.pnl) }}
                        </dd>
                    </div>
                    <div>
                        <dt>Realised</dt>
                        <dd>{{ signedCash(selectedPair.realised) }}</dd>
                    </div>
                    <div>
                        <dt>Unrealised</dt>
                        <dd>{{ signedCash(selectedPair.unrealised) }}</dd>
                    </div>
                    <div>
                        <dt>Open notional</dt>
                        <dd>{{ cash(selectedPair.notional) }}</dd>
                    </div>
                    <div>
                        <dt>Market feed</dt>
                        <dd>
                            {{
                                demo
                                    ? "Simulated"
                                    : selectedPair.live && !stale
                                      ? "Live"
                                      : "Cached / delayed"
                            }}
                        </dd>
                    </div>
                </dl>
                <p>
                    Guided by the
                    {{ (snapshot?.strategy || "AI").toUpperCase() }} strategy
                    through SCAN → VET → SIZE → FILLS, with RISK monitoring the
                    position.
                </p>
                <router-link
                    :to="`/chart/${selectedPair.id}`"
                    class="open-desk"
                    @click="selected = null"
                    >Open full chart
                    <i class="fa-solid fa-arrow-up-right" aria-hidden="true"></i
                ></router-link>
            </div>
        </dialog>
    </div>
</template>
