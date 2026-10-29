# MT MCP — feature roadmap

Extracted from Motion Tactic Utilities into a standalone plugin. Auth is **OAuth 2.1 (PKCE)** and WordPress **Application Passwords** only — no shared API keys.

Goal: an agent should be able to **inventory, build, and update** a typical MT client site (ACF-heavy themes, Yoast, media, menus, redirects, staging→prod moves) without WP Admin — with dry-run, audit, and user-scoped grants.

---

## Shipped in 1.1.x

- [x] Standalone plugin (`mt-mcp`) with clean Settings UI (Overview URL + Cursor JSON)
- [x] MCP JSON-RPC over `/mt-mcp` + REST fallback
- [x] OAuth 2.1 authorization server (PKCE S256, CIMD, optional DCR, refresh tokens)
- [x] `/authorize` root shim (Claude issuer-relative authorize)
- [x] Protected Resource + Authorization Server metadata (`.well-known`)
- [x] Application Password auth (still a WP user)
- [x] Capability checks per tool; scoped grants (read vs write) at consent
- [x] Audit log (HMAC) + CSV export
- [x] Tool groups, environment flag, dry-run writes
- [x] Content / SEO / ACF / media / menus (read) / redirects / transfer tools
- [x] Cursor skill generator + review prompts
- [x] Media alt bulk fill (`bulk_fill_media_alt`) + per-attachment `update_media`
- [x] Rank Math + SEOPress adapters (`bulk_update_seo`)
- [x] Internal links, sitemap HEAD check, schema.org JSON-LD validate
- [x] Multi-post export/import + conflict check + attachment SHA dedupe
- [x] Admin playground, WP-CLI, health endpoint
- [x] Chunked `get_post` (`max_content_chars` / offset)

### Shipped in 1.2.0

- [x] Menu write tools + link validation + dry_run
- [x] `list_acf_options_pages` + summarized ACF location rules
- [x] `describe_post_type`, term CRUD, `duplicate_post`, `find_replace`, `preflight_publish`
- [x] ACF/meta search filters on `search_posts`
- [x] Write verification on `update_post` / `update_options_page`
- [x] Protected-option denylist; arg aliases; Cache-Control no-store; JSON response guard
- [x] SSRF-safe `upload_media` URL sideload + `media_alt_report`

### Shipped in 1.3.0

- [x] Draft preview links + one-shot media upload tickets
- [x] `acf_media_audit`, `import_redirects`, two-phase `state_token` / `confirm_dangerous_op`
- [x] Pause writes, force draft, Site Brief (admin + `set_write_gates`)
- [x] `connection_doctor` with redacted paste-for-support

### Shipped in 1.3.1

- [x] Object-level caps, op receipts, identifier-only audit, privacy copy
- [x] OAuth refresh reuse + audience binding; DCR extra hosts
- [x] Needs-review inbox + `manage_connections` presets

---

## Capability map (what works today)

Use this as the “can the agent do X?” cheat sheet. Gaps below are intentional backlog, not bugs unless noted.

| Domain | Read | Write | Notes |
|---|---|---|---|
| Site context / health / plugins | ✓ | — | `get_site_context`, `get_site_health` |
| Post types inventory | ✓ | — | `list_post_types` (public types) |
| Posts / pages / CPTs | ✓ | ✓ | create / update / trash / schedule; ACF + Yoast buckets |
| ACF field **schema** | ✓ | — | `list_acf_field_groups`, `get_acf_schema` (incl. flexible layouts) |
| ACF field **values** on posts | ✓ | ✓ | via `get_post` / `update_post` `acf` |
| ACF options pages | ✓ | ✓ | `list_acf_options_pages` / `get_options_page` / `update_options_page` |
| Taxonomies / terms | ✓ | ✓ | list + get + create/update/delete (dry_run) |
| Featured image | ✓ | ✓ | id on get/update; alt via media tools |
| Media library | ✓ | ✓ | list/get/upload/update/alt bulk/dedupe |
| Menus | ✓ | ✓ | list/get + create/update/delete menu + update_menu_items |
| Redirects | ✓ | ✓ | Redirection + Yoast Premium |
| SEO meta | ✓ | ✓ | Yoast / Rank Math / SEOPress + audit |
| Site options | ✓ allowlist | ✓ allowlist | not arbitrary `wp_options` |
| Revisions | ✓ | ✓ restore | |
| Staging ↔ prod packages | ✓ | ✓ | export/import posts + media remap |
| Nav / theme locations | ✓ | ✗ | locations listed with menus |
| Users / roles | ✗ | ✗ | identity only via OAuth user |
| Gravity Forms / multilingual | ✗ | ✗ | detect in context; no tools |

### Already strong for MT builds

1. **Discover structure** — `get_site_context` → `list_post_types` → `list_acf_field_groups` → `get_acf_schema` → `generate_cursor_skill`.
2. **Fill / fix CPT content** — `search_posts` / `list_posts` → `get_post` → `update_post` with `acf` mirroring schema (flexible/repeaters as nested arrays).
3. **SEO pass** — `seo_audit` / `content_review` → `bulk_update_yoast` or `bulk_update_seo`.
4. **Media a11y** — `list_media` (filter) → `bulk_fill_media_alt` or vision externally → `update_media`.
5. **Launch prep** — redirects, sitemap_diff, validate_schema_ld, suggest_internal_links.
6. **Move a page live** — `export_post` (embed_media) → target `import_post` dry_run → apply.

---

## How we use sites (priority lens)

Typical MT session: **Cursor or Claude** → OAuth on a **WPE/client site** → inventory ACF/classic theme → edit drafts → menus/SEO/media → staging→prod → launch QA. Themes stay in git; we do **not** Woo, Elementor, Gutenberg trees, or in-WP chatbots.

Workflows the roadmap must unlock (in order):

1. Connect reliably (WPE / WAF / discovery)
2. Onboard a client (schemas, options pages, skill)
3. Build / update ACF pages and CPTs
4. Change navigation IA
5. Move content staging → prod safely
6. SEO + media a11y + launch QA
7. Survive mistakes (dry-run, verify, undo, pause)

