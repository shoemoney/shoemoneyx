<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Hub\HubClient;
use App\Hub\HubConnection;
use App\Hub\HubException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HubAccountController extends Controller
{
    public function register(Request $request, HubClient $client): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'handle' => 'required|string',
            'password' => 'required|string|min:8',
            'desk_name' => 'nullable|string',
        ]);

        $deskName = $data['desk_name'] ?? (gethostname() ?: 'desk');

        try {
            $response = $client->register($data['email'], $data['handle'], $data['password'], $deskName);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->code], $e->code === 'validation' ? 422 : 502);
        }

        $handle = $response['user']['handle'] ?? $data['handle'];

        HubConnection::create([
            'user_handle' => $handle,
            'desk_id' => null,
            'token' => $response['token'] ?? null,
            'connected_at' => now(),
        ]);

        return response()->json(['connected' => true, 'handle' => $handle, 'hub_url' => config('hub.url')]);
    }

    public function login(Request $request, HubClient $client): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'desk_name' => 'nullable|string',
        ]);

        $deskName = $data['desk_name'] ?? (gethostname() ?: 'desk');

        try {
            $response = $client->login($data['email'], $data['password'], $deskName);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->code], $e->code === 'validation' ? 422 : 502);
        }

        $handle = $response['user']['handle'] ?? null;

        HubConnection::create([
            'user_handle' => $handle,
            'desk_id' => null,
            'token' => $response['token'] ?? null,
            'connected_at' => now(),
        ]);

        return response()->json(['connected' => true, 'handle' => $handle, 'hub_url' => config('hub.url')]);
    }

    public function link(Request $request, HubClient $client): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
            'desk_name' => 'nullable|string',
        ]);

        $deskName = $data['desk_name'] ?? (gethostname() ?: 'desk');

        try {
            $response = $client->link($data['code'], $deskName);
        } catch (HubException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->code], $e->code === 'validation' ? 422 : 502);
        }

        $handle = $response['user']['handle'] ?? null;

        HubConnection::create([
            'user_handle' => $handle,
            'desk_id' => null,
            'token' => $response['token'] ?? null,
            'connected_at' => now(),
        ]);

        return response()->json(['connected' => true, 'handle' => $handle, 'hub_url' => config('hub.url')]);
    }

    public function status(HubClient $client): JsonResponse
    {
        return response()->json([
            'connected' => $client->connected(),
            'handle' => HubConnection::active()?->user_handle,
            'hub_url' => config('hub.url'),
        ]);
    }

    public function disconnect(): JsonResponse
    {
        $connection = HubConnection::active();
        $connection?->update(['revoked_at' => now()]);

        return response()->json(['connected' => false]);
    }
}
