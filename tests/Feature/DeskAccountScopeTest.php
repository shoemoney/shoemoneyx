<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArenaSeat;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake(['*' => Http::response(['products' => []])]);
    }

    public function test_main_lists_and_explicit_seat_queries_keep_accounts_and_modes_separate(): void
    {
        $seatA = $this->seat('A');
        $seatB = $this->seat('B');
        $main = $this->activity(null, 'paper', now()->subMinutes(2));
        $a = $this->activity($seatA->id, 'paper', now()->subMinute());
        $this->activity($seatB->id, 'paper', now());
        $live = $this->activity(null, 'live', now()->addMinute());

        foreach (['positions', 'runs', 'candidates', 'fills'] as $resource) {
            $path = $resource === 'positions' ? '0.id' : 'data.0.id';
            $countPath = $resource === 'positions' ? null : 'data';
            $this->getJson('/api/'.$resource)->assertOk()->assertJsonCount(1, $countPath)->assertJsonPath($path, $main[$resource]);
            $this->getJson('/api/'.$resource.'?arena_seat_id='.$seatA->id)->assertOk()
                ->assertJsonCount(1, $countPath)->assertJsonPath($path, $a[$resource]);
            $this->getJson('/api/'.$resource.'?mode=live')->assertOk()
                ->assertJsonCount(1, $countPath)->assertJsonPath($path, $live[$resource]);
        }
        $this->getJson('/api/runs/latest')->assertOk()->assertJsonPath('id', $main['runs']);
        $this->getJson('/api/runs/latest?arena_seat_id='.$seatA->id)->assertOk()->assertJsonPath('id', $a['runs']);
        $this->getJson('/api/status')->assertOk()->assertJsonPath('open_positions', 1)->assertJsonPath('last_run.id', $main['runs']);
    }

    public function test_seat_selector_rejects_invalid_and_unknown_seats(): void
    {
        foreach (['positions', 'runs', 'runs/latest', 'candidates', 'fills'] as $resource) {
            foreach (['-1', '0', 'all', '1.5', '999999'] as $seatId) {
                $this->getJson('/api/'.$resource.'?arena_seat_id='.$seatId)->assertUnprocessable()
                    ->assertJsonValidationErrors('arena_seat_id');
            }
        }
    }

    private function seat(string $label): ArenaSeat
    {
        return ArenaSeat::create(['label' => $label, 'strategy_key' => 'mr', 'starting_cash' => 1000,
            'status' => 'active', 'started_at' => now()]);
    }

    private function activity(?int $seatId, string $mode, $startedAt): array
    {
        $run = DeskRun::create(['arena_seat_id' => $seatId, 'mode' => $mode, 'strategy' => 'mr', 'started_at' => $startedAt]);
        $candidate = Candidate::create(['desk_run_id' => $run->id, 'product_id' => 'BTC-USD', 'rank' => 1,
            'rank_reason' => 'scope fixture', 'metrics' => [], 'verdict' => 'PASS']);
        $position = Position::create(['arena_seat_id' => $seatId, 'mode' => $mode, 'strategy' => 'mr',
            'product_id' => 'BTC-USD', 'status' => 'open', 'side' => 'long', 'quantity' => 1,
            'entry_price' => 100, 'last_price' => 100, 'entry_usd' => 100, 'opened_at' => $startedAt]);
        $fill = Fill::create(['arena_seat_id' => $seatId, 'desk_run_id' => $run->id, 'position_id' => $position->id,
            'mode' => $mode, 'product_id' => 'BTC-USD', 'side' => 'BUY', 'kind' => 'entry', 'status' => 'filled',
            'requested_usd' => 100, 'filled_usd' => 100, 'decision_price' => 100, 'fill_price' => 100]);

        return ['runs' => $run->id, 'candidates' => $candidate->id, 'positions' => $position->id, 'fills' => $fill->id];
    }
}
