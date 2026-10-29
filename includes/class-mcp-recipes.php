<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MT MCP cookbook: copyable agent prompts for common Motion Tactic workflows.
 * Focused on ACF/classic themes, Yoast, menus, media, staging→prod — not Woo/builders.
 */
class McpRecipes {

	/**
	 * Category slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			'setup'       => 'Setup',
			'content'     => 'Content',
			'acf'         => 'ACF',
			'seo'         => 'SEO',
			'media'       => 'Media',
			'menus'       => 'Menus',
			'migration'   => 'Migration',
			'launch'      => 'Launch',
			'maintenance' => 'Maintenance',
			'safety'      => 'Safety',
		);
	}

	/**
	 * All recipes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$recipes = array(
			array(
				'id'         => 'connection-doctor',
				'title'      => 'Diagnose MCP connection',
				'summary'    => 'Check HTTPS, .well-known discovery, MCP/REST health, write gates, and WAF hints. Read only.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => false,
				'tags'       => array( 'oauth', 'waf', 'doctor' ),
				'prompt'     => "You are connected to this WordPress site via MT MCP.\n\n1. Call connection_doctor and summarize failed checks first.\n2. If .well-known is not HTTP 200 (or redirects), explain the WAF/host fix and stop.\n3. Confirm get_site_context environment, writes_enabled, pause_writes, force_draft, high_risk_allowed, and site_brief.\n4. Note auth_header_probe / waf_signals if present.\n5. Paste the paste_for_support block if anything failed (secrets are already redacted).\n\nDo not write anything.",
			),
			array(
				'id'         => 'onboard-skill',
				'title'      => 'Onboard a client site (generate Cursor skill)',
				'summary'    => 'Inventory post types, ACF schemas, options pages, and menus; generate a Cursor skill for this site.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => false,
				'tags'       => array( 'acf', 'skill', 'inventory' ),
				'prompt'     => "Onboard this MT client site for future agent sessions.\n\n1. get_site_context — note environment, theme, ACF/Yoast/redirects, Site Brief.\n2. inventory_post_types (+ permalink_clash_report) and describe_post_type for each public CPT we care about.\n3. list_acf_field_groups + get_acf_schema for the main page/CPT types (flexible layouts included).\n4. list_acf_options_pages + get_options_page for site-wide options.\n5. list_menus + get_menu for each location.\n6. plugin_capability_matrix — note which SEO/redirect packs are unlocked.\n7. generate_cursor_skill and return the skill markdown so I can save it in the client repo.\n\nRead only. Do not mutate content.",
			),
			array(
				'id'         => 'session-plan',
				'title'      => 'Plan before writing',
				'summary'    => 'Propose the exact tool sequence, dry-run checkpoints, and ask before the first write.',
				'category'   => 'safety',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => false,
				'tags'       => array( 'dry-run', 'plan' ),
				'prompt'     => "Goal: [describe what you want done]\n\nBefore writing anything:\n1. get_site_context (confirm environment + Site Brief + write gates).\n2. List the exact tools you will call, in order, marking each read vs write.\n3. Identify dry_run / state_token checkpoints.\n4. Ask for confirmation before the first write tool.\n\nDo not mutate content until I approve the plan.",
			),
			array(
				'id'         => 'acf-gap-check',
				'title'      => 'Find posts with empty ACF fields',
				'summary'    => 'Compare ACF schema to live posts and list empty/missing fields per URL. Read only.',
				'category'   => 'acf',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'acf', 'gaps', 'read-only' ),
				'prompt'     => "Check ACF completeness for post_type=\"[page or CPT]\".\n\n1. get_site_context\n2. get_acf_schema for that post type (note required + flexible layouts).\n3. Use search_posts with missing_required_acf / acf_empty where helpful; otherwise list_posts published items.\n4. For incomplete posts, get_post with include:[\"acf\"] (use only_acf_fields when payloads are large).\n5. Report a table: URL, title, empty/missing fields by label. Count gaps per field.\n\nDo not write unless asked.",
			),
			array(
				'id'         => 'acf-fill-draft',
				'title'      => 'Fill empty ACF fields as drafts',
				'summary'    => 'Propose ACF values from existing content, dry-run updates, then apply only after approval. Force-draft friendly.',
				'category'   => 'acf',
				'difficulty' => 'intermediate',
				'minutes'    => 20,
				'writes'     => true,
				'tags'       => array( 'acf', 'update_post', 'dry-run' ),
				'prompt'     => "Fill empty ACF fields on post_type=\"[CPT]\" for up to 10 incomplete posts.\n\n1. Run the ACF gap check first (schema + empty fields).\n2. For each post, draft proposed values from existing title/content/related fields — show me old → new before saving.\n3. update_post with dry_run:true for the first post; show verification.\n4. After I approve, apply with update_post (keep status draft if force_draft is on; do not publish).\n5. Prefer operation_id on writes. Stop after 10 posts or when I say stop.\n\nNever edit siteurl/options/MCP settings. Respect Site Brief.",
			),
			array(
				'id'         => 'duplicate-as-draft',
				'title'      => 'Clone a live page as draft',
				'summary'    => 'Duplicate a published page/CPT (core + tax + ACF + featured) so edits happen off live.',
				'category'   => 'content',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => true,
				'tags'       => array( 'duplicate_post', 'draft' ),
				'prompt'     => "Clone this live page as a draft for editing: [URL or ID]\n\n1. get_post with include:[\"core\",\"taxonomies\",\"acf\",\"yoast\",\"featured_image\"].\n2. duplicate_post (or create via duplicate_post tool) as draft — confirm core + ACF + featured copied.\n3. Optionally create_draft_preview and give me the preview link.\n4. Do not change the original published post.\n\nSummarize what was copied and the new draft ID.",
			),
			array(
				'id'         => 'surgical-replace',
				'title'      => 'Surgical find-and-replace',
				'summary'    => 'Find strings in content/ACF with dry_run + expected_count, then apply only after match counts look right.',
				'category'   => 'content',
				'difficulty' => 'intermediate',
				'minutes'    => 10,
				'writes'     => true,
				'tags'       => array( 'find_replace', 'dry-run' ),
				'prompt'     => "Find and replace on this site.\n\nFind: \"[old]\"\nReplace with: \"[new]\"\nScope: [content / title / excerpt / ACF / post_type=…]\n\n1. find_replace with dry_run:true — report match counts and nearest mismatches if zero.\n2. Show me expected_count and sample hits.\n3. Only after I confirm, re-call with apply:true and expected_count set.\n4. Prefer operation_id. Do not touch redirects or options unless I asked.",
			),
			array(
				'id'         => 'seo-pass',
				'title'      => 'SEO title & meta pass',
				'summary'    => 'Audit SEO, draft better titles/descriptions, dry-run bulk updates, then apply when approved.',
				'category'   => 'seo',
				'difficulty' => 'beginner',
				'minutes'    => 20,
				'writes'     => true,
				'tags'       => array( 'yoast', 'rankmath', 'seo_audit' ),
				'prompt'     => "Run an SEO pass on post_type=\"[page or post]\".\n\n1. get_site_context + plugin_capability_matrix (confirm Yoast / Rank Math / SEOPress).\n2. seo_audit + seo_social_audit for that post type.\n3. For the worst offenders, get_seo_social / get_post include:[\"core\",\"yoast\"] and draft improved title / metadesc / focuskw / OG (title ≤60, metadesc ≤160).\n4. Preview with bulk_update_yoast or bulk_update_seo dry_run:true.\n5. Apply only after I approve.\n\nDo not publish drafts or change canonicals unless asked.",
			),
			array(
				'id'         => 'media-alt-pass',
				'title'      => 'Fill missing image alt text',
				'summary'    => 'Report attachments missing alt, propose alts, then bulk_fix_media_alt / update_media after approval.',
				'category'   => 'media',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'a11y', 'alt', 'media' ),
				'prompt'     => "Fix missing image alt text on this site.\n\n1. media_alt_report — summarize counts and list sample IDs.\n2. Optionally acf_media_audit for broken ACF image refs / missing alts in fields.\n3. Propose alt text for up to 25 images (from filename/title/context). Show me the list.\n4. After approval, bulk_fix_media_alt or update_media per id. Prefer dry_run first if available.\n\nDo not replace files or delete attachments.",
			),
			array(
				'id'         => 'acf-media-audit',
				'title'      => 'Audit ACF images & galleries',
				'summary'    => 'Find broken attachment IDs, missing files, and missing alts in ACF image/gallery/file fields.',
				'category'   => 'media',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'acf', 'media', 'audit' ),
				'prompt'     => "Run acf_media_audit for post_type=\"[page or CPT]\" (published + drafts if useful).\n\n1. Group issues: broken IDs, missing files, missing alts.\n2. List post ID, field path, attachment ID, issue.\n3. Suggest next tools (update_media, replace_attachment_file, or content edit) — do not write yet.\n\nRead only.",
			),
			array(
				'id'         => 'unused-media',
				'title'      => 'Find unused media candidates',
				'summary'    => 'Heuristic unused attachment report. Review before any delete.',
				'category'   => 'media',
				'difficulty' => 'intermediate',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'media', 'cleanup' ),
				'prompt'     => "Call unused_media_report (limit 40, mime_type image if useful).\n\n1. Explain it is heuristic (sample of posts + featured + ACF).\n2. List candidates with id, title, date, url.\n3. Do NOT trash or force-delete anything unless I explicitly approve specific IDs — and never force_delete without state_token / confirm.\n\nRead only for now.",
			),
			array(
				'id'         => 'menu-ia',
				'title'      => 'Update navigation IA',
				'summary'    => 'Inspect menus/locations, plan item changes with dry_run, then update_menu_items.',
				'category'   => 'menus',
				'difficulty' => 'intermediate',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'menus', 'ia' ),
				'prompt'     => "Update site navigation.\n\nDesired IA:\n[paste desired structure]\n\n1. list_menus + get_menu for each location.\n2. Propose add/update/delete/reorder ops; validate links.\n3. update_menu_items with dry_run:true first.\n4. After approval, apply. Do not delete a menu (delete_menu) unless I explicitly ask.\n\nRespect Site Brief.",
			),
			array(
				'id'         => 'staging-to-prod',
				'title'      => 'Move a page staging → production',
				'summary'    => 'Export from staging (embed media), import on prod with dry_run, remap attachments, then apply.',
				'category'   => 'migration',
				'difficulty' => 'advanced',
				'minutes'    => 25,
				'writes'     => true,
				'tags'       => array( 'export_post', 'import_post', 'transfer' ),
				'prompt'     => "Move a page from staging to production via MT MCP.\n\nSource URL / ID: [staging]\nTarget site: this connection (production).\n\n1. On staging: export_post with embed_media (or export_posts). Note package shape.\n2. On production: check_import_conflicts / import_post with dry_run:true — show remaps and conflicts.\n3. After approval, import_post apply. Prefer draft on prod if force_draft is on.\n4. Verify with get_post + optionally diff_public_html after publish.\n5. Use manage_connections presets if named staging/prod URLs are stored.\n\nNever overwrite live without dry_run. Never touch OAuth credentials.",
			),
			array(
				'id'         => 'redirect-import',
				'title'      => 'Bulk import redirects',
				'summary'    => 'Preview redirect CSV/items via state_token, then confirm_dangerous_op / apply.',
				'category'   => 'migration',
				'difficulty' => 'intermediate',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'redirects', 'state_token' ),
				'prompt'     => "Import redirects safely.\n\nCSV or items:\n[paste]\n\n1. plugin_capability_matrix / list_redirects — confirm Redirection or Yoast Premium backend.\n2. Confirm high_risk_allowed (Settings → Allow high-risk destructive ops, or set_write_gates). Import apply is locked when high-risk is off.\n3. import_redirects dry_run / preview — expect a state_token.\n4. Show me the planned from→to list and counts.\n5. Only after I approve, confirm_dangerous_op or re-call with state_token/apply.\n\nDo not wipe existing redirects.",
			),
			array(
				'id'         => 'launch-prep',
				'title'      => 'Pre-launch checklist',
				'summary'    => 'Menus, redirects, SEO audit, ACF content review, schema/sitemap signals — blockers vs nice-to-haves.',
				'category'   => 'launch',
				'difficulty' => 'intermediate',
				'minutes'    => 30,
				'writes'     => false,
				'tags'       => array( 'launch', 'qa' ),
				'prompt'     => "Run a pre-launch review on this site.\n\n1. get_site_context + connection_doctor (fix discovery/WAF).\n2. list_menus + get_menu for each location.\n3. list_redirects (sample).\n4. seo_audit on page (and post if used).\n5. content_review on page with check_acf:true; preflight_publish on key URLs.\n6. Optional: sitemap_diff, validate_schema_ld, suggest_internal_links, check_links on key pages.\n7. Produce a launch-readiness report: blockers vs nice-to-haves.\n\nNo writes unless asked.",
			),
			array(
				'id'         => 'preflight-publish',
				'title'      => 'Preflight before publishing',
				'summary'    => 'One-post check: SEO, required ACF, featured image, alts, schema sample.',
				'category'   => 'launch',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => false,
				'tags'       => array( 'preflight_publish', 'qa' ),
				'prompt'     => "Preflight this draft before publish: [URL or ID]\n\n1. preflight_publish for that post.\n2. Summarize blockers vs warnings (SEO, required ACF, featured, alt, schema).\n3. If force_draft / needs_review, explain review_draft options (publish|keep|discard) — do not publish until I say so.\n\nRead only unless I ask you to publish.",
			),
			array(
				'id'         => 'content-inventory',
				'title'      => 'Content inventory',
				'summary'    => 'Counts and incomplete pages/posts with ACF flags. Read only.',
				'category'   => 'content',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'inventory', 'content_review' ),
				'prompt'     => "Inventory content for post_type=\"[page]\".\n\n1. get_site_context\n2. content_review with check_acf:true\n3. Summarize counts and list the top incomplete URLs with their flags.\n\nDo not write anything unless asked.",
			),
			array(
				'id'         => 'needs-review-inbox',
				'title'      => 'Clear the needs-review inbox',
				'summary'    => 'List force-draft MCP posts and publish, keep, or discard each after preview.',
				'category'   => 'maintenance',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'force_draft', 'review_draft' ),
				'prompt'     => "Process the MT MCP needs-review inbox.\n\n1. list_needs_review.\n2. For each item, summarize title/status/type and optionally create_draft_preview.\n3. Ask me per item: publish, keep (clear flag), or discard (trash).\n4. review_draft with the chosen action. Prefer dry_run first on the first item.\n\nDo not publish everything in one shot without per-item approval.",
			),
			array(
				'id'         => 'verify-public-html',
				'title'      => 'Verify public HTML after an edit',
				'summary'    => 'Fetch the live (or preview) page and check title/h1/meta/expected snippet.',
				'category'   => 'maintenance',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => false,
				'tags'       => array( 'diff_public_html', 'qa' ),
				'prompt'     => "Verify the public HTML for [URL or ID] after our edits.\n\n1. diff_public_html with expect_contains=\"[key phrase we changed]\".\n2. Report http status, title, h1, meta description, and which checks failed.\n3. If the post is still draft, use preview_url from create_draft_preview.\n\nRead only.",
			),
			array(
				'id'         => 'check-links',
				'title'      => 'Check links on a post type',
				'summary'    => 'Extract and HTTP-check links from a post or capped post_type sample.',
				'category'   => 'maintenance',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'check_links', 'qa' ),
				'prompt'     => "Check outbound/internal links.\n\nScope: [single URL/ID OR post_type=page, limit 20]\n\n1. check_links with a sane max_links (≤40).\n2. List broken URLs with status/error and source post_id.\n3. Suggest fixes; do not rewrite content until I approve.\n\nRead only for now.",
			),
			array(
				'id'         => 'undo-last',
				'title'      => 'Undo a recent MCP change',
				'summary'    => 'Inspect before/written/now via get_change, then undo_change (force only if drifted).',
				'category'   => 'safety',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => true,
				'tags'       => array( 'undo', 'list_changes' ),
				'prompt'     => "Undo a recent MT MCP change.\n\n1. list_changes — show recent tokens, tools, labels, drift flags.\n2. get_change for the token I care about — summarize before vs written vs now.\n3. undo_change with dry_run:true first.\n4. After I confirm, undo_change (use force:true only if drift and I explicitly allow it).\n\nNever touch OAuth grants or MCP plugin options. Confirm restore kept credentials working via get_site_context.",
			),
			array(
				'id'         => 'pause-writes',
				'title'      => 'Incident: pause writes',
				'summary'    => 'Freeze all write tools site-wide while reads keep working.',
				'category'   => 'safety',
				'difficulty' => 'beginner',
				'minutes'    => 2,
				'writes'     => true,
				'tags'       => array( 'set_write_gates', 'incident' ),
				'prompt'     => "Incident response: pause MCP writes on this site.\n\n1. get_site_context — confirm current pause_writes / allow_writes.\n2. set_write_gates with pause_writes:true.\n3. Confirm connection_doctor / get_site_context shows writes paused.\n4. Do not clear the pause unless I say so.\n\nReads may continue. Explain that set_write_gates is the exception that still works while paused.",
			),
			array(
				'id'         => 'theme-templates',
				'title'      => 'List page templates',
				'summary'    => 'Show theme page templates for page/hierarchical CPTs.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 3,
				'writes'     => false,
				'tags'       => array( 'templates', 'theme' ),
				'prompt'     => "Call list_theme_templates (and describe_post_type for page if useful).\n\nReturn theme name, stylesheet, and template file → label map. Read only.",
			),


			array(
				'id'         => 'client-rollout',
				'title'      => 'Client rollout checklist',
				'summary'    => 'First-install gates: doctor green, Site Brief, AGENTS.md stub, high-risk off, updates off on prod, Cursor skill. Operational setup.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'rollout', 'gates', 'agents_md', 'doctor', 'setup' ),
				'prompt'     => "Prepare this WordPress site for MT MCP client rollout.\n\n1. connection_doctor — all critical checks green (HTTPS, .well-known or alternates, MCP endpoint, auth_header_probe). Fix failures before continuing; paste_for_support if stuck.\n2. get_site_context — note environment. If production: confirm Allow MCP writes is intentional; Allow MCP plugin/core updates must stay OFF unless this is a staging drill; high_risk_allowed must be OFF.\n3. Propose a Site Brief (≤2000 chars) covering: never publish without approval, force_draft preference, sensitive pages (legal/careers), who to ping. After I approve, set_write_gates site_brief:\"…\".\n4. Propose a short AGENTS.md stub (brand voice, main CPTs, ACF flexible layouts, SEO plugin). After I approve, update_site_docs — only if docs_write_allowed; otherwise print markdown for me to paste in Settings → AGENTS.md. Optionally seed CHANGELOG.md with today's rollout note.\n5. Confirm force_draft on for production client sites (set_write_gates if needed).\n6. generate_cursor_skill — return skill markdown for the client repo.\n7. Summarize a one-page rollout card: environment, gates (writes/pause/force_draft/high_risk/updates), discovery URLs, MCP URL for Cursor, doctor status.\n\nDo not enable high_risk or updates on production. Do not mutate content pages.",
			),
			array(
				'id'         => 'seed-site-docs',
				'title'      => 'Seed AGENTS.md + CHANGELOG',
				'summary'    => 'Draft site context docs from get_site_context + ACF inventory; write only if docs_write_allowed.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => true,
				'tags'       => array( 'agents_md', 'changelog', 'get_site_docs' ),
				'prompt'     => "Seed site docs for chat agents.\n\n1. get_site_context + get_site_docs.\n2. inventory_post_types + list_acf_field_groups (summary only).\n3. Draft AGENTS.md: environment rules, CPTs, where flexible content lives, SEO/redirect plugins, \"prefer drafts\".\n4. Draft a CHANGELOG.md starter with today's date.\n5. Show both for approval. If docs_write_allowed, update_site_docs dry_run then apply; else give me paste-ready markdown for Settings.\n\nSite Brief wins on conflict with AGENTS.md.",
			),
			array(
				'id'         => 'soft-404-pass',
				'title'      => 'Sitemap soft-404 crawl',
				'summary'    => 'GET-check published URLs (or sitemap) for soft-404 body signals beyond HEAD.',
				'category'   => 'launch',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => false,
				'tags'       => array( 'sitemap_diff', 'soft_404', 'qa' ),
				'prompt'     => "Crawl for soft-404s.\n\n1. sitemap_diff with check_body:true, source:\"posts\" (or source:\"sitemap\"), limit 40.\n2. Group issues: http_4xx, redirect, soft_404 (include signal).\n3. Suggest redirects or content fixes; do not write until I approve.\n\nRead only.",
			),
			array(
				'id'         => 'permalink-clash-audit',
				'title'      => 'Permalink / CPT clash audit',
				'summary'    => 'Deep CPT inventory plus duplicate slug report before IA or import work.',
				'category'   => 'setup',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => false,
				'tags'       => array( 'inventory_post_types', 'permalink_clash_report' ),
				'prompt'     => "Audit post types and slug clashes.\n\n1. inventory_post_types.\n2. permalink_clash_report.\n3. Table of clashes with types/URLs and recommended unique slugs.\n\nRead only — do not rename until I approve.",
			),
			array(
				'id'         => 'internal-links-pass',
				'title'      => 'Suggest internal links',
				'summary'    => 'For key URLs, suggest related published targets; draft inserts only after approval.',
				'category'   => 'seo',
				'difficulty' => 'intermediate',
				'minutes'    => 20,
				'writes'     => true,
				'tags'       => array( 'suggest_internal_links', 'seo' ),
				'prompt'     => "Internal linking pass for: [list of URLs or IDs]\n\n1. For each source, suggest_internal_links.\n2. Propose 1–3 contextual link insertions (anchor + target) without stuffing.\n3. Show old → new excerpts. dry_run update_post / find_replace first.\n4. Apply only after I approve, prefer drafts on production.\n\nRespect Site Brief.",
			),
			array(
				'id'         => 'schedule-content',
				'title'      => 'Schedule a draft to publish',
				'summary'    => 'Preflight a draft, then schedule_post for a future time after approval.',
				'category'   => 'content',
				'difficulty' => 'beginner',
				'minutes'    => 10,
				'writes'     => true,
				'tags'       => array( 'schedule_post', 'preflight_publish' ),
				'prompt'     => "Schedule this draft: [ID or URL]\nPublish at: [local datetime + timezone]\n\n1. preflight_publish — list blockers.\n2. If blockers, stop and report.\n3. schedule_post dry_run if available, else show the planned datetime.\n4. After I approve, schedule_post. Do not publish immediately unless I said \"publish now\".",
			),
			array(
				'id'         => 'options-page-edit',
				'title'      => 'Edit ACF options page',
				'summary'    => 'Read options schema/values, propose field updates with dry_run, then apply.',
				'category'   => 'acf',
				'difficulty' => 'intermediate',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'update_options_page', 'acf' ),
				'prompt'     => "Update ACF options page fields.\n\nPage: [option page slug or \"option\"]\nChanges wanted: [describe]\n\n1. list_acf_options_pages + get_acf_schema / get_options_page.\n2. Propose field → new value map.\n3. update_options_page dry_run:true — show diff/verification.\n4. Apply only after I approve.\n\nNever touch protected options (siteurl, active_plugins, keys).",
			),
			array(
				'id'         => 'term-taxonomy-setup',
				'title'      => 'Set up categories / terms',
				'summary'    => 'Inventory taxonomies, create missing terms as dry_run first, then apply.',
				'category'   => 'content',
				'difficulty' => 'beginner',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'create_term', 'taxonomies' ),
				'prompt'     => "Set up taxonomy terms.\n\nTaxonomy: [category / custom]\nDesired tree:\n[paste]\n\n1. list_taxonomies + get_terms.\n2. Diff desired vs existing.\n3. create_term / update_term with dry_run first.\n4. Apply after approval. delete_term only if I explicitly ask (permanent).\n\nDo not reassign posts until terms exist.",
			),
			array(
				'id'         => 'bulk-reassign-author',
				'title'      => 'Bulk reassign author / status',
				'summary'    => 'list_users + bulk_update_posts with dry_run for status/author/parent changes.',
				'category'   => 'content',
				'difficulty' => 'intermediate',
				'minutes'    => 15,
				'writes'     => true,
				'tags'       => array( 'bulk_update_posts', 'list_users' ),
				'prompt'     => "Bulk update posts.\n\nFilter: [post_type / status / ids]\nChange: [author and/or status and/or parent]\n\n1. list_users (safe fields only) if assigning author.\n2. list_posts / search_posts to confirm targets.\n3. bulk_update_posts dry_run:true — show count and sample.\n4. Apply after I approve. Prefer drafts over publish on production.",
			),
			array(
				'id'         => 'replace-attachment-keep-id',
				'title'      => 'Replace media file keep ID',
				'summary'    => 'Swap attachment bytes while keeping the same ID so ACF image fields stay valid.',
				'category'   => 'media',
				'difficulty' => 'intermediate',
				'minutes'    => 10,
				'writes'     => true,
				'tags'       => array( 'replace_attachment_file', 'media' ),
				'prompt'     => "Replace an attachment file without changing its ID.\n\nAttachment ID or URL: […]\nNew file: [url or note that I will provide data]\n\n1. get_media — confirm current file/mime/alt.\n2. find_media_by_hash if we might already have the new bytes.\n3. replace_attachment_file dry_run if available, then apply. Keep alt unless I supply a new one.\n4. Confirm ACF fields still point at the same ID.\n\nDo not upload a duplicate unless force_new is required.",
			),
			array(
				'id'         => 'flush-after-deploy',
				'title'      => 'Flush caches after deploy',
				'summary'    => 'Allowlisted flush_caches (object cache / rewrites / known page caches) with dry_run first.',
				'category'   => 'maintenance',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => true,
				'tags'       => array( 'flush_caches' ),
				'prompt'     => "Flush caches after a deploy/content push.\n\n1. flush_caches dry_run:true with targets like [\"object_cache\",\"rewrites\"] plus any active page cache from plugin_capability_matrix / site context (wp_rocket, litespeed, w3tc, autoptimize, elementor).\n2. Show planned vs skipped.\n3. After I approve, flush_caches without dry_run.\n\nNo shell. Do not flush unknown plugins.",
			),
			array(
				'id'         => 'draft-preview-share',
				'title'      => 'Share a draft preview link',
				'summary'    => 'create_draft_preview for stakeholder review without publishing.',
				'category'   => 'content',
				'difficulty' => 'beginner',
				'minutes'    => 3,
				'writes'     => true,
				'tags'       => array( 'create_draft_preview' ),
				'prompt'     => "Create a shareable preview for draft [ID or URL].\n\n1. get_post confirm status=draft (or pending).\n2. create_draft_preview — return the preview URL and expiry.\n3. Do not publish. Offer revoke_draft_preview if I need to kill the link later.",
			),
			array(
				'id'         => 'usage-to-recipes',
				'title'      => 'Usage insights → recipe ideas',
				'summary'    => 'Aggregate audit log tool usage and map to cookbook recipes / gaps.',
				'category'   => 'maintenance',
				'difficulty' => 'beginner',
				'minutes'    => 5,
				'writes'     => false,
				'tags'       => array( 'get_usage_insights', 'audit', 'recipes' ),
				'prompt'     => "Analyze how this site uses MT MCP.\n\n1. get_usage_insights (limit recent audit entries).\n2. Summarize top tools, error rate, and matched recipes.\n3. List gaps: frequent tools with weak/no recipe coverage — suggest new recipe titles.\n\nRead only. Do not export PII; audit is identifier-only.",
			),

			array(
				'id'         => 'plugin-core-updates',
				'title'      => 'Plugin & core update session',
				'summary'    => 'List updates, confirm packages, update one-at-a-time with zip rollback, smoke-check after each, full suite + report at the end.',
				'category'   => 'maintenance',
				'difficulty' => 'advanced',
				'minutes'    => 45,
				'writes'     => true,
				'tags'       => array( 'updates', 'plugins', 'core', 'rollback', 'smoke' ),
				'prompt'     => "Run a controlled WordPress update session on this site via MT MCP.\n\nKey pages to smoke-test: [home + 2–3 critical URLs]\nOptional form probe: [url + fields + success text, or none]\nOptional edit-probe post ID (draft/test page): [id or none]\n\n1. get_site_context — confirm environment and updates_allowed. If updates_allowed is false, tell me to enable Settings → MT MCP → Allow MCP plugin/core updates and stop.\n2. list_updates (force_check:true). Show a table: type, name, id, from → to. Flag MT MCP itself; do not include it unless I explicitly allow_self_update.\n3. Ask me which ids to update. Do not start until I confirm the list.\n4. start_update_session with those ids, smoke_urls, and optional form_probe / edit_probe_post_id.\n5. For each pending package, in order:\n   a. update_package (session_id + id). For wordpress core, only with acknowledge_core_no_zip_rollback=true after I confirm a host backup exists.\n   b. run_smoke_checks level=quick, after=id (success disarms the dead-man timer).\n   c. If checks fail: rollback_package immediately. If MCP is down: wait for auto-rollback or run `wp --skip-plugins mt-mcp-guard rollback`.\n   d. If still broken after rollback, stop and report. Do not continue the queue.\n6. After all succeed: run_smoke_checks level=full.\n7. finish_update_session status=completed and present the report (packages, from/to, rollbacks, check results).\n\nI may say stop / rollback / abort at any step — honor that (finish_update_session status=aborted if ending early).\nNever update everything in one call. Prefer staging over production.",
			),
		);

		/**
		 * Filter the MT MCP recipe cookbook.
		 *
		 * @param array $recipes Recipe list.
		 */
		return apply_filters( 'mt_mcp_recipes', $recipes );
	}

	/**
	 * @param string $id Recipe id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		$id = sanitize_title( (string) $id );
		foreach ( self::all() as $recipe ) {
			if ( isset( $recipe['id'] ) && $recipe['id'] === $id ) {
				return $recipe;
			}
		}
		return null;
	}

	/**
	 * Short “already connected” prompt for a recipe.
	 *
	 * @param array $recipe Recipe row.
	 * @return string
	 */
	public static function short_prompt( $recipe ) {
		$title = isset( $recipe['title'] ) ? $recipe['title'] : 'recipe';
		return 'Run the “‘ . $title . ’” recipe from the MT MCP cookbook on this connected site. Follow its steps; prefer dry_run before writes; respect Site Brief and write gates.';
	}

	/**
	 * Suggest recipes whose tags/tools overlap with used tool names.
	 *
	 * @param string[] $tools Tool names from audit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggest_for_tools( $tools ) {
		$tools = array_values( array_unique( array_filter( array_map( 'strval', (array) $tools ) ) ) );
		$out   = array();
		foreach ( self::all() as $recipe ) {
			$tags = isset( $recipe['tags'] ) ? (array) $recipe['tags'] : array();
			$hay  = strtolower( implode( ' ', $tags ) . ' ' . ( isset( $recipe['id'] ) ? $recipe['id'] : '' ) . ' ' . ( isset( $recipe['title'] ) ? $recipe['title'] : '' ) );
			$hits = array();
			foreach ( $tools as $tool ) {
				$t = strtolower( (string) $tool );
				if ( ! $t ) {
					continue;
				}
				if ( in_array( $t, $tags, true ) || false !== strpos( $hay, $t ) ) {
					$hits[] = $tool;
				}
			}
			if ( $hits ) {
				$out[] = array(
					'id'            => $recipe['id'],
					'title'         => $recipe['title'],
					'category'      => isset( $recipe['category'] ) ? $recipe['category'] : '',
					'matched_tools' => array_values( array_unique( $hits ) ),
					'summary'       => isset( $recipe['summary'] ) ? $recipe['summary'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * MCP prompts/list shaped entries derived from recipes that map cleanly.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function as_mcp_prompt_list() {
		$order = array(
			'client-rollout'    => 'client_rollout',
			'session-plan'      => 'session_plan',
			'seo-pass'          => 'seo_pass',
			'content-inventory' => 'content_inventory',
			'acf-gap-check'     => 'acf_gap_check',
			'launch-prep'       => 'launch_prep',
		);
		$by_id = array();
		foreach ( self::all() as $recipe ) {
			$by_id[ $recipe['id'] ] = $recipe;
		}
		$out = array();
		foreach ( $order as $recipe_id => $prompt_name ) {
			if ( empty( $by_id[ $recipe_id ] ) ) {
				continue;
			}
			$recipe = $by_id[ $recipe_id ];
			if ( 'session-plan' === $recipe_id ) {
				$args = array( array( 'name' => 'goal', 'description' => 'What the user wants done', 'required' => true ) );
			} elseif ( in_array( $recipe_id, array( 'launch-prep', 'client-rollout' ), true ) ) {
				$args = array();
			} else {
				$args = array( array( 'name' => 'post_type', 'description' => 'Post type (default page)', 'required' => ( 'acf-gap-check' === $recipe_id ) ) );
			}
			$out[]  = array(
				'name'        => $prompt_name,
				'description' => $recipe['summary'],
				'arguments'   => $args,
			);
		}
		return $out;
	}
}
