<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaCoreServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Mdrbx\NovaMcp\NovaMcpServiceProvider;
use Mdrbx\NovaMcp\Tests\Fixtures\NovaServiceProvider;
use Mdrbx\NovaMcp\Tests\Fixtures\Record;
use Mdrbx\NovaMcp\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;

    protected const RESOURCE = 'http://localhost/nova-mcp';

    protected const REDIRECT_URI = 'https://client.example/callback';

    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        URL::forceRootUrl('http://localhost');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        // Multiple HTTP exchanges share one test application; real requests receive fresh PSR messages and once caches.
        $this->app->forgetInstance(ServerRequestInterface::class);
        Once::flush();
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tearDown(): void
    {
        Nova::flushState();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            PassportServiceProvider::class,
            McpServiceProvider::class,
            NovaCoreServiceProvider::class,
            NovaServiceProvider::class,
            NovaMcpServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $privateKey);
            self::$keys = ['private' => $privateKey, 'public' => openssl_pkey_get_details($key)['key']];
        }

        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'app.url' => 'http://localhost',
            'app.locale' => 'en',
            'app.debug' => false,
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'auth.defaults.guard' => 'web',
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => User::class],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'passport.private_key' => self::$keys['private'],
            'passport.public_key' => self::$keys['public'],
            'nova.guard' => 'web',
            'nova.path' => 'nova',
            'nova.pagination' => 'links',
            'nova-mcp.redirect_uris' => [self::REDIRECT_URI],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passport/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/nova/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn (): string => 'Sign in')->name('login');
        $router->get('/nova/login', fn (): string => 'Nova sign in')->name('nova.login');
    }

    protected function user(array $attributes = []): User
    {
        return User::query()->create($attributes + [
            'name' => 'Taylor Example',
            'email' => 'user-'.User::query()->count().'@example.test',
            'can_use_nova' => true,
        ]);
    }

    protected function record(User $owner, array $attributes = []): Record
    {
        return Record::query()->create($attributes + [
            'owner_id' => $owner->getKey(),
            'name' => 'Fixture record',
            'secret' => 'server secret',
        ]);
    }

    protected function registerClient(string $name = 'Example MCP client'): array
    {
        return $this->postJson(route('nova-mcp.oauth.register'), [
            'client_name' => $name,
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json();
    }

    protected function authorizationParameters(array $client, array $scopes = ['nova:read']): array
    {
        return [
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'test-client-state',
            'resource' => self::RESOURCE,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ];
    }

    protected function authorizationCode(User $user, array $client, array $scopes = ['nova:read']): string
    {
        $parameters = $this->authorizationParameters($client, $scopes);

        $this->actingAs($user, config('nova.guard'))->get(route('nova-mcp.oauth.authorize').'?'.http_build_query($parameters))->assertOk();

        $approval = $this->post(route('nova-mcp.oauth.approve'), [
            'auth_token' => session('authToken'),
            'client_id' => $client['client_id'],
            'state' => $parameters['state'],
        ])->assertRedirect();

        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('test-client-state', $query['state']);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame(self::RESOURCE.'/oauth', $query['iss']);

        return $query['code'];
    }

    protected function oauthToken(User $user, array $scopes = ['nova:read'], ?array $client = null): array
    {
        $client ??= $this->registerClient();
        $code = $this->authorizationCode($user, $client, $scopes);

        $token = $this->postJson(route('nova-mcp.oauth.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => str_repeat('v', 64),
            'resource' => self::RESOURCE,
        ])->assertOk()->json();

        auth()->forgetGuards();
        session()->flush();

        return $token + ['client_id' => $client['client_id']];
    }

    protected function rpc(string $method, array $params = [], ?string $token = null, array $headers = []): TestResponse
    {
        return $this->postJson(route('nova-mcp.endpoint'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ], $headers + ($token === null ? [] : ['Authorization' => "Bearer {$token}"]));
    }

    protected function toolResult(TestResponse $response): array
    {
        $response->assertOk();

        if ($response->baseResponse instanceof StreamedResponse) {
            preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $messages);
            $payload = json_decode(end($messages[1]), true, flags: JSON_THROW_ON_ERROR);

            return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        }

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function execute(array $token, array $calls): array
    {
        return $this->toolResult($this->rpc('tools/call', [
            'name' => 'execute_tools',
            'arguments' => ['calls' => $calls],
        ], $token['access_token']));
    }

    protected function entry(array $result, int $index = 0): array
    {
        return json_decode($result['results'][$index]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }
}
