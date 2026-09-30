<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Logins now last about a year, so switching to live needs the master password typed again: from
 * a browser session and from an X-Desk-Token script alike.
 */
class LiveModeGateTest extends TestCase
{
    use RefreshDatabase;

    private function goLive(array $extra = [], array $headers = []): TestResponse
    {
        return $this->putJson('/api/settings', ['key' => 'mode', 'value' => 'live'] + $extra, $headers);
    }

    public function test_live_without_a_password_is_refused_even_with_a_valid_token(): void
    {
        $this->goLive()->assertStatus(422)->assertJsonPath('errors.password.0', 'password required to go live');
        $this->goLive(['password' => ''])->assertStatus(422)->assertJsonPath('errors.password.0', 'password required to go live');
        $this->goLive(['password' => ['array']])->assertStatus(422)->assertJsonPath('errors.password.0', 'password required to go live');

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertNull(Setting::find('mode'));
    }

    public function test_a_logged_in_browser_without_the_header_still_needs_the_password(): void
    {
        $this->withoutHeader('X-Desk-Token')->withHeader('X-CSRF-TOKEN', session()->token());

        $this->goLive()->assertStatus(422)->assertJsonPath('errors.password.0', 'password required to go live');
        $this->goLive(['password' => self::DESK_PASSWORD])->assertOk();
        $this->assertSame('live', app(Settings::class)->mode());
    }

    public function test_a_wrong_password_is_refused_and_counted_by_the_login_throttle(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->goLive(['password' => 'guess'.$i])->assertStatus(422)->assertJsonPath('errors.password.0', 'wrong password');
        }

        $this->goLive(['password' => self::DESK_PASSWORD])->assertStatus(429);
        $this->assertSame('paper', app(Settings::class)->mode());

