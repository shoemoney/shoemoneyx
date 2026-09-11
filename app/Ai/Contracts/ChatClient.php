<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\ChatResponse;

/**
 * One chat completion against whatever model provider the user connected.
 *
 * $messages follow the OpenAI shape: [{role: system|user|assistant|tool, content, tool_call_id?, tool_calls?}].
 * $tools follow the OpenAI function-tool shape: [{type: 'function', function: {name, description, parameters}}].
 * $model null means the provider's configured default. $options are passed through (temperature, max_tokens...).
 */
interface ChatClient
{
    public function chat(array $messages, array $tools = [], ?string $model = null, array $options = []): ChatResponse;

    /** Model id this client will use when $model is null. */
    public function defaultModel(): string;
}
