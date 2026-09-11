<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Http;

/**
 * OpenRouter's OAuth PKCE handshake (S256 only). Stateless — the caller owns the
 * verifier and decides where to keep it between the redirect and the callback.
 */
final class OpenRouterOAuth
{
    private const AUTHORIZE_URL = 'https://openrouter.ai/auth';

    private const EXCHANGE_URL = 'https://openrouter.ai/api/v1/auth/keys';

    private const KEY_INFO_URL = 'https://openrouter.ai/api/v1/key';

    /** URL-safe, 86 characters — inside PKCE's 43-128 range. */
    public function generateVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    public function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizeUrl(string $callbackUrl, string $challenge): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'callback_url' => $callbackUrl,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Trade the one-time code for a user-scoped key.
     *
     * @throws \RuntimeException when OpenRouter rejects the code, the verifier, or the method
     */
    public function exchangeCode(string $code, string $verifier): string
    {
        $response = Http::timeout(20)->asJson()->post(self::EXCHANGE_URL, [
            'code' => $code,
            'code_verifier' => $verifier,
            'code_challenge_method' => 'S256',
        ]);

        $key = $response->successful() ? $response->json('key') : null;
        if (! is_string($key) || $key === '') {
            throw new \RuntimeException('OpenRouter key exchange failed ('.$response->status().').');
        }

        return $key;
    }

    /** @return array<string, mixed>|null the key's `data` bag: label, limit, limit_remaining, usage. */
    public function fetchKeyInfo(string $apiKey): ?array
    {
        $data = Http::withToken($apiKey)->timeout(15)->get(self::KEY_INFO_URL)->json('data');

        return is_array($data) ? $data : null;
    }
}
