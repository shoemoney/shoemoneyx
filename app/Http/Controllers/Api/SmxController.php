<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Settings;
use App\Http\Controllers\Controller;
use App\Models\Candle;
use App\Services\Indicators\Smx;
use App\Services\Market\ChartCandles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SMX series for the chart panel: /api/smx?product=BTC-USD&tf=1H&bars=300 */
class SmxController extends Controller
{
    public function show(Request $request, ChartCandles $store, Settings $settings): JsonResponse
    {
        $pid = strtoupper((string) $request->input('product', 'BTC-USD'));
        $tf = (string) $request->input('tf', '1H');
        $count = min(2000, max(150, (int) $request->integer('bars', 400)));
        $dur = Candle::DURATIONS[$tf] ?? abort(422, 'bad timeframe');

        $bars = $store->bars($pid, $tf, time() - ($count + 5) * $dur, time());
        $params = (array) $settings->get('smx.indicator', []);
        $c = Smx::compute($bars, $params + Smx::DEFAULTS);

        $pick = fn (array $s) => array_map(fn ($v) => $v === null ? null : (is_bool($v) ? $v : round((float) $v, 4)), $s);
        $out = [
            'product' => $pid,
            'tf' => $tf,
            't' => array_column($bars, 'start'),
            'close' => array_column($bars, 'close'),
            'wt1' => $pick($c['wt1']),
            'wt2' => $pick($c['wt2']),
            'wt_vwap' => $pick($c['wt_vwap']),
            'rsi_mfi' => $pick($c['rsi_mfi']),
            'rsi' => $pick($c['rsi']),
            'stoch_k' => $pick($c['stoch_k']),
            'stoch_d' => $pick($c['stoch_d']),
            'stc' => $pick($c['stc']),
            'signals' => $c['signals'],
            'latest' => Smx::latest($c),
            'levels' => ['ob' => $params['ob_level'] ?? 53, 'ob2' => $params['ob_level2'] ?? 60, 'os' => $params['os_level'] ?? -53, 'os2' => $params['os_level2'] ?? -60, 'os3' => $params['os_level3'] ?? -75],
        ];

        return response()->json($out);
    }
}
