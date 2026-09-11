<script setup>
import { fmt } from "../api";
import VerdictBadge from "./VerdictBadge.vue";
defineProps({ candidates: { type: Array, default: () => [] } });
</script>
<template>
    <div class="overflow-x-auto">
        <table class="grid">
            <thead>
                <tr>
                    <th>#</th>
                    <th>product</th>
                    <th>score</th>
                    <th>SCAN reason</th>
                    <th>h1 surge</th>
                    <th>buy share</th>
                    <th>Δ1h</th>
                    <th>spread</th>
                    <th>VET</th>
                    <th>check / why</th>
                    <th>SIZE</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in candidates" :key="c.id">
                    <td class="num text-zinc-500">{{ c.rank }}</td>
                    <td>
                        <router-link
                            :to="`/chart/${c.product_id}`"
                            class="font-semibold hover:text-sky-300"
                            >{{ c.product_id }}</router-link
                        >
                    </td>
                    <td class="num">{{ fmt.num(c.score, 3) }}</td>
                    <td class="max-w-64 text-zinc-400">{{ c.rank_reason }}</td>
                    <td class="num">
                        {{
                            c.metrics?.volume_surge_h1 != null
                                ? fmt.num(c.metrics.volume_surge_h1, 2) + "x"
                                : "—"
                        }}
                    </td>
                    <td class="num">
                        {{
                            c.metrics && c.metrics.buys_h1 + c.metrics.sells_h1
                                ? Math.round(
                                      (c.metrics.buys_h1 /
                                          (c.metrics.buys_h1 +
                                              c.metrics.sells_h1)) *
                                          100,
                                  ) + "%"
                                : "—"
                        }}
                    </td>
                    <td
                        class="num"
                        :class="
                            (c.metrics?.price_change_h1_pct ?? 0) >= 0
                                ? 'text-emerald-400'
                                : 'text-red-400'
                        "
                    >
                        {{ fmt.pct(c.metrics?.price_change_h1_pct) }}
                    </td>
                    <td class="num">
                        {{
                            c.metrics?.spread_bps != null
                                ? c.metrics.spread_bps + "bps"
                                : "—"
                        }}
                    </td>
                    <td><VerdictBadge :verdict="c.verdict" /></td>
                    <td class="max-w-80 text-zinc-400">
                        <span
                            v-if="c.failed_check"
                            class="font-mono text-red-300"
                            >[{{ c.failed_check }}]</span
                        >
                        {{ c.why }}
                    </td>
                    <td class="num">
                        <template v-if="c.size_usd > 0"
                            >{{ fmt.usd(c.size_usd) }}
                            <span class="text-zinc-500"
                                >({{ fmt.num(c.pct_of_bank, 1) }}% bank<span
                                    v-if="c.ceiling_applied"
                                    title="Kelly cap applied"
                                    >, cap</span
                                >)</span
                            ></template
                        >
                        <span
                            v-else-if="c.size_why"
                            class="text-zinc-500"
                            :title="c.size_why"
                            >0</span
                        >
                        <span v-else class="text-zinc-600">—</span>
                    </td>
                </tr>
                <tr v-if="!candidates.length">
                    <td colspan="11" class="py-6 text-center text-zinc-600">
                        no candidates — an empty list is a valid output and a
                        normal one
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
