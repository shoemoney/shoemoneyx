<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Arena\ArenaRunner;
use App\Desk\Data\Bank;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\EndOfDayReport;
use App\Exchange\Contracts\MarketData;
use App\Models\ArenaSeat;
use App\Models\Candle;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fixtures\AlwaysEnterStrategy;
use Tests\TestCase;

/**
 * ArenaRunner::cycle() against a real (faked-exchange) SCAN -> VET -> SIZE -> FILLS -> RISK
 * pass, proving Desk::forSeat()'s scoping actually isolates each seat's own account rather than
 * just compiling. Uses the AlwaysEnterStrategy test double so the assertion is about the
 * arena's plumbing, not about coaxing a real strategy's statistical signal to fire.
 */
class ArenaMultiSeatCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.perps.enabled' => false, // this suite's fixed $100 test size is under one BTC-PERP contract
            'desk.strategies.always_enter' => AlwaysEnterStrategy::class,
        ]);
        Http::fake([
            'api.coinbase.com/api/v3/brokerage/market/products*' => Http::response(['products' => [
                ['product_id' => 'BTC-USD', 'base_currency_id' => 'BTC', 'quote_currency_id' => 'USD', 'product_type' => 'SPOT', 'status' => 'online', 'price' => '80000', 'volume_24h' => '1000', 'price_percentage_change_24h' => '1.5', 'base_increment' => '0.00000001', 'quote_increment' => '0.01'],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/products/*/ticker*' => Http::response(['best_bid' => '79990', 'best_ask' => '80010', 'trades' => [
                ['trade_id' => '1', 'price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => now()->toIso8601String()],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/product_book*' => Http::response(['pricebook' => ['bids' => [['price' => '79990', 'size' => '5']], 'asks' => [['price' => '80010', 'size' => '5']]]]),
            'api.coinbase.com/*' => Http::response(['candles' => []]),
        ]);
        $this->artisan('market:sync-products')->assertSuccessful();
        $t = now()->startOfHour();
        foreach (range(0, 47) as $i) {
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $t->copy()->subHours(47 - $i), 'open' => 80000, 'high' => 80100, 'low' => 79900, 'close' => 80050, 'volume' => 10]);
        }
    }

    public function test_two_seats_on_one_cycle_produce_independent_fills_positions_and_cash(): void
    {
        $seatA = ArenaSeat::create(['label' => 'Seat A', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $seatB = ArenaSeat::create(['label' => 'Seat B', 'strategy_key' => 'always_enter', 'starting_cash' => 500, 'status' => 'active', 'started_at' => now()]);

        $runs = app(ArenaRunner::class)->cycle();

        $this->assertCount(2, $runs);
        foreach ($runs as $result) {
            $this->assertSame($result['seat_id'], DeskRun::findOrFail($result['run_id'])->arena_seat_id);
        }

        $posA = Position::mode('paper')->seat($seatA->id)->get();
        $posB = Position::mode('paper')->seat($seatB->id)->get();
        $this->assertCount(1, $posA);
        $this->assertCount(1, $posB);
        $this->assertNotSame($posA->first()->id, $posB->first()->id, 'each seat gets its own position row');

        $fillsA = Fill::seat($seatA->id)->where('status', 'filled')->get();
        $fillsB = Fill::seat($seatB->id)->where('status', 'filled')->get();
        $this->assertCount(1, $fillsA);
        $this->assertCount(1, $fillsB);

        // Cash moved independently, off each seat's own starting_cash — not the same ledger.
        $cashA = (float) PaperLedger::seat($seatA->id)->sum('amount');
        $cashB = (float) PaperLedger::seat($seatB->id)->sum('amount');
        $this->assertEqualsWithDelta(1000 - 100, $cashA, 1.0);
        $this->assertEqualsWithDelta(500 - 100, $cashB, 1.0);
        $this->assertNotEquals($cashA, $cashB);

        // The main desk's own (seat-less) account is untouched.
        $this->assertSame(0, Position::whereNull('arena_seat_id')->count());
        $this->assertSame(0, Fill::whereNull('arena_seat_id')->where('status', 'filled')->count());
        $this->assertSame(0, (int) PaperLedger::whereNull('arena_seat_id')->count());

        // A main cycle on the same tape keeps its run and candidate totals independent too.
        $desk = app(Desk::class);
        $mainRun = $desk->runCycle(new AlwaysEnterStrategy, $desk->executorForMode('paper'), null);
        $this->assertNull($mainRun->arena_seat_id);
        $this->assertSame(1, $mainRun->candidates()->count());
        $report = app(EndOfDayReport::class)->build(now(), 'paper');
        $this->assertSame(1, $report['cycles']);
        $this->assertSame(1, $report['products_scanned']);
        $this->assertSame(1, $report['candidates']);
        $this->getJson('/api/runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mainRun->id);
        $this->getJson('/api/candidates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.desk_run_id', $mainRun->id);
    }

    public function test_retire_stops_a_seat_from_trading_on_the_next_cycle(): void
    {
        $seat = ArenaSeat::create(['label' => 'Retired', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'retired', 'started_at' => now(), 'stopped_at' => now()]);

        $runs = app(ArenaRunner::class)->cycle();

        $this->assertSame([], $runs, 'a retired seat is never picked up by the runner');
        $this->assertSame(0, Position::seat($seat->id)->count());
        $this->assertSame(0, Fill::seat($seat->id)->count());
    }

    public function test_seat_clones_share_stop_with_their_root_but_keep_account_and_owner_state_independent(): void
    {
        $root = app(Desk::class);
        $unrelated = app(Desk::class);
        $first = $root->forSeat(1, ['seat' => ['label' => 'first']]);
        $second = $root->forSeat(2, ['seat' => ['label' => 'second']]);
        $owner = new \ReflectionProperty(Desk::class, 'heldCycle');
        $owner->setValue($first, ['paper', 'first-owner']);

        $root->requestStop();

        $this->assertTrue($first->stopRequested());
        $this->assertTrue($second->stopRequested());
        $this->assertTrue($root->forSeat(3)->stopRequested(), 'a later clone also inherits the stop');
        $this->assertFalse($unrelated->stopRequested(), 'a fresh desk has its own cancellation state');
        $this->assertNull($owner->getValue($root));
        $this->assertNull($owner->getValue($second));
        $this->assertSame(1, $first->context(new AlwaysEnterStrategy)->arenaSeatId);
        $this->assertSame(2, $second->context(new AlwaysEnterStrategy)->arenaSeatId);
        $this->assertSame('first', $first->context(new AlwaysEnterStrategy)->param('seat.label'));
        $this->assertSame('second', $second->context(new AlwaysEnterStrategy)->param('seat.label'));
    }

    public function test_an_already_stopped_command_root_starts_no_arena_work(): void
    {
        ArenaSeat::create(['label' => 'Waiting', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'active']);
        $root = app(Desk::class);
        $runner = app(ArenaRunner::class);
        $root->requestStop();
        $requestsBefore = Http::recorded()->count();

        $this->assertSame([], $runner->cycle($root));

        $this->assertSame($requestsBefore, Http::recorded()->count(), 'the stopped root must not fetch a universe');
        $this->assertSame(0, DeskRun::count());
        $this->assertSame(0, PaperLedger::count());
    }

    public function test_a_root_stop_during_first_seat_sizing_prevents_entry_and_later_seat_scan(): void
    {
        $root = app(Desk::class);
        $strategy = new class($root) extends AlwaysEnterStrategy
        {
            public array $scannedSeats = [];

            public function __construct(private Desk $root)
            {
                parent::__construct();
            }

            public function scan(array $universe, DeskContext $ctx): array
            {
                $this->scannedSeats[] = $ctx->arenaSeatId;

                return parent::scan($universe, $ctx);
            }

            public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
            {
                $this->root->requestStop();

                return parent::size($verdict, $bank, $ctx);
            }
        };
        $this->app->instance(AlwaysEnterStrategy::class, $strategy);
        [$first, $second] = $this->stopTestSeats();

        $runs = app(ArenaRunner::class)->cycle($root);

        $this->assertSame([$first->id], $strategy->scannedSeats);
        $this->assertStoppedSeatRun($runs, $first, $second);
    }

    public function test_a_command_root_stop_during_real_paper_ticker_preparation_prevents_debit_and_later_seat_scan(): void
    {
        $root = null;
        $market = app(MarketData::class);
        $marketProxy = \Mockery::mock($market)->makePartial();
        $marketProxy->shouldReceive('ticker')->with('BTC-USD', 1)->once()->andReturnUsing(function () use (&$root, $market) {
            // Same cooperative requestStop call as the desk:run signal handler, after its seat clone exists.
            $root->requestStop();

            return $market->ticker('BTC-USD', 1);
        });
        $this->app->instance(MarketData::class, $marketProxy);
        $root = app(Desk::class);
        $runner = app(ArenaRunner::class);
        $injectedDesk = (new \ReflectionProperty(ArenaRunner::class, 'desk'))->getValue($runner);
        $this->assertNotSame($root, $injectedDesk, 'the actual container resolves independent command and arena desks');
        [$first, $second] = $this->stopTestSeats();

        $runs = $runner->cycle($root);

        $this->assertTrue($root->stopRequested());
        $this->assertFalse($injectedDesk->stopRequested());
        $this->assertStoppedSeatRun($runs, $first, $second);
        $this->assertStringContainsString('termination requested', DeskRun::sole()->error);
    }

    public function test_desk_run_passes_its_signal_root_to_the_real_arena_runner(): void
    {
        $commandRoot = null;
        $market = app(MarketData::class);
        $marketProxy = \Mockery::mock($market)->makePartial();
        $marketProxy->shouldReceive('ticker')->with('BTC-USD', 1)->once()->andReturnUsing(function () use (&$commandRoot, $market) {
            $commandRoot->requestStop();

            return $market->ticker('BTC-USD', 1);
        });
        $this->app->instance(MarketData::class, $marketProxy);
        $constructor = (new \ReflectionClass(Desk::class))->getConstructor();
        $dependencies = array_map(fn ($parameter) => app($parameter->getType()->getName()), $constructor->getParameters());
        $commandRoot = \Mockery::mock(Desk::class, $dependencies)->makePartial();
        // Isolate the command's arena phase while retaining its real dependency resolution and signal-root wiring.
        $commandRoot->shouldReceive('riskSweep')->once()->andReturn([]);
        $commandRoot->shouldReceive('cycle')->once()->andReturn(new DeskRun([
            'id' => 0, 'status' => 'done', 'products_scanned' => 0,
            'candidates' => 0, 'passed' => 0, 'rejected' => 0, 'filled' => 0,
        ]));
        $arenaInjectedDesk = app(Desk::class);
        $resolutions = 0;
        $this->app->bind(Desk::class, function () use (&$resolutions, $commandRoot, $arenaInjectedDesk) {
            return ++$resolutions === 1 ? $commandRoot : $arenaInjectedDesk;
        });
        [$first, $second] = $this->stopTestSeats();

        $this->artisan('desk:run', ['--once' => true])->assertSuccessful();

        $this->assertGreaterThanOrEqual(2, $resolutions);
        $this->assertTrue($commandRoot->stopRequested(), json_encode(DeskEvent::pluck('message')->all()));
        $this->assertFalse($arenaInjectedDesk->stopRequested());
        $runs = DeskRun::all()->map(fn (DeskRun $run) => ['seat_id' => $run->arena_seat_id])->all();
        $this->assertStoppedSeatRun($runs, $first, $second);
    }

    private function stopTestSeats(): array
    {
        return [
            ArenaSeat::create(['label' => 'First', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'active']),
            ArenaSeat::create(['label' => 'Second', 'strategy_key' => 'always_enter', 'starting_cash' => 500, 'status' => 'active']),
        ];
    }

    private function assertStoppedSeatRun(array $runs, ArenaSeat $first, ArenaSeat $second): void
    {
        $this->assertCount(1, $runs);
        $this->assertSame($first->id, $runs[0]['seat_id']);
        $this->assertSame(1, DeskRun::count());
        $this->assertSame(0, DeskRun::where('arena_seat_id', $second->id)->count(), 'no later seat scan may start');
        $this->assertSame(0, Fill::count());
        $this->assertSame(0, Position::count());
        $this->assertSame(0, PaperLedger::where('amount', '<', 0)->count(), 'paper entry cannot debit cash after stop');
        $this->assertSame(0, PaperLedger::seat($second->id)->count());
        $this->assertEquals(1000, PaperLedger::seat($first->id)->sum('amount'));
    }
}
