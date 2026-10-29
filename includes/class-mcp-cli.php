<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WP-CLI commands for MT MCP.
 *
 * ## EXAMPLES
 *
 *     wp mt-mcp status
 *     wp mt-mcp revoke-user 3
 *     wp mt-mcp flush
 */
class McpCli {

	/**
	 * Show MCP status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mt-mcp status
	 *
	 * @when after_wp_load
	 */
	public function status() {
		\WP_CLI::line( 'MT MCP v' . MT_MCP_VERSION );
		\WP_CLI::line( 'Enabled:     ' . ( get_option( McpSupport::OPTION_ENABLED ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Writes:      ' . ( get_option( McpSupport::OPTION_ALLOW_WRITES ) ? 'yes' : 'no' ) );
		\WP_CLI::line( 'Environment: ' . McpSupport::get_environment() );
		\WP_CLI::line( 'Auth mode:   ' . McpAuth::get_auth_mode() );
		\WP_CLI::line( 'MCP URL:     ' . home_url( '/mt-mcp' ) );
		\WP_CLI::line( 'Health:      ' . rest_url( 'mt-mcp/v1/health' ) );
		$grants = McpOAuth::list_active_grants();
		\WP_CLI::line( 'OAuth grants: ' . count( $grants ) );
	}

	/**
	 * Revoke all OAuth tokens for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID
	 *
	 * ## EXAMPLES
	 *
	 *     wp mt-mcp revoke-user 3
	 *
	 * @when after_wp_load
	 * @subcommand revoke-user
	 */
	public function revoke_user( $args ) {
		$user_id = (int) $args[0];
		if ( ! get_userdata( $user_id ) ) {
			\WP_CLI::error( 'User not found.' );
		}
		McpOAuth::revoke_all_for_user( $user_id );
		\WP_CLI::success( "Revoked OAuth tokens for user {$user_id}." );
	}

	/**
	 * Flush MCP rewrite rules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mt-mcp flush
	 *
	 * @when after_wp_load
	 */
	public function flush() {
		McpServer::flush_rewrites_now();
		\WP_CLI::success( 'Flushed MCP rewrite rules.' );
	}

	/**
	 * Show armed update dead-man pending (alias of mt-mcp-guard pending).
	 *
	 * When the site is 500ing, prefer:
	 *   wp --skip-plugins mt-mcp-guard pending
	 *
	 * @when after_wp_load
	 * @subcommand updates-pending
	 */
	public function updates_pending() {
		McpUpdates::ensure_guard_mu_plugin();
		$p = McpUpdates::read_pending();
		if ( ! $p ) {
			\WP_CLI::success( 'No pending dead-man rollback armed.' );
			return;
		}
		$left = (int) $p['deadline_ts'] - time();
		\WP_CLI::line( 'session:  ' . ( isset( $p['session_id'] ) ? $p['session_id'] : '' ) );
		\WP_CLI::line( 'plugin:   ' . ( isset( $p['id'] ) ? $p['id'] : '' ) );
		\WP_CLI::line( 'zip:      ' . ( isset( $p['zip'] ) ? $p['zip'] : '' ) );
		\WP_CLI::line( 'deadline: ' . ( isset( $p['deadline'] ) ? $p['deadline'] : '' ) . ' (' . $left . 's left)' );
		\WP_CLI::line( 'If MCP is down use: wp --skip-plugins mt-mcp-guard rollback' );
	}

	/**
	 * Roll back a plugin from an update session snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [--session=<id>]
	 * : Update session id.
	 *
	 * [--id=<plugin>]
	 * : Plugin file e.g. akismet/akismet.php
	 *
	 * [--yes]
	 * : Skip confirm.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mt-mcp rollback --session=upd_abc --id=eps-301-redirects/eps-301-redirects.php
	 *
	 * When HTTP is dead (plugin fatals), the MU-plugin CLI still works:
	 *     wp --skip-plugins mt-mcp-guard rollback
	 *
	 * @when after_wp_load
	 */
	public function rollback( $args, $assoc ) {
		McpUpdates::ensure_guard_mu_plugin();
		if ( function_exists( 'mt_mcp_guard_read_pending' ) ) {
			// Prefer guard implementation (same as --skip-plugins path).
			$cli = new \MT_MCP_Guard_CLI();
			$cli->rollback( $args, $assoc );
			return;
		}
		\WP_CLI::error( 'Update guard MU-plugin not installed. Enable Allow MCP updates once, or copy mu-plugins/mt-mcp-update-guard.php into wp-content/mu-plugins/.' );
	}
}

\WP_CLI::add_command( 'mt-mcp', __NAMESPACE__ . '\\McpCli' );
