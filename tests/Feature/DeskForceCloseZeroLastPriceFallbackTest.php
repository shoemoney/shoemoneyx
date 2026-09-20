<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\Fill;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-8 review, MAJOR: the escalated close's price fallback used `?? $p->entry_price`, which
 * only substitutes on NULL. A position whose last_price is already 0.0 (a real, non-null float —
 * e.g. stamped by an older bug, or a position that never got a first live price) closed at
 * decision price 0 instead of falling through to entry_price. Asserts the `> 0` chain: last_price
 * wins only if it is actually usable, entry_price is the next fallback, and a position with no
 * usable price anywhere is skipped rather than closed at 0.
 */
class DeskForceCloseZeroLastPriceFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.risk.stale_data_retries' => 0]);
    }

    private function fakeExecutor(): Executor
    {
        return new class implements Executor
        {
            public array $sold = [];

            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                $this->sold[] = [$productId, $decisionPrice];

                return new OrderResult('filled', $qty * $decisionPrice, $qty * $decisionPrice, $qty, $decisionPrice, $decisionPrice, 0.0);
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    private function strategy(): Strategy
    {
        return new class implements Strategy
        {
            public function key(): string
            {
                return 'probe';
            }

            public function name(): string
            {
                return 'probe';
            }

            public function defaults(): array
            {
                return [];
            }

            public function scan(array $candidates, DeskContext $ctx): array
            {
                return [];
            }

            public function vet(CandidateRow $c, Bank $b, DeskContext $ctx): Verdict
            {
                throw new \LogicException('not used in this test');
            }

            public function size(Verdict $v, Bank $b, DeskContext $ctx): SizeDecision
            {
                throw new \LogicException('not used in this test');
            }

            public function risk(Position $p, ProductStats $s, DeskContext $ctx): RiskDecision
            {
                throw new \LogicException('unreachable — a null-stats row must be force-closed before the strategy is ever consulted');
            }
        };
    }

    public function test_a_zero_last_price_falls_through_to_entry_price_instead_of_closing_at_zero(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            // last_price is a real 0.0, not null — the `??` bug's exact trigger.
            'peak_price' => 100.0, 'last_price' => 0.0, 'opened_at' => now()->subDays(10), 'meta' => [],
        ]);

        // No candles at all -> stats() always throws -> statsWithRetries() returns null -> 'unmeasurable' close.
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andThrow(new \RuntimeException('no candles'));
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $executor = $this->fakeExecutor();
        $out = $desk->runRiskSweep($this->strategy(), $executor);

        $this->assertCount(1, $out);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame('unmeasurable', $out[0]['rule']);

        $this->assertSame([['BTC-USD', 100.0]], $executor->sold, 'must close at entry_price (100.0), never at the stale last_price of 0.0');

        $position->refresh();
        $this->assertSame('closed', $position->status);

        $fill = Fill::latest('id')->first();
        $this->assertNotNull($fill);
        $this->assertEqualsWithDelta(100.0, $fill->fill_price, 1e-9, 'the closing fill must price at entry_price, never 0');
    }

    public function test_no_usable_price_anywhere_is_skipped_not_closed_at_zero(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            // Both last_price AND entry_price are non-positive — there is no usable price at all.
            'quantity' => 1.0, 'entry_price' => 0.0, 'entry_usd' => 0.0, 'fees_usd' => 0.0,
            'peak_price' => 0.0, 'last_price' => 0.0, 'opened_at' => now()->subDays(10), 'meta' => [],
        ]);

        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andThrow(new \RuntimeException('no candles'));
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $executor = $this->fakeExecutor();
        $out = $desk->runRiskSweep($this->strategy(), $executor);

        $this->assertSame([['position' => 'BTC-USD', 'action' => 'stale', 'rule' => null]], $out);
        $this->assertSame([], $executor->sold, 'must never attempt to close at a fabricated price of 0');
    }
}
