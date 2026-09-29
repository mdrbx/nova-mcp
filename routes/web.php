<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Mdrbx\NovaMcp\Http\Controllers\ConnectionsController;
use Mdrbx\NovaMcp\Http\Controllers\StylesController;
use Mdrbx\NovaMcp\Http\Middleware\AuthenticateMcp;
use Mdrbx\NovaMcp\Http\Middleware\AuthorizeTool;
use Mdrbx\NovaMcp\Http\Middleware\EnsureOAuthRequest;
use Mdrbx\NovaMcp\OAuth\AuthorizationController;
use Mdrbx\NovaMcp\OAuth\MetadataController;
use Mdrbx\NovaMcp\OAuth\RegistrationController;
use Mdrbx\NovaMcp\OAuth\TokenController;
use Mdrbx\NovaMcp\Server;
use Mdrbx\NovaMcp\Support\NovaContext;

$path = trim(config('nova-mcp.path'), '/');

Route::get(".well-known/oauth-protected-resource/{$path}", [MetadataController::class, 'resource'])->name('nova-mcp.metadata.resource');
Route::get(".well-known/oauth-authorization-server/{$path}/oauth", [MetadataController::class, 'authorization'])->name('nova-mcp.metadata.authorization');

Route::middleware([AuthenticateMcp::class, 'throttle:120,1'])->group(function () use ($path): void {
    Mcp::web($path, Server::class)
        ->withoutMiddleware(AddWwwAuthenticateHeader::class)
        ->name('nova-mcp.endpoint');
});

Route::prefix($path)->name('nova-mcp.')->group(function (): void {
    Route::get('assets/styles.css', StylesController::class)->name('styles');
    Route::post('oauth/register', RegistrationController::class)->middleware('throttle:20,1')->name('oauth.register');
    Route::post('oauth/token', TokenController::class)->middleware('throttle:60,1')->name('oauth.token');

    Route::middleware(['web', 'auth:'.app(NovaContext::class)->guardName(), 'nova:api', AuthorizeTool::class])->group(function (): void {
        Route::get('connections', [ConnectionsController::class, 'index'])->name('connections.index');
        Route::delete('connections/{clientId}', [ConnectionsController::class, 'destroy'])->name('connections.destroy');

        Route::middleware(EnsureOAuthRequest::class)->group(function (): void {
            Route::get('oauth/authorize', AuthorizationController::class)->name('oauth.authorize');
            Route::post('oauth/authorize', [AuthorizationController::class, 'approve'])->name('oauth.approve');
            Route::delete('oauth/authorize', [AuthorizationController::class, 'deny'])->name('oauth.deny');
        });
    });
});
