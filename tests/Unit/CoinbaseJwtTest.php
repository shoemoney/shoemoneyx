<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CoinbaseAccount;
use App\Exchange\Coinbase\Api\CoinbaseJwtService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class CoinbaseJwtTest extends TestCase
{
    public function test_ed25519_key_signs_eddsa_jwt(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($kp));   // CDP JSON "privateKey" shape
        [$alg, $key] = CoinbaseJwtService::keyMaterial($secret);
        $this->assertSame('EdDSA', $alg);

        $token = JWT::encode(['uri' => 'GET api.coinbase.com/x'], $key, $alg, null, ['kid' => 'k', 'nonce' => 'n']);
        $decoded = JWT::decode($token, new Key(base64_encode(sodium_crypto_sign_publickey($kp)), 'EdDSA'));
        $this->assertSame('GET api.coinbase.com/x', $decoded->uri);
    }

    public function test_seed_only_key_is_expanded(): void
    {
        $seed = base64_encode(random_bytes(32));
        [$alg, $key] = CoinbaseJwtService::keyMaterial($seed);
        $this->assertSame('EdDSA', $alg);
        $this->assertSame(64, strlen(base64_decode($key)));
    }

    public function test_pem_key_stays_es256(): void
    {
        $pem = "-----BEGIN EC PRIVATE KEY-----\\nabc\\n-----END EC PRIVATE KEY-----";
        [$alg, $key] = CoinbaseJwtService::keyMaterial($pem);
        $this->assertSame('ES256', $alg);
        $this->assertStringContainsString("\n", $key);
    }

    public function test_garbage_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoinbaseJwtService::keyMaterial('not-a-key');
    }
}
