<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankSnapshot;
use App\Models\Candidate;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\OptimizerRound;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\LiveFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LandingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper',
            'desk.perps.enabled' => true, 'desk.perps.active' => ['BTC-USD', 'ETH-USD']]);
        Http::preventStrayRequests();
        $this->mock(LiveFeed::class, function ($mock) {
            $mock->shouldReceive('alive')->andReturn(true);
            $mock->shouldReceive('quote')->with('BTC-USD')->andReturn(['price' => 110.0, 'chg24' => 2.0, 'vol24' => 1000, 'ts' => time()]);
            $mock->shouldReceive('quote')->with('ETH-USD')->andReturn(null);
        });
    }

    private function position(array $attrs = []): Position
    {
        return Position::create(array_replace(['mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD',
            'status' => 'open', 'side' => 'long', 'quantity' => 2, 'entry_price' => 100, 'entry_usd' => 200,
            'realised_usd' => 0, 'opened_at' => now()], $attrs));
    }

    public function test_mode_isolation_side_aware_marks_and_partial_realisation(): void
    {
        $this->position(['realised_usd' => 5]); // +20 open, +5 already banked
        $this->position(['side' => 'short', 'quantity' => 1, 'entry_usd' => 100]); // -10 open
        $this->position(['status' => 'closed', 'pnl_usd' => 30, 'closed_at' => now()]);
        $this->position(['mode' => 'live', 'quantity' => 100, 'realised_usd' => 5000]);
        $this->position(['mode' => 'live', 'status' => 'closed', 'pnl_usd' => 5000]);

        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('mode', 'paper')->assertJsonPath('metrics.pnl', 45)
            ->assertJsonPath('metrics.realised', 35)->assertJsonPath('metrics.unrealised', 10)
            ->assertJsonPath('metrics.open', 2)->assertJsonPath('metrics.trades', 1)
            ->assertJsonPath('metrics.win_rate', 100)->assertJsonPath('metrics.notional', 330)
            ->assertJsonPath('pairs.0.side', 'hedged')->assertJsonPath('pairs.0.live', true)
            ->assertJsonPath('pairs.1.price', null)->assertJsonPath('pairs.1.pnl', 0);
        Http::assertNothingSent();
    }

    public function test_cached_quotes_are_labelled_and_unknown_marks_do_not_fabricate_pnl(): void
    {
        Product::create(['product_id' => 'ETH-USD', 'base_currency' => 'ETH', 'quote_currency' => 'USD', 'price' => 90]);
        $this->position(['product_id' => 'ETH-USD']);
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('pairs.1.live', false)
            ->assertJsonPath('pairs.1.price', 90)->assertJsonPath('pairs.1.pnl', -20);
        Product::where('product_id', 'ETH-USD')->delete();
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('pairs.1.price', null)
            ->assertJsonPath('metrics.pnl', null)->assertJsonPath('metrics.unrealised', null);
    }

    public function test_all_open_pairs_are_included_without_a_hundred_position_limit(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->position();
        }
        $this->mock(LiveFeed::class, function ($mock) {
            $mock->shouldReceive('alive')->andReturn(false);
            $mock->shouldReceive('quote')->andReturn(null);
        });
        $this->position(['product_id' => 'SOL-USD', 'last_price' => 120]);
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('metrics.open', 106)
            ->assertJsonCount(3, 'pairs')->assertJsonPath('pairs.2.id', 'SOL-USD')
            ->assertJsonPath('pairs.2.price', 120)->assertJsonPath('pairs.2.live', false);
    }

    public function test_landing_snapshot_is_token_free_and_remains_read_only(): void
    {
        config(['desk.api_token' => 'landing-test-token']);
        $position = $this->position();

        $this->getJson('/api/landing')->assertOk()->assertJsonPath('metrics.pnl', 20);
        $this->withHeader('X-Desk-Token', 'outdated-token')->getJson('/api/landing')
            ->assertOk()->assertJsonPath('metrics.open', 1);
        $this->getJson('/api/settings')->assertOk();
        $this->postJson('/api/landing')->assertMethodNotAllowed();
        $this->assertSame('open', $position->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_net_pnl_deducts_only_remaining_costs_and_includes_historical_markets(): void
    {
        $this->position(['realised_usd' => 5, 'fees_usd' => 20, 'meta' => ['entry_fees_usd' => 3, 'funding_usd' => 2]]);
        $this->position(['side' => 'short', 'quantity' => 1, 'entry_usd' => 100, 'fees_usd' => 8, 'meta' => ['entry_fees_usd' => 1, 'funding_usd' => -2]]);
        $this->position(['product_id' => 'LTC-USD', 'status' => 'closed', 'pnl_usd' => 30, 'fees_usd' => 10, 'closed_at' => now()]);
        $this->mock(LiveFeed::class, function ($mock) {
            $mock->shouldReceive('alive')->andReturn(true);
            $mock->shouldReceive('quote')->with('BTC-USD')->andReturn(['price' => 110, 'ts' => time()]);
            $mock->shouldReceive('quote')->with('ETH-USD')->andReturn(null);
            $mock->shouldReceive('quote')->with('LTC-USD')->andReturn(null);
        });

        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('metrics.pnl', 41)->assertJsonPath('metrics.realised', 35)
            ->assertJsonPath('metrics.unrealised', 6)->assertJsonPath('metrics.open_costs', 4)
            ->assertJsonPath('metrics.longs', 1)->assertJsonPath('metrics.shorts', 1)
            ->assertJsonPath('metrics.pnl_basis', 'net_of_booked_costs')
            ->assertJsonPath('pairs.0.pnl', 11)->assertJsonPath('pairs.2.pnl', 30)
            ->assertJsonCount(2, 'pairs.0.positions')->assertJsonPath('pairs.2.id', 'LTC-USD');
        Http::assertNothingSent();
    }

    public function test_current_mode_controls_run_guidance_fills_and_bank_but_global_events_remain_labelled(): void
    {
        $this->freezeTime();
        $paperRun = DeskRun::create(['mode' => 'paper', 'strategy' => 'mr', 'started_at' => now()->subMinute(), 'candidates' => 1, 'passed' => 1]);
        $liveRun = DeskRun::create(['mode' => 'live', 'strategy' => 'custom', 'started_at' => now()]);
        Candidate::create(['desk_run_id' => $paperRun->id, 'product_id' => 'BTC-USD', 'rank' => 1, 'rank_reason' => 'confirmed momentum', 'score' => 7, 'metrics' => [], 'verdict' => 'PASS', 'size_usd' => 200]);
        Candidate::create(['desk_run_id' => $liveRun->id, 'product_id' => 'BTC-USD', 'rank' => 1, 'rank_reason' => 'other book', 'score' => 100, 'metrics' => []]);
        foreach (['paper', 'live'] as $mode) {
            Fill::create(['mode' => $mode, 'product_id' => 'BTC-USD', 'side' => 'BUY', 'requested_usd' => 200, 'filled_usd' => $mode === 'paper' ? 200 : 999, 'decision_price' => 100, 'fill_price' => 100, 'raw' => ['private' => 'not in snapshot'], 'venue_order_id' => 'not-in-snapshot']);
            BankSnapshot::create(['mode' => $mode, 'cash' => $mode === 'paper' ? 1000 : 9000, 'equity' => 1200, 'positions_value' => 200, 'locked' => 0, 'free_cash' => 800, 'collateral' => 50, 'exposure' => 200, 'taken_at' => $mode === 'paper' ? now()->subSeconds(70) : now()]);
        }
        DeskEvent::create(['desk_run_id' => $paperRun->id, 'agent' => 'SCAN', 'message' => 'paper decision']);
        DeskEvent::create(['desk_run_id' => $liveRun->id, 'agent' => 'SCAN', 'message' => 'live decision']);
        DeskEvent::create(['agent' => 'CHIEF', 'message' => 'global operational event']);

        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('latest_run.id', $paperRun->id)
            ->assertJsonPath('pairs.0.decision.reason', 'confirmed momentum')
            ->assertJsonPath('pairs.0.decision.size_usd', 200)->assertJsonPath('pairs.1.decision', null)
            ->assertJsonPath('bank.cash', 1000)->assertJsonPath('bank.collateral', 50)
            ->assertJsonPath('bank.exposure', 200)->assertJsonPath('bank.age_seconds', 70)
            ->assertJsonPath('bank.stale', false)->assertJsonPath('bank.source', 'saved_snapshot')
            ->assertJsonCount(1, 'fills')->assertJsonPath('fills.0.filled_usd', 200)
            ->assertJsonMissingPath('fills.0.raw')->assertJsonMissingPath('fills.0.venue_order_id')
            ->assertJsonCount(2, 'events')->assertJsonPath('events.0.scope', 'system')
            ->assertJsonPath('events.1.scope', 'paper')->assertJsonMissing(['message' => 'live decision']);
        $this->travel(11)->minutes();
        $this->getJson('/api/landing')->assertOk()->assertJsonPath('bank.stale', true);
        Http::assertNothingSent();
    }

    public function test_empty_book_does_not_invent_bank_guidance_or_event_history(): void
    {
        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('bank', null)->assertJsonPath('latest_run', null)
            ->assertJsonPath('pairs.0.decision', null)->assertJsonPath('metrics.pnl', 0)
            ->assertJsonCount(0, 'fills')->assertJsonCount(0, 'events')->assertJsonCount(0, 'optimizer_rounds');
        Http::assertNothingSent();
    }

    public function test_unlinked_position_events_stay_in_their_own_mode_and_do_not_leak_payloads(): void
    {
        $paper = $this->position();
        $live = $this->position(['mode' => 'live']);
        DeskEvent::create(['agent' => 'RISK', 'message' => 'paper trim', 'payload' => ['position_id' => $paper->id, 'private' => 'excluded']]);
        DeskEvent::create(['agent' => 'RISK', 'message' => 'live trim', 'payload' => ['position_id' => $live->id]]);
        DeskEvent::create(['agent' => 'CHIEF', 'message' => 'system heartbeat']);
        $this->getJson('/api/landing')->assertOk()->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.scope', 'system')->assertJsonPath('events.1.scope', 'paper')
            ->assertJsonMissing(['message' => 'live trim'])->assertJsonMissingPath('events.1.payload');
    }

    public function test_recent_fill_and_optimizer_histories_are_bounded(): void
    {
        for ($i = 0; $i < 35; $i++) {
            Fill::create(['mode' => 'paper', 'product_id' => 'BTC-USD', 'side' => 'BUY', 'requested_usd' => 200, 'decision_price' => 100]);
        }
        for ($i = 0; $i < 15; $i++) {
            OptimizerRound::create(['product_id' => 'BTC-USD', 'strategy' => 'mr', 'train_from' => now()->subDays(2), 'train_to' => now()->subDay(), 'test_from' => now()->subDay(), 'test_to' => now(), 'candidates' => $i]);
        }
        $this->getJson('/api/landing')->assertOk()->assertJsonCount(30, 'fills')
            ->assertJsonCount(12, 'optimizer_rounds')->assertJsonPath('optimizer_rounds.0.candidates', 14);
    }
}
