<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use App\Support\JsonDiff;
use App\Support\SemVer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read (+ restore) access to a strategy plugin's immutable version history.
 * Versions themselves are write-once: StrategyPluginController::store()
 * is the only place a new row is inserted, plus restore() below, which
 * always creates a NEW version rather than touching an old one.
 */
class StrategyPluginVersionController extends Controller
{
    public function index(StrategyPlugin $plugin): JsonResponse
    {
        return response()->json([
            'data' => $plugin->versions()->orderByDesc('id')->get(['id', 'version', 'changelog', 'created_by', 'created_at']),
        ]);
    }

    public function show(StrategyPlugin $plugin, string $version): JsonResponse
    {
        return response()->json($this->findVersion($plugin, $version));
    }

    /** Creates a NEW version carrying the old definition forward — never rewinds a row in place. */
    public function restore(Request $request, StrategyPlugin $plugin, string $version): JsonResponse
    {
        $data = $request->validate([
            'bump' => 'nullable|string|in:major,minor,patch',
            'changelog' => 'nullable|string|max:2000',
        ]);

        $source = $this->findVersion($plugin, $version);
        $def = $source->definition;
        $next = SemVer::bump($plugin->current_version ?? '1.0.0', $data['bump'] ?? 'patch');

        $plugin->update([
            'name' => $def['meta']['name'] ?? $def['name'] ?? $plugin->name,
            'description' => $def['meta']['description'] ?? $def['description'] ?? $plugin->description,
            'definition' => $def,
            'current_version' => $next,
        ]);

        $new = $plugin->versions()->create([
            'version' => $next,
            'definition' => $def,
            'changelog' => $data['changelog'] ?? "restored from v{$version}",
            'created_by' => $request->user()?->email,
        ]);

        return response()->json(['plugin' => $plugin->fresh(), 'version' => $new], 201);
    }

    public function diff(StrategyPlugin $plugin, string $a, string $b): JsonResponse
    {
        $va = $this->findVersion($plugin, $a);
        $vb = $this->findVersion($plugin, $b);

        return response()->json([
            'from' => $a,
            'to' => $b,
            'diff' => JsonDiff::diff($va->definition, $vb->definition),
        ]);
    }

    private function findVersion(StrategyPlugin $plugin, string $version): StrategyPluginVersion
    {
        return StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)
            ->where('version', $version)
            ->firstOrFail();
    }
}
