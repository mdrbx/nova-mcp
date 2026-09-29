<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use Laravel\Passport\Bridge\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Mdrbx\NovaMcp\Scopes;

class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, Scopes::supported(), true) ? new Scope($identifier) : null;
    }

    /**
     * @param  array<ScopeEntityInterface>  $scopes
     * @return array<ScopeEntityInterface>
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $identifiers = array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);

        if (! Scopes::allows($identifiers)) {
            throw OAuthServerException::invalidScope(implode(' ', $identifiers));
        }

        return $scopes;
    }
}
