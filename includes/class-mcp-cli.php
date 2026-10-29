<?php

namespace MT\Mcp;

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
}

\WP_CLI::add_command( 'mt-mcp', __NAMESPACE__ . '\\McpCli' );
