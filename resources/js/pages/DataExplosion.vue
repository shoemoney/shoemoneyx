<script setup>
import { publicDemo } from "../demoMode";
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from "vue";
import { useRoute } from "vue-router";
import { publishPublicStatus } from "../siteShell";
import { explosionDesigns } from "../landing/explosionDesigns";
import { singularityDesigns } from "../landing/singularityDesigns";
import { useLiveDesk } from "../landing/useLiveDesk";
import {
    cash,
    signedCash,
    price,
    percent,
    compact,
    ticker,
    clockTime,
} from "../landing/format";
import { pngUrlFor } from "../coinIcons";
import GpuDataField from "../landing/GpuDataField.vue";
import ExplosionPair from "../landing/ExplosionPair.vue";
import Sparkline from "../landing/Sparkline.vue";
import PnlInstrument from "../landing/PnlInstrument.vue";
import MarketFocusLens from "../landing/MarketFocusLens.vue";
import BrandInsignia from "../landing/BrandInsignia.vue";
import HorizonGuardian from "../landing/HorizonGuardian.vue";
import RobotEffectStage from "../landing/RobotEffectStage.vue";
import RobotGazeBeam from "../landing/RobotGazeBeam.vue";
import "../../css/explosion.css";
import "../../css/singularity-editions.css";
import "../../css/home-desk.css";

const route = useRoute();
const isFrontPage = computed(() => route.meta.frontPage === true);
const robotEdition = computed(() =>
    singularityDesigns.find(
        (edition) =>
            edition.id ===
            (isFrontPage.value ? "nanites" : route.params.effect),
    ),
);
const coinNodes = computed(() => robotEdition.value?.id === "nanites");
const scanId = ref(null);
const marketScene = ref(null),
    robotBeam = ref(null);
function paintRobotGaze(gaze) {
    robotBeam.value?.paint(
        gaze,
        marketScene.value?.getCoinClientPoint(gaze.id),
    );
}
const naniteTitle = "ShoeMoney AI V3.8";
const naniteDescription =
    "Powered By: A local qwen 3.8 28b model distilled with abliteration and fine tuned with trading data sets.";
const navigationDesigns = computed(() =>
    robotEdition.value ? singularityDesigns : explosionDesigns,
);
const design = computed(
    () =>
        explosionDesigns.find(
            (d) =>
                d.id ===
                (robotEdition.value ? "horizon-blue" : route.params.version),
        ) || explosionDesigns[0],
);
const pageTitle = computed(() =>
    isFrontPage.value
        ? "ShoeMoneyX · Live trading overview"
        : robotEdition.value
          ? `${robotEdition.value.name} · The Singularity · ShoeMoneyX`
          : `${design.value.name} · ShoeMoneyX`,
);
const replay = ref(0),
    inspection = ref(0);
const family = computed(() => design.value.family || design.value.id);
const familyDesign = computed(() => ({ ...design.value, id: family.value }));
// Remounting the landing boundary prevents a preview feed from carrying over.
const demo = publicDemo || (!isFrontPage.value &&
    new URLSearchParams(location.search).get("demo") === "1");
const {
    snapshot,
    pairs,
    events,
    error,
    connection,
    lastUpdate,
    now,
    rate,
    totalMessages,
    statusText,
    stale,
    pnlHistory,
    motion,
    feedPaused,
    selected,
    selectedPair,
    toggleFeed,
    refresh,
} = useLiveDesk(demo, { demoPairs: 18, demoBatch: 5, demoInterval: 160 });
const metrics = computed(() => snapshot.value?.metrics || {});
const modeLabel = computed(
    () => snapshot.value?.mode?.toUpperCase() || "AWAITING DESK",
);
const feedLabel = computed(() =>
    feedPaused.value
        ? "PAUSED"
        : demo
          ? "SIMULATED"
          : !snapshot.value
            ? "AWAITING DATA"
            : stale.value
              ? "DATA DELAYED"
              : "DESK EVENTS",
);
const pnlBasisLabel = computed(() =>
    demo
        ? "BEFORE FEES"
        : metrics.value.pnl_basis === "net_of_booked_costs"
          ? "BOOKED COSTS INCLUDED"
          : "DESK P&L",
);
const pnlNote = computed(() =>
    demo
        ? "Simulated data and illustrative effects. No actual orders."
        : metrics.value.pnl_note ||
          "P&L from the configured desk. Market connections are illustrative.",
);
watch(
    [snapshot, statusText, stale, isFrontPage, pnlNote],
    () => {
        if (isFrontPage.value)
            publishPublicStatus({
                mode: snapshot.value?.mode?.toUpperCase(),
                source: statusText.value,
                note: pnlNote.value,
                live: !stale.value && !!snapshot.value?.feed_alive,
            });
    },
    { immediate: true },
);
const latestRun = computed(() => snapshot.value?.latest_run || null);
const bank = computed(() => snapshot.value?.bank || null);
const bankAge = computed(() => {
    if (!bank.value?.taken_at) return null;
    const timestamp = Date.parse(bank.value.taken_at);
    return Number.isFinite(timestamp)
        ? Math.max(0, Math.floor((now.value - timestamp) / 1000))
        : null;
});
const bankAgeLabel = computed(() =>
    bankAge.value == null
        ? "Not available"
        : bankAge.value < 60
          ? `${bankAge.value}s ago`
          : bankAge.value < 3600
            ? `${Math.floor(bankAge.value / 60)}m ago`
            : `${Math.floor(bankAge.value / 3600)}h ago`,
);
const query = ref(""),
    sort = ref("market"),
    filter = ref("all"),
    eventFilter = ref("all");
