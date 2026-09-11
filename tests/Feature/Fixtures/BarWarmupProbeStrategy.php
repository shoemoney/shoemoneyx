<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Position;

/**
 * Test-only strategy: opens no positions, ever. On the very first SCAN call (the backtest's first
 * step, $ctx->now() === $from) it records how many closed 1H bars are visible through the same
 * fetch shape MeanReversionStrategy::trendOk() uses for a trend gate
 * of $need bars: $ctx->bars($pid, '1H', $now - ($need + 2) * 3600, $now - 3600). Isolates the
 * Backtester's own 1H warmup preload from any real strategy's gating logic.
 */
class BarWarmupProbeStrategy implements Strategy
{
    public ?int $barsAtFirstStep = null;

    private bool $checked = false;

    public function __construct(private readonly string $productId = 'BTC-USD', private readonly int $need = 200) {}

    public function key(): string
    {
        return 'bar_warmup_probe_test';
    }

    public function name(): string
    {
        return 'Bar Warmup Probe (test fixture)';
    }

    public function defaults(): array
    {
        return [];
    }

    public function scan(array $universe, DeskContext $ctx): array
    {
        if (! $this->checked) {
            $this->checked = true;
            $now = $ctx->now()->getTimestamp();
            $bars = $ctx->bars($this->productId, '1H', $now - ($this->need + 2) * 3600, $now - 3600);
            $this->barsAtFirstStep = count($bars);
        }

        return [];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::reject($candidate, 'never_called', 'bar warmup probe never proposes candidates', []);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, 0.0, 0.0, 0.0, false, false, 'bar warmup probe never sizes');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
