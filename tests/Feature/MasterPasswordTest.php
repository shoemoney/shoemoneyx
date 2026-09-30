<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MasterPasswordTest extends TestCase
{
    use RefreshDatabase;

    // Master-password gating is unrelated to onboarding; mark the wizard complete so the
    // new root redirect never intercepts these assertions.
    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            OnboardingWizard::ORDER,
            ['status' => 'done'],
        )]);
    }

    public function test_pages_open_when_no_master_password(): void
    {
        config(['desk.master_password' => '']);

        $this->get('/')->assertOk();
        $this->get('/login')->assertRedirect('/');
    }

    public function test_pages_gate_behind_login_when_password_set(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->get('/')->assertRedirect('/login');
        $this->get('/builder')->assertRedirect('/login');
        $this->get('/login')->assertOk();

        $this->post('/login', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/login', ['password' => 'secret'])->assertRedirect('/');

        $this->get('/')->assertOk();
        $this->get('/builder')->assertOk();
    }

    public function test_api_accepts_master_password_header_but_not_query_param(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->getJson('/api/status')->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk();
        $this->getJson('/api/status?token=secret')->assertUnauthorized();
    }

    public function test_login_never_renders_the_password_into_the_page(): void
    {
        config(['desk.master_password' => 'secret-pw-123']);

        $this->post('/login', ['password' => 'secret-pw-123'])->assertRedirect('/');

        $this->get('/')->assertOk()
            ->assertDontSee('secret-pw-123', false)
            ->assertDontSee('desk_token', false)
            ->assertSee('<meta name="csrf-token" content="'.session()->token().'">', false);
    }

    public function test_login_session_authenticates_the_api_and_writes_need_csrf(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->post('/login', ['password' => 'secret']);

        $this->getJson('/api/status')->assertOk();
        $this->putJson('/api/settings', [])->assertStatus(419);
        $this->putJson('/api/settings', [], ['X-CSRF-TOKEN' => 'forged'])->assertStatus(419);
        $this->putJson('/api/settings', [], ['X-CSRF-TOKEN' => session()->token()])
            ->assertStatus(422);
    }

    public function test_logout_revokes_page_and_api_access(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->post('/login', ['password' => 'secret']);
        $this->getJson('/api/status')->assertOk();

        $this->post('/logout')->assertRedirect('/login');

        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/status')->assertUnauthorized();
    }

    public function test_settings_api_never_returns_the_master_password(): void
    {
        config(['desk.master_password' => 'env-secret-pw']);
        $headers = ['X-Desk-Token' => 'env-secret-pw'];

        $this->getJson('/api/settings', $headers)->assertOk()->assertDontSee('env-secret-pw');

        app(Settings::class)->set('master_password', 'override-secret-pw');
        $this->getJson('/api/settings', ['X-Desk-Token' => 'override-secret-pw'])->assertOk()
            ->assertDontSee('override-secret-pw')
            ->assertJsonMissingPath('overrides.master_password')
            ->assertJsonMissingPath('params.master_password');
    }

    public function test_settings_api_cannot_change_or_clear_the_master_password(): void
    {
        config(['desk.master_password' => 'secret']);
        $headers = ['X-Desk-Token' => 'secret'];

        $this->putJson('/api/settings', ['key' => 'master_password', 'value' => ''], $headers)->assertStatus(422);
        $this->deleteJson('/api/settings/master_password', [], $headers)->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'master_password.shadow', 'value' => 'x'], $headers)->assertStatus(422);
        $this->deleteJson('/api/settings/master_password.shadow', [], $headers)->assertStatus(422);

        // MariaDB's utf8mb4_unicode_ci would match every one of these to the master_password row.
        foreach (['MASTER_PASSWORD', 'Master_Password.x', 'master_password ', ' master_password', 'mäster_password'] as $alias) {
            $this->putJson('/api/settings', ['key' => $alias, 'value' => ''], $headers)->assertStatus(422);
            $this->deleteJson('/api/settings/'.rawurlencode($alias), [], $headers)->assertStatus(422);
        }

        $this->assertSame('secret', app(Settings::class)->masterPassword());
        $this->getJson('/api/status')->assertUnauthorized();
    }

    public function test_a_legacy_nested_password_row_cannot_turn_the_password_into_array(): void
    {
        config(['desk.master_password' => 'secret']);
        Setting::create(['key' => 'master_password.shadow', 'value' => 'x']);
        Cache::forget('desk:settings');

        $this->assertSame('secret', app(Settings::class)->masterPassword());
        $this->getJson('/api/status', ['X-Desk-Token' => 'Array'])->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk();
    }

    public function test_migration_purges_legacy_unsafe_setting_rows(): void
    {
        foreach (['master_password.shadow', 'MASTER_PASSWORD', 'size.kelly_cap_pct'] as $key) {
            Setting::create(['key' => $key, 'value' => 'x']);
        }

        (require database_path('migrations/2026_09_30_000001_purge_unsafe_setting_keys.php'))->up();

        $keys = Setting::query()->pluck('key')->all();
        $this->assertContains('size.kelly_cap_pct', $keys);
        $this->assertNotContains('master_password.shadow', $keys);
        $this->assertNotContains('MASTER_PASSWORD', $keys);
    }

    public function test_changing_the_password_ends_existing_browser_sessions(): void
    {
        config(['desk.master_password' => 'secret']);
        $this->post('/login', ['password' => 'secret']);
        $this->getJson('/api/status')->assertOk();

        app(Settings::class)->set('master_password', 'rotated-password');

        $this->getJson('/api/status')->assertUnauthorized();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_script_clients_using_the_header_get_no_session_cookie(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk()
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_login_page_shows_the_hint_while_the_password_is_still_the_bootstrap_value(): void
    {
        config(['desk.master_password' => 'i-0abc123def456789', 'desk.master_password_hint' => 'Your EC2 instance ID']);

        $this->get('/login')->assertOk()->assertSee('id="master-password-hint"', false)->assertSee('Your EC2 instance ID');
    }

    public function test_login_page_hides_the_hint_once_the_buyer_sets_their_own_password(): void
    {
        config(['desk.master_password' => 'i-0abc123def456789', 'desk.master_password_hint' => 'Your EC2 instance ID']);
        app(Settings::class)->set('master_password', 'my-own-password');

        $this->get('/login')->assertOk()->assertDontSee('id="master-password-hint"', false);
    }

    public function test_login_page_hides_the_hint_when_no_hint_is_configured(): void
    {
        config(['desk.master_password' => 'i-0abc123def456789', 'desk.master_password_hint' => '']);

        $this->get('/login')->assertOk()->assertDontSee('id="master-password-hint"', false);
    }
}
