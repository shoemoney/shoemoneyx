<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Explicitly mapped by the disposable E2E nginx configuration, never by public/index.php.
// The real application, middleware, controllers, SDK and database all run normally. Only
// outbound provider HTTP is replaced; no credential, exchange or paid service is needed.
$enabled = $_SERVER['E2E_FIXTURE_MODE'] ?? getenv('E2E_FIXTURE_MODE');
if ($enabled !== '1' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

define('LARAVEL_START', microtime(true));
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('testing') || (string) config('services.openrouter.key') !== '') {
    throw new RuntimeException('E2E requires real CSRF middleware and no ambient provider key.');
}

Http::preventStrayRequests();
Http::fake(function (OutboundRequest $request) {
    $url = $request->url();
    if ($url === 'https://openrouter.ai/api/v1/key') {
        if ($request->header('Authorization') !== ['Bearer sk-or-v1-e2e-local-fixture-only']) {
            return Http::response(['error' => 'fixture key rejected'], 401);
        }

        return Http::response(['data' => ['label' => 'Local E2E fixture', 'limit_remaining' => 0]], 200);
    }
    if ($url === 'https://openrouter.ai/api/v1/models') {
        return Http::response(['data' => [[
            'id' => 'fixture/local:free', 'name' => 'Local E2E fixture',
            'pricing' => ['prompt' => '0', 'completion' => '0'],
        ]]], 200);
    }
    if ($url === 'https://openrouter.ai/api/v1/chat/completions') {
        if ($request->header('Authorization') !== ['Bearer sk-or-v1-e2e-local-fixture-only']) {
            return Http::response(['error' => 'fixture key rejected'], 401);
        }
        $messages = $request->data()['messages'] ?? [];
        $hasToolResult = collect($messages)->contains(fn ($message) => ($message['role'] ?? null) === 'tool');
        $availableTools = array_column(array_column($request->data()['tools'] ?? [], 'function'), 'name');
        $callTool = ! $hasToolResult && in_array('arena_scoreboard', $availableTools, true);
        $message = $callTool ? [
            'role' => 'assistant', 'content' => null,
            'tool_calls' => [[
                'id' => 'e2e-arena-read', 'type' => 'function',
                'function' => ['name' => 'arena_scoreboard', 'arguments' => '{}'],
            ]],
        ] : [
            'role' => 'assistant',
            'content' => $hasToolResult ? 'Local fixture completed a real arena_scoreboard tool call.' : 'PONG',
        ];

        return Http::response([
            'id' => 'e2e-local-response', 'model' => 'fixture/local:free',
            'choices' => [['message' => $message, 'finish_reason' => $callTool ? 'tool_calls' : 'stop']],
            'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 5, 'total_tokens' => 14, 'cost' => 0],
        ], 200);
    }
    if ($url === rtrim((string) config('strategies.source_url'), '/').'/manifest.json') {
        return Http::response(['schema_version' => 1, 'strategies' => []], 200);
    }

    throw new RuntimeException('Unconfigured outbound HTTP refused by the local E2E fixture: '.parse_url($url, PHP_URL_HOST));
});

$app->handleRequest(Request::capture());
