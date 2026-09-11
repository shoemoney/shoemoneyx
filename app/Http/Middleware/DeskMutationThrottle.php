<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Rate-limit expensive desk actions independently of private-network access. */
class DeskMutationThrottle
{
    /** desk/{action} values that are expensive enough to rate-limit. */
    private const THROTTLED_ACTIONS = ['cycle', 'risk', 'paper-reset'];

    private const THROTTLE_MAX_ATTEMPTS = 30;

    private const THROTTLE_DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->route('action'), self::THROTTLED_ACTIONS, true)) {
            $key = 'desk-mutation:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::THROTTLE_MAX_ATTEMPTS)) {
                abort(429, 'too many desk mutations, slow down', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, self::THROTTLE_DECAY_SECONDS);
        }

        return $next($request);
    }
}
