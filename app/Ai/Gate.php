<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exceptions\AiGateException;
use App\Models\AiCall;
use App\Models\AiConnection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one choke point every model call passes through. ensureAllowed() runs
 * before the HTTP request, record() after it, whichever way it went.
 *
 * record() is handed metadata only. Prompts, completions and tool arguments
 * never reach it, so there is nowhere in ai_calls for them to leak to.
 */
final class Gate
{
    private const ERROR_MAX = 2000;

    /** @throws AiGateException when the caller is over budget or suspended */
    public function ensureAllowed(string $userKey): void
    {
        $minute = 'ai:rate:minute:'.$userKey;
        $day = 'ai:rate:day:'.$userKey;

        if (RateLimiter::tooManyAttempts($minute, (int) config('ai.rate_limit.per_minute', 20))) {
            throw new AiGateException(
                AiGateException::RATE_LIMITED,
                'Too many AI calls this minute. Try again in '.RateLimiter::availableIn($minute).'s.',
            );
        }

        if (RateLimiter::tooManyAttempts($day, (int) config('ai.rate_limit.per_day', 500))) {
            throw new AiGateException(
                AiGateException::RATE_LIMITED,
                'Daily AI call budget spent. It resets in '.RateLimiter::availableIn($day).'s.',
            );
        }

        $suspendedUntil = AiConnection::activeFor($userKey)?->suspended_until;
        if ($suspendedUntil !== null && $suspendedUntil->isFuture()) {
            throw new AiGateException(
                AiGateException::SUSPENDED,
                'AI access is paused until '.$suspendedUntil->toDateTimeString().' after repeated upstream errors.',
            );
        }

        RateLimiter::hit($minute, 60);
        RateLimiter::hit($day, 86400);
    }

    /** @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, cost_usd?: float}  $usage */
    public function record(string $userKey, string $model, string $status, ?string $error, int $durationMs, array $usage = []): void
    {
        AiCall::create([
            'user_id' => $userKey,
            'model' => $model,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'cost_usd' => $usage['cost_usd'] ?? null,
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, self::ERROR_MAX),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);

        if ($status === 'error') {
            $this->suspendIfAbusive($userKey);
        }
    }

    /**
     * A key that keeps failing upstream is either misconfigured or being hammered;
     * either way the desk stops spending it for an hour.
     */
    private function suspendIfAbusive(string $userKey): void
    {
        $errors = AiCall::where('user_id', $userKey)
            ->where('status', 'error')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($errors >= (int) config('ai.abuse.errors_per_hour', 50)) {
            AiConnection::activeFor($userKey)?->update(['suspended_until' => now()->addHour()]);
        }
    }
}
