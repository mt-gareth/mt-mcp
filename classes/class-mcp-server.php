<?php

namespace MT\Mcp;

use MT\Mcp\McpSupport;
use MT\Mcp\McpToolHandlers;

/**
 * MT MCP Server
 *
 * Stateless MCP (Model Context Protocol) over plain HTTP JSON-RPC:
 *   https://<this-site>/mt-mcp
 *   https://<this-site>/wp-json/mt-mcp/v1
 *
 * Auth (preferred): OAuth 2.1 + PKCE — agent logs in as a WordPress user.
 * Also: WordPress Application Passwords. No shared API keys.
 *
 * Capabilities: tools, resources, prompts. Writes require site-wide enable
 * plus the authenticated user's capabilities.
 */
class McpServer {

	const OPTION_ENABLED     = 'mt_mcp_enabled';
	const OPTION_ALLOW_WRITE = 'mt_mcp_allow_writes';
	const OPTION_FLUSHED     = 'mt_mcp_flushed';
	const ROUTE_SLUG         = 'mt-mcp';
	const SERVER_VERSION     = '3.0.0';

	const PROTOCOL_VERSIONS        = array( '2025-06-18', '2025-03-26', '2024-11-05' );
	const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

	/** @var McpToolHandlers */
	private $handlers;

	/** @var string|null auth scope for current request */
	private $auth_scope;

