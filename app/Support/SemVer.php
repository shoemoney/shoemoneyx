<?php

declare(strict_types=1);

namespace App\Support;

/** Minimal major.minor.patch bump helper for strategy-plugin versioning. */
final class SemVer
{
    public static function bump(string $version, string $level): string
    {
        [$major, $minor, $patch] = self::parse($version);

        return match ($level) {
            'major' => ($major + 1).'.0.0',
            'minor' => $major.'.'.($minor + 1).'.0',
            default => $major.'.'.$minor.'.'.($patch + 1),
        };
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function parse(string $version): array
    {
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
            return [1, 0, 0];
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
