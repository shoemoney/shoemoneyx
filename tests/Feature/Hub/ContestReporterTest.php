<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\ContestEntry;
use App\Hub\ContestReporter;
use App\Models\ArenaSeat;
use App\Models\Fill;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ContestReporter never sends orders — it reports a seat's own already-executed (locally
 * paper-traded) fills and a throttled equity snapshot to the hub. No HTTP route involved;
 * ContestReporter is exercised directly.
 */
class ContestReporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    private function fillsUrl(string $slug = 'spring-open'): string
    {
        return "https://hub.test/api/v1/contests/{$slug}/fills";
    }

    private function snapshotsUrl(string $slug = 'spring-open'): string
    {
        return "https://hub.test/api/v1/contests/{$slug}/snapshots";
    }

    private static int $pluginSeq = 0;

    /** @return array{0: StrategyPlugin, 1: StrategyPluginVersion} */
    private function pluginWithVersion(): array
    {
        $key = 'mr-h1-'.(++self::$pluginSeq);

        $plugin = StrategyPlugin::create([
            'key' => $key,
            'name' => 'MR H1',
            'description' => 'desc',
            'definition' => ['schema_version' => 1, 'key' => $key, 'meta' => ['name' => 'MR H1']],
            'current_version' => '1.0.0',
            'hub_slug' => $key,
        ]);

        $version = StrategyPluginVersion::create([
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.0.0',
            'definition' => $plugin->definition,
        ]);

        return [$plugin, $version];
    }

    private function liveEntry(ArenaSeat $seat, ?int $lastReportedFillId = null, ?Carbon $lastSnapshotAt = null): ContestEntry
    {
        [$plugin, $version] = $this->pluginWithVersion();

        return ContestEntry::create([
            'contest_slug' => 'spring-open',
            'plugin_id' => $plugin->id,
            'version_id' => $version->id,
            'entry_id' => 'entry-1',
            'state' => 'live',
            'arena_seat_id' => $seat->id,
            'last_reported_fill_id' => $lastReportedFillId,
            'last_snapshot_at' => $lastSnapshotAt,
        ]);
    }

    private function filledFill(ArenaSeat $seat, array $overrides = []): Fill
    {
        return Fill::create(array_merge([
            'arena_seat_id' => $seat->id,
            'position_id' => null,
            'mode' => 'paper',
            'product_id' => 'BTC-USD',
            'side' => 'BUY',
            'kind' => 'entry',
            'requested_usd' => 100,
            'filled_usd' => 100,
            'filled_qty' => 0.5,
            'decision_price' => 20000,
            'fill_price' => 20000,
            'fee_usd' => 6,
            'status' => 'filled',
        ], $overrides));
    }

    public function test_report_pushes_unsent_fills_and_a_snapshot_then_advances_both_bookmarks(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01T12:00:00Z'));

        $seat = ArenaSeat::create(['label' => 'contest:spring-open', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $entry = $this->liveEntry($seat);

        $fill1 = $this->filledFill($seat, ['side' => 'BUY', 'filled_qty' => 0.5, 'fill_price' => 20000, 'fee_usd' => 6, 'created_at' => now()->subMinutes(2)]);
        $fill2 = $this->filledFill($seat, ['side' => 'SELL', 'filled_qty' => 0.5, 'fill_price' => 20500, 'fee_usd' => 6.15, 'created_at' => now()->subMinute()]);

        Http::fake([
            $this->fillsUrl() => Http::response(['accepted' => 2, 'duplicates' => 0]),
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry);

        Http::assertSent(function ($request) use ($seat, $fill1, $fill2) {
            return $request->url() === $this->fillsUrl()
                && $request->method() === 'POST'
                && $request->data() === ['fills' => [
                    [
                        'client_id' => "seat{$seat->id}-fill{$fill1->id}",
                        'product_id' => 'BTC-USD',
                        'side' => 'buy',
                        'size' => 0.5,
                        'price' => 20000.0,
                        'fee_usd' => 6.0,
                        'at' => $fill1->created_at->toIso8601String(),
                    ],
                    [
                        'client_id' => "seat{$seat->id}-fill{$fill2->id}",
                        'product_id' => 'BTC-USD',
                        'side' => 'sell',
                        'size' => 0.5,
                        'price' => 20500.0,
                        'fee_usd' => 6.15,
                        'at' => $fill2->created_at->toIso8601String(),
                    ],
                ]];
        });

        Http::assertSent(fn ($request) => $request->url() === $this->snapshotsUrl()
            && $request->method() === 'POST'
            && $request->data() === [
                'equity' => 1000.0,
                'cash' => 1000.0,
                'open_positions' => [],
                'at' => now()->toIso8601String(),
            ]);

        $entry->refresh();
        $this->assertSame($fill2->id, $entry->last_reported_fill_id);
        $this->assertNotNull($entry->last_snapshot_at);
        $this->assertTrue(now()->equalTo($entry->last_snapshot_at));
    }

    public function test_a_second_report_only_resends_fills_added_since_the_bookmark(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01T12:00:00Z'));

        $seat = ArenaSeat::create(['label' => 'contest:spring-open', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $entry = $this->liveEntry($seat);

        $fill1 = $this->filledFill($seat, ['created_at' => now()->subMinutes(2)]);

        Http::fake([
            $this->fillsUrl() => Http::response(['accepted' => 1, 'duplicates' => 0]),
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry);
        $entry->refresh();
        $this->assertSame($fill1->id, $entry->last_reported_fill_id);

        $fill2 = $this->filledFill($seat, ['side' => 'SELL', 'filled_qty' => 0.25, 'fill_price' => 21000, 'fee_usd' => 3.15, 'created_at' => now()]);

        Http::fake([
            $this->fillsUrl() => Http::response(['accepted' => 1, 'duplicates' => 0]),
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry);

        Http::assertSent(function ($request) use ($seat, $fill2) {
            return $request->url() === $this->fillsUrl()
                && $request->data() === ['fills' => [
                    [
                        'client_id' => "seat{$seat->id}-fill{$fill2->id}",
                        'product_id' => 'BTC-USD',
                        'side' => 'sell',
                        'size' => 0.25,
                        'price' => 21000.0,
                        'fee_usd' => 3.15,
                        'at' => $fill2->created_at->toIso8601String(),
                    ],
                ]];
        });

        $entry->refresh();
        $this->assertSame($fill2->id, $entry->last_reported_fill_id);
    }

    public function test_snapshot_is_throttled_to_once_per_sixty_seconds_but_fills_still_push(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01T12:00:00Z'));

        $seat = ArenaSeat::create(['label' => 'contest:spring-open', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $entry = $this->liveEntry($seat);
        $this->filledFill($seat, ['created_at' => now()]);

        Http::fake([
            $this->fillsUrl() => Http::response(['accepted' => 1, 'duplicates' => 0]),
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry);
        Http::assertSentCount(2); // fills + the first-ever snapshot

        $secondFill = $this->filledFill($seat, ['side' => 'SELL', 'created_at' => now()]);

        Http::fake([
            $this->fillsUrl() => Http::response(['accepted' => 1, 'duplicates' => 0]),
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry->refresh());
        Http::assertSentCount(1); // only the new fill; snapshot throttled (<60s since the last one)
        Http::assertSent(fn ($request) => $request->url() === $this->fillsUrl());
        Http::assertNotSent(fn ($request) => $request->url() === $this->snapshotsUrl());

        // Backdate the bookmark past the 60s throttle window and report again with no new fills.
        $entry->update(['last_snapshot_at' => now()->subSeconds(61)]);

        Http::fake([
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry->refresh());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $this->snapshotsUrl());
    }

    public function test_rejected_fills_are_never_reported(): void
    {
        $seat = ArenaSeat::create(['label' => 'contest:spring-open', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $entry = $this->liveEntry($seat);

        $this->filledFill($seat, ['status' => 'rejected']);

        Http::fake([
            $this->snapshotsUrl() => Http::response(['ok' => true]),
        ]);

        app(ContestReporter::class)->report($entry);

        Http::assertNotSent(fn ($request) => $request->url() === $this->fillsUrl());
        $this->assertNull($entry->fresh()->last_reported_fill_id);
    }

    public function test_a_withdrawn_or_seatless_entry_reports_nothing(): void
    {
        $seat = ArenaSeat::create(['label' => 'contest:spring-open', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);

        $withdrawn = $this->liveEntry($seat);
        $withdrawn->update(['state' => 'withdrawn']);
        $this->filledFill($seat);

        [$plugin, $version] = $this->pluginWithVersion();
        $seatless = ContestEntry::create([
            'contest_slug' => 'spring-open',
            'plugin_id' => $plugin->id,
            'version_id' => $version->id,
            'entry_id' => 'entry-2',
            'state' => 'live',
            'arena_seat_id' => null,
        ]);

        Http::fake();

        app(ContestReporter::class)->report($withdrawn->fresh());
        app(ContestReporter::class)->report($seatless);

        Http::assertNothingSent();
    }
}
