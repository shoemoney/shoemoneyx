<script setup>
import { computed } from "vue";

const props = defineProps({
    design: String,
    pairs: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    beat: Number,
    direction: Number,
});
const levels = computed(() => {
    const maximum = Math.max(
        1,
        ...props.pairs.map((pair) => Math.abs(pair.pnl || 0)),
    );
    return props.pairs.map((pair, index) => ({
        id: pair.id,
        index,
        height: Math.max(2, (Math.abs(pair.pnl || 0) / maximum) * 64),
        negative: pair.pnl < 0,
        known: pair.pnl != null,
        notional: Math.max(
            2,
            ((pair.notional || 0) /
                Math.max(1, ...props.pairs.map((p) => p.notional || 0))) *
                75,
        ),
    }));
});
const ratio = computed(() =>
    props.pairs.length
        ? props.pairs.filter((pair) => pair.pnl > 0).length / props.pairs.length
        : 0,
);
const historyPath = computed(() => {
    const values = props.history
        .filter((value) => Number.isFinite(value))
        .slice(-36);
    if (values.length < 2) return "";
    const low = Math.min(...values),
        spread = Math.max(1, Math.max(...values) - low);
    return values
        .map(
            (value, index) =>
                `${index ? "L" : "M"}${(index / (values.length - 1)) * 280},${88 - ((value - low) / spread) * 56}`,
        )
        .join(" ");
});
</script>

