<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyPluginVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function definition(string $name = 'RSI Dip'): array
    {
        return [
            'key' => 'rsi-dip',
            'name' => $name,
            'version' => 1,
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
        ];
    }

    public function test_create_writes_1_0_0(): void
    {
        $res = $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])
            ->assertCreated()->json();

        $this->assertSame('1.0.0', $res['plugin']['current_version']);
        $this->assertSame(1, StrategyPluginVersion::where('strategy_plugin_id', $res['plugin']['id'])->count());
        $version = StrategyPluginVersion::where('strategy_plugin_id', $res['plugin']['id'])->sole();
        $this->assertSame('1.0.0', $version->version);
        $this->assertSame('RSI Dip', $version->definition['name']);
    }

    public function test_update_with_bump_minor_writes_1_1_0(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();

        $res = $this->postJson('/api/strategy-plugins', [
            'definition' => $this->definition('RSI Dip v2'),
            'bump' => 'minor',
            'changelog' => 'widen the band',
        ])->assertCreated()->json();

        $this->assertSame('1.1.0', $res['plugin']['current_version']);
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();
        $this->assertSame(2, $plugin->versions()->count());
        $latest = $plugin->versions()->where('version', '1.1.0')->sole();
        $this->assertSame('widen the band', $latest->changelog);
    }

    public function test_default_bump_is_patch(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $res = $this->postJson('/api/strategy-plugins', ['definition' => $this->definition('v2')])->assertCreated()->json();

        $this->assertSame('1.0.1', $res['plugin']['current_version']);
    }

    public function test_versions_index_lists_newest_first(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition('v2'), 'bump' => 'minor'])->assertCreated();

        $data = $this->getJson("/api/strategy-plugins/{$plugin->id}/versions")->assertOk()->json('data');

        $this->assertSame(['1.1.0', '1.0.0'], array_column($data, 'version'));
    }

    public function test_show_one_version(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();

        $this->getJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0")
            ->assertOk()
            ->assertJsonPath('version', '1.0.0')
            ->assertJsonPath('definition.name', 'RSI Dip');
    }

    public function test_restore_creates_a_new_version_rather_than_rewriting_history(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition('v2'), 'bump' => 'minor'])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();
        $this->assertSame('1.1.0', $plugin->current_version);

        $res = $this->postJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0/restore")
            ->assertCreated()->json();

        $this->assertSame('1.1.1', $res['plugin']['current_version']);
        $this->assertSame('RSI Dip', $res['plugin']['definition']['name']);
        $this->assertSame(3, $plugin->versions()->count());
        // The original 1.0.0 row is untouched.
        $original = $plugin->versions()->where('version', '1.0.0')->sole();
        $this->assertSame('RSI Dip', $original->definition['name']);
    }

    public function test_diff_reports_added_removed_and_changed_paths(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();

        $def2 = $this->definition('RSI Dip v2');
        $def2['scan']['max_candidates'] = 5;
        $this->postJson('/api/strategy-plugins', ['definition' => $def2, 'bump' => 'minor'])->assertCreated();

        $diff = $this->getJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0/diff/1.1.0")
            ->assertOk()->json('diff');

        $this->assertContains('name', array_column($diff['changed'], 'path'));
        $this->assertContains('scan.max_candidates', array_column($diff['added'], 'path'));
    }

    public function test_versions_are_read_only(): void
    {
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition()])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();
        $version = $plugin->versions()->sole();

        $this->putJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0", ['definition' => []])->assertStatus(405);
        $this->deleteJson("/api/strategy-plugins/{$plugin->id}/versions/1.0.0")->assertStatus(405);
        $this->assertNotNull(StrategyPluginVersion::find($version->id));
    }
}
