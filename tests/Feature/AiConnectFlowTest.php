<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConnectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-or-v1-abc123deadbeef';

    private function fakeOpenRouter(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/auth/keys' => Http::response(['key' => self::KEY]),
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'shoemoneyx desk', 'limit_remaining' => 4.25]]),
        ]);
    }

    public function test_connect_stores_a_verifier_and_redirects_to_openrouter(): void
    {
        $response = $this->get('/ai/openrouter/connect');

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith('https://openrouter.ai/auth?', $target);
        $this->assertStringContainsString('code_challenge_method=S256', $target);

        $verifier = session('ai.openrouter.verifier');
        $this->assertIsString($verifier);
        $this->assertGreaterThanOrEqual(43, strlen($verifier));

        parse_str(parse_url($target, PHP_URL_QUERY) ?: '', $query);
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expectedChallenge, $query['code_challenge']);
        $this->assertStringContainsString('state='.session('ai.openrouter.state'), $query['callback_url']);
    }

    public function test_callback_exchanges_the_code_and_stores_an_encrypted_key(): void
    {
        $this->fakeOpenRouter();

        $this->withSession(['ai.openrouter.state' => 'st4te', 'ai.openrouter.verifier' => str_repeat('v', 64)])
            ->get('/ai/openrouter/callback?state=st4te&code=authcode')
            ->assertRedirect('/builder');

        $connection = AiConnection::sole();
        $this->assertSame('local', $connection->user_id);
        $this->assertSame('openrouter', $connection->provider);
        $this->assertSame(self::KEY, $connection->key);
        $this->assertSame('shoemoneyx desk', $connection->label);
        $this->assertNotNull($connection->connected_at);
        $this->assertNull($connection->revoked_at);

        $raw = DB::table('ai_connections')->where('id', $connection->id)->value('key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString(self::KEY, (string) $raw);
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        $this->fakeOpenRouter();

        $this->withSession(['ai.openrouter.state' => 'st4te', 'ai.openrouter.verifier' => str_repeat('v', 64)])
            ->get('/ai/openrouter/callback?state=forged&code=authcode')
            ->assertStatus(419);

        $this->assertSame(0, AiConnection::count());
    }

    public function test_callback_rejects_a_missing_state(): void
    {
        $this->fakeOpenRouter();

        $this->get('/ai/openrouter/callback?code=authcode')->assertStatus(419);

        $this->assertSame(0, AiConnection::count());
    }

    public function test_status_reports_a_connection_and_disconnect_revokes_it(): void
    {
        config()->set('services.openrouter.key', null);
        Http::fake(['openrouter.ai/api/v1/key' => Http::response(['data' => ['limit_remaining' => 4.25]])]);

        AiConnection::create([
            'user_id' => 'local', 'provider' => 'openrouter', 'key' => self::KEY,
            'label' => 'shoemoneyx desk', 'connected_at' => now(),
        ]);

        $this->getJson('/api/ai/status')->assertOk()->assertJson([
            'connected' => true,
            'provider' => 'openrouter',
            'label' => 'shoemoneyx desk',
            'default_model' => 'openrouter/free',
            'key_limit_remaining' => 4.25,
            'nudge' => ['show' => true, 'model' => 'deepseek/deepseek-v4-flash-vision-exp'],
        ]);

        $this->postJson('/api/ai/disconnect')->assertOk()->assertJson(['connected' => false]);

        $this->getJson('/api/ai/status')->assertOk()->assertJson(['connected' => false, 'label' => null, 'key_limit_remaining' => null]);
    }

    public function test_status_counts_an_env_key_as_connected_without_a_label(): void
    {
        config()->set('services.openrouter.key', 'sk-or-v1-selfhost');

        $this->getJson('/api/ai/status')->assertOk()->assertJson([
            'connected' => true,
            'label' => null,
            'key_limit_remaining' => null,
        ]);
    }
}
