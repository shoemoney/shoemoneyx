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

    #[Test]
    public function reconcile_pending_cash_out_clamps_the_sold_qty_to_what_was_actually_intended(): void
    {
        // Intended: sell 4.0 of a 4.0-unit reentry lot. Real shrink since emit: 8.0 (this 4.0
        // cash-out plus an unrelated 4.0 out-of-band trim on the same position) — an unclamped
        // `qty_at_emit - quantity` delta folds a negative 4.0 into the ladder below.
        $p = new Position(['product_id' => 'X-USD', 'side' => 'long', 'quantity' => 1.0, 'trims_count' => 3]);
        $p->meta = ['v2' => [
            'ladder' => ['original_qty' => 10.0, 'fired' => [], 'sold' => []],
            'reentries' => [[
                'qty' => 4.0, 'price' => 100.0, 'confirmed' => true, 'cashed_out' => false,
                'cash_out_pending' => [
                    'trims_count_at_emit' => 1, 'qty_at_emit' => 9.0,
                    'lot_qty' => 4.0, 'sell_qty' => 4.0, 'remainder' => 'ladder', 'reset_on_add' => false,
                ],
            ]],
        ]];

        $this->invokeReconcile('reconcilePendingCashOut', $p);

        $this->assertEqualsWithDelta(
            10.0,
            $p->meta['v2']['ladder']['original_qty'],
            1e-9,
            'an out-of-band trim beyond the intended sell_qty must never shrink the ladder below what was actually cashed out'
        );
        $this->assertTrue($p->meta['v2']['reentries'][0]['cashed_out']);
    }

    /**
     * Round-5 review, BLOCKER 2: a legacy cash_out_pending record written before qty_at_emit and
     * sell_qty both existed carries neither — reading $pending['sell_qty'] unguarded threw
     * "Undefined array key sell_qty" on every confirmed cash-out, which propagates out of
     * riskV2() into Desk::riskSweep() (outside its own try), stopping every later position in the
     * sweep from being managed at all. Covers both remainder values (r5_sellqty.php: 'runner'
     * never even reads sell_qty pre-fix; r5_items_1_3_4.php item 4(d): 'ladder' does).
     */
    #[Test]
    public function reconcile_pending_cash_out_never_throws_on_a_legacy_record_with_no_sell_qty(): void
    {
        $ladder = new Position(['product_id' => 'X-USD', 'side' => 'long', 'quantity' => 1.0, 'trims_count' => 3]);
        $ladder->meta = ['v2' => [
            'ladder' => ['original_qty' => 10.0, 'fired' => [], 'sold' => []],
            'reentries' => [[
                'qty' => 4.0, 'cashed_out' => false,
                'cash_out_pending' => ['trims_count_at_emit' => 1, 'qty_at_emit' => 9.0, 'lot_qty' => 4.0, 'remainder' => 'ladder', 'reset_on_add' => false],
            ]],
        ]];

        $this->invokeReconcile('reconcilePendingCashOut', $ladder);

        $this->assertTrue($ladder->meta['v2']['reentries'][0]['cashed_out']);
        $this->assertEqualsWithDelta(10.0, $ladder->meta['v2']['ladder']['original_qty'], 1e-9, 'no recorded intent falls back to lot_qty, selling the whole lot — original_qty must be unchanged');

        $runner = new Position(['product_id' => 'X-USD', 'side' => 'long', 'quantity' => 5.0, 'trims_count' => 2]);
        $runner->meta = ['v2' => ['ladder' => ['original_qty' => 10.0], 'reentries' => [[
            'qty' => 4.0, 'cashed_out' => false,
            'cash_out_pending' => ['trims_count_at_emit' => 1, 'lot_qty' => 4.0, 'remainder' => 'runner'],
        ]]]];

        $this->invokeReconcile('reconcilePendingCashOut', $runner);

        $this->assertTrue($runner->meta['v2']['reentries'][0]['cashed_out']);
    }

    /** Round-5 review, MINOR 5: mirrors reconcilePendingRung()'s own unset — a cash-out sells through the same Desk::trim() stamp. */
    #[Test]
    public function reconcile_pending_cash_out_clears_the_stamp_so_a_later_rung_never_inherits_it(): void
    {
        $p = new Position(['product_id' => 'X-USD', 'side' => 'long', 'quantity' => 1.0, 'trims_count' => 3]);
        $p->meta = ['v2' => [
            'last_trim_fill_price' => 99.0,
            'ladder' => ['original_qty' => 10.0, 'fired' => [], 'sold' => []],
            'reentries' => [[
                'qty' => 4.0, 'confirmed' => true, 'cashed_out' => false,
                'cash_out_pending' => ['trims_count_at_emit' => 1, 'qty_at_emit' => 9.0, 'lot_qty' => 4.0, 'sell_qty' => 4.0, 'remainder' => 'ladder', 'reset_on_add' => false],
            ]],
        ]];

        $this->invokeReconcile('reconcilePendingCashOut', $p);

        $this->assertArrayNotHasKey('last_trim_fill_price', $p->meta['v2'], 'a cash-out must consume the stamp, not leave it for a later ladder rung to inherit');
    }
}
