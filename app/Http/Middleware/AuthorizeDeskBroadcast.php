<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskAccess;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

/**
 * /broadcasting/auth gate. The desk has no user accounts, but Laravel refuses to sign private channels without a
 * user, so an allowed desk session is given a stand-in one. Everyone else gets 403 before any channel is signed.
 */
class AuthorizeDeskBroadcast
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->hasSession() || ! DeskAccess::allows($request->session())) {
            abort(403);
        }

        $request->setUserResolver(fn () => new GenericUser(['id' => 'desk']));

        return $next($request);
    }
}
