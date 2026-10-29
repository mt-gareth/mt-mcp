<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in OAuth 2.1 Authorization Server for MT MCP.
 *
 * Endpoints:
 *   GET  /.well-known/oauth-protected-resource
 *   GET  /.well-known/oauth-authorization-server
 *   GET  /mt-mcp/oauth/protected-resource   (alternate when hosts block /.well-known/)
 *   GET  /mt-mcp/oauth/authorization-server
 *   GET  /wp-json/mt-mcp/v1/oauth/*         (REST alternate)
 *   POST /mt-mcp/oauth/register          (RFC 7591 dynamic client registration)
 *   GET  /mt-mcp/oauth/authorize         (authorization code + PKCE)
 *   POST /mt-mcp/oauth/authorize         (consent submit)
 *   POST /mt-mcp/oauth/token             (code / refresh exchange)
 *   POST /mt-mcp/oauth/revoke            (optional token revocation)
 *
 * Tokens are opaque, stored hashed, and bound to a WordPress user + resource.
 */
class McpOAuth {

	const OPTION_CLIENTS       = 'mt_mcp_oauth_clients';
	const OPTION_AUTH_CODES    = 'mt_mcp_oauth_codes';
	const OPTION_TOKENS        = 'mt_mcp_oauth_tokens';
	const TRANSIENT_PREFIX     = 'mt_mcp_oauth_';

