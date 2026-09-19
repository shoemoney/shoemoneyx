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

    public function test_import_rejects_a_v2_definition_while_the_engine_flag_is_off_and_writes_no_plugin(): void
    {
        config(['strategies.v2_engine' => false]);
        $definition = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);

        Http::fake([
            'https://hub.test/api/v1/strategies/smx-pi/import' => Http::response(['ok' => true]),
            'https://hub.test/api/v1/strategies/smx-pi' => Http::response(['author' => ['handle' => 'shoemoney']]),
            'https://hub.test/api/v1/strategies/smx-pi/versions/1.0.0' => Http::response(['definition' => $definition]),
        ]);

        $this->postJson('/api/hub/import', ['slug' => 'smx-pi', 'version' => '1.0.0'])
            ->assertStatus(422);

        $this->assertSame(0, StrategyPlugin::count());
    }
}
