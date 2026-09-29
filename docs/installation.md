# Installation

[← Back to the README](../README.md)

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

## Install the package

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

## What the installer does

The installer publishes missing configuration and Passport migrations, and
generates a Passport key pair only when neither key exists. It preserves existing
files and inline key configuration. An incomplete key pair stops installation;
restore the matching pair rather than replacing an existing key. Migrations are
never run automatically. Review them before running them in an existing application.
The package does not require changes to your user model or a `HasApiTokens` trait.
It uses its own OAuth endpoints without replacing the application's Passport
guard, authorization view, scopes, or token lifetimes.

## Custom Nova menus

If you define a custom `Nova::mainMenu`, include the tool's menu explicitly:

```php
(new NovaMcp)->menu($request),
```

## Next step

[Connect your MCP client](clients.md), or [adjust the configuration](configuration.md).
