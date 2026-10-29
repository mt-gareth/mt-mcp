# MT MCP — features & backlog

Standalone WordPress MCP server for Motion Tactic client work. Auth is **OAuth 2.1 (PKCE)** and WordPress **Application Passwords** only — no shared API keys, no LLM keys in WP, no cloud credential relays.

**Goal:** inventory, build, and update a typical MT site (ACF + classic, Yoast, media, menus, redirects, staging→prod) from Cursor/Claude — with dry-run, audit, undo, and user-scoped grants.

**Current:** `1.3.14` (feature-locked) · ~37 Recipes · MCP prompts include `client_rollout`, `session_plan`, `seo_pass`, `content_inventory`, `acf_gap_check`, `launch_prep`.

---

## Capability map

| Domain | Read | Write | Notes |
|---|---|---|---|
| Site context / health / doctor | ✓ | gates | `get_site_context`, `get_site_health`, `connection_doctor`, `set_write_gates` |
| Site docs | ✓ | opt-in | AGENTS.md / CHANGELOG.md · `get_site_docs` / `update_site_docs` · `site://agents` |
| Post types / CPT inventory | ✓ | — | `list_post_types`, `describe_post_type`, `inventory_post_types`, `permalink_clash_report` |
| Posts / pages / CPTs | ✓ | ✓ | create / update / trash / schedule / duplicate / find_replace / bulk_update; ACF + SEO buckets |
| ACF schema | ✓ | — | field groups, flexible layouts, options pages list |
| ACF values | ✓ | ✓ | posts + options pages; `acf_media_audit` |
| Taxonomies / terms | ✓ | ✓ | CRUD + dry_run |
| Media | ✓ | ✓ | upload / alt bulk / SHA reuse / replace-keep-ID / SVG sanitize / unused report |
| Menus | ✓ | ✓ | CRUD + items; `menu://{location}` |
| Redirects | ✓ | ✓ | Redirection + Yoast Premium; import behind high-risk lock |
| SEO | ✓ | ✓ | Yoast / Rank Math / SEOPress · social audit · schema · sitemap soft-404 |
| Options | ✓ allowlist | ✓ allowlist | meta-key allowlist for meta/`find_replace` |
| Revisions / undo | ✓ | ✓ | WP revisions + 72h MCP undo journal + Changes UI |
| Staging ↔ prod | ✓ | ✓ | export/import + conflict check + attachment dedupe |
| Plugin / core updates | ✓ | gated | zip rollback + MU dead-man; **off on prod by default** |
| Cache flush | — | allowlist | object cache / rewrites / Rocket / LiteSpeed / W3TC / Autoptimize / Elementor |
| Audit / usage | ✓ | — | HMAC log, CSV, `get_usage_insights` (top tools → recipe gaps) |
| Users / roles | ✗ | ✗ | identity via OAuth user only (`list_users` for author assign) |
| GF / multilingual / Woo / builders | ✗ | ✗ | detect only |

### Core workflows (recipes cover these)

1. **Connect** — `connection_doctor` → green discovery / WAF / gates  
2. **Roll out** — `client_rollout` (brief, AGENTS.md, skill, gates)  
3. **Onboard** — schemas → `generate_cursor_skill`  
4. **Build / fix ACF** — gap check → draft fills → dry_run → apply  
5. **SEO / media / launch** — audits → soft-404 → redirects → menus  
6. **Staging → prod** — export → conflict check → import dry_run → apply  
7. **Recover** — pause writes · undo · Changes UI · update zip rollback  

### Safety model (always on)

Three gates: tool allowlist ∩ read/write ceiling ∩ `current_user_can`. Writes: dry_run / state_token / high-risk lock / force-draft / pause / Site Brief. Audit is identifier-only. Plugin updates and high-risk destructive ops are separate Settings toggles.

---

## Backlog

Only **open** work. Pull from Maybe only when a real client job needs it.

### Nice (when pain shows up)

| Item | Why wait |
|---|---|
| Session rollback / redo | Undo journal covers per-change; session scope if agents stack many ops |
| DB checkpoints before opaque/bulk | Host backups (WPE) cover most; add if import/SEO sweeps bite |
| Agent-queryable history (`history_list` / diff / apply) | Changes UI + undo exist; Easy-style investigate/restore later |
| Live change toasts in wp-admin | Nice while watching the agent; not a foot-gun |
| HITL approval queue beyond Force draft | Force draft + needs-review inbox is enough for now |
| Write-and-confirm outcome enum on every mutation | Partial via verification; formalize if agents miss silent drops |
| Alert emails (no payload) on denials/errors | Ops polish |
| Tool profiles / discover-run gateway | Only if `tools/list` overwhelms Cursor |
| Long-running task/poll | For huge sitemap/SEO/alt sweeps |
| Step-up OAuth scopes (read → re-consent write) | Force draft + grant scopes cover MT admins |
| Usage latency + revision-diff links | Counts/gaps shipped; timing is Nice |

### Maybe (client-triggered)

