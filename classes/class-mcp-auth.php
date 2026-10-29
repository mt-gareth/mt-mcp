<?php

namespace MT\Mcp;

/**
 * Request authentication for the MCP resource server.
 *
 * Supported methods only (no shared API keys — those are a backdoor risk):
 * 1. OAuth 2.1 access tokens issued by McpOAuth (preferred — binds to a WP user)
 * 2. WordPress Application Passwords (Basic or Bearer "user:app_password")
 *
 * Successful auth always calls wp_set_current_user() so capability checks apply.
 */
class McpAuth {

	const OPTION_AUTH_MODE = 'mt_mcp_auth_mode'; // oauth | both

	const SCOPE_READ  = 'mcp:read';
	const SCOPE_WRITE = 'mcp:write';

	/**
	 * @return array{ok:bool,scope?:string,user_id?:int,method?:string,error?:string,www_authenticate?:string}
	 */
	public static function authenticate( $required_scope = 'read' ) {
		$token = self::extract_bearer_token();
		$mode  = self::get_auth_mode();

		if ( $token ) {
			if ( in_array( $mode, array( 'oauth', 'both' ), true ) ) {
				$oauth = McpOAuth::validate_access_token( $token );
				if ( $oauth['ok'] ) {
					return self::finalize( $oauth, $required_scope );
				}
			}

			if ( 'both' === $mode ) {
				$app = self::try_application_password_bearer( $token );
				if ( $app['ok'] ) {
					return self::finalize( $app, $required_scope );
				}
			}
		}

		if ( 'both' === $mode ) {
			$basic = self::try_basic_auth();
			if ( $basic['ok'] ) {
				return self::finalize( $basic, $required_scope );
			}
		}

		return array(
			'ok'               => false,
			'error'            => 'both' === $mode
				? 'Authorization required. Sign in with OAuth or use a WordPress Application Password.'
				: 'Authorization required. Sign in with OAuth.',
			'www_authenticate' => self::www_authenticate_header(),
		);
	}

	/**
	 * WWW-Authenticate for MCP clients (RFC 9728 discovery).
	 */
	public static function www_authenticate_header() {
		// Prefer REST metadata (always 200). Include error= so Cursor enters needsAuth.
		$resource_meta = rest_url( 'mt-mcp/v1/oauth/protected-resource' );
		return sprintf(
			'Bearer realm="mt-mcp", error="invalid_token", error_description="Authentication required", resource_metadata="%s", scope="%s %s"',
			$resource_meta,
			self::SCOPE_READ,
			self::SCOPE_WRITE
		);
	}

	public static function get_auth_mode() {
		$mode = get_option( self::OPTION_AUTH_MODE, 'oauth' );
		// Migrate away from removed modes.
		if ( in_array( $mode, array( 'api_key', 'hybrid', 'app_password' ), true ) ) {
			$mode = 'oauth';
		}
		$allowed = array( 'oauth', 'both' );
		return in_array( $mode, $allowed, true ) ? $mode : 'oauth';
	}

	/**
	 * Delete any leftover shared-key options from earlier builds.
	 */
	public static function purge_legacy_key_options() {
		$keys = array(
			'mt_mcp_api_key',
			'mt_mcp_write_key',
			'mt_mcp_allow_legacy_keys',
			'mt_mcp_legacy_key_user_id',
			'utility_plugin_mcp_write_key',
		);
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Capability gate for a tool once a user is authenticated.
	 *
	 * @return true|\WP_Error
	 */
	public static function user_can_run_tool( $tool_name ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new \WP_Error( 'forbidden', 'A WordPress user session is required. Use OAuth or an Application Password.' );
		}

		$map = array(
			'create_post'              => 'edit_posts',
			'update_post'              => 'edit_posts',
			'trash_post'               => 'delete_posts',
			'bulk_update_yoast'        => 'edit_posts',
			'bulk_update_seo'          => 'edit_posts',
			'update_media'             => 'upload_files',
			'upload_media'             => 'upload_files',
			'bulk_fix_media_alt'    => 'upload_files',
			'schedule_post'            => 'edit_posts',
			'update_options'           => 'manage_options',
			'update_options_page'      => 'manage_options',
			'create_menu'              => 'edit_theme_options',
			'update_menu'              => 'edit_theme_options',
			'delete_menu'              => 'edit_theme_options',
			'update_menu_items'        => 'edit_theme_options',
			'create_term'              => 'manage_categories',
			'update_term'              => 'manage_categories',
			'delete_term'              => 'manage_categories',
			'duplicate_post'           => 'edit_posts',
			'create_draft_preview'     => 'edit_posts',
			'revoke_draft_preview'     => 'edit_posts',
			'prepare_media_upload'     => 'upload_files',
			'import_redirects'         => 'manage_options',
			'set_write_gates'          => 'manage_options',
			'confirm_dangerous_op'     => 'edit_posts',
			'find_replace'             => 'edit_posts',
			'create_redirect'          => 'manage_options',
			'update_redirect'          => 'manage_options',
			'delete_redirect'          => 'manage_options',
			'import_post'              => 'edit_posts',
			'import_posts'             => 'edit_posts',
			'export_posts'             => 'edit_posts',
			'restore_revision'         => 'edit_posts',
			'get_options'              => 'manage_options',
			'get_audit_log'            => 'manage_options',
			'export_audit_log'         => 'manage_options',
			'get_site_health'          => 'manage_options',
			'notify_write_batch'       => 'edit_posts',
		);

		$cap = isset( $map[ $tool_name ] ) ? $map[ $tool_name ] : 'read';
		if ( ! user_can( $user_id, $cap ) ) {
			return new \WP_Error(
				'forbidden',
				sprintf( 'User lacks capability "%s" for tool "%s".', $cap, $tool_name )
			);
		}

		return true;
	}

