<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One probability-shaped ask sent to a decision model. Wire type is always "noul": this
 * codebase asks Jev yes/no-shaped questions about whether something matches a stated
 * intent, never a viability, score, or numeric-performance question, and there is no
 * case here for anything else.
 */
final readonly class DecisionQuestion
{
    public function __construct(public string $instructions) {}
}
