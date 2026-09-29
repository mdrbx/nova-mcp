<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mdrbx\NovaMcp\Tests\TestCase;

class ConnectionsTest extends TestCase
{
    public function test_connections_requires_login_and_nova_authorization(): void
    {
        $this->get(route('nova-mcp.connections.index'))->assertRedirect();

        $this->actingAs($this->user(['can_use_nova' => false]))
            ->get(route('nova-mcp.connections.index'))->assertForbidden();
    }

    public function test_connections_shows_the_endpoint_and_only_the_current_users_clients(): void
    {
        $owner = $this->user();
        $this->oauthToken($owner, client: $this->registerClient('My Claude client'));
        $this->oauthToken($this->user(), client: $this->registerClient('Someone elses client'));

        $this->actingAs($owner)->get(route('nova-mcp.connections.index'))
            ->assertOk()
            ->assertSee(self::RESOURCE)
            ->assertSee('My Claude client')
            ->assertDontSee('Someone elses client');
    }

    public function test_client_names_are_escaped_in_connection_and_consent_views(): void
    {
        $name = '<script>alert("client")</script>';
        $user = $this->user();
        $client = $this->registerClient($name);

        $this->actingAs($user)->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($this->authorizationParameters($client)))
            ->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(', false);

        $this->oauthToken($user, client: $client);

        $this->actingAs($user)->get(route('nova-mcp.connections.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(', false);
    }

    public function test_revoking_a_connection_invalidates_access_and_refresh_tokens(): void
    {
        $user = $this->user();
        $token = $this->oauthToken($user, ['nova:read', 'offline_access']);

        $this->actingAs($user)->delete(route('nova-mcp.connections.destroy', ['clientId' => $token['client_id']]))->assertRedirect();
        auth()->forgetGuards();
        session()->flush();

        $this->rpc('tools/list', token: $token['access_token'])->assertUnauthorized();
        $this->postJson(route('nova-mcp.oauth.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $token['client_id'],
            'refresh_token' => $token['refresh_token'],
            'resource' => self::RESOURCE,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_user_cannot_revoke_another_users_connection(): void
    {
        $token = $this->oauthToken($this->user());

        $this->actingAs($this->user())->delete(route('nova-mcp.connections.destroy', ['clientId' => $token['client_id']]))->assertNotFound();
        auth()->forgetGuards();
        session()->flush();

        $this->rpc('tools/list', token: $token['access_token'])->assertOk();
    }

    public function test_connection_revocation_remains_csrf_protected(): void
    {
        $user = $this->user();
        $token = $this->oauthToken($user);
        $this->app['env'] = 'production';

        $this->actingAs($user)->delete(route('nova-mcp.connections.destroy', ['clientId' => $token['client_id']]))->assertStatus(419);

        $this->assertDatabaseHas('oauth_access_tokens', ['client_id' => $token['client_id'], 'revoked' => false]);
    }

    public function test_an_expired_access_token_does_not_hide_a_refreshable_connection(): void
    {
        $user = $this->user();
        $client = $this->registerClient('Refreshable client');
        $token = $this->oauthToken($user, ['nova:read', 'offline_access'], $client);
        DB::table('oauth_access_tokens')->where('client_id', $token['client_id'])->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user)->get(route('nova-mcp.connections.index'))->assertOk()->assertSee('Refreshable client');
    }
}
