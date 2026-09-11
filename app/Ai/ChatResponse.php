<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class ChatResponse
{
    /**
     * @param  list<array{id: string, name: string, arguments: array}>  $toolCalls
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, cost_usd?: float}  $usage
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public string $model,
        public array $usage = [],
        public ?string $finishReason = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
