# Contributing

Keep changes focused on a concrete Nova or MCP use case. Explain the observable
behavior, compatibility impact, and how you verified it. Bug fixes should include
a regression test that fails without the fix.

## Local setup

You need PHP 8.3+, Composer, SQLite support, and a valid Laravel Nova license.
The repository uses Nova's Composer repository. Composer will request the Nova
account email and license key when downloading dependencies. You can also supply
credentials through Composer's local authentication configuration or
`COMPOSER_AUTH`; never commit them.

```bash
git clone https://github.com/mdrbx/nova-mcp.git
cd nova-mcp
composer install
composer check
```

See [Nova's installation documentation](https://nova.laravel.com/docs/v5/installation#installing-nova-via-composer)
for authentication details. Nova source and assets must remain outside the public
repository. A license is also required to run the full suite on your fork.

The tests use Orchestra Testbench and SQLite. No running database service,
queue worker, scheduler, or frontend build is needed.

## Checks

| Command | Purpose |
| --- | --- |
| `composer test` | Run package tests. |
| `composer analyse` | Run PHPStan with Larastan at level 6. |
| `composer format` | Apply Pint formatting. |
| `composer format:check` | Check formatting without changes. |
| `composer refactor` | Apply configured Rector rules. |
| `composer refactor:check` | Check Rector changes without applying them. |
| `composer check` | Run the package's required checks. |

Prefer type declarations and precise PHPDoc for collections over suppressed
analysis errors. Comment the reason for an authorization constraint, compatibility
workaround, or state-restoration step; avoid comments that repeat the code.

Test HTTP boundaries with real Nova resources and sample policies. Changes to
authentication need denied cases as well as successful calls. Keep the host's
Passport configuration, Nova guard, user model, and other MCP endpoints isolated.
Do not add browser components or build tooling for a page that Blade can render.

## CI and Nova credentials

The CI workflow always validates Composer metadata and PHP syntax. Full tests,
PHPStan, Rector, and Pint require the licensed Nova dependency. Repository
maintainers configure `NOVA_USERNAME` and `NOVA_LICENSE_KEY` as GitHub Actions
secrets for those jobs.

Fork pull requests and Dependabot runs do not receive these Actions credentials.
Dependabot uses a separate secret store, even when its branch belongs to this
repository. The workflow skips licensed jobs for both cases and explains the
reduced coverage in the public-source job. A maintainer must review the changes
and run the complete checks on a trusted branch before merging. A passing
public-source job alone does not establish package compatibility. Never switch
this workflow to `pull_request_target` while checking out or executing untrusted
PR code. See [GitHub's Dependabot restrictions](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-on-actions).

The configured matrix covers Laravel 12 and 13 with their matching Testbench
versions. A CI configuration is not evidence that a particular run passed; check
the results for the commit being reviewed. Action dependencies are pinned to
commit hashes and updated through Dependabot.

## Screenshots and documentation

Use a local example application with invented records. Capture the actual Nova
page, OAuth consent, and supported client setup. Exclude private URLs, user data,
tokens, keys, and unrelated account information. Do not use a mockup as evidence
that a client connected successfully. Distinguish setup screenshots from a
completed, tested connection.

Update the README when behavior or installation changes. Keep version-specific
client instructions linked to the client's official documentation.
Update [the architecture notes](docs/architecture.md) when changing OAuth
isolation, Nova request dispatch, state restoration, or error handling.

## Releasing

Before tagging a release:

1. Run `composer check` and confirm the licensed CI matrix passes on the release
   commit.
2. Exercise installation in a clean Nova application and an application that
   already uses Passport. Verify migration and key handling without replacing
   existing configuration.
3. Review `git diff --check` and the files included by `git archive HEAD`. Confirm
   the archive contains no vendor code, credentials, database files, or private
   screenshots.
4. Move the relevant changelog entries into the version being released. Use
   semantic versioning and document breaking configuration or protocol changes.
5. Push the reviewed tag and create its GitHub release. Submit the repository to
   Packagist for the initial release, then verify Composer can resolve the tag.

Publishing the repository, publishing a Composer package, and being listed in a
client's connector directory are separate steps. Do not claim directory approval
or client certification unless it has happened.
