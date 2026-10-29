<?php
/**
 * Plugin Name:       MT MCP
 * Plugin URI:        https://github.com/mt-gareth/mt-mcp
 * Description:       Standalone Model Context Protocol (MCP) server for WordPress. OAuth 2.1 / Application Password user login — no shared API keys.
 * Version:           1.3.18
 * Requires at least: 6.1
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Motion Tactic
 * Author URI:        https://motiontactic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mt-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/mt-gareth/mt-mcp
 */

namespace MT\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MT_MCP_VERSION', '1.3.18' );
define( 'MT_MCP_FILE', __FILE__ );
define( 'MT_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MT_MCP_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( MT_MCP_PATH . 'vendor/autoload.php' ) ) {
	require_once MT_MCP_PATH . 'vendor/autoload.php';
}

require_once MT_MCP_PATH . 'classes/class-mcp-support.php';
require_once MT_MCP_PATH . 'classes/class-mcp-token-store.php';
require_once MT_MCP_PATH . 'classes/class-mcp-auth.php';
require_once MT_MCP_PATH . 'classes/class-mcp-oauth.php';
require_once MT_MCP_PATH . 'classes/class-mcp-seo.php';
require_once MT_MCP_PATH . 'classes/class-mcp-transfer.php';
require_once MT_MCP_PATH . 'classes/class-mcp-updates.php';
require_once MT_MCP_PATH . 'classes/class-mcp-tool-handlers.php';
require_once MT_MCP_PATH . 'classes/class-mcp-server.php';
require_once MT_MCP_PATH . 'classes/class-mcp-public.php';
require_once MT_MCP_PATH . 'includes/class-mcp-recipes.php';
require_once MT_MCP_PATH . 'includes/class-mcp-github-updater.php';
require_once MT_MCP_PATH . 'admin/class-admin.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MT_MCP_PATH . 'includes/class-mcp-cli.php';
}

/**
 * Bootstrap.
 */
function boot() {
	load_plugin_textdomain( 'mt-mcp', false, dirname( plugin_basename( MT_MCP_FILE ) ) . '/languages' );
	Migrator::maybe_migrate();
	McpAuth::purge_legacy_key_options();
	McpTokenStore::maybe_install();
	McpGithubUpdater::boot();
	new McpOAuth();
	new McpServer();
	new McpPublic();
	if ( is_admin() ) {
		new Admin();
	}

	// Self-signed certs: only skip verify for loopback / *.local / *.test (not broad "staging").
	add_filter(
		'mt_mcp_sslverify',
		static function ( $verify, $url = '' ) {
			$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( $host && preg_match( '/(\.local$|\.test$|localhost|127\.0\.0\.1|\[::1\])/i', $host ) ) {
				return false;
			}
			if ( defined( 'MT_MCP_SSLVERIFY' ) ) {
				return (bool) MT_MCP_SSLVERIFY;
			}
			return $verify;
		},
		10,
		2
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Activation: schema, flush rewrites, migrate options, purge shared keys.
 */
function activate() {
	require_once MT_MCP_PATH . 'classes/class-mcp-support.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-token-store.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-auth.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-oauth.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-seo.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-transfer.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-updates.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-tool-handlers.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-server.php';
	require_once MT_MCP_PATH . 'classes/class-mcp-public.php';

	Migrator::maybe_migrate();
	McpAuth::purge_legacy_key_options();
	McpTokenStore::install();

	if ( ! get_option( McpSupport::OPTION_ENABLED ) ) {
		update_option( McpSupport::OPTION_ENABLED, '1' );
	}
	if ( false === get_option( McpSupport::OPTION_ENVIRONMENT, false ) ) {
		update_option( McpSupport::OPTION_ENVIRONMENT, 'unknown' );
	}
	if ( false === get_option( McpAuth::OPTION_AUTH_MODE, false ) ) {
		update_option( McpAuth::OPTION_AUTH_MODE, 'oauth' );
	}

	McpServer::flush_rewrites_now();
	if ( McpUpdates::updates_allowed() ) {
		McpUpdates::ensure_guard_mu_plugin();
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: clear rewrite flush flag.
 */
function deactivate() {
	delete_option( McpSupport::OPTION_FLUSHED );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * One-time migrate from mt-utilities MCP options (never migrates shared keys).
 */
class Migrator {

	const FLAG = 'mt_mcp_migrated_from_utilities';

	const FLAG_OAUTH_DEFAULT = 'mt_mcp_auth_default_oauth';

	public static function maybe_migrate() {
		// One-time: prefer OAuth-only over the earlier "both" default.
		if ( ! get_option( self::FLAG_OAUTH_DEFAULT ) ) {
			if ( 'both' === get_option( McpAuth::OPTION_AUTH_MODE, 'oauth' ) ) {
				update_option( McpAuth::OPTION_AUTH_MODE, 'oauth' );
			}
			update_option( self::FLAG_OAUTH_DEFAULT, '1' );
		}

		if ( get_option( self::FLAG ) ) {
			return;
		}

		$map = array(
			'utility_plugin_mcp_enabled'      => McpSupport::OPTION_ENABLED,
			'utility_plugin_mcp_allow_writes' => McpSupport::OPTION_ALLOW_WRITES,
			'utility_plugin_mcp_environment'  => McpSupport::OPTION_ENVIRONMENT,
			'utility_plugin_mcp_tool_groups'  => McpSupport::OPTION_TOOL_GROUPS,
			'utility_plugin_mcp_audit_log'    => McpSupport::OPTION_AUDIT_LOG,
		);

		foreach ( $map as $old => $new ) {
			if ( false === get_option( $new, false ) ) {
				$val = get_option( $old, null );
				if ( null !== $val && false !== $val ) {
					update_option( $new, $val );
				}
			}
		}

		McpAuth::purge_legacy_key_options();
		update_option( self::FLAG, '1' );
	}
}
