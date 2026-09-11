<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\EndOfDayReport;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use App\Http\Controllers\Controller;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\Product;
use App\Exchange\Contracts\MarketData;
use App\Models\Setting;
use App\Services\Market\LiveFeed;
use App\Support\ParamNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeskController extends Controller
{
    public function action(string $action, Desk $desk, Chief $chief, Request $request): JsonResponse
    {
        return match ($action) {
            'cycle' => response()->json(['run' => $desk->cycle()->load('candidates')]),
            'risk' => response()->json(['results' => $desk->riskSweep()]),
            'halt' => tap(response()->json(['ok' => true]), fn () => $chief->halt((string) $request->input('reason', 'dashboard'))),
            'resume' => tap(response()->json(['ok' => true]), fn () => $chief->resume()),
            'start' => tap(response()->json(['ok' => true]), fn () => $chief->setRunning(true)),
            'stop' => tap(response()->json(['ok' => true]), fn () => $chief->setRunning(false)),
            'paper-reset' => tap(response()->json(['ok' => true]), function () {
                Position::mode('paper')->delete();
                PaperLedger::truncate();
            }),
            default => abort(404, 'unknown action'),
        };
    }

    public function settings(Settings $settings, StrategyRegistry $registry): JsonResponse
    {
        $strategy = $registry->make();

        return response()->json([
            'mode' => $settings->mode(),
            'strategy' => $settings->strategyKey(),
            'strategies' => collect($registry->all())->map(fn ($cls, $key) => ['key' => $key, 'name' => app($cls)->name()])->values(),
            'live_confirm' => config('desk.live_confirm') === 'yes',
            'params' => $settings->flattened($strategy),
            'overrides' => Setting::all()->pluck('value', 'key'),
        ]);
    }

    public function updateSetting(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:120', 'value' => 'present']);
        $v = ParamNormalizer::normalize($data['key'], $data['value']);
        if ($data['key'] === 'mode' && ! in_array($v, ['paper', 'live'], true)) {
            abort(422, 'mode must be paper or live');
        }
        if ($data['key'] === 'strategy' && ! isset(config('desk.strategies')[$v])) {
            abort(422, 'unknown strategy');
        }
        $settings->set($data['key'], $v);

        return response()->json(['ok' => true, 'key' => $data['key'], 'value' => $v]);
    }

    public function deleteSetting(string $key, Settings $settings): JsonResponse
    {
        $settings->forget($key);

        return response()->json(['ok' => true]);
    }

    public function quote(Request $request, LiveFeed $feed): JsonResponse
    {
        $productId = (string) $request->query('product', '');
        $live = $productId !== '' ? $feed->quote($productId) : null;
        if ($live !== null) {
            return response()->json(['product_id' => $productId, 'source' => 'feed'] + $live);
        }
        $row = Product::query()->where('product_id', $productId)->first(['price', 'price_change_24h_pct', 'volume_24h_usd', 'updated_at']);
        if ($row === null || $row->price === null) {
            return response()->json(null);
        }

        return response()->json([
            'product_id' => $productId,
            'source' => 'products',
            'price' => (float) $row->price,
            'bid' => null,
            'ask' => null,
            'vol24' => $row->volume_24h_usd !== null ? (float) $row->volume_24h_usd : null,
            'chg24' => $row->price_change_24h_pct !== null ? (float) $row->price_change_24h_pct : null,
            'ts' => $row->updated_at?->getTimestamp() ?? 0,
        ]);
    }

    public function products(MarketData $market): JsonResponse
    {
        return response()->json(Product::tracked()->orderByDesc('volume_24h_usd')->get(['product_id', 'base_currency', 'quote_currency', 'price', 'price_change_24h_pct', 'volume_24h_usd', 'listed_at', 'status']));
    }

    public function report(Request $request, EndOfDayReport $report): JsonResponse
    {
        $day = $request->input('date') ? Carbon::parse((string) $request->input('date')) : now();
        $r = $report->build($day);

        return response()->json(['report' => $r, 'text' => strip_tags($report->text($r))]);
    }
}
