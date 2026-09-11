<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Reporter;
use App\Models\DeskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporterAgentWidthTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agent_tag_longer_than_the_column_is_truncated_instead_of_failing_the_write(): void
    {
        config(['desk.telegram.bot_token' => null]);

        app(Reporter::class)->warn('ROUNDS-WATCH', 'a warning that must land');

        $event = DeskEvent::sole();
        $this->assertSame('ROUNDS-W', $event->agent, 'desk_events.agent is VARCHAR(8) on MariaDB; sqlite never enforces it');
        $this->assertSame('a warning that must land', $event->message);
    }
}
