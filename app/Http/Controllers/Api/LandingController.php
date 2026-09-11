<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Chief;
use App\Desk\Settings;
use App\Http\Controllers\Controller;
use App\Models\BankSnapshot;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\OptimizerRound;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\LiveFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** A read-only snapshot. Prices come from the feeder, never one remote request per pair. */
class LandingController extends Controller
{
    public function index(Settings $settings, LiveFeed $feed, Chief $chief): JsonResponse
    {
        $mode = $settings->mode();
        // The production database uses REPEATABLE-READ. Keep these reads together so a
        // concurrently closed position cannot land in both the open and closed totals.
        [$open, $closed] = DB::transaction(fn () => [
            Position::open()->mode($mode)->get(),
            Position::mode($mode)->where('status', 'closed')
                ->selectRaw('product_id, SUM(pnl_usd) AS realised, COUNT(*) AS trades, SUM(CASE WHEN pnl_usd > 0 THEN 1 ELSE 0 END) AS wins')
                ->groupBy('product_id')->get()->keyBy('product_id'),
        ]);
        $active = config('desk.perps.enabled')
            ? (config('desk.perps.active') ?: array_keys(config('desk.perps.map', [])))
            : Product::tracked()->pluck('product_id')->all();
        // Historical markets belong in the universe too: their realised P&L is in the total.
        $ids = collect($active)->merge($open->pluck('product_id'))->merge($closed->keys())->unique()->values();
        $products = Product::whereIn('product_id', $ids)->get()->keyBy('product_id');
        $latestRun = DeskRun::where('mode', $mode)->latest('started_at')->first([
            'id', 'strategy', 'status', 'degraded', 'products_scanned', 'candidates', 'passed', 'rejected', 'filled', 'started_at', 'finished_at',
        ]);
        $decisions = $latestRun?->candidates()->get([
            'product_id', 'verdict', 'score', 'rank_reason', 'why', 'size_usd', 'created_at',
        ])->unique('product_id')->keyBy('product_id') ?? collect();
        $byProduct = $open->groupBy('product_id');
        $pairs = $ids->map(function (string $id) use ($products, $byProduct, $closed, $feed, $decisions): array {
            $product = $products->get($id);
            $quote = $feed->quote($id);
            $positions = $byProduct->get($id, collect());
            $price = $quote['price'] ?? $product?->price ?? $positions->first()?->last_price;
            $price = $price !== null && (float) $price > 0 ? (float) $price : null;
            // Closed P&L and banked trims are already net. Deduct only costs still attached
            // to the open quantity, matching Desk::bookExit (including negative funding credits).
            $openCosts = (float) $positions->sum(fn ($p) => ($p->meta['entry_fees_usd'] ?? $p->fees_usd ?? 0) + ($p->meta['funding_usd'] ?? 0));
            $unrealised = $price !== null ? $positions->sum(fn ($p) => $p->unrealisedPnl($price)) - $openCosts : ($positions->isEmpty() ? 0 : null);
            $realised = (float) ($closed->get($id)?->realised ?? 0) + $positions->sum('realised_usd');
            $decision = $decisions->get($id);

            return [
                'id' => $id, 'price' => $price,
                'change' => $quote['chg24'] ?? ($product?->price_change_24h_pct !== null ? (float) $product->price_change_24h_pct : null),
                'volume' => $quote['vol24'] ?? ($product?->volume_24h_usd !== null ? (float) $product->volume_24h_usd : null),
                'live' => $quote !== null, 'quote_at' => $quote['ts'] ?? $product?->updated_at?->timestamp,
                'open' => $positions->count(), 'side' => $positions->isEmpty() ? 'watching' : ($positions->pluck('side')->unique()->count() > 1 ? 'hedged' : ($positions->first()->side ?? 'long')),
                'unrealised' => $unrealised, 'realised' => $realised,
                'pnl' => $unrealised !== null ? $realised + $unrealised : null,
                'notional' => $price !== null ? $positions->sum('quantity') * $price : null,
                'open_costs' => $openCosts,
                'trades' => (int) ($closed->get($id)?->trades ?? 0),
                'decision' => $decision ? [
                    'verdict' => $decision->verdict, 'score' => $decision->score,
                    'reason' => $decision->why ?: $decision->rank_reason,
                    'size_usd' => $decision->size_usd, 'at' => $decision->created_at,
                ] : null,
                'positions' => $positions->map(fn ($p) => [
                    'id' => $p->id, 'side' => $p->side ?? 'long', 'quantity' => $p->quantity,
                    'entry_price' => $p->entry_price, 'entry_usd' => $p->entry_usd,
                    'opened_at' => $p->opened_at, 'realised' => $p->realised_usd,
                ])->values(),
            ];
        });
        $unpriced = $pairs->contains(fn ($p) => $p['unrealised'] === null);
        $realised = (float) $closed->sum('realised') + (float) $open->sum('realised_usd');
        $unrealised = $unpriced ? null : (float) $pairs->sum('unrealised');
        $trades = (int) $closed->sum('trades');
        $fills = Fill::where('mode', $mode)->latest('id')->limit(30)->get([
            'id', 'product_id', 'side', 'kind', 'status', 'filled_usd', 'filled_qty', 'fill_price', 'fee_usd', 'created_at',
        ]);
        $bank = BankSnapshot::where('mode', $mode)->latest('taken_at')->first([
            'cash', 'equity', 'positions_value', 'free_cash', 'collateral', 'exposure', 'buying_power', 'taken_at',
        ]);
        $bankAge = $bank ? max(0, now()->timestamp - $bank->taken_at->timestamp) : null;
        $events = DeskEvent::query()->where(fn ($q) => $q->where(fn ($global) => $global->whereNull('desk_run_id')
            ->where(fn ($position) => $position->whereNull('payload->position_id')
                ->orWhereIn('payload->position_id', Position::mode($mode)->select('id'))))
            ->orWhereIn('desk_run_id', DeskRun::where('mode', $mode)->select('id')))
            ->latest('id')->limit(40)->get(['id', 'desk_run_id', 'payload', 'agent', 'level', 'message', 'created_at'])
            ->map(fn ($event) => [
                ...$event->only(['id', 'agent', 'level', 'message', 'created_at']),
                'scope' => $event->desk_run_id || isset($event->payload['position_id']) ? $mode : 'system',
            ]);
        // A bounded durable history complements the existing optimizer socket after reload/reconnect.
        $rounds = OptimizerRound::latest('id')->limit(12)->get([
            'id', 'product_id', 'side', 'candidates', 'promoted', 'created_at',
        ]);

        return response()->json([
            'at' => now()->toIso8601String(), 'mode' => $mode, 'strategy' => $settings->strategyKey(),
            'running' => $chief->running(), 'halted' => $chief->halted(), 'heartbeats' => $chief->heartbeats(),
            'heartbeat_stale_seconds' => (int) $settings->get('chief.heartbeat_seconds', 60) * (int) $settings->get('chief.missed_pings_restart', 2),
            'feed_alive' => $feed->alive(), 'pairs' => $pairs,
            'metrics' => [
                'pnl' => $unrealised !== null ? $realised + $unrealised : null,
                'realised' => $realised, 'unrealised' => $unrealised,
                'open' => $open->count(), 'trades' => $trades,
                'longs' => $open->filter(fn ($p) => ! $p->isShort())->count(),
                'shorts' => $open->filter(fn ($p) => $p->isShort())->count(),
                'win_rate' => $trades > 0 ? (float) $closed->sum('wins') / $trades * 100 : null,
                'notional' => $unpriced ? null : (float) $pairs->sum('notional'),
                'open_costs' => (float) $pairs->sum('open_costs'),
                'pnl_basis' => 'net_of_booked_costs',
                'pnl_note' => 'Includes booked fees and funding; estimated exit costs excluded.',
            ],
            'latest_run' => $latestRun, 'fills' => $fills, 'optimizer_rounds' => $rounds,
            'bank' => $bank ? [...$bank->toArray(), 'age_seconds' => $bankAge, 'stale' => $bankAge > 600, 'source' => 'saved_snapshot'] : null,
            'events' => $events,
        ])->header('Cache-Control', 'private, no-store');
    }
}
