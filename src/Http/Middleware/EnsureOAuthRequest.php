<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Client as ClientModel;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Mdrbx\NovaMcp\OAuth\ClientRepository;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureOAuthRequest
{
    public function __construct(private readonly NovaContext $context, private readonly ClientRepository $clients) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->exists('resource') && $request->input('resource') !== $this->context->endpoint()) {
            return $this->error('invalid_target', 'The resource must identify this Nova MCP endpoint.');
        }

        if ($request->isMethod('GET')) {
            if ($request->query('code_challenge_method') !== 'S256') {
                return $this->error('invalid_request', 'PKCE with S256 is required.');
            }

            $client = $request->query('client_id');
            $scope = $request->query('scope');
            $scopes = is_string($scope) ? (preg_split('/\\s+/', trim($scope)) ?: []) : [];
        } else {
            $pending = $this->pending($request);

            if (! $pending instanceof AuthorizationRequestInterface || $pending->getUser()?->getIdentifier() !== (string) $request->user()?->getAuthIdentifier()) {
                return $this->error('invalid_request', 'The authorization request is no longer valid.');
            }

            $client = $pending->getClient()->getIdentifier();
            $scopes = array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $pending->getScopes());
        }

        $clientModel = is_string($client) ? $this->clients->find($client) : null;

        if (! $clientModel instanceof ClientModel) {
            return $this->error('invalid_client', 'This client is not registered for this Nova server.');
        }

        if (! Scopes::allows($scopes)) {
            return $this->error('invalid_scope', 'Request nova:read with optional nova:write and offline_access.');
        }

        $response = $next($request);
        $this->identifyIssuer($response, $clientModel);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function identifyIssuer(Response $response, ClientModel $client): void
    {
        $location = $response->headers->get('Location');

        if (! $response->isRedirection() || $location === null) {
            return;
        }

        [$url, $fragment] = array_pad(explode('#', $location, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');

        if (preg_match('/(?:^|&)(?:code|error)=/', $query) !== 1) {
            return;
        }

        $callbacks = $client->getAttribute('redirect_uris');
        $bases = array_map(fn (string $callback): string => explode('?', $callback, 2)[0], $callbacks);

        if (! in_array($base, $bases, true)) {
            return;
        }

        // RFC 9207 covers successful and failed OAuth redirects, but never the application's login redirects.
        $parameters = array_filter(explode('&', $query), fn (string $parameter): bool => urldecode(explode('=', $parameter, 2)[0]) !== 'iss');
        $parameters[] = http_build_query(['iss' => $this->context->issuer()], '', '&', PHP_QUERY_RFC3986);
        $location = $base.'?'.implode('&', $parameters);

        if ($fragment !== null) {
            $location .= '#'.$fragment;
        }

        $response->headers->set('Location', $location);
    }

    private function pending(Request $request): ?AuthorizationRequestInterface
    {
        $serialized = $request->session()->get('authRequest');

        if (! is_string($serialized)) {
            return null;
        }

        try {
            // Inspect the pending request without consuming Passport's one-time approval token.
            $pending = unserialize($serialized, ['allowed_classes' => [AuthorizationRequest::class, Client::class, Scope::class, User::class]]);
        } catch (Throwable) {
            return null;
        }

        return $pending instanceof AuthorizationRequestInterface ? $pending : null;
    }

    private function error(string $error, string $description): Response
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400)
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
