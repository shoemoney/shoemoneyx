<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exchange\Contracts\Exchange;
use App\Http\Controllers\Controller;
use App\Models\Backtest;
use App\Models\Candle;
use App\Models\Fill;
use App\Models\Product;
use App\Services\Market\ChartCandles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * TradingView UDF datafeed (the private charting_library talks to this).
 * Bars come from bounded local snapshots; ingestion never blocks a chart. Fills are
 * exposed as marks so every entry/exit is drawn on the chart.
 */
class UdfController extends Controller
{
    private const RESOLUTIONS = ['15S' => '15s', '30S' => '30s', '45S' => '45s', '90S' => '90s', '1' => '1m', '2' => '2m', '3' => '3m', '4' => '4m', '5' => '5m', '10' => '10m', '15' => '15m', '30' => '30m', '60' => '1H', '360' => '6H', 'D' => '1D', '1D' => '1D'];

    private const CHART_RESOLUTIONS = ['15S', '1', '5'];

    private readonly string $exchangeId;

    public function __construct(private ChartCandles $store, Exchange $exchange)
    {
        $this->exchangeId = strtoupper($exchange->id());
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'supported_resolutions' => self::CHART_RESOLUTIONS,
            'supports_group_request' => false,
            'supports_marks' => true,
            'supports_search' => true,
            'supports_time' => true,
            'supports_timescale_marks' => false,
            'exchanges' => [['value' => $this->exchangeId, 'name' => 'Coinbase', 'desc' => 'Coinbase Advanced Trade']],
            'symbols_types' => [['name' => 'crypto', 'value' => 'crypto']],
        ]);
    }

    public function time(): Response
    {
        return response((string) time(), 200, ['Content-Type' => 'text/plain']);
    }

    public function search(Request $request): JsonResponse
    {
        $q = strtoupper((string) $request->input('query', ''));
        $limit = min((int) $request->input('limit', 30), 100);
        $rows = Product::tradeable()->where('product_id', 'like', "%{$q}%")->orderByDesc('volume_24h_usd')->limit($limit)->get();

        return response()->json($rows->map(fn (Product $p) => [
            'symbol' => $p->product_id,
            'full_name' => "{$this->exchangeId}:{$p->product_id}",
            'description' => "{$p->base_currency} / {$p->quote_currency}",
            'exchange' => $this->exchangeId,
            'ticker' => $p->product_id,
            'type' => 'crypto',
        ]));
    }

    public function symbols(Request $request): JsonResponse
    {
        $symbol = strtoupper(preg_replace('/^'.preg_quote($this->exchangeId, '/').':/', '', (string) $request->input('symbol', '')));
        $p = Product::where('product_id', $symbol)->first();
        if (! $p) {
            return response()->json(['s' => 'error', 'errmsg' => 'unknown symbol']);
        }
        $price = (float) ($p->price ?? 0);
        $pricescale = $price >= 10000 ? 100 : ($price >= 100 ? 10000 : ($price >= 1 ? 1000000 : 100000000));

        return response()->json([
            'name' => $p->product_id,
            'full_name' => "{$this->exchangeId}:{$p->product_id}",
            'ticker' => $p->product_id,
            'description' => "{$p->base_currency} / {$p->quote_currency}",
            'type' => 'crypto',
            'session' => '24x7',
            'exchange' => $this->exchangeId,
            'listed_exchange' => $this->exchangeId,
            'timezone' => 'Etc/UTC',
            'format' => 'price',
            'pricescale' => $pricescale,
            'minmov' => 1,
            'has_intraday' => true,
            'has_daily' => false,
            'has_weekly_and_monthly' => false,
            'has_empty_bars' => false,
            'volume_precision' => 4,
            'supported_resolutions' => self::CHART_RESOLUTIONS,
            'intraday_multipliers' => ['1', '5'],
            'has_seconds' => true,
            'seconds_multipliers' => ['15'],
            'data_status' => 'streaming',
            'currency_code' => $p->quote_currency,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $symbol = strtoupper(preg_replace('/^'.preg_quote($this->exchangeId, '/').':/', '', (string) $request->input('symbol', '')));
        $tf = self::RESOLUTIONS[(string) $request->input('resolution', '1')] ?? '1m';
        $from = (int) $request->input('from', time() - 60 * 600);
        $to = (int) $request->input('to', time());
        $countback = min(ChartCandles::MAX_BARS, max(0, (int) $request->input('countback', 0)));
        $dur = Candle::DURATIONS[$tf];

        if ($countback > 0) {
            $from = min($from, $to - $countback * $dur);
        }

        $bars = $this->store->bars($symbol, $tf, $from, $to);
        if ($bars === []) {
            $prev = Candle::for($symbol, $this->store->sourceTimeframe($tf))->where('candle_start', '<', gmdate('Y-m-d H:i:s', $from))->max('candle_start');

            return response()->json(['s' => 'no_data', 'nextTime' => $prev ? strtotime((string) $prev.' UTC') : null]);
        }

        return response()->json([
            's' => 'ok',
            't' => array_column($bars, 'start'),
            'o' => array_column($bars, 'open'),
            'h' => array_column($bars, 'high'),
            'l' => array_column($bars, 'low'),
            'c' => array_column($bars, 'close'),
            'v' => array_column($bars, 'volume'),
        ]);
    }

    /**
     * Entries and exits as chart marks. Live/paper fills by default; pass
     * ?backtest_id=N to draw that backtest's simulated trades instead
     * (used by the strategy builder to show results on the TradingView chart).
     */
    public function marks(Request $request): JsonResponse
    {
        $symbol = strtoupper(preg_replace('/^'.preg_quote($this->exchangeId, '/').':/', '', (string) $request->input('symbol', '')));
        $from = (int) $request->input('from', 0);
        $to = (int) $request->input('to', time());

        $out = ['id' => [], 'time' => [], 'color' => [], 'text' => [], 'label' => [], 'labelFontColor' => [], 'minSize' => []];

        $backtestId = $request->input('backtest_id');
        if ($backtestId !== null && $backtestId !== '') {
            $bt = Backtest::find($backtestId);
            foreach ($bt?->trades ?? [] as $i => $t) {
                if (($t['product'] ?? '') !== $symbol) {
                    continue;
                }
                foreach ([['opened_at', $t['entry'] ?? null, 'B', 'green'], ['closed_at', $t['exit'] ?? null, 'S', 'red']] as [$tsKey, $px, $label, $color]) {
                    $ts = isset($t[$tsKey]) ? strtotime((string) $t[$tsKey]) : false;
                    if ($ts === false || $ts < $from || $ts > $to) {
                        continue;
                    }
                    $out['id'][] = "bt{$backtestId}-{$i}-{$label}";
                    $out['time'][] = $ts;
                    $out['color'][] = $color;
                    $out['text'][] = sprintf('backtest #%s %s %s @ %s (%s)', $backtestId, $label === 'B' ? 'entry' : 'exit', $t['product'], $px ?? '?', $t['rule'] ?? '?');
                    $out['label'][] = $label;
                    $out['labelFontColor'][] = 'white';
                    $out['minSize'][] = 14;
                }
            }

            return response()->json($out);
        }

        $fills = Fill::where('product_id', $symbol)->where('status', 'filled')
            ->whereBetween('created_at', [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)])->orderBy('id')->get();

        foreach ($fills as $f) {
            $out['id'][] = $f->id;
            $out['time'][] = $f->created_at->getTimestamp();
            $out['color'][] = $f->side === 'BUY' ? 'green' : 'red';
            $out['text'][] = sprintf('%s %s $%.2f @ %s (%s) slip %s bps', $f->side, $f->kind, $f->filled_usd, $f->fill_price, $f->mode, $f->slippage_bps ?? '—');
            $out['label'][] = $f->side === 'BUY' ? 'B' : 'S';
            $out['labelFontColor'][] = 'white';
            $out['minSize'][] = 14;
        }

        return response()->json($out);
    }
}
