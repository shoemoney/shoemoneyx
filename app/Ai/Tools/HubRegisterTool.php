<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;

/** Never registers on its own: the user creates the hub account themselves in the UI. */
final class HubRegisterTool implements Tool
{
    public function name(): string
    {
        return 'hub_register';
    }

    public function description(): string
    {
        return 'Show how to create a community hub account in the Hub connect panel. Never ask for credentials in chat or pass them to this tool.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
            'additionalProperties' => false,
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        // Account creation takes credentials the model must never be trusted to supply.
        return [
            'needs_confirmation' => true,
            'action' => 'hub_register',
            'endpoint' => 'POST /api/hub/register',
            'message' => 'Create your hub account in the Hub connect panel. Enter your email, handle, and password there, never in this chat.',
        ];
    }
}
