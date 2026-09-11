<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Ai\StrategyAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['plugin_id' => 'nullable|integer|exists:strategy_plugins,id']);
        $conversation = AgentConversation::create([
            'user_id' => $request->user()?->id,
            'plugin_id' => $data['plugin_id'] ?? null,
            'messages' => [],
            'phase' => 1,
        ]);

        return response()->json($conversation, 201);
    }

    public function turn(Request $request, AgentConversation $conversation, StrategyAgent $agent): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|max:8000']);

        return response()->json($agent->turn($conversation->id, $data['message']));
    }

    public function show(AgentConversation $conversation): JsonResponse
    {
        return response()->json($conversation);
    }
}
