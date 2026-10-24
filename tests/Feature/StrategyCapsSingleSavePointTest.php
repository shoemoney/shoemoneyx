<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Settings;
use App\Desk\Strategies\SchemaMigrator;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategyCapsSingleSavePointTest extends TestCase
{
    use RefreshDatabase;

    private function definition(string $pad = ''): array
    {
        return [
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'version' => 1,
            'padding' => $pad,
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
        ];
    }

    public function test_validate_for_save_rejects_an_oversize_definition(): void
    {
        $this->assertTrue(SchemaMigrator::validateForSave($this->definition('ok'))['valid']);

        $result = SchemaMigrator::validateForSave($this->definition(str_repeat('x', 70000)));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('64KB', $result['errors'][0]['message']);
    }

    public function test_the_cap_is_inclusive_at_65536_bytes(): void
    {
        $base = strlen((string) json_encode($this->definition('')));

        $this->assertTrue(SchemaMigrator::validateForSave($this->definition(str_repeat('x', 65536 - $base)))['valid']);
        $this->assertFalse(SchemaMigrator::validateForSave($this->definition(str_repeat('x', 65537 - $base)))['valid']);
    }

    public function test_restoring_an_oversize_old_version_is_refused(): void
    {
        $plugin = StrategyPlugin::create(['key' => 'rsi-dip', 'name' => 'RSI Dip', 'definition' => $this->definition(), 'current_version' => '1.0.0']);
        $plugin->versions()->create(['version' => '1.0.0', 'definition' => $this->definition(str_repeat('x', 70000)), 'changelog' => null]);

        $this->postJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0/restore")->assertStatus(422);

        $this->assertSame('1.0.0', $plugin->fresh()->current_version);
        $this->assertSame(1, $plugin->versions()->count());
    }

    public function test_worldmonitor_base_url_must_be_a_string_on_write(): void
    {
        $this->putJson('/api/settings', ['key' => 'worldmonitor.base_url', 'value' => ['x']])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'worldmonitor', 'value' => ['base_url' => ['x']]])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'worldmonitor.base_url.deep', 'value' => 'x'])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'worldmonitor.Base_URL', 'value' => ['x']])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'worldmonitor.BASE_URL.deep', 'value' => 'x'])->assertStatus(422);
        $this->putJson('/api/settings', ['key' => 'worldmonitor.base_url', 'value' => 'http://127.0.0.1:3000'])->assertOk();
    }

    public function test_health_survives_a_non_string_worldmonitor_setting_already_stored(): void
    {
        app(Settings::class)->set('worldmonitor.base_url', ['x']);

        $health = app(Chief::class)->health();

        $this->assertArrayNotHasKey('worldmonitor', $health['checks']);
    }

    public function test_worldmonitor_request_is_pinned_to_the_validated_addresses(): void
    {
        $target = Chief::worldMonitorTarget('http://localhost:3000');

        $this->assertNotNull($target);
        $pin = $target['curl'][CURLOPT_RESOLVE][0];
        $this->assertStringStartsWith('localhost:3000:', $pin);
        foreach ($target['ips'] as $ip) {
            $this->assertStringContainsString(str_contains($ip, ':') ? "[{$ip}]" : $ip, $pin);
        }
        $this->assertStringStartsWith('localhost:443:', Chief::worldMonitorTarget('https://localhost')['curl'][CURLOPT_RESOLVE][0]);
        $this->assertSame([], Chief::worldMonitorTarget('http://127.0.0.1:3000')['curl'], 'IP literals need no pin');
    }

    public function test_health_probe_still_reaches_worldmonitor_through_the_pin(): void
    {
        app(Settings::class)->set('worldmonitor.base_url', 'http://localhost:3000');
        $resolve = null;
        Http::fake(function ($request, array $options) use (&$resolve) {
            $resolve = $options['curl'][CURLOPT_RESOLVE][0] ?? null;

            return Http::response('{}', 200);
        });

        $this->assertTrue(app(Chief::class)->health()['checks']['worldmonitor']);
        $this->assertStringStartsWith('localhost:3000:', (string) $resolve);
    }
}
