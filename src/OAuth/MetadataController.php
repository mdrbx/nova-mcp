<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Illuminate\Http\JsonResponse;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;

class MetadataController
{
    public function __construct(private readonly NovaContext $context) {}

    public function resource(): JsonResponse
    {
        return response()->json([
            'resource' => $this->context->endpoint(),
            'authorization_servers' => [$this->context->issuer()],
            'scopes_supported' => Scopes::supported(),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorization(): JsonResponse
    {
        $issuer = $this->context->issuer();

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'registration_endpoint' => $issuer.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => Scopes::supported(),
        ]);
    }
}
