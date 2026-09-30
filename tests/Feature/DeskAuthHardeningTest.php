<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeskAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner-chosen-password';

    protected bool $deskPasswordSet = false;

    private function ownerPassword(): void
    {
        app(Settings::class)->setMasterPassword(self::OWNER);
    }

    public function test_wrong_token_flood_gets_429_then_the_correct_token_works_after_the_window(): void
    {
        $this->ownerPassword();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/status', ['X-Desk-Token' => 'guess'.$i])->assertUnauthorized();
        }

        $this->getJson('/api/status', ['X-Desk-Token' => 'guess-again'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        // A locked-out caller learns nothing from the response, even with the right token.
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
    }

    public function test_successful_token_requests_and_headerless_requests_never_fill_the_bucket(): void
    {
        $this->ownerPassword();

        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/status')->assertUnauthorized();
            $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
        }
    }

    public function test_login_failures_share_the_header_failure_bucket(): void
    {
        $this->ownerPassword();

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/status', ['X-Desk-Token' => 'guess'.$i])->assertUnauthorized();
            $this->post('/login', ['password' => 'guess'.$i])->assertSessionHasErrors('password');
        }

        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertStatus(429);
        $this->post('/login', ['password' => self::OWNER])->assertStatus(429);
    }

    public function test_cross_site_writes_are_refused_while_no_password_exists(): void
    {
        config(['desk.master_password' => '']);
        $body = ['password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        $this->postJson('/api/onboarding/master-password', $body, ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        $this->postJson('/api/onboarding/master-password', $body, ['Sec-Fetch-Site' => 'same-site'])->assertForbidden();
        $this->postJson('/api/onboarding/master-password', $body, ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->postJson('/api/onboarding/master-password', $body, ['Origin' => 'null'])->assertForbidden();
        // Sec-Fetch-Site wins over a spoofable-looking Origin.
        $this->postJson('/api/onboarding/master-password', $body, ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'http://localhost'])->assertForbidden();

        $this->assertFalse(app(Settings::class)->hasMasterPassword());
    }

    public function test_same_origin_and_headerless_setup_passes_while_no_password_exists(): void
    {
        config(['desk.master_password' => '']);
        $body = ['password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        $this->postJson('/api/onboarding/master-password', $body, ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
    }

    public function test_keyless_setup_is_refused_from_public_addresses(): void
    {
        config(['desk.master_password' => '']);
        $body = ['password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        foreach (['8.8.8.8', '172.32.0.1', '2606:4700::1111'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/onboarding/master-password', $body)
                ->assertForbidden()->assertJsonPath('message', 'Set MASTER_PASSWORD in .env to claim this desk from outside its own network.');
        }
        $this->assertFalse(app(Settings::class)->hasMasterPassword());
    }

    public function test_keyless_setup_is_allowed_from_loopback_and_private_addresses(): void
    {
        config(['desk.master_password' => '']);
        $body = ['password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        foreach (['127.0.0.1', '::1', '10.1.2.3', '172.16.5.5', '192.168.1.20', 'fd12:3456::1'] as $ip) {
            Setting::where('key', 'master_password')->delete();
            Cache::forget('desk:settings');
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/onboarding/master-password', $body)->assertOk();
        }
    }

    public function test_a_public_address_with_the_key_in_env_is_unaffected(): void
    {
        config(['desk.master_password' => 'i-0123456789abcdef0']);
        $body = ['password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->postJson('/api/onboarding/master-password', $body, ['X-Desk-Token' => 'i-0123456789abcdef0'])->assertOk();
    }

    public function test_cors_gives_no_cross_origin_access_to_the_api(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));

        $this->getJson('/api/status', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_desk_with_no_password_fails_closed_on_the_api_until_one_is_set(): void
    {
        config(['desk.master_password' => '']);

        $this->getJson('/api/status')->assertForbidden();
        $this->postJson('/api/desk/halt')->assertForbidden();
        $this->postJson('/api/onboarding/exchange', ['exchange' => 'paper'])->assertForbidden();

        $this->getJson('/api/onboarding')->assertOk();
        $this->postJson('/api/onboarding/master-password', ['password' => str_repeat('a', 12)])->assertStatus(422);
        $this->postJson('/api/onboarding/master-password', ['password' => str_repeat('a', 12), 'password_confirmation' => str_repeat('a', 12)])->assertOk();

        $this->getJson('/api/status')->assertOk();
    }

    public function test_a_desk_with_no_password_fails_closed_on_the_web_until_one_is_set(): void
    {
        config(['desk.master_password' => '']);

        $this->get('/builder')->assertRedirect('/onboarding');
        $this->get('/')->assertRedirect('/onboarding');
        $this->get('/ai/openrouter/connect')->assertRedirect('/onboarding');
        $this->get('/onboarding')->assertOk();
    }

    public function test_the_retired_require_flag_no_longer_opens_the_gate(): void
    {
        $this->completeOnboarding();
        config(['desk.master_password' => '', 'desk.require_master_password' => false]);

        $this->get('/builder')->assertRedirect('/onboarding');
        $this->getJson('/api/status')->assertForbidden();
    }
}
