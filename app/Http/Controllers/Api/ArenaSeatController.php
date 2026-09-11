<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Arena\ArenaScoreboard;
use App\Desk\StrategyRegistry;
use App\Http\Controllers\Controller;
use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The live arena: every seat runs its own strategy in paper, on its own account, against the
 * same tape as every other seat. This is the new /api/arena — the old optimizer champion-vs-
 * backtested-challengers view moved to ArenaController::show() at /api/arena/optimizer.
 */
class ArenaSeatController extends Controller
{
    /** Scoreboard: every seat, its live PnL/drawdown/win-rate/trade-count/open-positions, and its delta against the current champion. */
    public function index(ArenaScoreboard $scoreboard): JsonResponse
    {
        return response()->json([
            'seats' => $scoreboard->rows(),
            'builtin_strategies' => $scoreboard->builtinStrategies(),
        ]);
    }

    public function store(Request $request, StrategyRegistry $strategies): JsonResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:64',
            'strategy_plugin_version_id' => 'nullable|integer|exists:strategy_plugin_versions,id',
            'strategy_plugin_id' => 'nullable|integer|exists:strategy_plugins,id',
            'strategy_key' => 'nullable|string|max:32',
            'params' => 'nullable|array',
            'cash' => 'nullable|numeric|min:1',
        ]);

        $versionId = $data['strategy_plugin_version_id'] ?? null;
        $strategyKey = null;
        $params = null;

        if ($versionId === null && isset($data['strategy_plugin_id'])) {
            $plugin = StrategyPlugin::findOrFail($data['strategy_plugin_id']);
            abort_if($plugin->current_version === null, 422, 'plugin has no saved version yet');
            $version = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', $plugin->current_version)->first();
            abort_if($version === null, 422, 'current version not found');
            $versionId = $version->id;
        }

        if ($versionId === null) {
            $strategyKey = $data['strategy_key'] ?? null;
            abort_if($strategyKey === null, 422, 'one of strategy_plugin_version_id, strategy_plugin_id, strategy_key is required');
            abort_unless(array_key_exists($strategyKey, $strategies->all()) && $strategyKey !== 'json', 422, "unknown built-in strategy key: {$strategyKey}");
            $params = BacktestController::normalizeOverrides($data['params'] ?? []);
        }

        $seat = ArenaSeat::create([
            'label' => $data['label'],
            'strategy_plugin_version_id' => $versionId,
            'strategy_key' => $strategyKey,
            'params' => $params,
            'starting_cash' => $data['cash'] ?? 1000,
            'status' => 'active',
            'started_at' => now(),
        ]);

        return response()->json($seat, 201);
    }

    /** Swaps only the champion flag: unset it everywhere else, set it on this seat. Nothing about the seat itself (status, cash, positions) changes. */
    public function promote(ArenaSeat $seat): JsonResponse
    {
        DB::transaction(function () use ($seat) {
            ArenaSeat::where('is_champion', true)->where('id', '!=', $seat->id)->update(['is_champion' => false]);
            $seat->update(['is_champion' => true]);
        });

        return response()->json($seat->fresh());
    }

    /** Stops the seat from trading on the next arena cycle (ArenaRunner only picks up status=active seats). Leaves its history and champion flag alone. */
    public function retire(ArenaSeat $seat): JsonResponse
    {
        $seat->update(['status' => 'retired', 'stopped_at' => now()]);

        return response()->json($seat->fresh());
    }

    public function destroy(ArenaSeat $seat): JsonResponse
    {
        abort_unless($seat->status === 'retired', 422, 'retire the seat before deleting it');
        $seat->delete();

        return response()->json(['deleted' => true]);
    }
}
