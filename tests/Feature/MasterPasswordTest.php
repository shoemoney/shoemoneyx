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
}
