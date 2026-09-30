<?php

// The SPA is served from the same origin as the API, so no cross-origin caller needs
// CORS. An empty allowed_origins keeps browsers from reading desk responses cross-site.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
