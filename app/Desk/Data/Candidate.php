<?php

declare(strict_types=1);

namespace App\Desk\Data;

/** SCAN output row. Never carries a buy opinion, target or size. */
final class Candidate
{
    public function __construct(
        public readonly ProductStats $stats,
        public readonly float $score,
        public readonly string $rankReason,
        public int $rank = 0,
        public readonly ?array $context = null,   // {endpoint, line} from WorldMonitor, or null
        public readonly bool $degraded = false,
        /** Strategy's own edge estimate (0..1 win probability) and payoff ratio — consumed by SIZE. */
        public readonly float $edgeProbability = 0.5,
        public readonly float $payoffRatio = 1.5,
        /** long | short. Short candidates are only produced when the strategy allows them (perps). */
        public readonly string $side = 'long',
    ) {}

    public function isShort(): bool
    {
        return $this->side === 'short';
    }

    public function productId(): string
    {
        return $this->stats->productId;
    }
}
