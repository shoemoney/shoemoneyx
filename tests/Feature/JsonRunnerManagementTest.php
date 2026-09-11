<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\Position;
use App\Models\StrategyPlugin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * management.trailing / management.partials / management.adds, wired into
 * JsonPluginStrategy::risk() (docs/STRATEGY_SCHEMA.md). Canned-tape style: build one
 * position + one stats row per assertion and check the literal RiskDecision.
 */
class JsonRunnerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $management): StrategyPlugin
    {
        return StrategyPlugin::create([
            'key' => $key,
            'name' => 'Management test',
            'definition' => [
                'schema_version' => 1,
                'key' => $key,
                'meta' => ['name' => 'Management test'],
                'entry' => ['side' => 'long'],
                'management' => $management,
            ],
        ]);
    }

    private function ctx(string $key, array $open = []): DeskContext
    {
        return new DeskContext(
            ['json' => ['plugin_key' => $key]],
            'paper',
            false,
            $open,
            new \DateTimeImmutable('2026-09-11 12:00:00', new \DateTimeZone('UTC')),
        );
    }

    private function stats(float $price): ProductStats
    {
        // Healthy volume so nothing falls through to the generic volume_dry rail by accident.
        return ProductStats::fromArray([
            'product_id' => 'TEST-USD', 'price' => $price,
            'volume_h6_usd' => 400000, 'volume_h24_usd' => 1200000,
        ]);
    }

    private function position(array $attrs): Position
    {
        $p = Position::create($attrs + [
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'TEST-USD', 'status' => 'open',
            'quantity' => 10, 'entry_price' => 10, 'entry_usd' => 100, 'peak_price' => 10, 'last_price' => 10,
            'opened_at' => Carbon::parse('2026-09-11 10:00:00'),
        ]);

        return $p->fresh();
    }

    // -----------------------------------------------------------------
    // trailing
    // -----------------------------------------------------------------

    public function test_trailing_holds_before_activation(): void
    {
        $this->plugin('trail-1', ['trailing' => ['activate_pct' => 6, 'trail_pct' => 2.5]]);
        $p = $this->position(['peak_price' => 10.4]); // +4%, under the 6% activation line
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.4), $this->ctx('trail-1', [$p]));

        $this->assertFalse($d->shouldClose());
    }

    public function test_trailing_closes_once_giveback_from_peak_exceeds_trail_pct(): void
    {
        $this->plugin('trail-2', ['trailing' => ['activate_pct' => 6, 'trail_pct' => 2.5]]);
        // Peaked at +8% (past the 6% activation line), now back to +5% — 3 points of giveback > 2.5.
        $p = $this->position(['peak_price' => 10.8]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.5), $this->ctx('trail-2', [$p]));

        $this->assertTrue($d->shouldClose());
        $this->assertSame('management.trailing', $d->ruleFired);
    }

    public function test_trailing_off_when_omitted(): void
    {
        $this->plugin('trail-3', []);
        $p = $this->position(['peak_price' => 20.0]); // +100% peak, way past any activation line
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.0), $this->ctx('trail-3', [$p]));

        $this->assertNotSame('management.trailing', $d->ruleFired);
    }

    // -----------------------------------------------------------------
    // partials
    // -----------------------------------------------------------------

    private function partialsDef(): array
    {
        return ['partials' => [
            ['pct' => 5, 'fraction' => 0.25],
            ['pct' => 10, 'fraction' => 0.5],
        ]];
    }

    public function test_first_partial_rung_holds_below_its_threshold(): void
    {
        $this->plugin('partials-1', $this->partialsDef());
        $p = $this->position(['trims_count' => 0]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.4), $this->ctx('partials-1', [$p])); // +4% < 5%

        $this->assertFalse($d->shouldTrim());
    }

    public function test_first_partial_rung_fires_at_its_threshold(): void
    {
        $this->plugin('partials-2', $this->partialsDef());
        $p = $this->position(['trims_count' => 0]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.5), $this->ctx('partials-2', [$p])); // +5%

        $this->assertTrue($d->shouldTrim());
        $this->assertSame('management.partials.0', $d->ruleFired);
        $this->assertEqualsWithDelta(0.25, $d->fraction, 1e-9);
    }

    public function test_second_partial_rung_is_checked_once_the_first_has_fired(): void
    {
        $this->plugin('partials-3', $this->partialsDef());
        $p = $this->position(['trims_count' => 1]); // rung 0 already fired
        $ctx = $this->ctx('partials-3', [$p]);

        // Still under rung 1's 10% line: holds, even though it clears rung 0's 5% line.
        $hold = (new JsonPluginStrategy)->risk($p, $this->stats(10.7), $ctx);
        $this->assertFalse($hold->shouldTrim());

        $fire = (new JsonPluginStrategy)->risk($p, $this->stats(11.0), $ctx); // +10%
        $this->assertTrue($fire->shouldTrim());
        $this->assertSame('management.partials.1', $fire->ruleFired);
        $this->assertEqualsWithDelta(0.5, $fire->fraction, 1e-9);
    }

    public function test_no_rung_left_once_every_partial_has_fired(): void
    {
        $this->plugin('partials-4', $this->partialsDef());
        $p = $this->position(['trims_count' => 2]); // both rungs already fired
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(50.0), $this->ctx('partials-4', [$p])); // deep in profit

        $this->assertFalse($d->shouldTrim());
    }

    // -----------------------------------------------------------------
    // adds
    // -----------------------------------------------------------------

    private function addsDef(): array
    {
        return ['adds' => [
            ['trigger' => ['field' => 'position.pnl_pct', 'op' => '>=', 'value' => 4], 'size_pct' => 50],
        ]];
    }

    public function test_add_rung_holds_before_its_trigger(): void
    {
        $this->plugin('adds-1', $this->addsDef());
        $p = $this->position(['adds_count' => 0]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.3), $this->ctx('adds-1', [$p])); // +3% < 4%

        $this->assertFalse($d->shouldAdd());
    }

    public function test_add_rung_fires_at_its_trigger_sized_off_initial_cost(): void
    {
        $this->plugin('adds-2', $this->addsDef());
        $p = $this->position(['adds_count' => 0, 'meta' => ['initial_cost_usd' => 100.0]]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(10.5), $this->ctx('adds-2', [$p])); // +5%

        $this->assertTrue($d->shouldAdd());
        $this->assertSame('management.adds.0', $d->ruleFired);
        $this->assertEqualsWithDelta(50.0, $d->dollars, 1e-9); // 50% of the $100 initial cost, not the current $100 entry_usd
    }

    public function test_add_rung_sizes_off_initial_cost_not_a_grown_entry_usd(): void
    {
        $this->plugin('adds-3', $this->addsDef());
        // entry_usd has grown to $250 from an earlier add, but initial_cost_usd (never overwritten) is still $100.
        // The trigger itself reads position.pnl_pct against the CURRENT (grown) entry_usd — price 26.0 is
        // exactly +4% against $250 (10 qty x 26.0 = 260, up $10 on $250 = 4%).
        $p = $this->position(['adds_count' => 0, 'entry_usd' => 250, 'meta' => ['initial_cost_usd' => 100.0]]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(26.0), $this->ctx('adds-3', [$p]));

        $this->assertTrue($d->shouldAdd());
        $this->assertEqualsWithDelta(50.0, $d->dollars, 1e-9);
    }

    public function test_no_further_add_once_the_max_adds_cap_is_reached(): void
    {
        $this->plugin('adds-4', $this->addsDef()); // one rung declared: cap is 1
        $p = $this->position(['adds_count' => 1, 'meta' => ['initial_cost_usd' => 100.0]]);
        $d = (new JsonPluginStrategy)->risk($p, $this->stats(50.0), $this->ctx('adds-4', [$p])); // way past the trigger

        $this->assertFalse($d->shouldAdd());
    }
}
