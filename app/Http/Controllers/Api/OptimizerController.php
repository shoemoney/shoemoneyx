<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\DeskContext;
use App\Desk\Execution\Perps;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use App\Http\Controllers\Controller;
use App\Models\OptimizerRound;
use App\Support\CandidateSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptimizerController extends Controller
{
    /** The keys that make up a coin's long set and, under <strategy>.short.*, its short set. */
    private const KEYS = [
        'timeframe', 'engine.x1', 'engine.x2', 'engine.base_minutes', 'engine.jac_len', 'engine.ma_type',
        'engine.over_bought', 'engine.over_sold', 'tp_max_pct', 'tp_rungs', 'qty_pct', 'max_position_pct',
        'leverage', 'pyramiding', 'edge', 'short_stop_pct', 'short_trend_ma', 'allow_shorts',
        'tsl_pct', 'ttp_activate_pct', 'ttp_giveback_pct',
    ];

    /** Every active coin: its long champion, its short set (if any), and the latest scored round per side. */
    public function champions(Settings $settings): JsonResponse
    {
        $strategyKey = $settings->strategyKey();
        $strategy = app(StrategyRegistry::class)->make($strategyKey);
        $prefix = "{$strategyKey}.";
        $ctx = new DeskContext($settings->merged($strategy), $settings->mode());
        $latest = OptimizerRound::query()
            ->whereIn('id', OptimizerRound::selectRaw('max(id)')->groupBy('product_id', 'side'))
            ->get()->keyBy(fn ($r) => $r->product_id.'|'.$r->side);
        $promoted = OptimizerRound::query()->where('promoted', true)
            ->whereIn('id', OptimizerRound::where('promoted', true)->selectRaw('max(id)')->groupBy('product_id', 'side'))
            ->get()->keyBy(fn ($r) => $r->product_id.'|'.$r->side);

        $out = [];
        foreach ($this->coins() as $pid) {
            $c = $ctx->forProduct($pid);
            $short = $c->param("{$prefix}short");
            $row = ['coin' => $pid, 'long' => [], 'short' => null, 'shorts_enabled' => (bool) $c->param("{$prefix}allow_shorts", false), 'rounds' => []];
            foreach (self::KEYS as $k) {
                $row['long'][$k] = $c->param("{$prefix}{$k}");
            }
            if (is_array($short) && $short !== []) {
                $row['short'] = [];
                foreach (self::KEYS as $k) {
                    $row['short'][$k] = $c->param("{$prefix}short.{$k}", $c->param("{$prefix}{$k}"));
                }
            }
            foreach (['long', 'short'] as $side) {
                $l = $latest["{$pid}|{$side}"] ?? null;
                $p = $promoted["{$pid}|{$side}"] ?? null;
                $row['rounds'][$side] = $l ? [
                    'at' => $l->created_at, 'train' => $l->promoted ? $l->best_train : $l->champion_train, 'test' => $l->promoted ? $l->best_test : $l->champion_test,
                    'note' => $l->note, 'last_promoted_at' => $p?->created_at, 'last_promoted_train' => $p?->best_train, 'last_promoted_test' => $p?->best_test,
                ] : null;
            }
            $out[] = $row;
        }

        return response()->json(['coins' => $out, 'keys' => self::KEYS, 'server_time' => now()->toIso8601String()]);
    }

    /** Round log, newest first. ?coin=BTC-USD ?side=short ?promoted=1 ?limit=100 */
    public function rounds(Request $request): JsonResponse
    {
        $q = OptimizerRound::query()->latest('id');
        if ($request->filled('coin')) {
            $q->where('product_id', strtoupper((string) $request->string('coin')));
        }
        if ($request->filled('side')) {
            $q->where('side', (string) $request->string('side'));
        }
        if ($request->filled('strategy')) {
            $q->where('strategy', (string) $request->string('strategy'));
        }
        if ($request->boolean('promoted')) {
            $q->where('promoted', true);
        }
        if ($request->filled('cash')) {
            $q->where('cash', $request->float('cash'));
        }
        if ($request->filled('tag')) {
            $q->where('tag', (string) $request->string('tag'));
        }
        $rows = $q->limit(min(500, max(1, $request->integer('limit', 100))))->get();

        return response()->json($rows);
    }

    /**
     * Candidate points for the scatter and heatmaps: one row per candidate with its train and test scores paired.
     * ?coin=BTC-USD (required) ?side=long|short ?hours=24 ?limit=4000 rows scanned.
     */
    public function candidates(Request $request): JsonResponse
    {
        $coin = strtoupper((string) $request->string('coin'));
        $side = (string) $request->string('side', 'long');
        abort_if($coin === '', 422, 'coin is required');
        $q = \App\Models\Backtest::query()
            ->select(['id', 'status', 'params', 'stats', 'created_at'])
            ->where('status', 'done')
            ->where('opt_coin', $coin)
            ->where(function ($q) use ($side) {
                $q->where('opt_side', $side);
                if ($side === 'long') {
                    $q->orWhereNull('opt_side');   // rows from before the side field existed
                }
            })
            ->where('created_at', '>=', now()->subHours(max(1, $request->integer('hours', 24))));
        if ($request->filled('tag')) {
            $q->where('params->_opt->tag', (string) $request->string('tag'));
        }
        $rows = $q->latest('id')->limit(min(20000, max(100, $request->integer('limit', 4000))))->get();

        $points = [];
        foreach ($rows as $r) {
            $p = (array) $r->params;
            $o = (array) ($p['_opt'] ?? []);
            $win = $o['window'] ?? null;
            if (! in_array($win, ['train', 'test'], true)) {
                continue;
            }
            // batch id when present; older rows pair by candidate index within the same 10-second dispatch
            $key = ($o['batch'] ?? intdiv($r->created_at->getTimestamp(), 10)).'|'.($o['cand'] ?? 'x');
            $points[$key] ??= [
                'cand' => $o['cand'] ?? null, 'batch' => $o['batch'] ?? null, 'at' => $r->created_at->toIso8601String(),
                ...CandidateSummary::params($p, $side),
                'train' => null, 'test' => null, 'trades' => null, 'pf' => null, 'dd' => null,
            ];
            $s = (array) ($r->stats ?? []);
            $points[$key][$win] = $s['total_return_pct'] ?? null;
            if ($win === 'train') {
                $points[$key]['trades'] = $s['trades'] ?? null;
                $points[$key]['pf'] = $s['profit_factor'] ?? null;
                $points[$key]['dd'] = $s['max_drawdown_pct'] ?? null;
            }
        }
        $paired = array_values(array_filter($points, fn ($p) => $p['train'] !== null && $p['test'] !== null));

        return response()->json(['coin' => $coin, 'side' => $side, 'points' => $paired, 'scanned' => $rows->count()]);
    }

    private function coins(): array
    {
        $active = (array) config('desk.perps.active', []);
        if ($active !== []) {
            return $active;
        }

        return Perps::enabled() ? array_keys((array) config('desk.perps.map', [])) : [];
    }
}
