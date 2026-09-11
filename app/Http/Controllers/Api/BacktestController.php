<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Strategies\BacktestVersionPin;
use App\Http\Controllers\Controller;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Product;
use App\Support\ParamNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class BacktestController extends Controller
{
    /** Interactive-list page size: default and hard cap on ?limit=. */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    /**
     * Interactive backtest history: cursor-paginated (id desc), farm/optimizer rows (params->_opt
     * set — see the opt_coin virtual column) excluded by default since the farm can carry hundreds
     * of thousands of rows in the same table. Pass ?include=farm to see them too. The selected job
     * itself is polled through show() below, not re-fetched here.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->integer('limit', self::DEFAULT_LIMIT)));
        $includeFarm = strtolower((string) $request->query('include', '')) === 'farm';

        $query = Backtest::query()
            ->select(['id', 'strategy', 'strategy_plugin_version_id', 'status', 'products', 'from', 'to', 'starting_cash', 'ending_equity', 'stats', 'created_at'])
            ->with('strategyPluginVersion:id,version')
            ->when(! $includeFarm, fn ($q) => $q->whereNull('opt_coin'))
            ->orderByDesc('id');

        $cursor = $request->query('cursor');
        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '<', (int) $cursor);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        return response()->json([
            'data' => $rows,
            'next_cursor' => $hasMore ? $rows->last()->id : null,
            'has_more' => $hasMore,
        ]);
    }

    /** Cheap single-job fetch used for polling a running/queued backtest. */
    public function show(Backtest $backtest): JsonResponse
    {
        return response()->json($backtest);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strategy' => 'required|string',
            'products' => 'nullable|array',
            'products.*' => 'string',
            'days' => 'nullable|integer|min:1|max:365',
            'cash' => 'nullable|numeric|min:1',
            'params' => 'nullable|array',
        ]);
        $products = $data['products'] ?? Product::tracked()->orderByDesc('volume_24h_usd')->limit(20)->pluck('product_id')->all();
        $to = now()->startOfHour();
        $from = $to->copy()->subDays($data['days'] ?? 30);

        [$versionId, $params] = BacktestVersionPin::resolve($data['strategy'], self::normalizeOverrides($data['params'] ?? []));

        $bt = Backtest::create([
            'strategy' => $data['strategy'],
            'strategy_plugin_version_id' => $versionId,
            'products' => array_map('strtoupper', $products),
            'from' => $from,
            'to' => $to,
            'starting_cash' => $data['cash'] ?? 1000,
            'params' => $params,
            'status' => 'queued',
        ]);
        RunBacktest::dispatch($bt->id);

        return response()->json($bt, 202);
    }

    /**
     * Typed backtest param overrides. The form textarea (and any raw JSON caller) can send
     * booleans as strings — `mr.allow_shorts=false` used to arrive as the *string* "false",
     * which PHP casts truthy downstream. Values are typed via App\Support\ParamNormalizer from
     * the knob's DEFAULT (strategy defaults() for a "mr."-style root, config('desk') for
     * everything else) — true type parity with DeskController::updateSetting, not just shared
     * boolean tokens: a bool-defaulted knob accepts "true"/"false"/"1"/"0"/"on"/"off"/"yes"/"no"
     * (case-insensitive), an int/float-defaulted knob accepts numeric strings, and a
     * string-defaulted knob keeps the literal string untouched — including an empty one. Anything
     * that can't be resolved from a defaults tree falls back to the old value-shape guess. Rejects
     * an override key whose top-level segment isn't a known config namespace, a registered
     * strategy key, or "per_product", or a value that looks like a broken number (digits/dot/minus
     * only but not is_numeric) instead of a real string like a timeframe.
     *
     * @param  array<string, mixed>  $params  nested or already-dotted override tree
     * @return array<string, mixed> flattened, typed dotted map (App\Desk\Backtester::undot()
     *                               rebuilds the nested tree from this at simulate time)
     */
    public static function normalizeOverrides(array $params): array
    {
        if ($params === []) {
            return [];
        }

        $validRoots = array_values(array_unique(array_merge(
            array_keys((array) config('desk', [])),
            array_keys((array) config('desk.strategies', [])),
            ['per_product'],
        )));

        $out = [];
        foreach (Arr::dot($params) as $key => $value) {
            $root = strstr($key.'.', '.', true);
            if ($root === false || ! in_array($root, $validRoots, true)) {
                throw ValidationException::withMessages(['params' => "unknown override key: {$key}"]);
            }
            $out[$key] = self::normalizeOverrideValue($key, $value);
        }

        return $out;
    }

    private static function normalizeOverrideValue(string $key, mixed $value): bool|int|float|string|null
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages(['params' => "malformed override value for {$key}"]);
        }

        return ParamNormalizer::normalize($key, $value);
    }
}
