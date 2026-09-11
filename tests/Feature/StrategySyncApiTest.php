<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategySyncApiTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://fake.example.com/api-repo/';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('strategies.source_url', self::SOURCE);
    }

    private function definition(string $key): array
    {
        return [
            'schema_version' => 1,
            'key' => $key,
            'meta' => ['name' => ucfirst($key), 'description' => 'Fixture.'],
            'entry' => ['side' => 'long'],
        ];
    }

    public function test_status_and_check_report_the_manifest_over_http(): void
    {
        $content = json_encode($this->definition('api-fixture'));
        Http::fake([
            self::SOURCE.'manifest.json' => Http::response(['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'strategies' => [[
                'id' => 'api-fixture', 'name' => 'Api Fixture', 'version' => '1.0.0', 'file' => 'strategies/api-fixture.json',
                'sha256' => hash('sha256', $content), 'tags' => [], 'timeframe' => '1h', 'assets' => [], 'author' => 'community',
                'description' => '', 'min_desk_schema' => 1,
            ]]]),
        ]);

        $this->getJson('/api/strategies/sync/status')->assertOk()->assertJson(['up_to_date' => 0])
            ->assertJsonCount(1, 'new');

        $this->postJson('/api/strategies/sync/check')->assertOk()->assertJsonCount(1, 'new');
    }

    public function test_import_rejects_an_unknown_remote_id(): void
    {
        Http::fake([
            self::SOURCE.'manifest.json' => Http::response(['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'strategies' => []]),
        ]);

        $this->postJson('/api/strategies/sync/import', ['remote_id' => 'no-such-strategy'])
            ->assertStatus(422)->assertJson(['imported' => false]);
    }

    public function test_import_all_imports_every_new_strategy_over_http(): void
    {
        $content = json_encode($this->definition('api-import'));
        $manifest = ['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'strategies' => [[
            'id' => 'api-import', 'name' => 'Api Import', 'version' => '1.0.0', 'file' => 'strategies/api-import.json',
            'sha256' => hash('sha256', $content), 'tags' => [], 'timeframe' => '1h', 'assets' => [], 'author' => 'community',
            'description' => '', 'min_desk_schema' => 1,
        ]]];
        Http::fake([
            self::SOURCE.'manifest.json' => Http::response($manifest),
            self::SOURCE.'strategies/api-import.json' => Http::response($content),
        ]);

        $this->postJson('/api/strategies/sync/import-all')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.imported', true);
    }
}
