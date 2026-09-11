<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    /** A full, schema-valid `schema_version: 1` definition (shape per docs/STRATEGY_SCHEMA.md). */
    private function validDefinition(string $key = 'mean-reversion-h1'): array
    {
        return [
            'schema_version' => 1,
            'key' => $key,
            'base' => 'custom',
            'meta' => [
                'name' => 'Mean Reversion H1',
                'description' => 'Buys H1-oversold dips.',
                'tags' => ['mean-reversion', 'h1'],
                'timeframe' => '1h',
                'assets' => ['BTC-USD', 'ETH-USD'],
            ],
            'params' => [
                'rsi_entry' => ['type' => 'number', 'default' => 35, 'min' => 10, 'max' => 50, 'step' => 1],
            ],
            'setup' => ['rules' => []],
            'trigger' => ['max_candidates' => 5, 'rules' => []],
            'entry' => [
                'side' => 'long',
                'sizing' => ['kelly_fraction' => 0.25, 'max_pct_book' => 6.0],
                'confirm' => [],
            ],
            'management' => ['adds' => [], 'trailing' => null, 'partials' => []],
            'exit' => [
                'stop' => ['rules' => []],
                'take_profit' => ['rules' => []],
                'time_stop' => ['hours' => 48],
            ],
            'risk' => ['max_positions' => 3, 'daily_loss_cap_pct' => 5, 'leverage_cap' => 1],
        ];
    }

    private function fakeImport(string $slug, string $version, array $definition, string $author = 'shoemoney'): void
    {
        Http::fake([
            "hub.test/api/v1/strategies/{$slug}/import" => Http::response(['ok' => true]),
            "hub.test/api/v1/strategies/{$slug}/versions/{$version}" => Http::response(['version' => $version, 'definition' => $definition]),
            "hub.test/api/v1/strategies/{$slug}" => Http::response([
                'strategy' => ['id' => 1, 'slug' => $slug],
                'author' => ['handle' => $author],
                'current_version' => $version,
                'versions' => [],
                'stats' => ['stars' => 0, 'imports' => 1, 'comments' => 0],
            ]),
        ]);
    }

    public function test_import_creates_a_local_plugin_at_the_imported_version(): void
    {
        $definition = $this->validDefinition();
        $this->fakeImport('mean-reversion-h1', '1.0.0', $definition);

        $response = $this->postJson('/api/hub/import', ['slug' => 'mean-reversion-h1', 'version' => '1.0.0']);

        $response->assertStatus(201);

        $plugin = StrategyPlugin::where('hub_slug', 'mean-reversion-h1')->firstOrFail();
        $this->assertSame('1.0.0', $plugin->current_version);

        $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', '1.0.0')->firstOrFail();
        $this->assertSame('imported from hub shoemoney/mean-reversion-h1', $version->changelog);
    }

    public function test_importing_a_newer_version_reuses_the_same_local_plugin(): void
    {
        $this->fakeImport('mean-reversion-h1', '1.0.0', $this->validDefinition());
        $this->postJson('/api/hub/import', ['slug' => 'mean-reversion-h1', 'version' => '1.0.0'])->assertStatus(201);

        $plugin = StrategyPlugin::where('hub_slug', 'mean-reversion-h1')->firstOrFail();

        $definitionV2 = $this->validDefinition();
        $definitionV2['meta']['description'] = 'Updated for v1.1.0.';
        $this->fakeImport('mean-reversion-h1', '1.1.0', $definitionV2);
        $this->postJson('/api/hub/import', ['slug' => 'mean-reversion-h1', 'version' => '1.1.0'])->assertStatus(201);

        $this->assertSame(1, StrategyPlugin::where('hub_slug', 'mean-reversion-h1')->count());
        $this->assertSame($plugin->id, StrategyPlugin::where('hub_slug', 'mean-reversion-h1')->firstOrFail()->id);
        $this->assertSame('1.1.0', $plugin->fresh()->current_version);

        $this->assertSame(2, StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->count());
        $this->assertDatabaseHas('strategy_plugin_versions', [
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.1.0',
            'changelog' => 'imported from hub shoemoney/mean-reversion-h1',
        ]);
    }

    public function test_import_does_not_touch_an_unrelated_local_plugin_with_a_colliding_key(): void
    {
        $unrelated = StrategyPlugin::create([
            'key' => 'mean-reversion-h1',
            'name' => 'My Own Local Strategy',
            'description' => 'not from the hub',
            'definition' => ['schema_version' => 1, 'key' => 'mean-reversion-h1', 'meta' => ['name' => 'My Own Local Strategy']],
        ]);

        $this->fakeImport('mean-reversion-h1', '1.0.0', $this->validDefinition());

        $response = $this->postJson('/api/hub/import', ['slug' => 'mean-reversion-h1', 'version' => '1.0.0']);
        $response->assertStatus(201);

        $unrelated->refresh();
        $this->assertNull($unrelated->hub_slug);
        $this->assertSame('My Own Local Strategy', $unrelated->name);
        $this->assertSame('mean-reversion-h1', $unrelated->key);

        $imported = StrategyPlugin::where('hub_slug', 'mean-reversion-h1')->firstOrFail();
        $this->assertSame('mean-reversion-h1-hub', $imported->key);
        $this->assertNotSame($unrelated->id, $imported->id);

        $this->assertSame(2, StrategyPlugin::count());
    }

    public function test_import_with_an_invalid_definition_is_422_and_creates_nothing(): void
    {
        $invalid = $this->validDefinition();
        unset($invalid['meta']['name']); // meta.name is required by StrategySchemaValidator

        $this->fakeImport('mean-reversion-h1', '1.0.0', $invalid);

        $response = $this->postJson('/api/hub/import', ['slug' => 'mean-reversion-h1', 'version' => '1.0.0']);

        $response->assertStatus(422);
        $response->assertJsonPath('valid', false);

        $this->assertDatabaseCount('strategy_plugins', 0);
        $this->assertDatabaseCount('strategy_plugin_versions', 0);
    }
}
