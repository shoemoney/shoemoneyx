<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\HubConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    public function test_register_connects_the_desk_and_never_echoes_the_token_or_password(): void
    {
        Http::fake(['hub.test/*' => Http::response([
            'user' => ['id' => 1, 'handle' => 'shoemoney'],
            'token' => 'super-secret-hub-token',
        ])]);

        $response = $this->postJson('/api/hub/register', [
            'email' => 'jeremy@shoemoney.com',
            'handle' => 'shoemoney',
            'password' => 'supersecret1',
            'desk_name' => 'laptop',
        ]);

        $response->assertOk()->assertExactJson([
            'connected' => true,
            'handle' => 'shoemoney',
            'hub_url' => 'https://hub.test',
        ]);

        $this->assertDatabaseHas('hub_connections', [
            'user_handle' => 'shoemoney',
            'revoked_at' => null,
        ]);

        $connection = HubConnection::first();
        $this->assertSame('super-secret-hub-token', $connection->token);
    }

    public function test_register_maps_a_validation_error_to_422_and_creates_no_connection(): void
    {
        Http::fake(['hub.test/*' => Http::response([
            'error' => ['code' => 'validation', 'message' => 'handle taken', 'fields' => ['handle' => ['taken']]],
        ])]);

        $response = $this->postJson('/api/hub/register', [
            'email' => 'jeremy@shoemoney.com',
            'handle' => 'shoemoney',
            'password' => 'supersecret1',
        ]);

        $response->assertStatus(422)->assertExactJson([
            'error' => 'handle taken',
            'code' => 'validation',
        ]);

        $this->assertDatabaseCount('hub_connections', 0);
    }

    public function test_status_reports_disconnected_then_connected(): void
    {
        $this->getJson('/api/hub/status')->assertOk()->assertExactJson([
            'connected' => false,
            'handle' => null,
            'hub_url' => 'https://hub.test',
        ]);

        HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => 'tok-abc',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        $this->getJson('/api/hub/status')->assertOk()->assertExactJson([
            'connected' => true,
            'handle' => 'shoemoney',
            'hub_url' => 'https://hub.test',
        ]);
    }

    public function test_disconnect_revokes_the_active_connection(): void
    {
        $connection = HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => 'tok-abc',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        $this->postJson('/api/hub/disconnect')->assertOk()->assertExactJson(['connected' => false]);

        $this->assertNotNull($connection->fresh()->revoked_at);

        $this->getJson('/api/hub/status')->assertOk()->assertJsonPath('connected', false);
    }
}
