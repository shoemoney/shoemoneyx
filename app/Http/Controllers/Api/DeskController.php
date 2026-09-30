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

    /** A parent-map write (key "size", value {kelly_cap_pct: 5}) is flattened so every leaf is checked under its full key. */
    private function assertSaneRiskValue(string $key, mixed $value): void
    {
        if (is_array($value)) {
            abort_if(isset(self::RISK_RANGES[preg_replace('/^per_product\.[^.]+\./', '', $key)]), 422, 'risk settings must be plain numbers');
            foreach ($value as $child => $childValue) {
                $this->assertSaneRiskValue($key.'.'.$child, $childValue);
            }

            return;
        }

        $bare = preg_replace('/^per_product\.[^.]+\./', '', $key);
        if (! isset(self::RISK_RANGES[$bare])) {
            // A list/object at or under a numeric risk key ("kelly_cap_pct.0") is never a valid value.
            foreach (array_keys(self::RISK_RANGES) as $riskKey) {
                abort_if(str_starts_with($bare, $riskKey.'.'), 422, "{$riskKey} must be a plain number");
            }

            return;
        }
        [$min, $max, $minExclusive] = self::RISK_RANGES[$bare];
        if (! is_numeric($value) || ! is_finite((float) $value) || ($minExclusive ? $value <= $min : $value < $min) || $value > $max) {
            abort(422, sprintf('%s must be a number %s %s and <= %s', $bare, $minExclusive ? '>' : '>=', $min, $max));
        }
        abort_if($bare === 'size.max_open_positions' && floor((float) $value) !== (float) $value, 422, 'size.max_open_positions must be a whole number');
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
