=== MT MCP ===
Contributors: motiontactic
Tags: mcp, ai, oauth, cursor, claude, acf, rest-api
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.17
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone Model Context Protocol (MCP) server for WordPress. Agents sign in as a WordPress user via OAuth 2.1 — no shared API keys.

== Description ==

MT MCP exposes your WordPress site as an MCP server for AI agents (Cursor, Claude Desktop, and similar). Agents authenticate as a real WordPress user with OAuth 2.1 (PKCE) or Application Passwords. Tool calls respect that user’s capabilities and are recorded in a signed audit log.

Typical use: inventory ACF schemas, edit drafts safely with dry-run, manage menus/SEO/media/redirects, and move content staging → production.

= Highlights =

* OAuth 2.1 with PKCE, CIMD, optional DCR, refresh tokens
* Capability-gated tools with dry-run, write gates, and high-risk lock
* Undo journal + Changes UI (does not touch OAuth credentials)
* Recipes cookbook + MCP prompts for common workflows
* Plugin/core update sessions with zip rollback (optional, off by default)

== Installation ==

1. Upload the `mt-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen
3. Open Settings → MT MCP, enable the server, and copy the Cursor JSON
4. Connect from your MCP client and approve OAuth consent (read or write)

== Frequently Asked Questions ==

= Does this store OpenAI or Anthropic API keys? =

No. MT MCP is a server for external agents. LLM keys stay in Cursor/Claude.

= Is this safe on production? =

Use Force draft, Pause writes, and keep high-risk / plugin updates off unless you need them. Prefer dry_run before writes. Review Changes / undo after sessions.

= What about WooCommerce or page builders? =

Not a goal. This plugin targets ACF + classic editor client sites.

== Changelog ==

= 1.3.17 =
* Connection labels are per OAuth grant (grant_id), not per user+client — renaming one Claude session no longer renames the others
* Active grants table shows grant short id; Revoke removes only that grant family
* Consent-screen labels attach to the new grant at token issue

= 1.3.16 =
* Plugin icon for Updates / details UI
* Tested up to WordPress 7.1 (fixes “not tested” / Compatible up to 6.8.999 on WP 7.x)

= 1.3.15 =
* Plugins list Settings link → Overview
* Friendlier OAuth grant client names (Claude CIMD → “Claude”; truncate long URLs)

= 1.3.14 =
* Security lock: OAuth refresh cannot escalate scopes; DCR redirect allowlist; audience enforced
* High-risk + write gates on attachment dedupe; SSRF guards on sitemap/HTML fetch and webhooks
* SVG sanitize on upload tickets; undo journal object caps; playground allowlist; ABSPATH guards
* Connection labels (set_connection_label + Overview UI); Overview Copy URL layout fix; WP.org packaging (readme.txt, GPL, uninstall, Domain Path)
* GitHub Releases updater (public repo mt-gareth/mt-mcp) for in-dashboard plugin updates

= 1.3.13 =
* Client rollout recipe / client_rollout prompt; expanded cookbook; get_usage_insights

= 1.3.12 =
* High-risk second lock; menu:// and media://missing-alt resources; Doctor auth/WAF probe

== Upgrade Notice ==

= 1.3.17 =
Per-grant connection labels (shared mt_admin + Claude no longer collide). Re-label rows once after update if needed.

= 1.3.16 =
Plugin icon; WordPress 7.1 compatibility metadata.

= 1.3.15 =
Settings link on Plugins screen; clearer OAuth grant client labels.

= 1.3.14 =
Security hardening. Re-authorize OAuth clients if DCR redirects were on arbitrary HTTPS hosts — add those hosts under Settings → DCR extra hosts.
