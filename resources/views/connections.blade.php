@extends('nova-mcp::layout')
@section('title', __('MCP connections'))
@section('content')
    <a class="back" href="{{ $novaUrl }}">&larr; {{ __('Back to Nova') }}</a>
    <h1>{{ __('MCP connections') }}</h1>
    <p class="intro">{{ __('Connect an AI client to the resources you can access in Nova.') }}</p>

    @if(session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    <section class="card">
        <h2>{{ __('Connect a client') }}</h2>
        <label for="endpoint">{{ __('Server URL') }}</label>
        <input id="endpoint" type="url" readonly value="{{ $endpoint }}" spellcheck="false">
        <p class="hint">{{ __('Copy this URL into your client and choose OAuth authentication. Sign in with your Nova account to approve access.') }}</p>
        <div class="client-grid">
            <div><h3>ChatGPT</h3><p>{{ __('Enable developer mode, create a custom app or plugin, and enter the server URL.') }}</p><a href="https://developers.openai.com/api/docs/guides/developer-mode" rel="noreferrer">{{ __('Setup guide') }} &nearr;</a></div>
            <div><h3>Claude Desktop</h3><p>{{ __('Open Customize → Connectors, add a custom connector, and enter the server URL.') }}</p><a href="https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp" rel="noreferrer">{{ __('Setup guide') }} &nearr;</a></div>
        </div>
        <p class="hint">{{ __('Cloud clients need a publicly reachable HTTPS server. Your administrator must allow the callback URL supplied by the client.') }}</p>
    </section>

    <section class="card">
        <h2>{{ __('Your connections') }}</h2>
        <p>{{ __('Revoking a connection immediately prevents further requests and token renewal. It does not undo changes already made.') }}</p>
        @forelse($clients as $client)
            <div class="connection">
                <div><strong>{{ $client->name }}</strong><span class="hint">{{ __('Access approved with your Nova account') }}</span></div>
                <form action="{{ route('nova-mcp.connections.destroy', ['clientId' => $client->getKey()]) }}" method="post">
                    @csrf
                    @method('DELETE')
                    <button class="button danger" type="submit">{{ __('Revoke') }}</button>
                </form>
            </div>
        @empty
            <p class="empty">{{ __('No active connections. Add this server in a client to get started.') }}</p>
        @endforelse
        <nav class="pagination" aria-label="{{ __('Connections pagination') }}">
            @if($clients->previousPageUrl())
                <a href="{{ $clients->previousPageUrl() }}">{{ __('Previous') }}</a>
            @endif
            @if($clients->nextPageUrl())
                <a href="{{ $clients->nextPageUrl() }}">{{ __('Next') }}</a>
            @endif
        </nav>
    </section>
@endsection
