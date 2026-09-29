# Changelog

## 0.2.0 — 2026-09-29

- Add native Nova attachment, detachment, child creation, restore and force-delete operations.
- Expose replication forms and synchronous resource actions with Nova's policy behavior.
- Add an environment-configurable read-only switch enforced even for existing write tokens.
- Include configurable application guardrails in MCP server instructions.
- Preserve the simplified README and document operation arguments and permission defaults.

## 0.1.0 — 2026-09-29

- Expose registered Nova resources through Laravel MCP's searchable tool catalog.
- Reuse Nova's HTTP operations, policies, field visibility, and validation.
- Provide OAuth authorization with PKCE, scoped access, and connection revocation.
- Add a PHP Nova Tool with Blade setup and consent pages, without a frontend build.
- Add package tests, PHPStan level 6, Rector, Pint, and licensed Nova CI jobs.
