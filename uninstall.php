<?php
/**
 * Uninstall MT MCP.
 *
 * Removes options, OAuth tables, and optional MU guard. Does not revoke
 * WordPress Application Passwords — remove those under Users → Profile.
 *
 * @package MT_Mcp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'mt_mcp_enabled',
	'mt_mcp_allow_writes',
	'mt_mcp_flushed',
	'mt_mcp_environment',
	'mt_mcp_tool_groups',
	'mt_mcp_auth_mode',
	'mt_mcp_webhook_url',
	'mt_mcp_audit_log',
	'mt_mcp_audit_hmac_key',
	'mt_mcp_site_brief',
	'mt_mcp_pause_writes',
	'mt_mcp_force_draft',
	'mt_mcp_allow_high_risk',
	'mt_mcp_allow_updates',
	'mt_mcp_allow_docs_write',
	'mt_mcp_agents_md',
	'mt_mcp_changelog_md',
	'mt_mcp_meta_allowlist',
	'mt_mcp_dcr_extra_hosts',
	'mt_mcp_undo_journal',
	'mt_mcp_connections',
	'mt_mcp_op_receipts',
	'mt_mcp_update_sessions',
	'mt_mcp_oauth_clients',
	'mt_mcp_oauth_tokens',
	'mt_mcp_oauth_codes',
	'mt_mcp_connection_labels',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// OAuth tables (prefix-safe).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mt_mcp_oauth_clients" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mt_mcp_oauth_codes" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mt_mcp_oauth_tokens" );

$mu = WPMU_PLUGIN_DIR . '/mt-mcp-update-guard.php';
if ( file_exists( $mu ) && is_writable( $mu ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $mu );
}

$guard_dir = WP_CONTENT_DIR . '/mt-mcp-guard';
if ( is_dir( $guard_dir ) ) {
	$files = glob( $guard_dir . '/*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $file );
			}
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $guard_dir );
}
