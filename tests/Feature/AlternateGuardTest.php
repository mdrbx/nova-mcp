<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Feature;

use Mdrbx\NovaMcp\Tests\Fixtures\User;
use Mdrbx\NovaMcp\Tests\TestCase;

class AlternateGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'nova.guard' => 'staff',
            'auth.guards.staff' => ['driver' => 'session', 'provider' => 'staff-users'],
            'auth.providers.staff-users' => ['driver' => 'eloquent', 'model' => User::class],
        ]);
    }

    public function test_the_package_uses_the_nova_guard_and_provider_without_changing_the_default_guard(): void
    {
        $user = $this->user();
        $this->record($user, ['name' => 'Staff record']);
        $token = $this->oauthToken($user);
        auth()->setDefaultDriver('web');

        $result = $this->execute($token, [['name' => 'nova-records-index', 'arguments' => []]]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $this->entry($result)['body']['total']);
        $this->assertSame('web', auth()->getDefaultDriver());
        $this->assertDatabaseHas('oauth_clients', ['id' => $token['client_id'], 'provider' => 'staff-users']);
    }

    public function test_another_browser_guard_cannot_replace_the_bearer_identity(): void
    {
        $browserUser = $this->user();
        $this->record($browserUser, ['name' => 'Browser user record']);
        $staffUser = $this->user();
        $staffRecord = $this->record($staffUser, ['name' => 'Token owner record']);
        $token = $this->oauthToken($staffUser);
        $this->actingAs($browserUser, 'web');

        $result = $this->execute($token, [['name' => 'nova-records-index', 'arguments' => []]]);

        $this->assertTrue($result['ok']);
        $resources = $this->entry($result)['body']['resources'];
        $this->assertCount(1, $resources);
        $this->assertSame((string) $staffRecord->id, (string) $resources[0]['id']['value']);
        $this->assertSame($browserUser->id, auth('web')->id());
    }
}
