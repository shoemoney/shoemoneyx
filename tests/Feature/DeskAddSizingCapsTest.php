<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\RiskDecision;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RISK-driven adds/re-entries skip the SIZE stage, so Desk::addFromRisk() clamps them itself: each add
 * <= the original entry, at most 5 per position, the whole position <= 2x the Kelly cap, and the add
 * <= free cash and leverage room. The recording executor reports what was actually sent to the venue.
 */
class DeskAddSizingCapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        $this->mock(MarketData::class, fn ($m) => $m->shouldReceive('price')->andReturn(100.0));
    }

    private function executor(float $cash): object
    {
        return new class($cash) implements Executor
        {
            public array $buys = [];

            public function __construct(private float $cash) {}

            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                $this->buys[] = $usd;

                return new OrderResult('filled', $usd, $usd, $usd / $decisionPrice, $decisionPrice, $decisionPrice, 0.0);
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                throw new \LogicException('unused');
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
                return $this->cash;
            }
        };
    }

    private function position(float $entryUsd, array $attrs = [], array $meta = []): Position
    {
        return Position::create($attrs + [
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => $entryUsd / 100, 'entry_price' => 100.0, 'entry_usd' => $entryUsd, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => $meta + ['initial_cost_usd' => $entryUsd],
        ]);
    }

    /** @return float|null the dollars actually sent to the venue, null when nothing was */
    private function add(Position $p, object $executor, float $asked, array $params = []): ?float
    {
        $m = new \ReflectionMethod(Desk::class, 'addFromRisk');
        $m->setAccessible(true);
        $m->invoke(app(Desk::class), $p, RiskDecision::add('adds.0', $asked, 100.0, 'test add'), new DeskContext($params, 'paper'), $executor);

        return $executor->buys[0] ?? null;
    }

    public function test_an_add_is_never_bigger_than_the_original_entry(): void
    {
        // equity $10,000, free cash $7,600, Kelly 2x leaves $800: the $400 initial cost is what binds.
        $sent = $this->add($this->position(400), $this->executor(9600), 5000);

        $this->assertEqualsWithDelta(400.0, $sent, 0.01);
    }

    public function test_the_whole_position_never_exceeds_twice_the_kelly_cap(): void
    {
        // equity $10,000 -> 2 x 6% = $1,200; $1,000 already in, so only $200 may be added (not the $1,000 initial cost).
        $sent = $this->add($this->position(1000), $this->executor(9000), 500);

        $this->assertEqualsWithDelta(200.0, $sent, 0.01);
    }

    public function test_an_add_never_exceeds_free_cash(): void
    {
        // equity $550, 20% locked, $400 already working: free cash is $40. Kelly 2x and the initial cost are looser.
        $sent = $this->add($this->position(400), $this->executor(150), 400, ['size' => ['kelly_cap_pct' => 1.0]]);

        $this->assertEqualsWithDelta(40.0, $sent, 0.01);
    }

    public function test_an_add_never_exceeds_the_leverage_room(): void
    {
        // $8,000 notional on $1,000 margin, $9,000 cash: equity $10,000, 1x leaves $2,000 of room.
        // Free cash ($7,000), Kelly 2x ($12,000) and the initial cost ($8,000) are all looser.
        $p = $this->position(8000, [], ['margin_usd' => 1000]);
        $sent = $this->add($p, $this->executor(9000), 5000, ['size' => ['kelly_cap_pct' => 1.0, 'max_leverage' => 1.0]]);

        $this->assertEqualsWithDelta(2000.0, $sent, 0.01);
    }

    public function test_a_sixth_add_or_reentry_is_refused(): void
    {
        $executor = $this->executor(9600);
        $p = $this->position(400, ['adds_count' => 5]);

        $this->assertNull($this->add($p, $executor, 100));
        $this->assertSame(5, $p->fresh()->adds_count);
    }

    public function test_the_fifth_add_is_still_allowed(): void
    {
        $executor = $this->executor(9600);
        $p = $this->position(400, ['adds_count' => 4]);

        $this->assertEqualsWithDelta(100.0, $this->add($p, $executor, 100), 0.01);
        $this->assertSame(5, $p->fresh()->adds_count);
    }

    public function test_an_add_the_caps_shrink_below_the_minimum_ticket_is_not_sent(): void
    {
        // $1,195 in against a $1,200 whole-position ceiling leaves $5 < the $10 minimum ticket.
        $executor = $this->executor(8805);

        $this->assertNull($this->add($this->position(1195), $executor, 500));
    }
}
