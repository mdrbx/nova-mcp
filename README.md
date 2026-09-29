# Nova MCP - Zero configuration connector

Expose Laravel Nova resources to MCP clients through the same Nova operations,
policies, field visibility, and validation used by your application.

Users connect with OAuth and manage their connections from a Nova Tool, using a Blade consent screen backed by Laravel Passport.

![Nova MCP connections page in the local demo](docs/images/nova-connections.png)

Captured from the running local demo with PHP's development server and SQLite.

## Requirements

| Dependency | Version |
| --- | --- |
| PHP | 8.3+ |
| Laravel | 12.41.1+ or 13.x |
| Laravel Nova | 5.11+ |
| Laravel MCP | 1.0.1+ |
| Laravel Passport | 13.8+ |

Nova requires a separate license. This package does not include Nova source or
assets. The Composer constraints define the supported major versions; they do
not imply support for every third-party Nova field or customization.

## Install

In an application where Nova is already installed:

```bash
composer require mdrbx/nova-mcp
php artisan nova-mcp:install
```

Before migrating, check your user ID type. Passport's published `user_id` columns
default to unsigned big integers. For UUID or ULID users, adapt those columns to
the application's identifier type before running the migrations. Existing
Passport installations should retain their compatible schema.

```bash
php artisan migrate
```

Add the tool to your existing `NovaServiceProvider::tools()` method:

```php
use Mdrbx\NovaMcp\NovaMcp;

public function tools(): array
{
    return [
        new NovaMcp,
    ];
}
```

Keep any other tools already returned by that method. Open **MCP connections**
in Nova to find your server URL and manage connected clients.

The installer publishes missing configuration and Passport migrations, and
generates a Passport key pair only when neither key exists. It preserves existing
files and inline key configuration. An incomplete key pair stops installation;
restore the matching pair rather than replacing an existing key. Migrations are
never run automatically. Review them before running them in an existing application.
The package does not require changes to your user model or a `HasApiTokens` trait.
It uses its own OAuth endpoints without replacing the application's Passport
guard, authorization view, scopes, or token lifetimes.

If you define a custom `Nova::mainMenu`, include the tool's menu explicitly:

```php
(new NovaMcp)->menu($request),
```

## Configuration

The installer publishes `config/nova-mcp.php`. Defaults expose every resource
the user can access in Nova. Use `resources` to allow specific resource classes,
or `excluded_resources` to omit classes from the catalog. These restrictions
apply in addition to Nova's permissions.

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
Rebuild Laravel's configuration cache after changes if your deployment uses it.

The default endpoint is `/nova-mcp`. Authentication uses `nova.guard`, falling
back to the application's default guard when Nova has none configured.
Access tokens expire after 60 minutes and refresh tokens after 30 days; the
`access_token_ttl` and `refresh_token_ttl` settings use minutes and days
respectively.

Set `APP_URL` to the application's public URL before connecting clients. OAuth
uses `APP_URL` and `path` as the resource identity; changing either requires
clients to register and authorize again.

`redirect_uris` contains the expected ChatGPT and Claude OAuth callbacks. If a
client reports a different callback, add the exact HTTPS URL after checking its
official documentation. Remote callbacks must match the allowlist. `allowed_origins`
accepts additional browser origins; the application's own origin is accepted by
default. Neither setting changes which resources or operations a user can access.

## Connect a client

Use the complete server URL shown in Nova. Choose **OAuth** authentication. Public
clients register dynamically, so you do not need to create or copy a client
secret. Sign in with your Nova account and review the consent screen.

For cloud clients, the server must be reachable from the client provider. Use
HTTPS in production. A PHP development server and SQLite are sufficient for
local package development, but `localhost` is not a remotely reachable address.

## Permissions

| Scope | Access |
| --- | --- |
| `nova:read` | Discover and read resources available to the Nova user. |
| `nova:write` | Modify records and relationships, restore or permanently delete records, and run synchronous actions. |
| `offline_access` | Request continued access through a refresh token. |

