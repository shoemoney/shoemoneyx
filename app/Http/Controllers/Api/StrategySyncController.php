<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Strategies\Sync\StrategySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StrategySyncController extends Controller
{
    private const CACHE_KEY = 'strategies.sync.status';

    /** Cached check() — cheap enough for the builder to call on every page load. */
    public function status(StrategySync $sync): JsonResponse
    {
        try {
            $result = Cache::remember(self::CACHE_KEY, $this->ttl(), fn () => $sync->check());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    /** Forces a fresh check and refreshes the cache status() reads. */
    public function check(StrategySync $sync): JsonResponse
    {
        try {
            $result = $sync->check();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        Cache::put(self::CACHE_KEY, $result, $this->ttl());

        return response()->json($result);
    }

    public function import(Request $request, StrategySync $sync): JsonResponse
    {
        $data = $request->validate(['remote_id' => 'required|string']);

        try {
            $result = $sync->import($data['remote_id']);
        } catch (\RuntimeException $e) {
            return response()->json(['imported' => false, 'error' => $e->getMessage()], 502);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json($result, $result['imported'] ? 201 : 422);
    }

    public function importAll(StrategySync $sync): JsonResponse
    {
        try {
            $results = $sync->importAll();
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        Cache::forget(self::CACHE_KEY);

        return response()->json(['results' => $results]);
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('strategies.check_interval_minutes', 360));
    }
}