- GA4 / Search Console tool pack (own service account)
- Gravity Forms — form list, entry *counts*, export JSON; no PII dump
- WPML / Polylang — languages + translation links
- Allowlisted `theme_mod`; orphan URL finder; focal-point meta
- Post-meta schema hints (non-ACF); term meta / ACF-on-terms; term merge
- Menu item ACF
- Architecture inspectors (hooks/REST/tables, secret-redacted)
- Abilities API bridge + admin picker (WP 6.9+); third-party ability denylist if sites stack MCP plugins
- Agent Registry / triple allowlist / IP allowlist + rate limit
- Governed REST route allowlist (never install routes)
- Allowlisted serialized search-replace dry_run
- `llms.txt`; public server-card / agent-skills; `serverInfo.icons`
- Optional add-on PHP loading; SSE / stream idle timeout
- Page-builder ownership refuse; dual-control second approver
- DLP rules if GF/user tools ever land
- Conversation-file media optimize (WebP / max-bytes)
- mu-plugin variant; optional MT gateway for blocked UAs
- Compatibility shim cleanup once all sites are on mt-mcp

---

## Explicit non-goals

- Replacing WP Admin for humans  
- Arbitrary PHP / shell / live “vibe coding” of `wp-content`  
- Storing LLM API keys in WordPress  
- Shared site API keys / URL-token auth as primary  
- Cloud MCP relays / vendor gateways (WPVibe, miniOrange gateway, AI Puffer)  
- Agent-set `user_confirmed` as human approval  
- In-WP chatbots / embeddings / AI Forms / metered Cloud quotas  
- Gutenberg / FSE / Elementor / Divi / Beaver pipelines  
- WooCommerce / store tooling  
- Unrestricted `wp_options` or user password changes  
- Gravity Forms entry PII dumps by default  
- SEO Meta Bridge REST API (stays in Utilities if needed)

---

## Ship log (compact)

| Band | Highlights |
|---|---|
| **1.1** | Standalone plugin, OAuth 2.1 PKCE, App Passwords, MCP JSON-RPC, audit, dry-run, content/SEO/ACF/media/redirects/transfer, skill generator |
| **1.2** | Menu writes, options pages, describe CPT, terms, duplicate, find_replace, preflight, write verification, SSRF-safe upload |
| **1.3.0–.2** | Draft preview, temp upload, ACF media audit, redirect import + state_token, pause/force-draft/Site Brief, doctor, object caps, op receipts, needs-review, undo journal |
| **1.3.3–.5** | Theme templates, plugin matrix, public HTML diff, link checker, replace attachment, bulk posts, unused media, `MT_MCP_PUBLIC_BASE` |
| **1.3.6–.8** | Recipes + MCP prompts; plugin/core update sessions + zip rollback; MU dead-man CLI |
| **1.3.9–.11** | SEO social, CPT inventory/clash, flush_caches, media SHA, soft-404, SVG sanitize, meta allowlist, alt discovery paths, AGENTS.md/CHANGELOG |
| **1.3.12** | High-risk lock; `menu://` + `media://missing-alt`; doctor auth/WAF probe |
| **1.3.13** | Client rollout recipe/`client_rollout`; expanded cookbook; `get_usage_insights` + Activity aggregates |
| **1.3.14** | **Security lock** + packaging; connection labels; Overview polish; GitHub Releases updater |

---

## Borrowed ideas (status)

Patterns adapted into on-site OAuth mt-mcp — not cloud relays or builder stacks.

| Pattern | Peers | Status |
|---|---|---|
| Dry-run + state_token / two-phase dangerous ops | WP.com, Royal, Cowboy | **Shipped** |
| Op receipts / operation_id | WPVibe, AI Puffer | **Shipped** |
| Per-change undo + Changes UI | Royal, Cowboy | **Shipped** |
| Force draft / needs-review / Site Brief | Agent Abilities, Easy, Royal | **Shipped** |
| Write verification | Royal, Agent Abilities | **Shipped** |
| Connection Doctor + auth-header / WAF | Cowboy, miniOrange, AI Engine, Flex | **Shipped** |
| Temp upload URL | AI Engine | **Shipped** |
| Surgical find_replace | WPVibe, Royal, AI Engine | **Shipped** |
| Media fingerprint / SVG sanitize | Royal, WPVibe | **Shipped** |
| Tool annotations + arg aliases | AI Engine | **Shipped** |
| OAuth refresh reuse + audience bind | Easy | **Shipped** |
| AGENTS.md / CHANGELOG site docs | WSP | **Shipped** |
| Meta-key allowlist + high-risk lock | Agent Abilities, Cowboy docs | **Shipped** |
| Cache-Control no-store + JSON guard | miniOrange, WSP | **Shipped** |
| Allowlisted cache flush | WPVibe / Flex | **Shipped** |
| Usage insights from audit | AI Engine, WSP | **Shipped** (latency open) |
| MCP prompts / resources | Cowboy, WSP | **Shipped** |
| Plugin update sessions + rollback | (MT need; WPE complements host backup) | **Shipped** |
| HITL approval queue / live toasts | WPVibe, Easy, miniOrange | Backlog |
| Session rollback / DB checkpoints | Flex, Cowboy | Backlog |
| Tool profiles / discover-run | Royal, Cowboy | Backlog |
| Step-up scopes / task poll | Easy | Backlog |
| Abilities bridge / Agent Registry / DLP | Enable, miniOrange, Agent Abilities | Maybe |
| GA4 / GSC optional pack | Easy, Flex | Maybe |

**Skip peers entirely for product surface:** BEAR (Woo), Novamira (eval), WP.com billing/DNS, Elementor/Woo abilities, AI Engine/AI Puffer chatbots & cloud bridges.

Peer deep-dives (Oct 2026 zip/docs review) lived in git history if needed; this table is the working index.
