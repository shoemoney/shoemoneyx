<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class BuildStrategiesManifestScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/strategies-manifest-test-'.uniqid();
        mkdir($this->root.'/strategies', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/strategies/*') ?: [] as $f) {
            unlink($f);
        }
        @unlink($this->root.'/manifest.json');
        rmdir($this->root.'/strategies');
        rmdir($this->root);
        parent::tearDown();
    }

    private function fixture(string $name, array $definition): void
    {
        file_put_contents($this->root."/strategies/{$name}.json", json_encode($definition, JSON_PRETTY_PRINT));
    }

    private function build(): string
    {
        $script = base_path('ops/build-strategies-manifest.php');

        return (string) shell_exec('php '.escapeshellarg($script).' '.escapeshellarg($this->root).' 2>&1');
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents($this->root.'/manifest.json'), true);
    }

    public function test_it_builds_the_expected_manifest_for_two_fixtures(): void
    {
        $this->fixture('alpha', [
            'schema_version' => 1,
            'key' => 'alpha-strategy',
            'meta' => ['name' => 'Alpha Strategy', 'description' => 'Fixture A.', 'tags' => ['test'], 'timeframe' => '1h', 'assets' => ['BTC-USD']],
            'entry' => ['side' => 'long'],
        ]);
        $this->fixture('beta', [
            'schema_version' => 1,
            'key' => 'beta-strategy',
            'author' => 'shoemoney',
            'meta' => ['name' => 'Beta Strategy', 'description' => 'Fixture B.', 'tags' => ['test', 'breakout'], 'timeframe' => '4h', 'assets' => ['ETH-USD']],
            'entry' => ['side' => 'long'],
        ]);

        $output = $this->build();

        $this->assertStringContainsString('2 strategies written', $output);

        $manifest = $this->manifest();
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertCount(2, $manifest['strategies']);

        $alpha = $manifest['strategies'][0];
        $beta = $manifest['strategies'][1];

        $this->assertSame('alpha-strategy', $alpha['id']);
        $this->assertSame('1.0.0', $alpha['version']);
        $this->assertSame('strategies/alpha.json', $alpha['file']);
        $this->assertSame(hash('sha256', (string) file_get_contents($this->root.'/strategies/alpha.json')), $alpha['sha256']);
        $this->assertSame(['test'], $alpha['tags']);
        $this->assertSame('community', $alpha['author']);
        $this->assertSame(1, $alpha['min_desk_schema']);

        $this->assertSame('beta-strategy', $beta['id']);
        $this->assertSame('shoemoney', $beta['author']);
        $this->assertSame(['test', 'breakout'], $beta['tags']);
    }

    public function test_rerun_keeps_unchanged_versions_and_bumps_only_the_changed_file(): void
    {
        $this->fixture('alpha', [
            'schema_version' => 1, 'key' => 'alpha-strategy',
            'meta' => ['name' => 'Alpha Strategy', 'description' => 'Fixture A.'],
            'entry' => ['side' => 'long'],
        ]);
        $this->fixture('beta', [
            'schema_version' => 1, 'key' => 'beta-strategy',
            'meta' => ['name' => 'Beta Strategy', 'description' => 'Fixture B.'],
            'entry' => ['side' => 'long'],
        ]);
        $this->build();

        // beta changes, alpha does not.
        $this->fixture('beta', [
            'schema_version' => 1, 'key' => 'beta-strategy',
            'meta' => ['name' => 'Beta Strategy', 'description' => 'Fixture B, revised.'],
            'entry' => ['side' => 'long'],
        ]);
        $this->build();

        $manifest = $this->manifest();
        $byId = array_column($manifest['strategies'], null, 'id');

        $this->assertSame('1.0.0', $byId['alpha-strategy']['version']);
        $this->assertSame('1.0.1', $byId['beta-strategy']['version']);
    }
}
