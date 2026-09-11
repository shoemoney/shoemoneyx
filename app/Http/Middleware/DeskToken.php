<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Desk\Settings;
use Closure;
use Illuminate\Http\Request;

/**
 * API gate for the master password. Leave MASTER_PASSWORD empty and this is a no-op — the
 * private network stays the desk's only access boundary. Setting it requires a matching
 * X-Desk-Token header or ?token= query param on every API request; app.blade.php hands the
 * logged-in browser its own password into localStorage so the SPA's API calls keep working.
 */
class DeskToken
{
    public function handle(Request $request, Closure $next)
    {
        $master = app(Settings::class)->masterPassword();
        if ($master === '') {
            return $next($request);
        }

        $presented = (string) ($request->header('X-Desk-Token') ?: $request->query('token'));
        if (! hash_equals($master, $presented)) {
            abort(401, 'bad desk token');
        }

        return $next($request);
    }
}
