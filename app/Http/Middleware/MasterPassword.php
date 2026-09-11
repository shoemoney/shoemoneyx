<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        if (! config('site.demo') && app(Settings::class)->masterPassword() !== '' && ! $request->session()->get('desk_authed', false)) {
            return redirect('/login');
        }

        return $next($request);
    }
}
