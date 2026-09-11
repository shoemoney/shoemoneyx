<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StrategyPlugin;
use App\Models\SyncedStrategy;
use App\Strategies\Sync\StrategySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategySyncTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://fake.example.com/repo/';

    private function definition(string $key, string $name): array
    {
        return [
            'schema_version' => 1,
            'key' => $key,
            'meta' => ['name' => $name, 'description' => 'Fixture strategy.', 'tags' => ['fixture'], 'timeframe' => '1h', 'assets' => ['BTC-USD']],
            'entry' => ['side' => 'long'],
        ];
    }

    private function manifest(array $entries): void
    {
        Http::fake([
            self::SOURCE.'manifest.json' => Http::response(['schema_version' => 1, 'generated_at' => now()->toIso8601String(), 'strategies' => $entries]),
        ]);
    }

    private function fakeFile(string $path, string $content): void
    {
        Http::fake([self::SOURCE.$path => Http::response($content)]);
    }

    private function sync(): StrategySync
    {
        config()->set('strategies.source_url', self::SOURCE);

        return app(StrategySync::class);
    }

    private function entry(string $id, string $name, string $version, string $file, string $content, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'version' => $version,
            'file' => $file,
            'sha256' => hash('sha256', $content),
            'tags' => ['fixture'],
            'timeframe' => '1h',
            'assets' => ['BTC-USD'],
            'author' => 'community',
            'description' => 'Fixture strategy.',
            'min_desk_schema' => 1,
        ], $overrides);
    }

    public function test_check_reports_new_updated_and_up_to_date(): void
    {
        $newContent = json_encode($this->definition('new-strategy', 'New Strategy'));
        $freshContent = json_encode($this->definition('fresh-strategy', 'Fresh Strategy'));
        $staleContent = json_encode($this->definition('stale-strategy', 'Stale Strategy'));

        $freshPlugin = StrategyPlugin::create(['key' => 'fresh-strategy', 'name' => 'Fresh Strategy', 'definition' => [], 'current_version' => '1.0.0']);
        SyncedStrategy::create([
            'remote_id' => 'fresh-strategy', 'remote_version' => '1.0.0', 'sha256' => hash('sha256', $freshContent),
            'strategy_plugin_id' => $freshPlugin->id, 'imported_at' => now(), 'seen_at' => now()->subDay(),
        ]);

        $stalePlugin = StrategyPlugin::create(['key' => 'stale-strategy', 'name' => 'Stale Strategy', 'definition' => [], 'current_version' => '1.0.0']);
        SyncedStrategy::create([
            'remote_id' => 'stale-strategy', 'remote_version' => '1.0.0', 'sha256' => hash('sha256', 'old-bytes'),
            'strategy_plugin_id' => $stalePlugin->id, 'imported_at' => now(), 'seen_at' => now()->subDay(),
        ]);

        $this->manifest([
            $this->entry('new-strategy', 'New Strategy', '1.0.0', 'strategies/new.json', $newContent),
            $this->entry('fresh-strategy', 'Fresh Strategy', '1.0.0', 'strategies/fresh.json', $freshContent),
            $this->entry('stale-strategy', 'Stale Strategy', '1.1.0', 'strategies/stale.json', $staleContent),
        ]);

        $result = $this->sync()->check();

        $this->assertSame(['new-strategy'], array_column($result['new'], 'id'));
        $this->assertSame(['stale-strategy'], array_column($result['updated'], 'id'));
        $this->assertSame(1, $result['up_to_date']);

        $this->assertNotNull(SyncedStrategy::where('remote_id', 'new-strategy')->first());
    }

    public function test_import_creates_plugin_and_version_with_the_right_definition(): void
    {
        $definition = $this->definition('breakout-strategy', 'Breakout Strategy');
        $content = json_encode($definition);

        $this->manifest([$this->entry('breakout-strategy', 'Breakout Strategy', '1.2.0', 'strategies/breakout.json', $content)]);
        $this->fakeFile('strategies/breakout.json', $content);

        $result = $this->sync()->import('breakout-strategy');

        $this->assertTrue($result['imported']);
        $this->assertFalse($result['local_modified']);

        $plugin = StrategyPlugin::where('key', 'breakout-strategy')->firstOrFail();
        $this->assertSame($definition, $plugin->definition);
        $this->assertSame('1.2.0', $plugin->current_version);
        $this->assertSame(1, $plugin->versions()->count());
        $this->assertSame('imported 1.2.0', $plugin->versions()->first()->changelog);

        $row = SyncedStrategy::where('remote_id', 'breakout-strategy')->firstOrFail();
        $this->assertSame($plugin->id, $row->strategy_plugin_id);
        $this->assertNotNull($row->imported_at);
    }

    public function test_import_rejects_a_sha256_mismatch(): void
    {
        $content = json_encode($this->definition('tampered-strategy', 'Tampered Strategy'));

        $this->manifest([$this->entry('tampered-strategy', 'Tampered Strategy', '1.0.0', 'strategies/tampered.json', $content, ['sha256' => str_repeat('a', 64)])]);
        $this->fakeFile('strategies/tampered.json', $content);

        $result = $this->sync()->import('tampered-strategy');

        $this->assertFalse($result['imported']);
        $this->assertStringContainsString('sha256 mismatch', $result['error']);
        $this->assertSame(0, StrategyPlugin::where('key', 'tampered-strategy')->count());
    }

    public function test_updated_remote_creates_a_new_version_and_flags_local_modified(): void
    {
        // Http::fake matches the FIRST registered stub for a URL, so a three-stage
        // scenario against the same manifest/file URLs needs one Http::fake() call
        // with sequences, not three separate calls layered on top of each other.
        $v1 = json_encode($this->definition('evolving-strategy', 'Evolving Strategy'));
        $v2 = json_encode($this->definition('evolving-strategy', 'Evolving Strategy v2'));
        $v3 = json_encode($this->definition('evolving-strategy', 'Evolving Strategy v3'));

        $manifestFor = fn (string $version, string $content) => [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'strategies' => [$this->entry('evolving-strategy', 'Evolving Strategy', $version, 'strategies/evolving.json', $content)],
        ];

        config()->set('strategies.source_url', self::SOURCE);
        Http::fake([
            self::SOURCE.'manifest.json' => Http::sequence()
                ->push($manifestFor('1.0.0', $v1))
                ->push($manifestFor('1.1.0', $v2))
                ->push($manifestFor('1.2.0', $v3)),
            self::SOURCE.'strategies/evolving.json' => Http::sequence()
                ->push($v1)->push($v2)->push($v3),
        ]);
        $sync = app(StrategySync::class);

        $first = $sync->import('evolving-strategy');
        $this->assertTrue($first['imported']);
        $this->assertFalse($first['local_modified']);

        // No local edit: sync a real update, local_modified should stay false.
        $second = $sync->import('evolving-strategy');

        $this->assertTrue($second['imported']);
        $this->assertFalse($second['local_modified']);
        $plugin = StrategyPlugin::where('key', 'evolving-strategy')->firstOrFail();
        $this->assertSame('1.1.0', $plugin->current_version);
        $this->assertSame(json_decode($v2, true), $plugin->definition);
        $this->assertSame(2, $plugin->versions()->count());
        $this->assertSame('synced 1.1.0', $plugin->versions()->orderByDesc('id')->first()->changelog);

        // Operator edits the plugin locally (a manual save), then the remote moves again.
        $plugin->update(['definition' => array_merge($plugin->definition, ['params' => ['edited' => true]])]);

        $third = $sync->import('evolving-strategy');

        $this->assertTrue($third['imported']);
        $this->assertTrue($third['local_modified']);
        $plugin->refresh();
        $this->assertSame('1.2.0', $plugin->current_version);
        $this->assertSame(json_decode($v3, true), $plugin->definition);
        $this->assertSame(3, $plugin->versions()->count());
    }

    public function test_import_all_only_imports_new_strategies(): void
    {
        $newContent = json_encode($this->definition('alpha-strategy', 'Alpha'));
        $this->manifest([$this->entry('alpha-strategy', 'Alpha', '1.0.0', 'strategies/alpha.json', $newContent)]);
        $this->fakeFile('strategies/alpha.json', $newContent);

        $results = $this->sync()->importAll();

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['imported']);
        $this->assertSame(1, StrategyPlugin::where('key', 'alpha-strategy')->count());
    }
}
