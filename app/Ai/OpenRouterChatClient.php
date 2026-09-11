<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ChatClient;
use App\Ai\Exceptions\AiNoConnectionException;
use App\Models\AiConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Chat completions against the operator's own connected OpenRouter account.
 * Every call is gated and audited; nothing here ever sees a pasted key.
 */
final class OpenRouterChatClient implements ChatClient
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    private const BACKOFF_SECONDS = 30;

    public function __construct(private readonly Gate $gate) {}

    public function chat(array $messages, array $tools = [], ?string $model = null, array $options = []): ChatResponse
    {
        $userKey = CurrentUser::key();
        $this->gate->ensureAllowed($userKey);

        $apiKey = $this->resolveKey($userKey);
        $model ??= $this->defaultModel();

        $body = array_merge($options, ['model' => $model, 'messages' => $messages]);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $startedAt = hrtime(true);
        try {
            $response = $this->post($apiKey, $body);
        } catch (\Throwable $e) {
            $this->gate->record($userKey, $model, 'error', $e->getMessage(), self::elapsedMs($startedAt));
            throw $e;
        }

        $durationMs = self::elapsedMs($startedAt);
        if (! $response->successful()) {
            $this->gate->record($userKey, $model, 'error', 'HTTP '.$response->status().': '.$response->body(), $durationMs);
            throw new \RuntimeException('OpenRouter returned '.$response->status().'.');
        }

        $reply = self::parse($response->json() ?? [], $model);
        $this->gate->record($userKey, $reply->model, 'ok', null, $durationMs, $reply->usage);

        return $reply;
    }

    public function defaultModel(): string
    {
        return (string) config('services.openrouter.default_model', 'openrouter/free');
    }

    /** One retry on 429 and no more: a loop against a rate limit just digs deeper. */
    private function post(string $apiKey, array $body): Response
    {
        $response = $this->request($apiKey, $body);
        if ($response->status() === 429) {
            Sleep::for(self::BACKOFF_SECONDS)->seconds();
            $response = $this->request($apiKey, $body);
        }

        return $response;
    }

    private function request(string $apiKey, array $body): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'shoemoneyx strategy builder',
            'Content-Type' => 'application/json',
        ])->timeout(90)->post(self::ENDPOINT, $body);
    }

    /** @throws AiNoConnectionException when neither a connected account nor the self-host env key exists */
    private function resolveKey(string $userKey): string
    {
        $connection = AiConnection::activeFor($userKey);
        if ($connection !== null) {
            $connection->forceFill(['last_used_at' => now()])->save();

            return $connection->key;
        }

        $envKey = (string) config('services.openrouter.key');
        if ($envKey !== '') {
            return $envKey;
        }

        throw new AiNoConnectionException('No OpenRouter account connected.');
    }

    private static function parse(array $json, string $requestedModel): ChatResponse
    {
        $message = $json['choices'][0]['message'] ?? [];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        $usage = $json['usage'] ?? [];
        $counts = array_filter([
            'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            'cost_usd' => isset($usage['cost']) ? (float) $usage['cost'] : null,
        ], fn ($v) => $v !== null);

        return new ChatResponse(
            content: is_string($message['content'] ?? null) ? $message['content'] : null,
            toolCalls: $toolCalls,
            model: (string) ($json['model'] ?? $requestedModel),
            usage: $counts,
            finishReason: is_string($json['choices'][0]['finish_reason'] ?? null) ? $json['choices'][0]['finish_reason'] : null,
        );
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