---

## Must have

Unblocks daily MT work or closes a production foot-gun. Build these first.

### Site building blockers

- [x] **Menu write** — `create_menu` / `update_menu` / `delete_menu` / `update_menu_items` (add/update/delete/reorder), location assign, dry_run + link validation
- [x] **`list_acf_options_pages`** + richer ACF location rules on `list_acf_field_groups` / `get_acf_schema`
- [x] **`describe_post_type`** — supports, counts, taxonomies, ACF groups, page templates in one call
- [x] **Term CRUD** — `create_term` / `update_term` / `delete_term` with dry_run
- [x] **`duplicate_post` / clone-as-draft** — core + tax + ACF + featured (prepare edit of live pages)
- [x] **Search posts by ACF value / empty required fields** — `search_posts` `acf_field` / `acf_value` / `acf_empty` / `missing_required_acf`
- [x] **Surgical find-and-replace** — `find_replace` content / excerpt / title / meta / ACF; dry_run + nearest + `expected_count` + `apply`
- [x] **`preflight_publish`** — SEO + required ACF + featured + alt + schema sample in one check
- [x] **Write verification** — `verification` on `update_post` / `update_options_page`: applied / silent_drop / silent_modify
- [x] **Draft preview links** — `create_draft_preview` / `revoke_draft_preview` → `/mt-mcp-preview/{token}/`
- [x] **Temp / large media upload** — `prepare_media_upload` one-shot POST URL (`/mt-mcp-upload/{token}/`)
- [x] **SSRF-safe URL sideload** — https only, no redirects, refuse private IPs; require alt on URL uploads
- [x] **ACF image/gallery audit** — `acf_media_audit` (broken IDs, missing files, missing alts)
- [x] **`media_alt_report`** — counts + IDs feeding `bulk_fix_media_alt`
- [x] **Bulk redirect import** — `import_redirects` items[] or csv + state_token
- [x] **Two-phase dangerous ops** — `state_token` on force-delete + redirect import; `confirm_dangerous_op`
- [x] **Named staging↔prod connections** — `manage_connections` list/upsert/delete presets

### Production safety (we work on live client sites)

- [x] **Connection Doctor** — `connection_doctor` (HTTPS, `.well-known`, MCP/REST, WAF hints, paste_for_support)
- [x] **`Cache-Control: no-store`** on MCP HTTP + OAuth JSON responses
- [x] **JSON response guard** — discard stray PHP notices before MCP JSON body
- [x] **Object-level caps** — `edit_post` / `read_post` / `delete_post` (+ term caps) on target IDs
- [x] **Protected-option denylist** — never write `siteurl`, `active_plugins`, salts, etc.
- [x] **Force draft on create** — `set_write_gates` / Settings; `_mt_mcp_needs_review` + Overview inbox + `list_needs_review` / `review_draft`
- [x] **Pause writes** — site-wide freeze via `set_write_gates` / Settings; reads still work
- [x] **Op receipts / idempotent `operation_id`** — write tools store/replay receipts on reconnect
- [ ] **Per-change undo** + Changes admin UI (before / written / now); restore must keep MCP OAuth working
- [x] **Safe mode** — force-delete + bulk redirects require `state_token` / `confirm`
- [ ] **Three-gate dispatch** — tool allowlist ∩ read/write ceiling ∩ `current_user_can`; server inert until Enable
- [x] **Privacy copy** — initialize instructions spell out model-provider exposure
- [x] **Site Brief** — owner rules in Settings + `initialize` instructions + `get_site_context`
- [x] **Identifier-only audit** — ids/keys/slugs/status; bodies/emails redacted in log rows
- [x] **Revoke / uninstall hygiene** — Overview copy: revoke OAuth + remove App Passwords (uninstall ≠ revoke)

### Connect / OAuth hardening (cheap, high leverage)

- [x] **MCP tool annotations** — `readOnlyHint` / `destructiveHint` / `idempotentHint` (incl. new destructive menu/term deletes)
- [x] **Arg aliases** — `id` / `post_id` / `page_id` / `attachment_id` normalized on every tool call
- [x] **OAuth refresh reuse detection + audience binding** — reuse revokes family; resource must match grant
- [x] **DCR redirect / additional-hosts allowlist** — Settings `dcr_extra_hosts`
- [x] **Site Health: `.well-known` must be 200** — checked by `connection_doctor`
- [x] **Secret redaction** on doctor / health diagnostic payloads

---

## Nice to have

Makes sessions smoother or safer; not daily blockers.

### Content & QA polish

- [ ] **Public HTML verification** after content edits (`diff_public_html`)
- [ ] **OG / Twitter / canonical / primary category / breadcrumb** SEO helpers
- [ ] **Sitemap crawl with soft-404 body checks** (beyond HEAD)
- [ ] **Unused media report**
- [ ] **Media fingerprint** find-before-upload (extend SHA dedupe)
- [ ] **SVG sanitize** on upload
- [ ] **Replace attachment file** (keep ID — preserves ACF image fields)
- [ ] **Link checker** across a post type (capped)
- [ ] **Bulk status / author / parent**
- [ ] **`set post_author`** + safe `list_users` for assignment
- [ ] **Theme templates list** — `get_page_templates()`
- [ ] **Plugin capability matrix** — which SEO/redirect tools unlock for this site
- [ ] **CPT deep inventory / permalinks clash list**

### Agent collaboration & recovery

- [ ] **Session rollback / redo**
- [ ] **DB checkpoints** before opaque/bulk ops (restore keeps MCP credentials)
- [ ] **Agent-queryable history** — `history_list` / `diff` / `apply` dry_run
- [ ] **Live change toasts** in wp-admin while the agent works
- [ ] **HITL approval queue** for destructive tools (beyond Force draft)
- [ ] **Standard vs Trusted** connection levels (fail-closed when raising)
- [ ] **Per-grant tool allowlists** on OAuth consent (beyond read/write)
- [ ] **Drafts-only site mode** (stricter than Force draft)
- [ ] **Write-and-confirm outcome enum** on all mutations
- [ ] **Site Context AGENTS.md + CHANGELOG.md** for chat-only agents (complements repo skill)
- [ ] **Prompts / resources** — `seo_pass`, `launch_prep`, `acf_gap_check` wired and documented
- [ ] **Richer MCP resources** — `acf://`, `menu://`, `media://missing-alt`

