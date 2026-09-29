<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository as PassportClients;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Scope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationController extends PassportAuthorizationController
{
    public function __construct(NovaAuthorizationServer $server, NovaContext $context, PassportClients $clients)
    {
        parent::__construct($server, $context->guard(), $clients);
    }

    public function __invoke(ServerRequestInterface $psrRequest, Request $request, ResponseInterface $psrResponse): Response|AuthorizationViewResponse
    {
        return parent::authorize($psrRequest, $request, $psrResponse, new SimpleViewResponse('nova-mcp::authorize'));
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        return (new ApproveAuthorizationController($this->server))->approve($request, $psrResponse);
    }

    public function deny(Request $request, ResponseInterface $psrResponse): Response
    {
        return (new DenyAuthorizationController($this->server))->deny($request, $psrResponse);
    }

    /** @return array<Scope> */
    protected function parseScopes(AuthorizationRequestInterface $authRequest): array
    {
        $descriptions = Scopes::descriptions();

        // Keep consent and remembered grants accurate without replacing the application's Passport scopes.
        return array_map(
            fn (ScopeEntityInterface $scope): Scope => new Scope($scope->getIdentifier(), $descriptions[$scope->getIdentifier()]),
            $authRequest->getScopes(),
        );
    }
}