The authentication challenge requests read access by default. Write access must
be requested and approved explicitly; a write scope never overrides Nova
policies. Refresh and access tokens can be revoked from **MCP connections**.

![Nova MCP consent screen requesting read, write, and offline access](docs/images/oauth-consent.png)

Consent screen from the running local demo. This example requests all three
scopes; a client that only needs to read resources should request `nova:read`.

Resource policies, query restrictions, hidden fields, validation rules, model
events, and resource callbacks keep their application behavior. Hooks may send
notifications or perform other side effects. Review the application's existing
Nova permissions before granting a client write access.

## Available operations

The MCP server advertises Laravel MCP's `search_tools` and `execute_tools`. The
searchable catalog contains tools named `nova-{resource-uri-key}-{operation}`.

| Operation | Behavior |
| --- | --- |
| `index` | List and search resources, with pagination, ordering, and Nova filters. |
| `show` | Read one resource. |
| `create-fields` | Read Nova creation field metadata. |
| `update-fields` | Read Nova update field metadata for one resource. |
| `replicate-fields` | Read the authorized replication form; submit the chosen values with `store`. |
| `filters` | List the resource's available Nova filters. |
| `attachable` / `attach-fields` | Discover related candidates and pivot fields for a visible many-to-many relationship. |
| `add-fields` | Read the child creation form for a visible HasMany or MorphMany relationship. |
| `actions` | List visible synchronous resource actions and their fields for one record. |
| `store` | Create a resource using Nova's JSON field payload. |
| `update` | Update a resource using Nova's JSON field payload. |
| `destroy` | Request deletion of one resource. |
| `restore` / `force-delete` | Restore or permanently delete one soft-deletable resource. |
| `attach` / `detach` | Attach or detach one related ID through a BelongsToMany or MorphToMany field. |
| `add` | Create a related HasMany or MorphMany record through Nova. |
| `run-action` | Run one synchronous resource action on one selected ID. |

Discover field metadata before writing. Nova validates the payload and decides
whether the user can perform the operation. A denied deletion may follow Nova's
native behavior of skipping the record rather than returning an HTTP error.

Relationship tools take the parent `id` and the Nova field's `relationship`
attribute; `attach` and `detach` also take `related_id`. Form values belong in
`data`. `run-action` takes `id`, the exact `action` URI key, and `data`.

Nova's [policy defaults](https://nova.laravel.com/docs/v5/resources/authorization#undefined-policy-methods)
still apply, including permission to add/attach/detach when those policy methods
are absent. Actions retain Nova's `canSee`, `canRun`, `runAction` and
`runDestructiveAction` behavior. A refused action may return a `danger` message
with HTTP 200; inspect the body before reporting success.

Queued, standalone and pivot actions, lenses, uploads, pivot updates and bulk
selections are not exposed. Custom fields that depend on browser-side behavior
may need additional integration. There is no direct Eloquent or arbitrary API
request tool.

### Catalog limits

Laravel MCP currently defaults to 25 calls and 65,536 bytes per ToolSearch batch.
The package trims index presentation metadata while retaining field values and
pagination. Request smaller pages when records have large field values.

Calls run in order and stop on the first error. A batch is not a transaction:
earlier writes remain committed if a later call fails or output exceeds the
limit. Do not retry an entire write batch without checking completed operations.
See [Laravel MCP's searchable catalogs](https://laravel.com/framework/docs/13.x/mcp#searchable-tool-catalogs).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for licensed Nova setup, tests, and CI.
The [architecture notes](docs/architecture.md) explain OAuth isolation, Nova's
request lifecycle, and the adapter's compatibility boundaries.

```bash
composer test
composer analyse
composer format:check
composer refactor:check
```

Report vulnerabilities using [SECURITY.md](SECURITY.md). Changes are recorded in
[CHANGELOG.md](CHANGELOG.md).

## License

MIT, copyright Matthieu Deroubaix. See [LICENSE.md](LICENSE.md).
Laravel Nova is a separate, commercially licensed dependency. Nova MCP is an
independent community package and is not an official Laravel product.
