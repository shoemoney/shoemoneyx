<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\RiskDecision;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\OrderBudget;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Settings;
use App\Exchange\Contracts\MarketData;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeskRiskAddEntryFenceTest extends TestCase
{
    use RefreshDatabase;

    private const STOPPED = 'termination requested; entry abandoned before submission';

    private const LOST = 'cycle lock ownership lost; abandoning further cycle work';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array', 'desk.mode' => 'paper', 'desk.perps.enabled' => true,
            'desk.perps.paper_margin' => false, 'desk.perps.whole_contracts' => false,
        ]);
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $this->mock(MarketData::class)->shouldReceive('price')->andReturn(100.0);
        PaperLedger::create(['kind' => 'deposit', 'amount' => 9600]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function position(string $side): Position
    {
        return Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => $side,
            'status' => 'open', 'quantity' => 4, 'entry_price' => 100, 'entry_usd' => 400,
            'fees_usd' => 0, 'peak_price' => 100, 'last_price' => 100, 'opened_at' => now(),
            'meta' => ['initial_cost_usd' => 400],
        ]);
    }

    private function executor(?\Closure $onTicker = null): RiskAddFencePaperExecutor
    {
        $market = \Mockery::mock(MarketData::class);
        $market->shouldReceive('price')->andReturn(100.0);
        $market->shouldReceive('ticker')->andReturnUsing(function () use ($onTicker): array {
            $onTicker?->__invoke();

            return ['best_bid' => 100.0, 'best_ask' => 100.0];
        });
        $this->app->instance(MarketData::class, $market);

        return new RiskAddFencePaperExecutor($market, app(Settings::class));
    }

    private function add(Desk $desk, Position $p, PaperExecutor $executor): ?Fill
    {
        return (new \ReflectionMethod(Desk::class, 'addFromRisk'))->invoke(
            $desk, $p, RiskDecision::add('adds.0', 100, 100, 'test add'), new DeskContext([], 'paper'), $executor,
        );
    }

    private function assertUnchanged(Position $p): void
    {
        $this->assertSame(0, Fill::count());
        $this->assertSame(1, PaperLedger::count());
        $this->assertSame(0, $p->fresh()->adds_count);
        $this->assertEqualsWithDelta(4, $p->fresh()->quantity, 1e-9);
        $this->assertEqualsWithDelta(400, $p->fresh()->entry_usd, 1e-9);
    }

    public static function stopBoundaries(): iterable
    {
        foreach (['long', 'short'] as $side) {
            foreach (['before-add', 'cash-sizing', 'executor-preparation'] as $boundary) {
                yield "{$side}-{$boundary}" => [$side, $boundary];
            }
        }
    }

    #[DataProvider('stopBoundaries')]
    public function test_a_risk_add_cannot_create_exposure_after_stop(string $side, string $boundary): void
    {
        $desk = app(Desk::class);
        $triggered = false;
        $stop = function () use ($desk, &$triggered): void {
            $triggered = true;
            $desk->requestStop();
        };
        $executor = $this->executor($boundary === 'executor-preparation' ? $stop : null);
        $p = $this->position($side);
        if ($boundary === 'before-add') {
            $stop();
        } elseif ($boundary === 'cash-sizing') {
            $executor->onCash = $stop;
        }

        $error = null;
        $fill = null;
        try {
            $fill = $this->add($desk, $p, $executor);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        $this->assertNull($fill);
        $this->assertSame($boundary === 'before-add' ? null : self::STOPPED, $error);

        $this->assertTrue($triggered);
        $this->assertSame($boundary === 'executor-preparation' ? [$side] : [], $executor->attempts);
        $this->assertUnchanged($p);
        OrderBudget::assertEntryAllowed(); // The stopped desk's callback must not escape the add scope.
    }

    public static function sides(): iterable
    {
        yield 'long' => ['long'];
        yield 'short' => ['short'];
    }

    public static function ownerBoundaries(): iterable
    {
        foreach (['long', 'short'] as $side) {
            foreach (['cash-sizing', 'executor-preparation'] as $boundary) {
                yield "{$side}-{$boundary}" => [$side, $boundary];
            }
        }
    }

    #[DataProvider('ownerBoundaries')]
    public function test_a_risk_add_rechecks_the_actual_held_cycle_owner_after_preparation(string $side, string $boundary): void
    {
        $replacement = null;
        $replace = function () use (&$replacement): void {
            Cache::lock('desk:cycle:paper')->forceRelease();
            $replacement = Cache::lock('desk:cycle:paper', 3600);
            $this->assertTrue($replacement->get());
            Cache::put('desk:cycle-owner:paper', ['token' => $replacement->owner(), 'beat' => 5], 3600);
        };
        $executor = $this->executor($boundary === 'executor-preparation' ? $replace : null);
        if ($boundary === 'cash-sizing') {
            $executor->onCash = $replace;
        }
        $p = $this->position($side);
        $constructor = (new \ReflectionClass(Desk::class))->getConstructor();
        $dependencies = array_map(fn ($parameter) => app($parameter->getType()->getName()), $constructor->getParameters());
        $desk = \Mockery::mock(Desk::class, $dependencies)->makePartial();
        $desk->shouldReceive('strategy')->andReturn(\Mockery::mock(Strategy::class));
        $desk->shouldReceive('executorForMode')->andReturn($executor);
        $desk->shouldReceive('runCycle')->once()->andReturnUsing(function () use (&$desk, $p, $executor): DeskRun {
            $error = null;
            try {
                $this->add($desk, $p, $executor);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
            $this->assertSame(self::LOST, $error, 'a replaced cycle owner must not add exposure');

            return new DeskRun;
        });

        $desk->cycle();

        $this->assertSame($boundary === 'executor-preparation' ? [$side] : [], $executor->attempts);
        $this->assertUnchanged($p);
        $this->assertTrue($replacement->isOwnedByCurrentProcess(), 'old-cycle cleanup preserves the replacement lock');
        $this->assertSame(5, Cache::get('desk:cycle-owner:paper')['beat']);
        OrderBudget::assertEntryAllowed();
    }

    #[DataProvider('sides')]
    public function test_a_confirmed_risk_add_is_booked_once_when_stop_arrives_after_submission(string $side): void
    {
        $executor = $this->executor();
        $desk = app(Desk::class);
        $p = $this->position($side);
        $executor->afterFill = fn () => $desk->requestStop();
        $outerChecks = 0;

        OrderBudget::withEntryFence(function () use (&$outerChecks): void {
            $outerChecks++;
        }, function () use ($desk, $p, $executor): void {
            $fill = $this->add($desk, $p, $executor);
            $this->assertSame('filled', $fill->status);
            OrderBudget::assertEntryAllowed(); // The enclosing callback is restored after the successful add.
            $this->assertNull($this->add($desk, $p, $executor));
        });

        $this->assertSame(1, $outerChecks);
        $this->assertTrue($desk->stopRequested());
        $this->assertSame([$side], $executor->attempts);
        $this->assertSame(1, Fill::count());
        $this->assertSame(2, PaperLedger::count());
        $this->assertSame(1, $p->fresh()->adds_count);
        $this->assertEqualsWithDelta(500, $p->fresh()->entry_usd, 1e-9);
        $this->assertGreaterThan(4, $p->fresh()->quantity);
        OrderBudget::assertEntryAllowed();
    }
}

/** Real paper execution, with interruption hooks around cash sizing and confirmed submission. */
class RiskAddFencePaperExecutor extends PaperExecutor
{
    public ?\Closure $onCash = null;

    public ?\Closure $afterFill = null;

    public array $attempts = [];

    public function cash(): float
    {
        $hook = $this->onCash;
        $this->onCash = null;
        $hook?->__invoke();

        return parent::cash();
    }

    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        $this->attempts[] = 'long';
        $result = parent::buy($productId, $usd, $decisionPrice);
        $this->afterFill?->__invoke();

        return $result;
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        $this->attempts[] = 'short';
        $result = parent::openShort($productId, $usd, $decisionPrice);
        $this->afterFill?->__invoke();

        return $result;
    }
}
