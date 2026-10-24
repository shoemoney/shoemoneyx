<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Desk;
use App\Desk\Execution\Executor;
use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PerpsSession;
use App\Desk\Execution\ReconcilesOrders;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\DeskEvent;
use App\Models\Fill;
use App\Models\OrderIntent;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Fixtures\CloseOnCallStrategy;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\TestCase;

/**
 * Spot and perps are both mode 'live' and share desk product ids, so a position must stay pinned
 * to the venue that opened it: flipping the perps setting must not send its exit to the other venue.
 */
class DeskPositionVenuePinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function liveExecutor(string $venue, ?OrderResult $reconciled = null): Executor
    {
        return new class($venue, $reconciled) implements Executor, ReconcilesOrders
        {
            public int $orders = 0;

            public function __construct(private string $venue, private ?OrderResult $reconciled) {}

            public function orderVenue(): string
            {
                return $this->venue;
            }

            public function mode(): string
            {
                return 'live';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                $this->orders++;

                return new OrderResult('filled', $usd, $usd, $usd / $decisionPrice, $decisionPrice, $decisionPrice, 0.0);
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                $this->orders++;

                return new OrderResult('filled', $qty * $decisionPrice, $qty * $decisionPrice, $qty, $decisionPrice, $decisionPrice, 0.0);
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                $this->orders++;

                return new OrderResult('filled', $usd, $usd, $usd / $decisionPrice, $decisionPrice, $decisionPrice, 0.0);
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                $this->orders++;

                return new OrderResult('filled', $entryUsdShare, $entryUsdShare, $qty, $decisionPrice, $decisionPrice, 0.0);
            }

            public function cash(): float
            {
                return 10000.0;
            }

            public function reconcile(OrderIntent $intent): ?OrderResult
            {
                return $this->reconciled;
            }

            public function confirmedAbsent(OrderIntent $intent): bool
            {
                return false;
            }
        };
    }

    private function livePosition(?string $venue, array $overrides = []): Position
    {
        return Position::create(array_merge([
            'mode' => 'live', 'venue' => $venue, 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 0.03, 'entry_price' => 80000.0, 'entry_usd' => 2400.0, 'fees_usd' => 0.0,
            'peak_price' => 80000.0, 'last_price' => 80000.0, 'opened_at' => now(), 'meta' => [],
        ], $overrides));
    }

    private function pendingIntent(string $venue, string $method): OrderIntent
    {
        return OrderIntent::create([
            'mode' => 'live', 'venue' => $venue, 'desk_product_id' => 'BTC-USD',
            'venue_product_id' => $venue === 'coinbase_perps' ? 'BIP-20DEC30-CDE' : 'BTC-USD',
            'method' => $method, 'side' => in_array($method, ['buy', 'cover_short'], true) ? 'BUY' : 'SELL',
            'client_order_id' => "venue-reconcile-{$venue}-{$method}", 'status' => 'pending',
            'requested_usd' => 800, 'decision_price' => 80000,
        ]);
    }

    public static function reconcileVenues(): array
    {
        $cases = [];
        foreach (['coinbase_spot', 'coinbase_perps'] as $venue) {
            foreach (['buy', 'sell', 'open_short', 'cover_short'] as $method) {
                $cases["{$venue}-{$method}"] = [$venue, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('reconcileVenues')]
    public function test_reconciliation_records_an_orphan_instead_of_changing_another_venues_position(string $venue, string $method): void
    {
        $otherVenue = $venue === 'coinbase_spot' ? 'coinbase_perps' : 'coinbase_spot';
        $position = $this->livePosition($otherVenue, ['side' => in_array($method, ['open_short', 'cover_short'], true) ? 'short' : 'long']);
        $before = $position->fresh()->getAttributes();
        $intent = $this->pendingIntent($venue, $method);
        $executor = $this->liveExecutor($venue, new OrderResult('filled', 800, 800, 0.01, 80000, 80000, 0));
        $desk = app(Desk::class);

        $desk->reconcileLiveOrders(new FixedTicketStrategy(800), $executor);

        $this->assertSame($before, $position->fresh()->getAttributes(), 'a venue fill cannot change a holding on another venue');
        $this->assertSame(1, Position::count(), 'a conflict requires manual recovery, not an implicit second venue position');
        $fill = Fill::sole();
        $this->assertNull($fill->position_id, 'orphan fill must not be linked to the unrelated position');
        $this->assertStringContainsString('ORPHAN LIVE FILL', $fill->note);
        $this->assertStringContainsString($venue, $fill->note);
        $this->assertSame('resolved', $intent->fresh()->status);
        $this->assertSame('filled', $intent->fresh()->outcome, 'the venue outcome is known even though position recovery is manual');
        $this->assertStringContainsString('manual recovery required', $intent->fresh()->note);
        $this->assertTrue(DeskEvent::where('level', 'error')->where('message', 'like', '%ORPHAN LIVE FILL%')->exists());
        $this->assertFalse(DeskEvent::where('message', 'like', 'RECONCILED%')->exists(), 'an orphan is not reported as a booked position');
        $this->assertSame(0, $executor->orders);

        $desk->reconcileLiveOrders(new FixedTicketStrategy(800), $executor);
        $this->assertSame(1, Fill::count(), 'confirmed orphan fills are recorded exactly once');
    }

    #[DataProvider('reconcileVenues')]
    public function test_reconciliation_prefers_the_matching_pinned_venue_even_if_another_venue_row_is_first(string $venue, string $method): void
    {
        $side = in_array($method, ['open_short', 'cover_short'], true) ? 'short' : 'long';
        $otherVenue = $venue === 'coinbase_spot' ? 'coinbase_perps' : 'coinbase_spot';
        $unrelated = $this->livePosition($otherVenue, ['side' => $side]);
        $before = $unrelated->fresh()->getAttributes();
        $matching = $this->livePosition($venue, ['side' => $side]);
        $intent = $this->pendingIntent($venue, $method);
        $executor = $this->liveExecutor($venue, new OrderResult('filled', 800, 800, 0.01, 80000, 80000, 0));

        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(800), $executor);

        $this->assertSame($before, $unrelated->fresh()->getAttributes());
        $exit = in_array($method, ['sell', 'cover_short'], true);
        $this->assertEqualsWithDelta($exit ? 0.02 : 0.04, $matching->fresh()->quantity, 1e-9);
        $this->assertEqualsWithDelta($exit ? 1600 : 3200, $matching->fresh()->entry_usd, 1e-6);
        $this->assertSame($matching->id, Fill::sole()->position_id);
        $this->assertSame($exit ? 'exit' : 'add', Fill::sole()->kind);
        $this->assertSame('filled', $intent->fresh()->outcome);
        $this->assertFalse(DeskEvent::where('message', 'like', '%ORPHAN LIVE FILL%')->exists());
        $this->assertSame(0, $executor->orders);
    }

    public function test_manual_filled_resolution_discloses_orphan_recovery(): void
    {
        $this->livePosition('coinbase_perps');
        $intent = $this->pendingIntent('coinbase_spot', 'sell');
        $executor = $this->liveExecutor('coinbase_spot', new OrderResult('filled', 800, 800, 0.01, 80000, 80000, 0));

        [$done, $message] = app(Desk::class)->resolveIntent($executor, $intent, 'filled');

        $this->assertTrue($done);
        $this->assertStringContainsString('manual recovery required', $message);
        $this->assertNull(Fill::sole()->position_id);
    }

    public function test_a_spot_position_is_not_closed_while_perps_is_the_configured_executor(): void
    {
        $perps = $this->liveExecutor('coinbase_perps');
        $p = $this->livePosition('coinbase_spot');

        $fill = app(Desk::class)->close($p, 'stop', 80000.0, $perps);

        $this->assertNull($fill);
        $this->assertSame(0, $perps->orders, 'no order may reach the wrong venue');
        $this->assertSame('open', $p->fresh()->status);
        $this->assertSame(0, Fill::count());
        $this->assertTrue(DeskEvent::where('level', 'error')->where('message', 'like', '%CLOSE (stop) REFUSED%coinbase_spot%coinbase_perps%')->exists());
    }

    public function test_a_perps_position_is_not_closed_by_the_spot_executor(): void
    {
        $spot = $this->liveExecutor('coinbase_spot');
        $p = $this->livePosition('coinbase_perps', ['side' => 'short']);

        $this->assertNull(app(Desk::class)->close($p, 'stop', 80000.0, $spot));
        $this->assertSame(0, $spot->orders);
        $this->assertSame('open', $p->fresh()->status);
    }

    public function test_a_matching_venue_still_closes(): void
    {
        $spot = $this->liveExecutor('coinbase_spot');
        $p = $this->livePosition('coinbase_spot');

        $fill = app(Desk::class)->close($p, 'stop', 80000.0, $spot);

        $this->assertNotNull($fill);
        $this->assertSame(1, $spot->orders);
        $this->assertSame('closed', $p->fresh()->status);
    }

    public function test_trim_is_refused_on_a_venue_mismatch(): void
    {
        $perps = $this->liveExecutor('coinbase_perps');
        $p = $this->livePosition('coinbase_spot');

        $this->assertNull(app(Desk::class)->trim($p, 0.5, 'tp1', 80000.0, $perps));
        $this->assertSame(0, $perps->orders);
        $this->assertEqualsWithDelta(0.03, $p->fresh()->quantity, 1e-9);
        $this->assertTrue(DeskEvent::where('level', 'error')->where('message', 'like', '%TRIM (tp1) REFUSED%')->exists());
    }

    /** A strategy whose RISK step always asks to close, so the sweep reaches the order path for any position it manages. */
    private function closeEveryRiskCall(): void
    {
        config(['desk.strategies.close_on_call_test' => CloseOnCallStrategy::class, 'desk.strategy' => 'close_on_call_test']);
        $this->app->instance(CloseOnCallStrategy::class, new CloseOnCallStrategy(2400.0, 'BTC-USD', 'long', closeOnCall: 1));
    }

    private function stubLiveStats(float $price): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $this->app->bind(ProductStatsBuilder::class, fn () => new class(app(MarketData::class), app(CandleStore::class), $price) extends ProductStatsBuilder
        {
            public function __construct(MarketData $m, CandleStore $c, private float $price)
            {
                parent::__construct($m, $c);
            }

            public function live(Product $product, bool $withBook = false): ProductStats
            {
                return ProductStats::fromArray(['product_id' => $product->product_id, 'price' => $this->price]);
            }
        });
    }

    public function test_risk_sweep_refuses_an_actionable_close_on_another_venue_but_closes_on_the_right_one(): void
    {
        $this->closeEveryRiskCall();
        $this->stubLiveStats(80000.0);
        $p = $this->livePosition('coinbase_spot');
        $desk = app(Desk::class);

        $perps = $this->liveExecutor('coinbase_perps');
        $out = $desk->runRiskSweep($desk->strategy(), $perps);

        $this->assertSame('venue_refused', $out[0]['action']);
        $this->assertSame(0, $perps->orders);
        $this->assertSame('open', $p->fresh()->status);

        // Control: the same sweep through the matching venue really does reach the order path.
        $this->closeEveryRiskCall();
        $spot = $this->liveExecutor('coinbase_spot');
        $desk->runRiskSweep($desk->strategy(), $spot);

        $this->assertSame(1, $spot->orders);
        $this->assertSame('closed', $p->fresh()->status);
    }

    public function test_a_venue_refusal_does_not_walk_the_position_into_force_close_terminal(): void
    {
        config(['desk.risk.max_zero_price_sweeps' => 1, 'desk.risk.force_close_backoff_sweeps' => 1, 'desk.risk.force_close_max_attempts' => 2]);
        $this->closeEveryRiskCall();   // no Product row, so stats are unavailable and the force-close ladder is in play
        $p = $this->livePosition('coinbase_spot');
        $desk = app(Desk::class);
        $perps = $this->liveExecutor('coinbase_perps');

        for ($i = 0; $i < 5; $i++) {
            $out = $desk->runRiskSweep($desk->strategy(), $perps);
            $this->assertSame('venue_refused', $out[0]['action']);
        }
        $this->assertSame(0, (int) ($p->fresh()->meta['force_close_attempts'] ?? 0), 'a refusal is not a failed close attempt');
        $this->assertSame(0, $perps->orders);

        // The operator restores the right venue: the ladder is still live and the close goes out.
        $spot = $this->liveExecutor('coinbase_spot');
        $desk->runRiskSweep($desk->strategy(), $spot);
        $this->assertSame(1, $spot->orders);
        $this->assertSame('closed', $p->fresh()->status);
    }

    public function test_a_risk_add_is_refused_on_a_venue_mismatch(): void
    {
        $perps = $this->liveExecutor('coinbase_perps');
        $p = $this->livePosition('coinbase_spot');
        $desk = app(Desk::class);

        $add = new \ReflectionMethod($desk, 'addFromRisk');
        $fill = $add->invoke($desk, $p, RiskDecision::add('pyramid', 500.0, null), $desk->context($desk->strategy(), false, 'live'), $perps);

        $this->assertNull($fill);
        $this->assertSame(0, $perps->orders);
        $this->assertEqualsWithDelta(0.03, $p->fresh()->quantity, 1e-9);
        $this->assertTrue(DeskEvent::where('level', 'error')->where('message', 'like', '%ADD REFUSED%')->exists());
    }

    public function test_deleverage_skips_a_worse_position_on_another_venue_and_reduces_the_perps_one(): void
    {
        config(['desk.perps.deleverage_buffer_pct' => 15]);
        $spotLoser = $this->livePosition('coinbase_spot', ['product_id' => 'BTC-USD', 'last_price' => 40000.0]);
        $perpsPos = $this->livePosition('coinbase_perps', ['product_id' => 'ETH-USD', 'last_price' => 79000.0]);
        $perps = $this->liveExecutor('coinbase_perps');
        $desk = app(Desk::class);

        $session = new PerpsSession(MarginWindow::Intraday, null, 0.0, 0.0, 0.0, 5.0);
        $m = new \ReflectionMethod($desk, 'deleverageIfBufferLow');
        $m->invoke($desk, $session, $perps);

        $this->assertSame('open', $spotLoser->fresh()->status);
        $this->assertSame('closed', $perpsPos->fresh()->status);
        $this->assertSame(1, $perps->orders);
    }

    public function test_a_position_on_the_matching_venue_may_be_trimmed_and_added_to(): void
    {
        $spot = $this->liveExecutor('coinbase_spot');
        $desk = app(Desk::class);

        $trimmed = $this->livePosition('coinbase_spot');
        $this->assertNotNull($desk->trim($trimmed, 0.5, 'tp1', 80000.0, $spot));
        $this->assertSame(1, $spot->orders);
        $this->assertEqualsWithDelta(0.015, $trimmed->fresh()->quantity, 1e-9);

        // Small enough to sit well inside the desk's add caps (kelly room, original entry size).
        $added = $this->livePosition('coinbase_spot', ['product_id' => 'ETH-USD', 'quantity' => 0.00125, 'entry_usd' => 100.0, 'entry_price' => 80000.0]);
        $add = new \ReflectionMethod($desk, 'addFromRisk');
        $fill = $add->invoke($desk, $added, RiskDecision::add('pyramid', 50.0, null), $desk->context($desk->strategy(), false, 'live'), $spot);

        $this->assertNotNull($fill);
        $this->assertSame(2, $spot->orders);
        $this->assertFalse(DeskEvent::where('message', 'like', '%REFUSED%')->exists());
    }

    public function test_a_null_venue_matches_the_current_executor_and_warns(): void
    {
        $spot = $this->liveExecutor('coinbase_spot');
        $p = $this->livePosition(null);

        $fill = app(Desk::class)->close($p, 'stop', 80000.0, $spot);

        $this->assertNotNull($fill);
        $this->assertSame(1, $spot->orders);
        $this->assertTrue(DeskEvent::where('level', 'warn')->where('message', 'like', '%no pinned venue, assuming coinbase_spot%')->exists());
    }

    public function test_a_legacy_paper_position_also_warns_when_assuming_its_venue(): void
    {
        $position = $this->livePosition(null, ['mode' => 'paper']);
        $executor = \Mockery::mock(Executor::class);
        $executor->shouldReceive('mode')->andReturn('paper');
        $executor->shouldReceive('cash')->andReturn(10000.0);
        $executor->shouldReceive('sell')->once()->andReturn(new OrderResult('filled', 2400.0, 2400.0, 0.03, 80000.0, 80000.0, 0.0));

        $fill = app(Desk::class)->close($position, 'stop', 80000.0, $executor);

        $this->assertNotNull($fill);
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertTrue(DeskEvent::where('level', 'warn')->where('message', 'like', '%no pinned venue, assuming paper%')->exists());
    }

    public function test_entries_are_stamped_with_the_executor_venue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-07 15:00:00', 'UTC'));
        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                // Same price as the fixture's decision price (1.0): a mismatch makes fills.slippage_bps
                // overflow decimal(10,2) on MariaDB, which SQLite never notices.
                return ['best_bid' => 1.0, 'best_ask' => 1.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });
        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
        config(['desk.perps.enabled' => true, 'desk.perps.paper_margin' => true, 'desk.perps.whole_contracts' => false, 'desk.paper.starting_cash' => 10000]);
        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class, 'desk.strategy' => 'fixed_ticket_test']);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(2400.0, 'BTC-USD', 'long'));

        $run = app(Desk::class)->cycle();

        $this->assertSame(1, $run->filled);
        $this->assertSame('paper', Position::sole()->venue);
        Carbon::setTestNow();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function liveVenues(): array
    {
        return [
            'coinbase spot' => ['coinbase_spot', 'long'],
            'coinbase perps' => ['coinbase_perps', 'short'],
            'ccxt exchange' => ['ccxt:kraken', 'long'],
        ];
    }

    #[DataProvider('liveVenues')]
    public function test_live_entries_are_stamped_with_the_executors_venue_id(string $venue, string $side): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-07 15:00:00', 'UTC'));
        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });
        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class, 'desk.strategy' => 'fixed_ticket_test']);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(2400.0, 'BTC-USD', $side));
        $executor = $this->liveExecutor($venue);
        $desk = app(Desk::class);

        $run = $desk->runCycle($desk->strategy(), $executor, null);

        $this->assertSame(1, $run->filled);
        $this->assertSame(1, $executor->orders);
        $this->assertSame($venue, Position::sole()->venue);
        Carbon::setTestNow();
    }

    public function test_the_venue_column_fits_the_longest_ids(): void
    {
        $this->assertTrue(Schema::hasColumn('positions', 'venue'));
        // SQLite stores a bare "varchar", so it cannot show the width; MariaDB (CI) reports varchar(40),
        // matching order_intents.venue. On SQLite pin the declared width in the migration itself.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertStringContainsString("string('venue', 40)", file_get_contents(database_path('migrations/2026_10_01_000001_add_venue_to_positions.php')));
        } else {
            $this->assertStringContainsString('(40)', collect(Schema::getColumns('positions'))->firstWhere('name', 'venue')['type']);
        }
        $id = 'ccxt:'.str_repeat('x', 35);
        $this->livePosition($id);
        $this->assertSame($id, Position::sole()->venue);
    }

    public function test_the_migration_backfills_only_unambiguous_rows(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_add_venue_to_positions.php');
        $migration->down();

        $row = fn (string $pid, array $o = []) => DB::table('positions')->insertGetId(array_merge([
            'mode' => 'live', 'strategy' => 't', 'product_id' => $pid, 'side' => 'long', 'status' => 'open', 'quantity' => 1,
            'entry_price' => 1, 'entry_usd' => 1, 'opened_at' => now(), 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ], $o));
        $intent = fn (string $pid, string $venue, string $outcome = 'filled', string $method = 'buy') => DB::table('order_intents')->insert([
            'mode' => 'live', 'venue' => $venue, 'desk_product_id' => $pid, 'venue_product_id' => $pid, 'method' => $method, 'side' => 'BUY',
            'outcome' => $outcome, 'client_order_id' => uniqid('c'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $paper = $row('P-USD', ['mode' => 'paper']);
        $byIntent = $row('A-USD');
        $intent('A-USD', 'coinbase_spot');
        $short = $row('B-USD', ['side' => 'short']);
        $margin = $row('C-USD', ['meta' => json_encode(['margin_usd' => 50])]);
        $both = $row('D-USD');
        $intent('D-USD', 'coinbase_spot');
        // Case variant on purpose: production's utf8mb4_unicode_ci sees one product, SQLite sees two.
        $intent('d-usd', 'coinbase_perps');
        $unknown = $row('E-USD');
        // A rejected spot buy on the same product never opened this short; the short-position rule must still apply.
        $shortVsRejected = $row('F-USD', ['side' => 'short']);
        $intent('F-USD', 'coinbase_spot', 'rejected');
        // A confirmed spot opening contradicts a position that looks like a perps short: leave it NULL.
        $shortVsFilled = $row('G-USD', ['side' => 'short']);
        $intent('G-USD', 'coinbase_spot');
        // A sell on its own says nothing about where the position was opened.
        $sellOnly = $row('H-USD');
        $intent('H-USD', 'coinbase_spot', 'filled', 'sell');

        $migration->up();

        $venue = fn (int $id) => DB::table('positions')->where('id', $id)->value('venue');
        $this->assertSame('paper', $venue($paper));
        $this->assertSame('coinbase_spot', $venue($byIntent));
        $this->assertSame('coinbase_perps', $venue($short));
        $this->assertSame('coinbase_perps', $venue($margin));
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame('coinbase_spot', $venue($both), 'SQLite compares case-sensitively, so D-USD and d-usd are different products');
        } else {
            $this->assertNull($venue($both), 'under the production collation D-USD and d-usd are one product with two venues: ambiguous');
        }
        $this->assertNull($venue($unknown));
        $this->assertSame('coinbase_perps', $venue($shortVsRejected));
        $this->assertNull($venue($shortVsFilled), 'position shape contradicting the intent evidence is not guessed');
        $this->assertNull($venue($sellOnly));
    }

    public function test_the_migration_can_be_re_run_after_a_partial_backfill(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_add_venue_to_positions.php');
        $row = fn (string $pid, array $o = []) => DB::table('positions')->insertGetId(array_merge([
            'mode' => 'live', 'strategy' => 't', 'product_id' => $pid, 'side' => 'short', 'status' => 'open', 'quantity' => 1,
            'entry_price' => 1, 'entry_usd' => 1, 'opened_at' => now(), 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ], $o));

        // State after a crash mid-backfill: the column exists, one row is done, one is not, one was pinned by the desk.
        $done = $row('A-USD', ['venue' => 'coinbase_perps']);
        $pending = $row('B-USD');
        $pinned = $row('C-USD', ['side' => 'long', 'venue' => 'coinbase_spot']);

        $migration->up();

        $venue = fn (int $id) => DB::table('positions')->where('id', $id)->value('venue');
        $this->assertSame('coinbase_perps', $venue($done));
        $this->assertSame('coinbase_perps', $venue($pending));
        $this->assertSame('coinbase_spot', $venue($pinned), 'a re-run never overwrites an existing venue');
    }

    public function test_the_backfill_covers_more_rows_than_one_batch(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_add_venue_to_positions.php');
        $migration->down();

        $rows = [];
        for ($i = 0; $i < 1005; $i++) {
            $rows[] = [
                'mode' => 'live', 'strategy' => 't', 'product_id' => 'S'.$i.'-USD', 'side' => 'short', 'status' => 'open', 'quantity' => 1,
                'entry_price' => 1, 'entry_usd' => 1, 'opened_at' => now(), 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('positions')->insert($chunk);
        }

        $migration->up();

        $this->assertSame(0, DB::table('positions')->whereNull('venue')->count(), 'offset paging would leave rows past the first 1000 NULL');
        $this->assertSame(1005, DB::table('positions')->where('venue', 'coinbase_perps')->count());
    }
}
