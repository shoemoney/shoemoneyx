<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\ContestEntry;
use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paper trading stays local: entering a contest creates an ordinary arena seat that trades
 * exactly like any other seat (ContestReporter, tested separately, is what later talks to the
 * hub). See docs/HUB_API.md's Contests section and HubContestController.
 */
class ContestEntryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    /** @return array{0: StrategyPlugin, 1: StrategyPluginVersion} */
    private function pluginWithVersion(?string $hubSlug): array
    {
        $plugin = StrategyPlugin::create([
            'key' => 'mr-h1',
            'name' => 'MR H1',
            'description' => 'desc',
            'definition' => ['schema_version' => 1, 'key' => 'mr-h1', 'meta' => ['name' => 'MR H1']],
            'current_version' => '1.0.0',
            'hub_slug' => $hubSlug,
        ]);

        $version = StrategyPluginVersion::create([
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.0.0',
            'definition' => $plugin->definition,
        ]);

        return [$plugin, $version];
    }

    public function test_entering_a_contest_with_an_unpublished_plugin_is_422_and_the_hub_is_never_called(): void
    {
        [$plugin, $version] = $this->pluginWithVersion(hubSlug: null);

        Http::fake();

        $response = $this->postJson('/api/hub/contests/spring-open/enter', [
            'plugin_id' => $plugin->id,
            'version' => $version->version,
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
        $this->assertDatabaseCount('contest_entries', 0);
        $this->assertDatabaseCount('arena_seats', 0);
    }

    public function test_entering_a_contest_with_a_published_plugin_creates_an_arena_seat_and_entry(): void
    {
        [$plugin, $version] = $this->pluginWithVersion(hubSlug: 'mr-h1');

        Http::fake([
            'hub.test/api/v1/contests/spring-open' => Http::response([
                'contest' => ['id' => 1, 'slug' => 'spring-open', 'starting_cash' => 2500],
                'leaderboard' => [],
            ]),
            'hub.test/api/v1/contests/spring-open/enter' => Http::response([
                'entry' => ['id' => 'e-1', 'frozen_version_id' => 9],
            ]),
        ]);

        $response = $this->postJson('/api/hub/contests/spring-open/enter', [
            'plugin_id' => $plugin->id,
            'version' => $version->version,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['entry', 'arena_seat']);

        $seat = ArenaSeat::firstOrFail();
        $this->assertSame(2500.0, (float) $seat->starting_cash);
        $this->assertSame($version->id, $seat->strategy_plugin_version_id);
        $this->assertSame('active', $seat->status);

        $entry = ContestEntry::firstOrFail();
        $this->assertSame('spring-open', $entry->contest_slug);
        $this->assertSame($plugin->id, $entry->plugin_id);
        $this->assertSame($version->id, $entry->version_id);
        $this->assertSame($seat->id, $entry->arena_seat_id);
        $this->assertSame('live', $entry->state);
    }

    public function test_withdrawing_flips_live_entries_to_withdrawn_and_retires_their_arena_seats(): void
    {
        [$plugin, $version] = $this->pluginWithVersion(hubSlug: 'mr-h1');

        $seat = ArenaSeat::create([
            'label' => 'contest:spring-open',
            'strategy_plugin_version_id' => $version->id,
            'starting_cash' => 1000,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $entry = ContestEntry::create([
            'contest_slug' => 'spring-open',
            'plugin_id' => $plugin->id,
            'version_id' => $version->id,
            'entry_id' => 'e-1',
            'arena_seat_id' => $seat->id,
            'state' => 'live',
        ]);

        Http::fake(['hub.test/api/v1/contests/spring-open/enter' => Http::response(null, 204)]);

        $response = $this->deleteJson('/api/hub/contests/spring-open/enter');

        $response->assertOk()->assertExactJson(['withdrawn' => true]);

        $entry->refresh();
        $this->assertSame('withdrawn', $entry->state);

        $seat->refresh();
        $this->assertSame('retired', $seat->status);
        $this->assertNotNull($seat->stopped_at);
    }
}
