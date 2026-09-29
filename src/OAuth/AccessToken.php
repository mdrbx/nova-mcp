<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken as PassportAccessToken;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;

class AccessToken extends PassportAccessToken
{
    use AccessTokenTrait;

    /**
     * @param  non-empty-string|null  $userIdentifier
     * @param  array<ScopeEntityInterface>  $scopes
     */
    public function __construct(?string $userIdentifier, array $scopes, ClientEntityInterface $client, private readonly string $resource)
    {
        parent::__construct($userIdentifier, $scopes, $client);
    }

    public function toString(): string
    {
        $this->initJwtConfiguration();

        // Passport uses the OAuth client as audience. MCP requires the protected resource itself.
        return $this->jwtConfiguration->builder()
            ->permittedFor($this->resource)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable)
            ->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? '')
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
