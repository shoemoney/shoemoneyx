<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\MarginWindow;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use Tests\TestCase;

class PerpsGateTest extends TestCase
{
    private array $cfg = [
        'min_liquidation_buffer_pct' => 30.0,
        'flip_guard_minutes' => 30,
        'max_margin_use_pct' => 50.0,
    ];

    private function perpsSession(
        MarginWindow $window = MarginWindow::Overnight,
        ?\DateTimeImmutable $windowEndsAt = null,
        float $futuresBuyingPower = 100000.0,
        ?float $liquidationBufferPct = 50.0,
    ): PerpsSession {
        return new PerpsSession($window, $windowEndsAt, $futuresBuyingPower, 0.0, 0.0, $liquidationBufferPct);
    }

    public function test_halt_fires_first_regardless_of_everything_else(): void
    {
        $now = new \DateTimeImmutable('2026-09-04T21:30:00Z'); // Friday 17:30 ET
        $gate = PerpsGate::newRisk($this->perpsSession(liquidationBufferPct: 1.0), $now, 1_000_000, $this->cfg);
        $this->assertFalse($gate->allowed);
        $this->assertStringStartsWith('halt:', $gate->reason);
    }

    public function test_liquidation_buffer_floor_fires_before_margin(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z'); // Tuesday, no halt
        $gate = PerpsGate::newRisk($this->perpsSession(liquidationBufferPct: 10.0), $now, 100, $this->cfg);
        $this->assertFalse($gate->allowed);
        $this->assertStringContainsString('liquidation buffer', $gate->reason);
    }

    public function test_null_buffer_skips_rule_two(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z');
        $gate = PerpsGate::newRisk($this->perpsSession(liquidationBufferPct: null), $now, 100, $this->cfg);
        $this->assertTrue($gate->allowed);
    }

    public function test_intraday_flip_guard_fires_near_window_end(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z');
        $endsAt = $now->modify('+10 minutes');
        $gate = PerpsGate::newRisk($this->perpsSession(window: MarginWindow::Intraday, windowEndsAt: $endsAt), $now, 100, $this->cfg);
        $this->assertFalse($gate->allowed);
        $this->assertStringContainsString('overnight margin applies', $gate->reason);
    }

    public function test_intraday_sizes_on_the_overnight_rate(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z');
        $endsAt = $now->modify('+1 hour'); // outside the flip guard
        // Overnight rate 35%: $100,000 notional needs $35,000; buying power $50,000 * 50% cap = $25,000 -> rejected
        $gate = PerpsGate::newRisk($this->perpsSession(window: MarginWindow::Intraday, windowEndsAt: $endsAt, futuresBuyingPower: 50000.0), $now, 100000, $this->cfg);
        $this->assertFalse($gate->allowed);
        $this->assertStringContainsString('at overnight margin', $gate->reason);
    }

    public function test_unknown_window_uses_the_unknown_rate(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z');
        $gate = PerpsGate::newRisk($this->perpsSession(window: MarginWindow::Unknown, futuresBuyingPower: 50000.0), $now, 100000, $this->cfg);
        $this->assertFalse($gate->allowed);
        $this->assertStringContainsString('at unknown margin', $gate->reason);
    }

    public function test_allowed_when_everything_clears(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T15:00:00Z');
        $gate = PerpsGate::newRisk($this->perpsSession(futuresBuyingPower: 100000.0), $now, 1000, $this->cfg);
        $this->assertTrue($gate->allowed);
        $this->assertNull($gate->reason);
    }
}
