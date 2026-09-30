<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskAuthThrottle;
use App\Desk\DeskLogin;
use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * API gate for the master password. A request passes with either:
 *  - the browser's login session (httpOnly cookie; writes also need the X-CSRF-TOKEN header), or
 *  - an X-Desk-Token header carrying the password, for scripts and operators.
 * The password is never handed to the browser, and never accepted from the query string, where
 * it would land in access logs and history. Wrong X-Desk-Token guesses are throttled per IP.
 *
 * The .env MASTER_PASSWORD is a one-time bootstrap key: until the owner sets their own password,
 * a session or header carrying it may only read onboarding state and set the password
 * (403 set_password_required everywhere else). With no credential at all the gate fails closed
 * the same way, so the first visitor sets the password.
 */
class DeskToken
{
    public function handle(Request $request, Closure $next)
    {
        $settings = app(Settings::class);
        if (! $settings->hasMasterPassword()) {
            return $this->setupOnly($request, $next);
        }

        $authed = false;
        $presented = (string) $request->header('X-Desk-Token');
        if ($presented !== '') {
            if (DeskAuthThrottle::tooManyFailures($request)) {
                DeskAuthThrottle::lockout($request);
            }

            if ($settings->verifyToken($presented)) {
                $authed = true;
            } else {
                DeskAuthThrottle::recordFailure($request);
            }
        }

        if (! $authed && $request->hasSession() && DeskLogin::check($request->session())) {
            if (! $request->isMethodSafe() && ! hash_equals((string) $request->session()->token(), (string) $request->header('X-CSRF-TOKEN'))) {
                abort(419, 'CSRF token mismatch');
            }
            $authed = true;
        }

        if (! $authed) {
            abort(401, 'bad desk token');
        }

        if ($settings->needsPasswordSetup() && ! $this->isPasswordSetup($request)) {
            return $this->setPasswordRequired();
        }

        return $next($request);
    }

    /**
     * No credential exists. Any website open in the operator's browser could post a form to the
     * desk, so unsafe cross-site requests are refused; only the two calls that set the first
     * password get through.
     */
    private function setupOnly(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() && $this->isCrossSite($request)) {
            abort(403, 'cross-site request refused');
        }

        if (! $this->isPasswordSetup($request)) {
            return $this->setPasswordRequired();
        }

        return $next($request);
    }

    private function setPasswordRequired()
    {
        return response()->json(['error' => 'set_password_required'], 403);
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
