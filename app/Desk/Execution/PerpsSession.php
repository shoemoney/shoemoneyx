<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** A snapshot of CFM's margin window + balance summary, read together so the gate sees one consistent moment. */
final readonly class PerpsSession
{
    public function __construct(
        public MarginWindow $window,
        public ?\DateTimeImmutable $windowEndsAt,
        public float $futuresBuyingPower,
        public float $initialMargin,
        public float $availableMargin,
        public ?float $liquidationBufferPct,
    ) {}

    /**
     * @param  array  $balanceSummary  GET /cfm/balance_summary → balance_summary
     * @param  array  $marginWindow  GET /cfm/intraday/current_margin_window → margin_window
     */
    public static function fromApi(array $balanceSummary, array $marginWindow): self
    {
        $value = static fn (?array $field): float => (float) ($field['value'] ?? 0);

        $endsAt = null;
        if (isset($marginWindow['end_time'])) {
            try {
                $endsAt = new \DateTimeImmutable((string) $marginWindow['end_time']);
            } catch (\Exception) {
                $endsAt = null;
            }
        }

        return new self(
            window: MarginWindow::fromApi($marginWindow['margin_window_type'] ?? null),
            windowEndsAt: $endsAt,
            futuresBuyingPower: $value($balanceSummary['futures_buying_power'] ?? null),
            initialMargin: $value($balanceSummary['initial_margin'] ?? null),
            availableMargin: $value($balanceSummary['available_margin'] ?? null),
            liquidationBufferPct: self::scalar($balanceSummary['liquidation_buffer_percentage'] ?? null),
        );
    }

    /** CFM sends this field as a bare string ("1000") on REST and as {value} on the websocket; accept both. */
    private static function scalar(mixed $field): ?float
    {
        if (is_array($field)) {
            $field = $field['value'] ?? null;
        }

        return is_numeric($field) ? (float) $field : null;
    }

    public function secondsToWindowEnd(\DateTimeImmutable $now): ?int
    {
        return $this->windowEndsAt === null ? null : $this->windowEndsAt->getTimestamp() - $now->getTimestamp();
    }
}
