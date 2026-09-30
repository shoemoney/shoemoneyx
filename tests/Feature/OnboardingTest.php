<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\DeskLogin;
use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Models\AiConnection;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * First-run wizard: no key -> paper trading, in under five minutes. GET /api/onboarding
 * reports progress (persisted server-side via Settings under 'onboarding_state', so it
 * survives a restart); POST /api/onboarding/{step} advances one step at a time and each
 * step but strategy-import must be completed in order before the next will accept.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-or-v1-onboardtestkey';

    private const NEW_PASSWORD = 'my-own-desk-password';

    private const CHANGED_PASSWORD = 'a-brand-new-password';

    protected bool $deskPasswordSet = false;

    private const SOURCE = 'https://fake.example.com/onboarding-repo/';

    /** Sets the owner's password on a fresh desk; the session that sets it is signed in and carries its new CSRF token. */
    private function setPassword(string $password = self::NEW_PASSWORD): void
    {
        $csrf = $this->postJson('/api/onboarding/master-password', ['password' => $password, 'password_confirmation' => $password])
            ->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-TOKEN', $csrf);
    }

    private function fakeOpenRouterKey(string $label = 'shoemoneyx desk'): void
    {
        Http::fake(['openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => $label, 'limit_remaining' => 9.5]])]);
    }

    private function manifestEntry(string $key): array
    {
        return [
            'schema_version' => 1,
            'key' => $key,
            'meta' => ['name' => ucfirst($key), 'description' => 'Onboarding fixture.'],
            'entry' => ['side' => 'long'],
        ];
    }

    private function fakeManifest(string $remoteId): void
    {
        $content = json_encode($this->manifestEntry($remoteId));
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'shoemoneyx desk', 'limit_remaining' => 9.5]]),
            self::SOURCE.'manifest.json' => Http::response(['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'strategies' => [[
                'id' => $remoteId, 'name' => ucfirst($remoteId), 'version' => '1.0.0', 'file' => "strategies/{$remoteId}.json",
                'sha256' => hash('sha256', $content), 'tags' => [], 'timeframe' => '1h', 'assets' => [], 'author' => 'community',
                'description' => '', 'min_desk_schema' => 1,
            ]]]),
            self::SOURCE."strategies/{$remoteId}.json" => Http::response($content),
        ]);
        config()->set('strategies.source_url', self::SOURCE);
    }

    public function test_fresh_install_reports_no_step_done_and_next_step_master_password(): void
    {
        $res = $this->getJson('/api/onboarding')->assertOk()->json();

        $this->assertFalse($res['completed']);
        $this->assertSame('master-password', $res['next_step']);
        $this->assertSame(
            ['master-password', 'openrouter', 'exchange', 'strategy-import', 'launch'],
            array_column($res['steps'], 'key'),
        );
        $this->assertTrue(collect($res['steps'])->every(fn ($s) => $s['status'] === 'pending'));
        $this->assertTrue(collect($res['steps'])->firstWhere('key', 'strategy-import')['skippable']);
        $this->assertFalse(collect($res['steps'])->firstWhere('key', 'exchange')['skippable']);
    }

    public function test_root_redirects_to_onboarding_on_a_fresh_install(): void
    {
        config(['desk.master_password' => '']);

        $this->get('/')->assertRedirect('/onboarding');
    }

    public function test_root_does_not_redirect_once_onboarding_is_complete(): void
    {
        $this->signInAsOwner();
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            ['master-password', 'openrouter', 'exchange', 'strategy-import', 'launch'],
            ['status' => 'done'],
        )]);

        $this->get('/')->assertOk();
    }

    public function test_master_password_step_stores_only_a_hash_and_authenticates_the_session(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'master-password', 'status' => 'done', 'master_password_set' => true]);

        $stored = Setting::find('master_password')?->value;
        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::NEW_PASSWORD));
        $this->assertTrue(DeskLogin::check(app('session.store')));
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) session('desk_authed'));

        // The browser that set it stays logged in through its session; any other client
        // now needs the password, exactly as for an operator-configured MASTER_PASSWORD.
        $this->getJson('/api/onboarding')->assertOk();
        $this->flushSession();
        $this->getJson('/api/onboarding')->assertUnauthorized();
        $this->getJson('/api/onboarding', ['X-Desk-Token' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_later_steps_work_with_the_csrf_token_rotated_by_the_password_step(): void
    {
        $this->get('/login');
        $staleToken = session()->token();

        $fresh = $this->postJson('/api/onboarding/master-password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertOk()->json('csrf_token');

        $this->assertNotSame($staleToken, $fresh);
        $this->postJson('/api/onboarding/openrouter', [], ['X-CSRF-TOKEN' => $staleToken])->assertStatus(419);
        $this->postJson('/api/onboarding/openrouter', [], ['X-CSRF-TOKEN' => $fresh])->assertStatus(422);
    }

    public function test_master_password_step_rejects_an_empty_or_missing_password(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => '', 'password_confirmation' => ''])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/onboarding/master-password', [])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(Setting::find('master_password'));
        $this->assertSame('master-password', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_master_password_step_needs_a_matching_confirmation(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => self::NEW_PASSWORD])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/onboarding/master-password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'something else entirely'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'The two passwords do not match.');

        $this->assertNull(Setting::find('master_password'));
    }

    public function test_master_password_step_enforces_length_limits(): void
    {
        $short = str_repeat('a', 11);
        $this->postJson('/api/onboarding/master-password', ['password' => $short, 'password_confirmation' => $short])->assertStatus(422)->assertJsonValidationErrors('password');

        $long = str_repeat('a', 201);
        $this->postJson('/api/onboarding/master-password', ['password' => $long, 'password_confirmation' => $long])->assertStatus(422)->assertJsonValidationErrors('password');

        $min = str_repeat('a', 12);
        $this->postJson('/api/onboarding/master-password', ['password' => $min, 'password_confirmation' => $min])->assertOk();
    }

    public function test_master_password_step_refuses_the_bootstrap_key_as_the_new_password(): void
    {
        config(['desk.master_password' => 'i-0abc123def4567890']);
        $this->post('/login', ['password' => 'i-0abc123def4567890']);
        $csrf = session()->token();

        foreach (['i-0abc123def4567890', ' I-0ABC123DEF4567890 '] as $reused) {
            $this->postJson('/api/onboarding/master-password', ['password' => $reused, 'password_confirmation' => $reused], ['X-CSRF-TOKEN' => $csrf])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->assertNull(Setting::find('master_password'));
    }

    public function test_fresh_install_with_no_password_at_all_fails_closed_but_allows_setup(): void
    {
        config(['desk.master_password' => '']);

        $this->getJson('/api/status')->assertForbidden()->assertJson(['error' => 'set_password_required']);
        $this->getJson('/api/onboarding')->assertOk();
        $this->get('/builder')->assertRedirect('/onboarding');
        $this->get('/login')->assertRedirect('/onboarding');
        $this->get('/onboarding')->assertOk();

        $this->postJson('/api/onboarding/master-password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertOk();
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::NEW_PASSWORD));
    }

    public function test_setup_endpoint_is_throttled_with_the_login_failure_bucket(): void
    {
        $this->setPassword();
        $this->flushSession();
        $this->flushHeaders();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/onboarding/master-password', [
                'current_password' => 'wrong'.$i, 'password' => self::CHANGED_PASSWORD, 'password_confirmation' => self::CHANGED_PASSWORD,
            ], ['X-Desk-Token' => self::NEW_PASSWORD])->assertStatus(422);
        }

        $this->postJson('/api/onboarding/master-password', [
            'current_password' => self::NEW_PASSWORD, 'password' => self::CHANGED_PASSWORD, 'password_confirmation' => self::CHANGED_PASSWORD,
        ], ['X-Desk-Token' => self::NEW_PASSWORD])->assertStatus(429);
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::NEW_PASSWORD));
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $this->setPassword();
        $body = ['password' => self::CHANGED_PASSWORD, 'password_confirmation' => self::CHANGED_PASSWORD];

        $this->postJson('/api/onboarding/master-password', $body)->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/onboarding/master-password', $body + ['current_password' => 'not-the-password'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::NEW_PASSWORD));

        $this->postJson('/api/onboarding/master-password', $body + ['current_password' => self::NEW_PASSWORD])->assertOk();
        $this->assertTrue(app(Settings::class)->verifyMasterPassword(self::CHANGED_PASSWORD));
        $this->assertFalse(app(Settings::class)->verifyMasterPassword(self::NEW_PASSWORD));
    }

    public function test_changing_the_password_keeps_this_browser_in_and_ends_other_sessions(): void
    {
        $this->setPassword();
        $otherBrowserFingerprint = DeskLogin::fingerprint();

        $this->postJson('/api/onboarding/master-password', [
            'current_password' => self::NEW_PASSWORD, 'password' => self::CHANGED_PASSWORD, 'password_confirmation' => self::CHANGED_PASSWORD,
        ])->assertOk();

        $this->assertTrue(DeskLogin::check(app('session.store')));
        $this->getJson('/api/onboarding')->assertOk();
        $this->assertNotSame($otherBrowserFingerprint, DeskLogin::fingerprint());

        $this->flushSession();
        $this->withSession(['desk_authed' => $otherBrowserFingerprint]);
        $this->getJson('/api/onboarding', ['X-Desk-Token' => ''])->assertUnauthorized();
    }

    public function test_state_reports_bootstrap_and_current_password_flags(): void
    {
        config(['desk.master_password' => 'i-0abc123def4567890']);

        $res = $this->getJson('/api/onboarding', ['X-Desk-Token' => 'i-0abc123def4567890'])->assertOk()->json();
        $this->assertTrue($res['bootstrap']);
        $this->assertFalse($res['current_password_required']);
        $this->assertArrayNotHasKey('require_master_password', $res);

        $this->postJson('/api/onboarding/master-password', [
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], ['X-Desk-Token' => 'i-0abc123def4567890'])->assertOk();

        $res = $this->getJson('/api/onboarding', ['X-Desk-Token' => self::NEW_PASSWORD])->assertOk()->json();
        $this->assertFalse($res['bootstrap']);
        $this->assertTrue($res['current_password_required']);
    }

    public function test_master_password_step_reads_pending_until_the_owner_has_a_password(): void
    {
        config(['desk.master_password' => 'i-0abc123def4567890']);
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(OnboardingWizard::ORDER, ['status' => 'done'])]);

        $state = $this->getJson('/api/onboarding', ['X-Desk-Token' => 'i-0abc123def4567890'])->assertOk()->json();

        $this->assertFalse($state['completed']);
        $this->assertSame('master-password', $state['next_step']);
    }

    public function test_openrouter_step_rejects_out_of_order_submission(): void
    {
        $this->signInAsOwner();
        $this->fakeOpenRouterKey();

        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'next_step' => 'master-password']);
    }

    public function test_openrouter_step_stores_a_pasted_key_encrypted_the_same_way_pkce_does(): void
    {
        $this->setPassword();
        $this->fakeOpenRouterKey('paste flow desk');

        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'openrouter', 'status' => 'done', 'label' => 'paste flow desk']);

        $connection = AiConnection::sole();
        $this->assertSame(self::KEY, $connection->key);
        $raw = DB::table('ai_connections')->where('id', $connection->id)->value('key');
        $this->assertStringNotContainsString(self::KEY, (string) $raw);

        $this->assertSame('exchange', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_openrouter_step_rejects_a_key_openrouter_does_not_recognize(): void
    {
        $this->setPassword();
        Http::fake(['openrouter.ai/api/v1/key' => Http::response(['error' => 'invalid'], 401)]);

        $this->postJson('/api/onboarding/openrouter', ['api_key' => 'sk-or-v1-bogus'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AiConnection::count());
        $this->assertSame('openrouter', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_exchange_step_defaults_to_coinbase_paper_and_rejects_unknown_ids(): void
    {
        $this->setPassword();
        $this->fakeOpenRouterKey();
        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY]);

        $this->postJson('/api/onboarding/exchange', ['exchange' => 'not-a-real-exchange'])
            ->assertStatus(422);

        $this->postJson('/api/onboarding/exchange', ['exchange' => 'coinbase'])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'exchange', 'status' => 'done', 'exchange' => 'coinbase', 'mode' => 'paper']);

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertSame('strategy-import', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_strategy_import_step_is_skippable(): void
    {
        $this->setPassword();
        $this->fakeOpenRouterKey();
        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY]);
        $this->postJson('/api/onboarding/exchange', ['exchange' => 'coinbase']);

        $this->postJson('/api/onboarding/strategy-import', ['skip' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'strategy-import', 'status' => 'skipped']);

        $this->assertSame('launch', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_strategy_import_step_imports_a_community_strategy_over_http(): void
    {
        $this->setPassword();
        $this->fakeManifest('onboard-strategy');
        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY]);
        $this->postJson('/api/onboarding/exchange', ['exchange' => 'coinbase']);

        $this->postJson('/api/onboarding/strategy-import', ['remote_id' => 'onboard-strategy'])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'strategy-import', 'status' => 'done', 'imported' => 'onboard-strategy']);

        $this->assertDatabaseHas('synced_strategies', ['remote_id' => 'onboard-strategy']);
    }

    public function test_launch_step_refuses_until_earlier_steps_are_settled(): void
    {
        $this->signInAsOwner();

        $this->postJson('/api/onboarding/launch')
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'next_step' => 'master-password']);
    }

    public function test_completing_all_five_steps_reaches_paper_trading_with_no_env_edits(): void
    {
        $this->setPassword();

        $this->fakeManifest('quickstart-strategy');
        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY])
            ->assertOk()->assertJsonPath('status', 'done');

        $this->postJson('/api/onboarding/exchange', ['exchange' => 'coinbase'])
            ->assertOk()->assertJsonPath('status', 'done');

        $this->postJson('/api/onboarding/strategy-import', ['remote_id' => 'quickstart-strategy'])
            ->assertOk()->assertJsonPath('status', 'done');

        $this->postJson('/api/onboarding/launch')
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'launch', 'status' => 'done', 'mode' => 'paper', 'running' => true, 'completed' => true]);

        $state = $this->getJson('/api/onboarding')->assertOk()->json();
        $this->assertTrue($state['completed']);
        $this->assertNull($state['next_step']);
        $this->assertTrue(collect($state['steps'])->every(fn ($s) => in_array($s['status'], ['done', 'skipped'], true)));

        $this->assertSame('paper', app(Settings::class)->mode());
        $this->assertTrue(app(Chief::class)->running());
        $this->assertDatabaseHas('ai_connections', ['provider' => 'openrouter']);
        $this->assertDatabaseHas('synced_strategies', ['remote_id' => 'quickstart-strategy']);

        $this->get('/')->assertOk();
    }

    public function test_unknown_step_is_not_found(): void
    {
        $this->signInAsOwner();

        $this->postJson('/api/onboarding/not-a-step', [])->assertNotFound();
    }
}
