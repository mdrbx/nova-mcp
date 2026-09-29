<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Illuminate\Support\ServiceProvider;
use Mdrbx\NovaMcp\Console\InstallCommand;

class NovaMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-mcp.php', 'nova-mcp');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nova-mcp');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/nova-mcp.php' => config_path('nova-mcp.php')], 'nova-mcp-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/nova-mcp')], 'nova-mcp-views');
            $this->commands([InstallCommand::class]);
        }
    }
}
