<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** CFM's margin-window states (GET /cfm/intraday/current_margin_window). Unknown covers an unreadable response. */
enum MarginWindow: string
{
    case Intraday = 'intraday';
    case Overnight = 'overnight';
    case Weekend = 'weekend';
    case Transition = 'transition';
    case Unknown = 'unknown';

    public static function fromApi(?string $type): self
    {
        return match ($type) {
            'MARGIN_WINDOW_TYPE_INTRADAY' => self::Intraday,
            'MARGIN_WINDOW_TYPE_OVERNIGHT' => self::Overnight,
            'MARGIN_WINDOW_TYPE_WEEKEND' => self::Weekend,
            'MARGIN_WINDOW_TYPE_TRANSITION' => self::Transition,
            default => self::Unknown,
        };
    }

    /** Initial margin as a fraction of notional (config/desk.php → perps.initial_margin_pct, in percent). */
    public function marginRate(): float
    {
        return (float) (config('desk.perps.initial_margin_pct')[$this->value] ?? 35) / 100;
    }
}
