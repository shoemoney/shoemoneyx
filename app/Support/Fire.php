<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Broadcast an event without letting the websocket server's absence break the work that produced it.
 * A backtest that scored is scored whether or not Reverb was up; a worker on a Pi with no REVERB_* env
 * must not fail its job because the dashboard is not listening.
 */
final class Fire
{
    private static bool $warned = false;

    public static function event(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            if (! self::$warned) {
                self::$warned = true;
                Log::debug('broadcast skipped: '.$e->getMessage(), ['event' => $event::class]);
            }
        }
    }
}
