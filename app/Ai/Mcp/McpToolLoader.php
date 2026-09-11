<?php

declare(strict_types=1);

namespace App\Ai\Mcp;

use App\Ai\Tools\ToolRegistry;

/** Reads config/mcp.php ('servers'), spawns each enabled server, and registers its tools. */
final class McpToolLoader
{
    public static function extend(ToolRegistry $registry): void
    {
        foreach ((array) config('mcp.servers', []) as $name => $server) {
            if (! is_array($server) || ($server['enabled'] ?? true) === false || empty($server['command'])) {
                continue;
            }
            try {
                $client = new McpClient($server['command'], $server['args'] ?? [], $server['env'] ?? []);
                $client->initialize();
                foreach ($client->listTools() as $tool) {
                    $registry->register(new McpToolAdapter($client, (string) $name, $tool['name'], $tool['description'] ?? '', $tool['inputSchema'] ?? []));
                }
            } catch (\Throwable $e) {
                report($e); // a misconfigured/unreachable MCP server must not break the whole agent
            }
        }
    }
}
