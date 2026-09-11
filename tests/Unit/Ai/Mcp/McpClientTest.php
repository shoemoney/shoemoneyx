<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Mcp;

use App\Ai\AgentContext;
use App\Ai\Mcp\McpClient;
use App\Ai\Mcp\McpToolLoader;
use App\Ai\Tools\ToolRegistry;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    public function test_initialize_list_tools_and_call_tool_round_trip(): void
    {
        $client = new McpClient('php', [base_path('tests/Fixtures/fake-mcp-server.php')]);

        $init = $client->initialize();
        $this->assertSame('fake-mcp-server', $init['serverInfo']['name']);

        $tools = $client->listTools();
        $this->assertCount(1, $tools);
        $this->assertSame('echo', $tools[0]['name']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            $tools[0]['inputSchema'],
        );

        $result = $client->callTool('echo', ['text' => 'hello mcp']);
        $this->assertSame('hello mcp', $result['content'][0]['text']);

        $client->close();
    }

    public function test_mcp_tool_loader_extends_registry_from_config(): void
    {
        config(['mcp.servers' => [
            'fixture' => ['command' => 'php', 'args' => [base_path('tests/Fixtures/fake-mcp-server.php')]],
        ]]);

        $registry = new ToolRegistry;
        McpToolLoader::extend($registry);

        $tool = $registry->get('mcp.fixture.echo');
        $this->assertNotNull($tool);

        $context = new AgentContext(1);
        $result = $tool->run(['text' => 'x'], $context);
        $this->assertSame('x', $result['content'][0]['text']);
    }
}
