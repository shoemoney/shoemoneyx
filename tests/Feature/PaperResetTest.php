<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\PaperBook;
use App\Models\ArenaSeat;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\RiskCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaperResetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Position, Position, Fill} */
    private function seedBook(): array
    {
        $paper = Position::create(['mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD', 'status' => 'open', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'opened_at' => now()]);
        $live = Position::create(['mode' => 'live', 'strategy' => 'mr', 'product_id' => 'ETH-USD', 'status' => 'open', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'opened_at' => now()]);
        RiskCheck::create(['position_id' => $paper->id, 'action' => 'HOLD']);
        $fill = Fill::create(['position_id' => $paper->id, 'mode' => 'paper', 'product_id' => 'BTC-USD', 'side' => 'BUY', 'kind' => 'entry',
            'requested_usd' => 100, 'decision_price' => 100, 'status' => 'filled']);
        PaperLedger::create(['kind' => 'deposit', 'amount' => 1000]);

        return [$paper, $live, $fill];
    }

    private function assertBookWiped(Position $live, Fill $fill): void
    {
        $this->assertSame(0, Position::mode('paper')->count());
        $this->assertSame(0, RiskCheck::count());
        $this->assertSame(0, PaperLedger::count());
        $this->assertNotNull(Position::find($live->id));
        $this->assertNotNull($fill->fresh());
        $this->assertNull($fill->fresh()->position_id);
    }

    public function test_the_api_action_wipes_the_paper_book_and_keeps_fills_and_live_positions(): void
    {
        [, $live, $fill] = $this->seedBook();

        $this->postJson('/api/desk/paper-reset')->assertOk();

        $this->assertBookWiped($live, $fill);
    }

    public function test_the_cli_does_exactly_the_same(): void
    {
        [, $live, $fill] = $this->seedBook();

        $this->artisan('desk:ctl', ['action' => 'paper-reset'])->assertSuccessful();

        $this->assertBookWiped($live, $fill);
    }

    public function test_a_failure_part_way_rolls_the_whole_reset_back(): void
    {
        [$paper, , $fill] = $this->seedBook();
        DB::listen(fn ($query) => str_contains(str_replace(['"', '`'], '', $query->sql), 'delete from paper_ledger') ? throw new \RuntimeException('boom') : null);

        try {
            PaperBook::reset();
            $this->fail('expected the reset to fail');
        } catch (\RuntimeException $error) {
            $this->assertSame('boom', $error->getMessage());
        }

        $this->assertSame(1, RiskCheck::count());
        $this->assertSame(1, PaperLedger::count());
        $this->assertNotNull(Position::find($paper->id));
        $this->assertSame($paper->id, $fill->fresh()->position_id);
    }

    public function test_a_large_book_is_reset_with_a_subquery_not_thousands_of_bindings(): void
    {
        $this->seedBook();
        // Enough paper positions that binding their ids one by one would blow past the ceiling below.
        foreach (range(1, 25) as $i) {
            Position::create(['mode' => 'paper', 'strategy' => 'mr', 'product_id' => "P{$i}-USD", 'status' => 'closed', 'side' => 'long',
                'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'opened_at' => now()]);
        }
        $max = 0;
        DB::listen(function ($query) use (&$max) {
            $max = max($max, count($query->bindings));
        });

        PaperBook::reset();

        $this->assertLessThan(10, $max, 'child statements must select ids in SQL, not bind them');
    }

    /** @return array{ArenaSeat, Position} */
    private function seedSeat(): array
    {
        $seat = ArenaSeat::create(['label' => 'S', 'strategy_key' => 'mean_reversion', 'starting_cash' => 500, 'status' => 'active', 'started_at' => now()]);
        $position = Position::create(['arena_seat_id' => $seat->id, 'mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'SOL-USD', 'status' => 'open', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'opened_at' => now()]);
        PaperLedger::create(['arena_seat_id' => $seat->id, 'kind' => 'deposit', 'amount' => 500]);
        RiskCheck::create(['position_id' => $position->id, 'action' => 'HOLD']);
        Fill::create(['position_id' => $position->id, 'mode' => 'paper', 'product_id' => 'SOL-USD', 'side' => 'BUY', 'kind' => 'entry',
            'requested_usd' => 100, 'decision_price' => 100, 'status' => 'filled', 'arena_seat_id' => $seat->id]);

        return [$seat, $position];
    }

    public function test_the_default_scope_leaves_arena_seats_alone(): void
    {
        $this->seedBook();
        [$seat, $seatPosition] = $this->seedSeat();

        $this->postJson('/api/desk/paper-reset')->assertOk()->assertJson(['scope' => 'main']);

        $this->assertSame(0, Position::mode('paper')->seat(null)->count());
        $this->assertSame(0, PaperLedger::seat(null)->count());
        $this->assertNotNull(Position::find($seatPosition->id));
        $this->assertSame(1, PaperLedger::seat($seat->id)->count());
        $this->assertSame(1, RiskCheck::where('position_id', $seatPosition->id)->count(), 'seat risk checks survive a main reset');
        $this->assertSame(1, Fill::where('position_id', $seatPosition->id)->count(), 'seat fills keep their position link');
    }

    public function test_scope_all_wipes_every_seat_too_but_keeps_the_seats(): void
    {
        [, $live, $fill] = $this->seedBook();
        [$seat, $seatPosition] = $this->seedSeat();
        $seatFill = Fill::where('position_id', $seatPosition->id)->firstOrFail();

        $this->postJson('/api/desk/paper-reset', ['scope' => 'all'])->assertOk()->assertJson(['scope' => 'all']);

        $this->assertBookWiped($live, $fill);
        $this->assertSame(0, Position::seat($seat->id)->count());
        $this->assertSame(0, PaperLedger::seat($seat->id)->count());
        $this->assertSame(0, RiskCheck::where('position_id', $seatPosition->id)->count());
        $this->assertNotNull($seatFill->fresh(), 'seat fills are history and survive');
        $this->assertNull($seatFill->fresh()->position_id, 'their position link is cleared');
        $this->assertNotNull(ArenaSeat::find($seat->id));
    }

    public function test_an_unknown_scope_is_refused_and_nothing_is_wiped(): void
    {
        [$paper, $live, $fill] = $this->seedBook();
        [$seat, $seatPosition] = $this->seedSeat();
        $seatFill = Fill::where('position_id', $seatPosition->id)->firstOrFail();
        $counts = fn () => [Position::count(), PaperLedger::count(), RiskCheck::count(), Fill::whereNotNull('position_id')->count(), ArenaSeat::count()];
        $before = $counts();

        $this->postJson('/api/desk/paper-reset', ['scope' => 'everything'])->assertStatus(422);

        $this->assertSame($before, $counts());
        $this->assertSame($paper->id, $fill->fresh()->position_id);
        $this->assertSame($seatPosition->id, $seatFill->fresh()->position_id);
        $this->assertSame(1, RiskCheck::where('position_id', $paper->id)->count());
        $this->assertSame(1, RiskCheck::where('position_id', $seatPosition->id)->count());
        $this->assertSame(1, PaperLedger::seat($seat->id)->count());
        $this->assertNotNull(Position::find($live->id));
    }

    public function test_the_cli_takes_the_same_scope(): void
    {
        $this->seedBook();
        [$seat] = $this->seedSeat();

        $this->artisan('desk:ctl', ['action' => 'paper-reset', '--scope' => 'main'])->assertSuccessful();
        $this->assertSame(1, PaperLedger::seat($seat->id)->count());

        $this->artisan('desk:ctl', ['action' => 'paper-reset', '--scope' => 'all'])->assertSuccessful();
        $this->assertSame(0, PaperLedger::count());

        $this->seedBook();
        [, $seatPosition] = $this->seedSeat();
        $before = [Position::count(), PaperLedger::count(), RiskCheck::count(), Fill::whereNotNull('position_id')->count()];

        $this->artisan('desk:ctl', ['action' => 'paper-reset', '--scope' => 'bogus'])->assertFailed();

        $this->assertSame($before, [Position::count(), PaperLedger::count(), RiskCheck::count(), Fill::whereNotNull('position_id')->count()], 'a refused scope deletes nothing');
    }
}