	private static function finalize( $auth, $required_scope ) {
		$user_id = isset( $auth['user_id'] ) ? (int) $auth['user_id'] : 0;
		if ( ! $user_id ) {
			return array(
				'ok'    => false,
				'error' => 'Authentication must map to a WordPress user.',
			);
		}

		wp_set_current_user( $user_id );

		$scope = isset( $auth['scope'] ) ? $auth['scope'] : 'read';
		if ( 'write' === $required_scope && 'write' !== $scope ) {
			return array(
				'ok'    => false,
				'error' => 'Token is read-only. Re-authorize with mcp:write scope.',
			);
		}

		return array(
			'ok'      => true,
			'scope'   => $scope,
			'user_id' => $user_id,
			'method'  => isset( $auth['method'] ) ? $auth['method'] : 'unknown',
		);
	}

	public static function extract_bearer_token() {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( $header && 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}
		return null;
	}

	private static function try_basic_auth() {
		$user = '';
		$pass = '';

		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			$user = $_SERVER['PHP_AUTH_USER'];
			$pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '';
		} else {
			$header = '';
			if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['HTTP_AUTHORIZATION'], 'Basic ' ) ) {
				$header = substr( $_SERVER['HTTP_AUTHORIZATION'], 6 );
			} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 'Basic ' ) ) {
				$header = substr( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 6 );
			}
			if ( $header ) {
				$decoded = base64_decode( $header, true );
				if ( $decoded && false !== strpos( $decoded, ':' ) ) {
					list( $user, $pass ) = explode( ':', $decoded, 2 );
				}
			}
		}

		if ( ! $user || ! $pass ) {
			return array( 'ok' => false );
		}

		return self::authenticate_application_password( $user, $pass );
	}

	/**
	 * Bearer may be "username:app_password" or an Application Password with X-WP-Username.
	 */
	private static function try_application_password_bearer( $token ) {
		if ( false !== strpos( $token, ':' ) ) {
			list( $user, $pass ) = explode( ':', $token, 2 );
			return self::authenticate_application_password( $user, $pass );
		}

		$username = '';
		if ( ! empty( $_SERVER['HTTP_X_WP_USERNAME'] ) ) {
			$username = sanitize_user( wp_unslash( $_SERVER['HTTP_X_WP_USERNAME'] ) );
		}
		if ( ! $username ) {
			return array( 'ok' => false );
		}
		return self::authenticate_application_password( $username, $token );
	}

	private static function authenticate_application_password( $username, $password ) {
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			$user = get_user_by( 'email', $username );
		}
		if ( ! $user ) {
			return array( 'ok' => false );
		}

		$password = preg_replace( '/\s+/', '', (string) $password );
		if ( '' === $password ) {
			return array( 'ok' => false );
		}

		$authed = null;
		if ( function_exists( 'wp_authenticate_application_password' ) ) {
			$authed = wp_authenticate_application_password( null, $user->user_login, $password );
		}

		if ( ! $authed instanceof \WP_User && class_exists( '\WP_Application_Passwords' ) ) {
			$stored = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
			$match  = false;
			foreach ( (array) $stored as $item ) {
				if ( ! empty( $item['password'] ) && \WP_Application_Passwords::check_password( $password, $item['password'] ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match ) {
				return array( 'ok' => false );
			}
			$authed = $user;
		}

		if ( ! $authed instanceof \WP_User ) {
			return array( 'ok' => false );
		}

		$can_write = user_can( $authed, 'edit_posts' ) || user_can( $authed, 'manage_options' );
		return array(
			'ok'      => true,
			'scope'   => $can_write ? 'write' : 'read',
			'user_id' => (int) $authed->ID,
			'method'  => 'application_password',
		);
	}
}
