<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as PassportClients;
use Laravel\Passport\Passport;
use Mdrbx\NovaMcp\Models\OAuthClient;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;

class RegistrationController
{
    public function __construct(
        private readonly PassportClients $clients,
        private readonly RedirectUris $redirects,
        private readonly NovaContext $context,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => [
                'bail', 'required', 'string', 'max:2048', 'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->redirects->allows($value)) {
                        $fail('This redirect URI is not allowed by this server.');
                    }
                },
            ],
            'token_endpoint_auth_method' => ['sometimes', 'in:none'],
            'grant_types' => ['sometimes', 'array', 'min:1'],
            'grant_types.*' => ['string', 'in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array', 'size:1'],
            'response_types.*' => ['in:code'],
        ]);

        if ($validator->fails()) {
            $redirectError = $validator->errors()->first('redirect_uris*');

            return response()->json([
                'error' => $redirectError !== '' ? 'invalid_redirect_uri' : 'invalid_client_metadata',
                'error_description' => $redirectError !== '' ? $redirectError : $validator->errors()->first(),
            ], 400)->withHeaders(['Cache-Control' => 'no-store']);
        }

        $data = $validator->validated();
        $client = Passport::client()->getConnection()->transaction(function () use ($data): Client {
            $client = $this->clients->createAuthorizationCodeGrantClient(
                name: $data['client_name'] ?? 'MCP client',
                redirectUris: $data['redirect_uris'],
                confidential: false,
                enableDeviceFlow: false,
            );
            $client->forceFill(['provider' => $this->context->providerName()])->save();

            OAuthClient::query()->create([
                'client_id' => (string) $client->getKey(),
                'resource' => $this->context->endpoint(),
            ]);

            return $client;
        });

        return response()->json([
            'client_id' => (string) $client->getKey(),
            'client_name' => $client->getAttribute('name'),
            'redirect_uris' => $client->getAttribute('redirect_uris'),
            'response_types' => ['code'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
            'scope' => Scopes::READ,
        ], 201)->withHeaders(['Cache-Control' => 'no-store']);
    }
}
