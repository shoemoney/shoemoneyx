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
        return response()->json(DeskRun::where('mode', $request->input('mode', $desk->mode()))->orderByDesc('started_at')->paginate((int) $request->integer('per_page', 25)));
    }

    public function show(DeskRun $run): JsonResponse
    {
        return response()->json($run->load('candidates', 'fills'));
    }

    public function latest(Desk $desk): JsonResponse
    {
        $run = DeskRun::where('mode', $desk->mode())->orderByDesc('started_at')->first();

        return response()->json($run?->load('candidates', 'fills'));
    }

    public function candidates(Request $request): JsonResponse
    {
        $q = Candidate::orderByDesc('id');
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
        return response()->json(Fill::where('mode', $request->input('mode', $desk->mode()))->orderByDesc('id')->paginate((int) $request->integer('per_page', 50)));
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
