<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\OrderBudget;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Settings;
use App\Exchange\Coinbase\CoinbaseExecutor;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Models\CoinbaseAccount;
use App\Models\Fill;
use App\Models\OrderIntent;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Exchange\FakeCoinbaseVenue;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\TestCase;

class EntrySubmissionFenceTest extends TestCase
{
    use RefreshDatabase;

    private const LOST = 'cycle lock ownership lost; abandoning further cycle work';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.live_orders.poll_sleep_ms' => 0]);
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Real cache-lock replacement and ownership probes, invoked only at physical entry boundaries. */
    private function ownerFence(): array
    {
        $lock = Cache::lock('entry-fence:cycle', 60, 'old-owner');
        $this->assertTrue($lock->get());
        $replace = function () use ($lock): void {
            $lock->forceRelease();
            $this->assertTrue(Cache::lock('entry-fence:cycle', 60, 'new-owner')->get());
        };
        $fence = static function () use ($lock): void {
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new \RuntimeException(self::LOST);
            }
        };

        return [$replace, $fence];
    }

    public static function paperBoundaries(): iterable
    {
        foreach (['buy', 'openShort'] as $method) {
            foreach (['margin', 'whole', 'fractional'] as $accounting) {
                foreach (['ticker', 'cash-lock', 'cash-read', 'ticker-probe-error', 'cash-lock-probe-error'] as $boundary) {
                    yield "{$method}-{$accounting}-{$boundary}" => [$method, $accounting, $boundary];
                }
            }
        }
    }

    #[DataProvider('paperBoundaries')]
    public function test_paper_checks_after_blocking_preparation_before_every_debit(string $method, string $accounting, string $boundary): void
    {
        config([
            'desk.perps.enabled' => true,
            'desk.perps.paper_margin' => $accounting === 'margin',
            'desk.perps.whole_contracts' => $accounting !== 'fractional',
        ]);
        PaperLedger::create(['kind' => 'deposit', 'amount' => 10000]);
        $store = new EntryFenceArrayStore;
        Cache::extend('entry-fence', fn () => Cache::repository($store));
        config(['cache.stores.entry-fence' => ['driver' => 'entry-fence'], 'cache.default' => 'entry-fence']);
        [$replace, $checkOwner] = $this->ownerFence();
        $probeError = false;
        $triggered = false;
        $interrupt = function () use ($replace, $boundary, &$probeError, &$triggered): void {
            $triggered = true;
            if (str_ends_with($boundary, 'probe-error')) {
                $probeError = true;
            } else {
                $replace();
            }
        };
        $fence = static function () use ($checkOwner, &$probeError): void {
            if ($probeError) {
                throw new \RuntimeException('ownership probe unavailable');
            }
            $checkOwner();
        };
        $market = new class extends CoinbaseMarketData
        {
            public ?\Closure $onTicker = null;

            public function ticker(string $productId, int $limit = 100): array
            {
                ($this->onTicker ?? static fn () => null)();

                return ['best_bid' => 80000.0, 'best_ask' => 80000.0, 'trades' => []];
            }
        };
        if (str_starts_with($boundary, 'ticker')) {
            $market->onTicker = $interrupt;
        } elseif (str_starts_with($boundary, 'cash-lock')) {
            $store->onCashLock = $interrupt;
        } else {
            DB::listen(static function ($query) use ($interrupt): void {
                if (str_contains($query->sql, 'sum(') && str_contains($query->sql, 'paper_ledger')) {
                    $interrupt();
                }
            });
        }
        $executor = new PaperExecutor($market, app(Settings::class));
        try {
            OrderBudget::withEntryFence($fence, fn () => $executor->{$method}('BTC-USD', 2400, 80000));
            $this->fail('an entry must not spend after ownership is lost or cannot be checked');
        } catch (\RuntimeException $e) {
            $this->assertSame(str_ends_with($boundary, 'probe-error') ? 'ownership probe unavailable' : self::LOST, $e->getMessage());
        }
        $this->assertTrue($triggered, 'the preparation hook ran before the boundary check');
        $this->assertSame(1, PaperLedger::count());
        $this->assertSame(0, PaperLedger::whereIn('kind', ['buy', 'short'])->count());
    }

    public static function liveEntries(): iterable
    {
        foreach (['buy', 'openShort'] as $method) {
            foreach (['balance', 'margin-window', 'account'] as $boundary) {
                yield "{$method}-{$boundary}" => [$method, $boundary];
            }
        }
    }

    private function liveVenue(): EntryFenceCoinbaseVenue
    {
        CoinbaseAccount::create(['name' => 'fence', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true]);

        return new EntryFenceCoinbaseVenue;
    }

    #[DataProvider('liveEntries')]
    public function test_perps_preparation_cannot_outlive_its_entry_owner(string $method, string $boundary): void
    {
        $venue = $this->liveVenue();
        [$replace, $fence] = $this->ownerFence();
        $triggered = false;
        $interrupt = function () use ($replace, &$triggered): void {
            if (! $triggered) {
                $triggered = true;
                $replace();
            }
        };
        if ($boundary === 'balance') {
            $venue->onBalance = $interrupt;
        } elseif ($boundary === 'margin-window') {
            $venue->onMarginWindow = $interrupt;
        } else {
            DB::listen(static function ($query) use ($interrupt): void {
                if (str_contains($query->sql, 'coinbase_accounts')) {
                    $interrupt();
                }
            });
        }
        try {
            OrderBudget::withEntryFence($fence, fn () => (new CoinbasePerpsExecutor($venue))->{$method}('BTC-USD', 2400, 80000));
            $this->fail('a prepared live entry must recheck its owner before create');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::LOST, $e->getMessage());
        }
        $this->assertTrue($triggered);
        $this->assertSame([], $venue->attempts);
        $this->assertSame('pending', OrderIntent::sole()->status, 'ownership loss is not a venue rejection');
    }

    public function test_absent_pending_entry_is_read_back_but_never_resent_after_takeover(): void
    {
        $venue = $this->liveVenue();
        $executor = new CoinbaseExecutor($venue);
        $venue->timeoutBeforePlacing = true;
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100, 100)->status);
        $intent = OrderIntent::sole();
        $this->travel(3)->minutes();
        $venue->timeoutBeforePlacing = false;
        [$replace, $fence] = $this->ownerFence();
        $venue->onLookup = $replace;
        try {
            OrderBudget::withEntryFence($fence, fn () => $executor->buy('BTC-USD', 100, 100));
            $this->fail('an absent lookup cannot authorize a new send after takeover');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::LOST, $e->getMessage());
        }
        $this->assertNull($venue->onLookup, 'pending lookup was still allowed');
        $this->assertCount(1, $venue->attempts);
        $this->assertSame('pending', $intent->refresh()->status);
        $this->assertNull($intent->resolution);
    }

    public function test_filled_pending_entry_is_returned_and_booked_after_takeover_during_readback(): void
    {
        $venue = $this->liveVenue();
        $executor = new CoinbaseExecutor($venue);
        $venue->timeoutAfterPlacing = true;
        $this->assertSame('unknown', $executor->buy('BTC-USD', 100, 100)->status);
        $venue->timeoutAfterPlacing = false;
        [$replace, $fence] = $this->ownerFence();
        $venue->onLookup = $replace;
        $result = OrderBudget::withEntryFence($fence, fn () => $executor->buy('BTC-USD', 100, 100));
        $this->assertTrue($result->ok());
        $this->assertSame(OrderIntent::sole()->id, $result->intentId);
        $this->assertCount(1, $venue->attempts);

        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100), $executor);
        $this->assertSame(1, Position::count());
        $this->assertSame(1, Fill::count());
        $this->assertSame('resolved', OrderIntent::sole()->status);
    }

    public function test_created_entry_result_survives_takeover_inside_create_and_remains_bookable(): void
    {
        $venue = $this->liveVenue();
        $executor = new CoinbaseExecutor($venue);
        [$replace, $fence] = $this->ownerFence();
        $venue->afterCreate = $replace;
        $result = OrderBudget::withEntryFence($fence, fn () => $executor->buy('BTC-USD', 100, 100));
        $this->assertTrue($result->ok());
        $this->assertSame(OrderIntent::sole()->id, $result->intentId);
        app(Desk::class)->reconcileLiveOrders(new FixedTicketStrategy(100), $executor);
        $this->assertSame(1, Position::count());
        $this->assertSame(1, Fill::count());
        $this->assertSame('resolved', OrderIntent::sole()->status);
        $this->assertCount(1, $venue->attempts);
    }

    public function test_exits_do_not_invoke_a_scoped_entry_fence(): void
    {
        $venue = $this->liveVenue();
        $rejectEntry = static fn () => throw new \RuntimeException(self::LOST);
        $spot = new CoinbaseExecutor($venue);
        $perps = new CoinbasePerpsExecutor($venue);
        $paper = new PaperExecutor(new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 80000.0, 'best_ask' => 80000.0];
            }
        }, app(Settings::class));
        OrderBudget::withEntryFence($rejectEntry, function () use ($spot, $perps, $paper): void {
            $this->assertTrue($spot->sell('BTC-USD', 1, 100)->ok());
            OrderIntent::query()->delete();
            $this->assertTrue($perps->sell('BTC-USD', 0.01, 80000)->ok());
            OrderIntent::query()->delete();
            $this->assertTrue($perps->coverShort('BTC-USD', 0.01, 80000, 800)->ok());
            $sell = $paper->sell('BTC-USD', 0.01, 80000, 800);
            $cover = $paper->coverShort('BTC-USD', 0.01, 80000, 800);
            $sell->writeLedger();
            $cover->writeLedger();
        });
        $this->assertSame(2, PaperLedger::count());
    }

    public function test_scopes_restore_on_success_throw_and_nested_calls_without_leaking_to_later_entries(): void
    {
        $calls = [];
        OrderBudget::withEntryFence(function () use (&$calls): void {
            $calls[] = 'outer';
        }, function () use (&$calls): void {
            OrderBudget::assertEntryAllowed();
            $value = OrderBudget::withEntryFence(function () use (&$calls): void {
                $calls[] = 'inner';
            }, function (): string {
                OrderBudget::assertEntryAllowed();

                return 'returned';
            });
            $this->assertSame('returned', $value);
            OrderBudget::assertEntryAllowed();
            try {
                OrderBudget::withEntryFence(static fn () => throw new \RuntimeException('inner'), static fn () => OrderBudget::assertEntryAllowed());
            } catch (\RuntimeException $e) {
                $this->assertSame('inner', $e->getMessage());
            }
            OrderBudget::assertEntryAllowed();
        });
        OrderBudget::assertEntryAllowed();
        try {
            OrderBudget::withEntryFence(static fn () => throw new \RuntimeException('failed cycle'), static fn () => OrderBudget::assertEntryAllowed());
        } catch (\RuntimeException $e) {
            $this->assertSame('failed cycle', $e->getMessage());
        }
        OrderBudget::assertEntryAllowed();
        $this->assertSame(['outer', 'inner', 'outer', 'outer'], $calls);
        $venue = $this->liveVenue();
        $this->assertTrue((new CoinbaseExecutor($venue))->buy('BTC-USD', 100, 100)->ok(), 'later entries have no stale cycle callback');
    }
}

