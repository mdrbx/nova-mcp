<?php

declare(strict_types=1);

return [
    'path' => 'nova-mcp',

    // Empty means every authorized Nova resource. Use class names to restrict the published catalog.
    'resources' => [],
    'excluded_resources' => [],

    'access_token_ttl' => 60,
    'refresh_token_ttl' => 30,

    // Exact callback URLs. Add the callback shown by your client before connecting it.
    'redirect_uris' => [
        'https://claude.ai/api/mcp/auth_callback',
        'https://chatgpt.com/connector_platform_oauth_redirect',
    ],

    // The application's own origin is accepted automatically; other origins must be explicit.
    'allowed_origins' => [],
];
