<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Heartbeat;
use App\Jobs\QueueHeartbeat;
use App\Models\DeskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class InfraRetentionAndHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(Heartbeat::path('test'));
        @unlink(Heartbeat::path('queue'));
        parent::tearDown();
    }

    public function test_model_prune_removes_desk_events_past_retention(): void
    {
        config(['desk.retention.events_days' => 30]);
        $old = DeskEvent::create(['level' => 'info', 'agent' => 'CHIEF', 'message' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $fresh = DeskEvent::create(['level' => 'info', 'agent' => 'CHIEF', 'message' => 'fresh']);

        $this->artisan('model:prune', ['--model' => DeskEvent::class])->assertSuccessful();

        $this->assertNull(DeskEvent::find($old->id));
        $this->assertNotNull(DeskEvent::find($fresh->id));
    }

    public function test_failed_job_and_batch_pruning_are_scheduled(): void
    {
        $commands = collect(Schedule::events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringContainsString('queue:prune-failed --hours=168', $commands);
        $this->assertStringContainsString('queue:prune-batches --hours=48', $commands);
        $this->assertStringContainsString('model:prune', $commands);
    }

    public function test_prune_hours_are_clamped_to_at_least_one(): void
    {
        config(['desk.retention.failed_jobs_hours' => 0, 'desk.retention.job_batches_hours' => -5]);
        $before = count(Schedule::events());

        require base_path('routes/console.php');

        $added = collect(Schedule::events())->slice($before)->map(fn ($e) => (string) $e->command)->implode("\n");
        $this->assertStringContainsString('queue:prune-failed --hours=1', $added);
        $this->assertStringContainsString('queue:prune-batches --hours=1', $added);
        $this->assertStringNotContainsString('--hours=0', $added);
        $this->assertStringNotContainsString('--hours=-', $added);
    }

    public function test_daily_log_channel_honours_the_retention_days_key(): void
    {
        $this->assertSame(7, config('logging.channels.daily.days'));
        $this->assertStringContainsString("\nLOG_STACK=daily\n", (string) file_get_contents(base_path('.env.example')));
    }

    public function test_heartbeat_beat_and_age(): void
    {
        @unlink(Heartbeat::path('test'));
        $this->assertNull(Heartbeat::age('test'));

        Heartbeat::beat('test');

        $age = Heartbeat::age('test');
        $this->assertNotNull($age);
        $this->assertLessThanOrEqual(2, $age);

        touch(Heartbeat::path('test'), time() - 60);
        $this->assertGreaterThanOrEqual(59, Heartbeat::age('test'));
        $this->assertLessThanOrEqual(65, Heartbeat::age('test'));
    }

    public function test_queue_heartbeat_job_beats_the_queue_file(): void
    {
        @unlink(Heartbeat::path('queue'));

        (new QueueHeartbeat)->handle();

        $this->assertNotNull(Heartbeat::age('queue'));
    }

    public function test_schedule_registers_the_queue_and_schedule_heartbeats(): void
    {
        $descriptions = collect(Schedule::events())->map(fn ($e) => (string) $e->description);

        $this->assertTrue($descriptions->contains('heartbeat:schedule'));
        $this->assertTrue($descriptions->contains('heartbeat:queue'));

        foreach (['heartbeat:schedule', 'heartbeat:queue'] as $name) {
            $event = collect(Schedule::events())->first(fn ($e) => $e->description === $name);
            $this->assertSame('* * * * *', $event->expression);
        }
    }

    public function test_shell_checker_agrees_with_the_php_heartbeat_file(): void
    {
        $check = fn (int $max): int => (function () use ($max) {
            exec(sprintf('HEARTBEAT_DIR=%s sh %s test %d', escapeshellarg(dirname(Heartbeat::path('test'))), escapeshellarg(base_path('docker/heartbeat-check.sh')), $max), $out, $code);

            return $code;
        })();

        @unlink(Heartbeat::path('test'));
        $this->assertSame(1, $check(60), 'no file means unhealthy');

        Heartbeat::beat('test');
        $this->assertSame(0, $check(60));

        touch(Heartbeat::path('test'), time() - 3600);
        $this->assertSame(1, $check(60), 'a stale file means unhealthy');
        $this->assertSame(0, $check(7200));
    }

    public function test_retention_values_are_clamped_so_a_typo_cannot_delete_everything(): void
    {
        config(['desk.retention.events_days' => 0]);
        $row = DeskEvent::create(['level' => 'info', 'agent' => 'CHIEF', 'message' => 'fresh']);

        $this->artisan('model:prune', ['--model' => DeskEvent::class])->assertSuccessful();

        $this->assertNotNull(DeskEvent::find($row->id));
    }
}