### Ops & hosting

- [ ] **`MT_MCP_PUBLIC_BASE`** for tunnel/local OAuth advertise URL
- [ ] **Alternate discovery URL** when hosts block `/.well-known/`
- [ ] **Output / Auth-header / Sucuri diagnostics** folded into Doctor
- [ ] **Pending OAuth client approval** queue (extra lock on prod Maptician-class sites)
- [ ] **Allowlisted cache flush** (PHP, no shell)
- [ ] **Meta-key allowlist** for any generic meta writes
- [ ] **High-risk second lock** — permanent delete / redirect wipe need extra toggle
- [ ] **Alert emails without payload** on denials/errors
- [ ] **Tool profiles or discover/run gateway** if `tools/list` gets heavy
- [ ] **Long-running task/poll** for sitemap_diff / bulk SEO / alt sweeps
- [ ] **MCP usage insights** (top tools, latency) from audit log
- [ ] **Step-up scopes** — start read-only, re-consent for write

---

## Maybe useful

Worth keeping on the board; pull in only when a real client job needs it.

- [ ] **Optional GA4 / Search Console** tool pack (own service account) for SEO retainers
- [ ] **Gravity Forms** — list forms, entry *counts*, export form JSON; no PII dump by default
- [ ] **WPML / Polylang** — languages, get/set translation links, duplicate to lang
- [ ] **Allowlisted theme_mod** read/write
- [ ] **Orphan URL finder**
- [ ] **Focal point / crop meta** if a theme uses it
- [ ] **Post meta schema hints** from sample posts (non-ACF)
- [ ] **Term meta + ACF-on-terms**
- [ ] **Term merge / reassign**
- [ ] **Menu item ACF**
- [ ] **Architecture inspectors** — hooks, REST routes, structured table read (secret-redacted) for support
- [ ] **Abilities API bridge** + admin picker (when clients run WP 6.9+ ability plugins)
- [ ] **Third-party ability denylist** (only if a site stacks multiple MCP plugins)
- [ ] **Agent Registry** role × tool matrix (multi-editor client orgs)
- [ ] **Triple allowlist** global → role → connection
- [ ] **IP allowlist + rate limit**
- [ ] **Title-length cap / block-markup validation** (less relevant for classic+ACF)
- [ ] **Governed REST route allowlist** (escape hatch; hard-block install routes)
- [ ] **Allowlisted search-replace** (serialized options) with dry_run
- [ ] **llms.txt** get/validate/update
- [ ] **Public MCP server-card / agent-skills** well-known
- [ ] **`serverInfo.icons`** from site icon
- [ ] **Optional add-on PHP loading** for heavy packs
- [ ] **SSE / stream idle timeout** for very large payloads
- [ ] **Page-builder ownership refuse** if a rare client still has Elementor/Divi
- [ ] **Dual-control** — second OAuth approver on production
- [ ] **Power mode** gate (only if we ever ship privileged packs)
- [ ] **DLP rules** for form/user PII in responses (if GF tools land)
- [ ] **Conversation-file media optimize** (WebP/max-bytes fail-closed) beyond temp URL
- [ ] **Safe plugin/theme update via agent** (we usually update outside MCP)
- [ ] **Compatibility shim cleanup** once all sites are on mt-mcp
- [ ] **mu-plugin variant** for locked-down hosts
- [ ] **Optional MT gateway** (UA rewrite) for hosts that block `python-httpx` — infra, not plugin
- [ ] **SSE transport** alongside stateless HTTP JSON-RPC (only if a client requires it)

---

## Not likely useful (for MT)

