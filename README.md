# MT MCP

Standalone WordPress plugin that exposes your site as an [MCP](https://modelcontextprotocol.io) server for AI agents (Cursor, Claude Desktop, etc.).

Agents **sign in as a WordPress user** via OAuth 2.1 (PKCE) or Application Passwords. There are **no shared API keys**. Tool calls respect that user’s capabilities and show up in the signed audit log under their login.

## Install

```bash
ln -s /Users/gareth/Sites/ai/mt-mcp /path/to/wp-content/plugins/mt-mcp
```

Activate **MT MCP** → **Settings → MT MCP**.

## Connect (Cursor)

Use a **site-specific server key** so agents can keep many WordPress MCPs side by side:

```json
{
  "mcpServers": {
    "propio-local-mcp": {
      "url": "https://propio.d.mt.com:8891/mt-mcp"
    }
  }
}
```

The Connect tab suggests a key from the hostname (`propio.d.mt.com` → `propio-local-mcp`, `foo.dev…` → `foo-dev-mcp`). On first use the client discovers OAuth, opens a browser, and you choose **read only** or **read & write**.

Application Passwords (Users → Profile) are also supported for scripts.

## Auth

| Mode | Behavior |
|------|----------|
| `oauth` (default) | OAuth 2.1 only |
| `both` | OAuth + Application Passwords |

## Endpoints

| URL | Purpose |
|-----|---------|
| `/mt-mcp` | MCP JSON-RPC |
| `/wp-json/mt-mcp/v1` | REST fallback |
| `/wp-json/mt-mcp/v1/health` | Uptime (no auth) |
| `/.well-known/oauth-protected-resource` | RFC 9728 |
| `/.well-known/oauth-authorization-server` | RFC 8414 |
| `/mt-mcp/oauth/*` | Authorize / token / register / revoke |

## WP-CLI

```bash
wp mt-mcp status
wp mt-mcp revoke-user 3
wp mt-mcp flush
```

## wp-config

```php
define( 'MT_MCP_ENVIRONMENT', 'staging' ); // production | staging | development | unknown
```

## Roadmap

See [FEATURES.md](./FEATURES.md).
