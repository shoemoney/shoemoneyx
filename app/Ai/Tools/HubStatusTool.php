<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use App\Hub\HubClient;
use App\Hub\HubConnection;

final class HubStatusTool implements Tool
{
    public function name(): string
    {
        return 'hub_status';
    }

    public function description(): string
    {
        return 'Check whether this desk is connected to the community hub.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function run(array $args, AgentContext $context): array
    {
        $client = app(HubClient::class);

        return [
            'connected' => $client->connected(),
            'handle' => HubConnection::active()?->user_handle,
            'hub_url' => config('hub.url'),
        ];
    }
}
