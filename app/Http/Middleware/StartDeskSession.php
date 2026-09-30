<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;

/**
 * StartSession for the API, except script clients that authenticate with X-Desk-Token get a
 * throwaway in-memory session: no cookie and no sessions row per request.
 */
class StartDeskSession extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (! $request->headers->has('X-Desk-Token')) {
            return parent::handle($request, $next);
        }

        $session = new Store((string) config('session.cookie'), new ArraySessionHandler(0));
        $session->start();
        $request->setLaravelSession($session);

        return $next($request);
    }
}
