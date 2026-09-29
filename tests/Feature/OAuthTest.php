<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Mdrbx\NovaMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OAuthTest extends TestCase
{
    public function test_discovery_advertises_the_resource_authorization_server_and_pkce(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/nova-mcp')
            ->assertOk()
            ->assertJsonPath('resource', self::RESOURCE)
            ->assertJsonPath('authorization_servers.0', self::RESOURCE.'/oauth')
            ->assertJsonPath('scopes_supported', ['nova:read', 'nova:write', 'offline_access']);

        $this->getJson('/.well-known/oauth-authorization-server/nova-mcp/oauth')
            ->assertOk()
            ->assertJsonPath('issuer', self::RESOURCE.'/oauth')
            ->assertJsonPath('authorization_endpoint', route('nova-mcp.oauth.authorize'))
            ->assertJsonPath('token_endpoint', route('nova-mcp.oauth.token'))
            ->assertJsonPath('registration_endpoint', route('nova-mcp.oauth.register'))
            ->assertJsonPath('authorization_response_iss_parameter_supported', true)
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_a_public_client_can_obtain_and_refresh_a_resource_bound_token(): void
    {
        $token = $this->oauthToken($this->user(), ['nova:read', 'offline_access']);
        $segments = explode('.', $token['access_token']);
        $claims = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains(self::RESOURCE, (array) $claims['aud']);
        $this->assertNotContains($token['client_id'], (array) $claims['aud']);
        $this->rpc('tools/list', token: $token['access_token'])->assertOk();

        $refreshed = $this->postJson(route('nova-mcp.oauth.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $token['client_id'],
            'refresh_token' => $token['refresh_token'],
            'resource' => self::RESOURCE,
        ])->assertOk()->json();

        $this->rpc('tools/list', token: $refreshed['access_token'])->assertOk();
    }

    public function test_token_endpoint_rejects_an_incorrect_pkce_verifier(): void
    {
        $client = $this->registerClient();
        $code = $this->authorizationCode($this->user(), $client);

        $this->postJson(route('nova-mcp.oauth.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => str_repeat('x', 64),
            'resource' => self::RESOURCE,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    #[DataProvider('invalidAuthorizationParameters')]
    public function test_authorization_rejects_scope_redirect_pkce_and_resource_confusion(array $overrides, int $status = 400): void
    {
        $client = $this->registerClient();
        $parameters = array_replace($this->authorizationParameters($client), $overrides);

        $this->actingAs($this->user())->getJson(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))
            ->assertStatus($status);

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public static function invalidAuthorizationParameters(): array
    {
        return [
            'write without read' => [['scope' => 'nova:write']],
            'mixed host scope' => [['scope' => 'nova:read host:read']],
            'wildcard' => [['scope' => '*']],
            'different resource' => [['resource' => 'https://other.example/mcp']],
            'different redirect' => [['redirect_uri' => 'https://attacker.example/callback'], 401],
            'plain pkce' => [['code_challenge_method' => 'plain']],
        ];
    }

    public function test_registration_refuses_an_unapproved_redirect_uri(): void
    {
        $this->postJson(route('nova-mcp.oauth.register'), [
            'client_name' => 'Untrusted callback',
            'redirect_uris' => ['https://attacker.example/callback'],
            'token_endpoint_auth_method' => 'none',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        $this->assertDatabaseCount('nova_mcp_clients', 0);
    }

    public function test_bearer_authentication_ignores_a_browser_session_and_returns_discovery_challenge(): void
    {
        $response = $this->actingAs($this->user())->rpc('tools/list');

        $response->assertUnauthorized();
        $this->assertStringContainsString('/.well-known/oauth-protected-resource/nova-mcp', $response->headers->get('WWW-Authenticate'));
        $this->rpc('tools/list', token: 'invalid-token')->assertUnauthorized();
    }

    public function test_an_invalid_origin_is_rejected_even_with_a_valid_access_token(): void
    {
        $token = $this->oauthToken($this->user());

        $this->rpc('tools/list', token: $token['access_token'], headers: ['Origin' => 'https://attacker.example'])->assertForbidden();
        $this->rpc('tools/list', token: $token['access_token'])->assertOk();
    }

    public function test_removing_nova_access_immediately_blocks_an_existing_grant(): void
    {
        $user = $this->user();
        $token = $this->oauthToken($user);
        $user->update(['can_use_nova' => false]);
        auth()->forgetGuards();

        $this->rpc('tools/list', token: $token['access_token'])->assertForbidden();
    }

    public function test_unregistered_host_oauth_clients_cannot_use_package_authorization(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Host application', [self::REDIRECT_URI], confidential: false);
        $parameters = $this->authorizationParameters(['client_id' => $client->getKey()]);

        $this->actingAs($this->user())->getJson(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertStatus(400);

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_package_oauth_does_not_change_the_hosts_passport_scopes_or_expiration(): void
    {
        Passport::tokensCan(['host:read' => 'Read the host application']);
        Passport::defaultScopes(['host:read']);
        $expiration = now()->addMinutes(17);
        Passport::tokensExpireIn($expiration);
        $lifetime = Passport::tokensExpireIn();

        $this->oauthToken($this->user());

        $this->assertSame(['host:read'], Passport::scopeIds());
        $this->assertSame(['host:read'], Passport::defaultScopes());
        $this->assertEquals($lifetime, Passport::tokensExpireIn());
    }

    public function test_revoked_access_tokens_are_rejected(): void
    {
        $token = $this->oauthToken($this->user());
        DB::table('oauth_access_tokens')->where('client_id', $token['client_id'])->update(['revoked' => true]);

        $this->rpc('tools/list', token: $token['access_token'])->assertUnauthorized();
    }

    public function test_refresh_rejects_another_resource(): void
    {
        $token = $this->oauthToken($this->user(), ['nova:read', 'offline_access']);

        $this->postJson(route('nova-mcp.oauth.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $token['client_id'],
            'refresh_token' => $token['refresh_token'],
            'resource' => 'https://other.example/mcp',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    }

    public function test_expired_tokens_cannot_read_nova(): void
    {
        $token = $this->oauthToken($this->user());

        // League validates JWT time against the system clock, independently of Laravel's test clock.
        $expired = $this->signedToken($token['access_token'], expiresAt: new DateTimeImmutable('-1 minute'));

        $this->rpc('tools/list', token: $expired)->assertUnauthorized();
    }

    public function test_a_valid_signature_for_a_different_audience_is_not_sufficient(): void
    {
        $token = $this->oauthToken($this->user());
        $foreignAudience = $this->signedToken($token['access_token'], audience: 'https://other.example/mcp');

        $this->rpc('tools/list', token: $foreignAudience)->assertUnauthorized();
    }

    public function test_changing_the_client_user_provider_invalidates_its_grants(): void
    {
        $token = $this->oauthToken($this->user());
        DB::table('oauth_clients')->where('id', $token['client_id'])->update(['provider' => 'other-users']);

        $this->rpc('tools/list', token: $token['access_token'])->assertUnauthorized();
    }

    public function test_discovery_cannot_be_poisoned_by_the_request_host(): void
    {
        $this->withHeader('Host', 'attacker.example')->getJson('/.well-known/oauth-protected-resource/nova-mcp')
            ->assertOk()
            ->assertJsonPath('resource', self::RESOURCE)
            ->assertJsonPath('authorization_servers.0', self::RESOURCE.'/oauth');
    }

    public function test_package_tool_visibility_is_an_additional_authorization_boundary(): void
    {
        $client = $this->registerClient();
        $parameters = $this->authorizationParameters($client);

        $this->actingAs($this->user(['email' => 'tool-denied@example.test']))
            ->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertForbidden();

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_consent_approval_remains_csrf_protected(): void
    {
        $client = $this->registerClient();
        $parameters = $this->authorizationParameters($client);
        $this->actingAs($this->user())->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertOk();
        $this->app['env'] = 'production';

        $this->post(route('nova-mcp.oauth.approve'), [
            'auth_token' => session('authToken'),
            'client_id' => $client['client_id'],
            'state' => $parameters['state'],
        ])->assertStatus(419);

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_declining_consent_returns_a_bound_issuer_without_issuing_tokens(): void
    {
        $client = $this->registerClient();
        $parameters = $this->authorizationParameters($client);
        $this->actingAs($this->user())->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertOk();

        $response = $this->delete(route('nova-mcp.oauth.deny'), [
            'auth_token' => session('authToken'),
            'client_id' => $client['client_id'],
            'state' => $parameters['state'],
        ])->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('test-client-state', $query['state']);
        $this->assertSame(self::RESOURCE.'/oauth', $query['iss']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_silent_authorization_requires_consent_and_identifies_the_issuer(): void
    {
        $client = $this->registerClient();
        $parameters = array_replace($this->authorizationParameters($client), ['prompt' => 'none']);

        $response = $this->actingAs($this->user())->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('consent_required', $query['error']);
        $this->assertSame(self::RESOURCE.'/oauth', $query['iss']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_a_previously_authorized_scope_can_reconnect_without_repeating_consent(): void
    {
        $user = $this->user();
        $client = $this->registerClient();
        $this->oauthToken($user, client: $client);
        $parameters = array_replace($this->authorizationParameters($client), ['prompt' => 'none']);

        $response = $this->actingAs($user)->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame(self::RESOURCE.'/oauth', $query['iss']);
    }

    private function signedToken(string $original, ?DateTimeImmutable $expiresAt = null, string $audience = self::RESOURCE): string
    {
        $jwt = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText(config('passport.private_key')),
            InMemory::plainText(config('passport.public_key')),
        );
        $claims = $jwt->parser()->parse($original)->claims();

        return $jwt->builder()
            ->permittedFor($audience)
            ->identifiedBy($claims->get('jti'))
            ->relatedTo($claims->get('sub'))
            ->issuedAt(new DateTimeImmutable('-2 minutes'))
            ->canOnlyBeUsedAfter(new DateTimeImmutable('-2 minutes'))
            ->expiresAt($expiresAt ?? new DateTimeImmutable('+1 hour'))
            ->withClaim('scopes', $claims->get('scopes'))
            ->getToken($jwt->signer(), $jwt->signingKey())
            ->toString();
    }
}
