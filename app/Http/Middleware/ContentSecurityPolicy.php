<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single source of truth for the page CSP (nginx only adds X-Frame-Options for static files).
 *
 * script-src 'self': every script is a Vite-built same-origin module. The only inline <script> in
 * the views is the JSON-LD block, a non-executable data block that CSP does not govern. Nothing may
 * add an inline or third-party script without revisiting this policy (see ContentSecurityPolicyTest).
 */
class ContentSecurityPolicy
{
    public const POLICY = "script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The Vite dev server serves scripts from another origin and injects inline HMR code.
        if (! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        return $response;
    }
}
