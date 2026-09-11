<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Hub\ContestEntry;
use App\Hub\HubClient;
use App\Hub\HubException;
use App\Http\Controllers\Controller;
use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin pass-through to the hub's /contests routes (see docs/HUB_API.md) for browsing, plus
 * entering: paper trading stays local, so entering a contest creates an ordinary arena seat
 * (started at the contest's starting_cash) that trades exactly like any other seat — the
 * scheduled `hub:report-contests` command (App\Hub\ContestReporter) is what actually talks to
 * the hub after that, pushing that seat's fills and a throttled equity snapshot.
 */
class HubContestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => 'nullable|in:upcoming,live,settled',
        ]);

        return response()->json(app(HubClient::class)->contests($data['state'] ?? null));
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json(app(HubClient::class)->contest($slug));
    }

    public function enter(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'plugin_id' => 'required|integer|exists:strategy_plugins,id',
            'version' => 'required|string',
        ]);

        $plugin = StrategyPlugin::findOrFail($data['plugin_id']);

        if ($plugin->hub_slug === null) {
            return response()->json(['error' => 'Publish this strategy to the hub before entering a contest.'], 422);
        }

        $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
            ->where('version', $data['version'])
            ->firstOrFail();

        try {
            $contest = app(HubClient::class)->contest($slug);
            $response = app(HubClient::class)->enter($slug, $plugin->hub_slug, $data['version']);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage()], $e->code === 'conflict' ? 409 : 502);
        }

        $seat = ArenaSeat::create([
            'label' => "contest:{$slug}",
            'strategy_plugin_version_id' => $version->id,
            'starting_cash' => $contest['contest']['starting_cash'] ?? 1000,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $entry = ContestEntry::create([
            'contest_slug' => $slug,
            'plugin_id' => $plugin->id,
            'version_id' => $version->id,
            'entry_id' => $response['entry']['id'] ?? null,
            'arena_seat_id' => $seat->id,
            'state' => 'live',
        ]);

        return response()->json(['entry' => $entry, 'arena_seat' => $seat], 201);
    }

    public function withdraw(string $slug): JsonResponse
    {
        try {
            app(HubClient::class)->withdraw($slug);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage()], $e->code === 'conflict' ? 409 : 502);
        }

        $entries = ContestEntry::where('contest_slug', $slug)->where('state', 'live')->get();
        foreach ($entries as $entry) {
            if ($entry->arena_seat_id !== null) {
                ArenaSeat::whereKey($entry->arena_seat_id)->update(['status' => 'retired', 'stopped_at' => now()]);
            }
        }
        ContestEntry::whereIn('id', $entries->pluck('id'))->update(['state' => 'withdrawn']);

        return response()->json(['withdrawn' => true]);
    }
}
