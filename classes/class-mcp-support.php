<?php

namespace MT\Mcp;

/**
 * Shared helpers for the MCP server: auth scopes, tool-group allowlists,
 * audit log, allowlisted options, Yoast/ACF helpers, and before/after diffs.
 */
class McpSupport {

	const OPTION_ENABLED      = 'mt_mcp_enabled';
	const OPTION_ALLOW_WRITES = 'mt_mcp_allow_writes';
	const OPTION_FLUSHED      = 'mt_mcp_flushed';
	const OPTION_TOOL_GROUPS  = 'mt_mcp_tool_groups';
	const OPTION_AUDIT_LOG    = 'mt_mcp_audit_log';
	const OPTION_ENVIRONMENT  = 'mt_mcp_environment';
	const OPTION_WEBHOOK_URL  = 'mt_mcp_webhook_url';
	const OPTION_PAUSE_WRITES = 'mt_mcp_pause_writes';
	const OPTION_FORCE_DRAFT  = 'mt_mcp_force_draft';
	const OPTION_SITE_BRIEF   = 'mt_mcp_site_brief';
	const OPTION_CONNECTIONS = 'mt_mcp_connections';
	const OPTION_DCR_HOSTS   = 'mt_mcp_dcr_extra_hosts';
	const OPTION_OP_RECEIPTS = 'mt_mcp_op_receipts';
	const OPTION_UNDO_JOURNAL = 'mt_mcp_undo_journal';
	const OPTION_ALLOW_UPDATES = 'mt_mcp_allow_updates';
	const AUDIT_MAX_ENTRIES   = 200;
	const UNDO_MAX_ENTRIES    = 50;
	const UNDO_TTL_SECONDS    = 259200; // 72 hours
	const RATE_LIMIT_PER_MIN  = 120;
	const AUDIT_HMAC_OPTION   = 'mt_mcp_audit_hmac_key';

	/** Tools that get a pre-write snapshot + undo token (OAuth / MCP options never snapshotted). */
	const UNDOABLE_TOOLS = array(
		'create_post',
		'update_post',
		'trash_post',
		'update_media',
		'update_options_page',
		'restore_revision',
		'duplicate_post',
	);

	const ENVIRONMENTS = array(
		'production'  => 'Production',
		'staging'     => 'Staging',
		'development' => 'Development',
		'unknown'     => 'Unknown',
	);

	const TOOL_GROUPS = array(
		'content'    => 'Content (posts/pages)',
		'taxonomies' => 'Taxonomies',
		'seo'        => 'SEO (Yoast)',
		'acf'        => 'ACF schemas & options pages',
		'media'      => 'Media library',
		'menus'      => 'Menus',
		'options'    => 'Site / utility options',
		'redirects'  => 'Redirects',
		'review'     => 'Review / audit tools',
		'audit'      => 'MCP audit log',
		'transfer'   => 'Export / import packages',
		'system'     => 'Site health & skill generator',
		'updates'    => 'Plugin / core updates',
	);

	const WRITE_TOOLS = array(
		'create_post',
		'update_post',
		'trash_post',
		'duplicate_post',
		'create_term',
		'update_term',
		'delete_term',
		'find_replace',
		'bulk_update_yoast',
		'bulk_update_seo',
		'update_media',
		'upload_media',
		'bulk_fix_media_alt',
		'replace_attachment_file',
		'bulk_update_posts',
		'schedule_post',
		'update_options',
		'update_options_page',
		'create_menu',
		'update_menu',
		'delete_menu',
		'update_menu_items',
		'create_draft_preview',
		'revoke_draft_preview',
		'prepare_media_upload',
		'import_redirects',
		'set_write_gates',
		'manage_connections',
		'review_draft',
		'undo_change',
		'confirm_dangerous_op',
		'create_redirect',
		'update_redirect',
		'delete_redirect',
		'import_post',
		'import_posts',
		'restore_revision',
		'notify_write_batch',
		'start_update_session',
		'update_package',
		'rollback_package',
		'run_smoke_checks',
		'finish_update_session',
	);

	/**
	 * Option keys that must never be written via MCP, even if a filter adds them to the allowlist.
	 */
	const PROTECTED_OPTION_KEYS = array(
		'siteurl',
		'home',
		'active_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'admin_email',
		'users_can_register',
		'default_role',
		'cron',
		'rewrite_rules',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		'secret_key',
		'mt_mcp_audit_hmac_key',
	);

