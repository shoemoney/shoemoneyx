<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\EndOfDayReport;
use App\Models\ArenaSeat;
use App\Models\BankSnapshot;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndOfDayReportSeatScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_eod_report_excludes_arena_seat_activity(): void
    {
        $seat = ArenaSeat::create(['label' => 'S', 'strategy_key' => 'mean_reversion', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $at = now()->setTime(12, 0);

        foreach ([null, $seat->id] as $seatId) {
            $main = $seatId === null;
            $usd = $main ? 100 : 700;
            $run = DeskRun::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'strategy' => 'mr',
                'started_at' => $at, 'products_scanned' => $main ? 10 : 900]);
            Candidate::create(['desk_run_id' => $run->id, 'product_id' => 'BTC-USD', 'rank' => 1, 'rank_reason' => 'fixture',
                'metrics' => [], 'verdict' => $main ? 'PASS' : 'REJECT', 'failed_check' => $main ? null : 'seat-only']);
            Fill::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'product_id' => 'BTC-USD', 'side' => 'BUY', 'kind' => 'entry', 'status' => 'filled',
                'requested_usd' => $usd, 'filled_usd' => $usd, 'decision_price' => 100, 'fill_price' => 100]);
            Position::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'BTC-USD', 'status' => 'closed',
                'side' => 'long', 'quantity' => 1, 'entry_price' => 100, 'entry_usd' => $usd, 'opened_at' => $at->copy()->subHour(), 'closed_at' => $at, 'pnl_usd' => $main ? 5 : 50]);
            Position::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'ETH-USD', 'status' => 'open',
                'side' => 'long', 'quantity' => 1, 'entry_price' => 100, 'entry_usd' => $usd, 'opened_at' => $at]);
            // Seat snapshots sit outside the main ones on both ends, so an unscoped query would pick them.
            BankSnapshot::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'cash' => 0, 'positions_value' => 0, 'locked' => 0, 'free_cash' => 0,
                'equity' => $main ? 1000 : 5000, 'taken_at' => $at->copy()->addMinutes($main ? 0 : -1)]);
            BankSnapshot::create(['arena_seat_id' => $seatId, 'mode' => 'paper', 'cash' => 0, 'positions_value' => 0, 'locked' => 0, 'free_cash' => 0,
                'equity' => $main ? 1100 : 6000, 'taken_at' => $at->copy()->addMinutes($main ? 5 : 6)]);
        }

        $r = app(EndOfDayReport::class)->build(now(), 'paper');

        $this->assertSame(1, $r['cycles']);
        $this->assertSame(10, $r['products_scanned']);
        $this->assertSame(1, $r['candidates']);
        $this->assertSame(1, $r['passed']);
        $this->assertSame([], $r['rejections']);
        $this->assertCount(1, $r['fills']);
        $this->assertSame(100.0, (float) $r['fills'][0]['usd']);
        $this->assertCount(1, $r['closes']);
        $this->assertSame(5.0, $r['realised_pnl_usd']);
        $this->assertSame(100.0, $r['working_usd']);
        $this->assertSame(1, $r['open_positions']);
        $this->assertSame(1000.0, (float) $r['bank_open']);
        $this->assertSame(1100.0, (float) $r['bank_close']);
    }
}
