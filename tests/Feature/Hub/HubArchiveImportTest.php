<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubArchiveImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    public function test_import_accepts_a_valid_v2_definition_and_saves_it_as_the_plugins_own_shape(): void
    {
        $definition = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);

        Http::fake([
            'https://hub.test/api/v1/strategies/smx-pi/import' => Http::response(['ok' => true]),
            'https://hub.test/api/v1/strategies/smx-pi' => Http::response(['author' => ['handle' => 'shoemoney']]),
            'https://hub.test/api/v1/strategies/smx-pi/versions/1.0.0' => Http::response(['definition' => $definition]),
        ]);

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])
            ->assertStatus(201);

        $plugin = StrategyPlugin::sole();
        $this->assertSame(2, $plugin->definition['schema_version']);
        $this->assertSame('smx-pi', $plugin->hub_slug);
    }

    private array $hubDefinition = [];

    private function fakeHub(string $slug, string $version, array $definition): void
    {
        // Http::fake stubs stack and the first match wins, so serve the definition from a property.
        $this->hubDefinition = $definition;
        Http::fake([
            "https://hub.test/api/v1/strategies/{$slug}/import" => Http::response(['ok' => true]),
            "https://hub.test/api/v1/strategies/{$slug}" => Http::response(['author' => ['handle' => 'shoemoney']]),
            "https://hub.test/api/v1/strategies/{$slug}/versions/{$version}" => fn () => Http::response(['definition' => $this->hubDefinition]),
        ]);
    }

    private function definition(): array
    {
        return json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
    }

    public function test_version_is_lowercased_so_case_variants_are_one_version(): void
    {
        $definition = $this->definition();
        $this->fakeHub('smx-pi', '1.0.0-rc1', $definition);

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0-RC1'])->assertStatus(201);
        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0-rc1'])->assertStatus(201);

        $plugin = StrategyPlugin::sole();
        $this->assertSame('1.0.0-rc1', $plugin->current_version);
        $this->assertSame(['1.0.0-rc1'], $plugin->versions()->pluck('version')->all());
    }

    public function test_reimporting_a_pinned_version_with_a_different_definition_is_refused(): void
    {
        $definition = $this->definition();
        $this->fakeHub('smx-pi', '1.0.0', $definition);
        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])->assertStatus(201);
        $pluginDefinition = StrategyPlugin::sole()->definition;
        $versionDefinition = StrategyPlugin::sole()->versions()->sole()->definition;

        $changed = $definition;
        $changed['meta']['name'] = 'Tampered';
        $this->fakeHub('smx-pi', '1.0.0', $changed);

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])->assertStatus(409);
        $plugin = StrategyPlugin::sole();
        $this->assertNotSame('Tampered', $plugin->name);
        $this->assertSame($pluginDefinition, $plugin->definition);
        $this->assertSame(1, $plugin->versions()->count());
        $this->assertSame($versionDefinition, $plugin->versions()->sole()->definition);
    }

    public function test_reimporting_the_same_definition_with_reordered_object_members_is_accepted(): void
    {
        $definition = $this->definition();
        $this->fakeHub('smx-pi', '1.0.0', $definition);
        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])->assertStatus(201);

        $this->fakeHub('smx-pi', '1.0.0', array_reverse($definition, true));

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])->assertStatus(201);
        $this->assertSame(1, StrategyPlugin::sole()->versions()->count());
    }

    public function test_version_longer_than_the_32_char_column_is_rejected_before_any_side_effect(): void
    {
        Http::fake();
        $version = '1.0.0-'.str_repeat('a', 27);
        $this->assertSame(33, strlen($version));

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => $version])->assertStatus(422);
        Http::assertNothingSent();

        $this->getJson('/api/hub/strategies/smx-pi/versions/'.$version)->assertNotFound();
    }

    public function test_generated_plugin_key_never_exceeds_the_64_char_column(): void
    {
        $slug = 'a'.str_repeat('b', 62);
        StrategyPlugin::create(['key' => $slug, 'name' => 'taken', 'definition' => ['x' => 1]]);
        StrategyPlugin::create(['key' => substr($slug, 0, 60).'-hub', 'name' => 'taken2', 'definition' => ['x' => 1]]);
        $this->fakeHub($slug, '1.0.0', $this->definition());

        $this->postJson('/api/hub/import', ['slug' => $slug, 'version' => '1.0.0'])->assertStatus(201);

        $key = StrategyPlugin::where('hub_slug', $slug)->value('key');
        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringEndsWith('-hub-2', $key);
    }
}
