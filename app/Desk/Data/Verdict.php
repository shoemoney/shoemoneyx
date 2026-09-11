<?php

declare(strict_types=1);

namespace App\Desk\Data;

/** VET output. There is no "maybe" on this desk. */
final class Verdict
{
    public const PASS = 'PASS';

    public const PASS_PARTIAL = 'PASS_PARTIAL';

    public const REJECT = 'REJECT';

    public function __construct(
        public readonly Candidate $candidate,
        public readonly string $verdict,
        public readonly ?string $failedCheck,
        public readonly array $checksRun,
        public readonly array $checksSkipped,
        public readonly ?array $evidence,
        public readonly string $why,
    ) {}

    public function passed(): bool
    {
        return $this->verdict !== self::REJECT;
    }

    public static function reject(Candidate $c, string $check, string $why, array $run, array $skipped = [], ?array $evidence = null): self
    {
        return new self($c, self::REJECT, $check, $run, $skipped, $evidence, $why);
    }

    public static function pass(Candidate $c, array $run, array $skipped = [], string $why = 'all checks passed', bool $partial = false): self
    {
        return new self($c, $partial || $skipped !== [] ? self::PASS_PARTIAL : self::PASS, null, $run, $skipped, null, $why);
    }
}
