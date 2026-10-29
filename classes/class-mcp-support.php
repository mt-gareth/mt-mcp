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
	const AUDIT_MAX_ENTRIES   = 200;
	const RATE_LIMIT_PER_MIN  = 120;
	const AUDIT_HMAC_OPTION   = 'mt_mcp_audit_hmac_key';

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
		'confirm_dangerous_op',
		'create_redirect',
		'update_redirect',
		'delete_redirect',
		'import_post',
		'import_posts',
		'restore_revision',
		'notify_write_batch',
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
			'prepare_media_upload'   => 'media',
			'acf_media_audit'        => 'media',
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
			'get_site_health'        => 'system',
			'connection_doctor'      => 'system',
			'set_write_gates'        => 'system',
			'confirm_dangerous_op'   => 'system',
			'generate_cursor_skill'  => 'system',
			'notify_write_batch'     => 'system',
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
		$out = array();
		foreach ( $args as $k => $v ) {
			if ( in_array( $k, array( 'content', 'post_content', 'acf', 'core' ), true ) ) {
				$out[ $k ] = is_string( $v ) ? ('[string ' . strlen( $v ) . ' chars]') : '[complex]';
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = $v;
			} else {
				$out[ $k ] = is_array( $v ) ? ( '[array:' . count( $v ) . ']' ) : gettype( $v );
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
}
