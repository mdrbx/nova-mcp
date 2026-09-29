# Configuration

[← Back to the README](../README.md)

## Choose which resources to expose

The installer publishes `config/nova-mcp.php`. Defaults expose every resource
the user can access in Nova. Use `resources` to allow specific resource classes,
or `excluded_resources` to omit classes from the catalog. These restrictions
apply in addition to Nova's permissions.

## Read-only mode and guardrails

Writes are enabled subject to Nova policies and the `nova:write` OAuth scope.
Use `.env` to switch the entire MCP to read-only or customize its client instructions:

```dotenv
NOVA_MCP_READ_ONLY=true
NOVA_MCP_GUARDRAILS="Ask for confirmation before destructive changes. Never export personal data."
```

`NOVA_MCP_READ_ONLY` defaults to `false`. Setting it to `true` hides and blocks all
mutations and action execution, including for existing tokens. `NOVA_MCP_GUARDRAILS`
replaces the default guardrail text in the MCP server announcement; these are
instructions for the client, not permission checks. Do not put secrets in them.
Rebuild Laravel's configuration cache after changes if your deployment uses it:

```bash
php artisan config:cache
```

## Endpoint, authentication and token lifetimes

The default endpoint is `/nova-mcp`. Authentication uses `nova.guard`, falling
back to the application's default guard when Nova has none configured.
Access tokens expire after 60 minutes and refresh tokens after 30 days; the
`access_token_ttl` and `refresh_token_ttl` settings use minutes and days
respectively.

Set `APP_URL` to the application's public URL before connecting clients. OAuth
uses `APP_URL` and `path` as the resource identity; changing either requires
clients to register and authorize again.

## Client callbacks and browser origins

`redirect_uris` contains the expected ChatGPT and Claude OAuth callbacks. If a
client reports a different callback, add the exact HTTPS URL after checking its
official documentation. Remote callbacks must match the allowlist. `allowed_origins`
accepts additional browser origins; the application's own origin is accepted by
default. Neither setting changes which resources or operations a user can access.

See [client connections and permissions](clients.md) for OAuth scopes and consent.