Keep as explicit deferrals so we don’t re-litigate them. Details/attribution live in [Borrowed ideas](#ideas-borrowed-from-other-mcp-plugins).

- WooCommerce / store / order / customer MCP surfaces
- Elementor / Divi / Beaver / Kadence / FSE / Gutenberg block-tree editing
- In-WP chatbots, AI forms, embeddings, image/video generation, visitor credits
- Cloud MCP relays / gateways (WPVibe, miniOrange gateway, AI Puffer `mcp.aipuffer.org`)
- Novamira-style arbitrary PHP / file vibe-coding on live sites
- WordPress.com domain / DNS / billing tools
- Semrush / Ahrefs / DataForSEO packs (unless a retainer already pays)
- Draft-theme sandbox / AI theme file editing (themes via git)
- Event-driven automations that store vendor LLM keys in WP
- BEAR-style catalogue bulk editors
- Metered “website action” Cloud quotas
- Agent-set `user_confirmed` as human approval
- Shared static MCP bearer / URL-token auth as primary
- BuddyPress / LearnDash / Tutor / TEC deep packs

---

## Ideas borrowed from other MCP plugins

Attribution for roadmap items above. We adapt patterns into **on-site OAuth** mt-mcp — we do **not** copy cloud credential relays or builder stacks we don’t use.

### WPVibe (SeedProd) — `vibe-ai` 1.21.x

| Idea | How they do it | Our take |
|---|---|---|
| HITL approvals | Worker pauses destructive ops; in-chat Approve/Decline + dry-run preview; append-only Approval Log in wp-admin | Same UX goal, but approval UI lives in **our** wp-admin (no WPVibe cloud worker) |
| Op receipts | `X-WPVibe-Op-Id` on mutation; DB table records started/completed; replay → 409 + stored receipt (`class-wpvibe-op-receipts.php`) | Op-id + receipt table for OAuth tool calls so Claude/Cursor reconnects don’t double-write |
| Live reload / change tracker | Transient ring buffer of changes; poller script toasts in admin / soft-reloads frontend (`Change_Tracker` + `Live_Reload`) | Same: toast “MCP updated Post X — View” while agent works |
| Surgical replace | Whitespace-lenient find/replace in content/meta/options; nearest-match errors (`Content_Ops`) | `replace_in_post` / ACF string fields with dry_run |
| SVG sanitize | Bundled `enshrined/svg-sanitize` before theme/media write | Gate SVG in `upload_media` |
| WP-CLI-in-PHP allowlist | Native PHP dispatch; command allowlist; destructive needs approval (`class-wpvibe-cli.php`) | Tiny allowlist: cache flush, serialized search-replace dry_run |
| Abilities API | REST list/get/run for abilities with `show_in_rest` or `meta.mcp.public` (`class-wpvibe-abilities.php`) | Bridge when WP 6.9+ / plugins expose abilities |
| Auth diagnostics | Detects stripped Authorization / WAF HTML on connect | Fold into health / connection diagnostic tool |
| Draft theme sandbox | Clone active theme → edit → preview URL → publish | **Skip** for MT (themes via git) |

### Royal MCP — `royal-mcp` 1.5.x (self-hosted OAuth like us)

| Idea | How they do it | Our take |
|---|---|---|
| 72h undo tokens | Pre-op snapshot gzip+base64 in `wp_options` (`Undo_Store`); response `undo.token`; `mcp_undo_last_operation` restores; Changes admin UI shows before/written/now | High value for prod agents — add undo envelope on destructive writes + Changes page |
| WriteVerifier | After every write: compare requested vs before vs actual; throw on silent drop; report silent modifies (slug suffix etc.) | Shared helper on `update_post` / ACF / media writes |
| Drafts-only mode | Site setting: creates only draft/pending; refuses publish/options/live edits (`Drafts_Policy`) | Prod-safe default toggle for client sites |
| Preview links | Token transient + query var; renders draft as owning user without cookies (`Preview_Link`) | Agents (and humans) open draft URLs headlessly |
| Clone as draft / publish+promote | Dedicated tools so live content isn’t edited in place | Complements our `duplicate_post` + menu writes |
| Find-replace safety | Dry-run + **expected-count** + read-after-write verification | Merge with WPVibe-style surgical replace |
| Media fingerprints | Source URL + SHA of bytes + find existing before upload (`Media_Fingerprint`) | Extend our `_mt_mcp_sha256` dedupe |
| Pending OAuth clients | Setting: new DCR clients sit pending until admin Approve/Reject; admin-bar badge (`Pending_Clients_Page`) | Extra lock for production Maptician-class sites |
| Tool profiles | `?tools=core` trims `tools/list` by name prefix (`Tool_Profiles`) | Helpful when tool count grows (Cursor/Claude limits) |
| Agent discovery | `/.well-known/mcp/server-card.json` + `agent-skills/index.json` (Cloudflare Agent Readiness) | Optional public discovery without exposing schemas |
| ACF type-aware tools | Dedicated `acf_get_field(s)` / `acf_update_field` using `get_field`/`update_field` Return Format | We already hydrate via `get_fields` on `get_post`; keep schema-first; optional single-field tools if agents struggle |
| Coexistence | Detects other MCP plugins; doesn’t kill their endpoints | Stay friendly if a site also runs Royal/WPVibe |

**Royal MCP — skip for us:** Elementor/Divi pipelines, WooCommerce suite, Gutenberg block-tree tools, comments moderation deep suite, widgets, BuddyPress, their API-key auth path (we stay OAuth-user).

### Easy MCP AI — `easy-mcp-ai` 2.3.x (self-hosted OAuth like us)

Closest peer in architecture: MCP runs **on the site**, OAuth 2.1, no vendor relay. Differentiator is **Change History as tools** + **SEO/analytics providers** + fine-grained grants.

| Idea | How they do it | Our take |
|---|---|---|
| Change History tools | DB change log + `wp_history_list` / `get` / `diff` / `apply`; apply rebuilds via ordinary tools with dry_run + drift detection (`Rebuild_Planner`, `Live_State`) | Stronger than undo-token alone — agent can *investigate* and restore; complement Royal-style undo |
| HITL approvals | Approval gate + admin page + store (`includes/approvals/*`); tools can pause (`needs_approval`) | Align with WPVibe HITL item; their pause/replay kinds are worth mirroring |
| Per-grant tool allowlists | Consent / API token creation picks which tools that connection may call | Extend our read/write scopes → optional tool-group or tool-level allowlist per grant |
| Force draft on create | Toggle: create tools save as draft by default | Easy prod safety without full drafts-only lockdown |
| Refresh reuse detection | Refresh-token rotation; reuse of an old refresh revokes the chain (`OAuth_Token_Manager`) | Add to our OAuth token store |
| Audience binding | Access tokens validated against this MCP resource URL (`OAuth_Token_Validator::ERROR_INVALID_AUDIENCE`) | Bind tokens to `home_url('/mt-mcp')` |
| Step-up scopes | Transient of pending extra scopes after re-consent (`Step_Up`) | Let an agent request write after starting read-only |
| MCP Tasks | Long tools return a task; client polls; WP-Cron/`Task_Scheduler` continues (`Task_Manager`, extension `io.modelcontextprotocol/tasks`) | Use for sitemap_diff, bulk SEO, media alt sweeps |
| Abilities picker | Admin UI enables selected WP Abilities → dynamic MCP tools (`Dynamic_Tool_Registrar`) | Better than auto-exposing everything |
| Preview links | `includes/preview` token URLs | Same need as Royal — one implementation |
| GA4 / GSC / SEO APIs | External-data tools with site-stored encrypted credentials (service account JSON, Semrush, etc.) | **Optional** tool group for MT SEO retainers; start with GSC + GA4 only |
| Privileged tool packs | Separate allowlisted packs (files-read, search-replace) under `privileged/` | Pattern for our allowlisted admin ops |
| One-click Cursor/VS Code | Dashboard buttons that deep-link install configs | Nice admin UX for Overview |

**Easy MCP AI — skip for us:** WooCommerce suite, Gutenberg/site-editor tools, BuddyPress, Events Calendar (unless a client needs it later), Semrush/DataForSEO/SE Ranking as defaults (paid third-party; keep optional), remote signed-skills CDN sync (we prefer local `generate_cursor_skill`), API-token auth as primary (OAuth-user first).

### AI Engine (Meow Apps) — `ai-engine` 3.8.x

Different product: **chatbot + LLM keys in WordPress** *plus* a mature self-hosted MCP server. We only borrow MCP/server patterns — not the in-WP AI suite (explicit non-goal: storing provider API keys).

| Idea | How they do it | Our take |
|---|---|---|
| Temp upload URL | `wp_upload_request` returns one-shot `/mcp/v1/upload/{token}` (5 min); agent curls the file instead of base64 | High value for Figma→media / large assets; add next to `upload_media` |
| Surgical content alter | `wp_alter_post` regex/insert/replace without resending full body; busts post cache after write | Merge into our surgical-replace item; ensure write tools flush caches so follow-up `get_post` is fresh |
| Tool annotations | Every tool gets MCP `readOnlyHint` / `destructiveHint` / `idempotentHint` | Cheap win on `tools/list` |
| Arg aliases | Dispatch accepts `id` / `post_id` / `page_id` for the same record | Stop agent “ID required” flubs |
| Default readonly consent | `mcp_default_readonly`: first OAuth grant is read-only unless user opts into writes | Complements Easy step-up / Force draft |
| Editor OAuth sessions | Optional `mcp_oauth_editors`: Editors get a hard-coded content-only tool allowlist + their own caps | Maybe later for client content editors — MT admins usually connect as admin |
| Auth header recovery | Reads `Authorization` from Apache/CGI fallbacks when WP REST can’t see it; connection test warns + `.htaccess` tip | Fold into our health / diagnostics (we already hit WPE/header issues) |
| MCP usage insights | Aggregates tool calls 35 days; MCP tab: top tools, recent changes with WP revision diff URLs | Nice admin Overview companion to audit log |
| Stream management | Streamable HTTP + heartbeats + idle timeout + `notifications/cancelled` | Matters if we add SSE/long sessions |
| Host-root well-known | Serves OAuth discovery at bare `/.well-known/…` as well as under the resource path | We already shim authorize/metadata — keep parity |
| Dynamic REST tools | Optional expose REST routes as MCP tools (`mcp-rest`) | Skip as default — too wide; prefer curated tools |
| Outbound MCP client | Chatbots can call *external* MCP servers | Skip — we are a server for Cursor/Claude, not an LLM host |

**AI Engine — skip for us:** Chatbots, AI Forms, embeddings, Vision/image gen, storing OpenAI/Anthropic keys in WP, Workspace mobile shell, URL-in-path bearer tokens (`/mcp/v1/{token}` — header-stripping workaround we don’t want as auth), Pro DB/theme/plugin/Woo MCP packs, block-pattern/Gutenberg write tools, SEO Engine / Code Engine / Social Engine upsells.

### Enable Abilities for MCP — `enable-abilities-for-mcp` 2.13.x

Abilities-API specialist (WP **6.9+** + official **MCP Adapter**). Registers ~112 `wp_register_ability()` entries with `mcp.public`, admin toggles, and optional OAuth via bundled `wp-media/mcp-oauth` (CIMD for Claude; opt-in DCR for ChatGPT). Different stack than our first-party MCP tools — still useful for patterns when we bridge Abilities.

| Idea | How they do it | Our take |
|---|---|---|
| Per-ability toggles | Settings › WP Abilities: category switches; disabled abilities never register | Same as Easy/AI Engine Abilities picker — one admin UX for “what the agent may call” |
| Third-party denylist | Snapshot other plugins’ abilities; `wp_unregister_ability` at `PHP_INT_MAX` so Fluent Forms & co. disappear from *every* MCP server | Unique: site-wide kill switch when clients install multiple MCP plugins |
| DCR + callback allowlist | ChatGPT connectors opt-in; admin lists allowed redirect URIs; enforced at register + authorize; URI smuggling tests | Harden our DCR: allowlist redirect_uris (complements Royal pending-clients) |
| CIMD + DCR coexistence | Claude via trusted metadata URL; ChatGPT via `/oauth/register` behind the allowlist | We already do both — keep Claude CIMD stable when DCR is on |
| Discovery Site Health | Warns if `/.well-known/oauth-*` isn’t a direct 200; blocks trailing-slash canonical 301 | Add to our health tool / WP Site Health |
| Multisite discovery bridge | Network-activate so main site serves root well-known for subdirectory subsites | Only if MT runs subdirectory multisite |
| Object-level caps | Every post ability checks `read_post` / `edit_post` on the target ID | **Audit** our tools — site-wide `edit_posts` isn’t enough |
| Activity log as OAuth user | Executions attributed to the logged-in WP user | Align with our audit log |
| llms.txt readiness | Get / validate / update llmstxt.org file (SEOPress Pro or virtual) | Optional P3 for agent discovery |
| Missing-alt snapshot | WCAG 1.1.1 media scan → fix via meta | We already have alt bulk tools — keep ours |
| python-httpx FAQ | Documents WAF blocks + Anthropic IP range for hosts | Same WPE lesson we hit — keep in support text / health |

**Enable Abilities — skip for us:** Depending on MCP Adapter as the only server (we own the MCP endpoint); Woo / LearnDash / Tutor / TEC / Elementor / JetEngine / FSE / Code Snippets ability packs; shipping a second OAuth library alongside ours; Bearer tokens scoped by route-prefix tricks.

### Cowboy MCP — `cowboy-mcp` 1.7.x (self-hosted OAuth like us)

Turnkey MCP with the strongest **safety story**: per-change undo journal + **full DB checkpoints**, Connection Doctor, discover/run gateway, Safe/Power modes. Closest “run the site by chat” peer — we borrow safety UX, not the kitchen-sink tool surface.

| Idea | How they do it | Our take |
|---|---|---|
| Per-change undo journal | Before-state snapshots (gz blob) at dispatch; `undo` restores; `after_hash` conflict rail; 7-day retention; Activity tab | Merge with Royal undo tokens — prefer snapshot-at-chokepoint over ad-hoc |
| DB checkpoints | Pure-PHP prefix-scoped SQL dump; restore via temp tables + atomic `RENAME TABLE`; auto before plugin/theme updates & mutating WP-CLI | High value before bulk SEO / import / opaque ops — tables only (not uploads) |
| Discover / run gateway | Agents see `cowboy_discover` + `cowboy_run`; full catalog on demand (~203 tools) | Better than dumping 100+ tools into Cursor context; alternative to `?tools=` profiles |
| Safe mode | Destructive tools require `confirm:true`; dry_run preview otherwise | Align with our dry_run culture |
| Power mode | Admin toggle unlocks WP-CLI, file write, mu-plugins, etc. | Pattern for any future privileged pack — default off |
| Connection Doctor | HTTPS/REST/OAuth/CF/WAF/cache self-test; admin AJAX + WP-CLI + MCP tool; pasteable support report | Upgrade our health tool to this depth (WPE/`python-httpx` already taught us) |
| MCP prompts & resources | 8 guided prompts + 17 read-only resources | Optional: “SEO sprint”, “launch QA” as MCP prompts wrapping our tools |
| Batch execute | Multi-tool batch with shared undo batch id | Nice for “publish these three drafts” |
| Consent scopes | Full / read-only / hand-picked tools per OAuth app or API key | Same as Easy/Enable least-privilege |
| Additional OAuth hosts | Allowlist redirect hosts for n8n etc. | Same as Enable Abilities callback allowlist |
| Inert until connect | Endpoint refuses until first key or approved OAuth app | Good default for fresh installs |
| Bidirectional Abilities | Tools ↔ `cowboy-mcp/*` abilities; foreign abilities become tools | When we bridge Abilities, keep undo/dry_run in front of *our* tools |
| Undo verify | After undo, report fields that still differ from snapshot | Pair with Royal WriteVerifier mindset |

**Cowboy MCP — skip for us:** Arbitrary WP-CLI / `wp-content` file editing / “vibe code live site”; Woo / Wordfence / Elementor / Beaver / SiteOrigin / TEC packs; Gutenberg block-tree & Site Editor tools; API keys as primary auth; shipping 200+ always-on tools (we stay curated ACF/classic).

### miniOrange Secure MCP Server — `miniorange-secure-mcp-server` 1.5.x

Governance-first Abilities MCP (WP **6.9+**). Self-hosted OAuth + optional **miniOrange Gateway** (`gateway.miniorange.ai`) for rate limits / Claude app UX. Differentiator is **Agent Registry** (role × tool policy) + Business **DLP / HITL**, not content depth.

| Idea | How they do it | Our take |
|---|---|---|
| Agent Registry | Named agents; role/ability matrix; tools off for a role are hidden + clear “switched off” message; revoke instantly | Stronger than raw scopes — named policies for “Editor agent” vs “Admin agent” |
| Three-layer authz | OAuth user → role grants → `current_user_can` (object-level) | Same model we want; audit ours against this |
| Governed REST | REST routes as tools; per route/method/role; site-wide create/update/delete switches; install/batch routes never grantable | Optional escape hatch for obscure plugins — default off; never open install routes |
| DLP / data rules | Business: scan/mask emails, phones, secrets in responses (`Data_Rules\Scanner` / `Enforcer`) | High value before Gravity Forms / user tools ever dump PII |
| HITL approvals | Business: `Approval_Gate` + `Call_Fingerprint` for sensitive actions | Align with WPVibe/Easy approval queue |
| Troubleshooting + redacted support dump | Detects CF/WAF/auth-header strip/cache; Apache/Nginx snippets; “Copy for support” | Merge into Connection Doctor |
| `Cache-Control: no-store` | MCP/OAuth responses never cached (LiteSpeed/CDN breaks clients) | **Do this now** on our endpoints if not already |
| `MOSMCP_PUBLIC_BASE` | wp-config constant so discovery/advertise URLs use a tunnel hostname | Helpful for local Cursor/Claude testing |
| `MOSMCP_EXPOSED_PREFIXES` | Dev constant limits which tool prefixes register | Like tool profiles / discover gateway |
| Preview links + post-write cache flush | Expiring draft URLs; clear page caches after AI edits | Preview = Royal/Easy; flush = allowlisted cache op |
| “Your AI Access” | Members see what their role may use and how to connect | Nice for non-admin MT clients later |
| NHI (non-human identity) store | Agent registrations tracked as first-class identities | Fits Agent Registry / audit attribution |

**miniOrange — skip for us:** Requiring or defaulting to `gateway.miniorange.ai` (third-party hop — same class of concern as WPVibe cloud); 400+ Elementor/Divi/Kadence/Woo/Forms tool surface; theme-file editing pipeline; prompt-injection/anomaly claims without on-site transparency; paid Business/Pro upsells as the only place for DLP/HITL (we’ll own those on-site).

### WSP MCP — `wsp-mcp-ai-agents-connector` 2.9.x (self-hosted, native MCP)

Free WebSensePro connector. Native MCP + optional OAuth (off by default); API key / app password still primary for many users. Differentiator: **Site Context** docs the agent reads before exploring, plus pragmatic transport hardening.

| Idea | How they do it | Our take |
|---|---|---|
| Site Context | Admin AGENTS.md + CHANGELOG.md; push truncated into `initialize` `instructions`; full via tool + `wsp://context/*` resources; agent can update (opt-in) | **Strong complement** to `generate_cursor_skill` — live docs for Claude/ChatGPT without a git repo |
| Writes off by default | Only read tools enabled on install; each mutate tool has its own switch | Align with Force draft / drafts-only / default-readonly |
| JSON output guard | Early `ob_start` on MCP/OAuth URIs; discard stray notices from other plugins before JSON | Cheap reliability win — “connected but no tools” is often corrupted JSON |
| Alternate discovery URL | Extra non-`.well-known` discovery when host blocks `/.well-known/` (SiteGround) | Add if we hit that class of host; keep primary well-known |
| Object-level guards | Shared `guard_edit_post` / `guard_delete_post` / status transition helpers | Same audit item as Enable Abilities |
| Analytics from audit log | Requests, top tools, latency, error rate — no external service | Merge with AI Engine usage insights |
| Refresh-token family revoke | Replaying a spent refresh revokes the chain | Same as Easy MCP OAuth hardening |
| No raw HTML/JS/CSS inject | Blocks agents from injecting raw code into page content | Less relevant for ACF/classic; keep sanitization discipline |
| Connection copy/download | Per-client tabs (Claude, Cursor, Codex, Antigravity, …) | Nice Overview UX; we already lean this way |

**WSP MCP — skip for us:** API-key-first auth (OAuth-user first); Elementor / UAE / Woo / forms suites; theme zip upload / install plugins; Site Editor & widgets packs; Gravity Forms entry dumps without DLP.

### Agent Abilities for MCP — `agent-abilities-for-mcp` 1.9.x

Abilities + **bundled MCP Adapter** with heavy governance. Closest peer for “agent as a scoped WP user + human review before publish.” Differentiator: **write-and-confirm contract**, **Needs review** inbox, and **Site Brief** the agent cannot rewrite.

| Idea | How they do it | Our take |
|---|---|---|
| Off until enabled | None of *their* tools exposed until toggled; updates never auto-enable | Match for any new dangerous tools |
| Read-only mode | One switch unregisters all write abilities (incl. bridged); selections preserved | Complements Force draft / default-readonly |
| Force draft + Needs review | Creates/publishes → draft; Dashboard card Preview / Publish / Discard | Best HITL UX for content — adopt |
| Site Brief | ≤1500 char owner rules in `initialize` instructions; **no ability can write it** | Prefer this over agent-writable Site Context for *policy*; keep WSP-style docs for structure |
| Write-and-confirm | Central writers return written/unchanged/refused/unconfirmed/partial; meta alias detection | Merge with Royal WriteVerifier — formalize our dry_run + verify path |
| Triple allowlist | Global ∩ role ∩ connection (only narrows) | Stronger than single per-grant list |
| Bridged abilities opt-in | Other plugins’ abilities on “Other plugins” screen, off until enabled | Prefer opt-in (Agent Abilities) over Enable Abilities’ denylist-of-everything |
| Page-builder guard | Refuse `post_content` writes when Elementor/Divi/Beaver/Avada owns the page | Good refuse-loudly pattern if a client site has a builder |
| Meta-key allowlist | Only admin-approved keys; never protected/`_`/auth | Important before any generic `update_post_meta` |
| Identifier-only audit | Args keys + ids/slugs/status; denial reason in log not agent message; no email/body text | Privacy-preserving audit — adopt |
| SSRF-safe upload | HTTPS only, no redirects, refuse private/loopback/link-local | Harden our `upload_media` URL path |
| Rate limit + IP allowlist | Optional per-user RPM; empty IP list = open | Optional ops controls |
| Connection Doctor | Server-side self-test (auth header strip, etc.) | Same family as Cowboy/miniOrange |
| Adapter coexistence | Automatic / Always on / Always off for Adapter default server; eager-load wins class race | Stay friendly if Adapter or other ability plugins are present |

**Agent Abilities — skip for us:** Depending on MCP Adapter as the only transport (we keep first-party MCP); Woo / TEC / Avada / GeoDirectory packs; Application Password as equal-primary auth path; bridging arbitrary third-party abilities without review.

### Flex MCP (StifLi) — `stifli-flex-mcp` 3.6.x

Self-hosted OAuth MCP **plus** optional in-WP Copilot / Chat Agent / Automations (those need provider API keys — skip). Differentiator on the server side: **session rollback/redo**, modular **Add-ons**, and strong media upload ergonomics.

| Idea | How they do it | Our take |
|---|---|---|
| Change tracker + session rollback | Before/after snapshots for mutating tools; Logs & Roll Back UI; undo one change, redo, or whole session; `mcp_*` changelog tools | Extend Royal/Easy undo → **session** scope is the unique win |
| Tool profiles | Restrict tool sets (e.g. Read Only) | Same as Royal |
| Optional Add-ons | Copilot/Chat/Automations/SEO/integrations load only when enabled | Pattern for optional GSC/GA packs — don’t load dead code |
| Conversation-file upload | `wp_upload_image` accepts ChatGPT conversation `file` (≤20 MiB); not just base64 | Pair with AI Engine temp-URL for large Figma assets |
| Media optimize fail-closed | JPEG/WebP + max dimensions + target KiB; error if target unmet (no oversized success) | Nice for hero images from agents |
| Dry-run + HTML verify | Option/settings dry-run; after edits, fetch public HTML to confirm strings | Complements WriteVerifier on published pages |
| Preview + publish_and_promote | Expiring preview links; publish + menu in one undoable call | Already on roadmap from Royal |
| Abilities import | Discover/import WP 6.9+ abilities | Same bridge family |
| GSC OAuth add-on | Google Search Console tools via site OAuth | Same as Easy MCP optional analytics |
| OAuth diagnostics | Auth-header strip, Sucuri/CloudProxy, optional new-client approval, metadata URL filter | Fold into Connection Doctor |
| `.mcpb` Claude Desktop bundle | One-click connector download | Nice Overview UX |
| Server icons | Site icon in `serverInfo.icons` on initialize | Cheap polish |
| PII redaction on form reads | Flamingo/WPForms entries redact email/phone/IP/secrets | Same as DLP item |

**Flex MCP — skip for us:** In-WP Copilot / Chat Agent / Automations that store OpenAI/Anthropic/Gemini keys; image/video generation & stock-image search; Woo / Divi / Elementor / CF7 depth; WP MCP Hub as a dependency; Application Passwords as equal primary.

### Out of scope (reviewed, not MT peers)

| Plugin | What it is | Why skipped |
|---|---|---|
| **BEAR / woo-bulk-editor** 1.2.x | WooCommerce products bulk editor with a built-in MCP server (filter, bulk price/stock/SKU, preview, History rollback, Bearer MCP key) | Entire surface is **shop catalogue** — matches our WooCommerce non-goal. Patterns we already borrowed elsewhere (preview-before-write, refuse-loudly validation, history undo, upload links) |
| **Novamira** | Arbitrary PHP / file / DB MCP for developers | Staging-only RCE — explicit non-goal |
| **WordPress.com / Jetpack MCP** | Hosted account MCP (domains, billing, multi-site) | Not self-hosted MT client sites; useful UX patterns borrowed above |
| **Elementor MCP / WooCommerce abilities** | Vendor builder/store MCP via Adapter | Builder/commerce non-goals |

### AI Puffer (AI Power) — `gpt3-ai-content-generator` 2.5.x

Chatbot / content-writer / forms / images suite **plus** MCP website management. Claude/ChatGPT hit **`mcp.aipuffer.org`** (cloud bridge); site registers with Ed25519-signed callbacks and Cloud account. Same architectural reject as WPVibe/miniOrange gateway — keep auth on-site.

| Idea | How they do it | Our take |
|---|---|---|
| Standard vs Trusted | Per-connection safety: Standard needs WP approval for publish/plugin/destructive; Trusted runs directly (default for new); level sync fail-closed (lower now, raise after confirm) | Cleaner than a single safe-mode flag — adopt as connection policy |
| Pause | Global pause: reads continue, writes stop until Resume | Instant incident response — add to admin Overview |
| Operation journal | `operation_id` + states (pending_approval, succeeded, conflict, expired, fenced); never double-executes | Stronger than receipts alone — merge with WPVibe op-id |
| Pending approval + expiry | Journal rows expire if human doesn’t act | Align with HITL queue |
| Bounded WP-CLI subset | In-process “wp help” tools; no shell; same approvals/caps | Same allowlisted-admin-ops family as WPVibe |
| Website-action quotas | Metered monthly actions via Cloud | Skip metering/cloud; optional soft rate limit already from Agent Abilities |
| Verifier | Bridge request: Ed25519 + nonce + grant MAC + skew | Cloud-specific crypto — N/A for direct OAuth |

**AI Puffer — skip for us:** Cloud MCP bridge / credits / Cloud account requirement; chatbot, AI forms, knowledge-base embeddings, image/video gen, visitor credit Woo products; Elementor MCP depth; storing provider API keys for in-WP AI.

### Docs deep dive — peers not in the Downloads zip set (Oct 2026)

Sources: WordPress.org listings, vendor docs, [WPResources comparison](https://wpresources.org/articles/best-wordpress-mcp-plugins), [Easy MCP comparison](https://easymcpai.com/blog/best-wordpress-mcp-server-plugin-2026), Cowboy/Agent Abilities/Sokket/Zen/WordPress.com docs.

| Peer | Architecture | Steal | Skip |
|---|---|---|---|
| **Sokket** | Self-hosted; OAuth + user-bound tokens; ~20 tools | **Three-gate** allowlist ∩ read-only ∩ caps; server off until enabled; writes off by default; delete badges; DNS-rebinding Origin checks; audit without args; `sokket_register_tools`-style extension seam | Thin content surface; Pro file/DB/WP-CLI packs |
| **Zen MCP Bridge** | Self-hosted; read-first debug bridge | Architecture inspectors (hooks/REST/schema/ACF) with **secret redaction**; structured DB reads (no raw SQL); write mode that **cannot publish/delete**; revoke-all generation counter (object-cache safe) | File/code search of `wp-content` on client prod; Bearer-never-expires; Elementor/Woo inspectors |
| **Official MCP Adapter** | Abilities bridge only; no first-party OAuth | Coexistence (`Requires Plugins: mcp-adapter`); raise meta-tool capability above default `read` on shared sites | Not a turnkey Claude/ChatGPT connector alone |
| **WordPress.com MCP** | Hosted OAuth; Jetpack-connected sites | Account-level per-tool toggles; **two-phase** dangerous ops (`preview` → `state_token` → apply); “keep existing access” on reconnect | Domain/DNS/billing tools; Jetpack-only; `user_confirmed` set by the *agent* (anti-pattern vs human HITL) |
| **Novamira** | Self-hosted; arbitrary PHP/`eval` | — | Staging-only RCE by design |
| **Elementor / WooCommerce MCP** | Vendor abilities via Adapter | Draft-first Elementor pages; refuse editing builder data from outside its pipeline | Builder/commerce stacks we don’t ship |

**Extra Cowboy/Agent Abilities doc notes (beyond the zips):** checkpoint restore must re-apply MCP credentials; uninstall ≠ revoke App Passwords; OAuth *scope* does not narrow tools (user caps do); Application Passwords are whole-site credentials — plugin allowlists only bind calls that hit *our* endpoint; title cap + block-markup validation; high-risk second lock; alert emails without content.

---

## Explicit non-goals

- Replacing WP Admin for humans
- Running arbitrary PHP / shell from tools (includes Novamira-style `eval`)
- Storing LLM API keys inside WordPress (AI Engine chatbot model — we are MCP-only)
- Shared site API keys / bearer secrets (backdoor risk; includes AI Engine-style static MCP bearer / URL tokens)
- Relaying app passwords through a third-party MCP cloud (WPVibe model — keep auth on-site OAuth)
- Defaulting MCP traffic through a vendor gateway (miniOrange `gateway.miniorange.ai`, AI Puffer `mcp.aipuffer.org` — never required)
- Trusting agent-set `user_confirmed` flags as human approval (WordPress.com anti-pattern — HITL must be human UI)
- Hosting chatbots / embeddings / AI Forms inside WordPress (AI Engine / AI Puffer product surface)
- Metered “website action” Cloud quotas as a gate on MCP tools (AI Puffer)
- Shipping the SEO Meta Bridge REST API (stays in Utilities if needed)
- Dumping Gravity Forms entry PII by default
- Unrestricted `wp_options` or user password changes
- Gutenberg block-tree editing / FSE patterns (MT sites are ACF + classic editor, not block editor)
- WooCommerce / e-commerce tooling (not used on MT client sites)
- Page-builder save pipelines (Elementor / Bricks / Beaver / Divi — not used)
- Live-site “vibe coding” via WP-CLI / arbitrary `wp-content` file writes (Cowboy MCP Power mode — themes stay in git)
