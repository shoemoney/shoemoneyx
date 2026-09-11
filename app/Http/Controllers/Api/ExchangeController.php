<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exchange\ConformanceStatus;
use App\Exchange\ExchangeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Backs the exchange directory page (resources/js/pages/Exchanges.vue) — see docs/EXCHANGES.md. */
class ExchangeController extends Controller
{
    /** Every registered adapter (native and enabled ccxt), its capabilities, and its conformance badge. */
    public function index(ExchangeRegistry $registry): JsonResponse
    {
        $native = array_keys((array) config('exchanges.drivers', []));
        $activeId = (string) config('exchanges.active', 'coinbase');

        $rows = array_map(function (string $id) use ($registry, $native, $activeId) {
            $row = [
                'id' => $id,
                'name' => $id,
                'kind' => in_array($id, $native, true) ? 'native' : 'ccxt',
                'capabilities' => null,
                'conformance' => ConformanceStatus::for($id),
                'active' => $id === $activeId,
            ];

            try {
                $exchange = $registry->make($id);
                $caps = $exchange->capabilities();
                $row['name'] = $exchange->name();
                $row['capabilities'] = [
                    'spot' => $caps->spot,
                    'perps' => $caps->perps,
                    'websocket' => $caps->websocket,
                    'historical_trades' => $caps->historicalTrades,
                    'shorts' => $caps->shorts,
                ];
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }

            return $row;
        }, $registry->all());

        return response()->json(['data' => $rows]);
    }
}
