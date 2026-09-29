# Operations reference

[← Back to the README](../README.md)

## Discover tools

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

## Working with forms, relationships and actions

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

## Compatibility boundaries

Queued, standalone and pivot actions, lenses, uploads, pivot updates and bulk
selections are not exposed. Custom fields that depend on browser-side behavior
may need additional integration. There is no direct Eloquent or arbitrary API
request tool.

## Catalog limits

Laravel MCP currently defaults to 25 calls and 65,536 bytes per ToolSearch batch.
The package trims index presentation metadata while retaining field values and
pagination. Request smaller pages when records have large field values.

Calls run in order and stop on the first error. A batch is not a transaction:
earlier writes remain committed if a later call fails or output exceeds the
limit. Do not retry an entire write batch without checking completed operations.
See [Laravel MCP's searchable catalogs](https://laravel.com/framework/docs/13.x/mcp#searchable-tool-catalogs).

For the implementation details, see [the architecture notes](architecture.md).
