<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Desk;
use App\Desk\Execution\ExecutionModeMismatchException;
use App\Http\Controllers\Controller;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function index(Request $request, Desk $desk, MarketData $market): JsonResponse
    {
        $status = $request->input('status', 'open');
        $q = Position::mode($request->input('mode', $desk->mode()))->orderByDesc('opened_at');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        $rows = $q->limit((int) $request->integer('limit', 100))->get();

        $rows->transform(function (Position $p) use ($market) {
            $px = $p->status === 'open' ? ($market->price($p->product_id) ?? $p->last_price) : $p->exit_price;
            $p->setAttribute('mark_price', $px);
            $p->setAttribute('unrealised_pnl', $p->status === 'open' && $px ? round($p->unrealisedPnl((float) $px), 4) : null);
            $p->setAttribute('unrealised_pnl_pct', $p->status === 'open' && $px ? round($p->unrealisedPnlPct((float) $px), 4) : null);
            $p->setAttribute('held_minutes', $p->heldMinutes());

            return $p;
        });

        return response()->json($rows);
    }

    public function show(Position $position): JsonResponse
    {
        return response()->json($position->load(['fills', 'riskChecks' => fn ($q) => $q->latest()->limit(200)]));
    }

    public function close(Request $request, Position $position, Desk $desk, MarketData $market): JsonResponse
    {
        abort_if($position->status !== 'open', 422, 'position already closed');

        // The desk's global mode can differ from the mode this position was opened under (the position
        // list intentionally lets you browse other modes). Closing must never silently route a paper
        // position to the live executor or vice versa — require the caller to explicitly confirm the
        // position's own mode before closing across a mode boundary. Desk::close() enforces the same
        // rule again below regardless of what happens here.
        if ($position->mode !== $desk->mode() && $request->input('mode') !== $position->mode) {
            throw new ExecutionModeMismatchException(sprintf(
                'position %s is %s-mode but the desk is currently %s-mode; pass mode=%s to confirm closing it in its own mode',
                $position->product_id, $position->mode, $desk->mode(), $position->mode
            ));
        }

        $px = $market->price($position->product_id) ?? (float) $position->last_price;
        $fill = $desk->close($position, 'manual', (float) $px);

        return response()->json(['position' => $position->fresh(), 'fill' => $fill]);
    }
}
