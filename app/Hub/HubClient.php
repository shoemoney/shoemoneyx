<?php

declare(strict_types=1);

namespace App\Hub;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Typed client for the hosted community hub's /api/v1 routes (see docs/HUB_API.md).
 * Resolves everything it needs (base URL, bearer token) internally, so it can be
 * pulled from the container with no bindings. Every call goes through Http so
 * Http::fake() works in tests.
 */
class HubClient
{
    private ?int $rateLimitRemaining = null;

    public function __construct()
    {
    }

    // Accounts

    public function register(string $email, string $handle, string $password, string $deskName): array
    {
        return $this->request('POST', '/register', ['json' => [
            'email' => $email,
            'handle' => $handle,
            'password' => $password,
            'desk_name' => $deskName,
        ]]);
    }

    public function login(string $email, string $password, string $deskName): array
    {
        return $this->request('POST', '/login', ['json' => [
            'email' => $email,
            'password' => $password,
            'desk_name' => $deskName,
        ]]);
    }

    public function link(string $code, string $deskName): array
    {
        return $this->request('POST', '/link', ['json' => [
            'code' => $code,
            'desk_name' => $deskName,
        ]]);
    }

    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    public function updateMe(array $data): array
    {
        return $this->request('PATCH', '/me', ['json' => $data]);
    }

    public function deleteDesk(int|string $id): void
    {
        $this->request('DELETE', "/me/desks/{$id}");
    }

    // Archive

    public function publish(array $data): array
    {
        return $this->request('POST', '/strategies', ['json' => $data]);
    }

    public function publishVersion(string $slug, array $data): array
    {
        return $this->request('POST', "/strategies/{$slug}/versions", ['json' => $data]);
    }

    public function strategy(string $slug): array
    {
        return $this->request('GET', "/strategies/{$slug}");
    }

    public function version(string $slug, string $version): array
    {
        return $this->request('GET', "/strategies/{$slug}/versions/{$version}");
    }

    public function search(array $query): array
    {
        return $this->request('GET', '/strategies/search', ['query' => $this->withoutNulls($query)]);
    }

    public function typeahead(string $q): array
    {
        return $this->request('GET', '/strategies/typeahead', ['query' => ['q' => $q]]);
    }

    public function star(string $slug): array
    {
        return $this->request('POST', "/strategies/{$slug}/star");
    }

    public function unstar(string $slug): array
    {
        return $this->request('DELETE', "/strategies/{$slug}/star");
    }

    public function import(string $slug): array
    {
        return $this->request('POST', "/strategies/{$slug}/import");
    }

    public function deleteStrategy(string $slug): void
    {
        $this->request('DELETE', "/strategies/{$slug}");
    }

    // Social

    public function user(string $handle): array
    {
        return $this->request('GET', "/users/{$handle}");
    }

    public function follow(string $handle): array
    {
        return $this->request('POST', "/users/{$handle}/follow");
    }

    public function unfollow(string $handle): array
    {
        return $this->request('DELETE', "/users/{$handle}/follow");
    }

    public function feed(int $page = 1): array
    {
        return $this->request('GET', '/feed', ['query' => ['page' => $page]]);
    }

    public function comments(string $slug, int $page = 1): array
    {
        return $this->request('GET', "/strategies/{$slug}/comments", ['query' => ['page' => $page]]);
    }

    public function postComment(string $slug, array $data): array
    {
        return $this->request('POST', "/strategies/{$slug}/comments", ['json' => $data]);
    }

    public function deleteComment(int|string $id): void
    {
        $this->request('DELETE', "/comments/{$id}");
    }

    // Contests

    public function contests(?string $state = null): array
    {
        return $this->request('GET', '/contests', ['query' => $state !== null ? ['state' => $state] : []]);
    }

    public function contest(string $slug): array
    {
        return $this->request('GET', "/contests/{$slug}");
    }

    public function enter(string $slug, string $strategySlug, string $version): array
    {
        return $this->request('POST', "/contests/{$slug}/enter", ['json' => [
            'strategy_slug' => $strategySlug,
            'version' => $version,
        ]]);
    }

    public function withdraw(string $slug): void
    {
        $this->request('DELETE', "/contests/{$slug}/enter");
    }

    /** Paper trading stays local — this reports already-executed local fills, it doesn't request new ones. Idempotent on each fill's `client_id`. */
    public function pushFills(string $slug, array $fills): array
    {
        return $this->request('POST', "/contests/{$slug}/fills", ['json' => ['fills' => $fills]]);
    }

    /** The hub enforces at most one accepted snapshot per minute per entry; call more often and it just no-ops the extras. */
    public function pushSnapshot(string $slug, array $snapshot): array
    {
        return $this->request('POST', "/contests/{$slug}/snapshots", ['json' => $snapshot]);
    }

    public function account(string $slug): array
    {
        return $this->request('GET', "/contests/{$slug}/account");
    }

    /**
     * Reserved for the hub's v2 hosted exchange (docs/HUB_API.md — "not built yet"). Left in place,
     * unused: nothing in this codebase calls it while contests run entirely on locally-reported
     * paper fills.
     */
    public function tape(string $slug, string $productId, array $query = []): array
    {
        return $this->request('GET', "/contests/{$slug}/tape/{$productId}", ['query' => $this->withoutNulls($query)]);
    }

    public function connected(): bool
    {
        return HubConnection::active() !== null;
    }

    /** Last X-RateLimit-Remaining value seen from the hub, if any. */
    public function rateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    private function withoutNulls(array $query): array
    {
        return array_filter($query, static fn ($value) => $value !== null);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $response = $this->send($method, $path, $options);

        if ($this->isRateLimited($response)) {
            $retryAfter = (int) data_get($response->json(), 'error.retry_after', 0);
            sleep(min($retryAfter, 30));
            $response = $this->send($method, $path, $options);
        }

        return $this->decode($response);
    }

    private function send(string $method, string $path, array $options): Response
    {
        $http = Http::timeout((int) config('hub.timeout', 15))->acceptJson();

        $connection = HubConnection::active();
        if ($connection !== null && filled($connection->token)) {
            $http = $http->withToken($connection->token);
        }

        $response = $http->send($method, $this->baseUrl().$path, $options);

        $remaining = $response->header('X-RateLimit-Remaining');
        if ($remaining !== null && $remaining !== '') {
            $this->rateLimitRemaining = (int) $remaining;
        }

        return $response;
    }

    private function isRateLimited(Response $response): bool
    {
        return data_get($response->json(), 'error.code') === 'rate_limited';
    }

    private function decode(Response $response): array
    {
        $body = $response->json();
        $error = is_array($body) ? data_get($body, 'error') : null;

        if ($response->failed() || is_array($error)) {
            throw new HubException(
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'Hub request failed.',
                isset($error['retry_after']) ? (int) $error['retry_after'] : null,
            );
        }

        return is_array($body) ? $body : [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('hub.url'), '/').'/api/v1';
    }
}
