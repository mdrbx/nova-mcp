# Security

Report vulnerabilities privately through **Security → Report a vulnerability**
on the `mdrbx/nova-mcp` GitHub repository when private reporting is enabled.
If that option is unavailable, open an issue asking the maintainer for a private
reporting channel, without publishing the vulnerability or exploit details.

Include affected versions, a minimal reproduction using sample data, the
permissions of the affected user, and the expected and actual behavior.
Never include access tokens, refresh tokens, OAuth private keys, Nova license
keys, or production data.

Security fixes target the latest released version. Unreleased code has no
stability guarantee.

## Access boundaries

Nova MCP acts as the authenticated Nova user. OAuth scopes limit which operations
the client can request; Nova policies, resource queries, field visibility, and
validation remain responsible for application access control. Resource callbacks
and model events run with their normal application behavior.

Treat client names and resource content as untrusted input. A client registration
does not verify the identity of its publisher. Approve only the permissions you
intend to grant, and use the Nova connections page to revoke access.

Keep the production endpoint behind HTTPS. Do not record OAuth credentials in
request logs, error trackers, screenshots, or issue reports. Laravel Nova remains
a separately licensed dependency and must not be redistributed with a report.
