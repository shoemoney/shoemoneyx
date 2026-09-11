<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures;

use App\Ai\ChatResponse;
use App\Ai\Contracts\ChatClient;

/** Scriptable fake: shifts one queued ChatResponse per chat() call, records every call's args. */
final class FakeChatClient implements ChatClient
{
    /** @var list<array{messages: array, tools: array, model: ?string, options: array}> */
    public array $calls = [];

    /** @param list<ChatResponse> $responses */
    public function __construct(private array $responses) {}

    public function chat(array $messages, array $tools = [], ?string $model = null, array $options = []): ChatResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools, 'model' => $model, 'options' => $options];

        return array_shift($this->responses) ?? new ChatResponse(content: 'done', toolCalls: [], model: 'fake');
    }

    public function defaultModel(): string
    {
        return 'fake/test';
    }
}