/** Simulates cash-lock acquisition completing after another owner has taken over the cycle. */
class EntryFenceArrayStore extends ArrayStore
{
    public ?\Closure $onCashLock = null;

    public function lock($name, $seconds = 0, $owner = null)
    {
        if ($name !== 'desk:mutate:paper:cash' || $this->onCashLock === null) {
            return parent::lock($name, $seconds, $owner);
        }
        $hook = $this->onCashLock;
        $this->onCashLock = null;

        return new class($this, $name, $seconds, $owner, $hook) extends ArrayLock
        {
            public function __construct($store, $name, $seconds, $owner, private \Closure $hook)
            {
                parent::__construct($store, $name, $seconds, $owner);
            }

            public function acquire()
            {
                $acquired = parent::acquire();
                if ($acquired) {
                    ($this->hook)();
                }

                return $acquired;
            }
        };
    }
}

class EntryFenceCoinbaseVenue extends FakeCoinbaseVenue
{
    public ?\Closure $onBalance = null;

    public ?\Closure $onMarginWindow = null;

    public ?\Closure $afterCreate = null;

    public function futuresBalance(CoinbaseAccount $account): array
    {
        ($this->onBalance ?? static fn () => null)();

        return ['futures_buying_power' => ['value' => '100000'], 'available_margin' => ['value' => '35000']];
    }

    public function marginWindow(CoinbaseAccount $account): array
    {
        ($this->onMarginWindow ?? static fn () => null)();

        return ['margin_window_type' => 'MARGIN_WINDOW_TYPE_OVERNIGHT'];
    }

    public function marketBuy(CoinbaseAccount $account, string $productId, float $quoteUsd, ?string $clientOrderId = null): array
    {
        $create = parent::marketBuy($account, $productId, $quoteUsd, $clientOrderId);
        ($this->afterCreate ?? static fn () => null)();

        return $create;
    }
}