	/**
	 * Default: every group enabled. Stored as a JSON/array option of group => '1'.
	 */
	public static function enabled_groups() {
		$stored = get_option( self::OPTION_TOOL_GROUPS, null );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array_fill_keys( array_keys( self::TOOL_GROUPS ), true );
		}
		$out = array();
		foreach ( self::TOOL_GROUPS as $key => $label ) {
			$out[ $key ] = ! empty( $stored[ $key ] );
		}
		return $out;
	}

	public static function group_for_tool( $tool ) {
		$map = array(
			'get_site_context'       => 'content',
			'list_post_types'        => 'content',
			'list_posts'             => 'content',
			'search_posts'           => 'content',
			'get_post'               => 'content',
			'create_post'            => 'content',
			'update_post'            => 'content',
			'trash_post'             => 'content',
			'list_revisions'         => 'content',
			'get_revision'           => 'content',
			'restore_revision'       => 'content',
			'list_taxonomies'        => 'taxonomies',
			'get_terms'              => 'taxonomies',
			'create_term'            => 'taxonomies',
			'update_term'            => 'taxonomies',
			'delete_term'            => 'taxonomies',
			'list_acf_field_groups'  => 'acf',
			'list_acf_options_pages' => 'acf',
			'get_acf_schema'         => 'acf',
			'get_options_page'       => 'acf',
			'update_options_page'    => 'acf',
			'bulk_update_yoast'      => 'seo',
			'bulk_update_seo'        => 'seo',
			'seo_audit'              => 'seo',
			'suggest_internal_links' => 'seo',
			'validate_schema_ld'     => 'seo',
			'sitemap_diff'           => 'seo',
			'list_media'             => 'media',
			'get_media'              => 'media',
			'update_media'           => 'media',
			'upload_media'           => 'media',
			'bulk_fix_media_alt'    => 'media',
			'media_alt_report'       => 'media',
			'unused_media_report'    => 'media',
			'prepare_media_upload'   => 'media',
			'replace_attachment_file'=> 'media',
			'acf_media_audit'        => 'media',
			'list_theme_templates'   => 'content',
			'plugin_capability_matrix'=> 'system',
			'diff_public_html'       => 'review',
			'check_links'            => 'review',
			'list_menus'             => 'menus',
			'get_menu'               => 'menus',
			'create_menu'            => 'menus',
			'update_menu'            => 'menus',
			'delete_menu'            => 'menus',
			'update_menu_items'      => 'menus',
			'get_options'            => 'options',
			'update_options'         => 'options',
			'list_redirects'         => 'redirects',
			'import_redirects'       => 'redirects',
			'create_redirect'        => 'redirects',
			'update_redirect'        => 'redirects',
			'delete_redirect'        => 'redirects',
			'content_review'         => 'review',
			'preflight_publish'      => 'review',
			'create_draft_preview'   => 'content',
			'revoke_draft_preview'   => 'content',
			'get_audit_log'          => 'audit',
			'export_audit_log'       => 'audit',
			'export_post'            => 'transfer',
			'export_posts'           => 'transfer',
			'import_post'            => 'transfer',
			'import_posts'           => 'transfer',
			'check_import_conflicts' => 'transfer',
			'dedupe_attachments'     => 'transfer',
			'schedule_post'          => 'content',
			'duplicate_post'         => 'content',
			'describe_post_type'     => 'content',
			'find_replace'           => 'content',
			'bulk_update_posts'      => 'content',
			'list_users'             => 'content',
			'get_site_health'        => 'system',
			'connection_doctor'      => 'system',
			'manage_connections'     => 'system',
			'list_needs_review'      => 'content',
			'review_draft'           => 'content',
			'list_changes'           => 'audit',
			'get_change'             => 'audit',
			'undo_change'            => 'audit',
			'set_write_gates'        => 'system',
			'confirm_dangerous_op'   => 'system',
			'generate_cursor_skill'  => 'system',
			'notify_write_batch'     => 'system',
			'list_updates'           => 'updates',
			'start_update_session'   => 'updates',
			'get_update_session'     => 'updates',
			'update_package'         => 'updates',
			'rollback_package'       => 'updates',
			'run_smoke_checks'       => 'updates',
			'finish_update_session'  => 'updates',

		);
		return isset( $map[ $tool ] ) ? $map[ $tool ] : null;
	}

	/**
	 * Environment for this install. Constant MT_MCP_ENVIRONMENT wins over the option.
	 */
	public static function get_environment() {
		if ( defined( 'MT_MCP_ENVIRONMENT' ) && MT_MCP_ENVIRONMENT ) {
			$env = strtolower( (string) MT_MCP_ENVIRONMENT );
		} else {
			$env = strtolower( (string) get_option( self::OPTION_ENVIRONMENT, 'unknown' ) );
		}
		return isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'unknown';
	}

	/**
	 * Public base URL for OAuth/MCP discovery ads (tunnel-friendly).
	 * Define MT_MCP_PUBLIC_BASE in wp-config (e.g. https://abc.ngrok.io) when
	 * home_url() is not reachable by Cursor/Claude.
	 *
	 * @param string $path Optional path beginning with /.
	 * @return string
	 */
	public static function public_url( $path = '/' ) {
		$path = '/' . ltrim( (string) $path, '/' );
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		$base = '';
		if ( defined( 'MT_MCP_PUBLIC_BASE' ) && MT_MCP_PUBLIC_BASE ) {
			$base = untrailingslashit( (string) MT_MCP_PUBLIC_BASE );
		}
		/**
		 * Filter the advertised public base (no trailing slash).
		 *
		 * @param string $base Empty to use home_url.
		 */
		$base = apply_filters( 'mt_mcp_public_base', $base );
		if ( $base ) {
			return ( '/' === $path ) ? trailingslashit( $base ) : ( $base . $path );
		}
		return ( '/' === $path ) ? trailingslashit( home_url( '/' ) ) : home_url( $path );
	}

	public static function mcp_resource_url() {
		return untrailingslashit( self::public_url( '/mt-mcp' ) );
	}

	/**
	 * Rewrite a home/rest URL onto MT_MCP_PUBLIC_BASE when defined.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function publicize_url( $url ) {
		$url = (string) $url;
		if ( ! $url ) {
			return $url;
		}
		$base = '';
		if ( defined( 'MT_MCP_PUBLIC_BASE' ) && MT_MCP_PUBLIC_BASE ) {
			$base = untrailingslashit( (string) MT_MCP_PUBLIC_BASE );
		}
		$base = apply_filters( 'mt_mcp_public_base', $base );
		if ( ! $base ) {
			return $url;
		}
		$home = untrailingslashit( home_url( '/' ) );
		if ( 0 === strpos( $url, $home ) ) {
			return $base . substr( $url, strlen( $home ) );
		}
		return $url;
	}

	public static function tool_allowed( $tool ) {
		$group = self::group_for_tool( $tool );
		if ( ! $group ) {
			return true;
		}
		$groups = self::enabled_groups();
		return ! empty( $groups[ $group ] );
	}

	public static function is_write_tool( $tool ) {
		return in_array( $tool, self::WRITE_TOOLS, true );
	}

	/**
	 * Extract API key from common headers.
	 */
	public static function extract_key_from_headers() {
		$key = null;

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['HTTP_AUTHORIZATION'], 'Bearer ' ) ) {
			$key = substr( $_SERVER['HTTP_AUTHORIZATION'], 7 );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 'Bearer ' ) ) {
			$key = substr( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 7 );
		} elseif ( ! empty( $_SERVER['HTTP_X_MT_SEO_KEY'] ) ) {
			$key = $_SERVER['HTTP_X_MT_SEO_KEY'];
		}

		return $key ? trim( $key ) : null;
	}

	/**
	 * @return array{ok:bool,scope?:string,error?:string}
	 */
	/**
	 * Authenticate the current request via OAuth or Application Passwords
	 * (always a real WP user — no shared API keys).
	 *
	 * @return array{ok:bool,scope?:string,user_id?:int,method?:string,error?:string,www_authenticate?:string}
	 */
	public static function authenticate( $required_scope = 'read' ) {
		return McpAuth::authenticate( $required_scope );
	}

	/**
	 * Secret used to HMAC-sign audit rows (created once).
	 */
	public static function audit_hmac_key() {
		$key = get_option( self::AUDIT_HMAC_OPTION );
		if ( ! $key ) {
			$key = bin2hex( random_bytes( 32 ) );
			update_option( self::AUDIT_HMAC_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * Verify a signed audit entry.
	 */
	public static function verify_audit_entry( $entry ) {
		if ( empty( $entry['sig'] ) ) {
			return false;
		}
		$sig = $entry['sig'];
		$copy = $entry;
		unset( $copy['sig'] );
		$expected = hash_hmac( 'sha256', wp_json_encode( $copy ), self::audit_hmac_key() );
		return hash_equals( $expected, $sig );
	}

	public static function check_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'mt_mcp_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_LIMIT_PER_MIN ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public static function audit( $tool, $args_summary, $ok, $message = '' ) {
		$log   = get_option( self::OPTION_AUDIT_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$user = wp_get_current_user();
		$entry = array(
			'ts'      => gmdate( 'c' ),
			'tool'    => $tool,
			'ok'      => (bool) $ok,
			'message' => (string) $message,
			'args'    => $args_summary,
			'user_id' => $user && $user->ID ? (int) $user->ID : 0,
			'user'    => $user && $user->ID ? $user->user_login : '',
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
		);
		$entry['sig'] = hash_hmac( 'sha256', wp_json_encode( $entry ), self::audit_hmac_key() );
		$log[] = $entry;
		if ( count( $log ) > self::AUDIT_MAX_ENTRIES ) {
			$log = array_slice( $log, -1 * self::AUDIT_MAX_ENTRIES );
		}
		update_option( self::OPTION_AUDIT_LOG, $log, false );
	}

	/**
	 * Export audit log as CSV string.
	 */
	public static function audit_log_csv( $limit = 200 ) {
		$rows = self::get_audit_log( $limit );
		$out  = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'ts', 'tool', 'ok', 'user', 'user_id', 'message', 'ip', 'sig_valid' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					isset( $row['ts'] ) ? $row['ts'] : '',
					isset( $row['tool'] ) ? $row['tool'] : '',
					! empty( $row['ok'] ) ? '1' : '0',
					isset( $row['user'] ) ? $row['user'] : '',
					isset( $row['user_id'] ) ? $row['user_id'] : '',
					isset( $row['message'] ) ? $row['message'] : '',
					isset( $row['ip'] ) ? $row['ip'] : '',
					self::verify_audit_entry( $row ) ? '1' : '0',
				)
			);
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	public static function get_audit_log( $limit = 50 ) {
		$log = get_option( self::OPTION_AUDIT_LOG, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$limit = max( 1, min( (int) $limit, self::AUDIT_MAX_ENTRIES ) );
		return array_slice( array_reverse( $log ), 0, $limit );
	}

	public static function allowed_option_keys() {
		$mcp = array(
			self::OPTION_ENABLED,
			self::OPTION_ALLOW_WRITES,
			self::OPTION_ENVIRONMENT,
		);
		$core = array(
			'blogname',
			'blogdescription',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'date_format',
			'time_format',
			'timezone_string',
			'start_of_week',
			'permalink_structure',
		);
		/**
		 * Filter allowlisted option keys readable/writable via MCP.
		 *
		 * @param string[] $keys
		 */
		return apply_filters( 'mt_mcp_allowed_option_keys', array_merge( $core, $mcp ) );
	}

	public static function summarize_args( $args ) {
		if ( ! is_array( $args ) ) {
			return array();
		}
		// Identifier-only audit: ids/keys/slugs/status/counts — never bodies, emails, or field values.
		$allow = array(
			'id', 'ids', 'post_id', 'page_id', 'term_id', 'revision_id', 'menu_id', 'attachment_id', 'media_id',
			'post_type', 'taxonomy', 'status', 'post_status', 'slug', 'name', 'location', 'from', 'code',
			'dry_run', 'apply', 'force_delete', 'confirm', 'operation', 'operation_id', 'state_token',
			'limit', 'page', 'per_page', 'mime_type', 'provider', 'mode', 'action', 'scopes', 'expected_count',
			'include_acf', 'include_taxonomies', 'include_featured', 'include_meta', 'ttl', 'max_bytes',
			'pause_writes', 'force_draft', 'allow_non_draft', 'parent', 'parent_post_id', 'group_id',
		);
		$out = array();
		foreach ( $args as $k => $v ) {
			$lk = strtolower( (string) $k );
			if ( preg_match( '/(email|content|password|secret|token|body|acf|yoast|meta|fields|options|items|csv|data_base64|package|core|title|description|alt|url|find|replace|site_brief)/i', $lk )
				&& ! in_array( $lk, array( 'post_id', 'operation_id', 'state_token', 'dry_run', 'force_delete', 'parent_post_id', 'attachment_id', 'media_id', 'revision_id', 'menu_id', 'term_id', 'page_id', 'group_id', 'expected_count', 'max_bytes' ), true )
			) {
				if ( is_array( $v ) ) {
					$out[ $k ] = '[array:' . count( $v ) . ']';
				} elseif ( is_string( $v ) ) {
					$out[ $k ] = '[string ' . strlen( $v ) . ' chars]';
				} else {
					$out[ $k ] = '[redacted]';
				}
				continue;
			}
			if ( in_array( $lk, $allow, true ) || preg_match( '/(_id|ids)$/', $lk ) ) {
				if ( is_scalar( $v ) || null === $v ) {
					$out[ $k ] = $v;
				} elseif ( is_array( $v ) && $v && array_keys( $v ) === range( 0, count( $v ) - 1 ) && is_scalar( $v[0] ) ) {
					$out[ $k ] = array_slice( $v, 0, 20 );
				} else {
					$out[ $k ] = is_array( $v ) ? ( '[array:' . count( $v ) . ']' ) : gettype( $v );
				}
			} elseif ( is_bool( $v ) || is_numeric( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	public static function get_yoast_fields( $post_id ) {
		$keys = array(
			'title', 'metadesc', 'focuskw', 'canonical', 'redirect',
			'meta-robots-noindex', 'meta-robots-nofollow',
			'opengraph-title', 'opengraph-description', 'opengraph-image',
			'twitter-title', 'twitter-description', 'twitter-image',
		);
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = class_exists( 'WPSEO_Meta' )
				? \WPSEO_Meta::get_value( $key, $post_id )
				: get_post_meta( $post_id, '_yoast_wpseo_' . str_replace( '-', '_', $key ), true );
		}
		return $out;
	}

	public static function set_yoast_value( $key, $value, $post_id ) {
		if ( class_exists( 'WPSEO_Meta' ) ) {
			\WPSEO_Meta::set_value( $key, $value, $post_id );
			return;
		}
		update_post_meta( $post_id, '_yoast_wpseo_' . str_replace( '-', '_', $key ), $value );
	}

	public static function normalize_url_to_post_id( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path  = '/' . ltrim( $path, '/' );

		$target  = home_url( $path );
		$post_id = url_to_postid( $target );

		if ( ! $post_id ) {
			$alt     = ( '/' === substr( $target, -1 ) ) ? rtrim( $target, '/' ) : trailingslashit( $target );
			$post_id = url_to_postid( $alt );
		}

		return (int) $post_id;
	}

	/**
	 * Normalize common agent arg aliases onto canonical keys (id, post_id → id, etc.).
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public static function normalize_arg_aliases( $args ) {
		if ( ! is_array( $args ) ) {
			return array();
		}
		$id_aliases = array( 'post_id', 'page_id', 'attachment_id', 'media_id', 'ID' );
		foreach ( $id_aliases as $alias ) {
			if ( empty( $args['id'] ) && ! empty( $args[ $alias ] ) ) {
				$args['id'] = $args[ $alias ];
			}
		}
		if ( empty( $args['revision_id'] ) && ! empty( $args['revision'] ) ) {
			$args['revision_id'] = $args['revision'];
		}
		if ( empty( $args['parent_post_id'] ) && ! empty( $args['parent_id'] ) ) {
			$args['parent_post_id'] = $args['parent_id'];
		}
		return $args;
	}

	public static function resolve_post_id( $args ) {
		$args = self::normalize_arg_aliases( $args );
		if ( ! empty( $args['id'] ) ) {
			return (int) $args['id'];
		}
		if ( ! empty( $args['url'] ) ) {
			$id = self::normalize_url_to_post_id( $args['url'] );
			if ( ! $id ) {
				throw new \Exception( 'URL did not resolve to a post on this site.' );
			}
			return $id;
		}
		throw new \Exception( 'Provide "id", "post_id", "page_id", or "url".' );
	}

	/**
	 * Resolve a nav menu term id from id / slug / location.
	 *
	 * @param array $args Tool arguments.
	 * @return int
	 */
	public static function resolve_menu_id( $args ) {
		$args    = self::normalize_arg_aliases( $args );
		$menu_id = 0;
		if ( ! empty( $args['id'] ) ) {
			$menu_id = (int) $args['id'];
		} elseif ( ! empty( $args['menu_id'] ) ) {
			$menu_id = (int) $args['menu_id'];
		} elseif ( ! empty( $args['slug'] ) ) {
			$menu = wp_get_nav_menu_object( $args['slug'] );
			$menu_id = $menu ? (int) $menu->term_id : 0;
		} elseif ( ! empty( $args['location'] ) ) {
			$locations = get_nav_menu_locations();
			$loc       = sanitize_key( $args['location'] );
			$menu_id   = isset( $locations[ $loc ] ) ? (int) $locations[ $loc ] : 0;
		}
		if ( ! $menu_id ) {
			throw new \Exception( 'Provide menu id, menu_id, slug, or location.' );
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			throw new \Exception( 'Menu not found.' );
		}
		return (int) $menu->term_id;
	}

	public static function is_protected_option_key( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::PROTECTED_OPTION_KEYS, true ) ) {
			return true;
		}
		if ( preg_match( '/(_key|_salt|_secret|_password|auth_key|nonce)$/i', $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Summarize ACF location rules for agents (param/operator/value rows).
	 *
	 * @param array $location Nested ACF location groups.
	 * @return array
	 */
	public static function summarize_acf_location( $location ) {
		if ( ! is_array( $location ) ) {
			return array();
		}
		$groups = array();
		foreach ( $location as $or_group ) {
			if ( ! is_array( $or_group ) ) {
				continue;
			}
			$rows = array();
			foreach ( $or_group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$rows[] = array(
					'param'    => isset( $rule['param'] ) ? $rule['param'] : '',
					'operator' => isset( $rule['operator'] ) ? $rule['operator'] : '==',
					'value'    => isset( $rule['value'] ) ? $rule['value'] : '',
				);
			}
			if ( $rows ) {
				$groups[] = $rows;
			}
		}
		return $groups;
	}

	/**
	 * Compact before/after diff for agent confirmation.
	 */
	public static function diff_values( $before, $after, $path = '' ) {
		$changes = array();
		if ( ! is_array( $before ) || ! is_array( $after ) ) {
			if ( $before !== $after ) {
				$changes[] = array(
					'path'   => $path ?: '(root)',
					'before' => $before,
					'after'  => $after,
				);
			}
			return $changes;
		}
		$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		foreach ( $keys as $key ) {
			$p = $path ? ( $path . '.' . $key ) : (string) $key;
			$b = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			$a = array_key_exists( $key, $after ) ? $after[ $key ] : null;
			if ( is_array( $b ) || is_array( $a ) ) {
				$changes = array_merge( $changes, self::diff_values( (array) $b, (array) $a, $p ) );
			} elseif ( $b !== $a ) {
				$changes[] = array(
					'path'   => $p,
					'before' => is_string( $b ) && strlen( $b ) > 200 ? ( substr( $b, 0, 200 ) . '…' ) : $b,
					'after'  => is_string( $a ) && strlen( $a ) > 200 ? ( substr( $a, 0, 200 ) . '…' ) : $a,
				);
			}
		}
		return $changes;
	}

	public static function describe_acf_field( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}
		$out = array(
			'key'   => isset( $field['key'] ) ? $field['key'] : '',
			'name'  => isset( $field['name'] ) ? $field['name'] : '',
			'label' => isset( $field['label'] ) ? $field['label'] : '',
			'type'  => isset( $field['type'] ) ? $field['type'] : '',
			'required' => ! empty( $field['required'] ),
		);
		foreach ( array( 'instructions', 'default_value', 'choices', 'return_format', 'layout', 'button_label', 'min', 'max' ) as $extra ) {
			if ( isset( $field[ $extra ] ) && '' !== $field[ $extra ] && null !== $field[ $extra ] ) {
				$out[ $extra ] = $field[ $extra ];
			}
		}
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$out['sub_fields'] = array();
			foreach ( $field['sub_fields'] as $sub ) {
				$described = self::describe_acf_field( $sub );
				if ( $described ) {
					$out['sub_fields'][] = $described;
				}
			}
		}
		if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$layout_out = array(
					'key'   => isset( $layout['key'] ) ? $layout['key'] : '',
					'name'  => isset( $layout['name'] ) ? $layout['name'] : '',
					'label' => isset( $layout['label'] ) ? $layout['label'] : '',
					'sub_fields' => array(),
				);
				if ( ! empty( $layout['sub_fields'] ) ) {
					foreach ( $layout['sub_fields'] as $sub ) {
						$described = self::describe_acf_field( $sub );
						if ( $described ) {
							$layout_out['sub_fields'][] = $described;
						}
					}
				}
				$out['layouts'][] = $layout_out;
			}
		}
		return $out;
	}

	public static function active_plugins_summary() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		$all    = get_plugins();
		$out    = array();
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$out[] = array(
					'file'    => $file,
					'name'    => $all[ $file ]['Name'],
					'version' => $all[ $file ]['Version'],
				);
			}
		}
		return $out;
	}


	public static function writes_paused() {
		return (bool) get_option( self::OPTION_PAUSE_WRITES );
	}

	public static function force_draft_enabled() {
		return (bool) get_option( self::OPTION_FORCE_DRAFT );
	}

	public static function site_brief() {
		$brief = (string) get_option( self::OPTION_SITE_BRIEF, '' );
		return trim( $brief );
	}

	/**
	 * Redact secrets from diagnostic strings / arrays.
	 *
	 * @param mixed $value Value to redact.
	 * @return mixed
	 */
	public static function redact_secrets( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$lk = strtolower( (string) $k );
				if ( preg_match( '/(password|secret|token|salt|auth_key|nonce_key|hmac|api_key|authorization)/i', $lk ) ) {
					$out[ $k ] = '[redacted]';
				} else {
					$out[ $k ] = self::redact_secrets( $v );
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$value = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $value );
			$value = preg_replace( '/(?i)(password|secret|token|api[_-]?key)\s*[:=]\s*\S+/', '$1=[redacted]', $value );
		}
		return $value;
	}


	public static function assert_can_edit_post( $post_id ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \Exception( 'Current user cannot edit_post on id ' . $post_id . '.' );
		}
		return $post;
	}

	public static function assert_can_read_post( $post_id ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		// Public published content is readable without caps.
		if ( 'publish' === $post->post_status && empty( $post->post_password ) ) {
			return $post;
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			throw new \Exception( 'Current user cannot read_post on id ' . $post_id . '.' );
		}
		return $post;
	}

	public static function assert_can_delete_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			throw new \Exception( 'Post not found.' );
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			throw new \Exception( 'Current user cannot delete_post on id ' . $post_id . '.' );
		}
	}

	public static function assert_can_edit_term( $term_id, $taxonomy = '' ) {
		$term = $taxonomy ? get_term( (int) $term_id, $taxonomy ) : get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new \Exception( 'Term not found.' );
		}
		$tax = get_taxonomy( $term->taxonomy );
		$cap = ( $tax && ! empty( $tax->cap->edit_terms ) ) ? $tax->cap->edit_terms : 'manage_categories';
		if ( ! current_user_can( $cap ) ) {
			throw new \Exception( 'Current user lacks taxonomy capability "' . $cap . '".' );
		}
		return $term;
	}

	public static function get_op_receipt( $operation_id ) {
		$operation_id = sanitize_text_field( (string) $operation_id );
		if ( '' === $operation_id ) {
			return null;
		}
		$all = get_option( self::OPTION_OP_RECEIPTS, array() );
		if ( ! is_array( $all ) || empty( $all[ $operation_id ] ) ) {
			return null;
		}
		return $all[ $operation_id ];
	}

	public static function store_op_receipt( $operation_id, $tool, $result ) {
		$operation_id = sanitize_text_field( (string) $operation_id );
		if ( '' === $operation_id ) {
			return;
		}
		$all = get_option( self::OPTION_OP_RECEIPTS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $operation_id ] = array(
			'operation_id' => $operation_id,
			'tool'         => $tool,
			'ts'           => gmdate( 'c' ),
			'user_id'      => get_current_user_id(),
			'result'       => $result,
		);
		// Cap ring buffer.
		if ( count( $all ) > 100 ) {
			$all = array_slice( $all, -100, null, true );
		}
		update_option( self::OPTION_OP_RECEIPTS, $all, false );
	}

	public static function get_connections() {
		$stored = get_option( self::OPTION_CONNECTIONS, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function save_connections( $connections ) {
		update_option( self::OPTION_CONNECTIONS, is_array( $connections ) ? $connections : array(), false );
	}

	public static function dcr_extra_hosts() {
		$raw = (string) get_option( self::OPTION_DCR_HOSTS, '' );
		$hosts = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ) ) );
		/**
		 * Filter additional hosts allowed for DCR redirect_uris (beyond https / localhost).
		 *
		 * @param string[] $hosts
		 */
		return apply_filters( 'mt_mcp_dcr_extra_hosts', array_values( $hosts ) );
	}

	public static function redirects_backend() {
		if ( class_exists( 'Red_Item' ) ) {
			return 'redirection';
		}
		if ( class_exists( 'WPSEO_Redirect_Manager' ) || get_option( 'wpseo-premium-redirects-base' ) !== false ) {
			return 'yoast';
		}
		return null;
	}

	/**
	 * SSRF guard for remote media sideloads: https only, no redirects to private nets, no loopback/link-local.
	 *
	 * @param string $url Remote URL.
	 * @throws \Exception When unsafe.
	 * @return string Sanitized URL.
	 */
	public static function assert_safe_remote_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			throw new \Exception( 'Invalid URL.' );
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new \Exception( 'URL must include scheme and host.' );
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			throw new \Exception( 'Only https URLs are allowed for remote sideload.' );
		}
		$host = strtolower( $parts['host'] );
		if ( in_array( $host, array( 'localhost', 'metadata.google.internal' ), true ) ) {
			throw new \Exception( 'Refusing private/metadata host.' );
		}
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$resolved = gethostbynamel( $host );
			if ( is_array( $resolved ) ) {
				$ips = $resolved;
			}
		}
		foreach ( $ips as $ip ) {
			if ( self::is_private_ip( $ip ) ) {
				throw new \Exception( 'Refusing URL that resolves to a private/reserved IP.' );
			}
		}
		return $url;
	}

	public static function is_private_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	public static function is_undoable_tool( $tool ) {
		return in_array( $tool, self::UNDOABLE_TOOLS, true );
	}

	/**
	 * Pack a snapshot for storage (gz + base64). Never used for OAuth/MCP secrets.
	 *
	 * @param mixed $data Snapshot array.
	 * @return string
	 */
	public static function pack_snapshot( $data ) {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return '';
		}
		if ( function_exists( 'gzcompress' ) ) {
			$gz = gzcompress( $json, 6 );
			if ( false !== $gz ) {
				return 'gz:' . base64_encode( $gz );
			}
		}
		return 'raw:' . base64_encode( $json );
	}

	/**
	 * @param string $packed Packed snapshot.
	 * @return array|null
	 */
	public static function unpack_snapshot( $packed ) {
		$packed = (string) $packed;
		if ( '' === $packed ) {
			return null;
		}
		if ( 0 === strpos( $packed, 'gz:' ) ) {
			$bin = base64_decode( substr( $packed, 3 ), true );
			if ( false === $bin || ! function_exists( 'gzuncompress' ) ) {
				return null;
			}
			$json = @gzuncompress( $bin );
		} elseif ( 0 === strpos( $packed, 'raw:' ) ) {
			$json = base64_decode( substr( $packed, 4 ), true );
		} else {
			$json = $packed;
		}
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	public static function snapshot_hash( $snap ) {
		if ( ! is_array( $snap ) ) {
			return '';
		}
		return md5( wp_json_encode( $snap ) );
	}

	/**
	 * Full post snapshot for undo. Does not include users, OAuth, or MCP options.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function snapshot_post( $post_id ) {
		$post_id = (int) $post_id;
		$p       = get_post( $post_id );
		if ( ! $p ) {
			return null;
		}
		$tax = array();
		foreach ( get_object_taxonomies( $p->post_type, 'names' ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$tax[ $taxonomy ] = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
		}
		$acf = array();
		if ( function_exists( 'get_fields' ) ) {
			$fields = get_fields( $post_id );
			$acf    = is_array( $fields ) ? $fields : array();
		}
		return array(
			'kind'              => 'post',
			'id'                => $post_id,
			'core'              => array(
				'post_title'   => $p->post_title,
				'post_content' => $p->post_content,
				'post_excerpt' => $p->post_excerpt,
				'post_status'  => $p->post_status,
				'post_name'    => $p->post_name,
				'post_parent'  => (int) $p->post_parent,
				'menu_order'   => (int) $p->menu_order,
				'post_type'    => $p->post_type,
			),
			'featured_image_id' => (int) get_post_thumbnail_id( $post_id ),
			'taxonomies'        => $tax,
			'yoast'             => self::get_yoast_fields( $post_id ),
			'acf'               => $acf,
			'needs_review'      => (bool) get_post_meta( $post_id, '_mt_mcp_needs_review', true ),
		);
	}

	/**
	 * Media attachment snapshot (alt/title/caption/description only).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public static function snapshot_media( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$p             = get_post( $attachment_id );
		if ( ! $p || 'attachment' !== $p->post_type ) {
			return null;
		}
		return array(
			'kind'        => 'media',
			'id'          => $attachment_id,
			'title'       => $p->post_title,
			'caption'     => $p->post_excerpt,
			'description' => $p->post_content,
			'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * @param string $tool Tool name.
	 * @param array  $args Normalized tool args.
	 * @return array|null Before envelope: action + before snap (or null for creates).
	 */
	public static function capture_undo_before( $tool, $args ) {
		if ( ! self::is_undoable_tool( $tool ) ) {
			return null;
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return null;
		}
		switch ( $tool ) {
			case 'create_post':
			case 'duplicate_post':
				return array(
					'action' => 'create',
					'before' => null,
					'label'  => isset( $args['post_title'] ) ? (string) $args['post_title'] : ( isset( $args['core']['post_title'] ) ? (string) $args['core']['post_title'] : 'new post' ),
				);
			case 'update_post':
			case 'restore_revision':
				$id = self::resolve_post_id( $args );
				if ( ! $id ) {
					return null;
				}
				$snap = self::snapshot_post( $id );
				return $snap ? array(
					'action' => 'update',
					'before' => $snap,
					'label'  => $snap['core']['post_title'],
					'object' => array( 'type' => 'post', 'id' => $id ),
				) : null;
			case 'trash_post':
				if ( ! empty( $args['force_delete'] ) ) {
					return null; // Permanent delete is not undoable.
				}
				$id = self::resolve_post_id( $args );
				if ( ! $id ) {
					return null;
				}
				$snap = self::snapshot_post( $id );
				return $snap ? array(
					'action' => 'trash',
					'before' => $snap,
					'label'  => $snap['core']['post_title'],
					'object' => array( 'type' => 'post', 'id' => $id ),
				) : null;
			case 'update_media':
				$id = 0;
				foreach ( array( 'id', 'attachment_id', 'media_id' ) as $k ) {
					if ( ! empty( $args[ $k ] ) ) {
						$id = (int) $args[ $k ];
						break;
					}
				}
				if ( ! $id ) {
					return null;
				}
				$snap = self::snapshot_media( $id );
				return $snap ? array(
					'action' => 'update',
					'before' => $snap,
					'label'  => $snap['title'] ? $snap['title'] : ( 'attachment #' . $id ),
					'object' => array( 'type' => 'media', 'id' => $id ),
				) : null;
			case 'update_options_page':
				$page = isset( $args['page'] ) ? (string) $args['page'] : ( isset( $args['options_page'] ) ? (string) $args['options_page'] : '' );
				if ( ! $page || ! function_exists( 'get_fields' ) ) {
					return null;
				}
				$fields = get_fields( 'option' );
				// Prefer page-scoped if ACF supports it; fall back to all options fields.
				if ( function_exists( 'acf_get_options_page' ) ) {
					$page_fields = get_fields( $page );
					if ( is_array( $page_fields ) ) {
						$fields = $page_fields;
					}
				}
				return array(
					'action' => 'update',
					'before' => array(
						'kind'   => 'options_page',
						'page'   => $page,
						'fields' => is_array( $fields ) ? $fields : array(),
					),
					'label'  => 'options:' . $page,
					'object' => array( 'type' => 'options_page', 'id' => $page ),
				);
			default:
				return null;
		}
	}

	/**
	 * Record a change after a successful write. Returns undo envelope for the tool response.
	 *
	 * @param string     $tool Tool name.
	 * @param array|null $before_envelope From capture_undo_before.
	 * @param array      $result Tool result.
	 * @return array|null undo { token, expires_at }
	 */
	public static function record_change( $tool, $before_envelope, $result ) {
		if ( ! is_array( $before_envelope ) || ! is_array( $result ) ) {
			return null;
		}
		if ( ! empty( $result['dry_run'] ) || ! empty( $result['state_token'] ) || ! empty( $result['needs_confirmation'] ) ) {
			return null;
		}

		$written = null;
		$object  = isset( $before_envelope['object'] ) ? $before_envelope['object'] : null;
		$action  = isset( $before_envelope['action'] ) ? $before_envelope['action'] : 'update';

		if ( in_array( $tool, array( 'create_post', 'duplicate_post' ), true ) && ! empty( $result['id'] ) ) {
			$written = self::snapshot_post( (int) $result['id'] );
			$object  = array( 'type' => 'post', 'id' => (int) $result['id'] );
			$action  = 'create';
		} elseif ( 'update_post' === $tool || 'restore_revision' === $tool ) {
			$id      = ! empty( $result['id'] ) ? (int) $result['id'] : ( isset( $object['id'] ) ? (int) $object['id'] : 0 );
			$written = $id ? self::snapshot_post( $id ) : null;
		} elseif ( 'trash_post' === $tool && empty( $result['deleted'] ) ) {
			$id      = isset( $result['id'] ) ? (int) $result['id'] : ( isset( $object['id'] ) ? (int) $object['id'] : 0 );
			$written = array( 'kind' => 'post', 'id' => $id, 'trashed' => true );
		} elseif ( 'update_media' === $tool ) {
			$id      = ! empty( $result['id'] ) ? (int) $result['id'] : ( isset( $object['id'] ) ? (int) $object['id'] : 0 );
			$written = $id ? self::snapshot_media( $id ) : null;
		} elseif ( 'update_options_page' === $tool && is_array( $before_envelope['before'] ) ) {
			$page    = $before_envelope['before']['page'];
			$fields  = function_exists( 'get_fields' ) ? get_fields( $page ) : null;
			if ( ! is_array( $fields ) && function_exists( 'get_fields' ) ) {
				$fields = get_fields( 'option' );
			}
			$written = array(
				'kind'   => 'options_page',
				'page'   => $page,
				'fields' => is_array( $fields ) ? $fields : array(),
			);
		}

		if ( ! $written && 'create' !== $action ) {
			return null;
		}

		$token   = 'und_' . bin2hex( random_bytes( 12 ) );
		$expires = time() + self::UNDO_TTL_SECONDS;
		$entry   = array(
			'token'       => $token,
			'tool'        => $tool,
			'action'      => $action,
			'ts'          => gmdate( 'c' ),
			'expires_at'  => gmdate( 'c', $expires ),
			'expires_ts'  => $expires,
			'user_id'     => get_current_user_id(),
			'label'       => isset( $before_envelope['label'] ) ? (string) $before_envelope['label'] : $tool,
			'object'      => $object,
			'before_pack' => self::pack_snapshot( $before_envelope['before'] ),
			'written_pack'=> self::pack_snapshot( $written ),
			'after_hash'  => self::snapshot_hash( $written ),
			'undone'      => false,
		);

		$journal = get_option( self::OPTION_UNDO_JOURNAL, array() );
		if ( ! is_array( $journal ) ) {
			$journal = array();
		}
		$journal[ $token ] = $entry;
		$journal = self::prune_undo_journal( $journal );
		update_option( self::OPTION_UNDO_JOURNAL, $journal, false );

		return array(
			'token'      => $token,
			'expires_at' => $entry['expires_at'],
		);
	}

	public static function prune_undo_journal( $journal ) {
		$now = time();
		$out = array();
		foreach ( $journal as $token => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$exp = isset( $entry['expires_ts'] ) ? (int) $entry['expires_ts'] : 0;
			if ( $exp && $exp < $now ) {
				continue;
			}
			$out[ $token ] = $entry;
		}
		if ( count( $out ) > self::UNDO_MAX_ENTRIES ) {
			uasort(
				$out,
				static function ( $a, $b ) {
					return strcmp( isset( $a['ts'] ) ? $a['ts'] : '', isset( $b['ts'] ) ? $b['ts'] : '' );
				}
			);
			$out = array_slice( $out, -self::UNDO_MAX_ENTRIES, null, true );
		}
		return $out;
	}

	public static function get_undo_journal( $limit = 50 ) {
		$journal = get_option( self::OPTION_UNDO_JOURNAL, array() );
		if ( ! is_array( $journal ) ) {
			return array();
		}
		$journal = self::prune_undo_journal( $journal );
		update_option( self::OPTION_UNDO_JOURNAL, $journal, false );
		$rows = array_values( $journal );
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( isset( $b['ts'] ) ? $b['ts'] : '', isset( $a['ts'] ) ? $a['ts'] : '' );
			}
		);
		$limit = max( 1, min( (int) $limit, self::UNDO_MAX_ENTRIES ) );
		return array_slice( $rows, 0, $limit );
	}

	public static function get_change( $token ) {
		$token   = sanitize_text_field( (string) $token );
		$journal = get_option( self::OPTION_UNDO_JOURNAL, array() );
		if ( ! is_array( $journal ) || empty( $journal[ $token ] ) ) {
			return null;
		}
		$entry = $journal[ $token ];
		if ( ! empty( $entry['expires_ts'] ) && (int) $entry['expires_ts'] < time() ) {
			return null;
		}
		return $entry;
	}

	/**
	 * Public summary of a change for list/UI (no packed blobs).
	 *
	 * @param array $entry Journal entry.
	 * @param bool  $include_snaps Include unpacked before/written/now.
	 * @return array
	 */
	public static function summarize_change( $entry, $include_snaps = false ) {
		$before  = self::unpack_snapshot( isset( $entry['before_pack'] ) ? $entry['before_pack'] : '' );
		$written = self::unpack_snapshot( isset( $entry['written_pack'] ) ? $entry['written_pack'] : '' );
		$now     = null;
		$drift   = false;
		if ( is_array( $written ) && isset( $written['kind'] ) ) {
			if ( 'post' === $written['kind'] && ! empty( $written['id'] ) ) {
				$now = self::snapshot_post( (int) $written['id'] );
			} elseif ( 'media' === $written['kind'] && ! empty( $written['id'] ) ) {
				$now = self::snapshot_media( (int) $written['id'] );
			} elseif ( 'options_page' === $written['kind'] && ! empty( $written['page'] ) && function_exists( 'get_fields' ) ) {
				$fields = get_fields( $written['page'] );
				if ( ! is_array( $fields ) ) {
					$fields = get_fields( 'option' );
				}
				$now = array(
					'kind'   => 'options_page',
					'page'   => $written['page'],
					'fields' => is_array( $fields ) ? $fields : array(),
				);
			}
			if ( $now && ! empty( $entry['after_hash'] ) ) {
				$drift = self::snapshot_hash( $now ) !== $entry['after_hash'];
			}
		}
		$user = ! empty( $entry['user_id'] ) ? get_userdata( (int) $entry['user_id'] ) : null;
		$out  = array(
			'token'      => $entry['token'],
			'tool'       => $entry['tool'],
			'action'     => $entry['action'],
			'ts'         => $entry['ts'],
			'expires_at' => $entry['expires_at'],
			'label'      => $entry['label'],
			'object'     => isset( $entry['object'] ) ? $entry['object'] : null,
			'user'       => $user ? $user->user_login : '',
			'undone'     => ! empty( $entry['undone'] ),
			'drift'      => $drift,
		);
		if ( $include_snaps ) {
			$out['before']  = $before;
			$out['written'] = $written;
			$out['now']     = $now;
		}
		return $out;
	}

	/**
	 * Three-gate status for admin / doctor: Enable ∩ tool groups ∩ write ceiling ∩ user caps.
	 *
	 * @return array
	 */
	public static function three_gate_status() {
		return array(
			'server_enabled' => (bool) get_option( self::OPTION_ENABLED ),
			'allow_writes'   => (bool) get_option( self::OPTION_ALLOW_WRITES ),
			'pause_writes'   => self::writes_paused(),
			'tool_groups'    => self::enabled_groups(),
			'note'           => 'Dispatch requires: Enable MCP ∩ tool group allowlist ∩ read/write ceiling (OAuth scope + Allow writes) ∩ current_user_can (+ object-level caps).',
		);
	}
}
