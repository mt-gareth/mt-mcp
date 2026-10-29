# MT MCP

Standalone WordPress plugin that exposes your site as an [MCP](https://modelcontextprotocol.io) server for AI agents (Cursor, Claude Desktop, etc.).

Agents **sign in as a WordPress user** via OAuth 2.1 (PKCE) or Application Passwords. There are **no shared API keys**. Tool calls respect that user’s capabilities and show up in the signed audit log under their login.

License: **GPL-2.0-or-later**.

## Install

1. Copy or symlink this folder into `wp-content/plugins/mt-mcp`
2. Activate **MT MCP**
3. Open **Settings → MT MCP**, enable the server, copy the Cursor JSON

```bash
# local symlink example
ln -s /absolute/path/to/mt-mcp /path/to/wp-content/plugins/mt-mcp
```

## Connect (Cursor)

Use a **site-specific server key** so agents can keep many WordPress MCPs side by side:

```json
{
  "mcpServers": {
    "example-local-mcp": {
      "url": "https://example.test/mt-mcp"
    }
  }
}
```

On first connect, Cursor opens a browser; choose **read only** or **read & write**. Application Passwords (Users → Profile) work for scripts when Auth mode is `both`.

## Auth

| Mode | Behavior |
|------|----------|
| `oauth` (default) | OAuth 2.1 only |
| `both` | OAuth + Application Passwords |

DCR redirect URIs are allowlisted (loopback, known Claude/ChatGPT hosts, app schemes, plus Settings → DCR extra hosts).

## Endpoints

| URL | Purpose |
|-----|---------|
| `/mt-mcp` | MCP JSON-RPC |
| `/wp-json/mt-mcp/v1` | REST fallback |
| `/wp-json/mt-mcp/v1/health` | Uptime (no auth) |
| `/.well-known/oauth-*` | OAuth discovery |
| `/mt-mcp/oauth/*` | Authorize / token / register / revoke |

## WP-CLI

```bash
wp mt-mcp status
wp mt-mcp revoke-user 3
wp mt-mcp flush
wp --skip-plugins mt-mcp-guard rollback
```

## wp-config

```php
define( 'MT_MCP_ENVIRONMENT', 'staging' ); // production | staging | development | unknown
define( 'MT_MCP_PUBLIC_BASE', 'https://tunnel.example' ); // optional OAuth advertise URL
define( 'MT_MCP_SSLVERIFY', false ); // optional; only if you must disable TLS verify site-wide
```

## Roadmap / feature lock

v1.3.x is feature-locked for polish and security. See [FEATURES.md](./FEATURES.md) and [readme.txt](./readme.txt).
