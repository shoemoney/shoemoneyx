<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\DeskMutationThrottle;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeskMutationThrottleTest extends TestCase
{
    private function request(string $action, string $ip = '127.0.0.1', string $retiredToken = ''): Request
    {
        $request = Request::create('/api/desk/'.$action, 'POST', server: [
            'REMOTE_ADDR' => $ip,
            'HTTP_X_DESK_TOKEN' => $retiredToken,
        ]);
        $route = new Route('POST', 'api/desk/{action}', []);
        $route->bind($request);
        $route->setParameter('action', $action);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_expensive_actions_remain_rate_limited_without_credentials(): void
    {
        config(['cache.default' => 'array']);
        RateLimiter::clear('desk-mutation:127.0.0.1');
        $middleware = new DeskMutationThrottle;
        $next = fn () => response()->json(['ok' => true]);

        for ($i = 0; $i < 30; $i++) {
            $action = ['cycle', 'risk', 'paper-reset'][$i % 3];
            $response = $middleware->handle($this->request($action, retiredToken: 'ignored-'.$i), $next);
            $this->assertSame(200, $response->getStatusCode());
        }
        $this->assertSame(30, RateLimiter::attempts('desk-mutation:127.0.0.1'));

        try {
            $middleware->handle($this->request('cycle'), $next);
            $this->fail('The 31st expensive action must remain rate limited.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertGreaterThan(0, (int) $exception->getHeaders()['Retry-After']);
        }

        // A halt remains available even when expensive actions are throttled.
        $this->assertSame(200, $middleware->handle($this->request('halt'), $next)->getStatusCode());
        $this->assertSame(200, $middleware->handle($this->request('cycle', '127.0.0.2'), $next)->getStatusCode());
    }
}
