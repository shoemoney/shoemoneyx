<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * `error` is TEXT (65 535 bytes), but a PDOException message carries the whole failing
 * statement — a bulk candle insert produced a 288 KB message. The write meant to *record* a
 * failure then failed itself with SQLSTATE 22001, so the row kept its pre-failure status and
 * the real cause (a 1213 deadlock) was never stored.
 */
trait TruncatesError
{
    public const ERROR_MAX = 2000;

    protected function error(): Attribute
    {
        return Attribute::set(
            fn (?string $value) => $value === null ? null : mb_substr($value, 0, self::ERROR_MAX),
        );
    }
}
