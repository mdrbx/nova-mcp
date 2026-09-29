<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Mdrbx\NovaMcp\Support\NovaContext;

class AccessTokenRepository extends PassportAccessTokenRepository
{
    public function __construct(Dispatcher $events, private readonly NovaContext $context)
    {
        parent::__construct($events);
    }

    /** @param array<ScopeEntityInterface> $scopes */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        return new AccessToken($userIdentifier, $scopes, $clientEntity, $this->context->endpoint());
    }
}
