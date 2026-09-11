<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\Position;
use App\Services\Market\CandleStore;
use Illuminate\Support\Arr;

/**
 * Per-cycle context handed to every strategy call: merged parameters
 * (config/desk.php -> strategy defaults -> dashboard overrides), the mode,
 * degraded flags, open positions, and a "now" that backtests can rewind.
 */
final class DeskContext
{
    /** @var array<string, mixed> */
    private array $params;

    public function __construct(
        array $params,
        public readonly string $mode,
        public readonly bool $degraded = false,
        /** @var array<int, Position> */
        public readonly array $openPositions = [],
        public readonly ?\DateTimeImmutable $now = null,
        public readonly bool $backtest = false,
        /** Optional bar source (pid, timeframe, fromUnix, toUnix) => bars; backtests inject preloaded history. */
        private readonly ?\Closure $barsProvider = null,
    ) {
        $this->params = $params;
    }

    /** Bars oldest -> newest for a pair/timeframe, from the injected provider or the candle store. */
    public function bars(string $productId, string $timeframe, int $fromUnix, ?int $toUnix = null): array
    {
        if ($this->barsProvider !== null) {
            return ($this->barsProvider)($productId, $timeframe, $fromUnix, $toUnix ?? $this->now()->getTimestamp());
        }

        return app(CandleStore::class)->bars($productId, $timeframe, $fromUnix, $toUnix);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->params, $key, $default);
    }

    public function params(): array
    {
        return $this->params;
    }

    /** @var array<string, self> */
    private array $perProduct = [];

    /**
     * Context for one coin: global params with per_product.<PID> overrides merged on top
     * (per_product.BTC-USD.mr.timeframe = 2m, per_product.BTC-USD.mr.entry_z = 2.5 …).
     * Every strategy hook that looks at a specific product should read params through this.
     */
    public function forProduct(string $productId): self
    {
        $o = $this->params['per_product'][$productId] ?? null;
        if (! is_array($o) || $o === []) {
            return $this;
        }

        return $this->perProduct[$productId] ??= $this->withParams($o);
    }

    public function withParams(array $overrides): self
    {
        return new self(array_replace_recursive($this->params, $overrides), $this->mode, $this->degraded, $this->openPositions, $this->now, $this->backtest, $this->barsProvider);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function hasOpenPosition(string $productId): bool
    {
        foreach ($this->openPositions as $p) {
            if ($p->product_id === $productId) {
                return true;
            }
        }

        return false;
    }

    public function openPosition(string $productId): ?Position
    {
        foreach ($this->openPositions as $p) {
            if ($p->product_id === $productId) {
                return $p;
            }
        }

        return null;
    }
}
