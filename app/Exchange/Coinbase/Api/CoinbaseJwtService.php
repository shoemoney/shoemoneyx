<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase\Api;

use App\Models\CoinbaseAccount;
use Firebase\JWT\JWT;

/**
 * Coinbase CDP API keys sign every request with a JWT that embeds
 * "{METHOD} {host}{path}" and expires within two minutes.
 *
 * Two key formats exist (docs/COINBASE_DOCS.md → auth):
 *   - ECDSA (older keys): PEM "-----BEGIN EC PRIVATE KEY-----" → alg ES256
 *   - Ed25519 (current CDP portal default): base64 of 64 bytes (seed+pub) → alg EdDSA (sodium)
 * Both carry kid = key name, nonce, typ = JWT.
 */
class CoinbaseJwtService
{
    public function generateToken(CoinbaseAccount $account, string $method, string $path): string
    {
        $now = time();

        $payload = [
            'iss' => 'cdp',
            'sub' => $account->api_key_name,
            'nbf' => $now,
            'exp' => $now + (int) config('coinbase.jwt_expiry_seconds', 120),
            'uri' => strtoupper($method).' '.config('coinbase.host').$path,
        ];

        return $this->sign($payload, $account);
    }

    public function generateWebSocketToken(CoinbaseAccount $account): string
    {
        $now = time();

        $payload = [
            'iss' => 'cdp',
            'sub' => $account->api_key_name,
            'nbf' => $now,
            'exp' => $now + (int) config('coinbase.jwt_expiry_seconds', 120),
        ];

        return $this->sign($payload, $account);
    }

    private function sign(array $payload, CoinbaseAccount $account): string
    {
        [$alg, $key] = self::keyMaterial($account->api_private_key);

        return JWT::encode($payload, $key, $alg, null, [
            'kid' => $account->api_key_name,
            'nonce' => bin2hex(random_bytes(16)),
            'typ' => 'JWT',
        ]);
    }

    /**
     * Detect the key flavour. Returns [alg, key-as-php-jwt-wants-it].
     * php-jwt's EdDSA path expects the base64-encoded 64-byte sodium secret key — exactly what the CDP JSON holds.
     */
    public static function keyMaterial(string $raw): array
    {
        $pem = str_replace('\\n', "\n", trim($raw));   // pasted PEMs carry literal \n
        if (str_contains($pem, 'BEGIN')) {
            return ['ES256', $pem];
        }
        $bin = base64_decode($pem, true);
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return ['EdDSA', $pem];
        }
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            // 32-byte seed only: derive the full secret key
            return ['EdDSA', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($bin)))];
        }
        throw new \InvalidArgumentException('Unrecognised Coinbase private key: expected an EC PEM or a base64 Ed25519 key');
    }
}
