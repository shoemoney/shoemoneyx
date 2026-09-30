<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\EndOfDayReport;
use App\Desk\Exceptions\CycleInProgressException;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use App\Exchange\Contracts\MarketData;
use App\Http\Controllers\Controller;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\Product;
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
            'cycle' => $this->cycle($desk),
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

    private function cycle(Desk $desk): JsonResponse
    {
        try {
            return response()->json(['run' => $desk->cycle()->load('candidates')]);
        } catch (CycleInProgressException) {
            return response()->json(['ok' => false, 'error' => 'a cycle is already running'], 409);
        }
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
            'overrides' => Setting::all()->pluck('value', 'key')->reject(fn ($value, string $key) => Settings::isSecret($key)),
        ]);
    }

    public function updateSetting(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:120', 'value' => 'present']);
        abort_unless(Settings::isCanonicalKey($data['key']), 422, 'invalid setting key');
        abort_if(Settings::isSecret($data['key']), 422, 'change the master password from onboarding');
        $v = ParamNormalizer::normalize($data['key'], $data['value']);
        $this->assertSaneRiskValue($data['key'], $v);
        if ($data['key'] === 'mode' && ! in_array($v, ['paper', 'live'], true)) {
            abort(422, 'mode must be paper or live');
        }
        if ($data['key'] === 'strategy' && ! isset(config('desk.strategies')[$v])) {
            abort(422, 'unknown strategy');
        }
        $settings->set($data['key'], $v);

        return response()->json(['ok' => true, 'key' => $data['key'], 'value' => $v]);
    }

    /** Risk-critical numeric knobs and the range each must stay inside: [min, max, minExclusive]. */
    private const RISK_RANGES = [
        'size.kelly_cap_pct' => [0.0, 1.0, true],
        'size.max_open_positions' => [1, 1000, false],
        'size.max_leverage' => [1.0, 20.0, false],
        'size.min_ticket_usd' => [0.0, 1_000_000.0, false],
    ];

    /**
     * Walks the value recursively so a parent-map write (key "size", value {kelly_cap_pct: 5}) is
     * checked leaf by leaf under its full key. Any path that equals or descends from a risk key must
     * end in a scalar in range exactly AT that key; containers there (even empty ones) or anything
     * nested below it would hydrate the setting as an array, so they are refused.
     */
    private function assertSaneRiskValue(string $key, mixed $value): void
    {
        $bare = preg_replace('/^per_product\.[^.]+\./', '', $key);

        foreach (self::RISK_RANGES as $riskKey => [$min, $max, $minExclusive]) {
            if (str_starts_with($bare, $riskKey.'.')) {
                abort(422, "{$key}: {$riskKey} must be a plain number, nothing may be nested under it");
            }
            if ($bare === $riskKey) {
                $ok = is_numeric($value) && is_finite((float) $value)
                    && ($minExclusive ? $value > $min : $value >= $min) && $value <= $max
                    && ($riskKey !== 'size.max_open_positions' || floor((float) $value) === (float) $value);
                abort_unless($ok, 422, sprintf('%s must be a %s number %s %s and <= %s', $key, $riskKey === 'size.max_open_positions' ? 'whole' : 'plain', $minExclusive ? '>' : '>=', $min, $max));

                return;
            }
            // An ancestor of a risk key (e.g. "size") may only be written as a map.
            abort_if(str_starts_with($riskKey, $bare.'.') && ! is_array($value), 422, "{$key} must be an object");
        }

        if (is_array($value)) {
            foreach ($value as $child => $childValue) {
                $this->assertSaneRiskValue($key.'.'.$child, $childValue);
            }
        }
    }

    public function deleteSetting(string $key, Settings $settings): JsonResponse
    {
        abort_unless(Settings::isCanonicalKey($key), 422, 'invalid setting key');
        abort_if(Settings::isSecret($key), 422, 'change the master password from onboarding');
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
