<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

use DateInterval;
use Illuminate\Contracts\Encryption\Encrypter;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

class NovaAuthorizationServer extends AuthorizationServer
{
    public function __construct(
        ClientRepository $clients,
        AccessTokenRepository $tokens,
        ScopeRepository $scopes,
        AuthCodeRepository $codes,
        RefreshTokenRepository $refreshTokens,
        Encrypter $encrypter,
    ) {
        $key = str_replace('\\n', "\n", (string) config('passport.private_key', ''));
        $privateKey = new CryptKey(
            $key !== '' ? $key : 'file://'.Passport::keyPath('oauth-private.key'),
            null,
            Passport::$validateKeyPermissions,
        );

        parent::__construct($clients, $tokens, $scopes, $privateKey, Passport::tokenEncryptionKey($encrypter));

        $accessTtl = new DateInterval('PT'.max(1, (int) config('nova-mcp.access_token_ttl', 60)).'M');
        $refreshTtl = new DateInterval('P'.max(1, (int) config('nova-mcp.refresh_token_ttl', 30)).'D');
        $authorization = new AuthCodeGrant($codes, $refreshTokens, new DateInterval('PT10M'));
        $authorization->setRefreshTokenTTL($refreshTtl);
        $refresh = new RefreshTokenGrant($refreshTokens);
        $refresh->setRefreshTokenTTL($refreshTtl);

        $this->enableGrantType($authorization, $accessTtl);
        $this->enableGrantType($refresh, $accessTtl);
        $this->revokeRefreshTokens(true);
    }
}
