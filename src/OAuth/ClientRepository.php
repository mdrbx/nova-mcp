<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Passport\Bridge\ClientRepository as PassportClientRepository;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as ClientModels;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Mdrbx\NovaMcp\Models\OAuthClient;
use Mdrbx\NovaMcp\Support\NovaContext;

class ClientRepository extends PassportClientRepository
{
    public function __construct(ClientModels $clients, Hasher $hasher, private readonly NovaContext $context)
    {
        parent::__construct($clients, $hasher);
    }

    public function find(string $identifier): ?Client
    {
        if (! OAuthClient::query()->whereKey($identifier)->where('resource', $this->context->endpoint())->exists()) {
            return null;
        }

        $client = $this->clients->findActive($identifier);

        if (! $client instanceof Client || $client->getAttribute('provider') !== $this->context->providerName()) {
            return null;
        }

        if (! $client->hasGrantType('authorization_code')) {
            return null;
        }

        return $client;
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->find($clientIdentifier);

        return $client instanceof Client ? $this->fromClientModel($client) : null;
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        return $this->find($clientIdentifier) instanceof Client
            && parent::validateClient($clientIdentifier, $clientSecret, $grantType);
    }
}
