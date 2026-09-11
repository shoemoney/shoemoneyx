<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Http\Controllers\Controller;
use App\Models\BankSnapshot;
use App\Models\DeskRun;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function index(Chief $chief, Desk $desk): JsonResponse
    {
        $health = $chief->health();
        $bank = null;
        $bankError = null;
        try {
            $bank = $desk->bank()->toArray();
        } catch (\Throwable $e) {
            $bankError = $e->getMessage();
        }
        $mode = $health['mode'];

        return response()->json([
            'health' => $health,
            'bank' => $bank,
            'bank_error' => $bankError,
            'open_positions' => Position::open()->mode($mode)->count(),
            'closed_today' => Position::mode($mode)->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())->count(),
            'realised_today' => (float) Position::mode($mode)->where('status', 'closed')->where('closed_at', '>=', now()->startOfDay())->sum('pnl_usd'),
            'realised_total' => (float) Position::mode($mode)->where('status', 'closed')->sum('pnl_usd'),
            'last_run' => DeskRun::where('mode', $mode)->latest('started_at')->first(),
            'live_confirm' => config('desk.live_confirm') === 'yes',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function bankHistory(Request $request, Desk $desk): JsonResponse
    {
        $hours = (int) $request->integer('hours', 72);
        $rows = BankSnapshot::where('mode', $request->input('mode', $desk->mode()))
            ->where('taken_at', '>=', now()->subHours($hours))->orderBy('taken_at')->get(['taken_at', 'equity', 'cash', 'positions_value', 'free_cash']);

        return response()->json($rows);
    }
}
