<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\HubConnection;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    private function connectHub(): void
    {
        HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => 'tok-abc',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);
    }

    /** @return array{0: StrategyPlugin, 1: StrategyPluginVersion} */
    private function pluginWithVersion(?string $hubSlug = null): array
    {
        $plugin = StrategyPlugin::create([
            'key' => 'mr-h1',
            'name' => 'MR H1',
            'description' => 'desc',
            'definition' => [
                'schema_version' => 1,
                'key' => 'mr-h1',
                'meta' => ['name' => 'MR H1', 'description' => 'desc', 'tags' => ['mr']],
            ],
            'current_version' => '1.0.0',
            'hub_slug' => $hubSlug,
        ]);

        $version = StrategyPluginVersion::create([
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.0.0',
            'definition' => $plugin->definition,
            'changelog' => null,
            'created_by' => null,
        ]);

        return [$plugin, $version];
    }

    public function test_first_publish_posts_to_strategies_and_sets_hub_slug_and_published_at(): void
    {
        $this->connectHub();
        [$plugin, $version] = $this->pluginWithVersion();

        Http::fake(['hub.test/*' => Http::response([
            'strategy' => ['id' => 1, 'slug' => 'mr-h1', 'url' => 'https://hub.test/s/mr-h1'],
            'version' => ['id' => 1, 'version' => '1.0.0'],
        ])]);

        $response = $this->postJson("/api/strategy-plugins/{$plugin->id}/publish");

        $response->assertStatus(201);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://hub.test/api/v1/strategies');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/versions'));

        $this->assertSame('mr-h1', $plugin->fresh()->hub_slug);
        $this->assertNotNull($version->fresh()->published_at);
    }

    public function test_second_publish_on_an_already_published_plugin_posts_a_new_version_not_a_new_strategy(): void
    {
        $this->connectHub();
        [$plugin, $version] = $this->pluginWithVersion(hubSlug: 'mr-h1');

        Http::fake(['hub.test/*' => Http::response(['version' => ['id' => 2, 'version' => '1.0.0']])]);

        $response = $this->postJson("/api/strategy-plugins/{$plugin->id}/publish");

        $response->assertStatus(201);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://hub.test/api/v1/strategies/mr-h1/versions');
        Http::assertNotSent(fn ($r) => $r->url() === 'https://hub.test/api/v1/strategies');

        $this->assertSame('mr-h1', $plugin->fresh()->hub_slug);
        $this->assertNotNull($version->fresh()->published_at);
    }

    public function test_publishing_with_no_hub_connection_is_401_and_the_hub_is_never_called(): void
    {
        [$plugin] = $this->pluginWithVersion();

        Http::fake();

        $response = $this->postJson("/api/strategy-plugins/{$plugin->id}/publish");

        $response->assertStatus(401);
        Http::assertNothingSent();
        $this->assertNull($plugin->fresh()->hub_slug);
    }

    public function test_publishing_a_nonexistent_version_is_404_and_the_hub_is_never_called(): void
    {
        $this->connectHub();
        [$plugin] = $this->pluginWithVersion();

        Http::fake();

        $response = $this->postJson("/api/strategy-plugins/{$plugin->id}/publish", ['version' => '9.9.9']);

        $response->assertStatus(404);
        Http::assertNothingSent();
    }
}
