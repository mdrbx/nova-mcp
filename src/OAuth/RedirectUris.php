<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\OAuth;

class RedirectUris
{
    public function allows(string $uri): bool
    {
        $candidate = $this->parts($uri);

        if ($candidate === null) {
            return false;
        }

        foreach (config('nova-mcp.redirect_uris', []) as $allowed) {
            $registered = is_string($allowed) ? $this->parts($allowed) : null;

            if ($registered === null || $candidate['scheme'] !== $registered['scheme'] || $candidate['host'] !== $registered['host']) {
                continue;
            }

            $loopback = in_array($registered['host'], ['localhost', '127.0.0.1', '[::1]'], true);

            // Native clients choose an ephemeral loopback port; remote callbacks match exactly.
            if (! $loopback || $registered['port'] !== null) {
                $defaultPort = $registered['scheme'] === 'https' ? 443 : 80;

                if (($candidate['port'] ?? $defaultPort) !== ($registered['port'] ?? $defaultPort)) {
                    continue;
                }
            }

            if ($loopback && $registered['path'] === '/' && $registered['query'] === null) {
                return true;
            }

            if ($candidate['path'] === $registered['path'] && $candidate['query'] === $registered['query']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{scheme: string, host: string, port: ?int, path: string, query: ?string}|null */
    private function parts(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? null,
        ];
    }
}
