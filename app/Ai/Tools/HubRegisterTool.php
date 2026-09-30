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
        return 'Request a community hub account. Never registers by itself: returns needs_confirmation and the user completes it in the UI.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'description' => 'Account email.'],
                'handle' => ['type' => 'string', 'description' => 'Unique handle, ^[a-z0-9_]{3,24}$.'],
                'password' => ['type' => 'string', 'description' => 'Account password, min 8 chars.'],
            ],
            'required' => ['email', 'handle', 'password'],
        ];
    }

    public function run(array $args, AgentContext $context): array
    {
        // Account creation takes credentials the model must never be trusted to supply.
        return [
            'needs_confirmation' => true,
            'action' => 'hub_register',
            'endpoint' => 'POST /api/hub/register',
            'message' => 'Not registered. Ask the user to create their hub account themselves in the Hub connect panel.',
        ];
    }
}
