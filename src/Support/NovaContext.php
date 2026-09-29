<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Support;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use LogicException;

final readonly class NovaContext
{
    public function __construct(private AuthManager $auth) {}

    public function guardName(): string
    {
        // Nova's own middleware and user resolver must see the same identity as OAuth.
        return config('nova.guard') ?? config('auth.defaults.guard');
    }

    public function guard(): SessionGuard
    {
        $guard = $this->auth->guard($this->guardName());

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('Nova MCP requires a session guard for browser consent.');
        }

        return $guard;
    }

    public function providerName(): string
    {
        return config('auth.guards.'.$this->guardName().'.provider');
    }

    public function endpoint(): string
    {
        // OAuth audiences must be stable across requests and cannot trust an incoming Host header.
        return rtrim(config('app.url'), '/').'/'.trim(config('nova-mcp.path'), '/');
    }

    public function issuer(): string
    {
        return $this->endpoint().'/oauth';
    }
}
