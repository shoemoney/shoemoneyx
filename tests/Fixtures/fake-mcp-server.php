#!/usr/bin/env php
<?php

// Minimal fake MCP server for McpClient tests: reads newline-delimited JSON-RPC
// requests from stdin, replies on stdout. Implements initialize, tools/list, and
// tools/call for one fixture tool ("echo").

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = json_decode($line, true);
    if (! is_array($req)) {
        continue;
    }
    $id = $req['id'] ?? null;
    $method = $req['method'] ?? '';

    $result = match ($method) {
        'initialize' => [
            'protocolVersion' => '2026-06-18',
            'serverInfo' => ['name' => 'fake-mcp-server', 'version' => '1.0.0'],
            'capabilities' => new stdClass,
        ],
        'tools/list' => ['tools' => [[
            'name' => 'echo',
            'description' => 'Echoes back the given text.',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        ]]],
        'tools/call' => (function () use ($req) {
            $args = $req['params']['arguments'] ?? [];

            return ['content' => [['type' => 'text', 'text' => (string) ($args['text'] ?? '')]]];
        })(),
        default => null,
    };

    if ($id !== null) {
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result])."\n");
        fflush(STDOUT);
    }
}
