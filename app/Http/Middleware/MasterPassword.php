<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\DeskLogin;
use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * Web gate: every page requires the login session first. Until the owner has chosen their own
 * password (a session that signed in with the bootstrap key, or a fresh install with no
 * credential at all), the only page available is the set-password screen under /onboarding.
 */
class MasterPassword
{
    public function handle(Request $request, Closure $next)
    {
        // Public demo pages must render without touching the database — same rule PublicDemo enforces.
        if (config('site.demo')) {
            return $next($request);
        }

        $settings = app(Settings::class);
        $onboarding = $request->is('onboarding', 'onboarding/*');

        if (! $settings->hasMasterPassword()) {
            return $onboarding ? $next($request) : redirect('/onboarding');
        }

        if (! DeskLogin::check($request->session())) {
            return redirect('/login');
        }

        if ($settings->needsPasswordSetup() && ! $onboarding) {
            return redirect('/onboarding');
        }

        return $next($request);
    }
}
