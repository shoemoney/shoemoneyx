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

    public function test_ok_is_false_when_filled_usd_is_zero_even_with_a_positive_price(): void
    {
        $r = new OrderResult('filled', 200.0, 0.0, 2.0, 100.0, 100.0, 0.0);

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
}
