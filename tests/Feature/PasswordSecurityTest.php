<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\DeskLogin;
use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $deskPasswordSet = false;

    private const BOOTSTRAP = 'i-0123456789abcdef0';

    private const OWNER = 'owner-password-for-security-test';

    private const WINNER = 'first-concurrent-owner-password';

    private function aws(?string $status): void
    {
        $path = sys_get_temp_dir().'/password-security-'.bin2hex(random_bytes(8));
        if ($status !== null) {
            file_put_contents($path, $status);
        }
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        config(['desk.master_password' => self::BOOTSTRAP, 'desk.first_login_guide' => 'aws', 'desk.tls_status_path' => $path]);
    }

    private function body(string $password = self::OWNER): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_aws_bootstrap_refuses_missing_fallback_and_malformed_trust_markers(): void
    {
        foreach ([null, "fallback ip:127.0.0.1 2026-10-23T00:00:00Z\n", "trustedanything ip:127.0.0.1 2026-10-23T00:00:00Z\n", "trusted ip:127.0.0.1 invalid\n", "trusted ip:127.0.0.1 2026-02-30T00:00:00Z\n", "trusted ip:127.0.0.1 2026-10-23T00:00:00Z\nextra"] as $status) {
            $this->aws($status);

            $this->get('https://127.0.0.1/login')->assertOk()->assertSee('id="tls-warning"', false)
                ->assertDontSee('name="password"', false)->assertDontSee('<form', false);
            $this->post('https://127.0.0.1/login', ['password' => self::BOOTSTRAP])->assertStatus(503);
            $this->postJson('https://127.0.0.1/api/onboarding/master-password', $this->body(), ['X-Desk-Token' => self::BOOTSTRAP])
                ->assertStatus(503)->assertExactJson(['error' => 'trusted_tls_required']);
            $this->getJson('https://127.0.0.1/api/onboarding', ['X-Desk-Token' => self::BOOTSTRAP])
                ->assertStatus(503)->assertExactJson(['error' => 'trusted_tls_required']);
            $this->assertNull(Setting::find('master_password'));
            $this->assertFalse(DeskLogin::check(app('session.store')));
        }
    }

    public function test_trusted_marker_still_requires_actual_https_and_the_matching_host(): void
    {
        $this->aws("trusted ip:127.0.0.1 2026-10-23T00:00:00Z\n");

        $this->get('http://127.0.0.1/login', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()->assertSee('id="tls-warning"', false)->assertDontSee('name="password"', false);
        $this->post('http://127.0.0.1/login', ['password' => self::BOOTSTRAP], ['X-Forwarded-Proto' => 'https'])->assertStatus(503);
        $this->postJson('http://127.0.0.1/api/onboarding/master-password', $this->body(), ['X-Desk-Token' => self::BOOTSTRAP, 'X-Forwarded-Proto' => 'https'])
            ->assertStatus(503);
        $this->get('https://127.0.0.2/login')->assertOk()->assertSee('id="tls-warning"', false);
        $this->get('https://localhost/login')->assertOk()->assertSee('id="tls-warning"', false);

        $this->get('https://127.0.0.1/login')->assertOk()->assertDontSee('id="tls-warning"', false)->assertSee('name="password"', false);
        $this->post('https://127.0.0.1/login', ['password' => self::BOOTSTRAP])->assertRedirect('/onboarding');
        $this->postJson('https://127.0.0.1/api/onboarding/master-password', $this->body(), ['X-CSRF-TOKEN' => session()->token()])->assertOk();
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::OWNER));
    }

    public function test_trusted_dns_marker_requires_its_exact_hostname(): void
    {
        $this->aws("trusted dns:desk.example.test 2026-10-23T00:00:00Z\n");

        $this->get('https://other.example.test/login')->assertOk()->assertSee('id="tls-warning"', false);
        $this->post('https://desk.example.test/login', ['password' => self::BOOTSTRAP])->assertRedirect('/onboarding');
        $this->getJson('https://desk.example.test/api/onboarding')->assertOk();
    }

    public function test_existing_bootstrap_sessions_cannot_bypass_a_lost_tls_status_or_redirect_forever(): void
    {
        $this->aws("trusted ip:127.0.0.1 2026-10-23T00:00:00Z\n");
        $this->post('https://127.0.0.1/login', ['password' => self::BOOTSTRAP])->assertRedirect('/onboarding');
        $csrf = session()->token();
        $this->get('https://127.0.0.1/onboarding')->assertOk();

        file_put_contents(config('desk.tls_status_path'), "fallback ip:127.0.0.1 2026-10-23T00:00:00Z\n");
        $this->get('https://127.0.0.1/onboarding')->assertRedirect('/login');
        $this->get('https://127.0.0.1/login')->assertOk()->assertSee('id="tls-warning"', false);
        $this->postJson('https://127.0.0.1/api/onboarding/master-password', $this->body(), ['X-CSRF-TOKEN' => $csrf])
            ->assertStatus(503);
        $this->getJson('https://127.0.0.1/api/status', ['X-Desk-Token' => self::BOOTSTRAP])->assertStatus(503);
        $this->assertNull(Setting::find('master_password'));
    }

    public function test_aws_without_a_bootstrap_key_still_cannot_claim_over_untrusted_transport(): void
    {
        $this->aws(null);
        config(['desk.master_password' => '']);

        $this->get('/login')->assertOk()->assertSee('id="tls-warning"', false)->assertDontSee('<form', false);
        $this->get('/onboarding')->assertRedirect('/login');
        $this->postJson('/api/onboarding/master-password', $this->body())->assertStatus(503);
        $this->assertNull(Setting::find('master_password'));
    }

    public function test_owner_and_non_aws_logins_do_not_depend_on_the_ami_marker(): void
    {
        $this->aws(null);
        app(Settings::class)->setMasterPassword(self::OWNER);
        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');
        $this->getJson('/api/onboarding')->assertOk();
        $this->flushSession();

        app(Settings::class)->forget('master_password');
        config(['desk.first_login_guide' => null]);
        $this->post('/login', ['password' => self::BOOTSTRAP])->assertRedirect('/onboarding');
        $this->postJson('/api/onboarding/master-password', $this->body(), ['X-CSRF-TOKEN' => session()->token()])->assertOk();
    }

    public function test_exactly_72_bytes_authenticate_but_appended_suffixes_never_do(): void
    {
        config(['desk.master_password' => '', 'desk.first_login_guide' => null]);
        $owner = str_repeat('a', 72);
        $candidate = $owner.'suffix';
        app(Settings::class)->setMasterPassword($owner);
        $this->assertTrue(Hash::check($candidate, Setting::find('master_password')->value), 'positive control: bcrypt truncates this suffix');

        $this->post('/login', ['password' => $candidate])->assertSessionHasErrors('password');
        $this->getJson('/api/onboarding', ['X-Desk-Token' => $candidate])->assertUnauthorized();

        // Simulate a success cached by an older build; validation must precede that lookup.
        $cacheKey = 'desk:token-ok:'.hash_hmac('sha256', $candidate."\0".app(Settings::class)->credentialFingerprintSource(), config('app.key'));
        Cache::put($cacheKey, true, 60);
        $this->assertFalse(app(Settings::class)->verifyToken($candidate));
        $this->getJson('/api/onboarding', ['X-Desk-Token' => $candidate])->assertUnauthorized();
        $this->postJson('/api/onboarding/master-password', $this->body(), ['X-Desk-Token' => $owner])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/onboarding/master-password', $this->body() + ['current_password' => $candidate], ['X-Desk-Token' => $owner])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertTrue(app(Settings::class)->verifyMasterPassword($owner));

        $this->post('/login', ['password' => $owner])->assertRedirect('/');
        $this->getJson('/api/onboarding', ['X-Desk-Token' => $owner])->assertOk();
        $this->postJson('/api/onboarding/master-password', $this->body() + ['current_password' => $owner], ['X-Desk-Token' => $owner])->assertOk();
    }

    public function test_multibyte_passwords_use_the_same_byte_limit_on_verification(): void
    {
        $owner = str_repeat('€', 24);
        $this->assertSame(72, strlen($owner));
        app(Settings::class)->setMasterPassword($owner);

        $this->assertTrue(app(Settings::class)->verifyMasterPassword($owner));
        $this->assertTrue(app(Settings::class)->verifyToken($owner));
        $this->assertFalse(app(Settings::class)->verifyMasterPassword($owner.'€'));
        $this->assertFalse(app(Settings::class)->verifyToken($owner.'€'));
    }

    public function test_first_owner_losing_a_concurrent_setup_cannot_overwrite_or_authenticate(): void
    {
        config(['desk.master_password' => '', 'desk.first_login_guide' => null]);
        $this->racePasswordWrite();

        $this->postJson('/api/onboarding/master-password', $this->body())->assertStatus(409)->assertJsonPath('error', 'credential_changed');
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::WINNER));
        $this->assertFalse(app(Settings::class)->verifyMasterPassword(self::OWNER));
        $this->assertFalse(DeskLogin::check(app('session.store')));
        $this->assertNull(Setting::find('onboarding_state'));
    }

    public function test_losing_password_rotation_cannot_overwrite_the_winner_or_keep_its_session(): void
    {
        config(['desk.first_login_guide' => null]);
        app(Settings::class)->setMasterPassword(self::OWNER);
        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');
        $this->racePasswordWrite();

        $this->postJson('/api/onboarding/master-password', $this->body('losing-rotation-password') + ['current_password' => self::OWNER], ['X-CSRF-TOKEN' => session()->token()])
            ->assertStatus(409)->assertJsonPath('error', 'credential_changed');
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::WINNER));
        $this->assertFalse(app(Settings::class)->verifyMasterPassword('losing-rotation-password'));
        $this->assertFalse(DeskLogin::check(app('session.store')));
    }

    private function racePasswordWrite(): void
    {
        $this->app->instance(Settings::class, new class extends Settings
        {
            public function setMasterPasswordIfUnchanged(string $password, string $expectedCredential): ?string
            {
                // A different request wins after this request authorized its input but before
                // the conditional storage operation. The real CAS must refuse that stale input.
                $this->setMasterPassword('first-concurrent-owner-password');

                return parent::setMasterPasswordIfUnchanged($password, $expectedCredential);
            }
        });
    }

    public function test_credentials_are_read_fresh_even_if_a_displaced_reader_refills_the_generic_cache(): void
    {
        app(Settings::class)->setMasterPassword(self::OWNER);
        $oldHash = Setting::find('master_password')->value;
        $oldFingerprint = DeskLogin::fingerprint();
        app(Settings::class)->setMasterPassword(self::WINNER);
        Cache::put('desk:settings', ['master_password' => $oldHash], 30);

        $this->assertFalse(app(Settings::class)->verifyMasterPassword(self::OWNER));
        $this->assertFalse(app(Settings::class)->verifyToken(self::OWNER));
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::WINNER));
        $this->assertNotSame($oldFingerprint, DeskLogin::fingerprint());
    }

    public function test_a_displaced_login_does_not_receive_the_replacement_owners_session(): void
    {
        config(['desk.first_login_guide' => null]);
        app(Settings::class)->setMasterPassword(self::OWNER);
        $this->app->instance(Settings::class, new class extends Settings
        {
            public function verifyMasterPassword(string $candidate): bool
            {
                $verified = parent::verifyMasterPassword($candidate);
                $this->setMasterPassword('first-concurrent-owner-password');

                return $verified;
            }
        });

        $this->post('/login', ['password' => self::OWNER])->assertRedirect('/');
        $this->assertFalse(DeskLogin::check(app('session.store')));
        $this->getJson('/api/onboarding')->assertUnauthorized();
    }

    public function test_a_successful_password_write_cannot_grant_a_newer_owners_session(): void
    {
        config(['desk.master_password' => '', 'desk.first_login_guide' => null]);
        $this->app->instance(Settings::class, new class extends Settings
        {
            public function setMasterPasswordIfUnchanged(string $password, string $expectedCredential): ?string
            {
                $credential = parent::setMasterPasswordIfUnchanged($password, $expectedCredential);
                $this->setMasterPassword('first-concurrent-owner-password');

                return $credential;
            }
        });

        $this->postJson('/api/onboarding/master-password', $this->body())->assertOk();
        $this->assertFalse(DeskLogin::check(app('session.store')));
        $this->getJson('/api/onboarding')->assertUnauthorized();
    }

    public function test_cli_get_refuses_password_keys_and_aliases_but_reads_normal_settings(): void
    {
        config(['desk.master_password' => self::BOOTSTRAP]);
        foreach (['master_password', 'master_password.shadow', 'MASTER_PASSWORD', 'master_password ', 'mäster_password'] as $key) {
            $this->artisan('desk:ctl', ['action' => 'get', 'arg' => $key])
                ->expectsOutput('invalid or protected setting key')->doesntExpectOutput('"'.self::BOOTSTRAP.'"')->assertFailed();
        }
        app(Settings::class)->setMasterPassword(self::OWNER);
        $hash = Setting::find('master_password')->value;
        $this->artisan('desk:ctl', ['action' => 'get', 'arg' => 'master_password'])
            ->expectsOutput('invalid or protected setting key')->doesntExpectOutput(json_encode($hash))->assertFailed();
        app(Settings::class)->set('mode', 'paper');
        $this->artisan('desk:ctl', ['action' => 'get', 'arg' => 'mode'])->expectsOutput('"paper"')->assertSuccessful();
    }
}
