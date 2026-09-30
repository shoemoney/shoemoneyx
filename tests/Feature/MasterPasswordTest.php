<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\DeskLogin;
use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MasterPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner-chosen-password';

    private const BOOTSTRAP = 'i-0123456789abcdef0';

    protected bool $deskPasswordSet = false;

    // Master-password gating is unrelated to onboarding; mark the wizard complete so the
    // root redirect never intercepts these assertions.
    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            OnboardingWizard::ORDER,
            ['status' => 'done'],
        )]);
    }

    /** The desk after first-login setup: the owner's own password, stored hashed. */
    private function ownerPassword(): void
    {
        app(Settings::class)->setMasterPassword(self::OWNER);
    }

    private function bootstrapKey(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP]);
    }

    public function test_pages_gate_behind_login_when_password_set(): void
    {
        $this->ownerPassword();

        $this->get('/')->assertRedirect('/login');
        $this->get('/builder')->assertRedirect('/login');
        $this->get('/login')->assertOk();

        $this->post('/login', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');

        $this->get('/')->assertOk();
        $this->get('/builder')->assertOk();
    }

    public function test_api_accepts_master_password_header_but_not_query_param(): void
    {
        $this->ownerPassword();

        $this->getJson('/api/status')->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
        $this->getJson('/api/status?token='.self::OWNER)->assertUnauthorized();
    }

    public function test_login_never_renders_the_password_into_the_page(): void
    {
        $this->ownerPassword();

        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');

        $this->get('/')->assertOk()
            ->assertDontSee(self::OWNER, false)
            ->assertDontSee('desk_token', false)
            ->assertSee('<meta name="csrf-token" content="'.session()->token().'">', false);
    }

    public function test_login_session_authenticates_the_api_and_writes_need_csrf(): void
    {
        $this->ownerPassword();

        $this->post('/login', ['password' => self::OWNER]);

        $this->getJson('/api/status')->assertOk();
        $this->putJson('/api/settings', [])->assertStatus(419);
        $this->putJson('/api/settings', [], ['X-CSRF-TOKEN' => 'forged'])->assertStatus(419);
        $this->putJson('/api/settings', [], ['X-CSRF-TOKEN' => session()->token()])
            ->assertStatus(422);
    }

    public function test_logout_revokes_page_and_api_access(): void
    {
        $this->ownerPassword();

        $this->post('/login', ['password' => self::OWNER]);
        $this->getJson('/api/status')->assertOk();

        $this->post('/logout')->assertRedirect('/login');

        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/status')->assertUnauthorized();
    }

    public function test_the_session_lifetime_defaults_to_a_year_and_survives_browser_close(): void
    {
        // A clean child process that never loads .env: this run's .env may legitimately override SESSION_LIFETIME.
        $process = new Process([PHP_BINARY, '-r', 'function storage_path($path = "") { return $path; } require "vendor/autoload.php"; echo json_encode(require "config/session.php");'], base_path(), ['SESSION_LIFETIME' => false, 'SESSION_EXPIRE_ON_CLOSE' => false]);
        $process->mustRun();
        $defaults = json_decode($process->getOutput(), true);

        $this->assertSame(525600, $defaults['lifetime']);
        $this->assertFalse($defaults['expire_on_close']);
    }

    public function test_a_browser_login_cookie_lasts_the_configured_session_lifetime(): void
    {
        config(['session.lifetime' => 525600]);
        $this->ownerPassword();
        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');
        $cookie = collect($this->get('/')->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertEqualsWithDelta(time() + 525600 * 60, $cookie->getExpiresTime(), 60);
    }

    public function test_the_stored_password_is_a_hash_and_never_the_plaintext(): void
    {
        $this->ownerPassword();

        $stored = Setting::find('master_password')->value;
        $this->assertNotSame(self::OWNER, $stored);
        $this->assertTrue(Hash::check(self::OWNER, $stored));
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::OWNER));
        $this->assertFalse(app(Settings::class)->verifyMasterPassword(strtoupper(self::OWNER)));
    }

    public function test_settings_api_never_returns_the_master_password(): void
    {
        $this->ownerPassword();
        $hash = Setting::find('master_password')->value;

        $this->getJson('/api/settings', ['X-Desk-Token' => self::OWNER])->assertOk()
            ->assertDontSee(self::OWNER)
            ->assertDontSee($hash, false)
            ->assertJsonMissingPath('overrides.master_password')
            ->assertJsonMissingPath('params.master_password');
    }

    public function test_settings_api_cannot_change_or_clear_the_master_password(): void
    {
        $this->ownerPassword();
        $headers = ['X-Desk-Token' => self::OWNER];

        $this->putJson('/api/settings', ['key' => 'master_password', 'value' => ''], $headers)->assertStatus(422);
        $this->deleteJson('/api/settings/master_password', [], $headers)->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'master_password.shadow', 'value' => 'x'], $headers)->assertStatus(422);
        $this->deleteJson('/api/settings/master_password.shadow', [], $headers)->assertStatus(422);

        // MariaDB's utf8mb4_unicode_ci would match every one of these to the master_password row.
        foreach (['MASTER_PASSWORD', 'Master_Password.x', 'master_password ', ' master_password', 'mäster_password'] as $alias) {
            $this->putJson('/api/settings', ['key' => $alias, 'value' => ''], $headers)->assertStatus(422);
            $this->deleteJson('/api/settings/'.rawurlencode($alias), [], $headers)->assertStatus(422);
        }

        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::OWNER));
        $this->getJson('/api/status')->assertUnauthorized();
    }

    public function test_a_legacy_nested_password_row_cannot_turn_the_password_into_array(): void
    {
        $this->ownerPassword();
        Setting::create(['key' => 'master_password.shadow', 'value' => 'x']);
        Cache::forget('desk:settings');

        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::OWNER));
        $this->getJson('/api/status', ['X-Desk-Token' => 'Array'])->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
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

    public function test_migration_hashes_a_legacy_plaintext_password_and_it_still_logs_in(): void
    {
        Setting::create(['key' => 'master_password', 'value' => 'legacy-plaintext-pw']);
        Cache::forget('desk:settings');

        (require database_path('migrations/2026_09_30_000003_hash_stored_master_password.php'))->up();

        $stored = Setting::find('master_password')->value;
        $this->assertNotSame('legacy-plaintext-pw', $stored);
        $this->assertTrue(Hash::check('legacy-plaintext-pw', $stored));
        $this->post('/login', ['password' => 'legacy-plaintext-pw'])->assertRedirect('/');
        $this->getJson('/api/status')->assertOk();

        // Running it again leaves an already-hashed row alone.
        (require database_path('migrations/2026_09_30_000003_hash_stored_master_password.php'))->up();
        $this->assertSame($stored, Setting::find('master_password')->value);
    }

    public function test_migration_drops_the_retired_empty_password_override(): void
    {
        Setting::create(['key' => 'master_password', 'value' => '']);

        (require database_path('migrations/2026_09_30_000003_hash_stored_master_password.php'))->up();

        $this->assertNull(Setting::find('master_password'));
    }

    public function test_an_empty_password_override_is_not_a_credential(): void
    {
        config(['desk.master_password' => '']);
        Setting::create(['key' => 'master_password', 'value' => '']);
        Cache::forget('desk:settings');

        $this->assertFalse(app(Settings::class)->hasMasterPassword());
        $this->getJson('/api/status')->assertForbidden();
        $this->getJson('/api/status', ['X-Desk-Token' => ''])->assertForbidden();
    }

    public function test_changing_the_password_ends_existing_browser_sessions(): void
    {
        $this->ownerPassword();
        $this->post('/login', ['password' => self::OWNER]);
        $this->getJson('/api/status')->assertOk();
        $before = DeskLogin::fingerprint();

        app(Settings::class)->setMasterPassword(self::OWNER.'-rotated');

        $this->assertNotSame($before, DeskLogin::fingerprint());
        $this->getJson('/api/status')->assertUnauthorized();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_changed_password_stops_working_as_a_token_even_inside_the_verification_cache_window(): void
    {
        $this->ownerPassword();
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();

        app(Settings::class)->setMasterPassword(self::OWNER.'-rotated');

        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER.'-rotated'])->assertOk();
    }

    public function test_a_successful_token_is_verified_once_then_served_from_cache_without_storing_it(): void
    {
        $this->ownerPassword();
        $spy = Hash::spy();
        $spy->shouldReceive('check')->once()->andReturn(true);

        $settings = app(Settings::class);
        $this->assertTrue($settings->verifyToken('cached-token-value'));
        $this->assertTrue($settings->verifyToken('cached-token-value'));

        $key = 'desk:token-ok:'.hash('sha256', "cached-token-value\0".Setting::find('master_password')->value);
        $this->assertTrue(Cache::get($key));
        $this->assertStringNotContainsString('cached-token-value', $key);
    }

    public function test_script_clients_using_the_header_get_no_session_cookie(): void
    {
        $this->ownerPassword();

        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk()
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_login_page_shows_the_hint_while_the_password_is_still_the_bootstrap_value(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.master_password_hint' => 'Your EC2 instance ID']);

        $this->get('/login')->assertOk()->assertSee('id="master-password-hint"', false)->assertSee('Your EC2 instance ID');
    }

    public function test_login_page_hides_the_hint_once_the_buyer_sets_their_own_password(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.master_password_hint' => 'Your EC2 instance ID']);
        $this->ownerPassword();

        $this->get('/login')->assertOk()->assertDontSee('id="master-password-hint"', false);
    }

    public function test_login_page_hides_the_hint_when_no_hint_is_configured(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.master_password_hint' => '']);

        $this->get('/login')->assertOk()->assertDontSee('id="master-password-hint"', false);
    }

    public function test_aws_login_guide_links_to_the_regional_console_and_never_shows_the_instance_id(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.first_login_guide' => 'aws', 'desk.aws_region' => 'eu-west-2',
            'desk.master_password_hint' => 'generic hint that the guide replaces']);

        $page = $this->get('/login')->assertOk()
            ->assertSee('id="first-login-guide"', false)
            ->assertSee('Open my AWS instances')
            ->assertSee('Instance ID')
            ->assertSee('i-0abc123def4567890', false)
            ->assertSee('use it once', false)
            ->assertDontSee('id="master-password-hint"', false);

        $html = $page->getContent();
        $this->assertStringContainsString('href="https://eu-west-2.console.aws.amazon.com/ec2/home?region=eu-west-2#Instances:"', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringNotContainsString(self::BOOTSTRAP, $html);
    }

    public function test_aws_login_guide_falls_back_to_the_generic_console_link_without_a_region(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.first_login_guide' => 'aws', 'desk.aws_region' => '']);

        $this->get('/login')->assertOk()
            ->assertSee('href="https://console.aws.amazon.com/ec2/home#Instances:"', false);
    }

    public function test_aws_login_guide_ignores_a_malformed_region_so_it_cannot_shape_the_link(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.first_login_guide' => 'aws', 'desk.aws_region' => 'evil.example/"x']);

        $this->get('/login')->assertOk()
            ->assertSee('href="https://console.aws.amazon.com/ec2/home#Instances:"', false)
            ->assertDontSee('evil.example', false);
    }

    public function test_aws_login_guide_disappears_once_the_owner_has_a_password(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.first_login_guide' => 'aws', 'desk.aws_region' => 'us-east-1']);
        $this->ownerPassword();

        $this->get('/login')->assertOk()
            ->assertDontSee('id="first-login-guide"', false)
            ->assertDontSee('Open my AWS instances');
    }

    public function test_bootstrap_login_lands_on_the_set_password_screen_and_pages_redirect_there(): void
    {
        $this->bootstrapKey();

        $this->get('/builder')->assertRedirect('/login');
        $this->post('/login', ['password' => self::BOOTSTRAP])->assertRedirect('/onboarding');

        $this->get('/builder')->assertRedirect('/onboarding');
        $this->get('/')->assertRedirect('/onboarding');
        $this->get('/ai/openrouter/connect')->assertRedirect('/onboarding');
        $this->get('/login')->assertRedirect('/');
        $this->get('/onboarding')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_bootstrap_session_api_is_403_except_the_two_set_password_calls(): void
    {
        $this->bootstrapKey();
        $this->post('/login', ['password' => self::BOOTSTRAP]);
        $csrf = ['X-CSRF-TOKEN' => session()->token()];

        $this->getJson('/api/status')->assertForbidden()->assertExactJson(['error' => 'set_password_required']);
        $this->getJson('/api/settings')->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->postJson('/api/desk/halt', [], $csrf)->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->postJson('/api/onboarding/openrouter', [], $csrf)->assertForbidden()->assertJson(['error' => 'set_password_required']);

        $this->getJson('/api/onboarding')->assertOk();
        $this->postJson('/api/onboarding/master-password', [], $csrf)->assertStatus(422);
    }

    public function test_bootstrap_key_as_a_header_gets_the_same_restriction(): void
    {
        $this->bootstrapKey();
        $headers = ['X-Desk-Token' => self::BOOTSTRAP];

        $this->getJson('/api/status', $headers)->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->postJson('/api/desk/halt', [], $headers)->assertForbidden();
        $this->getJson('/api/onboarding', $headers)->assertOk();
        $this->postJson('/api/onboarding/master-password', [
            'password' => self::OWNER, 'password_confirmation' => self::OWNER,
        ], $headers)->assertOk();

        // Set: the restriction lifts for the new password and the bootstrap key is dead.
        $this->getJson('/api/status', ['X-Desk-Token' => self::OWNER])->assertOk();
        $this->getJson('/api/status', $headers)->assertUnauthorized();
        $this->post('/login', ['password' => self::BOOTSTRAP])->assertSessionHasErrors('password');
    }

    public function test_bootstrap_key_ignores_case_and_surrounding_whitespace(): void
    {
        $this->bootstrapKey();

        $this->post('/login', ['password' => '  '.strtoupper(self::BOOTSTRAP)." \n"])->assertRedirect('/onboarding');
        $this->getJson('/api/onboarding', ['X-Desk-Token' => ' '.strtoupper(self::BOOTSTRAP).' '])->assertOk();
        $this->getJson('/api/onboarding', ['X-Desk-Token' => substr(self::BOOTSTRAP, 0, -1).'1'])->assertUnauthorized();
    }

    public function test_the_owners_own_password_is_compared_exactly(): void
    {
        $this->bootstrapKey();
        $this->ownerPassword();

        foreach ([' '.self::OWNER, self::OWNER.' ', strtoupper(self::OWNER)] as $variant) {
            $this->post('/login', ['password' => $variant])->assertSessionHasErrors('password');
            $this->getJson('/api/status', ['X-Desk-Token' => $variant])->assertUnauthorized();
        }
        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');
    }

    public function test_bootstrap_failures_are_throttled_like_any_other_guess(): void
    {
        $this->bootstrapKey();

        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['password' => 'guess'.$i])->assertSessionHasErrors('password');
        }

        $this->post('/login', ['password' => self::BOOTSTRAP])->assertStatus(429);
    }

    public function test_a_fresh_install_with_no_password_at_all_fails_closed(): void
    {
        config(['desk.master_password' => '']);

        $this->get('/')->assertRedirect('/onboarding');
        $this->get('/builder')->assertRedirect('/onboarding');
        $this->get('/login')->assertRedirect('/onboarding');
        $this->post('/login', ['password' => ''])->assertSessionHasErrors('password');
        $this->post('/login', ['password' => 'anything'])->assertSessionHasErrors('password');
        $this->getJson('/api/status')->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->getJson('/api/status', ['X-Desk-Token' => ''])->assertForbidden();
        $this->assertFalse(DeskLogin::check(app('session.store')));
    }
}
