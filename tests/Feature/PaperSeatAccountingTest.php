<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\BankService;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Settings;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Desk\Strategies\MeanReversionStrategy;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\ArenaSeat;
use App\Models\BankSnapshot;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PaperSeatAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => false]);
        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }

            public function price(string $productId): ?float
            {
                return 80000.0;
            }
        });
        $settings = app(Settings::class);
        $settings->set('paper.slippage_bps', 0);
        $settings->set('fees.taker_rate', 0);
        $settings->set('fees.maker_rate', 0);
        $settings->set('fees.per_contract_usd', 0);
    }

    private function seat(float $cash = 1000): ArenaSeat
    {
        $seat = ArenaSeat::create(['label' => 'S', 'strategy_key' => 'mean_reversion', 'starting_cash' => $cash, 'status' => 'active', 'started_at' => now()]);
        PaperLedger::create(['arena_seat_id' => $seat->id, 'kind' => 'deposit', 'amount' => $cash]);

        return $seat;
    }

    private function seatPosition(ArenaSeat $seat, float $usd = 100): Position
    {
        PaperLedger::create(['arena_seat_id' => $seat->id, 'kind' => 'buy', 'amount' => -$usd]);

        return Position::create(['arena_seat_id' => $seat->id, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'BTC-USD',
            'status' => 'open', 'side' => 'long', 'quantity' => $usd / 80000, 'entry_price' => 80000, 'entry_usd' => $usd, 'opened_at' => now()]);
    }

    public function test_closing_a_seat_position_from_the_main_desk_credits_that_seat_not_the_main_ledger(): void
    {
        $seat = $this->seat(1000);
        $position = $this->seatPosition($seat, 100);

        app(Desk::class)->close($position, 'manual', 80000.0);

        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(1000.0, (float) PaperLedger::seat($seat->id)->sum('amount'), 0.01);
        $this->assertSame(0, PaperLedger::seat(null)->count(), 'the main ledger was never touched');
        $this->assertSame($seat->id, Fill::where('kind', 'exit')->firstOrFail()->arena_seat_id);
    }

    public function test_trimming_a_seat_position_from_the_main_desk_credits_that_seat(): void
    {
        $seat = $this->seat(1000);
        $position = $this->seatPosition($seat, 100);

        app(Desk::class)->trim($position, 0.5, 'tp1', 80000.0);

        $this->assertEqualsWithDelta(950.0, (float) PaperLedger::seat($seat->id)->sum('amount'), 0.01);
        $this->assertSame(0, PaperLedger::seat(null)->count());
    }

    public function test_a_floored_whole_contract_trim_only_releases_the_contracts_actually_sold(): void
    {
        config(['desk.perps.enabled' => true, 'desk.perps.paper_margin' => true, 'desk.perps.whole_contracts' => true, 'desk.paper.starting_cash' => 10000]);
        $executor = app(PaperExecutor::class);
        $start = $executor->cash();
        $executor->buy('BTC-USD', 2500, 80000.0); // 3 contracts: $2400 notional, $840 margin
        $afterBuy = $executor->cash();

        // A half trim asks for 1.5 contracts, the venue floors it to 1 and releases a third of the entry cost.
        $sell = $executor->sell('BTC-USD', 0.015, 80000.0, 1200.0);
        $sell->writeLedger();

        $this->assertEqualsWithDelta(0.01, $sell->filledQty, 1e-9);
        $this->assertEqualsWithDelta($start - 840 + 280, $executor->cash(), 0.01, 'one flat contract returns exactly its own $280 of margin');
        $this->assertEqualsWithDelta($afterBuy + 280, $executor->cash(), 0.01);
    }

    public function test_the_main_banks_realised_today_ignores_arena_seats(): void
    {
        $seat = $this->seat();
        Position::create(['arena_seat_id' => $seat->id, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'ETH-USD', 'status' => 'closed', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'pnl_usd' => -50, 'opened_at' => now(), 'closed_at' => now()]);
        $executor = app(PaperExecutor::class);

        $main = app(BankService::class)->current($executor, [], false, null);
        $seated = app(BankService::class)->current($executor->withSeat($seat->id), [], false, $seat->id);

        $this->assertSame(0.0, $main->realisedPnlToday);
        $this->assertSame(-50.0, $seated->realisedPnlToday);
    }

    public function test_the_daily_loss_cap_reads_only_the_contexts_own_seat(): void
    {
        $seat = $this->seat();
        $other = $this->seat();
        // The main desk's snapshot is earlier and bigger: an unscoped lookup would pick it and report -10%, not -20%.
        BankSnapshot::create(['arena_seat_id' => null, 'mode' => 'paper', 'cash' => 2000, 'positions_value' => 0, 'equity' => 2000, 'locked' => 0, 'free_cash' => 2000, 'taken_at' => now()->startOfDay()]);
        foreach ([$seat->id, $other->id] as $owner) {
            BankSnapshot::create(['arena_seat_id' => $owner, 'mode' => 'paper', 'cash' => 1000, 'positions_value' => 0, 'equity' => 1000, 'locked' => 0, 'free_cash' => 1000, 'taken_at' => now()->startOfDay()->addMinute()]);
        }
        Position::create(['arena_seat_id' => $other->id, 'mode' => 'paper', 'strategy' => 'mean_reversion', 'product_id' => 'ETH-USD', 'status' => 'closed', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'pnl_usd' => -200, 'opened_at' => now(), 'closed_at' => now()]);

        $pct = fn (?int $seatId) => (new \ReflectionMethod(JsonPluginStrategy::class, 'dailyPnlPct'))->invoke(
            (new \ReflectionClass(JsonPluginStrategy::class))->newInstanceWithoutConstructor(),
            new DeskContext([], 'paper', arenaSeatId: $seatId)
        );

        $this->assertEqualsWithDelta(0.0, $pct(null), 1e-9, 'the main desk does not inherit a seat\'s loss');
        $this->assertEqualsWithDelta(0.0, $pct($seat->id), 1e-9);
        $this->assertEqualsWithDelta(-20.0, $pct($other->id), 1e-9);
    }

    public function test_a_seats_close_does_not_arm_the_main_desks_reentry_cooldown(): void
    {
        $seat = $this->seat();
        Position::create(['arena_seat_id' => $seat->id, 'mode' => 'paper', 'strategy' => (new MeanReversionStrategy)->key(), 'product_id' => 'BTC-USD', 'status' => 'closed', 'side' => 'long',
            'quantity' => 1, 'entry_price' => 100, 'entry_usd' => 100, 'opened_at' => now(), 'closed_at' => now()]);

        $cooldown = fn (?int $seatId) => (new \ReflectionMethod(MeanReversionStrategy::class, 'inCooldown'))->invoke(
            new MeanReversionStrategy, 'BTC-USD', new DeskContext(['mr' => ['cooldown_bars' => 5, 'timeframe' => '1m']], 'paper', arenaSeatId: $seatId)
        );

        $this->assertFalse($cooldown(null));
        $this->assertTrue($cooldown($seat->id));
    }

    public function test_a_wrong_seat_executor_is_rescoped_when_closing_a_seat_position(): void
    {
        $seat = $this->seat(1000);
        $position = $this->seatPosition($seat, 100);

        app(Desk::class)->close($position, 'manual', 80000.0, app(PaperExecutor::class));

        $this->assertEqualsWithDelta(1000.0, (float) PaperLedger::seat($seat->id)->sum('amount'), 0.01);
        $this->assertSame(0, PaperLedger::seat(null)->count(), 'the supplied main-desk executor did not take the cash');
    }

    public function test_a_wrong_seat_executor_is_rescoped_when_trimming_a_seat_position(): void
    {
        $seat = $this->seat(1000);
        $position = $this->seatPosition($seat, 100);

        app(Desk::class)->trim($position, 0.5, 'tp1', 80000.0, app(PaperExecutor::class));

        $this->assertEqualsWithDelta(950.0, (float) PaperLedger::seat($seat->id)->sum('amount'), 0.01);
        $this->assertSame(0, PaperLedger::seat(null)->count());
    }

    public function test_the_ticker_is_fetched_before_the_cash_lock_is_taken(): void
    {
        $probe = new \stdClass;
        $probe->held = null;
        $this->app->bind(MarketData::class, fn () => new class($probe) extends CoinbaseMarketData
        {
            public function __construct(private \stdClass $probe) {}

            public function ticker(string $productId, int $limit = 100): array
            {
                $lock = Cache::lock('desk:mutate:paper:cash', 5);
                $this->probe->held = ! $lock->get();
                if (! $this->probe->held) {
                    $lock->release();
                }

                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }
        });

        app(PaperExecutor::class)->buy('BTC-USD', 100, 80000.0);

        $this->assertFalse($probe->held, 'market I/O must not run while the cash lock is held');
    }
}
