<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\PerpsSession;
use Tests\TestCase;

class PerpsSessionTest extends TestCase
{
    public function test_from_api_reads_the_rest_shape(): void
    {
        $balance = [
            'futures_buying_power' => ['value' => '12345.67', 'currency' => 'USD'],
            'initial_margin' => ['value' => '1000.00', 'currency' => 'USD'],
            'available_margin' => ['value' => '11345.67', 'currency' => 'USD'],
            'liquidation_threshold' => ['value' => '500.00', 'currency' => 'USD'],
            'liquidation_buffer_amount' => ['value' => '2000.00', 'currency' => 'USD'],
            'liquidation_buffer_percentage' => ['value' => '42.5', 'currency' => 'USD'],
        ];
        $window = ['margin_window_type' => 'MARGIN_WINDOW_TYPE_INTRADAY', 'end_time' => '2026-09-01T16:00:00Z'];

        $s = PerpsSession::fromApi($balance, $window);

        $this->assertSame(MarginWindow::Intraday, $s->window);
        $this->assertSame(12345.67, $s->futuresBuyingPower);
        $this->assertSame(1000.00, $s->initialMargin);
        $this->assertSame(11345.67, $s->availableMargin);
        $this->assertSame(42.5, $s->liquidationBufferPct);
        $this->assertNotNull($s->windowEndsAt);
        $this->assertSame(1788278400, $s->windowEndsAt->getTimestamp());
    }

    public function test_from_api_handles_missing_fields(): void
    {
        $s = PerpsSession::fromApi([], []);

        $this->assertSame(MarginWindow::Unknown, $s->window);
        $this->assertSame(0.0, $s->futuresBuyingPower);
        $this->assertSame(0.0, $s->initialMargin);
        $this->assertSame(0.0, $s->availableMargin);
        $this->assertNull($s->liquidationBufferPct);
        $this->assertNull($s->windowEndsAt);
    }

    public function test_seconds_to_window_end(): void
    {
        $s = PerpsSession::fromApi([], ['margin_window_type' => 'MARGIN_WINDOW_TYPE_OVERNIGHT', 'end_time' => '2026-09-01T16:00:00Z']);
        $now = new \DateTimeImmutable('2026-09-01T15:59:00Z');
        $this->assertSame(60, $s->secondsToWindowEnd($now));
    }

    public function test_seconds_to_window_end_null_when_no_end_time(): void
    {
        $s = PerpsSession::fromApi([], []);
        $this->assertNull($s->secondsToWindowEnd(new \DateTimeImmutable()));
    }

    public function test_rest_sends_the_buffer_as_a_bare_string(): void
    {
        $s = PerpsSession::fromApi(['liquidation_buffer_percentage' => '1000', 'futures_buying_power' => ['value' => '3003.92', 'currency' => 'USD']], []);

        $this->assertSame(1000.0, $s->liquidationBufferPct);
        $this->assertSame(3003.92, $s->futuresBuyingPower);
        $this->assertNull(PerpsSession::fromApi(['liquidation_buffer_percentage' => ''], [])->liquidationBufferPct, 'an empty string is unknown, not zero');
    }
}
