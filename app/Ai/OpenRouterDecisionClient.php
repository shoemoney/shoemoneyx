<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\DecisionClient;
use Illuminate\Support\Facades\Http;

/**
 * Decisions (TypeSafe Jev today) against the operator's own connected OpenRouter
 * account. Every call is gated and audited like chat. Unlike OpenRouterChatClient
 * there is no 429 retry and no sleep: a decision sits inside a user-facing save,
 * and a background advisory that sleeps 30 seconds to retry is worse than none.
 */
final class OpenRouterDecisionClient implements DecisionClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/alpha/decisions';

    public function __construct(private readonly Gate $gate, private readonly OpenRouterKey $keys) {}

    public function decide(string $userKey, array $state, array $questions, string $model): DecisionResponse
    {
        // Resolved before the gate: a keyless caller burns no rate budget for a call it
        // was never going to be allowed to make anyway.
        $apiKey = $this->keys->require($userKey);
        $this->gate->ensureAllowed($userKey);

        $body = ['model' => $model, 'state' => $state, 'questions' => self::encode($questions)];

        $startedAt = hrtime(true);
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'shoemoneyx strategy builder',
                'Content-Type' => 'application/json',
            ])->timeout((int) config('ai.intent_lint.timeout_seconds', 3))->post(self::ENDPOINT, $body);
        } catch (\Throwable $e) {
            $this->gate->record($userKey, $model, 'error', $e->getMessage(), self::elapsedMs($startedAt));
            throw $e;
        }

        $durationMs = self::elapsedMs($startedAt);
        if (! $response->successful()) {
            $bodySnippet = mb_substr($response->body(), 0, 500);
            $this->gate->record($userKey, $model, 'error', 'HTTP '.$response->status().': '.$response->body(), $durationMs);
            throw new \RuntimeException('OpenRouter decisions returned '.$response->status().' for model '.$model.': '.$bodySnippet);
        }

        $reply = self::parse($response->json() ?? [], $model);
        $this->gate->record($userKey, $reply->model, 'ok', null, $durationMs, $reply->usage);

        return $reply;
    }

    /** Domain questions to the wire shape {id: {type: noul, instructions}}. The only place this wire shape exists. */
    private static function encode(array $questions): array
    {
        $wire = [];
        foreach ($questions as $id => $question) {
            $wire[$id] = ['type' => 'noul', 'instructions' => $question->instructions];
        }

        return $wire;
    }

    /**
     * Wire answers to domain probabilities. A missing id, a non-noul type, or a
     * non-numeric or out-of-range noul is dropped rather than thrown: it is what
     * DecisionResponse::probability() returns null for, and the caller's own
     * fail-soft policy takes it from there.
     */
    private static function parse(array $json, string $requestedModel): DecisionResponse
    {
        $probabilities = [];
        foreach ($json['answers'] ?? [] as $id => $answer) {
            if (! is_array($answer) || ($answer['type'] ?? null) !== 'noul' || ! is_numeric($answer['noul'] ?? null)) {
                continue;
            }
            $p = (float) $answer['noul'];
            if ($p >= 0.0 && $p <= 1.0) {
                $probabilities[$id] = $p;
            }
        }

        return new DecisionResponse(
            probabilities: $probabilities,
            model: (string) ($json['model'] ?? $requestedModel),
            usage: self::usage($json['usage'] ?? []),
        );
    }

    /** Jev bills input_tokens/output_tokens/cost; Gate::record reads prompt_tokens/completion_tokens/cost_usd. */
    private static function usage(array $usage): array
    {
        return array_filter([
            'prompt_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            'completion_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            'total_tokens' => isset($usage['input_tokens'], $usage['output_tokens'])
                ? (int) $usage['input_tokens'] + (int) $usage['output_tokens']
                : null,
            'cost_usd' => isset($usage['cost']) ? (float) $usage['cost'] : null,
        ], fn ($v) => $v !== null);
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
