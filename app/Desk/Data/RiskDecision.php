<?php

declare(strict_types=1);

namespace App\Desk\Data;

/** RISK output. CLOSE is immediate and full. */
final class RiskDecision
{
    public const HOLD = 'HOLD';

    public const CLOSE = 'CLOSE';

    public const TRIM = 'TRIM';

    /** Re-entry after a take-profit: buys back part of a fired rung's sold slice. */
    public const ADD = 'ADD';

    public function __construct(
        public readonly string $action,
        public readonly ?string $ruleFired,
        public readonly ?float $volume6h,
        public readonly ?float $avg6h,
        public readonly ?float $ratio,
        public readonly string $why = '',
        public readonly array $meta = [],
        /** TRIM only: fraction of the current quantity to sell (0..1). */
        public readonly ?float $fraction = null,
        /** TRIM only: the limit price the rung was set at (backtests fill here when the bar trades through it). */
        public readonly ?float $limitPrice = null,
        /** ADD only: dollar notional to buy, booked through the same fill path an ordinary add uses. */
        public readonly ?float $dollars = null,
    ) {}

    public function shouldTrim(): bool
    {
        return $this->action === self::TRIM && $this->fraction > 0;
    }

    public static function trim(string $rule, float $fraction, ?float $limitPrice, string $why = '', array $meta = []): self
    {
        return new self(self::TRIM, $rule, null, null, null, $why, $meta, min(1.0, max(0.0, $fraction)), $limitPrice);
    }

    public function shouldAdd(): bool
    {
        return $this->action === self::ADD && $this->dollars > 0;
    }

    /** $price is the price the strategy priced the add at (informational — the caller fills at the current mark, like any other add). */
    public static function add(string $rule, float $dollars, ?float $price, string $why = '', array $meta = []): self
    {
        return new self(self::ADD, $rule, null, null, null, $why, $meta, null, $price, max(0.0, $dollars));
    }

    public function shouldClose(): bool
    {
        return $this->action === self::CLOSE;
    }

    public static function hold(?float $v6 = null, ?float $avg = null, ?float $ratio = null, string $why = 'holding'): self
    {
        return new self(self::HOLD, null, $v6, $avg, $ratio, $why);
    }

    public static function close(string $rule, ?float $v6 = null, ?float $avg = null, ?float $ratio = null, string $why = '', array $meta = []): self
    {
        return new self(self::CLOSE, $rule, $v6, $avg, $ratio, $why, $meta);
    }
}
