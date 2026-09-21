<script setup>
import { computed } from "vue";
import { pngUrlFor } from "../coinIcons";
import { price, signedCash, cash, ticker } from "./format";
import Sparkline from "./Sparkline.vue";
const props = defineProps({ pair: Object, design: Object });
const icon = computed(() => pngUrlFor(props.pair.id));
const names = {
    supernova: "PLASMA TARGET",
    neon: "HOLOGRAPHIC PROJECTION",
    reactor: "INSTRUMENT LOCK",
    liquid: "MARKET REFRACTION",
    citadel: "CAPITAL BLUEPRINT",
    redline: "TELEMETRY LOCK",
    synapse: "SYNAPTIC FOCUS",
    spectrum: "PRISMATIC LOUPE",
    horizon: "GRAVITATIONAL FOCUS",
    overdrive: "TERMINAL HOTLINK",
};
</script>

<template>
    <div class="dx-market-lens" :class="`dx-lens-${design.id}`">
        <div class="dx-lens-orbits" aria-hidden="true">
            <i></i><i></i><i></i><i></i>
        </div>
        <div class="dx-lens-panel" :key="pair.id">
            <div class="dx-lens-label">
                <span>{{ names[design.id] }}</span
                ><span>{{
                    pair.live ? "QUOTE RECEIVED" : "LAST AVAILABLE"
                }}</span>
            </div>
            <div class="dx-lens-identity">
                <img
                    v-if="icon"
                    :src="icon"
                    alt=""
                    width="35"
                    height="35"
                /><strong>{{ ticker(pair.id) }}<small>/ USD</small></strong
                ><em>{{ pair.side || "WATCH" }}</em>
            </div>
            <div class="dx-lens-price">
                {{ price(pair.price)
                }}<strong :class="pair.pnl < 0 ? 'dx-down' : 'dx-up'"
                    >{{ signedCash(pair.pnl) }}<small>P&L</small></strong
                >
            </div>
            <Sparkline class="dx-lens-chart" :values="pair.history" area />
            <div class="dx-lens-breakdown">
                <span
                    >REALISED<b>{{ cash(pair.realised) }}</b></span
                ><span
                    >UNREALISED<b>{{ cash(pair.unrealised) }}</b></span
                >
            </div>
            <div class="dx-lens-hint">
                <i class="fa-solid fa-brain" aria-hidden="true"></i>
                CONNECTED TO AI <span>CLICK / ENTER TO INSPECT ↗</span>
            </div>
        </div>
    </div>
</template>