	const ACCESS_TTL           = 3600;       // 1 hour
	const REFRESH_TTL          = 2592000;    // 30 days
	const CODE_TTL             = 600;        // 10 minutes
	const CLIENT_TTL_DEFAULT   = 0;          // permanent unless rotated

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// Early so WP never commits a 404 for .well-known / oauth routes.
		add_action( 'parse_request', array( $this, 'maybe_dispatch_early' ), 0 );
		add_action( 'template_redirect', array( $this, 'dispatch' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_rest_fallbacks' ) );
	}

	/**
	 * Serve OAuth well-known + rewrite routes before WP marks the request as 404.
	 */
	public function maybe_dispatch_early( $wp ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		// RFC 9728 path insert: /.well-known/oauth-protected-resource/mt-mcp
		// (Claude.ai discovery). Must run before the MCP /mt-mcp handler.
		if ( preg_match( '#/\.well-known/oauth-protected-resource(?:/mt-mcp)?$#', $path ) ) {
			$this->handle_action( 'prm' );
			exit;
		}
		if ( preg_match( '#/\.well-known/oauth-authorization-server(?:/mt-mcp)?$#', $path ) ) {
			$this->handle_action( 'as_meta' );
			exit;
		}
		// Claude (and some OAuth clients) hit {issuer}/authorize instead of the
		// path advertised in AS metadata — accept both.
		if ( preg_match( '#^/authorize$#', $path ) || preg_match( '#^/oauth/authorize$#', $path ) ) {
			$this->handle_action( 'authorize' );
			exit;
		}
		if ( preg_match( '#/mt-mcp/oauth/protected-resource$#', $path ) ) {
			$this->handle_action( 'prm' );
			exit;
		}
		if ( preg_match( '#/mt-mcp/oauth/authorization-server$#', $path ) ) {
			$this->handle_action( 'as_meta' );
			exit;
		}
		if ( preg_match( '#/mt-mcp/oauth/(register|authorize|token|revoke)$#', $path, $m ) ) {
			$this->handle_action( $m[1] );
			exit;
		}
	}

	public function add_rewrite_rules() {
		add_rewrite_rule( '^\\.well-known/oauth-protected-resource(?:/mt-mcp)?/?$', 'index.php?mt_mcp_oauth=prm', 'top' );
		add_rewrite_rule( '^\\.well-known/oauth-authorization-server(?:/mt-mcp)?/?$', 'index.php?mt_mcp_oauth=as_meta', 'top' );
		add_rewrite_rule( '^authorize/?$', 'index.php?mt_mcp_oauth=authorize', 'top' );
		add_rewrite_rule( '^oauth/authorize/?$', 'index.php?mt_mcp_oauth=authorize', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/protected-resource/?$', 'index.php?mt_mcp_oauth=prm', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/authorization-server/?$', 'index.php?mt_mcp_oauth=as_meta', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/register/?$', 'index.php?mt_mcp_oauth=register', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/authorize/?$', 'index.php?mt_mcp_oauth=authorize', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/token/?$', 'index.php?mt_mcp_oauth=token', 'top' );
		add_rewrite_rule( '^mt-mcp/oauth/revoke/?$', 'index.php?mt_mcp_oauth=revoke', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'mt_mcp_oauth';
		return $vars;
	}

	public function register_rest_fallbacks() {
		$routes = array(
			'/oauth/register' => 'register',
			'/oauth/authorize' => 'authorize',
			'/oauth/token'     => 'token',
			'/oauth/revoke'    => 'revoke',
			'/oauth/protected-resource' => 'prm',
			'/oauth/authorization-server' => 'as_meta',
		);
		foreach ( $routes as $path => $action ) {
			register_rest_route(
				'mt-mcp/v1',
				$path,
				array(
					'methods'             => array( 'GET', 'POST', 'OPTIONS' ),
					'callback'            => function () use ( $action ) {
						$this->handle_action( $action );
						exit;
					},
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	public function dispatch() {
		// Also serve well-known without rewrite (some hosts strip dots).
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( $path );

		if ( preg_match( '#/\.well-known/oauth-protected-resource(?:/mt-mcp)?$#', $path ) ) {
			$this->handle_action( 'prm' );
			exit;
		}
		if ( preg_match( '#/\.well-known/oauth-authorization-server(?:/mt-mcp)?$#', $path ) ) {
			$this->handle_action( 'as_meta' );
			exit;
		}
		if ( preg_match( '#^/authorize$#', $path ) || preg_match( '#^/oauth/authorize$#', $path ) ) {
			$this->handle_action( 'authorize' );
			exit;
		}

		$action = get_query_var( 'mt_mcp_oauth' );
		if ( ! $action ) {
			return;
		}
		$this->handle_action( $action );
		exit;
	}

	private function handle_action( $action ) {
		$this->cors_headers();
		$this->clear_404_and_status( 200 );

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			return;
		}

		switch ( $action ) {
			case 'prm':
				$this->json( $this->protected_resource_metadata() );
				break;
			case 'as_meta':
				$this->json( $this->authorization_server_metadata() );
				break;
			case 'register':
				$this->handle_register();
				break;
			case 'authorize':
				$this->handle_authorize();
				break;
			case 'token':
				$this->handle_token();
				break;
			case 'revoke':
				$this->handle_revoke();
				break;
			default:
				$this->json_error( 'invalid_request', 'Unknown OAuth endpoint.', 404 );
		}
	}

	/* -------------------------------------------------------------------------
	 * Metadata
	 * ---------------------------------------------------------------------- */

	public function protected_resource_metadata() {
		$resource = McpSupport::mcp_resource_url();
		return array(
			'resource'                 => $resource,
			'authorization_servers'    => array( untrailingslashit( McpSupport::public_url( '/' ) ) ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( McpAuth::SCOPE_READ, McpAuth::SCOPE_WRITE ),
			'resource_documentation'   => admin_url( 'options-general.php?page=mt-mcp' ),
		);
	}

	public function authorization_server_metadata() {
		// Authorize must be a normal WP front-end URL so browser cookies work.
		// Token/register/revoke stay on REST (machine clients; no cookie session).
		//
		// Advertise both CIMD and DCR. Cursor requires registration_endpoint;
		// Claude prefers CIMD when client_id_metadata_document_supported is true.
		// (Earlier we hid DCR while WPE blocked python-httpx; that edge case is fixed per-site.)
		$meta = array(
			'issuer'                            => untrailingslashit( McpSupport::public_url( '/' ) ),
			// Prefer root /authorize — Claude navigates here even when metadata
			// advertises /mt-mcp/oauth/authorize. Both paths are handled.
			'authorization_endpoint'            => McpSupport::public_url( '/authorize' ),
			'token_endpoint'                    => McpSupport::publicize_url( rest_url( 'mt-mcp/v1/oauth/token' ) ),
			'revocation_endpoint'               => McpSupport::publicize_url( rest_url( 'mt-mcp/v1/oauth/revoke' ) ),
			'response_types_supported'          => array( 'code' ),
			'grant_types_supported'             => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'  => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'scopes_supported'                  => array( McpAuth::SCOPE_READ, McpAuth::SCOPE_WRITE ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
			'client_id_metadata_document_supported' => true,
		);

		/**
		 * Advertise Dynamic Client Registration (needed by Cursor).
		 * Set to false only on hosts that still block DCR at the edge.
		 *
		 * @param bool $advertise Default true.
		 */
		if ( apply_filters( 'mt_mcp_advertise_registration_endpoint', true ) ) {
			$meta['registration_endpoint'] = McpSupport::publicize_url( rest_url( 'mt-mcp/v1/oauth/register' ) );
		}

		return $meta;
	}

	/* -------------------------------------------------------------------------
	 * Dynamic client registration (RFC 7591)
	 * ---------------------------------------------------------------------- */

	private function handle_register() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->json_error( 'invalid_request', 'POST required.', 405 );
			return;
		}

		$body = $this->json_body();
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		if ( empty( $redirect_uris ) ) {
			$this->json_error( 'invalid_client_metadata', 'redirect_uris required.' );
			return;
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! $this->is_allowed_redirect( $uri ) ) {
				$this->json_error( 'invalid_redirect_uri', 'Redirect URI must be https or http://localhost / loopback.' );
				return;
			}
		}

		$client_id     = 'mtmcp_' . bin2hex( random_bytes( 16 ) );
		$client_secret = null;
		$auth_method   = isset( $body['token_endpoint_auth_method'] ) ? $body['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $auth_method ) {
			$client_secret = bin2hex( random_bytes( 24 ) );
			$auth_method   = 'client_secret_post';
		}

		$client = array(
			'client_id'                  => $client_id,
			'client_secret'              => $client_secret ? wp_hash_password( $client_secret ) : null,
			'client_id_issued_at'        => time(),
			'redirect_uris'              => array_values( $redirect_uris ),
			'token_endpoint_auth_method' => $auth_method,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'client_name'                => isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client',
			'scope'                      => isset( $body['scope'] ) ? sanitize_text_field( $body['scope'] ) : ( McpAuth::SCOPE_READ . ' ' . McpAuth::SCOPE_WRITE ),
		);

		McpTokenStore::upsert_client( $client );

		$response = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $client['client_id_issued_at'],
			'redirect_uris'              => $client['redirect_uris'],
			'grant_types'                => $client['grant_types'],
			'response_types'             => $client['response_types'],
			'token_endpoint_auth_method' => $auth_method,
			'client_name'                => $client['client_name'],
			'scope'                      => $client['scope'],
		);
		if ( $client_secret ) {
			$response['client_secret'] = $client_secret;
		}

		// RFC 7591 §3.2.1 — successful registration MUST be 201 Created.
		// Claude.ai rejects DCR when this is overwritten to 200.
		$this->json( $response, 201 );
	}

	/* -------------------------------------------------------------------------
	 * Authorize (consent UI)
	 * ---------------------------------------------------------------------- */

	private function handle_authorize() {
		// Browser OAuth needs the WP logged-in cookie. REST API clears cookie auth
		// unless a wp_rest nonce is present — that caused authorize → login → loop.
		$this->ensure_browser_user();

		$params = 'POST' === $_SERVER['REQUEST_METHOD'] ? wp_unslash( $_POST ) : wp_unslash( $_GET );

		$client_id             = isset( $params['client_id'] ) ? $this->sanitize_client_id( $params['client_id'] ) : '';
		$redirect_uri          = isset( $params['redirect_uri'] ) ? esc_url_raw( $params['redirect_uri'] ) : '';
		$response_type         = isset( $params['response_type'] ) ? sanitize_text_field( $params['response_type'] ) : '';
		$state                 = isset( $params['state'] ) ? sanitize_text_field( $params['state'] ) : '';
		$code_challenge        = isset( $params['code_challenge'] ) ? sanitize_text_field( $params['code_challenge'] ) : '';
		$code_challenge_method = isset( $params['code_challenge_method'] ) ? sanitize_text_field( $params['code_challenge_method'] ) : '';
		$scope                 = isset( $params['scope'] ) ? sanitize_text_field( $params['scope'] ) : ( McpAuth::SCOPE_READ . ' ' . McpAuth::SCOPE_WRITE );
		$resource              = isset( $params['resource'] ) ? esc_url_raw( $params['resource'] ) : McpSupport::mcp_resource_url();

		$client = $this->resolve_client( $client_id );
		if ( ! $client ) {
			wp_die( 'Unknown client_id. Register the MCP client first, or use a supported Client ID Metadata Document URL.', 'OAuth Error', array( 'response' => 400 ) );
		}
		if ( 'code' !== $response_type ) {
			wp_die( 'Only response_type=code is supported.', 'OAuth Error', array( 'response' => 400 ) );
		}
		if ( ! $this->client_allows_redirect( $client, $redirect_uri ) ) {
			wp_die( 'redirect_uri is not registered for this client.', 'OAuth Error', array( 'response' => 400 ) );
		}
		if ( ! $code_challenge || 'S256' !== $code_challenge_method ) {
			wp_die( 'PKCE S256 is required (code_challenge + code_challenge_method=S256).', 'OAuth Error', array( 'response' => 400 ) );
		}

		if ( ! is_user_logged_in() ) {
			// Prefer the non-REST authorize URL so post-login redirect keeps cookies.
			$qs      = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
			$current = $this->authorize_public_url();
			if ( $qs ) {
				$current .= '?' . $qs;
			}
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		$user = wp_get_current_user();
		if ( ! user_can( $user, 'read' ) ) {
			wp_die( 'This account cannot authorize MCP access.', 'OAuth Error', array( 'response' => 403 ) );
		}

		// Consent submit
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'mt_mcp_oauth_consent' );

			if ( ! empty( $params['deny'] ) ) {
				$dest = add_query_arg(
					array(
						'error' => 'access_denied',
						'state' => $state,
					),
					$redirect_uri
				);
				wp_redirect( $dest ); // phpcs:ignore WordPress.Security.SafeRedirect — validated against registered URIs
				exit;
			}

			// Explicit consent choice: read-only vs read+write.
			$grant_level = isset( $params['grant_level'] ) ? sanitize_key( $params['grant_level'] ) : 'read';
			$scopes      = array( McpAuth::SCOPE_READ );
			if ( 'write' === $grant_level && ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) ) {
				$scopes[] = McpAuth::SCOPE_WRITE;
			}

			$conn_label = isset( $params['connection_label'] ) ? sanitize_text_field( wp_unslash( $params['connection_label'] ) ) : '';

			$code = bin2hex( random_bytes( 24 ) );
			McpTokenStore::store_code(
				hash( 'sha256', $code ),
				array(
					'client_id'      => $client_id,
					'user_id'        => (int) $user->ID,
					'redirect_uri'   => $redirect_uri,
					'code_challenge' => $code_challenge,
					'scope'          => implode( ' ', $scopes ),
					'resource'       => $resource,
					'label'          => $conn_label,
					'expires'        => time() + self::CODE_TTL,
				)
			);

			$dest = add_query_arg(
				array(
					'code'  => $code,
					'state' => $state,
				),
				$redirect_uri
			);
			wp_redirect( $dest ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		$this->render_consent_screen( $user, $client, array(
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'response_type'         => $response_type,
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'scope'                 => $scope,
			'resource'              => $resource,
		) );
	}

	private function render_consent_screen( $user, $client, $params ) {
		$site      = get_bloginfo( 'name' );
		$name      = (string) $client['client_name'];
		$login     = (string) $user->user_login;
		$can_write = user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' );
		$wants_write = false !== strpos( (string) $params['scope'], McpAuth::SCOPE_WRITE );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( sprintf( 'Authorize %s — %s', $client['client_name'], $site ) ); ?></title>
	<style>
		:root { --bg:#0f1419; --card:#1a222c; --text:#e7ecf1; --muted:#8b9aab; --accent:#3d8bfd; --danger:#e35d6a; --ok:#3dd68c; --border:#2a3542; }
		* { box-sizing: border-box; }
		body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: radial-gradient(1200px 600px at 20% -10%, #1a3a5c 0%, transparent 50%), var(--bg); color: var(--text); }
		.card { width: min(460px, 92vw); background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
		.brand { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
		h1 { font-size: 22px; margin: 0 0 8px; font-weight: 600; line-height: 1.3; }
		p { color: var(--muted); font-size: 14px; line-height: 1.5; margin: 0 0 16px; }
		.who { background: #121820; border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 16px; }
		.who strong { color: var(--text); }
		.scopes { display:grid; gap:8px; margin: 0 0 18px; }
		.scopes label { display:block; border:1px solid var(--border); border-radius:10px; padding:12px 14px; cursor:pointer; background:#121820; }
		.scopes label:has(input:checked) { border-color: var(--accent); background:#152033; }
		.scopes strong { display:block; margin:0 0 4px; }
		.scopes span { color: var(--muted); font-size:12px; line-height:1.4; }
		.scopes input { margin-right:8px; }
		.actions { display: flex; gap: 10px; }
		button { flex: 1; border: 0; border-radius: 10px; padding: 12px 14px; font-size: 14px; font-weight: 600; cursor: pointer; }
		.allow { background: var(--accent); color: #fff; }
		.deny { background: transparent; color: var(--muted); border: 1px solid var(--border) !important; }
		.foot { margin-top: 16px; font-size: 12px; color: var(--muted); text-align: center; }
		code { font-size: 11px; }
	</style>
</head>
<body>
	<div class="card">
		<div class="brand">MT MCP · <?php echo esc_html( $site ); ?></div>
		<h1>Allow <em><?php echo esc_html( $name ); ?></em> to access this site?</h1>
		<p>The agent will act as <strong>your</strong> WordPress user. Choose read-only or write access. Revoke anytime from Settings → MT MCP.</p>
		<div class="who">Signed in as <strong><?php echo esc_html( $login ); ?></strong><?php echo $user->display_name ? ' · ' . esc_html( $user->display_name ) : ''; ?></div>
		<form method="post" action="<?php echo esc_url( $this->authorize_public_url() ); ?>">
			<?php wp_nonce_field( 'mt_mcp_oauth_consent' ); ?>
			<?php foreach ( $params as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<div class="scopes">
				<label>
					<input type="radio" name="grant_level" value="read" <?php checked( ! $wants_write || ! $can_write ); ?> />
					<strong>Read only</strong>
					<span>Browse content, SEO, media, schema. Cannot create or change anything.</span>
				</label>
				<label <?php echo $can_write ? '' : 'style="opacity:.45;pointer-events:none"'; ?>>
					<input type="radio" name="grant_level" value="write" <?php checked( $wants_write && $can_write ); ?> <?php disabled( ! $can_write ); ?> />
					<strong>Read &amp; write</strong>
					<span><?php echo $can_write ? 'Create and update content when site-wide writes are enabled. Still respects your role capabilities.' : 'This account cannot write.'; ?></span>
				</label>
			</div>
			<label style="display:block;margin:0 0 14px;font-size:13px;">
				<span style="display:block;margin-bottom:6px;color:var(--muted);">Label this connection (optional)</span>
				<input type="text" name="connection_label" maxlength="120" placeholder="e.g. Cursor on MacBook"
					style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:#121820;color:var(--text);" />
			</label>
			<p style="font-size:12px;margin-top:-8px;">Resource: <code><?php echo esc_html( $params['resource'] ); ?></code></p>
			<div class="actions">
				<button type="submit" name="deny" value="1" class="deny">Deny</button>
				<button type="submit" name="allow" value="1" class="allow">Allow</button>
			</div>
		</form>
		<div class="foot">OAuth 2.1 · PKCE S256 · access tokens expire in 1 hour · no shared API keys</div>
	</div>
</body>
</html>
		<?php
	}

	/* -------------------------------------------------------------------------
	 * Token endpoint
	 * ---------------------------------------------------------------------- */

	private function handle_token() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->json_error( 'invalid_request', 'POST required.', 405 );
			return;
		}

		$params = array_merge( $this->json_body(), wp_unslash( $_POST ) );
		$grant  = isset( $params['grant_type'] ) ? $params['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$this->exchange_code( $params );
			return;
		}
		if ( 'refresh_token' === $grant ) {
			$this->refresh_token( $params );
			return;
		}

		$this->json_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.' );
	}

	private function exchange_code( $params ) {
		$code          = isset( $params['code'] ) ? $params['code'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? $params['redirect_uri'] : '';
		$client_id     = isset( $params['client_id'] ) ? $this->sanitize_client_id( $params['client_id'] ) : '';
		$code_verifier = isset( $params['code_verifier'] ) ? $params['code_verifier'] : '';
		$resource      = isset( $params['resource'] ) ? $params['resource'] : McpSupport::mcp_resource_url();

		$client = $this->resolve_client( $client_id );
		if ( ! $client || ! $this->verify_client_secret( $client, $params ) ) {
			$this->json_error( 'invalid_client', 'Client authentication failed.', 401 );
			return;
		}

		$hash = hash( 'sha256', $code );
		$row  = McpTokenStore::consume_code( $hash );
		if ( ! $row ) {
			$this->json_error( 'invalid_grant', 'Authorization code invalid or expired.' );
			return;
		}

		if ( $row['expires'] < time() ) {
			$this->json_error( 'invalid_grant', 'Authorization code expired.' );
			return;
		}
		if ( $row['client_id'] !== $client_id || $row['redirect_uri'] !== $redirect_uri ) {
			$this->json_error( 'invalid_grant', 'Code does not match client/redirect.' );
			return;
		}

		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $row['code_challenge'], $expected ) ) {
			$this->json_error( 'invalid_grant', 'PKCE verification failed.' );
			return;
		}

		// Resource binding (RFC 8707) — soft match on host path.
		if ( ! empty( $row['resource'] ) && $resource && ! $this->resources_match( $row['resource'], $resource ) ) {
			$this->json_error( 'invalid_target', 'Resource mismatch.' );
			return;
		}

		$pending_label = isset( $row['label'] ) ? (string) $row['label'] : '';
		$this->json( $this->issue_tokens( $row['user_id'], $client_id, $row['scope'], $row['resource'], null, $pending_label ) );
	}

	private function refresh_token( $params ) {
		$refresh   = isset( $params['refresh_token'] ) ? $params['refresh_token'] : '';
		$client_id = isset( $params['client_id'] ) ? $this->sanitize_client_id( $params['client_id'] ) : '';
		$resource  = isset( $params['resource'] ) ? $params['resource'] : '';

		$client = $this->resolve_client( $client_id );
		if ( ! $client || ! $this->verify_client_secret( $client, $params ) ) {
			$this->json_error( 'invalid_client', 'Client authentication failed.', 401 );
			return;
		}

		$hash = hash( 'sha256', $refresh );
		$row  = McpTokenStore::get_token( $hash );
		if ( ! $row || empty( $row['is_refresh'] ) ) {
			// Reuse detection: previously rotated refresh presented again → revoke family.
			$reuse = get_transient( 'mt_mcp_rt_used_' . $hash );
			if ( is_array( $reuse ) && ! empty( $reuse['user_id'] ) && ! empty( $reuse['client_id'] ) ) {
				McpTokenStore::revoke_all_for_user( (int) $reuse['user_id'] );
				$this->json_error( 'invalid_grant', 'Refresh token reuse detected; all tokens for this user were revoked.', 401 );
				return;
			}
			$this->json_error( 'invalid_grant', 'Refresh token invalid.' );
			return;
		}
		if ( $row['expires'] < time() || $row['client_id'] !== $client_id ) {
			McpTokenStore::delete_token( $hash );
			$this->json_error( 'invalid_grant', 'Refresh token expired or mismatched.' );
			return;
		}

		// Audience binding: refresh resource must match the grant (when provided).
		$bound = isset( $row['resource'] ) ? $row['resource'] : McpSupport::mcp_resource_url();
		if ( $resource && ! $this->resources_match( $resource, $bound ) ) {
			$this->json_error( 'invalid_target', 'resource does not match the refresh token audience.', 401 );
			return;
		}
		$resource = $resource ? $resource : $bound;

		// Rotate: mark old hash as used for reuse detection, then delete.
		set_transient(
			'mt_mcp_rt_used_' . $hash,
			array(
				'user_id'   => (int) $row['user_id'],
				'client_id' => $client_id,
				'exp'       => time() + DAY_IN_SECONDS,
			),
			DAY_IN_SECONDS
		);
		McpTokenStore::delete_token( $hash );

		// Never escalate scopes on refresh. Client may only request a subset of the grant.
		$granted  = preg_split( '/\s+/', trim( (string) $row['scope'] ) );
		$granted  = is_array( $granted ) ? array_filter( $granted ) : array();
		$scope_in = (string) $row['scope'];
		if ( ! empty( $params['scope'] ) ) {
			$requested = preg_split( '/\s+/', trim( (string) $params['scope'] ) );
			$kept      = array_values( array_intersect( (array) $requested, $granted ) );
			if ( $kept ) {
				$scope_in = implode( ' ', $kept );
			}
		}
		$user     = get_userdata( (int) $row['user_id'] );
		$scope    = implode( ' ', $this->normalize_scopes( $scope_in, $user, $granted ) );
		$grant_id = ! empty( $row['grant_id'] ) ? (string) $row['grant_id'] : McpTokenStore::new_grant_id();
		$this->json( $this->issue_tokens( $row['user_id'], $client_id, $scope, $resource, $grant_id ) );
	}

	/**
	 * @param string|null $grant_id Stable consent family id (preserved across refresh).
	 * @param string      $label    Optional human label applied once at first issue.
	 */
	private function issue_tokens( $user_id, $client_id, $scope, $resource, $grant_id = null, $label = '' ) {
		$access   = 'mta_' . bin2hex( random_bytes( 32 ) );
		$refresh  = 'mtr_' . bin2hex( random_bytes( 32 ) );
		$grant_id = is_string( $grant_id ) && strlen( preg_replace( '/[^a-f0-9]/i', '', $grant_id ) ) === 32
			? strtolower( preg_replace( '/[^a-f0-9]/i', '', $grant_id ) )
			: McpTokenStore::new_grant_id();

		McpTokenStore::prune_expired_tokens();
		McpTokenStore::store_token(
			hash( 'sha256', $access ),
			array(
				'user_id'    => (int) $user_id,
				'client_id'  => $client_id,
				'scope'      => $scope,
				'resource'   => $resource,
				'grant_id'   => $grant_id,
				'expires'    => time() + self::ACCESS_TTL,
				'is_refresh' => false,
			)
		);
		McpTokenStore::store_token(
			hash( 'sha256', $refresh ),
			array(
				'user_id'    => (int) $user_id,
				'client_id'  => $client_id,
				'scope'      => $scope,
				'resource'   => $resource,
				'grant_id'   => $grant_id,
				'expires'    => time() + self::REFRESH_TTL,
				'is_refresh' => true,
			)
		);

		if ( '' !== trim( (string) $label ) ) {
			McpSupport::set_connection_label( (int) $user_id, $client_id, $label, $grant_id );
		}

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/**
	 * Validate a bearer access token. Used by McpAuth.
	 *
	 * @return array{ok:bool,scope?:string,user_id?:int,method?:string}
	 */
	public static function validate_access_token( $token ) {
		if ( 0 !== strpos( $token, 'mta_' ) ) {
			return array( 'ok' => false );
		}
		$row = McpTokenStore::get_token( hash( 'sha256', $token ) );
		if ( ! $row || ! empty( $row['is_refresh'] ) || $row['expires'] < time() ) {
			return array( 'ok' => false );
		}
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user ) {
			return array( 'ok' => false );
		}

		$scopes = explode( ' ', (string) $row['scope'] );
		$scope  = in_array( McpAuth::SCOPE_WRITE, $scopes, true ) ? 'write' : 'read';

		// Soft audience hint for callers (MCP resource URL).
		$expected = McpSupport::mcp_resource_url();
		$bound    = isset( $row['resource'] ) ? $row['resource'] : $expected;
		$audience_ok = self::static_resources_match( $bound, $expected );
		$grant_id    = McpTokenStore::ensure_grant_id( $row );

		return array(
			'ok'          => true,
			'scope'       => $scope,
			'user_id'     => (int) $row['user_id'],
			'client_id'   => isset( $row['client_id'] ) ? (string) $row['client_id'] : '',
			'grant_id'    => $grant_id,
			'method'      => 'oauth',
			'resource'    => $bound,
			'audience_ok' => $audience_ok,
		);
	}

	/**
	 * Static wrapper so validate_access_token can compare resources without an instance.
	 */
	public static function static_resources_match( $a, $b ) {
		$na = untrailingslashit( strtolower( (string) $a ) );
		$nb = untrailingslashit( strtolower( (string) $b ) );
		return $na === $nb;
	}

	private function handle_revoke() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->json_error( 'invalid_request', 'POST required.', 405 );
			return;
		}
		$params = array_merge( $this->json_body(), wp_unslash( $_POST ) );
		$token  = isset( $params['token'] ) ? $params['token'] : '';
		if ( $token ) {
			McpTokenStore::delete_token( hash( 'sha256', $token ) );
		}
		status_header( 200 );
		$this->json( new \stdClass() );
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	public static function list_active_grants() {
		return McpTokenStore::list_active_refresh_grants();
	}

	public static function revoke_all_for_user( $user_id ) {
		McpTokenStore::revoke_all_for_user( $user_id );
	}

	public static function revoke_grant_by_hash_prefix( $prefix ) {
		McpTokenStore::revoke_by_hash_prefix( $prefix );
	}

	/**
	 * WP REST API calls wp_set_current_user(0) when no wp_rest nonce is present.
	 * Authorize (and consent POST) must use the normal logged-in cookie instead.
	 */
	private function ensure_browser_user() {
		if ( is_user_logged_in() ) {
			return;
		}
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $user_id ) {
			$user_id = wp_validate_auth_cookie( '', 'secure_auth' );
		}
		if ( ! $user_id ) {
			$user_id = wp_validate_auth_cookie( '', 'auth' );
		}
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Public browser authorize URL for the current request (Claude uses /authorize).
	 */
	private function authorize_public_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( '/authorize' === $path || '/oauth/authorize' === $path ) {
			return home_url( $path ? $path : '/authorize' );
		}
		return home_url( '/authorize' );
	}

	private function get_client( $client_id ) {
		return $client_id ? McpTokenStore::get_client( $client_id ) : null;
	}

	/**
	 * Preserve https CIMD client_id URLs; sanitize opaque DCR ids.
	 */
	private function sanitize_client_id( $client_id ) {
		$client_id = trim( (string) $client_id );
		if ( 0 === stripos( $client_id, 'https://' ) ) {
			return esc_url_raw( $client_id );
		}
		return sanitize_text_field( $client_id );
	}

	/**
	 * Resolve a registered client or a Client ID Metadata Document (CIMD) URL.
	 */
	private function resolve_client( $client_id ) {
		if ( ! $client_id ) {
			return null;
		}
		$local = $this->get_client( $client_id );
		if ( $local ) {
			return $local;
		}
		if ( 0 === stripos( $client_id, 'https://' ) ) {
			return $this->fetch_cimd_client( $client_id );
		}
		return null;
	}

	/**
	 * Fetch + cache an OAuth Client ID Metadata Document (Claude published identity).
	 */
	private function fetch_cimd_client( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		// SSRF guard: only fetch known public CIMD hosts over https.
		$allowed_hosts = apply_filters(
			'mt_mcp_cimd_allowed_hosts',
			array( 'claude.ai', 'www.claude.ai' )
		);
		if ( 'https' !== ( $parts['scheme'] ?? '' ) || ! $host || ! in_array( $host, $allowed_hosts, true ) ) {
			return null;
		}

		$cache_key = 'mt_mcp_cimd_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['client_id'] ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'mt_mcp_sslverify', true, $url ),
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'MT-MCP-OAuth/1.1 (+https://motiontactic.com)',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return null;
		}

		$meta_id = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		// Spec: document's client_id should match the URL used as client_id.
		if ( $meta_id && untrailingslashit( $meta_id ) !== untrailingslashit( $url ) ) {
			return null;
		}

		$redirects = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_values( $body['redirect_uris'] ) : array();
		if ( empty( $redirects ) ) {
			return null;
		}
		foreach ( $redirects as $uri ) {
			if ( ! $this->is_allowed_redirect( $uri ) ) {
				return null;
			}
		}

		$client = array(
			'client_id'                  => $url,
			'client_secret'              => null,
			'client_name'                => isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'CIMD Client',
			'redirect_uris'              => $redirects,
			'token_endpoint_auth_method' => isset( $body['token_endpoint_auth_method'] ) ? sanitize_text_field( $body['token_endpoint_auth_method'] ) : 'none',
			'scope'                      => isset( $body['scope'] ) ? sanitize_text_field( $body['scope'] ) : ( McpAuth::SCOPE_READ . ' ' . McpAuth::SCOPE_WRITE ),
			'client_id_issued_at'        => time(),
			'cimd'                       => true,
		);

		set_transient( $cache_key, $client, 10 * MINUTE_IN_SECONDS );
		return $client;
	}

	private function client_allows_redirect( $client, $redirect_uri ) {
		if ( ! $redirect_uri || empty( $client['redirect_uris'] ) || ! is_array( $client['redirect_uris'] ) ) {
			return false;
		}
		$want = untrailingslashit( $redirect_uri );
		foreach ( $client['redirect_uris'] as $allowed ) {
			if ( untrailingslashit( (string) $allowed ) === $want ) {
				return true;
			}
		}
		return false;
	}

	private function verify_client_secret( $client, $params ) {
		if ( empty( $client['client_secret'] ) ) {
			return true; // public client
		}
		$secret = isset( $params['client_secret'] ) ? $params['client_secret'] : '';
		return $secret && wp_check_password( $secret, $client['client_secret'] );
	}

	/**
	 * @param string        $scope_string Requested scopes.
	 * @param \WP_User|false $user User.
	 * @param string[]|null $ceiling Optional max scopes (e.g. original grant on refresh).
	 * @return string[]
	 */
	private function normalize_scopes( $scope_string, $user, $ceiling = null ) {
		$requested = preg_split( '/\s+/', trim( (string) $scope_string ) );
		$requested = is_array( $requested ) ? $requested : array();
		$out       = array( McpAuth::SCOPE_READ );
		$can_write = $user && ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) );
		$write_ok  = in_array( McpAuth::SCOPE_WRITE, $requested, true ) && $can_write;
		if ( $write_ok && is_array( $ceiling ) ) {
			$write_ok = in_array( McpAuth::SCOPE_WRITE, $ceiling, true );
		}
		if ( $write_ok ) {
			$out[] = McpAuth::SCOPE_WRITE;
		}
		return array_values( array_unique( $out ) );
	}

	private function is_allowed_redirect( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		// Native app custom schemes (Cursor / VS Code / Claude Desktop).
		$app_schemes = array( 'cursor', 'vscode', 'vscode-insiders', 'claude' );
		if ( in_array( $scheme, $app_schemes, true ) ) {
			return true;
		}

		if ( empty( $host ) ) {
			return false;
		}

		// Loopback HTTP(S) for local MCP clients.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) && in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}

		$allowed_hosts = array_merge(
			array(
				'claude.ai',
				'www.claude.ai',
				'api.anthropic.com',
				'chatgpt.com',
				'chat.openai.com',
			),
			McpSupport::dcr_extra_hosts()
		);
		$allowed_hosts = array_values( array_unique( array_filter( array_map( 'strtolower', $allowed_hosts ) ) ) );

		if ( 'https' === $scheme ) {
			foreach ( $allowed_hosts as $allowed ) {
				if ( $host === $allowed || substr( $host, -1 * ( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
					return true;
				}
			}
			return false;
		}

		// Non-loopback http only when explicitly allowlisted (staging tunnels).
		if ( 'http' === $scheme && in_array( $host, $allowed_hosts, true ) ) {
			return true;
		}

		return false;
	}

	private function resources_match( $a, $b ) {
		return self::static_resources_match( $a, $b );
	}

	private function json_body() {
		$raw = file_get_contents( 'php://input' );
		if ( ! $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private function cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-WP-Username' );
	}

	/**
	 * Clear WP 404 state and set an explicit HTTP status (default 200 for metadata).
	 */
	private function clear_404_and_status( $status = 200 ) {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( (int) $status );
	}

	private function json( $data, $status = 200 ) {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		$this->clear_404_and_status( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data );
	}

	private function json_error( $error, $description, $status = 400 ) {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		$this->clear_404_and_status( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array(
			'error'             => $error,
			'error_description' => $description,
		) );
	}
}
