<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Nova\NovaCoreServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Mdrbx\NovaMcp\NovaMcpServiceProvider;
use Mdrbx\NovaMcp\Tests\Fixtures\NovaServiceProvider;
use Mdrbx\NovaMcp\Tests\Fixtures\User;
use Orchestra\Testbench\Foundation\Application as Testbench;

require_once __DIR__.'/../vendor/autoload.php';

return Testbench::create(
    resolvingCallback: function (Application $app): void {
        $app->booting(function () use ($app): void {
            $app['config']->set([
                'app.url' => 'http://localhost',
                'auth.providers.users.model' => User::class,
                'nova.guard' => 'web',
            ]);
        });
    },
    options: ['extra' => ['providers' => [
        InertiaServiceProvider::class,
        PassportServiceProvider::class,
        McpServiceProvider::class,
        NovaCoreServiceProvider::class,
        NovaServiceProvider::class,
        NovaMcpServiceProvider::class,
    ]]],
);
