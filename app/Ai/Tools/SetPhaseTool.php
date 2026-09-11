<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;

final class SetPhaseTool implements Tool
{
    public function name(): string
    {
        return 'set_phase';
    }

    public function description(): string
    {
        return 'Advance (or set) the strategy-builder phase, 1 (Setup) through 7 (Review).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'phase' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
            ],
            'required' => ['phase'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        $phase = $args['phase'] ?? null;
        if (! is_int($phase) || $phase < 1 || $phase > 7) {
            return ['error' => 'phase must be 1-7'];
        }

        $context->phase = $phase;

        return ['phase' => $phase];
    }
}
