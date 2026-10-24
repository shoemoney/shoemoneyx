<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArenaSeat;
use App\Models\BankSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankHistorySeatScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_history_excludes_arena_seat_snapshots(): void
    {
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        $seat = ArenaSeat::query()->create(['label' => 'S', 'strategy_key' => 'mean_reversion', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $base = ['mode' => 'paper', 'cash' => 100, 'positions_value' => 0, 'locked' => 0, 'free_cash' => 100, 'realised_pnl_today' => 0, 'taken_at' => now()->subHour()];
        BankSnapshot::create($base + ['equity' => 1000, 'arena_seat_id' => null]);
        BankSnapshot::create(array_merge($base, ['equity' => 555, 'arena_seat_id' => $seat->id, 'taken_at' => now()->subMinutes(30)]));

        $rows = $this->getJson('/api/bank/history?hours=24')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertEquals(1000, $rows[0]['equity']);
    }
}
