<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\HubConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HubConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_token_round_trips_through_the_encrypted_cast_and_is_encrypted_at_rest(): void
    {
        $plaintext = 'super-secret-desk-token-value';

        $connection = HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => $plaintext,
            'connected_at' => now(),
        ]);

        $fresh = HubConnection::find($connection->id);
        $this->assertSame($plaintext, $fresh->token);

        $raw = DB::table('hub_connections')->find($connection->id)->token;
        $this->assertNotSame($plaintext, $raw);
    }

    public function test_active_returns_the_latest_row_with_no_revoked_at_ignoring_revoked_rows(): void
    {
        $revoked = HubConnection::create([
            'user_handle' => 'old-desk',
            'token' => 'tok-old',
            'connected_at' => now()->subDay(),
            'revoked_at' => now(),
        ]);

        $active = HubConnection::create([
            'user_handle' => 'new-desk',
            'token' => 'tok-new',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        $found = HubConnection::active();

        $this->assertNotNull($found);
        $this->assertSame($active->id, $found->id);
        $this->assertNotSame($revoked->id, $found->id);
    }

    public function test_active_returns_the_latest_by_id_when_two_active_rows_exist(): void
    {
        $first = HubConnection::create([
            'user_handle' => 'a',
            'token' => 'tok-a',
            'connected_at' => now()->subHour(),
            'revoked_at' => null,
        ]);

        $second = HubConnection::create([
            'user_handle' => 'b',
            'token' => 'tok-b',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        $found = HubConnection::active();

        $this->assertSame($second->id, $found->id);
        $this->assertGreaterThan($first->id, $found->id);
    }
}
