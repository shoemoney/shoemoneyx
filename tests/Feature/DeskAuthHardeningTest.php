<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function completeOnboarding(): void
    {
        app(Settings::class)->set('onboarding_state', ['steps' => array_fill_keys(
            OnboardingWizard::ORDER,
            ['status' => 'done'],
        )]);
    }

    public function test_wrong_token_flood_gets_429_then_the_correct_token_works_after_the_window(): void
    {
        config(['desk.master_password' => 'secret']);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/status', ['X-Desk-Token' => 'guess'.$i])->assertUnauthorized();
        }

        $this->getJson('/api/status', ['X-Desk-Token' => 'guess-again'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        // A locked-out caller learns nothing from the response, even with the right token.
        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk();
    }

    public function test_successful_token_requests_and_headerless_requests_never_fill_the_bucket(): void
    {
        config(['desk.master_password' => 'secret']);

        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/status')->assertUnauthorized();
            $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk();
        }
    }

    public function test_login_failures_share_the_header_failure_bucket(): void
    {
        config(['desk.master_password' => 'secret']);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/status', ['X-Desk-Token' => 'guess'.$i])->assertUnauthorized();
            $this->post('/login', ['password' => 'guess'.$i])->assertSessionHasErrors('password');
        }

        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertStatus(429);
        $this->post('/login', ['password' => 'secret'])->assertStatus(429);
    }

    public function test_cross_site_writes_are_refused_when_the_gate_is_off(): void
    {
        config(['desk.master_password' => '']);

        $this->postJson('/api/desk/halt', [], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        $this->postJson('/api/desk/halt', [], ['Sec-Fetch-Site' => 'same-site'])->assertForbidden();
        $this->putJson('/api/settings', [], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->deleteJson('/api/settings/mode', [], ['Origin' => 'null'])->assertForbidden();
        // Sec-Fetch-Site wins over a spoofable-looking Origin.
        $this->postJson('/api/desk/halt', [], ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'http://localhost'])->assertForbidden();
    }

    public function test_same_origin_and_headerless_writes_pass_when_the_gate_is_off(): void
    {
        config(['desk.master_password' => '']);

        $this->postJson('/api/desk/halt', [], ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->postJson('/api/desk/resume', [], ['Sec-Fetch-Site' => 'none'])->assertOk();
        $this->postJson('/api/desk/halt', [], ['Origin' => rtrim(url('/'), '/')])->assertOk();
        $this->postJson('/api/desk/resume')->assertOk();
        $this->getJson('/api/status', ['Sec-Fetch-Site' => 'cross-site'])->assertOk();
    }

    public function test_cors_gives_no_cross_origin_access_to_the_api(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));

        $this->getJson('/api/status', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_required_password_fails_closed_on_the_api_until_one_is_set(): void
    {
        config(['desk.master_password' => '', 'desk.require_master_password' => true]);

        $this->getJson('/api/status')->assertForbidden();
        $this->postJson('/api/desk/halt')->assertForbidden();
        $this->postJson('/api/onboarding/exchange', ['exchange' => 'paper'])->assertForbidden();

        $this->getJson('/api/onboarding')->assertOk();
        $this->postJson('/api/onboarding/master-password', ['password' => str_repeat('a', 12)])->assertOk();

        $this->getJson('/api/status')->assertOk();
    }

    public function test_required_password_fails_closed_on_the_web_until_one_is_set(): void
    {
        config(['desk.master_password' => '', 'desk.require_master_password' => true]);

        $this->get('/builder')->assertRedirect('/onboarding');
        $this->get('/')->assertRedirect('/onboarding');
        $this->get('/ai/openrouter/connect')->assertRedirect('/onboarding');
        $this->get('/onboarding')->assertOk();
    }

    public function test_optional_password_still_leaves_the_gate_open(): void
    {
        $this->completeOnboarding();
        config(['desk.master_password' => '', 'desk.require_master_password' => false]);

        $this->get('/builder')->assertOk();
        $this->getJson('/api/status')->assertOk();
    }
}
