<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\Position;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The desk's size.kelly_cap_pct / max_leverage / max_open_positions outrank whatever a plugin asks for. */
class PluginSizingCapsTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $size, array $risk = []): void
    {
        StrategyPlugin::create([
            'key' => $key,
            'name' => 'caps test',
            'definition' => [
                'schema_version' => 2,
                'key' => $key,
                'meta' => ['name' => 'caps test', 'timeframe' => '1h'],
                'signals' => ['always' => ['all' => [['field' => 'price', 'op' => '>', 'value' => 0]]]],
                'entry' => ['side' => 'long', 'when' => ['always'], 'size' => $size],
                'risk' => $risk,
            ],
        ]);
    }

    private function sizeFor(string $key, Bank $bank, array $params = [], array $open = []): SizeDecision
    {
        $ctx = new DeskContext(['json' => ['plugin_key' => $key]] + $params, 'paper', false, $open, new \DateTimeImmutable('2026-09-19 12:00:00', new \DateTimeZone('UTC')));
        $stats = ProductStats::fromArray([
            'product_id' => 'AAA-USD', 'price' => 10.0,
            'volume_h6_usd' => 5e9, 'volume_h24_usd' => 2e10,
            'spread_bps' => 5, 'candles_h1_count' => 48, 'age_hours' => 500,
        ]);
        $c = new Candidate(stats: $stats, score: 1.0, rankReason: 'test', edgeProbability: 0.55, payoffRatio: 1.5);

        return (new JsonPluginStrategy)->size(Verdict::pass($c, ['test'], [], 'ok'), $bank, $ctx);
    }

    public function test_usd_mode_billion_dollar_ticket_is_clamped_to_kelly_cap_of_equity(): void
    {
        $this->plugin('cap-usd', ['mode' => 'usd', 'value' => 1e9]);
        $bank = new Bank(50_000, 0, 0.0, 0, 0);

        $d = $this->sizeFor('cap-usd', $bank);

        $this->assertFalse($d->zero());
        $this->assertLessThanOrEqual($bank->equity() * 0.06 + 0.01, $d->dollars);
        $this->assertEqualsWithDelta(3000.0, $d->dollars, 0.01);
    }

    public function test_pct_equity_100_is_clamped(): void
    {
        $this->plugin('cap-pct', ['mode' => 'pct_equity', 'value' => 100]);
        $bank = new Bank(50_000, 0, 0.0, 0, 0);

        $d = $this->sizeFor('cap-pct', $bank);

        $this->assertEqualsWithDelta(3000.0, $d->dollars, 0.01);
    }

    public function test_a_tightened_desk_cap_is_honoured(): void
    {
        $this->plugin('cap-tight', ['mode' => 'usd', 'value' => 1e9]);
        $bank = new Bank(50_000, 0, 0.0, 0, 0);

        $d = $this->sizeFor('cap-tight', $bank, ['size' => ['kelly_cap_pct' => 0.01]]);

        $this->assertEqualsWithDelta(500.0, $d->dollars, 0.01);
    }

    public function test_plugin_without_leverage_cap_gets_the_desk_leverage_ceiling(): void
    {
        $this->plugin('cap-lev', ['mode' => 'usd', 'value' => 1e9]);
        // equity 50k, already 149,000 notional open: 3x ceiling leaves only $1,000 of room.
        $bank = new Bank(50_000, 0, 0.0, 0, 1, 0, 0, 0, 149_000);

        $d = $this->sizeFor('cap-lev', $bank);

        $this->assertEqualsWithDelta(1000.0, $d->dollars, 0.01);
    }

    public function test_plugin_cannot_loosen_the_desk_position_cap(): void
    {
        $this->plugin('cap-pos', ['mode' => 'usd', 'value' => 100], ['max_positions' => 50]);
        $open = [];
        for ($i = 0; $i < 2; $i++) {
            $open["P$i-USD"] = new Position(['product_id' => "P$i-USD"]);
        }

        $d = $this->sizeFor('cap-pos', new Bank(50_000, 0, 0.0, 0, 2), ['size' => ['max_open_positions' => 2]], $open);

        $this->assertTrue($d->zero());
    }

    public function test_kelly_cap_out_of_range_via_api_is_422(): void
    {
        config(['cache.default' => 'array']);
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => 5])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0'])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'size.max_open_positions', 'value' => 0])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'size.max_leverage', 'value' => 500])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'per_product.BTC-USD.size.kelly_cap_pct', 'value' => 2])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0.04'])->assertOk();
    }
}
