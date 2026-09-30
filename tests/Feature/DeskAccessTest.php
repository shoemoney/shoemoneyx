<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeskAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::preventStrayRequests();
    }

    public function test_private_desk_can_boot_in_production_without_a_shared_secret(): void
    {
        $this->assertNull(config('desk.api_token'));
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('environment')->with('production')->andReturn(true);
        $app->shouldReceive('runningInConsole')->andReturn(false);
        Log::spy();

        (new AppServiceProvider($app))->boot();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_all_page_data_is_available_to_the_owner_and_ignores_the_retired_api_token(): void
    {
        // Old deployments may retain this setting or scripts may still send it in the query string.
        // Neither is an access credential anymore.
        config(['desk.api_token' => 'retired-setting']);

        foreach ([['', []], ['?token=outdated', []]] as [$query, $headers]) {
            foreach (['settings', 'products', 'backtests', 'runs', 'candidates', 'fills',
                'optimizer/champions', 'optimizer/rounds', 'optimizer/candidates?coin=BTC-USD',
                'udf/config', 'udf/time', 'udf/search'] as $endpoint) {
                $uri = '/api/'.$endpoint.(str_contains($endpoint, '?') ? str_replace('?', '&', $query) : $query);
                $response = $this->getJson($uri, $headers);
                $this->assertSame(200, $response->status(), $uri);
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_retired_api_token_value_is_rejected_as_a_credential(): void
    {
        config(['desk.api_token' => 'retired-setting']);
        $this->getJson('/api/settings', ['X-Desk-Token' => 'retired-setting'])->assertUnauthorized();
        $this->getJson('/api/settings', ['X-Desk-Token' => 'outdated'])->assertUnauthorized();
    }

    public function test_settings_changes_and_reset_work_without_a_shared_secret(): void
    {
        config(['desk.api_token' => 'retired-setting']);
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0.04'])->assertOk();
        $this->assertSame(0.04, $this->getJson('/api/settings')->json('overrides')['size.kelly_cap_pct']);
        $this->deleteJson('/api/settings/size.kelly_cap_pct?token=outdated')->assertOk();
        // Only the owner's password row remains.
        $this->assertDatabaseCount('settings', 1);
        Http::assertNothingSent();
    }

    public function test_operational_controls_use_network_access_without_token_checks(): void
    {
        config(['desk.api_token' => 'retired-setting']);
        $chief = Mockery::mock(Chief::class);
        $chief->shouldReceive('halt')->once()->with('dashboard');
        $chief->shouldReceive('resume')->once();
        $chief->shouldReceive('setRunning')->once()->with(true);
        $chief->shouldReceive('setRunning')->once()->with(false);
        $this->app->instance(Chief::class, $chief);

        foreach (['halt', 'resume', 'start', 'stop'] as $action) {
            $this->postJson('/api/desk/'.$action)->assertOk()->assertJsonPath('ok', true);
        }

        Http::assertNothingSent();
    }

    public function test_removing_the_token_does_not_remove_request_validation(): void
    {
        $this->putJson('/api/settings', ['key' => 'mode', 'value' => 'invalid'])->assertUnprocessable();
        $this->putJson('/api/settings', [])->assertUnprocessable();
        $this->postJson('/api/backtests', [])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_live_execution_still_requires_explicit_confirmation(): void
    {
        config(['desk.mode' => 'live', 'desk.live_confirm' => 'no']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DESK_LIVE_CONFIRM=yes');

        try {
            app(Desk::class)->executorForMode('live');
        } finally {
            Http::assertNothingSent();
        }
    }
}
