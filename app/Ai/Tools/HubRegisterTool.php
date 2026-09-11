<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Hub\HubClient;
use App\Hub\HubConnection;
use App\Hub\HubException;

/** Registers a new hub account for this desk. Never echoes the password or the raw token back. */
final class HubRegisterTool implements Tool
{
    public function name(): string
    {
        return 'hub_register';
    }

    public function description(): string
    {
        return 'Register a new account on the community hub and connect this desk to it.';
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
        $client = app(HubClient::class);

        try {
            $response = $client->register($args['email'], $args['handle'], $args['password'], gethostname() ?: 'desk');
        } catch (HubException $e) {
            return ['error' => $e->getMessage()];
        }

        $handle = $response['user']['handle'] ?? $args['handle'];

        HubConnection::create([
            'user_handle' => $handle,
            'desk_id' => null,
            'token' => $response['token'] ?? null,
            'connected_at' => now(),
        ]);

        return ['connected' => true, 'handle' => $handle];
    }
}
