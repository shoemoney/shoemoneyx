<?php

declare(strict_types=1);

namespace App\Desk\Data;

/** SIZE output: how many dollars. Never whether, never when. */
final class SizeDecision
{
    public function __construct(
        public readonly Verdict $verdict,
        public readonly float $dollars,
        public readonly float $percentOfFreeCash,
        public readonly float $percentOfBank,
        public readonly bool $exitable,
        public readonly bool $ceilingApplied,
        public readonly string $why,
    ) {}

    public function zero(): bool
    {
        return $this->dollars <= 0;
    }
}
