<?php

declare(strict_types=1);

namespace App\Ai;

/** Mutable-where-needed value object passed to every Tool::run() during one agent turn. */
final class AgentContext
{
    public function __construct(
        public readonly int $conversationId,
        public ?int $pluginId = null,
        public int $phase = 1,
        public readonly ?string $userEmail = null,
    ) {}
}
