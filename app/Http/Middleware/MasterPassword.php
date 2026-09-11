<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        if ((string) config('desk.master_password') !== '' && ! $request->session()->get('desk_authed', false)) {
            return redirect('/login');
        }

        return $next($request);
    }
}
