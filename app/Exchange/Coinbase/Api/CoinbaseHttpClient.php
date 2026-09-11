<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase\Api;

use App\Models\CoinbaseAccount;
use App\Models\CoinbaseApiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Authenticated Advanced Trade client. Every call is logged to coinbase_api_logs. */
class CoinbaseHttpClient
{
    public function __construct(private CoinbaseJwtService $jwt) {}

    public function get(CoinbaseAccount $account, string $endpoint, array $params = []): array
    {
        return $this->request($account, 'GET', $endpoint, $params);
    }

    public function post(CoinbaseAccount $account, string $endpoint, array $data = []): array
    {
        return $this->request($account, 'POST', $endpoint, $data);
    }

    public function put(CoinbaseAccount $account, string $endpoint, array $data = []): array
    {
        return $this->request($account, 'PUT', $endpoint, $data);
    }

    public function delete(CoinbaseAccount $account, string $endpoint): array
    {
        return $this->request($account, 'DELETE', $endpoint);
    }

    private function request(CoinbaseAccount $account, string $method, string $endpoint, array $data = []): array
    {
        $start = microtime(true);
        $fullUrl = config('coinbase.base_url').$endpoint;

        // JWT uri claim is the path WITHOUT the query string.
        $token = $this->jwt->generateToken($account, $method, '/api/v3/brokerage'.$endpoint);

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('coinbase.timeout', 30))
            ->connectTimeout((int) config('coinbase.connect_timeout', 10))
            ->retry(
                (int) config('coinbase.retry_attempts', 3),
                (int) config('coinbase.retry_delay', 200),
                fn ($e) => $e instanceof ConnectionException,
                throw: false,
            );

        $response = match ($method) {
            'GET' => $request->get($fullUrl, $data),
            'POST' => $request->post($fullUrl, $data),
            'PUT' => $request->put($fullUrl, $data),
            'DELETE' => $request->delete($fullUrl),
            default => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };

        $this->log($account, $method, $endpoint, $data, $response, (int) ((microtime(true) - $start) * 1000));
        $account->forceFill(['last_used_at' => now()])->saveQuietly();

        if ($response->failed()) {
            throw new CoinbaseApiException(
                $this->errorMessage($response),
                $response->status(),
                $response->json() ?? [],
            );
        }

        return $response->json() ?? [];
    }

    private function log(CoinbaseAccount $account, string $method, string $endpoint, array $data, Response $response, int $ms): void
    {
        try {
            CoinbaseApiLog::create([
                'coinbase_account_id' => $account->id,
                'method' => $method,
                'endpoint' => $endpoint,
                'request_body' => $method !== 'GET' ? $data : null,
                'response_status' => $response->status(),
                'response_body' => $response->json(),
                'duration_ms' => $ms,
                'error_message' => $response->failed() ? $this->errorMessage($response) : null,
            ]);
        } catch (\Throwable) {
            // logging must never break trading
        }
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json() ?? [];

        return $body['message'] ?? $body['error'] ?? (isset($body['error_details']) ? json_encode($body['error_details']) : 'HTTP '.$response->status());
    }
}
