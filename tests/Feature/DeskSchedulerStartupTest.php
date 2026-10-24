<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeskSchedulerStartupTest extends TestCase
{
    public static function cleanupScenarios(): array
    {
        return [
            'healthy cache' => [0, 1, 0, true],
            'transient cache outage' => [2, 3, 0, true],
            'persistent cache outage' => [3, 3, 1, false],
        ];
    }

    #[DataProvider('cleanupScenarios')]
    public function test_scheduler_starts_only_after_cleanup_succeeds(int $failures, int $attempts, int $exit, bool $starts): void
    {
        // Run the actual entrypoint with local shell doubles: no /app writes, real PHP process,
        // scheduler, watchdog timer or retry delay is needed to prove startup ordering.
        $script = <<<'BASH'
cd() { :; }
touch() { :; }
sleep() { [[ "$1" != 30 ]]; }
cleanup_attempt=0
php() {
  case "$2" in
    schedule:clear-cache)
      cleanup_attempt=$((cleanup_attempt + 1))
      echo "cleanup:$cleanup_attempt"
      [[ "$cleanup_attempt" -gt "$CLEANUP_FAILURES" ]] ;;
    schedule:work) echo scheduler-started ;;
    *) return 99 ;;
  esac
}
source "$ENTRYPOINT"
BASH;
        $process = new Process(['bash', '-c', $script], dirname(__DIR__, 2), [
            'ROLE' => 'schedule',
            'CLEANUP_FAILURES' => (string) $failures,
            'ENTRYPOINT' => dirname(__DIR__, 2).'/docker/entrypoint.sh',
        ]);
        $process->setTimeout(5)->run();

        $this->assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($attempts, substr_count($process->getOutput(), 'cleanup:'));
        $this->assertSame($starts, str_contains($process->getOutput(), 'scheduler-started'));
        if ($starts) {
            $this->assertStringEndsWith("cleanup:{$attempts}\nscheduler-started\n", $process->getOutput());
        } else {
            $this->assertStringContainsString('refusing startup', $process->getErrorOutput());
        }
    }
}
