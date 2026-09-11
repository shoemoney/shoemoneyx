<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON schema object, e.g. ['type'=>'object','properties'=>[...],'required'=>[...]]. */
    public function parameters(): array;

    /** @return array<string,mixed> JSON-serializable result — becomes the tool message content. */
    public function run(array $args, AgentContext $context): array;
}
