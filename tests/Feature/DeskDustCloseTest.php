<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskDustCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function executor(OrderResult $r): Executor
    {
        return new class($r) implements Executor
        {
            public function __construct(private OrderResult $r) {}

            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('unused');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                return $this->r;
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('unused');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \LogicException('unused');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    private function position(float $qty, float $entryUsd): Position
    {
        return Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => $qty, 'entry_price' => 100.0, 'entry_usd' => $entryUsd, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
    }

    public function test_a_whole_holding_below_the_minimum_closes_as_a_write_off(): void
    {
        $dust = (new OrderResult('filled', 3.0, 0, 0, 100.0, null, 0, raw: ['dust' => true]))->withDust(0.03);
        $p = $this->position(0.03, 3.0);

        $fill = app(Desk::class)->close($p, 'stop', 100.0, $this->executor($dust));

        $p->refresh();
        $this->assertSame('closed', $p->status);
        $this->assertEqualsWithDelta(0.0, (float) $p->quantity, 1e-12);
        $this->assertEqualsWithDelta(-3.0, (float) $p->pnl_usd, 1e-9, 'the unsellable cost is written off, not hidden');
        $this->assertEqualsWithDelta(100.0, (float) $p->exit_price, 1e-9);
        $this->assertSame('filled', $fill->status);
    }

    public function test_a_dust_only_holding_at_the_epsilon_is_still_written_off(): void
    {
        $dust = (new OrderResult('filled', 0.0, 0, 0, 100.0, null, 0, raw: ['dust' => true]))->withDust(1e-8);
        $p = $this->position(1e-8, 0.5);

        app(Desk::class)->close($p, 'stop', 100.0, $this->executor($dust));

        $p->refresh();
        $this->assertSame('closed', $p->status);
        $this->assertEqualsWithDelta(0.0, (float) $p->quantity, 1e-15);
        $this->assertEqualsWithDelta(0.0, (float) $p->entry_usd, 1e-12);
        $this->assertEqualsWithDelta(-0.5, (float) $p->pnl_usd, 1e-9);
    }

    public function test_a_sub_increment_tail_after_a_real_fill_closes_instead_of_staying_open(): void
    {
        $filled = (new OrderResult('filled', 12.0, 12.0, 0.12, 100.0, 100.0, 0.0))->withDust(0.0034);
        $p = $this->position(0.1234, 12.34);

        app(Desk::class)->close($p, 'tp', 100.0, $this->executor($filled));

        $p->refresh();
        $this->assertSame('closed', $p->status);
        $this->assertEqualsWithDelta(0.0, (float) $p->quantity, 1e-12);
        $this->assertEqualsWithDelta(-0.34, (float) $p->pnl_usd, 1e-9, 'only the dust tail cost is lost');
    }

    public function test_a_genuine_partial_fill_still_keeps_the_remainder_open(): void
    {
        $partial = new OrderResult('filled', 12.0, 6.0, 0.06, 100.0, 100.0, 0.0, partial: true);
        $p = $this->position(0.12, 12.0);

        app(Desk::class)->close($p, 'tp', 100.0, $this->executor($partial));

        $p->refresh();
        $this->assertSame('open', $p->status);
        $this->assertEqualsWithDelta(0.06, (float) $p->quantity, 1e-9);
    }
}
