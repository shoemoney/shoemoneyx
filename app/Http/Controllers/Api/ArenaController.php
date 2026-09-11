<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\DeskContext;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use App\Http\Controllers\Controller;
use App\Models\Backtest;
use App\Models\OptimizerRound;
use App\Support\CandidateSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ArenaController extends Controller
{
    /** Mirrors OptimizerController::KEYS — the active strategy's param set that makes up a coin's champion. */
    private const KEYS = [
        'timeframe', 'engine.x1', 'engine.x2', 'engine.base_minutes', 'engine.jac_len', 'engine.ma_type',
        'engine.over_bought', 'engine.over_sold', 'tp_max_pct', 'tp_rungs', 'qty_pct', 'max_position_pct',
        'leverage', 'pyramiding', 'edge', 'short_stop_pct', 'short_trend_ma', 'allow_shorts',
        'tsl_pct', 'ttp_activate_pct', 'ttp_giveback_pct',
    ];

    /** The champion's current set, its walk-forward score, recent challengers, and today's tally. ?coin=BTC-USD ?side=long */
    public function show(Request $request, Settings $settings): JsonResponse
    {
        $coin = strtoupper((string) $request->string('coin', 'BTC-USD'));
        $side = (string) $request->string('side', 'long');
        abort_if(! in_array($side, ['long', 'short'], true), 422, 'side must be long or short');

        return response()->json(Cache::remember("arena:{$coin}:{$side}", 3, fn () => $this->build($coin, $side, $settings)));
    }

    private function build(string $coin, string $side, Settings $settings): array
    {
        $strategyKey = $settings->strategyKey();
        $strategy = app(StrategyRegistry::class)->make($strategyKey);
        $ctx = new DeskContext($settings->merged($strategy), $settings->mode());
        $c = $ctx->forProduct($coin);
        $prefix = "{$strategyKey}.";

        $set = [];
        foreach (self::KEYS as $k) {
            $set[$k] = $side === 'short' ? $c->param("{$prefix}short.{$k}", $c->param("{$prefix}{$k}")) : $c->param("{$prefix}{$k}");
        }

        $latest = OptimizerRound::where('product_id', $coin)->where('side', $side)->latest('id')->first();
        $promoted = OptimizerRound::where('product_id', $coin)->where('side', $side)->where('promoted', true)->latest('id')->first();

        $today = now()->startOfDay();

        return [
            'coin' => $coin,
            'side' => $side,
            'champion' => [
                'set' => $set,
                'train' => $latest ? ($latest->promoted ? $latest->best_train : $latest->champion_train) : null,
                'test' => $latest ? ($latest->promoted ? $latest->best_test : $latest->champion_test) : null,
                'promoted_at' => $promoted?->created_at?->toIso8601String(),
            ],
            'recent' => $this->recentCandidates($coin, $side),
            'today' => [
                'challengers' => $this->challengerQuery($coin, $side)->where('created_at', '>=', $today)->count(),
                'promotions' => OptimizerRound::where('product_id', $coin)->where('side', $side)->where('promoted', true)->where('created_at', '>=', $today)->count(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function challengerQuery(string $coin, string $side)
    {
        return Backtest::query()
            ->where('status', 'done')
            ->where('opt_coin', $coin)
            ->where('opt_window', 'test')
            ->where(function ($q) use ($side) {
                $q->where('opt_side', $side);
                if ($side === 'long') {
                    $q->orWhereNull('opt_side');
                }
            });
    }

    /** Last 30 paired candidates (train+test scored) for the fight scene, newest first. 400 rows ≈ 200 candidates; 2000 cost 0.85 s of the old 2 s. */
    private function recentCandidates(string $coin, string $side): array
    {
        $rows = Backtest::query()
            ->select(['id', 'params', 'stats', 'created_at'])
            ->where('status', 'done')
            ->where('opt_coin', $coin)
            ->whereIn('opt_window', ['train', 'test'])
            ->where(function ($q) use ($side) {
                $q->where('opt_side', $side);
                if ($side === 'long') {
                    $q->orWhereNull('opt_side');
                }
            })
            ->latest('id')->limit(400)->get();

        $points = [];
        foreach ($rows as $r) {
            $p = (array) $r->params;
            $o = (array) ($p['_opt'] ?? []);
            $win = $o['window'] ?? null;
            if (! in_array($win, ['train', 'test'], true)) {
                continue;
            }
            $key = ($o['batch'] ?? intdiv($r->created_at->getTimestamp(), 10)).'|'.($o['cand'] ?? 'x');
            $points[$key] ??= [
                'cand' => $o['cand'] ?? null, 'batch' => $o['batch'] ?? null, 'at' => $r->created_at->toIso8601String(),
                'params' => CandidateSummary::params($p, $side),
                'train' => null, 'test' => null,
            ];
            $s = (array) ($r->stats ?? []);
            $points[$key][$win] = $s['total_return_pct'] ?? null;
        }
        $paired = array_values(array_filter($points, fn ($pt) => $pt['train'] !== null && $pt['test'] !== null));
        usort($paired, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($paired, 0, 30);
    }
}