        // The same bucket locks out the login form.
        $this->post('/login', ['password' => self::DESK_PASSWORD])->assertStatus(429);
    }

    public function test_the_correct_password_goes_live(): void
    {
        $this->goLive(['password' => self::DESK_PASSWORD])
            ->assertOk()->assertJson(['ok' => true, 'key' => 'mode', 'value' => 'live']);

        $this->assertSame('live', app(Settings::class)->mode());
    }

    public function test_the_bootstrap_key_cannot_go_live_because_it_cannot_reach_settings_at_all(): void
    {
        Setting::where('key', 'master_password')->delete();
        app(Settings::class)->forget('master_password');
        config(['desk.master_password' => 'i-0123456789abcdef0']);
        $this->flushHeaders();

        $this->goLive(['password' => 'i-0123456789abcdef0'], ['X-Desk-Token' => 'i-0123456789abcdef0'])
            ->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->assertSame('paper', app(Settings::class)->mode());
    }

    public function test_paper_needs_no_password(): void
    {
        $this->goLive(['password' => self::DESK_PASSWORD])->assertOk();

        $this->putJson('/api/settings', ['key' => 'mode', 'value' => 'paper'])->assertOk();
        $this->assertSame('paper', app(Settings::class)->mode());
    }

    public function test_other_settings_are_untouched_by_the_gate(): void
    {
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0.04'])->assertOk();
    }

    public function test_spelling_variants_cannot_sneak_live_past_the_gate(): void
    {
        foreach (['Live', 'LIVE', ' live', 'live '] as $value) {
            $this->putJson('/api/settings', ['key' => 'mode', 'value' => $value])->assertStatus(422);
        }
        foreach (['MODE', 'Mode'] as $key) {
            $this->putJson('/api/settings', ['key' => $key, 'value' => 'live', 'password' => self::DESK_PASSWORD])->assertStatus(422);
        }
        $this->putJson('/api/settings', ['key' => 'mode', 'value' => ['live'], 'password' => self::DESK_PASSWORD])->assertStatus(422);

        $this->assertSame('paper', app(Settings::class)->mode());
    }

    public function test_dropping_the_paper_override_while_env_says_live_needs_the_password(): void
    {
        config(['desk.mode' => 'live']);
        app(Settings::class)->set('mode', 'paper');

        $this->deleteJson('/api/settings/mode')->assertStatus(422)->assertJsonPath('errors.password.0', 'password required to go live');
        $this->assertSame('paper', app(Settings::class)->mode());

        $this->deleteJson('/api/settings/mode', ['password' => self::DESK_PASSWORD])->assertOk();
        $this->assertSame('live', app(Settings::class)->mode());
    }

    public function test_dropping_the_override_is_free_when_the_env_mode_is_paper(): void
    {
        app(Settings::class)->set('mode', 'live');

        $this->deleteJson('/api/settings/mode')->assertOk();
        $this->assertSame('paper', app(Settings::class)->mode());
    }

    public function test_the_cli_asks_for_the_password_before_going_live(): void
    {
        $this->artisan('desk:ctl', ['action' => 'set', 'arg' => 'mode', 'value' => 'live'])
            ->expectsQuestion('Master password (required to go live)', 'wrong-password')
            ->assertFailed();
        $this->assertSame('paper', app(Settings::class)->mode());

        $this->artisan('desk:ctl', ['action' => 'set', 'arg' => 'mode', 'value' => 'LIVE'])
            ->expectsQuestion('Master password (required to go live)', '')
            ->assertFailed();
        $this->assertSame('paper', app(Settings::class)->mode());

        $this->artisan('desk:ctl', ['action' => 'set', 'arg' => 'mode', 'value' => 'live'])
            ->expectsQuestion('Master password (required to go live)', self::DESK_PASSWORD)
            ->assertSuccessful();
        $this->assertSame('live', app(Settings::class)->mode());

        $this->artisan('desk:ctl', ['action' => 'set', 'arg' => 'mode', 'value' => 'paper'])->assertSuccessful();
        $this->assertSame('paper', app(Settings::class)->mode());
    }

    public function test_single_value_settings_reject_descendants_and_arrays(): void
    {
        foreach (['mode', 'strategy', 'exchange', 'live_confirm', 'use_scheduler'] as $key) {
            $this->putJson('/api/settings', ['key' => $key.'.x', 'value' => 'live'])->assertStatus(422);
            $this->putJson('/api/settings', ['key' => $key, 'value' => ['a' => 1]])->assertStatus(422);
        }

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertSame([], Setting::where('key', 'like', 'mode%')->get()->all());
    }

    public function test_legacy_nested_or_array_mode_rows_do_not_crash_the_desk(): void
    {
        Setting::create(['key' => 'mode.x', 'value' => 'live']);
        Setting::create(['key' => 'strategy', 'value' => ['a' => 1]]);
        Cache::forget('desk:settings');

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertSame('mr', app(Settings::class)->strategyKey());
        $this->getJson('/api/status')->assertOk();

        (require database_path('migrations/2026_09_30_000004_purge_nested_scalar_setting_rows.php'))->up();
        $this->assertNull(Setting::find('mode.x'));
        $this->assertNull(Setting::find('strategy'));
    }

    public function test_the_cli_rejects_alias_and_protected_keys_before_any_write(): void
    {
        foreach (['MODE', 'mode ', 'mäde', 'Mode', 'mode.x', 'master_password', 'MASTER_PASSWORD', 'master_password.x'] as $key) {
            $this->artisan('desk:ctl', ['action' => 'set', 'arg' => $key, 'value' => 'live'])->assertFailed();
        }

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertNull(Setting::find('mode'));
        $this->assertTrue(Hash::check(self::DESK_PASSWORD, Setting::find('master_password')->value));
    }

    public function test_onboarding_only_ever_writes_paper_mode(): void
    {
        $this->putJson('/api/settings', ['key' => 'mode', 'value' => 'live', 'password' => self::DESK_PASSWORD])->assertOk();
        app(Settings::class)->set('onboarding_state', ['steps' => ['master-password' => ['status' => 'done'], 'openrouter' => ['status' => 'done'], 'exchange' => ['status' => 'done'], 'strategy-import' => ['status' => 'skipped']]]);

        $this->postJson('/api/onboarding/launch', ['mode' => 'live'])->assertOk()->assertJsonPath('mode', 'paper');
        $this->assertSame('paper', app(Settings::class)->mode());
    }
}
