<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** One rule set, first failure wins. Pure: no config() or I/O, everything comes in as arguments. */
final readonly class PerpsGate
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    public static function newRisk(PerpsSession $s, \DateTimeImmutable $now, float $notional, array $cfg): self
    {
        if (PerpsCalendar::halted($now)) {
            return new self(false, 'halt: CFM weekly maintenance Fri 17-18 ET');
        }

        if ($s->liquidationBufferPct !== null && $s->liquidationBufferPct < $cfg['min_liquidation_buffer_pct']) {
            return new self(false, sprintf('liquidation buffer %.0f%% < %.0f%% floor', $s->liquidationBufferPct, $cfg['min_liquidation_buffer_pct']));
        }

        if ($s->window === MarginWindow::Intraday) {
            $secondsToEnd = $s->secondsToWindowEnd($now);
            if ($secondsToEnd !== null && $secondsToEnd < $cfg['flip_guard_minutes'] * 60) {
                return new self(false, sprintf('intraday window ends in %d min; overnight margin applies to new risk', (int) ceil($secondsToEnd / 60)));
            }
        }

        // Size for the flip, never for the cheap window: intraday risk is checked against the overnight rate.
        $rateWindow = $s->window === MarginWindow::Intraday ? MarginWindow::Overnight : $s->window;
        $rate = $rateWindow->marginRate();
        $needed = $notional * $rate;
        $cap = $s->futuresBuyingPower * $cfg['max_margin_use_pct'] / 100;
        if ($needed > $cap) {
            return new self(false, sprintf(
                'margin: $%.0f notional needs ~$%.0f at %s margin (%.0f%%), buying power $%.2f (cap %.0f%%)',
                $notional, $needed, $rateWindow->value, $rate * 100, $s->futuresBuyingPower, $cfg['max_margin_use_pct']
            ));
        }

        return new self(true, null);
    }
}
