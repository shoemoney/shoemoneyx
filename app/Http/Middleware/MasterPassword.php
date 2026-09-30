<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskLogin;
use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * Web gate: when MASTER_PASSWORD is set, every page requires the login
 * session first. Empty password = gate off (trusted local desk).
 */
class MasterPassword
{
    public function handle(Request $request, Closure $next)
    {
        // Public demo pages must render without touching the database — same rule PublicDemo enforces.
        if (config('site.demo')) {
            return $next($request);
        }

        $master = app(Settings::class)->masterPassword();
        if ($master !== '' && ! DeskLogin::check($request->session())) {
            return redirect('/login');
        }

        // DESK_REQUIRE_MASTER_PASSWORD fails closed: no password yet means onboarding only.
        if ($master === '' && config('desk.require_master_password') && ! $request->is('onboarding', 'onboarding/*')) {
            return redirect('/onboarding');
        }

        return $next($request);
    }
}
