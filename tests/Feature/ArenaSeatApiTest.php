<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArenaSeat;
use App\Models\BankSnapshot;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArenaSeatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function plugin(): StrategyPlugin
    {
        $definition = ['key' => 'rsi-dip', 'name' => 'RSI Dip', 'version' => 1, 'scan' => []];
        $plugin = StrategyPlugin::create(['key' => 'rsi-dip', 'name' => 'RSI Dip', 'definition' => $definition, 'current_version' => '1.0.0']);
        $plugin->versions()->create(['version' => '1.0.0', 'definition' => $definition]);

        return $plugin;
    }

    public function test_a_seat_can_be_created_from_a_strategy_plugin_id(): void
    {
        $plugin = $this->plugin();

        $r = $this->postJson('/api/arena/seats', ['label' => 'Challenger', 'strategy_plugin_id' => $plugin->id, 'cash' => 2000])
            ->assertCreated()
            ->json();

        $this->assertSame('Challenger', $r['label']);
        $this->assertSame($plugin->versions()->first()->id, $r['strategy_plugin_version_id']);
        $this->assertSame(2000.0, (float) $r['starting_cash']);
        $this->assertSame('active', $r['status']);
    }

    public function test_a_seat_can_be_created_from_a_built_in_strategy_key(): void
    {
        $r = $this->postJson('/api/arena/seats', ['label' => 'MR seat', 'strategy_key' => 'mr'])
            ->assertCreated()
            ->json();

        $this->assertSame('mr', $r['strategy_key']);
        $this->assertNull($r['strategy_plugin_version_id']);
        $this->assertSame(1000.0, (float) $r['starting_cash'], 'default starting cash');
    }

    public function test_an_unknown_built_in_strategy_key_is_rejected(): void
    {
        $this->postJson('/api/arena/seats', ['label' => 'Bad', 'strategy_key' => 'not-a-real-strategy'])
            ->assertStatus(422);
    }

    public function test_a_seat_needs_a_strategy(): void
    {
        $this->postJson('/api/arena/seats', ['label' => 'No strategy'])->assertStatus(422);
    }

    public function test_promote_swaps_only_the_champion_flag(): void
    {
        $a = ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'is_champion' => true, 'started_at' => now()]);
        $b = ArenaSeat::create(['label' => 'B', 'strategy_key' => 'custom', 'starting_cash' => 750, 'status' => 'active', 'started_at' => now()]);

        $this->postJson("/api/arena/seats/{$b->id}/promote")->assertOk();

        $a->refresh();
        $b->refresh();
        $this->assertFalse($a->is_champion);
        $this->assertTrue($b->is_champion);
        // Nothing else about either seat moved.
        $this->assertSame('active', $a->status);
        $this->assertSame(1000.0, (float) $a->starting_cash);
        $this->assertSame('B', $b->label);
        $this->assertSame(750.0, (float) $b->starting_cash);
    }

    public function test_retire_marks_the_seat_retired_and_stamps_stopped_at(): void
    {
        $seat = ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        $this->postJson("/api/arena/seats/{$seat->id}/retire")->assertOk()->assertJsonPath('status', 'retired');

        $seat->refresh();
        $this->assertSame('retired', $seat->status);
        $this->assertNotNull($seat->stopped_at);
    }

    public function test_only_a_retired_seat_can_be_deleted(): void
    {
        $seat = ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        $this->deleteJson("/api/arena/seats/{$seat->id}")->assertStatus(422);
        $this->assertDatabaseHas('arena_seats', ['id' => $seat->id]);

        $seat->update(['status' => 'retired']);
        $this->deleteJson("/api/arena/seats/{$seat->id}")->assertOk();
        $this->assertDatabaseMissing('arena_seats', ['id' => $seat->id]);
    }

    public function test_scoreboard_drawdown_is_computed_from_the_equity_snapshot_history(): void
    {
        $seat = ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        // Known equity path: 1000 -> 1200 (peak) -> 900 (trough, -25% off the peak) -> 1100.
        foreach ([1000, 1200, 900, 1100] as $i => $equity) {
            BankSnapshot::create([
                'arena_seat_id' => $seat->id, 'mode' => 'paper', 'cash' => $equity, 'positions_value' => 0,
                'equity' => $equity, 'locked' => 0, 'free_cash' => $equity, 'open_positions' => 0,
                'taken_at' => now()->addMinutes($i),
            ]);
        }

        $r = $this->getJson('/api/arena')->assertOk()->json();
        $row = collect($r['seats'])->firstWhere('id', $seat->id);

        $this->assertEqualsWithDelta(25.0, $row['drawdown_pct'], 0.001);
    }

    public function test_scoreboard_reports_vs_champion_delta(): void
    {
        $champion = ArenaSeat::create(['label' => 'Champ', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'is_champion' => true, 'started_at' => now()]);
        $challenger = ArenaSeat::create(['label' => 'Challenger', 'strategy_key' => 'custom', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        $r = $this->getJson('/api/arena')->assertOk()->json();

        $champRow = collect($r['seats'])->firstWhere('id', $champion->id);
        $challRow = collect($r['seats'])->firstWhere('id', $challenger->id);
        $this->assertNull($champRow['vs_champion_pct'], 'the champion has no delta against itself');
        $this->assertEqualsWithDelta(0.0, $challRow['vs_champion_pct'], 0.0001, 'neither seat has traded yet, so both sit at 0% and the delta is 0');
    }

    public function test_the_old_optimizer_view_still_answers_at_its_new_path(): void
    {
        $this->getJson('/api/arena/optimizer?coin=ETH-USD&side=long')->assertOk()->assertJsonPath('coin', 'ETH-USD');
    }
}
