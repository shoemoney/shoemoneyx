<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_api_accepts_master_password_header(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->getJson('/api/status')->assertUnauthorized();
        $this->getJson('/api/status', ['X-Desk-Token' => 'secret'])->assertOk();
        $this->getJson('/api/status?token=secret')->assertOk();
    }

    public function test_login_hands_browser_token_and_logout_revokes(): void
    {
        config(['desk.master_password' => 'secret']);

        $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee("localStorage.setItem('desk_token'", false);

        $this->post('/logout')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
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
