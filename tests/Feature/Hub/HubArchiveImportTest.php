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
}
