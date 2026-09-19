<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\OrderResult;
use Tests\TestCase;

/** Round-4 review, finding 1: ok() is the one boundary every Desk booking path trusts. */
class OrderResultTest extends TestCase
{
    public function test_ok_is_true_for_a_normal_filled_result(): void
    {
        $r = new OrderResult('filled', 200.0, 200.0, 2.0, 100.0, 100.0, 1.2);

        $this->assertTrue($r->ok());
    }

    public function test_ok_is_false_when_fill_price_is_zero(): void
    {
        // CoinbaseExecutor.php's degraded shape: filled_size > 0, no filled_value, no
        // average_filled_price, pre-fix that derived an average of 0.0.
        $r = new OrderResult('filled', 200.0, 1.2, 2.0, 100.0, 0.0, 1.2);

        $this->assertFalse($r->ok());
    }

    public function test_ok_is_false_when_fill_price_is_null(): void
    {
        $r = new OrderResult('filled', 200.0, 200.0, 2.0, 100.0, null, 1.2);

        $this->assertFalse($r->ok());
    }

    /**
     * Round-5 review, IMPORTANT 4: filledUsd is deliberately not part of ok() — a genuine exit
     * whose fee exceeds its proceeds (dust, or a per-contract fee floor) is a real fill with a
     * real, negative filledUsd. Round-4's filledUsd > 0 clause refused to close it outright.
     */
    public function test_ok_is_true_for_a_real_exit_whose_fee_exceeds_proceeds(): void
    {
        $dust = new OrderResult('filled', 0.40, 0.40 - 0.50, 0.004, 100.0, 100.0, 0.50);

        $this->assertEqualsWithDelta(-0.10, $dust->filledUsd, 1e-9, 'the fee genuinely exceeds proceeds here, or this test proves nothing');
        $this->assertTrue($dust->ok(), 'a real fill must still close even when its fee exceeds its proceeds');
    }

    public function test_ok_is_false_when_filled_usd_is_zero_but_fill_price_is_also_missing(): void
    {
        $r = new OrderResult('filled', 200.0, 0.0, 2.0, 100.0, null, 0.0);

        $this->assertFalse($r->ok());
    }

    public function test_ok_is_false_when_filled_qty_is_zero(): void
    {
        $r = new OrderResult('filled', 200.0, 200.0, 0.0, 100.0, 100.0, 1.2);

        $this->assertFalse($r->ok());
    }

    public function test_ok_is_false_when_status_is_not_filled(): void
    {
        $r = new OrderResult('rejected', 200.0, 200.0, 2.0, 100.0, 100.0, 1.2);

        $this->assertFalse($r->ok());
    }

    public function test_recover_fill_basis_falls_back_to_the_decision_price_when_the_average_is_missing(): void
    {
        [$price, $notional] = OrderResult::recoverFillBasis(2.0, null, 0.0, 100.0, 'test-venue', 'o-1');

        $this->assertSame(100.0, $price);
        $this->assertSame(200.0, $notional);
    }

    /**
     * Round-5 review, IMPORTANT 4(a): a Coinbase poll can report average_filled_price present
     * (real) but filled_value absent — round 4's fallback only checked the average, so this
     * shape bypassed it outright and yielded a real average with filledValue stuck at 0.
     */
    public function test_recover_fill_basis_rebuilds_the_value_when_the_average_is_present_but_the_value_is_not(): void
    {
        [$price, $notional] = OrderResult::recoverFillBasis(2.0, 102.0, 0.0, 100.0, 'test-venue', 'o-2');

        $this->assertSame(102.0, $price, 'a real average must not be discarded');
        $this->assertSame(204.0, $notional, 'the value must be rebuilt from the real average, not left at 0');
    }

    public function test_recover_fill_basis_leaves_a_healthy_fill_untouched(): void
    {
        [$price, $notional] = OrderResult::recoverFillBasis(2.0, 100.0, 200.0, 100.0, 'test-venue', 'o-3');

        $this->assertSame(100.0, $price);
        $this->assertSame(200.0, $notional);
    }

    public function test_recover_fill_basis_is_a_no_op_on_a_rejected_order(): void
    {
        [$price, $notional] = OrderResult::recoverFillBasis(0.0, null, 0.0, 100.0, 'test-venue', null);

        $this->assertNull($price);
        $this->assertSame(0.0, $notional);
    }
}
