<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Mdrbx\NovaMcp\NovaMcp;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeTool
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $tool = collect(Nova::registeredTools())->first(fn ($tool): bool => $tool instanceof NovaMcp);

        // A hidden or unregistered tool must not remain callable by typing its URL or using an old token.
        abort_unless($tool instanceof NovaMcp && $tool->authorize($request), 403);

        return $next($request);
    }
}