const detail = ref(null),
    closeButton = ref(null),
    designNav = ref(null);
let previousFocus, numberFrame, focusTimer, navResizeObserver;
let lastInstrumentBeat = 0;
const instrumentBeat = ref(0),
    pnlDelta = ref(null),
    focusedId = ref(null);
const focusedPair = computed(() =>
    pairs.value.find((pair) => pair.id === focusedId.value),
);
const animatedPnl = ref(null);
const visiblePairs = computed(() => {
    const result = pairs.value.filter(
        (p) =>
            p.id.toLowerCase().includes(query.value.toLowerCase()) &&
            (filter.value === "all" ||
                (filter.value === "open" ? p.open > 0 : p.pnl > 0)),
    );
    if (sort.value === "pnl")
        result.sort((a, b) => (b.pnl ?? -Infinity) - (a.pnl ?? -Infinity));
    if (sort.value === "move")
        result.sort(
            (a, b) => Math.abs(b.change || 0) - Math.abs(a.change || 0),
        );
    return result;
});
const shownEvents = computed(() =>
    events.value
        .filter((e) => eventFilter.value === "all" || e.agent === "AI")
        .slice(0, 16),
);
const agents = [
    { id: "SCAN", icon: "crosshairs", label: "Scan" },
    { id: "VET", icon: "shield-halved", label: "Validate" },
    { id: "SIZE", icon: "chart-scatter", label: "Size" },
    { id: "FILLS", icon: "bolt", label: "Execute" },
    { id: "RISK", icon: "wave-pulse", label: "Protect" },
];
const longCount = computed(
    () => pairs.value.filter((p) => p.side === "long").length,
);
const shortCount = computed(
    () => pairs.value.filter((p) => p.side === "short").length,
);
const positionDirectionLabel = computed(() =>
    !snapshot.value
        ? "Awaiting position records"
        : metrics.value.longs != null && metrics.value.shorts != null
          ? `${metrics.value.longs} long / ${metrics.value.shorts} short positions`
          : `${longCount.value} long / ${shortCount.value} short pairs`,
);
const gainCount = computed(() => pairs.value.filter((p) => p.pnl > 0).length);
const biggest = computed(
    () =>
        [...pairs.value]
            .filter((p) => p.pnl != null)
            .sort((a, b) => b.pnl - a.pnl)[0],
);
const pnlParts = computed(() => signedCash(animatedPnl.value).split("."));
const linkTo = (id) => ({
    path: robotEdition.value ? `/singularity/${id}` : `/landing/${id}`,
    query: demo ? { demo: "1" } : {},
});
const agentSeconds = (id) => {
    const age = snapshot.value?.heartbeats?.[id];
    if (age == null) return null;
    return (
        Number(age) +
        (lastUpdate.value
            ? Math.max(0, Math.floor((now.value - lastUpdate.value) / 1000))
            : 0)
    );
};
const agentActive = (id) => {
    const age = agentSeconds(id);
    return (
        demo ||
        (snapshot.value?.running === true &&
            !snapshot.value?.halted &&
            !stale.value &&
            age != null &&
            age <= (snapshot.value?.heartbeat_stale_seconds ?? 120))
    );
};
const agentAge = (id) => {
    const elapsed = agentSeconds(id);
    if (elapsed == null) return "OFFLINE";
    if (!demo && snapshot.value?.halted) return "HALTED";
    if (!demo && snapshot.value?.running === false) return "STOPPED";
    const label = elapsed < 60 ? `${elapsed}s` : `${Math.floor(elapsed / 60)}m`;
    return agentActive(id) ? label : `STALE · ${label}`;
};
function inspect(id) {
    inspection.value++;
    previousFocus = document.activeElement;
    selected.value = id;
}
function focusMarket(id) {
    clearTimeout(focusTimer);
    if (id) focusedId.value = id;
    else
        focusTimer = setTimeout(() => {
            focusedId.value = null;
        }, 90);
}
function revealActiveDesign() {
    const strip = designNav.value;
    const active = strip?.querySelector('[aria-current="page"]');
    if (!strip || !active || !strip.clientWidth) return;
    const bounds = strip.getBoundingClientRect();
    const item = active.getBoundingClientRect();
    const left = bounds.left + strip.clientLeft;
    const right = left + strip.clientWidth;
    if (item.width > strip.clientWidth - 16 || item.left < left + 8) {
        strip.scrollLeft += item.left - left - 8;
    } else if (item.right > right - 8) {
        strip.scrollLeft += item.right - right + 8;
    }
}
function openDemo() {
    window.location.href = `${route.path}?demo=1`;
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
watch(snapshot, (current, prior) => {
    const value = current?.metrics?.pnl,
        previous = prior?.metrics?.pnl;
    pnlDelta.value =
        value != null && previous != null ? value - previous : null;
    if (
        motion.value &&
        document.visibilityState !== "hidden" &&
        performance.now() - lastInstrumentBeat > 900
    ) {
        instrumentBeat.value++;
        lastInstrumentBeat = performance.now();
    }
});
watch(motion, (enabled) => {
    if (!enabled) {
        cancelAnimationFrame(numberFrame);
        animatedPnl.value = metrics.value.pnl;
    }
});
watch(
    () => metrics.value.pnl,
    (value) => {
        cancelAnimationFrame(numberFrame);
        if (value == null || animatedPnl.value == null || !motion.value) {
            animatedPnl.value = value;
            return;
        }
        const from = animatedPnl.value,
            start = performance.now();
        const step = (time) => {
            const progress = Math.min((time - start) / 380, 1);
            animatedPnl.value =
                from + (value - from) * (1 - Math.pow(1 - progress, 3));
            if (progress < 1) numberFrame = requestAnimationFrame(step);
        };
        numberFrame = requestAnimationFrame(step);
    },
    { immediate: true },
);
watch(
    () => robotEdition.value?.id || design.value.id,
    () => {
        document.title = pageTitle.value;
        selected.value = null;
        query.value = "";
        filter.value = "all";
        focusedId.value = null;
        replay.value = 0;
        inspection.value = 0;
        nextTick(revealActiveDesign);
    },
    { immediate: true },
);
onMounted(() => {
    document.title = pageTitle.value;
    revealActiveDesign();
    navResizeObserver = new ResizeObserver(revealActiveDesign);
    if (designNav.value) navResizeObserver.observe(designNav.value);
    document.fonts?.ready.then(revealActiveDesign);
});
onUnmounted(() => {
    navResizeObserver?.disconnect();
    clearTimeout(focusTimer);
    cancelAnimationFrame(numberFrame);
    detail.value?.close();
    document.title = "ShoeMoneyX — desk";
});
</script>

<template>
    <div
        class="dx"
        :class="[
            `dx-${design.id}`,
            family !== design.id ? `dx-${family}` : null,
            design.colorway ? `dx-colorway-${design.colorway}` : null,
            `dx-layout-${design.layout}`,
            { 'dx-singularity-edition': !!robotEdition },
            { 'dx-front-page': isFrontPage },
            { 'dx-nanites-release': coinNodes },
            { 'dx-still': !motion, 'dx-light': design.light },
        ]"
        :style="{ '--accent': design.accent, '--second': design.second }"
    >
        <a class="dx-skip" href="#dx-markets">Skip to trading pairs</a>
        <nav
            v-if="!coinNodes"
            class="dx-design-nav"
            aria-label="Landing page designs"
        >
            <router-link
                class="dx-originals"
                :to="robotEdition ? '/singularity' : '/designs'"
                ><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                {{
                    robotEdition ? "Robot effects" : "All designs"
                }}</router-link
            >
            <div ref="designNav" class="dx-design-links">
                <router-link
                    v-for="item in navigationDesigns"
                    :key="item.id"
                    :to="linkTo(item.id)"
                    :aria-current="
                        (robotEdition?.id || design.id) === item.id
                            ? 'page'
                            : undefined
                    "
                    ><span>{{ item.number }}</span
                    >{{ item.name }}</router-link
                >
            </div>
            <span class="dx-edition">{{
                robotEdition
                    ? "THE SINGULARITY / ROBOT SERIES"
                    : "THE DATA EXPLOSION SERIES"
            }}</span>
        </nav>
        <div class="dx-shell">
            <header class="dx-header">
                <router-link
                    v-if="!isFrontPage"
                    :to="
                        coinNodes
                            ? '/'
                            : robotEdition
                              ? '/singularity'
                              : linkTo(design.id)
                    "
                    class="dx-brand"
                    aria-label="ShoeMoneyX home"
                    ><BrandInsignia
                        class="dx-masthead-brand"
                        :theme="design.id"
                        placement="masthead"
                        :motion="motion"
                        :pulse="instrumentBeat"
                    /><strong>SHOEMONEY<span>X</span></strong></router-link
                >
                <div class="dx-headline">
                    <span>{{
                        coinNodes
                            ? "SHOEMONEYX / TRADING INTELLIGENCE"
                            : robotEdition
                              ? `${robotEdition.number} / 10 · THE SINGULARITY`
                              : design.kicker
                    }}</span>
                    <h1>
                        {{
                            coinNodes
                                ? "Every market. One mind."
                                : design.headline
                        }}
                    </h1>
                    <span v-if="coinNodes" class="dx-mobile-feed-status">
                        <i
                            class="dx-dot"
                            :class="{
                                'dx-dot-off':
                                    !demo && (stale || !snapshot?.feed_alive),
                            }"
                        ></i>
                        {{ statusText }}
                    </span>
                </div>
                <div class="dx-header-actions">
                    <span class="dx-system-status"
                        ><i
                            class="dx-dot"
                            :class="{
                                'dx-dot-off':
                                    !demo && (stale || !snapshot?.feed_alive),
                            }"
                        ></i
                        >{{ statusText }}</span
                    ><router-link to="/dashboard" class="dx-desk-link"
                        >Enter desk
                        <i
                            class="fa-solid fa-arrow-up-right"
                            aria-hidden="true"
                        ></i
                    ></router-link>
                </div>
            </header>

            <div v-if="error" class="dx-notice" role="status">
                <i
                    class="fa-solid fa-triangle-exclamation"
                    aria-hidden="true"
                ></i
                >{{ error }}<button @click="refresh">Retry</button>
            </div>
            <div
                v-else-if="!demo && (!snapshot?.feed_alive || stale)"
                class="dx-notice"
                role="status"
            >
                {{
                    !snapshot
                        ? "Connecting to the configured trading desk…"
                        : stale
                          ? "Desk updates are delayed. Showing the last available records."
                          : "The market feed is offline. Available desk records appear below."
                }}<a
                    v-if="!isFrontPage"
                    :href="`${route.path}?demo=1`"
                    @click.prevent="openDemo"
                    >View simulated demo</a
                >
            </div>

            <section class="dx-scoreboard" aria-label="Trading performance">
                <div class="dx-pnl-main">
                    <PnlInstrument
                        :design="family"
                        :pairs="pairs"
                        :history="pnlHistory"
                        :beat="instrumentBeat"
                        :direction="pnlDelta"
                    />
                    <div class="dx-eyebrow">
                        <span
                            >{{
                                demo
                                    ? "SIMULATED"
                                    : snapshot?.mode?.toUpperCase() || "DESK"
                            }}
                            TOTAL P&L</span
                        ><span class="dx-pnl-tag" :title="pnlNote">{{
                            pnlBasisLabel
                        }}</span>
                    </div>
                    <div
                        class="dx-big-number"
                        :class="{ 'dx-down': metrics.pnl < 0 }"
                    >
                        <span>{{ pnlParts[0] }}</span
                        ><small v-if="pnlParts[1]">.{{ pnlParts[1] }}</small
                        ><i
                            class="fa-solid fa-arrow-up-right"
                            aria-hidden="true"
                        ></i>
                    </div>
                    <div class="dx-pnl-bottom">
                        <span>{{
                            snapshot
                                ? `${gainCount} / ${pairs.length} pairs profitable`
                                : "Awaiting performance records"
                        }}</span
                        ><span class="dx-pnl-sub"
                            >Realised {{ cash(metrics.realised) }}</span
                        >
                    </div>
                    <div
                        class="dx-pnl-delta"
                        :class="{ 'dx-delta-down': pnlDelta < 0 }"
                    >
                        <span
                            class="dx-delta-pulse"
                            :key="instrumentBeat"
                            aria-hidden="true"
                        ></span>
                        <b>{{
                            pnlDelta == null ? "—" : signedCash(pnlDelta)
                        }}</b>
                        <span>LAST SNAPSHOT Δ</span>
                        <i aria-hidden="true"
                            >{{
                                String(pairs.length).padStart(2, "0")
                            }}
                            MARKETS</i
                        >
                    </div>
                    <Sparkline
                        class="dx-total-chart"
                        :values="pnlHistory"
                        area
                    />
                </div>
                <div class="dx-stat">
                    <span
                        ><i
                            class="fa-solid fa-circle-nodes"
                            aria-hidden="true"
                        ></i
                        >ACTIVE POSITIONS</span
                    ><strong
                        >{{ metrics.open ?? "—"
                        }}<small>/ {{ pairs.length }} pairs</small></strong
                    >
                    <div class="dx-stat-bars">
                        <i
                            v-for="n in 18"
                            :key="n"
                            :class="{ 'dx-bar-dim': n > (metrics.open || 0) }"
                            :style="{ height: `${25 + ((n * 19) % 70)}%` }"
                        ></i>
                    </div>
                    <p>{{ positionDirectionLabel }}</p>
                </div>
                <div class="dx-stat">
                    <span
                        ><i
                            class="fa-solid fa-crosshairs"
                            aria-hidden="true"
                        ></i
                        >CLOSED WIN RATE</span
                    ><strong
                        >{{
                            metrics.win_rate == null
                                ? "—"
                                : metrics.win_rate.toFixed(1)
                        }}<small>%</small></strong
                    >
                    <div class="dx-stat-progress">
                        <i :style="{ width: `${metrics.win_rate || 0}%` }"></i>
                    </div>
                    <p>{{ compact(metrics.trades) }} closed trades</p>
                </div>
                <div class="dx-stat">
                    <span
                        ><i
                            class="fa-solid fa-chart-scatter"
                            aria-hidden="true"
                        ></i
                        >NOTIONAL EXPOSURE</span
                    ><strong>{{
                        metrics.notional == null
                            ? "—"
                            : "$" + compact(metrics.notional)
                    }}</strong>
                    <p
                        class="dx-unrealised"
                        :class="metrics.unrealised < 0 ? 'dx-down' : 'dx-up'"
                    >
                        {{ signedCash(metrics.unrealised) }}
                    </p>
                    <p>Unrealised P&L</p>
                </div>
                <div class="dx-stat dx-throughput">
                    <span
                        ><i
                            class="fa-solid fa-satellite-dish"
                            aria-hidden="true"
                        ></i
                        >EVENT THROUGHPUT</span
                    ><strong>{{ rate.toFixed(1) }}<small>/ sec</small></strong>
                    <div class="dx-equalizer">
                        <i
                            v-for="n in 24"
                            :key="n"
                            :style="{
                                '--eq': n,
                                height: `${12 + ((n * 31) % 88)}%`,
                            }"
                        ></i>
                    </div>
                    <p>
                        {{ totalMessages.toLocaleString() }} received this
                        session
                    </p>
                </div>
            </section>

            <section class="dx-intelligence" aria-label="AI trading pipeline">
                <span class="dx-intelligence-title"
                    ><i
                        class="fa-solid fa-brain-circuit"
                        aria-hidden="true"
                    ></i>
                    AI GUIDANCE</span
                >
                <div class="dx-agent-pipe">
                    <div
                        v-for="(agent, index) in agents"
                        :key="agent.id"
                        class="dx-agent"
                        :class="{
                            'dx-agent-off': !agentActive(agent.id),
                        }"
                    >
                        <i
                            :class="`fa-solid fa-${agent.icon}`"
                            aria-hidden="true"
                        ></i
                        ><span>{{ agent.label }}</span
                        ><small>{{ agentAge(agent.id) }}</small
                        ><span
                            v-if="index < agents.length - 1"
                            class="dx-pipe-flow"
                            :class="{
                                'dx-pipe-inactive':
                                    !agentActive(agent.id) ||
                                    !agentActive(agents[index + 1].id),
                            }"
                        ></span>
                    </div>
                </div>
                <button
                    class="dx-motion"
                    :aria-pressed="motion"
                    @click="motion = !motion"
                >
                    <i
                        :class="`fa-solid fa-${motion ? 'pause' : 'play'}`"
                        aria-hidden="true"
                    ></i
                    >{{ motion ? "Effects on" : "Effects paused" }}
                </button>
            </section>

            <main class="dx-workspace">
                <section
                    class="dx-stage"
                    :class="{ 'dx-stage-focused': focusedPair }"
                    :aria-label="design.sceneTitle"
                >
                    <div class="dx-stage-head">
                        <span
                            ><i
                                class="dx-dot"
                                :class="{
                                    'dx-dot-off':
                                        !demo &&
                                        (stale || !snapshot?.feed_alive),
                                }"
                            ></i>
                            {{ design.sceneTitle }}</span
                        ><span
                            >{{ String(pairs.length).padStart(2, "0") }} INPUTS
                            <b>→</b> 01 MIND</span
                        >
                    </div>
                    <GpuDataField
                        ref="marketScene"
                        :key="`${design.id}:${coinNodes ? 'coins' : 'dots'}`"
                        :design="design"
                        :coin-nodes="coinNodes"
                        :scan-id="coinNodes ? scanId : null"
                        :pairs="pairs"
                        :rate="rate"
                        :motion="motion"
                        :focus-id="focusedId"
                        @select="inspect"
                    />
                    <RobotGazeBeam v-if="coinNodes" ref="robotBeam" />
                    <Transition name="dx-lens"
                        ><MarketFocusLens
                            v-if="focusedPair && !robotEdition"
                            :pair="focusedPair"
                            :design="familyDesign"
                    /></Transition>
                    <div class="dx-stage-reticle" aria-hidden="true">
                        <span>+</span><span>+</span><span>+</span><span>+</span>
                    </div>
                    <div class="dx-core-caption">
                        <RobotEffectStage
                            v-if="robotEdition"
                            :key="robotEdition.id"
                            :effect="robotEdition.id"
                            :motion="motion"
                            :pulse="totalMessages"
                            :rate="rate"
                            :pair="
                                focusedPair || selectedPair || pairs[0] || null
                            "
                            :pairs="pairs"
                            :inspection="inspection"
                            :replay="replay"
                            @inspect="inspect"
                            @scan="scanId = $event"
                            @gaze="paintRobotGaze"
                        />
                        <HorizonGuardian
                            v-else-if="family === 'horizon'"
                            :motion="motion"
                            :active="!focusedPair"
                            :pulse="instrumentBeat"
                        />
                        <BrandInsignia
                            v-else
                            class="dx-core-brand"
                            :theme="design.id"
                            placement="core"
                            :motion="motion"
                            :pulse="instrumentBeat"
                        /><strong>{{
                            coinNodes
                                ? naniteTitle
                                : robotEdition
                                  ? robotEdition.name
                                  : family === "citadel"
                                    ? "CAPITAL / CITY"
                                    : family === "horizon"
                                      ? "THE SINGULARITY"
                                      : "SHOEMONEY AI"
                        }}</strong
                        ><small>{{
                            coinNodes
                                ? naniteDescription
                                : robotEdition
                                  ? robotEdition.hint
                                  : design.description
                        }}</small>
                        <button
                            v-if="robotEdition && !coinNodes"
                            class="dx-robot-replay"
                            @click="replay++"
                        >
                            <i class="fa-solid fa-play" aria-hidden="true"></i>
                            Replay effect
                        </button>
                    </div>
                    <div class="dx-stage-telemetry">
                        <span
                            ><b>{{ pairs.length }}</b> TRACKED MARKETS</span
                        ><span
                            ><b>{{ rate.toFixed(1) }}</b> EVENTS / SEC</span
                        ><span
                            ><b>{{
                                demo
                                    ? "SIM"
                                    : snapshot?.strategy?.toUpperCase() || "—"
                            }}</b>
                            {{
                                demo
                                    ? coinNodes
                                        ? "SIMULATED DATA"
                                        : "ILLUSTRATIVE FIELD"
                                    : "STRATEGY"
                            }}</span
                        >
                    </div>
                    <div v-if="biggest" class="dx-top-signal">
                        <i
                            class="fa-solid fa-arrow-trend-up"
                            aria-hidden="true"
                        ></i
                        ><span>TOP CONTRIBUTOR</span
                        ><b>{{ ticker(biggest.id) }}</b
                        ><strong>{{ signedCash(biggest.pnl) }}</strong>
                    </div>
                </section>

                <section
                    id="dx-markets"
                    class="dx-markets"
                    aria-label="All trading pairs"
                >
                    <div class="dx-market-toolbar">
                        <h2>
                            <i class="fa-solid fa-grid-2" aria-hidden="true"></i
                            >{{
                                family === "redline"
                                    ? "THE TIMING BOARD"
                                    : family === "spectrum"
                                      ? "THE MARKET IN COLOR"
                                      : "EVERY PAIR. CONNECTED."
                            }}<span>{{ visiblePairs.length }}</span>
                        </h2>
                        <label class="dx-search"
                            ><i
                                class="fa-solid fa-magnifying-glass"
                                aria-hidden="true"
                            ></i
                            ><input
                                v-model="query"
                                type="search"
                                aria-label="Search trading pairs"
                                placeholder="Find pair"
                        /></label>
                    </div>
                    <div class="dx-market-controls">
                        <div>
                            <button
                                :aria-pressed="filter === 'all'"
                                @click="filter = 'all'"
                            >
                                All pairs</button
                            ><button
                                :aria-pressed="filter === 'open'"
                                @click="filter = 'open'"
                            >
                                Positions</button
                            ><button
                                :aria-pressed="filter === 'gaining'"
                                @click="filter = 'gaining'"
                            >
                                Profitable
                            </button>
                        </div>
                        <select v-model="sort" aria-label="Sort trading pairs">
                            <option value="market">Market order</option>
                            <option value="pnl">Highest P&L</option>
                            <option value="move">Largest move</option>
                        </select>
                    </div>
                    <div class="dx-market-grid">
                        <ExplosionPair
                            v-for="(pair, index) in visiblePairs"
                            :key="pair.id"
                            :pair="pair"
                            :index="index"
                            :motion="motion"
                            :focused="focusedId === pair.id"
                            @select="inspect"
                            @focus-market="focusMarket"
                        />
                    </div>
                    <div v-if="!visiblePairs.length" class="dx-empty">
                        <i
                            class="fa-solid fa-magnifying-glass"
                            aria-hidden="true"
                        ></i
                        ><strong>{{
                            query || filter !== "all"
                                ? "No matching pairs"
                                : "Waiting for your market universe"
                        }}</strong
                        ><span>{{
                            query || filter !== "all"
                                ? "Try a different symbol or filter."
                                : "Your active pairs will appear when the desk reports them."
                        }}</span
                        ><button
                            v-if="query || filter !== 'all'"
                            @click="
                                query = '';
                                filter = 'all';
                            "
                        >
                            Reset filters
                        </button>
                    </div>
                </section>

                <section
                    id="dx-activity"
                    class="dx-firehose"
                    aria-label="Real-time event firehose"
                >
                    <div class="dx-feed-head">
                        <h2>
                            <i
                                class="fa-solid fa-fire-flame-curved"
                                aria-hidden="true"
                            ></i>
                            THE FIREHOSE
                            <span>{{ feedLabel }}</span>
                        </h2>
                        <div>
                            <button
                                :aria-pressed="eventFilter === 'ai'"
                                @click="
                                    eventFilter =
                                        eventFilter === 'all' ? 'ai' : 'all'
                                "
                            >
                                AI only</button
                            ><button
                                :aria-label="
                                    feedPaused
                                        ? 'Resume event feed'
                                        : 'Pause event feed'
                                "
                                @click="toggleFeed"
                            >
                                <i
                                    :class="`fa-solid fa-${feedPaused ? 'play' : 'pause'}`"
                                    aria-hidden="true"
                                ></i>
                            </button>
                        </div>
                    </div>
                    <div
                        class="dx-feed-stream"
                        :class="{ 'dx-feed-paused': feedPaused }"
                    >
                        <div
                            v-for="(event, index) in shownEvents"
                            :key="event.key"
                            class="dx-event"
                            :class="{ 'dx-event-ai': event.agent === 'AI' }"
                            :style="{ '--event-index': index }"
                        >
                            <time>{{ clockTime(event.at) }}</time
                            ><b>{{ event.agent }}</b
                            ><span>{{ event.message }}</span
                            ><i
                                class="fa-solid fa-chevron-right"
                                aria-hidden="true"
                            ></i>
                        </div>
                        <div v-if="!shownEvents.length" class="dx-feed-empty">
                            {{
                                eventFilter === "ai"
                                    ? "Waiting for AI events…"
                                    : "Waiting for desk activity…"
                            }}
                        </div>
                    </div>
                    <div class="dx-feed-footer">
                        <span
                            ><i
                                class="dx-dot"
                                :class="{
                                    'dx-dot-off': !demo && (!snapshot || stale),
                                }"
                            ></i
                            >{{
                                demo
                                    ? "DEMO GENERATOR"
                                    : connection === "connected"
                                      ? "EVENT STREAM CONNECTED"
                                      : stale
                                        ? "WAITING FOR DESK"
                                        : "DESK UPDATES"
                            }}</span
                        ><span
                            >{{ totalMessages.toLocaleString() }} EVENTS
                            RECEIVED</span
                        >
                    </div>
                </section>
            </main>

            <section
                v-if="isFrontPage"
                class="dx-live-context"
                aria-label="Desk account and AI activity"
            >
                <article class="dx-live-context-card">
                    <header>
                        <span
                            ><i
                                class="fa-solid fa-chart-scatter"
                                aria-hidden="true"
                            ></i
                            >BANK SNAPSHOT</span
                        ><small>{{
                            bank
                                ? `${bank.stale || bankAge > 600 ? "Delayed · " : ""}${bankAgeLabel}`
                                : snapshot
                                  ? "No recorded snapshot"
                                  : "Awaiting account data"
                        }}</small>
                    </header>
                    <dl>
                        <div>
                            <dt>Equity</dt>
                            <dd>{{ cash(bank?.equity) }}</dd>
                        </div>
                        <div>
                            <dt>Cash</dt>
                            <dd>{{ cash(bank?.cash) }}</dd>
                        </div>
                        <div>
                            <dt>Free cash</dt>
                            <dd>{{ cash(bank?.free_cash) }}</dd>
                        </div>
                        <div>
                            <dt>Buying power</dt>
                            <dd>{{ cash(bank?.buying_power) }}</dd>
                        </div>
                    </dl>
                    <p>
                        {{
                            bank
                                ? "Latest persisted account values for this desk mode."
                                : snapshot
                                  ? "Account values appear after the desk records a bank snapshot."
                                  : "Connect to the desk to view its latest account snapshot."
                        }}<router-link to="/dashboard"
                            >Account detail ↗</router-link
                        >
                    </p>
                </article>
                <article class="dx-live-context-card">
                    <header>
                        <span
                            ><i
                                class="fa-solid fa-brain-circuit"
                                aria-hidden="true"
                            ></i
                            >LATEST AI SCAN</span
                        ><small>{{
                            latestRun
                                ? `#${latestRun.id} · ${String(latestRun.status || "unknown").replaceAll("_", " ")}`
                                : snapshot
                                  ? "No recorded scan"
                                  : "Awaiting scan data"
                        }}</small>
                    </header>
                    <dl>
                        <div>
                            <dt>Scanned</dt>
                            <dd>{{ latestRun?.products_scanned ?? "—" }}</dd>
                        </div>
                        <div>
                            <dt>Candidates</dt>
                            <dd>{{ latestRun?.candidates ?? "—" }}</dd>
                        </div>
                        <div>
                            <dt>Passed</dt>
                            <dd>{{ latestRun?.passed ?? "—" }}</dd>
                        </div>
                        <div>
                            <dt>Filled</dt>
                            <dd>{{ latestRun?.filled ?? "—" }}</dd>
                        </div>
                    </dl>
                    <p>
                        {{
                            latestRun
                                ? `${latestRun.strategy || "Strategy unavailable"}${latestRun.degraded ? " · Degraded scan" : ""}`
                                : snapshot
                                  ? "Decisions and executions appear when the AI desk reports a run."
                                  : "Connect to the desk to view AI activity."
                        }}<router-link
                            :to="latestRun ? `/desk/${latestRun.id}` : '/desk'"
                            >Scan detail ↗</router-link
                        >
                    </p>
                </article>
            </section>

            <section class="dx-ticker" aria-label="Market ticker">
                <div class="dx-ticker-track">
                    <template v-for="copy in 2" :key="copy"
                        ><span
                            v-for="pair in pairs"
                            :key="`${copy}-${pair.id}`"
                            :aria-hidden="copy === 2 ? true : undefined"
                            ><img
                                v-if="pngUrlFor(pair.id)"
                                :src="pngUrlFor(pair.id)"
                                alt=""
                                width="16"
                                height="16"
                            /><b>{{ ticker(pair.id) }}</b
                            ><span>{{ price(pair.price) }}</span
                            ><strong
                                :class="pair.change < 0 ? 'dx-down' : 'dx-up'"
                                >{{ percent(pair.change) }}</strong
                            ></span
                        ></template
                    >
                </div>
            </section>
            <footer v-if="!isFrontPage" class="dx-footer">
                <span
                    ><b v-if="!coinNodes"
                        >{{ robotEdition?.number || design.number }} /
                        {{
                            (robotEdition?.name || design.name).toUpperCase()
                        }}</b
                    ><b v-else
                        >{{ demo ? "SIMULATED PREVIEW" : modeLabel
                        }}{{ !demo && snapshot?.mode ? " DESK" : "" }}</b
                    >
                    <span
                        >SHOEMONEY<span class="dx-brand-x">X</span> ·
                        {{ new Date().getFullYear() }}</span
                    ></span
                ><span>{{ pnlNote }}</span
                ><router-link v-if="coinNodes && !isFrontPage" to="/designs"
                    >Design collection</router-link
                ><span>{{ clockTime(now) }} <i class="dx-dot"></i></span>
            </footer>
        </div>

        <dialog
            ref="detail"
            class="dx-detail"
            aria-labelledby="dx-detail-title"
            @close="selected = null"
            @click="
                (e) => {
                    if (e.target === detail) selected = null;
                }
            "
        >
            <template v-if="selectedPair"
                ><header>
                    <span class="dx-eyebrow">{{
                        demo ? "SIMULATED POSITION" : "POSITION DETAIL"
                    }}</span
                    ><button
                        ref="closeButton"
                        aria-label="Close pair details"
                        @click="selected = null"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="dx-detail-title">
                    <img
                        v-if="pngUrlFor(selectedPair.id)"
                        :src="pngUrlFor(selectedPair.id)"
                        alt=""
                        width="44"
                        height="44"
                    />
                    <h2 id="dx-detail-title">{{ selectedPair.id }}</h2>
                    <span>{{ selectedPair.side }}</span>
                </div>
                <div class="dx-detail-price">
                    {{ price(selectedPair.price) }}
                    <small
                        :class="selectedPair.change < 0 ? 'dx-down' : 'dx-up'"
                        >{{ percent(selectedPair.change) }}</small
                    >
                </div>
                <Sparkline :values="selectedPair.history" area />
                <dl>
                    <div>
                        <dt>Total P&L</dt>
                        <dd :class="selectedPair.pnl < 0 ? 'dx-down' : 'dx-up'">
                            {{ signedCash(selectedPair.pnl) }}
                        </dd>
                    </div>
                    <div>
                        <dt>Realised</dt>
                        <dd>{{ cash(selectedPair.realised) }}</dd>
                    </div>
                    <div>
                        <dt>Unrealised</dt>
                        <dd>{{ cash(selectedPair.unrealised) }}</dd>
                    </div>
                    <div>
                        <dt>Notional exposure</dt>
                        <dd>{{ cash(selectedPair.notional) }}</dd>
                    </div>
                </dl>
                <div class="dx-detail-guidance">
                    <i class="fa-solid fa-brain-circuit" aria-hidden="true"></i>
                    <div>
                        <strong>{{
                            selectedPair.decision
                                ? `AI · ${selectedPair.decision.verdict || "Recorded decision"}`
                                : demo
                                  ? "Simulated AI guidance"
                                  : "No recorded AI decision"
                        }}</strong
                        ><span>{{
                            selectedPair.decision?.reason ||
                            (demo
                                ? "Scan → Validate → Size → Execute → Protect"
                                : "This pair has no decision in the latest available scan.")
                        }}</span>
                    </div>
                </div>
                <p>
                    {{
                        selectedPair.live
                            ? "Receiving market quotes."
                            : "No fresh quote. Last available data shown."
                    }}
                    {{ demo ? "This position is simulated." : pnlNote }}
                </p>
                <dl
                    v-if="isFrontPage && selectedPair.decision"
                    class="dx-live-decision"
                >
                    <div>
                        <dt>Decision score</dt>
                        <dd>{{ selectedPair.decision.score ?? "—" }}</dd>
                    </div>
                    <div>
                        <dt>Proposed size</dt>
                        <dd>{{ cash(selectedPair.decision.size_usd) }}</dd>
                    </div>
                </dl>
                <section
                    v-if="isFrontPage && selectedPair.positions?.length"
                    class="dx-live-positions"
                    aria-label="Open positions for this market"
                >
                    <h3>Open positions</h3>
                    <div
                        v-for="position in selectedPair.positions"
                        :key="position.id"
                    >
                        <strong
                            >{{ position.side }}
                            <small>#{{ position.id }}</small></strong
                        >
                        <span
                            >{{ position.quantity }} units @
                            {{ price(position.entry_price) }}</span
                        >
                        <span
                            >Entry notional {{ cash(position.entry_usd) }}</span
                        >
                    </div>
                </section>
                <router-link
                    class="dx-detail-link"
                    :to="`/chart/${selectedPair.id}`"
                    >Open full chart
                    <i
                        class="fa-solid fa-arrow-up-right"
                        aria-hidden="true"
                    ></i></router-link
            ></template>
        </dialog>
    </div>
</template>
