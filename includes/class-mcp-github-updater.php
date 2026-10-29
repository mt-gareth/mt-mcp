<?php

namespace MT\Mcp;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * WordPress admin updates from GitHub Releases (public repo).
 *
 * Create a GitHub Release whose tag matches the plugin Version (e.g. v1.3.14),
 * and attach a zip whose top-level folder is `mt-mcp/`. Use bin/build-release.sh.
 */
class McpGithubUpdater {

	const DEFAULT_REPO = 'https://github.com/mt-gareth/mt-mcp/';

	/**
	 * @var object|null
	 */
	private static $checker = null;

	public static function boot() {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		/**
		 * Disable remote update checks (e.g. air-gapped).
		 *
		 * define( 'MT_MCP_DISABLE_UPDATES', true );
		 */
		if ( defined( 'MT_MCP_DISABLE_UPDATES' ) && MT_MCP_DISABLE_UPDATES ) {
			return;
		}

		$repo = defined( 'MT_MCP_GITHUB_REPO' ) ? (string) MT_MCP_GITHUB_REPO : self::DEFAULT_REPO;
		$repo = trailingslashit( $repo );

		self::$checker = PucFactory::buildUpdateChecker(
			$repo,
			MT_MCP_FILE,
			'mt-mcp'
		);

		// Prefer a release asset zip (correct top-level folder) over GitHub's source archive.
		$api = self::$checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/\.zip($|[?#])/i' );
		}

		/**
		 * Optional: GitHub token raises API rate limits (public repo still works without it).
		 *
		 * define( 'MT_MCP_GITHUB_TOKEN', 'ghp_...' );
		 */
		if ( defined( 'MT_MCP_GITHUB_TOKEN' ) && MT_MCP_GITHUB_TOKEN ) {
			self::$checker->setAuthentication( (string) MT_MCP_GITHUB_TOKEN );
		}
	}
}
