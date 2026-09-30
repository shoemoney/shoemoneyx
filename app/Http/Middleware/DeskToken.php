<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskLogin;
use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * API gate for the master password. Leave MASTER_PASSWORD empty and this is a no-op — the
 * private network stays the desk's only access boundary. Once set, a request passes with either:
 *  - the browser's login session (httpOnly cookie; writes also need the X-CSRF-TOKEN header), or
 *  - an X-Desk-Token header carrying the password, for scripts and operators.
 * The password is never handed to the browser, and never accepted from the query string, where
 * it would land in access logs and history.
 */
class DeskToken
{
    public function handle(Request $request, Closure $next)
    {
        $master = app(Settings::class)->masterPassword();
        if ($master === '') {
            return $next($request);
        }

        if (hash_equals($master, (string) $request->header('X-Desk-Token'))) {
            return $next($request);
        }

        if ($request->hasSession() && DeskLogin::check($request->session())) {
            if (! $request->isMethodSafe() && ! hash_equals((string) $request->session()->token(), (string) $request->header('X-CSRF-TOKEN'))) {
                abort(419, 'CSRF token mismatch');
            }

            return $next($request);
        }

        abort(401, 'bad desk token');
    }
}
