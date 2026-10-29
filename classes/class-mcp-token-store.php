<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom-table storage for OAuth clients, auth codes, and tokens.
 * Scales past wp_options for busy sites.
 */
class McpTokenStore {

	const DB_VERSION = 1;
	const OPTION_DB_VERSION = 'mt_mcp_token_db_version';

	public static function table( $which ) {
		global $wpdb;
		$map = array(
			'clients' => $wpdb->prefix . 'mt_mcp_oauth_clients',
			'codes'   => $wpdb->prefix . 'mt_mcp_oauth_codes',
			'tokens'  => $wpdb->prefix . 'mt_mcp_oauth_tokens',
		);
		return isset( $map[ $which ] ) ? $map[ $which ] : '';
	}

	public static function maybe_install() {
		$ver = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $ver < self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$clients = self::table( 'clients' );
		$codes   = self::table( 'codes' );
		$tokens  = self::table( 'tokens' );

		$sql_clients = "CREATE TABLE {$clients} (
			client_id varchar(64) NOT NULL,
			client_secret varchar(255) DEFAULT NULL,
			client_name varchar(191) NOT NULL DEFAULT '',
			redirect_uris longtext NOT NULL,
			token_endpoint_auth_method varchar(32) NOT NULL DEFAULT 'none',
			scope varchar(191) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (client_id)
		) {$charset};";

