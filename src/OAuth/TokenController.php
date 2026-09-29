<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Passport;
use Mdrbx\NovaMcp\Models\OAuthClient;
use Mdrbx\NovaMcp\Support\NovaContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class TokenController
{
    public function __construct(private readonly NovaAuthorizationServer $server, private readonly NovaContext $context) {}

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        if ($request->exists('resource') && $request->input('resource') !== $this->context->endpoint()) {
            return response()->json([
                'error' => 'invalid_target',
                'error_description' => 'The resource must identify this Nova MCP endpoint.',
            ], 400)->withHeaders(['Cache-Control' => 'no-store']);
        }

        return Passport::client()->getConnection()->transaction(function () use ($request, $psrRequest, $psrResponse): Response {
            $clientId = $request->input('client_id');

            if (! is_string($clientId)) {
                return response()->json(['error' => 'invalid_client'], 400);
            }

            // Revocation takes the same lock so a concurrent refresh cannot recreate a revoked connection.
            OAuthClient::query()->whereKey($clientId)->lockForUpdate()->first();

            return (new AccessTokenController($this->server))->issueToken($psrRequest, $psrResponse);
        });
    }
}