<template>
    <div
        class="dx-pnl-instrument"
        :class="{ 'dx-instrument-down': direction < 0 }"
        aria-hidden="true"
    >
        <svg viewBox="0 0 300 120" preserveAspectRatio="xMidYMid slice">
            <g v-if="design === 'supernova'" class="dx-instrument-nova">
                <circle cx="224" cy="62" r="43" class="dx-instrument-outline" />
                <circle
                    cx="224"
                    cy="62"
                    r="30"
                    class="dx-instrument-outline"
                    stroke-dasharray="2 7"
                />
                <path
                    v-for="n in 32"
                    :key="n"
                    d="M224 6V18"
                    :transform="`rotate(${n * 11.25} 224 62)`"
                    :opacity="n <= ratio * 32 ? 0.9 : 0.12"
                />
                <circle
                    :key="beat"
                    cx="224"
                    cy="62"
                    r="12"
                    class="dx-instrument-shock"
                />
                <path
                    d="M0 64H184M224 100V120M267 62H300"
                    class="dx-instrument-outline"
                />
            </g>
            <g v-else-if="design === 'neon'" class="dx-instrument-hologram">
                <path
                    :d="historyPath"
                    class="dx-instrument-history dx-holo-echo"
                />
                <path :d="historyPath" class="dx-instrument-history" />
                <path
                    v-for="n in 10"
                    :key="n"
                    :d="`M0 ${n * 11}H300`"
                    class="dx-instrument-grid"
                />
                <path :key="beat" d="M270 0V120" class="dx-instrument-scan" />
                <path
                    d="M210 8H285V36M285 89V110H210"
                    class="dx-instrument-outline"
                />
            </g>
            <g v-else-if="design === 'reactor'" class="dx-instrument-turbine">
                <path
                    v-for="n in 24"
                    :key="n"
                    d="M204 17L211 29L217 19"
                    :transform="`rotate(${n * 15} 225 60)`"
                    :opacity="n <= ratio * 24 ? 1 : 0.13"
                />
                <circle
                    cx="225"
                    cy="60"
                    r="33"
                    class="dx-instrument-outline"
                    stroke-dasharray="50 9 3 9"
                />
                <path
                    :key="beat"
                    d="M225 60L225 34"
                    :transform="`rotate(${-135 + ratio * 270} 225 60)`"
                    class="dx-instrument-needle"
                />
                <path
                    d="M0 91H155L179 67H190M0 101H166L189 78H199"
                    class="dx-instrument-outline"
                />
                <circle cx="225" cy="60" r="4" class="dx-instrument-solid" />
            </g>
            <g v-else-if="design === 'liquid'" class="dx-instrument-liquid">
                <path
                    v-for="n in 5"
                    :key="n"
                    :d="`${historyPath} L280 120L0 120Z`"
                    :transform="`translate(0 ${n * 6})`"
                    :opacity="0.12 + n * 0.04"
                    class="dx-instrument-wave"
                />
                <ellipse
                    :key="beat"
                    cx="235"
                    cy="58"
                    rx="28"
                    ry="9"
                    class="dx-instrument-ripple"
                />
                <path :d="historyPath" class="dx-instrument-history" />
            </g>
            <g v-else-if="design === 'citadel'" class="dx-instrument-city">
                <path
                    v-for="n in 6"
                    :key="n"
                    :d="`M${n * 45} 100L${n * 45 - 36} 120M0 ${n * 17}H300`"
                    class="dx-instrument-grid"
                />
                <g
                    v-for="level in levels"
                    :key="level.id"
                    :style="{ '--column': level.index }"
                    class="dx-instrument-building"
                    :opacity="level.known ? 0.85 : 0.2"
                >
                    <rect
                        :x="level.index * 15 + 12"
                        :y="104 - level.notional"
                        width="9"
                        :height="level.notional"
                        :class="{ 'dx-instrument-loss': level.negative }"
                    />
                    <path
                        :d="`M${level.index * 15 + 21} ${104 - level.notional}l5 -5v${level.notional}l-5 5z`"
                        class="dx-instrument-facet"
                    />
                </g>
            </g>
            <g v-else-if="design === 'redline'" class="dx-instrument-revs">
                <path
                    d="M160 111A87 87 0 0 1 294 23"
                    class="dx-instrument-outline"
                />
                <path
                    v-for="n in 18"
                    :key="n"
                    d="M159 101L172 103"
                    :transform="`rotate(${n * 7.4} 246 101)`"
                    :opacity="n <= ratio * 18 ? 0.9 : 0.15"
                    stroke-width="6"
                />
                <path
                    d="M0 98H128L150 77M0 106H127"
                    class="dx-instrument-outline"
                />
                <path
                    :key="beat"
                    :d="`M246 101L${246 + Math.cos(Math.PI + ratio * 2.35) * 72} ${101 + Math.sin(Math.PI + ratio * 2.35) * 72}`"
                    class="dx-instrument-needle"
                />
                <circle cx="246" cy="101" r="5" class="dx-instrument-solid" />
            </g>
            <g v-else-if="design === 'synapse'" class="dx-instrument-cortex">
                <g
                    v-for="level in levels"
                    :key="level.id"
                    :style="{ '--column': level.index }"
                    class="dx-instrument-nerve"
                >
                    <path
                        :d="`M153 60Q${level.index % 2 ? 208 : 115} ${level.index * 6} ${level.index % 2 ? 272 : 62} ${level.index * 6 + 8}`"
                        :opacity="0.15 + level.height / 95"
                    />
                    <circle
                        :cx="level.index % 2 ? 272 : 62"
                        :cy="level.index * 6 + 8"
                        :r="1 + level.height / 22"
                        :class="
                            level.negative
                                ? 'dx-instrument-loss'
                                : 'dx-instrument-solid'
                        "
                    />
                </g>
                <circle
                    :key="beat"
                    cx="153"
                    cy="60"
                    r="14"
                    class="dx-instrument-shock"
                />
            </g>
            <g v-else-if="design === 'spectrum'" class="dx-instrument-prism">
                <path
                    v-for="level in levels"
                    :key="level.id"
                    :d="`M130 106L${130 + level.index * 10} ${12 + 50 - level.height / 1.5}L${138 + level.index * 10} ${15 + 50 - level.height / 1.5}Z`"
                    :style="{
                        color: `hsl(${level.index * 19} 80% 58%)`,
                        '--column': level.index,
                    }"
                    class="dx-instrument-fan"
                />
                <path
                    d="M28 115L140 11L192 115Z"
                    class="dx-instrument-outline"
                />
                <path
                    :key="beat"
                    d="M0 86L140 11L300 89"
                    class="dx-instrument-scan"
                />
            </g>
            <g v-else-if="design === 'horizon'" class="dx-instrument-well">
                <ellipse
                    v-for="n in 7"
                    :key="n"
                    cx="220"
                    cy="58"
                    :rx="n * 15"
                    :ry="n * 6"
                    :transform="`rotate(-23 220 58)`"
                    class="dx-instrument-outline"
                    :opacity="n / 10"
                />
                <ellipse
                    :key="beat"
                    cx="220"
                    cy="58"
                    rx="80"
                    ry="32"
                    transform="rotate(-23 220 58)"
                    class="dx-instrument-infall"
                />
                <circle
                    cx="220"
                    cy="58"
                    r="17"
                    class="dx-instrument-event-core"
                />
                <path
                    :d="historyPath"
                    class="dx-instrument-history"
                    opacity=".5"
                />
            </g>
            <g v-else class="dx-instrument-packets">
                <g
                    v-for="level in levels"
                    :key="level.id"
                    :style="{ '--column': level.index }"
                >
                    <rect
                        v-for="row in 8"
                        :key="row"
                        :x="level.index * 15 + 12"
                        :y="108 - row * 11"
                        width="10"
                        height="7"
                        :opacity="row * 8 <= level.height ? 0.85 : 0.1"
                        :class="
                            level.negative
                                ? 'dx-instrument-loss'
                                : 'dx-instrument-solid'
                        "
                    />
                </g>
                <path
                    :key="beat"
                    d="M0 12H300"
                    class="dx-instrument-packet-scan"
                />
            </g>
        </svg>
    </div>
</template>
