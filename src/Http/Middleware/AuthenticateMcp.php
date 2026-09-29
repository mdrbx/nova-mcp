<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Mdrbx\NovaMcp\OAuth\ClientRepository;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Router $router,
        private readonly ResourceServer $server,
        private readonly ClientRepository $clients,
        private readonly NovaContext $context,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->allowsOrigin($request)) {
            return response()->json(['error' => 'invalid_origin'], 403)->withHeaders(['Cache-Control' => 'no-store']);
        }

        if (! $request->bearerToken()) {
            return $this->unauthorized();
        }

        try {
            $validated = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return $this->unauthorized();
        }

        // League exposes aud[0] as oauth_client_id. Our audience is the resource;
        // the signed token ID resolves its actual client in Passport's persistent token record.
        if ($validated->getAttribute('oauth_client_id') !== $this->context->endpoint()) {
            return $this->unauthorized();
        }

        $tokenId = $validated->getAttribute('oauth_access_token_id');
        $subject = $validated->getAttribute('oauth_user_id');
        $scopes = $validated->getAttribute('oauth_scopes');

        if (! is_string($tokenId) || ! is_string($subject) || $subject === '' || ! is_array($scopes)) {
            return $this->unauthorized();
        }

        $token = Passport::token()->newQuery()->find($tokenId);

        if (! $token || (string) $token->getAttribute('user_id') !== $subject || ! $this->clients->find((string) $token->getAttribute('client_id')) instanceof Client) {
            return $this->unauthorized();
        }

        if (! Scopes::allows($scopes)) {
            return response()->json(['error' => 'insufficient_scope'], 403)->withHeaders([
                'WWW-Authenticate' => $this->challenge().', error="insufficient_scope"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $user = $this->context->guard()->getProvider()->retrieveById($subject);

        if (! $user instanceof Authenticatable) {
            return $this->unauthorized();
        }

        $guard = $this->context->guard();
        $previousUser = $guard->user();
        $previousDriver = $this->auth->getDefaultDriver();
        $previousResolver = $this->auth->userResolver();
        $previousRequestResolver = $request->getUserResolver();
        $previousScopes = $request->attributes->get('nova-mcp.scopes');
        $cookies = $request->cookies->all();

        try {
            // Bearer requests must not inherit a browser's unrelated session identity.
            $request->cookies->replace([]);
            $guard->setUser($user);
            $this->auth->shouldUse($this->context->guardName());
            $request->setUserResolver(fn (?string $guard = null) => $this->auth->guard($guard)->user());
            $request->attributes->set('nova-mcp.scopes', $scopes);

            $middleware = $this->router->resolveMiddleware(['nova:api'], [
                VerifyCsrfToken::class,
                // Laravel 13 renamed this middleware; the string also remains valid on Laravel 12.
                'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            ]);
            $middleware[] = AuthorizeTool::class;
            $response = (new Pipeline(app()))->send($request)->through($middleware)->then($next);

            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }

            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            $previousUser === null ? $guard->forgetUser() : $guard->setUser($previousUser);
            $this->auth->setDefaultDriver($previousDriver);
            $this->auth->resolveUsersUsing($previousResolver);
            $request->setUserResolver($previousRequestResolver);
            $request->cookies->replace($cookies);
            $previousScopes === null
                ? $request->attributes->remove('nova-mcp.scopes')
                : $request->attributes->set('nova-mcp.scopes', $previousScopes);
        }
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'invalid_token'], 401)->withHeaders([
            'WWW-Authenticate' => $this->challenge(),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function challenge(): string
    {
        $endpoint = $this->context->endpoint();
        $path = parse_url($endpoint, PHP_URL_PATH) ?: '';
        $origin = substr($endpoint, 0, strlen($endpoint) - strlen($path));
        $metadata = $origin.'/.well-known/oauth-protected-resource'.$path;

        return 'Bearer resource_metadata="'.$metadata.'", scope="'.Scopes::READ.'"';
    }

    private function allowsOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return true;
        }

        $candidate = $this->origin($origin, true);

        if ($candidate === null) {
            return false;
        }

        $allowed = [$this->context->endpoint(), ...config('nova-mcp.allowed_origins', [])];

        foreach ($allowed as $url) {
            if (is_string($url) && $candidate === $this->origin($url)) {
                return true;
            }
        }

        return false;
    }

    private function origin(string $url, bool $header = false): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['fragment'])) {
            return null;
        }

        if ($header && (isset($parts['path']) || isset($parts['query']))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
