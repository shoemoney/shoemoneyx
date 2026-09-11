<?php

declare(strict_types=1);

namespace App\Ai\Mcp;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Minimal synchronous JSON-RPC-over-stdio client for a long-lived MCP server
 * child process. Uses Symfony Process's InputStream rather than Process::run()
 * (which blocks until the child exits) so a request/response round trip can
 * happen while the server keeps running.
 */
final class McpClient
{
    private readonly Process $process;

    private readonly InputStream $stdin;

    private int $nextId = 1;

    private string $outBuffer = '';

    /** @param  list<string>  $args @param array<string,string> $env */
    public function __construct(
        string $command,
        array $args = [],
        array $env = [],
        private readonly float $timeoutSeconds = 10.0,
    ) {
        $this->stdin = new InputStream;
        $this->process = new Process([$command, ...$args], null, $env ?: null);
        $this->process->setInput($this->stdin);
        $this->process->start();
    }

    /** @return array<string,mixed> */
    public function initialize(): array
    {
        return $this->call('initialize', [
            'protocolVersion' => '2026-06-18',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'shoemoneyx-strategy-agent', 'version' => '1.0.0'],
        ]);
    }

    /** @return list<array{name: string, description?: string, inputSchema?: array}> */
    public function listTools(): array
    {
        return $this->call('tools/list', [])['tools'] ?? [];
    }

    /** @return array<string,mixed> */
    public function callTool(string $name, array $arguments): array
    {
        return $this->call('tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    private function call(string $method, array $params): array
    {
        $id = $this->nextId++;
        $this->stdin->write(json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params])."\n");

        $deadline = microtime(true) + $this->timeoutSeconds;
        while (microtime(true) < $deadline) {
            $this->outBuffer .= $this->process->getIncrementalOutput();
            while (($pos = strpos($this->outBuffer, "\n")) !== false) {
                $line = substr($this->outBuffer, 0, $pos);
                $this->outBuffer = substr($this->outBuffer, $pos + 1);
                if (trim($line) === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                    if (isset($decoded['error'])) {
                        throw new \RuntimeException('MCP error: '.($decoded['error']['message'] ?? 'unknown'));
                    }

                    return $decoded['result'] ?? [];
                }
            }
            usleep(10_000);
        }

        throw new \RuntimeException("MCP request '{$method}' timed out after {$this->timeoutSeconds}s");
    }

    public function close(): void
    {
        $this->stdin->close();
        $this->process->stop(3);
    }

    public function __destruct()
    {
        $this->close();
    }
}
