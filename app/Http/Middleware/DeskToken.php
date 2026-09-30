<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskAuthThrottle;
use App\Desk\DeskLogin;
use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * API gate for the master password. Leave MASTER_PASSWORD empty and the private network stays the
 * desk's only access boundary (see openGate for what still applies). Once set, a request passes with either:
 *  - the browser's login session (httpOnly cookie; writes also need the X-CSRF-TOKEN header), or
 *  - an X-Desk-Token header carrying the password, for scripts and operators.
 * The password is never handed to the browser, and never accepted from the query string, where
 * it would land in access logs and history. Wrong X-Desk-Token guesses are throttled per IP.
 */
class DeskToken
{
    public function handle(Request $request, Closure $next)
    {
        $master = app(Settings::class)->masterPassword();
        if ($master === '') {
            return $this->openGate($request, $next);
        }

        $presented = (string) $request->header('X-Desk-Token');
        if ($presented !== '') {
            if (DeskAuthThrottle::tooManyFailures($request)) {
                DeskAuthThrottle::lockout($request);
            }

            if (hash_equals($master, $presented)) {
                return $next($request);
            }

            DeskAuthThrottle::recordFailure($request);
        }

        if ($request->hasSession() && DeskLogin::check($request->session())) {
            if (! $request->isMethodSafe() && ! hash_equals((string) $request->session()->token(), (string) $request->header('X-CSRF-TOKEN'))) {
                abort(419, 'CSRF token mismatch');
            }

            return $next($request);
        }

        abort(401, 'bad desk token');
    }

    /**
     * No password set. The private network is the boundary, but any website open in the operator's
     * browser can still post a form to the desk, so unsafe cross-site requests are refused. With
     * DESK_REQUIRE_MASTER_PASSWORD the gate fails closed instead: only the two calls that set the
     * first password get through.
     */
    private function openGate(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() && $this->isCrossSite($request)) {
            abort(403, 'cross-site request refused');
        }

        if (config('desk.require_master_password') && ! $this->isPasswordSetup($request)) {
            abort(403, 'set a master password in onboarding first');
        }

        return $next($request);
    }

    private function isPasswordSetup(Request $request): bool
    {
        return ($request->isMethod('GET') && $request->is('api/onboarding'))
            || ($request->isMethod('POST') && $request->is('api/onboarding/master-password'));
    }

    private function isCrossSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            return in_array(strtolower($site), ['cross-site', 'same-site'], true);
        }

        $origin = $request->headers->get('Origin');

        return $origin !== null && strcasecmp(rtrim($origin, '/'), $request->getSchemeAndHttpHost()) !== 0;
    }
}
