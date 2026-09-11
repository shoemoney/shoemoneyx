<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Strategies\JsonPluginValidator;
use App\Desk\Strategies\StrategySchemaValidator;
use App\Http\Controllers\Controller;
use App\Hub\HubClient;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin wrappers over HubClient for the archive browser page (search, typeahead,
 * strategy detail, versions, comments, import). All public reads — HubClient
 * already sends the bearer token automatically when a desk is connected, and
 * omits it when not, so there's nothing to branch on here.
 */
class HubArchiveController extends Controller
{
    public function search(Request $request, HubClient $hub): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string',
            'exchange' => 'nullable|string',
            'timeframe' => 'nullable|string',
            'asset' => 'nullable|string',
            'min_win_rate' => 'nullable|numeric',
            'max_drawdown' => 'nullable|numeric',
            'author' => 'nullable|string',
            'tag' => 'nullable|string',
            'sort' => 'nullable|in:new,stars,imports,win_rate',
            'page' => 'nullable|integer',
        ]);

        return response()->json($hub->search($data));
    }

    public function typeahead(Request $request, HubClient $hub): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2',
        ]);

        return response()->json($hub->typeahead($data['q']));
    }

    public function show(string $slug, HubClient $hub): JsonResponse
    {
        return response()->json($hub->strategy($slug));
    }

    public function version(string $slug, string $version, HubClient $hub): JsonResponse
    {
        return response()->json($hub->version($slug, $version));
    }

    public function comments(Request $request, string $slug, HubClient $hub): JsonResponse
    {
        $data = $request->validate([
            'page' => 'nullable|integer',
        ]);

        return response()->json($hub->comments($slug, $data['page'] ?? 1));
    }

    public function import(Request $request, HubClient $hub): JsonResponse
    {
        $data = $request->validate([
            'slug' => 'required|string',
            'version' => 'required|string',
        ]);

        $hub->import($data['slug']);

        $strategy = $hub->strategy($data['slug']);
        $handle = $strategy['author']['handle'] ?? $strategy['author'] ?? 'hub';

        $remoteVersion = $hub->version($data['slug'], $data['version']);
        $definition = $remoteVersion['definition'];

        $result = isset($definition['schema_version'])
            ? StrategySchemaValidator::validate($definition)
            : JsonPluginValidator::validate($definition);

        if (! $result['valid']) {
            return response()->json($result, 422);
        }

        $plugin = StrategyPlugin::where('hub_slug', $data['slug'])->first();

        if (! $plugin) {
            $key = $data['slug'];
            if (StrategyPlugin::where('key', $key)->exists()) {
                $key = "{$data['slug']}-hub";
                $suffix = 2;
                while (StrategyPlugin::where('key', $key)->exists()) {
                    $key = "{$data['slug']}-hub-{$suffix}";
                    $suffix++;
                }
            }

            $plugin = new StrategyPlugin(['key' => $key]);
        }

        $plugin->name = $definition['meta']['name'] ?? $definition['name'] ?? $plugin->key;
        $plugin->description = $definition['meta']['description'] ?? $definition['description'] ?? null;
        $plugin->definition = $definition;
        $plugin->current_version = $data['version'];
        $plugin->hub_slug = $data['slug'];
        $plugin->save();

        $version = StrategyPluginVersion::updateOrCreate(
            ['strategy_plugin_id' => $plugin->id, 'version' => $data['version']],
            [
                'definition' => $definition,
                'changelog' => "imported from hub {$handle}/{$data['slug']}",
                'created_by' => null,
            ],
        );

        return response()->json(['plugin' => $plugin, 'version' => $version], 201);
    }
}
