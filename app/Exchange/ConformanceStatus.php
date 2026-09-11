<?php

declare(strict_types=1);

namespace App\Exchange;

use Illuminate\Support\Str;

/**
 * The "conformance: passed" badge rule (docs/EXCHANGES.md, CONTRIBUTING.md#badge-rules): an
 * adapter is listed as conformant only when BOTH a recorded fixture directory
 * (tests/Exchange/Fixtures/<id>/) AND a conformance test class for it
 * (tests/Exchange/Conformance/<Id>ConformanceTest.php) are checked into the repo — and CI runs
 * them. A pure filesystem check, never autoloads or runs PHPUnit, so it is safe and cheap to call
 * from a live request.
 */
final class ConformanceStatus
{
    public const PASSED = 'passed';

    public const UNVERIFIED = 'unverified';

    public static function for(string $id): string
    {
        return is_dir(self::fixturesDir($id)) && is_file(self::testFile($id)) ? self::PASSED : self::UNVERIFIED;
    }

    public static function fixturesDir(string $id): string
    {
        return base_path("tests/Exchange/Fixtures/{$id}");
    }

    public static function testFile(string $id): string
    {
        return base_path('tests/Exchange/Conformance/'.Str::studly($id).'ConformanceTest.php');
    }
}
