<?php

declare(strict_types=1);

namespace App\Desk;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One per-IP bucket of failed master-password guesses, shared by the X-Desk-Token header and
 * the /login form so the two paths cannot be combined for double the attempts. Only failures
 * count; a correct guess is never slowed.
 */
final class DeskAuthThrottle
{
    public const MAX_FAILURES = 10;

    private const WINDOW_SECONDS = 60;

    public static function tooManyFailures(Request $request): bool
    {
        return RateLimiter::tooManyAttempts(self::key($request), self::MAX_FAILURES);
    }

    public static function recordFailure(Request $request): void
    {
        RateLimiter::hit(self::key($request), self::WINDOW_SECONDS);
    }

    public static function lockout(Request $request): never
    {
        $retryAfter = max(1, RateLimiter::availableIn(self::key($request)));

        abort(429, 'too many failed attempts', ['Retry-After' => (string) $retryAfter]);
    }

    private static function key(Request $request): string
    {
        return 'desk-auth-failures:'.$request->ip();
    }
}
