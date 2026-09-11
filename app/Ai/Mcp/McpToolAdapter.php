<?php

declare(strict_types=1);

namespace App\Ai\Mcp;

use App\Ai\AgentContext;
use App\Ai\Tools\Tool;

/** Wraps one tool exposed by a live MCP server as a native App\Ai\Tools\Tool. */
final class McpToolAdapter implements Tool
{
    public function __construct(
        private readonly McpClient $client,
        private readonly string $serverName,
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $inputSchema,
    ) {}

    public function name(): string
    {
        return "mcp.{$this->serverName}.{$this->toolName}";
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parameters(): array
    {
        return $this->inputSchema !== [] ? $this->inputSchema : ['type' => 'object', 'properties' => new \stdClass];
    }

    public function run(array $args, AgentContext $context): array
    {
        return $this->client->callTool($this->toolName, $args);
    }
}
