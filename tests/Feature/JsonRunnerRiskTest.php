<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\Bank;
use App\Desk\Data\ProductStats;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\BankSnapshot;
use App\Models\Position;
use App\Models\StrategyPlugin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * risk.max_positions / risk.daily_loss_cap_pct / risk.leverage_cap, wired into
 * JsonPluginStrategy::size() and ::risk() (docs/STRATEGY_SCHEMA.md). Canned-tape style:
 * literal expected outcomes on hand-built fixtures.
 */
class JsonRunnerRiskTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(string $key, array $risk): StrategyPlugin
    {
        return StrategyPlugin::create([
            'key' => $key,
            'name' => 'Risk test',
            'definition' => [
                'schema_version' => 1,
                'key' => $key,
                'meta' => ['name' => 'Risk test'],
                'entry' => ['side' => 'long'],
                'risk' => $risk,
            ],
        ]);
    }

    private function ctx(string $key, array $open = [], ?\DateTimeImmutable $now = null): DeskContext
    {
        return new DeskContext(
            ['json' => ['plugin_key' => $key]],
            'paper',
            false,
            $open,
            $now ?? new \DateTimeImmutable('2026-09-11 12:00:00', new \DateTimeZone('UTC')),
        );
    }

    private function stats(string $pid, float $price): ProductStats
    {
        return ProductStats::fromArray([
            'product_id' => $pid, 'price' => $price,
            'volume_h6_usd' => 400000, 'volume_h24_usd' => 1200000, 'spread_bps' => 5,
            'candles_h1_count' => 48, 'age_hours' => 500, 'book_depth_usd' => 500000,
        ]);
    }

    private function position(string $pid, array $attrs = []): Position
    {
        return Position::create($attrs + [
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => $pid, 'status' => 'open',
            'quantity' => 10, 'entry_price' => 10, 'entry_usd' => 100, 'peak_price' => 10, 'last_price' => 10,
            'opened_at' => Carbon::parse('2026-09-11 10:00:00'),
        ])->fresh();
    }

    private function verdictFor(string $pid, float $price): Verdict
    {
        $stats = $this->stats($pid, $price);
        $candidate = new \App\Desk\Data\Candidate(stats: $stats, score: 1.0, rankReason: 'test', edgeProbability: 0.55, payoffRatio: 1.5);

        return Verdict::pass($candidate, ['test'], [], 'ok');
    }

    // -----------------------------------------------------------------
    // risk.max_positions
    // -----------------------------------------------------------------

    public function test_size_skips_a_new_entry_once_max_positions_is_reached(): void
    {
        $this->plugin('cap-pos-1', ['max_positions' => 2]);
        $open = [$this->position('AAA-USD'), $this->position('BBB-USD')];
        $ctx = $this->ctx('cap-pos-1', $open);

        $v = $this->verdictFor('CCC-USD', 10.0);
        $d = (new JsonPluginStrategy)->size($v, new Bank(1000, 0, 0.2, 0, 2), $ctx);

        $this->assertTrue($d->zero());
        $this->assertStringContainsString('risk.max_positions', $d->why);
    }

    public function test_size_still_sizes_a_new_entry_under_the_cap(): void
    {
        $this->plugin('cap-pos-2', ['max_positions' => 2]);
        $open = [$this->position('AAA-USD')];
        $ctx = $this->ctx('cap-pos-2', $open);

        $v = $this->verdictFor('CCC-USD', 10.0);
        $d = (new JsonPluginStrategy)->size($v, new Bank(1000, 0, 0.2, 0, 1), $ctx);

        $this->assertFalse($d->zero());
    }

    public function test_max_positions_does_not_block_an_add_to_an_already_open_position(): void
    {
        $this->plugin('cap-pos-3', ['max_positions' => 1]);
        $open = [$this->position('AAA-USD')];
        $ctx = $this->ctx('cap-pos-3', $open);

        // AAA-USD is already open — this is an add, not a new position, so the cap must not apply.
        $v = $this->verdictFor('AAA-USD', 10.0);
        $d = (new JsonPluginStrategy)->size($v, new Bank(1000, 0, 0.2, 0, 1), $ctx);

        $this->assertFalse($d->zero());
    }

    // -----------------------------------------------------------------
    // risk.daily_loss_cap_pct
    // -----------------------------------------------------------------

    private function seedTodayBankSnapshot(float $equity): void
    {
        BankSnapshot::create([
            'mode' => 'paper', 'cash' => $equity, 'positions_value' => 0, 'equity' => $equity,
            'locked' => 0, 'free_cash' => $equity, 'taken_at' => Carbon::parse('2026-09-11 00:05:00', 'UTC'),
        ]);
    }

    public function test_risk_closes_all_once_todays_realised_loss_breaches_the_cap(): void
    {
        $this->plugin('cap-loss-1', ['daily_loss_cap_pct' => 5]);
        $this->seedTodayBankSnapshot(1000);
        // Already realised -$60 today (6% of the $1000 starting equity) via an earlier close.
        Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'ZZZ-USD', 'status' => 'closed',
            'quantity' => 0, 'entry_usd' => 0, 'pnl_usd' => -60, 'opened_at' => Carbon::parse('2026-09-11 09:00:00'),
            'closed_at' => Carbon::parse('2026-09-11 09:30:00'),
        ]);
        $p = $this->position('AAA-USD');
        $ctx = $this->ctx('cap-loss-1', [$p]);

        $d = (new JsonPluginStrategy)->risk($p, $this->stats('AAA-USD', 10.0), $ctx);

        $this->assertTrue($d->shouldClose());
        $this->assertSame('risk.daily_loss_cap_pct', $d->ruleFired);
    }

    public function test_risk_holds_when_todays_loss_is_under_the_cap(): void
    {
        $this->plugin('cap-loss-2', ['daily_loss_cap_pct' => 5]);
        $this->seedTodayBankSnapshot(1000);
        Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'ZZZ-USD', 'status' => 'closed',
            'quantity' => 0, 'entry_usd' => 0, 'pnl_usd' => -20, 'opened_at' => Carbon::parse('2026-09-11 09:00:00'),
            'closed_at' => Carbon::parse('2026-09-11 09:30:00'),
        ]);
        $p = $this->position('AAA-USD');
        $ctx = $this->ctx('cap-loss-2', [$p]);

        $d = (new JsonPluginStrategy)->risk($p, $this->stats('AAA-USD', 10.0), $ctx);

        $this->assertFalse($d->shouldClose());
    }

    public function test_yesterdays_loss_does_not_carry_into_todays_cap(): void
    {
        $this->plugin('cap-loss-3', ['daily_loss_cap_pct' => 5]);
        $this->seedTodayBankSnapshot(1000);
        // A big loss realised yesterday must not count against today's cap.
        Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'ZZZ-USD', 'status' => 'closed',
            'quantity' => 0, 'entry_usd' => 0, 'pnl_usd' => -900, 'opened_at' => Carbon::parse('2026-09-10 09:00:00'),
            'closed_at' => Carbon::parse('2026-09-10 09:30:00'),
        ]);
        $p = $this->position('AAA-USD');
        $ctx = $this->ctx('cap-loss-3', [$p]);

        $d = (new JsonPluginStrategy)->risk($p, $this->stats('AAA-USD', 10.0), $ctx);

        $this->assertFalse($d->shouldClose());
    }

    public function test_size_refuses_new_entries_once_the_daily_cap_is_breached(): void
    {
        $this->plugin('cap-loss-4', ['daily_loss_cap_pct' => 5]);
        $this->seedTodayBankSnapshot(1000);
        Position::create([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'ZZZ-USD', 'status' => 'closed',
            'quantity' => 0, 'entry_usd' => 0, 'pnl_usd' => -60, 'opened_at' => Carbon::parse('2026-09-11 09:00:00'),
            'closed_at' => Carbon::parse('2026-09-11 09:30:00'),
        ]);
        $ctx = $this->ctx('cap-loss-4', []);

        $v = $this->verdictFor('CCC-USD', 10.0);
        $d = (new JsonPluginStrategy)->size($v, new Bank(940, 0, 0.2, 0, 0), $ctx);

        $this->assertTrue($d->zero());
        $this->assertStringContainsString('risk.daily_loss_cap_pct', $d->why);
    }

    public function test_no_baseline_snapshot_yet_today_fails_open(): void
    {
        $this->plugin('cap-loss-5', ['daily_loss_cap_pct' => 5]);
        // No BankSnapshot seeded today: nothing to compare against, so the cap must not fire.
        $p = $this->position('AAA-USD');
        $ctx = $this->ctx('cap-loss-5', [$p]);

        $d = (new JsonPluginStrategy)->risk($p, $this->stats('AAA-USD', 10.0), $ctx);

        $this->assertNotSame('risk.daily_loss_cap_pct', $d->ruleFired);
    }

    // -----------------------------------------------------------------
    // risk.leverage_cap
    // -----------------------------------------------------------------

    public function test_size_clamps_the_ticket_to_leverage_cap_times_equity(): void
    {
        $this->plugin('cap-lev-1', ['leverage_cap' => 0.1]); // 10% of equity max exposure
        $ctx = $this->ctx('cap-lev-1', []);

        $v = $this->verdictFor('CCC-USD', 10.0);
        // Bank: $1000 equity, no exposure yet. Kelly would size ~6% (the base ceiling) -- well under
        // the 10% leverage room, so this asserts the clamp is a no-op when there's plenty of room.
        $bank = new Bank(1000, 0, 0.2, 0, 0, exposure: 0);
        $d = (new JsonPluginStrategy)->size($v, $bank, $ctx);

        $this->assertEqualsWithDelta(60.0, $d->dollars, 0.01);
    }

    public function test_size_clamps_down_when_existing_exposure_already_eats_the_leverage_room(): void
    {
        $this->plugin('cap-lev-2', ['leverage_cap' => 0.1]); // 10% of equity = $100 total exposure room
        $ctx = $this->ctx('cap-lev-2', []);

        $v = $this->verdictFor('CCC-USD', 10.0);
        // $80 of the $100 room is already spoken for by existing positions.
        $bank = new Bank(1000, 0, 0.2, 0, 1, exposure: 80);
        $d = (new JsonPluginStrategy)->size($v, $bank, $ctx);

        $this->assertEqualsWithDelta(20.0, $d->dollars, 0.01);
        $this->assertTrue($d->ceilingApplied);
    }

    public function test_size_returns_zero_when_leverage_cap_leaves_no_room(): void
    {
        $this->plugin('cap-lev-3', ['leverage_cap' => 0.1]);
        $ctx = $this->ctx('cap-lev-3', []);

        $v = $this->verdictFor('CCC-USD', 10.0);
        $bank = new Bank(1000, 0, 0.2, 0, 1, exposure: 100); // room fully spent already
        $d = (new JsonPluginStrategy)->size($v, $bank, $ctx);

        $this->assertTrue($d->zero());
    }
}
