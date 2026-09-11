<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('site.demo')) {
            return $next($request);
        }

        // Run before route bindings and controller resolution: no model or desk can execute.
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'This public demo is read-only. No trades, jobs or settings are changed.'], 403);
        }

        if ($request->is('api', 'api/*', 'broadcasting/*')) {
            return response()->json((new DemoData)->get($request))
                ->header('X-ShoeMoney-Data', 'simulated')->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
