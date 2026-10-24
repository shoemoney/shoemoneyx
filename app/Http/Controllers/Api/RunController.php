<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Desk\Desk;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Fill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController extends Controller
{
    public function index(Request $request, Desk $desk): JsonResponse
    {
        return response()->json(DeskRun::where('mode', $request->input('mode', $desk->mode()))->seat($this->seatId($request))->orderByDesc('started_at')->paginate((int) $request->integer('per_page', 25)));
    }

    public function show(DeskRun $run): JsonResponse
    {
        return response()->json($run->load('candidates', 'fills'));
    }

    public function latest(Request $request, Desk $desk): JsonResponse
    {
        $run = DeskRun::where('mode', $desk->mode())->seat($this->seatId($request))->orderByDesc('started_at')->first();

        return response()->json($run?->load('candidates', 'fills'));
    }

    public function candidates(Request $request, Desk $desk): JsonResponse
    {
        $seatId = $this->seatId($request);
        $mode = $request->input('mode', $desk->mode());
        $q = Candidate::whereHas('run', fn ($query) => $query->where('mode', $mode)->seat($seatId))->orderByDesc('id');
        if ($v = $request->input('verdict')) {
            $q->where('verdict', $v);
        }
        if ($p = $request->input('product')) {
            $q->where('product_id', strtoupper((string) $p));
        }

        return response()->json($q->paginate((int) $request->integer('per_page', 50)));
    }

    public function fills(Request $request, Desk $desk): JsonResponse
    {
        return response()->json(Fill::where('mode', $request->input('mode', $desk->mode()))->seat($this->seatId($request))->orderByDesc('id')->paginate((int) $request->integer('per_page', 50)));
    }

    private function seatId(Request $request): ?int
    {
        $scope = $request->validate(['arena_seat_id' => ['nullable', 'integer', 'min:1', 'exists:arena_seats,id']]);

        return isset($scope['arena_seat_id']) ? (int) $scope['arena_seat_id'] : null;
    }

    public function events(Request $request): JsonResponse
    {
        $q = DeskEvent::orderByDesc('id');
        if ($a = $request->input('agent')) {
            $q->where('agent', strtoupper((string) $a));
        }
        if ($l = $request->input('level')) {
            $q->where('level', $l);
        }
        if ($after = $request->integer('after')) {
            $q->where('id', '>', $after);
        }

        return response()->json($q->limit((int) $request->integer('limit', 100))->get());
    }
}
