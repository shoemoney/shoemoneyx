<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Strategies\SchemaMigrator;
use App\Http\Controllers\Controller;
use App\Hub\HubClient;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thin wrappers over HubClient for the archive browser page (search, typeahead,
 * strategy detail, versions, comments, import). All public reads — HubClient
 * already sends the bearer token automatically when a desk is connected, and
 * omits it when not, so there's nothing to branch on here.
 */
class HubArchiveController extends Controller
{
    /** Also used as route constraints: slug/version end up as HubClient URL path segments. */
    public const SLUG_PATTERN = '[a-z0-9][a-z0-9-]{0,63}';

    /** The leading lookahead caps the whole string at 32 chars: strategy_plugin_versions.version is varchar(32). */
    public const VERSION_PATTERN = '(?!.{33})\d+(?:\.\d+){0,2}(?:[-+][0-9A-Za-z][0-9A-Za-z.-]{0,31})?';

    private const KEY_MAX = 64;

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
            'slug' => ['required', 'string', 'regex:/^'.self::SLUG_PATTERN.'$/D'],
            'version' => ['required', 'string', 'regex:/^'.self::VERSION_PATTERN.'$/D'],
        ]);

        // The version column is case-insensitive on MariaDB; normalise so "1.0.0-RC1" and "-rc1" are one version everywhere.
        $data['version'] = strtolower($data['version']);

        $hub->import($data['slug']);

        $strategy = $hub->strategy($data['slug']);
        $handle = $strategy['author']['handle'] ?? $strategy['author'] ?? 'hub';

        $remoteVersion = $hub->version($data['slug'], $data['version']);
        $definition = $remoteVersion['definition'];

        $result = SchemaMigrator::validateForSave($definition);

        if (! $result['valid']) {
            return response()->json($result, 422);
        }

        return DB::transaction(function () use ($data, $definition, $handle) {
            // Lock the plugin row so concurrent imports of one slug serialise on the pin check + write.
            $plugin = StrategyPlugin::where('hub_slug', $data['slug'])->lockForUpdate()->first();

            if (! $plugin) {
                $key = $data['slug'];
                if (StrategyPlugin::where('key', $key)->exists()) {
                    $key = self::fitKey($data['slug'], '-hub');
                    $suffix = 2;
                    while (StrategyPlugin::where('key', $key)->exists()) {
                        $key = self::fitKey($data['slug'], "-hub-{$suffix}");
                        $suffix++;
                    }
                }

                $plugin = new StrategyPlugin(['key' => $key]);
            } else {
                $pinned = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', $data['version'])->first();
                if ($pinned && self::canonical($pinned->definition) !== self::canonical($definition)) {
                    return response()->json(['message' => "version {$data['version']} is already pinned with a different definition"], 409);
                }
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
        });
    }

    /** JSON object-member order carries no meaning: sort object keys recursively, keep list order and scalar types. */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return json_encode($sort($value));
    }

    /** slug + suffix must fit strategy_plugins.key (varchar 64): trim the slug, never the suffix. */
    private static function fitKey(string $slug, string $suffix): string
    {
        return substr($slug, 0, self::KEY_MAX - strlen($suffix)).$suffix;
    }
}
