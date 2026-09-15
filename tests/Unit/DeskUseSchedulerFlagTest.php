<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * routes/console.php gates the desk-loop schedule entries on config('desk.use_scheduler'),
 * which is itself sourced from the DESK_USE_SCHEDULER env var in config/desk.php. That
 * indirection matters: env() calls outside config/ freeze to their default once config is
 * cached (`artisan config:cache`/`optimize`), so the gate must read config(), not env(),
 * or the flag goes silently inert on a cached production install.
 *
 * These run `schedule:list` in a real subprocess (not via Artisan::call in-process) because
 * routes/console.php is require'd once per process during framework bootstrap — toggling
 * config at runtime inside a single PHPUnit process would not re-evaluate it.
 */
class DeskUseSchedulerFlagTest extends TestCase
{
    private function scheduleList(string $flag): string
    {
        // Anchored to this test file's own tree (not base_path()) so the subprocess runs
        // THIS branch's artisan/routes/console.php — under PHPUnit, vendor/ is a symlink
        // back to the main repo checkout, and base_path() resolves relative to that
        // symlinked vendor/autoload.php, not to this worktree.
        $repoRoot = dirname(__DIR__, 2);
        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg($repoRoot.'/artisan');

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            "DESK_USE_SCHEDULER={$flag} {$php} {$artisan} schedule:list 2>&1",
            $descriptors,
            $pipes,
            $repoRoot
        );

        if (! is_resource($process)) {
            $this->fail('Failed to start schedule:list subprocess.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    public function test_desk_loop_entries_are_scheduled_when_use_scheduler_is_true(): void
    {
        $out = $this->scheduleList('true');

        $this->assertStringContainsString('market:sync-products', $out);
        $this->assertStringContainsString('market:sync-candles', $out);
        $this->assertStringContainsString('desk:risk', $out);
        $this->assertStringContainsString('desk:cycle', $out);
    }

    public function test_desk_loop_entries_are_absent_when_use_scheduler_is_false(): void
    {
        $out = $this->scheduleList('false');

        $this->assertStringNotContainsString('market:sync-products', $out);
        $this->assertStringNotContainsString('market:sync-candles', $out);
        $this->assertStringNotContainsString('desk:risk', $out);
        $this->assertStringNotContainsString('desk:cycle', $out);

        // Always-on entries stay scheduled regardless of the flag.
        $this->assertStringContainsString('desk:report', $out);
        $this->assertStringContainsString('agent:backtest-loop', $out);
    }
}