	public function __construct() {
		// Classes are loaded by mt-mcp.php bootstrap.
		$this->handlers = new McpToolHandlers();

		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_handle_early' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_request' ) );
		add_filter( 'redirect_canonical', array( $this, 'disable_canonical_redirect' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Exact MCP endpoint path for this site (e.g. /mt-mcp).
	 * Must not match /.well-known/oauth-protected-resource/mt-mcp.
	 */
	public static function mcp_path() {
		$path = (string) wp_parse_url( home_url( '/' . self::ROUTE_SLUG ), PHP_URL_PATH );
		return untrailingslashit( $path ? $path : '/' . self::ROUTE_SLUG );
	}

	/**
	 * Trailing-slash 301s break Cursor's SSE/streamable HTTP client (non-200).
	 */
	public function disable_canonical_redirect( $redirect, $requested ) {
		if ( get_query_var( 'mt_mcp' ) ) {
			return false;
		}
		$path = untrailingslashit( (string) wp_parse_url( $requested, PHP_URL_PATH ) );
		if ( $path === self::mcp_path() ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Handle /mt-mcp before WP commits redirects / 404.
	 */
	public function maybe_handle_early( $wp ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( $path !== self::mcp_path() ) {
			return;
		}
		if ( ! get_option( self::OPTION_ENABLED ) ) {
			status_header( 404 );
			exit;
		}
		$this->handle_http();
		exit;
	}

	public static function flush_rewrites_now() {
		add_rewrite_rule( '^' . self::ROUTE_SLUG . '/?$', 'index.php?mt_mcp=1', 'top' );
		flush_rewrite_rules();
		update_option( self::OPTION_FLUSHED, '1' );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^' . self::ROUTE_SLUG . '/?$', 'index.php?mt_mcp=1', 'top' );
	}

	public function add_query_var( $vars ) {
		$vars[] = 'mt_mcp';
		return $vars;
	}

	public function maybe_flush_rewrites() {
		if ( get_option( self::OPTION_ENABLED ) ) {
			if ( ! get_option( self::OPTION_FLUSHED ) ) {
				flush_rewrite_rules();
				update_option( self::OPTION_FLUSHED, '1' );
			}
		} elseif ( get_option( self::OPTION_FLUSHED ) ) {
			delete_option( self::OPTION_FLUSHED );
		}
	}

	public function register_rest_route() {
		// Public health check even when MCP is disabled (uptime monitors).
		register_rest_route(
			'mt-mcp/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);

		if ( ! get_option( self::OPTION_ENABLED ) ) {
			return;
		}
		register_rest_route(
			'mt-mcp/v1',
			'/',
			array(
				'methods'             => array( 'GET', 'POST', 'OPTIONS' ),
				'callback'            => array( $this, 'handle_rest_any' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST entry that mirrors rewrite /mt-mcp (GET→auth/SSE probe, POST→JSON-RPC).
	 */
	public function handle_rest_any( \WP_REST_Request $request ) {
		if ( 'OPTIONS' === $request->get_method() ) {
			return new \WP_REST_Response( null, 204 );
		}
		if ( 'GET' === $request->get_method() ) {
			$auth = McpSupport::authenticate( 'read' );
			if ( ! $auth['ok'] ) {
				$response = new \WP_REST_Response(
					array(
						'jsonrpc' => '2.0',
						'id'      => null,
						'error'   => array( 'code' => -32001, 'message' => $auth['error'] ),
					),
					401
				);
				if ( ! empty( $auth['www_authenticate'] ) ) {
					$response->header( 'WWW-Authenticate', $auth['www_authenticate'] );
				}
				return $response;
			}
			// Empty SSE keepalive for authenticated GET.
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			status_header( 200 );
			echo ": mt-mcp connected\n\n";
			exit;
		}
		return $this->handle_rest( $request );
	}

	public function handle_health() {
		return new \WP_REST_Response(
			array(
				'ok'          => true,
				'plugin'      => 'mt-mcp',
				'version'     => MT_MCP_VERSION,
				'enabled'     => (bool) get_option( self::OPTION_ENABLED ),
				'writes'      => (bool) get_option( self::OPTION_ALLOW_WRITE ),
				'environment' => McpSupport::get_environment(),
				'auth_mode'   => McpAuth::get_auth_mode(),
				'time'        => gmdate( 'c' ),
			),
			200
		);
	}

	public function maybe_handle_request() {
		if ( ! get_query_var( 'mt_mcp' ) ) {
			return;
		}
		if ( ! get_option( self::OPTION_ENABLED ) ) {
			status_header( 404 );
			exit;
		}
		$this->handle_http();
		exit;
	}

	public function handle_rest( \WP_REST_Request $request ) {
		$result = $this->dispatch_raw( $request->get_body() );
		$status = isset( $result['_http_status'] ) ? (int) $result['_http_status'] : 200;
		$headers = array();
		if ( ! empty( $result['_www_authenticate'] ) ) {
			$headers['WWW-Authenticate'] = $result['_www_authenticate'];
		}
		unset( $result['_http_status'], $result['_www_authenticate'] );
		if ( isset( $result['_empty'] ) ) {
			return new \WP_REST_Response( null, 202 );
		}
		return new \WP_REST_Response( $result, $status, $headers );
	}

	private function handle_http() {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-WP-Username' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			return;
		}

		// Cursor probes GET (SSE / streamable HTTP). Unauthenticated → 401 so OAuth starts.
		// Authenticated GET opens a short-lived empty SSE stream (no server-push events yet).
		if ( 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			$this->handle_get_sse();
			return;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			header( 'Allow: GET, POST, OPTIONS' );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32600, 'message' => 'Only GET and POST are supported.' ) ) );
			return;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		$raw    = file_get_contents( 'php://input' );
		// Discard any accidental output (notices/warnings) before JSON-RPC body.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ob_start();
		$result = $this->dispatch_raw( $raw );
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$status = isset( $result['_http_status'] ) ? (int) $result['_http_status'] : 200;
		if ( ! empty( $result['_www_authenticate'] ) ) {
			header( 'WWW-Authenticate: ' . $result['_www_authenticate'] );
		}
		unset( $result['_http_status'], $result['_www_authenticate'] );

		if ( isset( $result['_empty'] ) ) {
			status_header( 202 );
			return;
		}

		status_header( $status );
		echo wp_json_encode( $result );
	}

	/**
	 * Streamable-HTTP / SSE GET probe. Must not return 405 — Cursor treats that as hard failure.
	 */
	private function handle_get_sse() {
		$auth = McpSupport::authenticate( 'read' );
		if ( ! $auth['ok'] ) {
			status_header( 401 );
			if ( ! empty( $auth['www_authenticate'] ) ) {
				header( 'WWW-Authenticate: ' . $auth['www_authenticate'] );
			}
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array( 'code' => -32001, 'message' => $auth['error'] ),
				)
			);
			return;
		}

		// Minimal SSE stream (keepalive only). Clients using POST JSON-RPC don't need this.
		status_header( 200 );
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		echo ": mt-mcp connected\n\n";
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}

	/**
	 * @return array JSON-RPC response body (+ optional _http_status / _empty markers)
	 */
	private function dispatch_raw( $raw ) {
		if ( ! McpSupport::check_rate_limit() ) {
			return array(
				'jsonrpc'       => '2.0',
				'id'            => null,
				'error'         => array( 'code' => -32002, 'message' => 'Rate limit exceeded. Try again shortly.' ),
				'_http_status'  => 429,
			);
		}

		$auth = McpSupport::authenticate( 'read' );
		if ( ! $auth['ok'] ) {
			$out = array(
				'jsonrpc'      => '2.0',
				'id'           => null,
				'error'        => array( 'code' => -32001, 'message' => $auth['error'] ),
				'_http_status' => 401,
			);
			if ( ! empty( $auth['www_authenticate'] ) ) {
				$out['_www_authenticate'] = $auth['www_authenticate'];
			}
			return $out;
		}
		$this->auth_scope = $auth['scope'];

		$req = json_decode( $raw, true );
		if ( ! is_array( $req ) || ! isset( $req['jsonrpc'] ) || '2.0' !== $req['jsonrpc'] ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => is_array( $req ) && isset( $req['id'] ) ? $req['id'] : null,
				'error'   => array( 'code' => -32600, 'message' => 'Invalid JSON-RPC 2.0 request.' ),
			);
		}

		$id     = array_key_exists( 'id', $req ) ? $req['id'] : null;
		$method = isset( $req['method'] ) ? $req['method'] : '';
		$params = isset( $req['params'] ) && is_array( $req['params'] ) ? $req['params'] : array();

		if ( ! array_key_exists( 'id', $req ) && 0 === strpos( $method, 'notifications/' ) ) {
			return array( '_empty' => true, '_http_status' => 202 );
		}

		try {
			switch ( $method ) {
				case 'initialize':
					$result = $this->rpc_initialize( $params );
					break;
				case 'tools/list':
					$result = array( 'tools' => $this->tool_definitions() );
					break;
				case 'tools/call':
					$result = $this->rpc_tools_call( $params );
					break;
				case 'resources/list':
					$result = array( 'resources' => $this->resource_definitions() );
					break;
				case 'resources/read':
					$result = $this->rpc_resources_read( $params );
					break;
				case 'prompts/list':
					$result = array( 'prompts' => $this->prompt_definitions() );
					break;
				case 'prompts/get':
					$result = $this->rpc_prompts_get( $params );
					break;
				case 'ping':
					$result = new \stdClass();
					break;
				default:
					return array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'error'   => array( 'code' => -32601, 'message' => "Method not found: $method" ),
					);
			}
		} catch ( \Throwable $e ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array( 'code' => -32603, 'message' => $e->getMessage() ),
			);
		}

		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private function rpc_initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : null;
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::DEFAULT_PROTOCOL_VERSION;

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'     => new \stdClass(),
				'resources' => new \stdClass(),
				'prompts'   => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'mt-mcp',
				'version' => self::SERVER_VERSION,
			),
			'instructions'    => $this->server_instructions(),
		);
	}

	private function server_instructions() {
		$writes = get_option( self::OPTION_ALLOW_WRITE ) ? 'enabled' : 'disabled';
		$brief = McpSupport::site_brief();
		$brief_block = $brief ? "Site Brief (owner rules — do not ignore):\n{$brief}\n\n" : '';
		if ( McpSupport::writes_paused() ) {
			$writes = 'PAUSED';
		}
		$env    = McpSupport::get_environment();
		$env_label = McpSupport::ENVIRONMENTS[ $env ];
		$prod_note = ( 'production' === $env )
			? 'This is PRODUCTION — prefer dry_run, drafts, and small batches; confirm before bulk writes.'
			: "Environment: {$env_label}.";
		$user = wp_get_current_user();
		$user_note = ( $user && $user->ID )
			? "Authenticated as WordPress user **{$user->user_login}** (ID {$user->ID}). Actions respect this user's capabilities."
			: 'Authenticated with a shared key (no user context). Prefer OAuth so edits are attributed to a real user.';
		return <<<TXT
You are connected to an MT WordPress site via mt-mcp. {$prod_note}
{$user_note}

Always start a session with get_site_context, then use list_post_types / list_acf_field_groups / get_acf_schema before editing structured content. Optionally call generate_cursor_skill and save the SKILL.md into the client repo.

Workflow for content:
1. search_posts or list_posts to find targets
2. get_post (optionally with include / only_acf_fields / max_content_chars to stay within context)
3. Prefer dry_run: true on write tools first, then apply without dry_run
4. Mirror ACF flexible-content / repeater shapes exactly from get_post or get_acf_schema

Staging → live page moves: export_post on source (embed_media if target cannot fetch source URLs), then import_post on target with dry_run first (URL rewrite + attachment remap included).

Writes are currently {$writes}. Use get_site_health for install health; list_revisions / restore_revision after bad edits.

{$brief_block}Privacy note: tool results are sent to the model provider (Cursor/Claude/etc.). "No cloud relay" means auth stays on this WordPress site — it does NOT mean content never leaves the machine.

Use prompts (seo_pass, content_inventory, acf_gap_check, launch_prep, session_plan) or copy Recipes from Settings → MT MCP → Recipes.
Use resources site://context, site://sitemap, and acf://schema/{post_type} for quick context pulls.
TXT;
	}

	private function annotate_tool( $tool, $hints ) {
		$tool['annotations'] = $hints;
		return $tool;
	}

	private function tool_definitions() {
		$reads = array(
			$this->annotate_tool(
				array(
					'name'        => 'get_site_context',
					'description' => 'Site overview: environment (production/staging/development), name/URL, WP/PHP versions, theme, active plugins, reading settings, permalinks, ACF/Yoast/Rank Math/SEOPress/redirects, MCP write/tool-group status. Call this first every session.',
					'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				),
				array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false )
			),
			array(
				'name'        => 'flush_caches',
				'description' => 'Allowlisted cache flush (object_cache, rewrites, wp_rocket, litespeed, w3tc, autoptimize, elementor). No shell. Supports dry_run. Requires manage_options.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'targets' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'get_site_health',
				'description' => 'WordPress Site Health snapshot: critical/recommended/good tests, core/plugin/theme update counts, PHP/WP debug flags.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'connection_doctor',
				'description' => 'Diagnose MCP connectivity: HTTPS, .well-known discovery (must be 200 not 301), MCP endpoint, REST health, write gates, WAF hints. Returns paste_for_support (secrets redacted).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			
			array(
				'name'        => 'list_updates',
				'description' => 'List available WordPress core and plugin updates (from→to). Read-only. Confirm ids with the user before start_update_session.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'force_check' => array( 'type' => 'boolean', 'description' => 'Refresh update transients from wordpress.org' ),
					),
				),
			),
			array(
				'name'        => 'start_update_session',
				'description' => 'Start a one-at-a-time update session with approved package ids from list_updates. Requires Allow MCP updates. Optional smoke_urls, form_probe, edit_probe_post_id.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'                 => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'smoke_urls'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'form_probe'          => array( 'type' => 'object' ),
						'edit_probe_post_id'  => array( 'type' => 'integer' ),
						'allow_self_update'   => array( 'type' => 'boolean' ),
						'force_check'         => array( 'type' => 'boolean' ),
						'dry_run'             => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'ids' ),
				),
			),
			array(
				'name'        => 'get_update_session',
				'description' => 'Get update session status/queue/log. Omit session_id to list recent sessions for this user.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'update_package',
				'description' => 'Update one package in a session (plugin zip-snapshotted first). Core needs acknowledge_core_no_zip_rollback=true.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
						'id'         => array( 'type' => 'string' ),
						'force'      => array( 'type' => 'boolean' ),
						'acknowledge_core_no_zip_rollback' => array( 'type' => 'boolean' ),
						'dry_run'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'session_id', 'id' ),
				),
			),
			array(
				'name'        => 'rollback_package',
				'description' => 'Reinstall a plugin from the pre-update zip snapshot. Core cannot be rolled back via MCP.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
						'id'         => array( 'type' => 'string' ),
						'dry_run'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'session_id', 'id' ),
				),
			),
			array(
				'name'        => 'run_smoke_checks',
				'description' => 'After an update: quick URL checks, or full (URLs + optional form_probe + edit/undo).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
						'level'      => array( 'type' => 'string', 'description' => 'quick|full' ),
						'after'      => array( 'type' => 'string', 'description' => 'Package id just updated' ),
					),
					'required'   => array( 'session_id' ),
				),
			),
			array(
				'name'        => 'confirm_update_ok',
				'description' => 'Disarm the dead-man auto-rollback timer after a plugin update (also done automatically when run_smoke_checks passes).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
						'id'         => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'finish_update_session',
				'description' => 'Close session as completed or aborted and return the update report.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string', 'description' => 'completed|aborted' ),
					),
					'required'   => array( 'session_id' ),
				),
			),
			array(
				'name'        => 'unused_media_report',
				'description' => 'Heuristic report of attachments not referenced as featured image or in a sample of post content/ACF. Review before delete.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'     => array( 'type' => 'integer' ),
						'mime_type' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'list_theme_templates',
				'description' => 'List theme page templates (file => label) for page and hierarchical CPTs.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_type' => array( 'type' => 'string' ) ),
				),
			),
			array(
				'name'        => 'plugin_capability_matrix',
				'description' => 'Which SEO/redirect/ACF/media tool packs are unlocked (plugin active ∩ tool group enabled).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'diff_public_html',
				'description' => 'Fetch the public (or preview) HTML for a post and verify title/h1/meta/expect_contains after edits.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'url'             => array( 'type' => 'string' ),
						'preview_url'     => array( 'type' => 'string' ),
						'expect_contains' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'check_links',
				'description' => 'Extract and HTTP-check links from a post or capped post_type sample (max 50 URLs).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'url'        => array( 'type' => 'string' ),
						'post_type'  => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'limit'      => array( 'type' => 'integer' ),
						'max_links'  => array( 'type' => 'integer' ),
					),
				),
			),
			
			array(
				'name'        => 'list_users',
				'description' => 'List users for author assignment: id, login, display_name, roles. Emails only if include_email=true.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'          => array( 'type' => 'integer' ),
						'role'           => array( 'type' => 'string' ),
						'search'         => array( 'type' => 'string' ),
						'include_email'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'bulk_update_posts',
				'description' => 'Bulk set post_status / post_author / post_parent on up to 50 ids. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'          => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'post_status'  => array( 'type' => 'string' ),
						'post_author'  => array( 'type' => 'integer' ),
						'post_parent'  => array( 'type' => 'integer' ),
						'dry_run'      => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'ids' ),
				),
			),
			array(
				'name'        => 'replace_attachment_file',
				'description' => 'Replace an attachment’s file bytes while keeping the same ID (ACF image fields stay valid). data_base64 or source_url (SSRF-safe).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'data_base64'  => array( 'type' => 'string' ),
						'source_url'   => array( 'type' => 'string' ),
						'filename'     => array( 'type' => 'string' ),
						'alt'          => array( 'type' => 'string' ),
						'dry_run'      => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'list_needs_review',
				'description' => 'List posts marked _mt_mcp_needs_review (force-draft inbox).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'limit' => array( 'type' => 'integer' ) ),
				),
			),
			array(
				'name'        => 'acf_media_audit',
				'description' => 'Scan ACF image/gallery/file fields for missing attachments, missing files, and missing alts.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'generate_cursor_skill',
				'description' => 'Generate a site-specific Cursor SKILL.md from get_site_context + ACF schemas + menus. Save the returned skill_markdown to suggested_path in the client repo.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Post types to include ACF schemas for. Defaults to ["page"].' ),
					),
				),
			),
			array(
				'name'        => 'list_revisions',
				'description' => 'List revisions for a post (id or url).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'get_revision',
				'description' => 'Get full title/content/excerpt for one revision.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id' => array( 'type' => 'integer' ),
						'id'          => array( 'type' => 'integer', 'description' => 'Alias for revision_id.' ),
					),
				),
			),
			array(
				'name'        => 'export_post',
				'description' => 'Export a post package (core, taxonomies, Yoast, ACF, referenced media) for import on another mt-mcp site. Set embed_media:true to include base64 when the target cannot download from this host.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'include_media' => array( 'type' => 'boolean' ),
						'embed_media'   => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'list_post_types',
				'description' => 'List public post types with labels and hierarchical flags.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'inventory_post_types',
				'description' => 'Deep CPT inventory: rewrite, archive, supports, taxonomies, status counts. Optional include_private.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'include_private' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'permalink_clash_report',
				'description' => 'Find duplicate post_name slugs across posts/CPTs that can break permalinks or imports.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'list_posts',
				'description' => 'List posts of one post type (lightweight summaries). Use get_post for full detail.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'description' => 'Defaults to publish. Use any for all.' ),
						'search'    => array( 'type' => 'string' ),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer', 'description' => 'Max 100.' ),
					),
					'required'   => array( 'post_type' ),
				),
			),
			array(
				'name'        => 'search_posts',
				'description' => 'Cross-type search with filters: search text, post_type, status, taxonomy/term, modified_after, missing_yoast.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'          => array( 'type' => 'string' ),
						'post_type'       => array( 'description' => 'Slug, array of slugs, or omit for any.' ),
						'status'          => array( 'type' => 'string' ),
						'taxonomy'        => array( 'type' => 'string' ),
						'term'            => array( 'description' => 'Term slug or id.' ),
						'modified_after'  => array( 'type' => 'string', 'description' => 'strtotime-compatible date.' ),
						'missing_yoast'   => array( 'type' => 'boolean' ),
						'acf_field'       => array( 'type' => 'string', 'description' => 'ACF/meta key to filter' ),
						'acf_value'       => array( 'description' => 'Value to match for acf_field' ),
						'acf_compare'     => array( 'type' => 'string' ),
						'acf_empty'       => array( 'type' => 'boolean', 'description' => 'NOT EXISTS for acf_field' ),
						'missing_required_acf' => array( 'type' => 'boolean' ),
						'page'            => array( 'type' => 'integer' ),
						'per_page'        => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'get_post',
				'description' => 'Full post detail: core, taxonomies, featured image, Yoast, ACF. Identify by id or url. Use include, only_acf_fields, exclude_acf_fields, max_content_chars to control payload size.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                 => array( 'type' => 'integer' ),
						'url'                => array( 'type' => 'string' ),
						'include'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of: core, taxonomies, featured_image, yoast, acf, raw_meta.' ),
						'only_acf_fields'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'exclude_acf_fields' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'max_content_chars'  => array( 'type' => 'integer' ),
						'include_raw_meta'   => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'list_taxonomies',
				'description' => 'List public taxonomies and which post types they apply to.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'get_terms',
				'description' => 'List terms in a taxonomy.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'taxonomy' => array( 'type' => 'string' ) ),
					'required'   => array( 'taxonomy' ),
				),
			),
			array(
				'name'        => 'list_acf_field_groups',
				'description' => 'List ACF field groups, optionally filtered by post_type.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_type' => array( 'type' => 'string' ) ),
				),
			),
			array(
				'name'        => 'get_acf_schema',
				'description' => 'ACF field schemas (keys, types, layouts/sub_fields) for a post_type, post_id, options_page, or group_key. Use before writing flexible content.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'    => array( 'type' => 'string' ),
						'post_id'      => array( 'type' => 'integer' ),
						'options_page' => array( 'type' => 'string' ),
						'group_key'    => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'get_options_page',
				'description' => 'Read ACF options-page field values. page defaults to "option".',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'page' => array( 'type' => 'string' ) ),
				),
			),
			array(
				'name'        => 'get_seo_social',
				'description' => 'Read OG/Twitter/canonical/primary category/breadcrumb gaps for one post (Yoast fields when present).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'seo_social_audit',
				'description' => 'Scan a post type for missing OG/Twitter/canonical/primary-category social SEO gaps. Capped.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'seo_audit',
				'description' => 'Scan a post type for Yoast issues: missing/long title & metadesc, missing focuskw, noindex. Returns summary + per-post issues.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'content_review',
				'description' => 'Structured content checklist: empty content, missing excerpt/featured/Yoast, draft status, optional empty ACF fields (check_acf: true).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'check_acf' => array( 'type' => 'boolean' ),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'list_media',
				'description' => 'Browse the media library.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'get_media',
				'description' => 'Get one attachment (title, alt, caption, url, metadata).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'media_alt_report',
				'description' => 'Report attachments missing alt text: counts + ids + sample items. Feed ids into bulk_fill_media_alt / update_media.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'     => array( 'type' => 'integer' ),
						'mime_type' => array( 'type' => 'string', 'description' => 'Default image' ),
					),
				),
			),
			array(
				'name'        => 'list_menus',
				'description' => 'List nav menus and theme locations.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'get_menu',
				'description' => 'Get menu items by id, slug, or location.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'slug'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'list_acf_options_pages',
				'description' => 'List ACF options pages (slug/post_id/title + attached field group keys). Use before get_options_page / update_options_page.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'describe_post_type',
				'description' => 'Deep inventory for one post_type: supports, counts by status, taxonomies, ACF groups + location, page templates.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_type' => array( 'type' => 'string' ) ),
					'required'   => array( 'post_type' ),
				),
			),
			array(
				'name'        => 'preflight_publish',
				'description' => 'Pre-publish checklist: title, featured+alt, required ACF, SEO title/metadesc, schema.org sample. Returns ready/blockers/warnings.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'post_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'get_options',
				'description' => 'Read allowlisted site/utility options. Omit keys to return all allowed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
			),
			array(
				'name'        => 'list_redirects',
				'description' => 'List redirects from Redirection plugin or Yoast Premium if present.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'get_audit_log',
				'description' => 'Recent MCP tool calls (HMAC-signed ring buffer). Useful after a session to review what an agent changed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'limit' => array( 'type' => 'integer' ) ),
				),
			),
			array(
				'name'        => 'export_audit_log',
				'description' => 'Export audit log as CSV string plus structured entries (includes signature validity).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'limit' => array( 'type' => 'integer' ) ),
				),
			),
			array(
				'name'        => 'suggest_internal_links',
				'description' => 'Suggest related published posts/pages as internal link targets for a post (id or url).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'validate_schema_ld',
				'description' => 'Fetch the public URL and extract/validate application/ld+json blocks.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'sitemap_diff',
				'description' => 'HEAD-check published public URLs for 4xx / unexpected redirects. Returns capped issue list.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'export_posts',
				'description' => 'Export multiple posts as packages (ids[] or post_type + limit).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'post_type'     => array( 'type' => 'string' ),
						'status'        => array( 'type' => 'string' ),
						'limit'         => array( 'type' => 'integer' ),
						'include_media' => array( 'type' => 'boolean' ),
						'embed_media'   => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'check_import_conflicts',
				'description' => 'Compare an export package slug against this site; report if target content diverged.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'package' => array( 'type' => 'object' ) ),
					'required'   => array( 'package' ),
				),
			),
		);

		$writes = array(
			array(
				'name'        => 'create_post',
				'description' => 'Create a post/page. Supports core fields, taxonomies, yoast, acf, featured_image_id, and dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'          => array( 'type' => 'string' ),
						'post_title'         => array( 'type' => 'string' ),
						'post_content'       => array( 'type' => 'string' ),
						'post_excerpt'       => array( 'type' => 'string' ),
						'post_status'        => array( 'type' => 'string' ),
						'post_name'          => array( 'type' => 'string' ),
						'core'               => array( 'type' => 'object' ),
						'taxonomies'         => array( 'type' => 'object' ),
						'yoast'              => array( 'type' => 'object' ),
						'acf'                => array( 'type' => 'object' ),
						'featured_image_id'  => array( 'type' => 'integer' ),
						'dry_run'            => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'update_post',
				'description' => 'Update a post by id/url. Buckets optional: core, taxonomies, yoast, acf, meta, featured_image_id. Supports dry_run; returns diff of changed fields.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer' ),
						'url'               => array( 'type' => 'string' ),
						'core'              => array( 'type' => 'object' ),
						'taxonomies'        => array( 'type' => 'object' ),
						'yoast'             => array( 'type' => 'object' ),
						'acf'               => array( 'type' => 'object' ),
						'meta'              => array( 'type' => 'object' ),
						'featured_image_id' => array( 'type' => 'integer' ),
						'dry_run'           => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'trash_post',
				'description' => 'Trash a post (or force_delete permanently). Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'url'          => array( 'type' => 'string' ),
						'force_delete' => array( 'type' => 'boolean' ),
						'dry_run'      => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'bulk_update_yoast',
				'description' => 'Batch-update Yoast fields. items: [{id|url, title?, description?, focuskw?, yoast?{...}}]. Max 50. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'items'   => array( 'type' => 'array' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'items' ),
				),
			),
			array(
				'name'        => 'bulk_update_seo',
				'description' => 'Batch-update SEO fields for Yoast / Rank Math / SEOPress. provider optional (auto-detect). items:[{id|url, title?, metadesc?, focuskw?, seo?{...}}]. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'provider' => array( 'type' => 'string' ),
						'items'    => array( 'type' => 'array' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'items' ),
				),
			),
			array(
				'name'        => 'bulk_fix_media_alt',
				'description' => 'Fill missing attachment alt text from a template ({title}, {filename}). Opt-in vision hints: generate alts externally then update_media. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'          => array( 'type' => 'integer' ),
						'hint_template'  => array( 'type' => 'string' ),
						'dry_run'        => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'schedule_post',
				'description' => 'Schedule a post (future publish) or set draft/private with a datetime. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
						'when'    => array( 'type' => 'string' ),
						'action'  => array( 'type' => 'string', 'description' => 'publish|draft|private' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'import_posts',
				'description' => 'Import multiple export packages. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'packages' => array( 'type' => 'array' ),
						'dry_run'  => array( 'type' => 'boolean' ),
						'mode'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'packages' ),
				),
			),
			array(
				'name'        => 'dedupe_attachments',
				'description' => 'Find attachments sharing _mt_mcp_sha256 and optionally delete duplicates (keeps lowest ID). Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'dry_run' => array( 'type' => 'boolean' ) ),
				),
			),
			array(
				'name'        => 'notify_write_batch',
				'description' => 'POST a write-batch summary to the configured webhook URL. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'summary'  => array( 'type' => 'string' ),
						'tools'    => array( 'type' => 'array' ),
						'post_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'update_media',
				'description' => 'Update attachment title, caption, description, alt. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'alt'         => array( 'type' => 'string' ),
						'dry_run'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'find_media_by_hash',
				'description' => 'Find an existing attachment by SHA-256 (or hash of data_base64 / source_url). Use before upload_media to avoid duplicates.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sha256'       => array( 'type' => 'string' ),
						'data_base64'  => array( 'type' => 'string' ),
						'source_url'   => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'upload_media',
				'description' => 'Upload a new attachment from url or data_base64 (+ filename). Reuses existing attachment when SHA-256 matches (_mt_mcp_sha256) unless force_new. URL sideloads: https only, no redirects, refuse private IPs, require alt. Optional title, caption, description, parent_post_id. Supports dry_run. Max 15MB.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'             => array( 'type' => 'string' ),
						'data_base64'     => array( 'type' => 'string' ),
						'filename'        => array( 'type' => 'string' ),
						'title'           => array( 'type' => 'string' ),
						'alt'             => array( 'type' => 'string' ),
						'caption'         => array( 'type' => 'string' ),
						'description'     => array( 'type' => 'string' ),
						'parent_post_id'  => array( 'type' => 'integer' ),
						'force_new'       => array( 'type' => 'boolean' ),
						'dry_run'         => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'import_post',
				'description' => 'Import an export_post package onto this site. Rewrites source→target URLs, uploads/reuses media, remaps attachment IDs in content/ACF. mode: update (match slug) or create. Supports dry_run, target_status, target_id, skip_media, source_url, target_url.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'package'       => array( 'type' => 'object' ),
						'mode'          => array( 'type' => 'string', 'description' => 'update|create' ),
						'target_id'     => array( 'type' => 'integer' ),
						'target_status' => array( 'type' => 'string' ),
						'source_url'    => array( 'type' => 'string' ),
						'target_url'    => array( 'type' => 'string' ),
						'skip_media'    => array( 'type' => 'boolean' ),
						'dry_run'       => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'package' ),
				),
			),
			array(
				'name'        => 'restore_revision',
				'description' => 'Restore a post to a revision_id. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'revision_id' => array( 'type' => 'integer' ),
						'id'          => array( 'type' => 'integer' ),
						'dry_run'     => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'update_options',
				'description' => 'Update allowlisted site/utility options only. Call get_options first. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'options' => array( 'type' => 'object' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'options' ),
				),
			),
			array(
				'name'        => 'update_options_page',
				'description' => 'Update ACF options-page fields. Supports dry_run; returns diff.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'page'    => array( 'type' => 'string' ),
						'fields'  => array( 'type' => 'object' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'fields' ),
				),
			),

			array(
				'name'        => 'review_draft',
				'description' => 'Resolve a needs-review draft: action publish|discard|keep. Supports dry_run. Pass operation_id for idempotent writes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'post_id'      => array( 'type' => 'integer' ),
						'action'       => array( 'type' => 'string' ),
						'dry_run'      => array( 'type' => 'boolean' ),
						'operation_id' => array( 'type' => 'string' ),
					),
					'required'   => array( 'action' ),
				),
			),
			array(
				'name'        => 'manage_connections',
				'description' => 'Named staging↔prod MCP connections/presets. action: list|upsert|delete. upsert needs name+url (+ environment, mcp_url, label, notes).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'action'      => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'mcp_url'     => array( 'type' => 'string' ),
						'environment' => array( 'type' => 'string' ),
						'notes'       => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'create_draft_preview',
				'description' => 'Create a signed public draft preview URL (no WP login). Optional ttl seconds. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'post_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
						'ttl'     => array( 'type' => 'integer' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'revoke_draft_preview',
				'description' => 'Revoke a draft preview token.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'token' => array( 'type' => 'string' ) ),
					'required'   => array( 'token' ),
				),
			),
			array(
				'name'        => 'prepare_media_upload',
				'description' => 'Create a one-shot upload URL for large/Figma assets (avoids base64). Requires alt. POST multipart file to returned url.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'alt'            => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'filename'       => array( 'type' => 'string' ),
						'parent_post_id' => array( 'type' => 'integer' ),
						'max_bytes'      => array( 'type' => 'integer' ),
						'ttl'            => array( 'type' => 'integer' ),
						'dry_run'        => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'alt' ),
				),
			),
			array(
				'name'        => 'import_redirects',
				'description' => 'Bulk import redirects from items[] or csv. Returns state_token preview; re-call with state_token/apply or use confirm_dangerous_op.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'items'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'csv'         => array( 'type' => 'string' ),
						'dry_run'     => array( 'type' => 'boolean' ),
						'apply'       => array( 'type' => 'boolean' ),
						'state_token' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'list_changes',
				'description' => 'List recent MCP write changes with undo tokens (72h). Does not include OAuth/MCP credential mutations.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'get_change',
				'description' => 'Inspect a change: before / written / now snapshots and drift flag.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token' => array( 'type' => 'string' ),
					),
					'required'   => array( 'token' ),
				),
			),
			array(
				'name'        => 'undo_change',
				'description' => 'Restore the before-state for an undo token. Refuses on drift unless force=true. Never touches OAuth tokens or MCP plugin options.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token'   => array( 'type' => 'string' ),
						'force'   => array( 'type' => 'boolean' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'token' ),
				),
			),
			array(
				'name'        => 'set_write_gates',
				'description' => 'Set pause_writes (incident freeze), force_draft (new posts forced to draft + needs_review), and/or site_brief owner rules.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'pause_writes' => array( 'type' => 'boolean' ),
						'force_draft'  => array( 'type' => 'boolean' ),
						'site_brief'   => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'confirm_dangerous_op',
				'description' => 'Apply a two-phase dangerous operation using state_token from a prior preview (force_delete_post, import_redirects).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'   => array( 'type' => 'string' ),
						'state_token' => array( 'type' => 'string' ),
					),
					'required'   => array( 'operation', 'state_token' ),
				),
			),
			array(
				'name'        => 'create_menu',
				'description' => 'Create a nav menu. Optional location assignment. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'name' ),
				),
			),
			array(
				'name'        => 'update_menu',
				'description' => 'Rename a menu and/or assign theme locations (location or locations[]). clear_locations removes this menu from all locations first. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'               => array( 'type' => 'integer' ),
						'slug'             => array( 'type' => 'string' ),
						'location'         => array( 'type' => 'string' ),
						'name'             => array( 'type' => 'string' ),
						'locations'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'clear_locations'  => array( 'type' => 'boolean' ),
						'dry_run'          => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'delete_menu',
				'description' => 'Delete a nav menu by id/slug/location. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'slug'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'update_menu_items',
				'description' => 'Add/update/delete menu items. items:[{action:add|update|delete, id?, title, url, type, object, object_id, parent, order, target, classes}]. Validates links. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'slug'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
						'items'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'items' ),
				),
			),
			array(
				'name'        => 'create_term',
				'description' => 'Create a taxonomy term. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
						'description' => array( 'type' => 'string' ),
						'dry_run'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'taxonomy', 'name' ),
				),
			),
			array(
				'name'        => 'update_term',
				'description' => 'Update a term by id. Optional taxonomy. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'term_id'     => array( 'type' => 'integer' ),
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
						'description' => array( 'type' => 'string' ),
						'dry_run'     => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'delete_term',
				'description' => 'Delete a term by id. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'term_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'duplicate_post',
				'description' => 'Clone a post as draft (default): core + taxonomies + ACF + featured. Optional include_meta. Supports dry_run. Accepts id/post_id/url.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                  => array( 'type' => 'integer' ),
						'post_id'             => array( 'type' => 'integer' ),
						'url'                 => array( 'type' => 'string' ),
						'post_title'          => array( 'type' => 'string' ),
						'post_status'         => array( 'type' => 'string' ),
						'include_acf'         => array( 'type' => 'boolean' ),
						'include_taxonomies'  => array( 'type' => 'boolean' ),
						'include_featured'    => array( 'type' => 'boolean' ),
						'include_meta'        => array( 'type' => 'boolean' ),
						'dry_run'             => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'find_replace',
				'description' => 'Surgical find/replace across content/excerpt/title/acf/meta for id or ids[]. Defaults to dry_run preview; set apply:true to write. Supports expected_count guard and nearest snippets.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'find'           => array( 'type' => 'string' ),
						'replace'        => array( 'type' => 'string' ),
						'id'             => array( 'type' => 'integer' ),
						'ids'            => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'url'            => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'scopes'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'expected_count' => array( 'type' => 'integer' ),
						'apply'          => array( 'type' => 'boolean' ),
						'dry_run'        => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'find', 'replace' ),
				),
			),
			array(
				'name'        => 'create_redirect',
				'description' => 'Create a redirect (Redirection or Yoast Premium). from/to required; code defaults to 301. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'from'     => array( 'type' => 'string' ),
						'to'       => array( 'type' => 'string' ),
						'code'     => array( 'type' => 'integer' ),
						'group_id' => array( 'type' => 'integer' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'update_redirect',
				'description' => 'Update a Yoast Premium redirect by from path. Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'from'     => array( 'type' => 'string' ),
						'to'       => array( 'type' => 'string' ),
						'code'     => array( 'type' => 'integer' ),
						'new_from' => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'delete_redirect',
				'description' => 'Delete a redirect: from (Yoast) or id (Redirection). Supports dry_run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'from'    => array( 'type' => 'string' ),
						'id'      => array( 'type' => 'integer' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
			),
		);

		$tools = array();
		foreach ( $reads as $tool ) {
			if ( McpSupport::tool_allowed( $tool['name'] ) ) {
				$tools[] = $this->with_default_annotations( $tool, false );
			}
		}
		if ( get_option( self::OPTION_ALLOW_WRITE ) ) {
			foreach ( $writes as $tool ) {
				if ( McpSupport::tool_allowed( $tool['name'] ) ) {
					$tools[] = $this->with_default_annotations( $tool, true );
				}
			}
		}

		return $tools;
	}

	private function with_default_annotations( $tool, $is_write ) {
		if ( empty( $tool['annotations'] ) ) {
			$destructive = in_array( $tool['name'], array( 'trash_post', 'delete_redirect', 'dedupe_attachments', 'delete_menu', 'delete_term', 'confirm_dangerous_op' ), true );
			$tool['annotations'] = array(
				'readOnlyHint'    => ! $is_write,
				'destructiveHint' => $destructive,
				'idempotentHint'  => ! $is_write,
				'openWorldHint'   => in_array( $tool['name'], array( 'upload_media', 'sitemap_diff', 'validate_schema_ld', 'notify_write_batch', 'import_post', 'import_posts' ), true ),
			);
		}
		return $tool;
	}

	private function rpc_tools_call( $params ) {
		$name = isset( $params['name'] ) ? $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$args = McpSupport::normalize_arg_aliases( $args );

		if ( ! $name ) {
			return $this->tool_error( 'Missing tool name.' );
		}

		// Three-gate dispatch: (1) tool-group allowlist (2) read/write ceiling (3) current_user_can.
		// Outer gate: server inert until Enable MCP (see handle_request / REST register).
		if ( ! McpSupport::tool_allowed( $name ) ) {
			return $this->tool_error( "Tool group for \"$name\" is disabled in Settings > MT MCP." );
		}

		if ( McpSupport::is_write_tool( $name ) ) {
			if ( ! get_option( self::OPTION_ALLOW_WRITE ) ) {
				return $this->tool_error( 'Writes are disabled. Enable "Allow MCP writes" in Settings > MT MCP.' );
			}
			if ( McpSupport::writes_paused() && 'set_write_gates' !== $name ) {
				return $this->tool_error( 'Writes are paused on this site (incident freeze). An admin can clear pause via set_write_gates or Settings > MT MCP.' );
			}
			$auth = McpSupport::authenticate( 'write' );
			if ( ! $auth['ok'] ) {
				return $this->tool_error( $auth['error'] );
			}
		}

		$cap = McpAuth::user_can_run_tool( $name );
		if ( is_wp_error( $cap ) ) {
			return $this->tool_error( $cap->get_error_message() );
		}

		$method = $this->handler_method( $name );
		if ( ! $method ) {
			return $this->tool_error( "Unknown tool: $name" );
		}

		// Idempotent write receipts: reconnects with the same operation_id replay the stored result.
		$operation_id = isset( $args['operation_id'] ) ? sanitize_text_field( (string) $args['operation_id'] ) : '';
		if ( $operation_id && McpSupport::is_write_tool( $name ) && empty( $args['dry_run'] ) ) {
			$existing = McpSupport::get_op_receipt( $operation_id );
			if ( is_array( $existing ) && isset( $existing['result'] ) ) {
				$replay = $existing['result'];
				if ( is_array( $replay ) ) {
					$replay['operation_id'] = $operation_id;
					$replay['receipt_replay'] = true;
				}
				McpSupport::audit( $name, McpSupport::summarize_args( $args ), true, 'receipt_replay' );
				$structured = $this->as_structured_object( $replay );
				return array(
					'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $structured ) ) ),
					'structuredContent' => $structured,
					'isError'           => false,
				);
			}
		}

		$undo_before = null;
		if ( McpSupport::is_undoable_tool( $name ) && empty( $args['dry_run'] ) ) {
			try {
				$undo_before = McpSupport::capture_undo_before( $name, $args );
			} catch ( \Throwable $e ) {
				$undo_before = null;
			}
		}

		try {
			$data = call_user_func( array( $this->handlers, $method ), $args );
		} catch ( \Throwable $e ) {
			McpSupport::audit( $name, McpSupport::summarize_args( $args ), false, $e->getMessage() );
			return $this->tool_error( $e->getMessage() );
		}

		if ( $undo_before && is_array( $data ) ) {
			$undo = McpSupport::record_change( $name, $undo_before, $data );
			if ( $undo ) {
				$data['undo'] = $undo;
			}
		}

		if ( $operation_id && McpSupport::is_write_tool( $name ) && empty( $args['dry_run'] ) && is_array( $data ) ) {
			$data['operation_id'] = $operation_id;
			McpSupport::store_op_receipt( $operation_id, $name, $data );
		}

		McpSupport::audit( $name, McpSupport::summarize_args( $args ), true, McpSupport::is_write_tool( $name ) ? 'write' : 'read' );

		// Cursor (and MCP clients) require structuredContent to be a JSON object, not an array.
		$structured = $this->as_structured_object( $data );
		$json       = wp_json_encode( $structured );
		return array(
			'content'           => array( array( 'type' => 'text', 'text' => $json ) ),
			'structuredContent' => $structured,
			'isError'           => false,
		);
	}

	/**
	 * Coerce tool payloads into a JSON object for structuredContent.
	 *
	 * @param mixed $data Tool return value.
	 * @return array|\stdClass
	 */
	private function as_structured_object( $data ) {
		if ( $data instanceof \stdClass ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return array( 'value' => $data );
		}
		if ( empty( $data ) ) {
			return new \stdClass();
		}
		// Sequential list → wrap; associative object → keep.
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return array( 'items' => $data );
		}
		return $data;
	}

	private function handler_method( $name ) {
		$map = array(
			'get_site_context'      => 'get_site_context',
			'list_post_types'       => 'list_post_types',
			'inventory_post_types'  => 'inventory_post_types',
			'permalink_clash_report'=> 'permalink_clash_report',
			'list_posts'            => 'list_posts',
			'search_posts'          => 'search_posts',
			'get_post'              => 'get_post',
			'create_post'           => 'create_post',
			'update_post'           => 'update_post',
			'trash_post'            => 'trash_post',
			'list_revisions'        => 'list_revisions',
			'get_revision'          => 'get_revision',
			'restore_revision'      => 'restore_revision',
			'list_taxonomies'       => 'list_taxonomies',
			'get_terms'             => 'get_terms',
			'create_term'           => 'create_term',
			'update_term'           => 'update_term',
			'delete_term'           => 'delete_term',
			'describe_post_type'    => 'describe_post_type',
			'duplicate_post'        => 'duplicate_post',
			'find_replace'          => 'find_replace',
			'create_draft_preview'  => 'create_draft_preview',
			'revoke_draft_preview'  => 'revoke_draft_preview',
			'prepare_media_upload'  => 'prepare_media_upload',
			'import_redirects'      => 'import_redirects',
			'acf_media_audit'       => 'acf_media_audit',
			'set_write_gates'       => 'set_write_gates',
			'confirm_dangerous_op'  => 'confirm_dangerous_op',
			'connection_doctor'     => 'connection_doctor',
			'manage_connections'    => 'manage_connections',
			'list_needs_review'     => 'list_needs_review',
			'review_draft'          => 'review_draft',
			'list_changes'          => 'list_changes',
			'get_change'            => 'get_change',
			'undo_change'           => 'undo_change',
			'unused_media_report'   => 'unused_media_report',
			'list_theme_templates'  => 'list_theme_templates',
			'plugin_capability_matrix' => 'plugin_capability_matrix',
			'diff_public_html'      => 'diff_public_html',
			'check_links'           => 'check_links',
			'replace_attachment_file' => 'replace_attachment_file',
			'list_users'             => 'list_users',
			'bulk_update_posts'      => 'bulk_update_posts',
			'list_updates'           => 'list_updates',
			'start_update_session'   => 'start_update_session',
			'get_update_session'     => 'get_update_session',
			'update_package'         => 'update_package',
			'rollback_package'       => 'rollback_package',
			'run_smoke_checks'       => 'run_smoke_checks',
			'confirm_update_ok'      => 'confirm_update_ok',
			'finish_update_session'  => 'finish_update_session',
			'preflight_publish'     => 'preflight_publish',
			'list_acf_field_groups' => 'list_acf_field_groups',
			'list_acf_options_pages'=> 'list_acf_options_pages',
			'get_acf_schema'        => 'get_acf_schema',
			'get_options_page'      => 'get_options_page',
			'update_options_page'   => 'update_options_page',
			'bulk_update_yoast'     => 'bulk_update_yoast',
			'seo_audit'             => 'seo_audit',
			'get_seo_social'        => 'get_seo_social',
			'seo_social_audit'      => 'seo_social_audit',
			'list_media'            => 'list_media',
			'get_media'             => 'get_media',
			'update_media'          => 'update_media',
			'find_media_by_hash'     => 'find_media_by_hash',
			'upload_media'          => 'upload_media',
			'list_menus'            => 'list_menus',
			'get_menu'              => 'get_menu',
			'create_menu'           => 'create_menu',
			'update_menu'           => 'update_menu',
			'delete_menu'           => 'delete_menu',
			'update_menu_items'     => 'update_menu_items',
			'get_options'           => 'get_options',
			'update_options'        => 'update_options',
			'list_redirects'        => 'list_redirects',
			'create_redirect'       => 'create_redirect',
			'update_redirect'       => 'update_redirect',
			'delete_redirect'       => 'delete_redirect',
			'content_review'        => 'content_review',
			'get_audit_log'          => 'get_audit_log',
			'export_audit_log'       => 'export_audit_log',
			'export_post'            => 'export_post',
			'export_posts'           => 'export_posts',
			'import_post'            => 'import_post',
			'import_posts'           => 'import_posts',
			'check_import_conflicts' => 'check_import_conflicts',
			'dedupe_attachments'     => 'dedupe_attachments',
			'bulk_update_seo'        => 'bulk_update_seo',
			'suggest_internal_links' => 'suggest_internal_links',
			'validate_schema_ld'     => 'validate_schema_ld',
			'sitemap_diff'           => 'sitemap_diff',
			'bulk_fix_media_alt'  => 'bulk_fix_media_alt',
			'schedule_post'          => 'schedule_post',
			'notify_write_batch'     => 'notify_write_batch',
			'flush_caches'           => 'flush_caches',
			'get_site_health'        => 'get_site_health',
			'generate_cursor_skill'  => 'generate_cursor_skill',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : null;
	}

	private function tool_error( $message ) {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => $message ) ),
			'isError' => true,
		);
	}

	private function resource_definitions() {
		$resources = array(
			array(
				'uri'         => 'site://context',
				'name'        => 'Site context',
				'description' => 'Same payload as get_site_context.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'site://sitemap',
				'name'        => 'Published URL inventory',
				'description' => 'Lightweight list of published public posts/pages (id, type, title, url).',
				'mimeType'    => 'application/json',
			),
		);

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$resources[] = array(
				'uri'         => 'acf://schema/' . $type,
				'name'        => 'ACF schema: ' . $type,
				'description' => 'ACF field schemas for post type ' . $type,
				'mimeType'    => 'application/json',
			);
		}

		return $resources;
	}

	private function rpc_resources_read( $params ) {
		$uri = isset( $params['uri'] ) ? $params['uri'] : '';
		if ( 'site://context' === $uri ) {
			$text = wp_json_encode( $this->handlers->get_site_context() );
		} elseif ( 'site://sitemap' === $uri ) {
			$text = wp_json_encode( $this->build_sitemap_resource() );
		} elseif ( 0 === strpos( $uri, 'acf://schema/' ) ) {
			$type = sanitize_key( substr( $uri, strlen( 'acf://schema/' ) ) );
			$text = wp_json_encode( $this->handlers->get_acf_schema( array( 'post_type' => $type ) ) );
		} else {
			throw new \Exception( 'Unknown resource URI: ' . $uri );
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => $text,
				),
			),
		);
	}

	private function build_sitemap_resource() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$query = new \WP_Query(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'        => $post->ID,
				'post_type' => $post->post_type,
				'title'     => get_the_title( $post ),
				'url'       => get_permalink( $post ),
			);
		}
		return array(
			'total' => (int) $query->found_posts,
			'returned' => count( $items ),
			'truncated' => $query->found_posts > count( $items ),
			'items' => $items,
		);
	}

	private function prompt_definitions() {
		$list = McpRecipes::as_mcp_prompt_list();
		return $list ? $list : array();
	}

	private function rpc_prompts_get( $params ) {
		$name = isset( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$post_type = isset( $arguments['post_type'] ) ? $arguments['post_type'] : 'page';
		$goal      = isset( $arguments['goal'] ) ? $arguments['goal'] : '[describe what you want done]';

		$id_map = array(
			'seo_pass'          => 'seo-pass',
			'content_inventory' => 'content-inventory',
			'acf_gap_check'     => 'acf-gap-check',
			'launch_prep'       => 'launch-prep',
			'session_plan'      => 'session-plan',
		);
		if ( ! isset( $id_map[ $name ] ) ) {
			throw new \Exception( 'Unknown prompt: ' . $name );
		}
		$recipe = McpRecipes::get( $id_map[ $name ] );
		if ( ! $recipe ) {
			throw new \Exception( 'Unknown prompt: ' . $name );
		}
		$text = isset( $recipe['prompt'] ) ? $recipe['prompt'] : '';
		$text = str_replace(
			array( 'post_type="[page or CPT]"', 'post_type="[page or post]"', 'post_type="[page]"', 'post_type="[CPT]"', '[describe what you want done]' ),
			array( 'post_type="' . $post_type . '"', 'post_type="' . $post_type . '"', 'post_type="' . $post_type . '"', 'post_type="' . $post_type . '"', $goal ),
			$text
		);

		return array(
			'description' => isset( $recipe['summary'] ) ? $recipe['summary'] : $name,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
