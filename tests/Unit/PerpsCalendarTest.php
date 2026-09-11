<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\PerpsCalendar;
use Tests\TestCase;

class PerpsCalendarTest extends TestCase
{
    private function et(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('America/New_York'));
    }

    public function test_halted_brackets_the_friday_maintenance_hour(): void
    {
        $this->assertFalse(PerpsCalendar::halted($this->et('2026-09-04 16:59:00'))); // Friday
        $this->assertTrue(PerpsCalendar::halted($this->et('2026-09-04 17:00:00')));
        $this->assertTrue(PerpsCalendar::halted($this->et('2026-09-04 17:59:00')));
        $this->assertFalse(PerpsCalendar::halted($this->et('2026-09-04 18:00:00')));
    }

    public function test_halted_ignores_other_weekdays(): void
    {
        $this->assertFalse(PerpsCalendar::halted($this->et('2026-09-03 17:30:00'))); // Thursday
    }

    public function test_halted_handles_dst_transition(): void
    {
        // Both expressed in UTC so the conversion into America/New_York does the DST work.
        $winter = new \DateTimeImmutable('2026-01-02T22:30:00Z'); // Friday 17:30 EST (UTC-5)
        $summer = new \DateTimeImmutable('2026-07-03T21:30:00Z'); // Friday 17:30 EDT (UTC-4)
        $this->assertTrue(PerpsCalendar::halted($winter));
        $this->assertTrue(PerpsCalendar::halted($summer));
    }
}
