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

    /** Parent env vars a child may inherit; everything else (APP_KEY, DB_*, API keys) is withheld. */
    private const BASE_ENV = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR'];

    /**
     * @param  list<string>  $args
     * @param  array<string,string>  $env  explicit vars handed to the child
     * @param  list<string>  $inheritEnv  extra parent var names the child may inherit
     */
    public function __construct(
        string $command,
        array $args = [],
        array $env = [],
        private readonly float $timeoutSeconds = 10.0,
        array $inheritEnv = [],
    ) {
        $this->stdin = new InputStream;
        $this->process = new Process([$command, ...$args], null, self::childEnv($env, $inheritEnv));
        $this->process->setInput($this->stdin);
        $this->process->start();
    }

    /**
     * Symfony Process merges the parent env under whatever it is given, and only
     * drops a variable when it is explicitly set to false — so every inherited
     * name outside the allowlist is passed as false.
     *
     * @param  array<string,string>  $env
     * @param  list<string>  $inheritEnv
     * @return array<string,string|false>
     */
    private static function childEnv(array $env, array $inheritEnv): array
    {
        $allowed = array_merge(self::BASE_ENV, $inheritEnv);
        $child = $env;
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            $name = (string) $name;
            if (! array_key_exists($name, $child) && ! in_array($name, $allowed, true)) {
                $child[$name] = false;
            }
        }

        return $child;
    }

    /** @return array<string,mixed> */
    public function initialize(): array
    {
        $result = $this->call('initialize', [
            'protocolVersion' => '2026-06-18',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'shoemoneyx-strategy-agent', 'version' => '1.0.0'],
        ]);
        // MCP enters its operational phase only after this client notification.
        // Notifications have no id and receive no response.
        $this->stdin->write(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n");

        return $result;
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
