<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Settings;
use App\Models\AiConnection;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private const SOURCE = 'https://fake.example.com/onboarding-repo/';

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
        config(['desk.master_password' => '']);
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            ['master-password', 'openrouter', 'exchange', 'strategy-import', 'launch'],
            ['status' => 'done'],
        )]);

        $this->get('/')->assertOk();
    }

    public function test_master_password_step_persists_without_touching_env_and_authenticates_the_session(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => 'trustno1'])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'master-password', 'status' => 'done', 'master_password_set' => true]);

        $this->assertSame('trustno1', app(Settings::class)->masterPassword());
        $this->assertSame('trustno1', Setting::find('master_password')?->value);
        $this->assertTrue(session('desk_authed'));

        // Set once, the desk token gate now enforces it on every other API call — exactly
        // like it always has for an operator-configured MASTER_PASSWORD, just persisted
        // through Settings instead of .env.
        $this->getJson('/api/onboarding')->assertUnauthorized();
        $this->getJson('/api/onboarding', ['X-Desk-Token' => 'trustno1'])->assertOk();
    }

    public function test_master_password_step_accepts_an_empty_password_for_a_trusted_local_desk(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => ''])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'done', 'master_password_set' => false]);

        $this->assertSame('', app(Settings::class)->masterPassword());
        $this->assertSame('openrouter', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_master_password_step_requires_a_password_when_the_desk_is_internet_exposed(): void
    {
        config(['desk.require_master_password' => true]);

        $this->postJson('/api/onboarding/master-password', ['password' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'A password is required because this desk is reachable from the internet.');

        $this->postJson('/api/onboarding/master-password', ['password' => str_repeat('a', 12)])
            ->assertOk()
            ->assertJson(['ok' => true, 'step' => 'master-password', 'status' => 'done', 'master_password_set' => true]);
    }

    public function test_state_reports_require_master_password_and_bootstrap_flags(): void
    {
        config(['desk.require_master_password' => true]);

        $res = $this->getJson('/api/onboarding')->assertOk()->json();
        $this->assertTrue($res['require_master_password']);
        $this->assertTrue($res['bootstrap']);

        $this->postJson('/api/onboarding/master-password', ['password' => str_repeat('a', 12)]);

        $res = $this->getJson('/api/onboarding', ['X-Desk-Token' => str_repeat('a', 12)])->assertOk()->json();
        $this->assertFalse($res['bootstrap']);
    }

    public function test_openrouter_step_rejects_out_of_order_submission(): void
    {
        $this->fakeOpenRouterKey();

        $this->postJson('/api/onboarding/openrouter', ['api_key' => self::KEY])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'next_step' => 'master-password']);
    }

    public function test_openrouter_step_stores_a_pasted_key_encrypted_the_same_way_pkce_does(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => '']);
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
        $this->postJson('/api/onboarding/master-password', ['password' => '']);
        Http::fake(['openrouter.ai/api/v1/key' => Http::response(['error' => 'invalid'], 401)]);

        $this->postJson('/api/onboarding/openrouter', ['api_key' => 'sk-or-v1-bogus'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AiConnection::count());
        $this->assertSame('openrouter', $this->getJson('/api/onboarding')->json('next_step'));
    }

    public function test_exchange_step_defaults_to_coinbase_paper_and_rejects_unknown_ids(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => '']);
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
        $this->postJson('/api/onboarding/master-password', ['password' => '']);
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
        $this->postJson('/api/onboarding/master-password', ['password' => '']);
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
        $this->postJson('/api/onboarding/launch')
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'next_step' => 'master-password']);
    }

    public function test_completing_all_five_steps_reaches_paper_trading_with_no_env_edits(): void
    {
        $this->postJson('/api/onboarding/master-password', ['password' => ''])
            ->assertOk()->assertJsonPath('status', 'done');

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

        config(['desk.master_password' => '']);
        $this->get('/')->assertOk();
    }

    public function test_unknown_step_is_not_found(): void
    {
        $this->postJson('/api/onboarding/not-a-step', [])->assertNotFound();
    }
}
