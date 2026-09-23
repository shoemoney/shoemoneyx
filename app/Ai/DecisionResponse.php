<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One decision call's answers, keyed by the question id the caller sent. The
 * probability lives here and only here: callers extract it once through
 * probability(), compare it once, and are expected to discard the float rather
 * than pass it on. See App\Desk\Strategies\DefinitionCheck.
 */
final readonly class DecisionResponse
{
    /**
     * @param  array<string, float>  $probabilities  keyed by question id, noul answers only
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, cost_usd?: float}  $usage
     */
    public function __construct(
        public array $probabilities,
        public string $model,
        public array $usage = [],
    ) {}

    /** Null when the id is absent: no answer, a non-noul answer, or an unparseable one. */
    public function probability(string $id): ?float
    {
        return $this->probabilities[$id] ?? null;
    }
}
