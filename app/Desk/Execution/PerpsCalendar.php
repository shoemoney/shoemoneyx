<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** CFM's fixed weekly schedule, in venue-local time. Pure; no I/O. */
final class PerpsCalendar
{
    private const TZ = 'America/New_York';

    /** Friday 17:00:00-17:59:59 America/New_York — CFM's weekly maintenance halt. */
    public static function halted(\DateTimeImmutable $now): bool
    {
        $et = $now->setTimezone(new \DateTimeZone(self::TZ));

        return $et->format('N') === '5' && (int) $et->format('H') === 17;
    }
}
