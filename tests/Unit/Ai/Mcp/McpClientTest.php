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
    public function test_server_rejects_tools_until_the_initialized_notification_is_sent(): void
    {
        $client = new McpClient('php', [base_path('tests/Fixtures/fake-mcp-server.php')]);

        try {
            try {
                $client->listTools();
                $this->fail('The strict fixture must reject tools before initialization.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('notifications/initialized required', $e->getMessage());
            }

            $client->initialize();
            $this->assertSame('echo', $client->listTools()[0]['name']);
        } finally {
            $client->close();
        }
    }

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

    public function test_child_does_not_inherit_parent_secrets(): void
    {
        $secrets = ['APP_KEY' => 'base64:k', 'DB_PASSWORD' => 'dbpw', 'MASTER_PASSWORD' => 'mpw', 'EXTRA_ALLOWED' => 'ok'];
        $original = [];
        foreach ($secrets as $k => $v) {
            $original[$k] = ['getenv' => getenv($k), 'env' => array_key_exists($k, $_ENV) ? [$_ENV[$k]] : null];
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }

        try {
            $client = new McpClient('php', [base_path('tests/Fixtures/fake-mcp-server.php')], ['EXPLICIT' => 'yes'], inheritEnv: ['EXTRA_ALLOWED']);
            $client->initialize();
            $seen = json_decode($client->callTool('env', ['keys' => [
                'APP_KEY', 'DB_PASSWORD', 'MASTER_PASSWORD', 'EXTRA_ALLOWED', 'EXPLICIT', 'PATH',
            ]])['content'][0]['text'], true);
            $client->close();
        } finally {
            foreach ($original as $k => $was) {
                putenv($was['getenv'] === false ? $k : "$k={$was['getenv']}");
                if ($was['env'] === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $was['env'][0];
                }
            }
        }

        $this->assertFalse($seen[0]);
        $this->assertFalse($seen[1]);
        $this->assertFalse($seen[2]);
        $this->assertSame('ok', $seen[3]);
        $this->assertSame('yes', $seen[4]);
        $this->assertNotFalse($seen[5]);
    }
}
