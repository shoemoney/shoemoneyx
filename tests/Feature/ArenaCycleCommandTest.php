<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArenaSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArenaCycleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    public function test_it_reports_no_active_seats_when_the_arena_is_empty(): void
    {
        $this->artisan('arena:cycle')
            ->expectsOutputToContain('no active arena seats')
            ->assertSuccessful();
    }

    public function test_it_runs_one_pass_per_active_seat(): void
    {
        ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        ArenaSeat::create(['label' => 'B', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'retired', 'started_at' => now()]);

        // No products/candles seeded — the point here is that exactly one seat (the active one)
        // gets a cycle, not that it finds a trade; DeskCycleTest-style suites cover fills.
        $this->artisan('arena:cycle')->assertSuccessful();
        $this->assertSame(1, \App\Models\DeskRun::count());
    }
}
