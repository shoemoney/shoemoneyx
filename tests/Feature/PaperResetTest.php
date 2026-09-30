<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\PaperBook;
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
        [$paper] = $this->seedBook();
        DB::listen(fn ($query) => str_contains($query->sql, 'delete from "paper_ledger"') ? throw new \RuntimeException('boom') : null);

        try {
            PaperBook::reset();
            $this->fail('expected the reset to fail');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, RiskCheck::count());
        $this->assertSame(1, PaperLedger::count());
        $this->assertNotNull(Position::find($paper->id));
    }
}
