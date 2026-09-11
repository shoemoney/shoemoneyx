<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Ai\Contracts\ChatClient;
use App\Ai\CurrentUser;
use App\Ai\OpenRouterOAuth;
use App\Http\Controllers\Controller;
use App\Models\AiCall;
use App\Models\AiConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    /** OpenRouter's own auto-router across free models — synthetic, not in /models. */
    private const FREE_ROUTER = 'openrouter/free';

    /**
     * What the builder needs to decide whether to offer "Connect OpenRouter".
     * A self-hoster who set OPENROUTER_API_KEY counts as connected without an
     * AiConnection row, so there is no label and no live key lookup to make.
     */
    public function status(OpenRouterOAuth $oauth, ChatClient $client): JsonResponse
    {
        $connection = AiConnection::activeFor(CurrentUser::key());
        $envKey = (string) config('services.openrouter.key');
        $defaultModel = $client->defaultModel();

        return response()->json([
            'connected' => $connection !== null || $envKey !== '',
            'provider' => 'openrouter',
            'label' => $connection?->label,
            'default_model' => $defaultModel,
            'key_limit_remaining' => $connection === null ? null : self::limitRemaining($oauth, $connection),
            'nudge' => [
                'show' => $defaultModel === 'openrouter/free' || str_ends_with($defaultModel, ':free'),
                'model' => (string) config('ai.nudge.model'),
                'reason' => (string) config('ai.nudge.reason'),
            ],
        ]);
    }

    /**
     * The builder's model picker: every zero-priced model OpenRouter currently
     * lists, plus the curated `ai.recommended` ids. Cached an hour, same as the
     * legacy free-only list on /strategy-models.
     */
    public function models(): JsonResponse
    {
        $catalog = Cache::remember('ai.models.free_and_recommended', 3600, function () {
            $response = Http::timeout(20)->get('https://openrouter.ai/api/v1/models');
            if (! $response->successful()) {
                return null;
            }

            $live = [];
            foreach ($response->json('data', []) as $m) {
                $live[$m['id']] = ['id' => $m['id'], 'name' => $m['name'] ?? $m['id'], 'pricing' => $m['pricing'] ?? null];
            }

            $free = array_values(array_filter(
                $live,
                fn ($m) => ($m['pricing']['prompt'] ?? null) === '0' && ($m['pricing']['completion'] ?? null) === '0',
            ));
            usort($free, fn ($a, $b) => strcmp($a['id'], $b['id']));

            if (! in_array(self::FREE_ROUTER, array_column($free, 'id'), true)) {
                array_unshift($free, ['id' => self::FREE_ROUTER, 'name' => 'openrouter/free (auto free router)', 'pricing' => null]);
            }

            // The config list decides what is recommended; a live-lookup miss costs the
            // entry its pricing, never its place in the picker.
            $recommended = array_map(
                fn (string $id) => $live[$id] ?? ['id' => $id, 'name' => $id, 'pricing' => null],
                array_values((array) config('ai.recommended', [])),
            );

            return ['free' => $free, 'recommended' => $recommended];
        });

        if ($catalog === null) {
            return response()->json(['error' => 'OpenRouter unreachable — paste any model id manually.'], 502);
        }

        return response()->json($catalog);
    }

    /** Today's spend for the current operator, straight off the audit log. */
    public function usage(): JsonResponse
    {
        $today = AiCall::query()
            ->where('user_id', CurrentUser::key())
            ->where('created_at', '>=', now()->startOfDay());

        return response()->json([
            'calls' => (int) $today->clone()->count(),
            'prompt_tokens' => (int) $today->clone()->sum('prompt_tokens'),
            'completion_tokens' => (int) $today->clone()->sum('completion_tokens'),
            'cost_usd' => round((float) $today->clone()->sum('cost_usd'), 6),
        ]);
    }

    public function disconnect(): JsonResponse
    {
        AiConnection::activeFor(CurrentUser::key())?->update(['revoked_at' => now()]);

        return response()->json(['connected' => false]);
    }

    private static function limitRemaining(OpenRouterOAuth $oauth, AiConnection $connection): ?float
    {
        $remaining = $oauth->fetchKeyInfo($connection->key)['limit_remaining'] ?? null;

        return is_numeric($remaining) ? (float) $remaining : null;
    }
}
