<?php

declare(strict_types=1);

namespace App\Desk;

/**
 * File heartbeats for the container healthchecks (docker/heartbeat-check.sh reads the mtime).
 * A file, not Redis/artisan: the check must work when Redis or the app boot is the thing that hung.
 */
class Heartbeat
{
    public static function path(string $name): string
    {
        return storage_path("framework/heartbeat-{$name}");
    }

    public static function beat(string $name): void
    {
        @touch(self::path($name));
    }

    /** Seconds since the last beat, or null if there has never been one. */
    public static function age(string $name): ?int
    {
        clearstatcache(true, self::path($name));
        $mtime = @filemtime(self::path($name));

        return $mtime === false ? null : max(0, time() - $mtime);
    }
}
