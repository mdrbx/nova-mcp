<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mdrbx\NovaMcp\Models\OAuthClient;
use Mdrbx\NovaMcp\Scopes;
use Mdrbx\NovaMcp\Support\NovaContext;

class ConnectionsController
{
    public function __construct(private readonly NovaContext $context) {}

    public function index(Request $request): Response
    {
        $clients = $this->clients()->whereHas('tokens', function (Builder $query) use ($request): void {
            $query->where('user_id', $request->user()->getAuthIdentifier())
                ->whereJsonContains('scopes', Scopes::READ)
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('revoked', false)->where('expires_at', '>', now());
                    })->orWhereHas('refreshToken', function (Builder $query): void {
                        $query->where('revoked', false)->where('expires_at', '>', now());
                    });
                });
        })->orderBy('name')->simplePaginate(20);

        return response()->view('nova-mcp::connections', [
            'clients' => $clients,
            'endpoint' => $this->context->endpoint(),
            'novaUrl' => url(config('nova.path', 'nova')),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function destroy(Request $request, string $clientId): RedirectResponse
    {
        Passport::client()->getConnection()->transaction(function () use ($request, $clientId): void {
            // Token exchange takes this same lock, preventing refresh from racing a revocation.
            OAuthClient::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
            $client = $this->clients()->whereKey($clientId)->firstOrFail();
            $tokens = $client->tokens()->where('user_id', $request->user()->getAuthIdentifier())
                ->whereJsonContains('scopes', Scopes::READ);

            abort_unless($tokens->exists(), 404);

            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokens->select('id'))->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);
            Passport::authCode()->newQuery()->where('client_id', $clientId)
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->whereJsonContains('scopes', Scopes::READ)->update(['revoked' => true]);
        });

        return redirect()->route('nova-mcp.connections.index')->with('status', __('Connection revoked.'));
    }

    /** @return Builder<Client> */
    private function clients(): Builder
    {
        return Passport::client()->newQuery()->where('provider', $this->context->providerName())
            ->where('revoked', false)
            ->whereIn('id', OAuthClient::query()->select('client_id')->where('resource', $this->context->endpoint()));
    }
}