		$sql_codes = "CREATE TABLE {$codes} (
			code_hash char(64) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			redirect_uri text NOT NULL,
			code_challenge varchar(128) NOT NULL,
			scope varchar(191) NOT NULL DEFAULT '',
			resource text NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (code_hash),
			KEY expires_at (expires_at)
		) {$charset};";

		$sql_tokens = "CREATE TABLE {$tokens} (
			token_hash char(64) NOT NULL,
			is_refresh tinyint(1) NOT NULL DEFAULT 0,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(191) NOT NULL DEFAULT '',
			resource text NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (token_hash),
			KEY user_id (user_id),
			KEY client_id (client_id),
			KEY expires_at (expires_at)
		) {$charset};";

		dbDelta( $sql_clients );
		dbDelta( $sql_codes );
		dbDelta( $sql_tokens );

		self::migrate_from_options();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * One-time import of tokens previously stored in options.
	 */
	private static function migrate_from_options() {
		global $wpdb;
		$clients_opt = get_option( 'mt_mcp_oauth_clients', null );
		if ( is_array( $clients_opt ) && $clients_opt ) {
			foreach ( $clients_opt as $id => $c ) {
				self::upsert_client( $c );
			}
			delete_option( 'mt_mcp_oauth_clients' );
		}

		$tokens_opt = get_option( 'mt_mcp_oauth_tokens', null );
		if ( is_array( $tokens_opt ) && $tokens_opt ) {
			$table = self::table( 'tokens' );
			foreach ( $tokens_opt as $hash => $row ) {
				$wpdb->replace(
					$table,
					array(
						'token_hash'  => $hash,
						'is_refresh'  => ! empty( $row['is_refresh'] ) ? 1 : 0,
						'client_id'   => $row['client_id'],
						'user_id'     => (int) $row['user_id'],
						'scope'       => isset( $row['scope'] ) ? $row['scope'] : '',
						'resource'    => isset( $row['resource'] ) ? $row['resource'] : '',
						'expires_at'  => (int) $row['expires'],
						'created_at'  => time(),
					),
					array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d' )
				);
			}
			delete_option( 'mt_mcp_oauth_tokens' );
		}

		delete_option( 'mt_mcp_oauth_codes' );
	}

	/* ---- Clients ---- */

	public static function upsert_client( $client ) {
		global $wpdb;
		$wpdb->replace(
			self::table( 'clients' ),
			array(
				'client_id'                  => $client['client_id'],
				'client_secret'              => isset( $client['client_secret'] ) ? $client['client_secret'] : null,
				'client_name'                => isset( $client['client_name'] ) ? $client['client_name'] : '',
				'redirect_uris'              => wp_json_encode( isset( $client['redirect_uris'] ) ? $client['redirect_uris'] : array() ),
				'token_endpoint_auth_method' => isset( $client['token_endpoint_auth_method'] ) ? $client['token_endpoint_auth_method'] : 'none',
				'scope'                      => isset( $client['scope'] ) ? $client['scope'] : '',
				'created_at'                 => isset( $client['client_id_issued_at'] ) ? (int) $client['client_id_issued_at'] : time(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	public static function get_client( $client_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'clients' ) . ' WHERE client_id = %s', $client_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['redirect_uris'] = json_decode( $row['redirect_uris'], true );
		if ( ! is_array( $row['redirect_uris'] ) ) {
			$row['redirect_uris'] = array();
		}
		$row['client_id_issued_at'] = (int) $row['created_at'];
		return $row;
	}

	/* ---- Auth codes ---- */

	public static function store_code( $code_hash, $data ) {
		global $wpdb;
		self::prune_expired_codes();
		$wpdb->replace(
			self::table( 'codes' ),
			array(
				'code_hash'      => $code_hash,
				'client_id'      => $data['client_id'],
				'user_id'        => (int) $data['user_id'],
				'redirect_uri'   => $data['redirect_uri'],
				'code_challenge' => $data['code_challenge'],
				'scope'          => $data['scope'],
				'resource'       => $data['resource'],
				'expires_at'     => (int) $data['expires'],
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	public static function consume_code( $code_hash ) {
		global $wpdb;
		$table = self::table( 'codes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s", $code_hash ), ARRAY_A );
		if ( $row ) {
			$wpdb->delete( $table, array( 'code_hash' => $code_hash ), array( '%s' ) );
			$row['expires'] = (int) $row['expires_at'];
		}
		return $row;
	}

	public static function prune_expired_codes() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'codes' ) . ' WHERE expires_at < %d', time() ) );
	}

	/* ---- Tokens ---- */

	public static function store_token( $token_hash, $data ) {
		global $wpdb;
		$wpdb->replace(
			self::table( 'tokens' ),
			array(
				'token_hash' => $token_hash,
				'is_refresh' => ! empty( $data['is_refresh'] ) ? 1 : 0,
				'client_id'  => $data['client_id'],
				'user_id'    => (int) $data['user_id'],
				'scope'      => $data['scope'],
				'resource'   => isset( $data['resource'] ) ? $data['resource'] : '',
				'expires_at' => (int) $data['expires'],
				'created_at' => time(),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d' )
		);
	}

	public static function get_token( $token_hash ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'tokens' ) . ' WHERE token_hash = %s', $token_hash ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['expires']    = (int) $row['expires_at'];
		$row['is_refresh'] = (bool) $row['is_refresh'];
		$row['user_id']    = (int) $row['user_id'];
		return $row;
	}

	public static function delete_token( $token_hash ) {
		global $wpdb;
		$wpdb->delete( self::table( 'tokens' ), array( 'token_hash' => $token_hash ), array( '%s' ) );
	}

	public static function prune_expired_tokens() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'tokens' ) . ' WHERE expires_at < %d', time() ) );
	}

	public static function list_active_refresh_grants() {
		global $wpdb;
		$table = self::table( 'tokens' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_refresh = 1 AND expires_at >= %d ORDER BY created_at DESC LIMIT 200",
				time()
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$client = self::get_client( $row['client_id'] );
			$user   = get_userdata( (int) $row['user_id'] );
			$client_id   = $row['client_id'];
			$client_name = $client ? $client['client_name'] : $row['client_id'];
			$out[]       = array(
				'hash'        => substr( $row['token_hash'], 0, 12 ),
				'token_hash'  => $row['token_hash'],
				'user_id'     => (int) $row['user_id'],
				'user_login'  => $user ? $user->user_login : '(deleted)',
				'client_id'   => $client_id,
				'client_name' => $client_name,
				'label'       => McpSupport::get_connection_label( (int) $row['user_id'], $client_id ),
				'scope'       => $row['scope'],
				'expires'     => gmdate( 'c', (int) $row['expires_at'] ),
			);
		}
		return $out;
	}

	public static function revoke_by_hash_prefix( $prefix ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash LIKE %s LIMIT 1", $wpdb->esc_like( $prefix ) . '%' ),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}
		$wpdb->delete(
			$table,
			array(
				'client_id' => $row['client_id'],
				'user_id'   => (int) $row['user_id'],
			),
			array( '%s', '%d' )
		);
	}

	public static function revoke_all_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'tokens' ), array( 'user_id' => (int) $user_id ), array( '%d' ) );
	}
}
