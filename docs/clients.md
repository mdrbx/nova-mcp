# Connect your MCP client

[← Back to the README](../README.md)

## Connect with your Nova account

1. Open **MCP connections** in Nova and copy the server URL.
2. Add a remote MCP server in your client and choose **OAuth** authentication.
3. Sign in with your Nova account and approve the requested permissions.
4. Try a read request, such as “Which resources can I access?”

Public clients register dynamically, so you do not need to create or copy a
client secret.

For cloud clients, the server must be reachable from the client provider. Use
HTTPS in production. A PHP development server and SQLite are sufficient for
local package development, but `localhost` is not a remotely reachable address.

## Permissions and consent

| Scope | Access |
| --- | --- |
| `nova:read` | Discover and read resources available to the Nova user. |
| `nova:write` | Modify records and relationships, restore or permanently delete records, and run synchronous actions. |
| `offline_access` | Request continued access through a refresh token. |

The authentication challenge requests read access by default. Write access must
be requested and approved explicitly; a write scope never overrides Nova
policies. Refresh and access tokens can be revoked from **MCP connections**.

![Nova MCP consent screen requesting read, write, and offline access](images/oauth-consent.png)

Consent screen from the running local demo. This example requests all three
scopes; a client that only needs to read resources should request `nova:read`.

Resource policies, query restrictions, hidden fields, validation rules, model
events, and resource callbacks keep their application behavior. Hooks may send
notifications or perform other side effects. Review the application's existing
Nova permissions before granting a client write access.

## Manage connections

Open **MCP connections** to review your connections and revoke access when you
no longer need it. Revocation removes both access and refresh tokens for that
connection.

![Nova MCP connections page](images/nova-connections.png)

Screenshots in this guide come from the local demo running on PHP's development
server and SQLite.

## If a client cannot connect

- Check that the server URL is reachable from the client provider and that
  `APP_URL` matches your public application URL.
- For a callback mismatch, check the client's callback against `redirect_uris`
  in [the configuration guide](configuration.md#client-callbacks-and-browser-origins).
- If writes are unavailable, check the approved `nova:write` scope, Nova policies
  and `NOVA_MCP_READ_ONLY`.
- After changing `APP_URL` or the MCP endpoint path, register and authorize the
  client again.
