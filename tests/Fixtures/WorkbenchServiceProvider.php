<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set([
            'auth.providers.users.model' => User::class,
            'nova.guard' => 'web',
            'nova.name' => 'Nova MCP Demo',
            'nova-mcp.redirect_uris' => ['https://client.example/callback'],
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/passport/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/nova/database/migrations');
    }
}
