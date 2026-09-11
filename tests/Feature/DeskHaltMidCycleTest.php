<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\TwoEntryHaltStrategy;
use Tests\TestCase;

/**
 * Finding 15 (code-reviews/2026-09-05-gpt-5.6-sol-code-review.md): halt was only checked near the
 * start of cycle(); halting mid-cycle (after the first candidate's entry, before the second's) did
 * not stop the second entry from being submitted. Desk::cycle() now re-checks the halt flag
 * immediately before each candidate's FILLS submission.
 */
class DeskHaltMidCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 100.0, 'best_ask' => 100.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_halting_mid_cycle_after_the_first_entry_prevents_the_second(): void
    {
        config(['desk.strategies.two_entry_halt_test' => TwoEntryHaltStrategy::class, 'desk.strategy' => 'two_entry_halt_test']);
        $this->app->instance(TwoEntryHaltStrategy::class, new TwoEntryHaltStrategy(
            usd: 100.0,
            productIds: ['BTC-USD', 'ETH-USD'],
            onSecondVet: fn () => app(Chief::class)->halt('operator hit halt mid-cycle'),
        ));

        $desk = app(Desk::class);
        $run = $desk->cycle();

        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->filled, 'only the first candidate should have entered');
        $this->assertSame(1, Position::count());
        $this->assertSame('BTC-USD', Position::sole()->product_id);
        $this->assertSame(1, Fill::whereNotNull('position_id')->count(), 'the second candidate must never have reached FILLS');

        // Belt and suspenders: the halt really is armed, and the desk really did see it.
        $this->assertNotNull(app(Chief::class)->halted());
    }

    public function test_without_a_mid_cycle_halt_both_candidates_enter_normally(): void
    {
        config(['desk.strategies.two_entry_halt_test' => TwoEntryHaltStrategy::class, 'desk.strategy' => 'two_entry_halt_test']);
        $this->app->instance(TwoEntryHaltStrategy::class, new TwoEntryHaltStrategy(
            usd: 100.0,
            productIds: ['BTC-USD', 'ETH-USD'],
        ));

        $run = app(Desk::class)->cycle();

        $this->assertSame('done', $run->status);
        $this->assertSame(2, $run->filled);
        $this->assertSame(2, Position::count());
    }
}
