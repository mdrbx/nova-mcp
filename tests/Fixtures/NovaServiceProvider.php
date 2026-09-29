<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Mdrbx\NovaMcp\NovaMcp;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    protected function fortify(): void {}

    protected function routes(): void
    {
        Nova::routes()->register();
    }

    protected function authorization(): void
    {
        Nova::auth(fn (Request $request): bool => $request->user()?->can_use_nova ?? false);
    }

    protected function gate(): void
    {
        Gate::define('viewNova', fn (User $user): bool => $user->can_use_nova);
        Gate::policy(Record::class, RecordPolicy::class);
    }

    protected function resources(): void
    {
        Nova::replaceResources([RecordResource::class, ForbiddenResource::class]);
    }

    public function tools(): array
    {
        return [(new NovaMcp)->canSee(fn (Request $request): bool => $request->user()?->email !== 'tool-denied@example.test')];
    }
}
