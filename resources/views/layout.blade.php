<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('title') · {{ config('nova.name', config('app.name')) }}</title>
        <link rel="stylesheet" href="{{ route('nova-mcp.styles') }}">
    </head>
    <body>
        <header class="topbar"><span class="brand">{{ config('nova.name', config('app.name')) }}</span><span class="badge">{{ __('MCP') }}</span></header>
        <main>@yield('content')</main>
        <footer>{{ __('Powered by Nova MCP') }}</footer>
    </body>
</html>
