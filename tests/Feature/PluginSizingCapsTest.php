<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Settings;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\Position;
use App\Models\Setting;
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

    /** A rejected write must 422, name the offending key, and leave the settings table untouched. */
    private function assertRejected(string $key, mixed $value, ?string $named = null): void
    {
        $before = Setting::query()->orderBy('key')->pluck('value', 'key')->all();
        $this->putJson('/api/settings', ['key' => $key, 'value' => $value])
            ->assertStatus(422)
            ->assertSee($named ?? $key, false);
        $this->assertSame($before, Setting::query()->orderBy('key')->pluck('value', 'key')->all(), "rejected write to {$key} must not persist");
    }

    public function test_out_of_range_risk_values_are_rejected_and_not_persisted(): void
    {
        config(['cache.default' => 'array']);
        $this->assertRejected('size.kelly_cap_pct', 5);
        $this->assertRejected('size.kelly_cap_pct', '0');
        $this->assertRejected('size.max_open_positions', 0);
        $this->assertRejected('size.max_open_positions', 2.5);
        $this->assertRejected('size.max_leverage', 500);
        $this->assertRejected('per_product.BTC-USD.size.kelly_cap_pct', 2);
        $this->assertRejected('size', ['kelly_cap_pct' => 5], 'size.kelly_cap_pct');
        $this->assertRejected('size', ['max_leverage' => 0], 'size.max_leverage');
        $this->assertRejected('size', ['max_open_positions' => 2.5], 'size.max_open_positions');
        $this->assertRejected('per_product.BTC-USD.size', ['kelly_cap_pct' => 5], 'kelly_cap_pct');
        $this->assertRejected('per_product', ['BTC-USD' => ['size' => ['max_leverage' => 0]]], 'max_leverage');
    }

    public function test_containers_at_or_below_a_risk_key_are_rejected_for_every_key_form(): void
    {
        config(['cache.default' => 'array']);
        foreach (['size.kelly_cap_pct', 'size.max_open_positions', 'size.max_leverage', 'size.min_ticket_usd'] as $risk) {
            $leaf = substr($risk, 5);
            // exact-key writes: array at, empty array at, and empty/scalar below the risk key
            $this->assertRejected($risk, [5], $risk);
            $this->assertRejected($risk, [], $risk);
            $this->assertRejected($risk.'.foo', [], $risk);
            $this->assertRejected($risk.'.foo', 1, $risk);
            $this->assertRejected("per_product.BTC-USD.{$risk}.foo", [], $risk);
            // parent-map writes
            $this->assertRejected('size', [$leaf => [5]], $risk);
            $this->assertRejected('size', [$leaf => []], $risk);
            $this->assertRejected('size', [$leaf => ['foo' => []]], $risk);
            $this->assertRejected('per_product.BTC-USD.size', [$leaf => ['foo' => []]], $risk);
            $this->assertRejected('per_product', ['BTC-USD' => ['size' => [$leaf => ['foo' => []]]]], $risk);
        }
        $this->assertRejected('size', 5, 'size');
    }

    public function test_valid_risk_writes_persist(): void
    {
        config(['cache.default' => 'array']);
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0.04'])->assertOk();
        $this->assertSame(0.04, app(Settings::class)->get('size.kelly_cap_pct'));
        $this->putJson('/api/settings', ['key' => 'size', 'value' => ['max_open_positions' => '2']])->assertOk();
        $this->assertEquals(2, app(Settings::class)->get('size.max_open_positions'));
        $this->putJson('/api/settings', ['key' => 'size.max_open_positions', 'value' => '3'])->assertOk();
        $this->assertEquals(3, app(Settings::class)->get('size.max_open_positions'));
    }

    public function test_a_non_numeric_kelly_cap_falls_back_to_the_config_default(): void
    {
        $this->plugin('cap-bad', ['mode' => 'usd', 'value' => 1e9]);

        $d = $this->sizeFor('cap-bad', new Bank(50_000, 0, 0.0, 0, 0), ['size' => ['kelly_cap_pct' => ['foo' => 1]]]);

        $this->assertEqualsWithDelta(3000.0, $d->dollars, 0.01);
    }
}
