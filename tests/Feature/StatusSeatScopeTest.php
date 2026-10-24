<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArenaSeat;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatusSeatScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake(['api.coinbase.com/*' => Http::response(['candles' => []])]);
    }

    private function position(?int $seatId, string $status, float $pnl = 0.0): Position
    {
        return Position::create([
            'arena_seat_id' => $seatId, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'BTC-USD',
            'status' => $status, 'side' => 'long', 'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100,
            'pnl_usd' => $status === 'closed' ? $pnl : null, 'opened_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
        ]);
    }

    public function test_status_counts_exclude_arena_seat_positions(): void
    {
        $seat = ArenaSeat::create(['label' => 'S', 'strategy_key' => 'mean_reversion', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        $this->position(null, 'open');
        $this->position(null, 'closed', 10.0);
        $this->position($seat->id, 'open');
        $this->position($seat->id, 'closed', -50.0);

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('open_positions', 1)
            ->assertJsonPath('closed_today', 1)
            ->assertJsonPath('realised_today', 10)
            ->assertJsonPath('realised_total', 10);
    }
}
