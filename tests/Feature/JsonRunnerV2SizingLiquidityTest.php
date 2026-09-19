<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2 sizing objects (entry.size: usd/pct_equity/formula, docs/STRATEGY_SCHEMA_V2.md) have no
 * relationship to BaseDeskStrategy's own 6%-of-equity Kelly ceiling — a v1 rail two places relied
 * on that proxy actually approximating the real ticket. Review round 3: VET's liquidity gate
 * measures against intendedTicket(), and SIZE's own liquidity cut never ran for a v2 definition.
 */
class JsonRunnerV2SizingLiquidityTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $entryOverrides = []): StrategyPlugin
    {
        return StrategyPlugin::create([
            'key' => $key,
            'name' => 'V2 liquidity test',
            'definition' => array_replace_recursive([
                'schema_version' => 2,
                'key' => $key,
                'meta' => ['name' => 'V2 liquidity test', 'timeframe' => '1h'],
                'signals' => ['always' => ['all' => [['field' => 'price', 'op' => '>', 'value' => 0]]]],
                'entry' => ['side' => 'long', 'when' => ['always'], 'size' => ['mode' => 'usd', 'value' => 500000]],
            ], ['entry' => $entryOverrides]),
        ]);
    }

    private function ctx(string $key): DeskContext
    {
        return new DeskContext(['json' => ['plugin_key' => $key]], 'paper', false, [], new \DateTimeImmutable('2026-09-19 12:00:00', new \DateTimeZone('UTC')));
    }

    /** $700,000 24h volume -> a 0.5%-of-volume cap of $3,500: comfortably above the base pipeline's own 6%-of-$50k-equity Kelly proxy ($3,000, which VET used to measure against instead of the real ticket) but nowhere near the $500,000 usd-mode ticket this definition actually sizes. */
    private function stats(): ProductStats
    {
        return ProductStats::fromArray([
            'product_id' => 'AAA-USD', 'price' => 10.0,
            'volume_h6_usd' => 200000, 'volume_h24_usd' => 700000,
            'spread_bps' => 5, 'candles_h1_count' => 48, 'age_hours' => 500,
        ]);
    }

    private function candidate(): Candidate
    {
        return new Candidate(stats: $this->stats(), score: 1.0, rankReason: 'test', edgeProbability: 0.55, payoffRatio: 1.5);
    }

    public function test_vet_measures_liquidity_against_the_real_v2_ticket_not_the_kelly_proxy(): void
    {
        $this->plugin('v2-liq-vet');
        $ctx = $this->ctx('v2-liq-vet');
        $bank = new Bank(1_000_000, 0, 0.2, 0, 0);   // equity ~ $50k free of the locked bag -> a $3,000 6% Kelly proxy

        $verdict = (new JsonPluginStrategy)->vet($this->candidate(), $bank, $ctx);

        $this->assertFalse($verdict->passed(), 'a $500,000 ticket against $700,000/day of volume must be rejected on liquidity');
        $this->assertSame('liquidity', $verdict->failedCheck);
    }

    public function test_size_cuts_a_v2_usd_ticket_that_exceeds_the_volume_cap(): void
    {
        $this->plugin('v2-liq-size');
        $ctx = $this->ctx('v2-liq-size');
        $bank = new Bank(1_000_000, 0, 0.2, 0, 0);
        $candidate = $this->candidate();
        $v = Verdict::pass($candidate, ['test'], [], 'ok');

        $d = (new JsonPluginStrategy)->size($v, $bank, $ctx);

        // liqCap = $700,000 x 0.5% = $3,500 — far under the $500,000 the definition asked for.
        // Still above size.min_ticket_usd, so it books cut down to the cap, not booked oversized
        // and not refused outright (BaseDeskStrategy::size()'s own liquidity-cut behaviour).
        $this->assertFalse($d->zero());
        $this->assertEqualsWithDelta(3500.0, $d->dollars, 1.0);
    }
}
