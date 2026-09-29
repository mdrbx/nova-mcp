# Architecture

[← Back to the README](../README.md)

Nova MCP adapts a bounded set of Nova HTTP operations to Laravel MCP tools. Nova
remains responsible for resource queries, policies, field visibility, validation,
and model changes. The adapter does not maintain a second CRUD implementation.

## OAuth and application isolation

The package has a dedicated OAuth authorization server using Passport's clients,
token persistence, keys, and authorization flow. Its repositories, scopes,
lifetimes, endpoints, and Blade consent view are local to that server. It does
not replace the application's Passport server, guard, scope definitions, or
authorization view. The Nova session guard supplies the authenticated user;
their model does not need Passport's `HasApiTokens` trait. The package always
uses `nova.guard`, or the application's default guard when Nova leaves it null.
A separate package guard could give Nova's middleware and OAuth different users.

Public clients register dynamically and use authorization code flow with S256
PKCE. Remote redirect URIs must match the configured allowlist exactly. Metadata
advertises the authorization response issuer parameter, and OAuth redirects to
registered clients identify the issuer with `iss` (RFC 9207). The default
authentication challenge requests `nova:read`. Writes require an explicit
`nova:write` grant as well as the relevant Nova permissions.

The canonical resource identifier is `APP_URL` plus `nova-mcp.path`; the issuer
adds `/oauth`. These values never come from the incoming Host header. A token's
audience must match that resource, and its persisted client must belong to the
package's `nova_mcp_clients` registry, the same resource, and the Nova user
provider. Ordinary application Passport clients and tokens do not satisfy these
checks. Registration also binds authorization codes and refreshes to that client
and resource.

Set the final public `APP_URL` and endpoint path before connecting clients. A
change invalidates existing connections: old tokens have the wrong audience,
and registered clients belong to the old resource. Clients must register and
authorize again. Revocation removes the user's access and refresh tokens for
the connection; token issuance and revocation coordinate through the client
registry to prevent a concurrent refresh from restoring access.

## Nova request boundary

An MCP request validates the bearer token before running Nova's API middleware
and the Tool's visibility check. Browser cookies cannot override the token's
identity. This boundary initializes Nova's request lifecycle once, so serving
callbacks can register resources and apply application behavior before catalog
discovery. Catalog contents and write availability are evaluated for that user
and the current scopes on every request.

`nova-mcp.read_only` removes every mutation from the catalog and rejects writes
again at dispatch time. Its default is false; Nova policies and the OAuth write
scope continue to decide access. Existing write tokens cannot bypass read-only
mode. `nova-mcp.guardrails` is appended to the protocol's server instructions,
separately from the operation guide. The text is configurable through `.env` and
Laravel's configuration cache. It guides clients but cannot enforce confirmation
or data handling rules; permissions and read-only mode remain server checks.

Each tool makes an internal JSON request through a cloned Laravel router. The
operation determines an allowed method, Nova route, and controller; the adapter
verifies that the matched route uses the expected controller. It rejects inputs
that could override the method, resource, or relationship context. There is no
arbitrary URL, controller, or Eloquent operation available to a client.

Relationship targets come from an authorized, visible Nova field on a parent
record available through its detail query and view policy. Clients supply a field
attribute and, for attachment/detachment, one related ID. They cannot supply Nova's
internal relationship routing parameters. The related resource must also satisfy
the package allowlist/exclusions and Nova visibility. Native controllers apply
relatable queries, pivot validation, add/attach/detach policies and callbacks.
Undefined relationship policy methods keep Nova's documented defaults.

Replication uses Nova's replication form followed by ordinary creation, matching
Nova's own form workflow instead of copying raw model attributes. Restore and
force-delete tools are offered only for soft-deletable resources.

Resource actions are discovered through Nova and executed through its action
controller with one selected ID. The adapter rejects `ShouldQueue` and standalone
actions, while Nova retains `canSee`, `canRun`, `runAction`, destructive-action
fallbacks, validation and action history. An application's synchronous action may
still perform its own side effects, including dispatching its own jobs. The
package does not override application business behavior or force jobs to run inline.

The subrequest retains Nova's operation middleware and policy checks. Session,
cookie, CSRF, and serving-event middleware already handled by the boundary are
excluded from the subrequest. Dispatching Nova's serving event twice can replace
the JSON exception handler and repeat application callbacks. Bearer requests
use token authentication rather than CSRF; browser consent and connection
management retain session authentication and CSRF protection.

The adapter restores request, route, router, guard, user resolver, locale, and
model strictness state after dispatch, including failures. The bearer boundary
also restores locale and model strictness when Nova access or Tool authorization
fails before any operation runs; Nova's serving middleware otherwise skips its
cleanup on exceptions. This matters when several operations run in one MCP
request or the application handles subsequent requests in the same process.

## Responses and side effects

Nova normally renders some errors through Inertia, which would make MCP depend
on browser assets. During the internal request, Laravel's standard exception
handler renders JSON while forwarding exception reporting to the application's
handler. The host keeps its reporting behavior; custom host error-page rendering
does not replace the MCP JSON response. Client errors retain messages and
validation errors without debug traces, and server errors return a generic
message.

Successful responses contain Nova's status and JSON body. Index results omit
repeated presentation metadata to fit ToolSearch's output limit, retaining field
values and pagination. Form and detail tools provide the corresponding metadata.
Writes still trigger the application's normal Nova callbacks, model events, and
other side effects. A ToolSearch batch runs sequentially and is not a database
transaction: earlier successful writes remain committed if a later call fails.
Nova can skip denied deletion/detachment operations or return an action `danger`
message with HTTP 200. The adapter preserves these responses; a successful HTTP
status alone does not prove that the requested change happened.

The Nova Tool links to server-rendered Blade pages with package CSS. It requires
no JavaScript component, frontend build, worker, or scheduler.
