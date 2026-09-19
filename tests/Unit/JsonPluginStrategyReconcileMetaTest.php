<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\Position;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round-4 review, findings 3 and 4: JsonPluginStrategy's private reconcilePendingRung()/
 * reconcilePendingCashOut() drive `meta.v2` bookkeeping that reconciles a pending TRIM against
 * the position's own trims_count once it actually confirms. Called directly via reflection —
 * the same shape as the reviewer's own reproduction scripts — since both bugs are purely about
 * what these two methods do to `$position->meta`, independent of the rest of risk().
 */
class JsonPluginStrategyReconcileMetaTest extends TestCase
{
    private function invokeReconcile(string $method, Position $position): void
    {
        $strategy = (new \ReflectionClass(JsonPluginStrategy::class))->newInstanceWithoutConstructor();
        $r = new \ReflectionMethod(JsonPluginStrategy::class, $method);
        $r->setAccessible(true);
        $r->invoke($strategy, $position);
    }

    #[Test]
    public function reconcile_pending_rung_clears_the_stamp_so_the_next_rung_never_inherits_it(): void
    {
        $p = new Position(['product_id' => 'X-USD', 'side' => 'long', 'quantity' => 8.0, 'trims_count' => 1]);
        $p->meta = ['v2' => [
            'last_trim_fill_price' => 110.0,
            'ladder' => [
                'fired' => [],
                'sold' => [],
                'pending' => ['idx' => 0, 'qty' => 2.0, 'price' => 110.0, 'trims_count_at_emit' => 0, 'qty_at_emit' => 10.0],
            ],
        ]];

        $this->invokeReconcile('reconcilePendingRung', $p);

        $this->assertEqualsWithDelta(110.0, $p->meta['v2']['ladder']['sold'][0]['price'], 1e-9, 'rung 0 itself still records its own real fill price');
        $this->assertArrayNotHasKey('last_trim_fill_price', $p->meta['v2'], 'the stamp must be consumed, not left for the next rung to inherit');

        // Rung 1 fires later; ITS OWN fill got guarded out to a zero/missing price (Desk::trim()
        // never stamps in that case), so no fresh stamp lands in meta before this call.
        $p->quantity = 6.0;
        $p->trims_count = 2;
        $p->meta = array_replace_recursive($p->meta, [
            'v2' => ['ladder' => ['pending' => ['idx' => 1, 'qty' => 2.0, 'price' => 120.0, 'trims_count_at_emit' => 1, 'qty_at_emit' => 8.0]]],
        ]);

        $this->invokeReconcile('reconcilePendingRung', $p);

        $this->assertEqualsWithDelta(
            120.0,
            $p->meta['v2']['ladder']['sold'][1]['price'],
            1e-9,
            'with no fresh stamp, rung 1 must fall back to its own target — not inherit rung 0s 110.0'
        );
    }
}
