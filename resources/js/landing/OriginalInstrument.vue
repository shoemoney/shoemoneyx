<script setup>
import { computed } from "vue";
import { cash, clockTime, percent, signedCash, ticker } from "./format";
import { pngUrlFor } from "../coinIcons";
const props = defineProps({
    theme: String,
    pairs: Array,
    events: Array,
    focus: String,
    motion: Boolean,
});
defineEmits(["select", "highlight"]);
const names = {
    pulse: "MARKET PRESSURE",
    neural: "SYNAPTIC ROUTES",
    terminal: "LIVE PACKET BUFFER",
    orbit: "CAPITAL SATELLITES",
    prism: "CAPITAL IN FULL COLOR",
};
const captions = {
    pulse: "24H MOVE · EACH PAIR",
    neural: "LAST RECEIVED SIGNAL · EACH PAIR",
    terminal: "LATEST RECEIVED EVENTS",
    orbit: "OPEN NOTIONAL · RELATIVE SCALE",
    prism: "OPEN NOTIONAL SHARE",
};
const maxMove = computed(() =>
    Math.max(1, ...(props.pairs || []).map((p) => Math.abs(p.change || 0))),
);
const maxNotional = computed(() =>
    Math.max(
        1,
        ...(props.pairs || []).map((p) => Math.max(0, p.notional || 0)),
    ),
);
const totalNotional = computed(() =>
    (props.pairs || []).reduce(
        (sum, p) => sum + Math.max(0, p.notional || 0),
        0,
    ),
);
const receipts = computed(() =>
    Object.fromEntries(
        (props.pairs || []).map((pair) => [
            pair.id,
            (props.events || []).find((event) =>
                event.message?.includes(pair.id),
            ),
        ]),
    ),
);
const bubbleSize = (pair) =>
    `${22 + Math.sqrt(Math.max(0, pair.notional || 0) / maxNotional.value) * 37}px`;
</script>

<template>
    <section
        class="pxo-instrument"
        :class="`pxo-instrument-${theme}`"
        :aria-label="names[theme]"
    >
        <div class="pxo-instrument-label">
            <i class="fa-solid fa-wave-square" aria-hidden="true"></i>
            <div>
                <b>{{ names[theme] }}</b
                ><small>{{ captions[theme] }}</small>
            </div>
            <span>{{
                theme === "terminal"
                    ? String(events.length).padStart(2, "0")
                    : String(pairs.length).padStart(2, "0")
            }}</span>
        </div>
        <div v-if="theme === 'terminal'" class="pxo-packet-buffer">
            <div
                v-for="event in events.slice(0, 3)"
                :key="event.key"
                class="pxo-packet"
            >
                <span class="pxo-packet-bracket">[RX]</span
                ><time>{{ clockTime(event.at) }}</time
                ><b>{{ event.agent }}</b
                ><span>{{ event.message }}</span>
            </div>
            <span v-if="!events.length" class="pxo-awaiting"
                >Waiting for the first received event…</span
            >
        </div>
        <div
            v-else
            class="pxo-instrument-pairs"
            :class="{ 'pxo-has-focus': !!focus }"
        >
            <button
                v-for="(pair, index) in pairs"
                :key="pair.id"
                class="pxo-instrument-pair"
                :class="{
                    'pxo-focused': focus === pair.id,
                    'pxo-receiving': receipts[pair.id]?.key === events[0]?.key,
                    'pxo-negative': pair.change < 0,
                }"
                :style="{
                    '--pair-order': index,
                    '--pair-hue': `${(index * 41 + 175) % 360}deg`,
                    '--move': `${Math.max(3, (Math.abs(pair.change || 0) / maxMove) * 100)}%`,
                    '--bubble': bubbleSize(pair),
                    '--weight':
                        theme === 'prism' && totalNotional
                            ? (Math.max(0, pair.notional || 0) /
                                  totalNotional) *
                              100
                            : 1,
                }"
                @click="$emit('select', pair.id)"
                @pointerenter="$emit('highlight', pair.id)"
                @pointerleave="$emit('highlight', '')"
                @focus="$emit('highlight', pair.id)"
                @blur="$emit('highlight', '')"
                :aria-label="`Inspect ${pair.id}, ${theme === 'pulse' ? percent(pair.change) + ' change' : theme === 'neural' ? 'latest signal ' + (receipts[pair.id]?.agent || 'pending') : cash(pair.notional) + ' open notional'}`"
            >
                <template v-if="theme === 'pulse'"
                    ><span class="pxo-pressure-track"
                        ><i></i><i></i><i></i><i></i></span
                    ><b>{{ ticker(pair.id) }}</b
                    ><small :class="pair.change < 0 ? 'down' : 'up'">{{
                        percent(pair.change)
                    }}</small></template
                >
                <template v-else-if="theme === 'neural'"
                    ><span class="pxo-synapse"
                        ><img
                            v-if="pngUrlFor(pair.id)"
                            :src="pngUrlFor(pair.id)"
                            alt="" /><i
                            v-else
                            class="fa-solid fa-circle-nodes"
                            aria-hidden="true"
                        ></i
                        ><span></span></span
                    ><b>{{ ticker(pair.id) }}</b
                    ><small>{{
                        receipts[pair.id]?.agent || "WAITING"
                    }}</small></template
                >
                <template v-else-if="theme === 'orbit'"
                    ><span class="pxo-satellite"
                        ><img
                            v-if="pngUrlFor(pair.id)"
                            :src="pngUrlFor(pair.id)"
                            alt="" /><i></i></span
                    ><b>{{ ticker(pair.id) }}</b
                    ><small>{{ cash(pair.notional, 0) }}</small></template
                >
                <template v-else
                    ><span class="pxo-glass-facet"></span
                    ><img
                        v-if="pngUrlFor(pair.id)"
                        :src="pngUrlFor(pair.id)"
                        alt=""
                    /><b>{{ ticker(pair.id) }}</b
                    ><small>{{
                        totalNotional
                            ? (
                                  (Math.max(0, pair.notional || 0) /
                                      totalNotional) *
                                  100
                              ).toFixed(1) + "%"
                            : "—"
                    }}</small></template
                >
                <span class="pxo-instrument-tooltip"
                    >{{ ticker(pair.id) }} ·
                    {{ signedCash(pair.pnl) }} P&L</span
                >
            </button>
            <span v-if="!pairs.length" class="pxo-awaiting"
                >Your market instruments appear with the first snapshot.</span
            >
        </div>
    </section>
</template>
