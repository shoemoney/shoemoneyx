<?php

return [
    /**
     * name => ['command' => ..., 'args' => [...], 'env' => [...], 'enabled' => true]
     * Populated from the MCP_SERVERS env var (JSON object) so servers can be added
     * without a code change.
     */
    'servers' => json_decode((string) env('MCP_SERVERS', '{}'), true) ?: [],

    // Example only — NOT active by default. As of writing, the npm package
    // `tradingview-mcp` (v1.0.1) bridges a *running TradingView Desktop app* over
    // Chrome DevTools Protocol; it is not a hosted market-data API and needs the
    // desktop app open locally. Verify it still fits before enabling. To enable,
    // set MCP_SERVERS to JSON containing this shape (don't uncomment PHP here):
    // {"tradingview": {"command": "npx", "args": ["-y", "tradingview-mcp"], "env": {}}}
];
