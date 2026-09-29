@extends('nova-mcp::layout')
@section('title', __('Authorize connection'))
@section('content')
    <div class="consent">
        <p class="eyebrow">{{ __('Connection request') }}</p>
        <h1>{{ __('Authorize :client?', ['client' => $client->name]) }}</h1>
        <p class="intro">{{ __('This client is asking to use your Nova account through MCP.') }}</p>
        <section class="card">
            <h2>{{ __('Requested access') }}</h2>
            <ul class="permissions">
                @foreach($scopes as $scope)
                    <li><strong>{{ __($scope->description) }}</strong><code>{{ $scope->id }}</code></li>
                @endforeach
            </ul>
            <p>{{ __('Nova checks your permissions on every request. Write access includes record changes, relationships, restoration, permanent deletion, and synchronous actions. Server read-only mode blocks these operations.') }}</p>
            <p class="hint">{{ __('Only approve a client you trust. You can revoke access from MCP connections in Nova.') }}</p>
            <div class="actions">
                <form method="post" action="{{ route('nova-mcp.oauth.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <button class="button" type="submit">{{ __('Cancel') }}</button>
                </form>
                <form method="post" action="{{ route('nova-mcp.oauth.approve') }}">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <button class="button primary" type="submit">{{ __('Authorize') }}</button>
                </form>
            </div>
        </section>
    </div>
@endsection
