<?php
/**
 * Plugin Name: MT MCP Update Guard
 * Description: Dead-man's switch for MT MCP plugin updates. Auto-rolls back if confirm_update_ok is not received before the deadline. Loads as must-use so it still runs when a regular plugin fatals.
 * Version: 1.3.8
 * Author: Motion Tactic
 *
 * Also registers: wp mt-mcp-guard rollback|pending|confirm
 * Use with a broken site: wp --skip-plugins mt-mcp-guard rollback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MT_MCP_GUARD_VERSION', '1.3.8' );

/**
 * Guard data directory (not under uploads — always under wp-content).
 */
function mt_mcp_guard_dir() {
	$dir = WP_CONTENT_DIR . '/mt-mcp-guard';
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		@mkdir( $dir, 0755, true );
	}
	return $dir;
}

function mt_mcp_guard_pending_path() {
	return mt_mcp_guard_dir() . '/pending.json';
}

function mt_mcp_guard_log( $message ) {
	$file = mt_mcp_guard_dir() . '/guard.log';
	$line = gmdate( 'c' ) . ' ' . $message . "\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $file, $line, FILE_APPEND );
}

function mt_mcp_guard_read_pending() {
	$path = mt_mcp_guard_pending_path();
	if ( ! file_exists( $path ) ) {
		return null;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$raw = file_get_contents( $path );
	$data = json_decode( (string) $raw, true );
	return is_array( $data ) ? $data : null;
}

function mt_mcp_guard_write_pending( $data ) {
	$path = mt_mcp_guard_pending_path();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $path, wp_json_encode( $data ) );
}

function mt_mcp_guard_clear_pending() {
	$path = mt_mcp_guard_pending_path();
	if ( file_exists( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
	}
}

/**
 * Restore plugin from zip without Plugin_Upgrader (works during fatals / CLI --skip-plugins).
 *
 * @param array $pending Pending payload.
 * @return true|string True or error message.
 */
function mt_mcp_guard_restore_from_pending( $pending ) {
	if ( empty( $pending['zip'] ) || empty( $pending['id'] ) ) {
		return 'Missing zip or id in pending.';
	}
	$zip = $pending['zip'];
	if ( ! file_exists( $zip ) ) {
		return 'Snapshot zip not found: ' . $zip;
	}
	$plugin_file = $pending['id'];
	$slug_dir    = dirname( $plugin_file );
	$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';

	if ( '.' === $slug_dir ) {
		// Single-file plugin.
		$target = $plugins_dir . '/' . $plugin_file;
		$broken = $target . '.mt-mcp-broken-' . gmdate( 'YmdHis' );
		if ( file_exists( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@rename( $target, $broken );
		}
	} else {
		$target = $plugins_dir . '/' . $slug_dir;
		$broken = $target . '.mt-mcp-broken-' . gmdate( 'YmdHis' );
		if ( is_dir( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@rename( $target, $broken );
		}
	}

	if ( class_exists( 'ZipArchive' ) ) {
		$za = new ZipArchive();
		if ( true !== $za->open( $zip ) ) {
			return 'Could not open zip.';
		}
		$ok = $za->extractTo( $plugins_dir );
		$za->close();
		if ( ! $ok ) {
			return 'Zip extract failed.';
		}
	} else {
		if ( ! class_exists( 'PclZip' ) ) {
			$pcl = ABSPATH . 'wp-admin/includes/class-pclzip.php';
			if ( file_exists( $pcl ) ) {
				require_once $pcl;
			}
		}
		if ( ! class_exists( 'PclZip' ) ) {
			return 'ZipArchive and PclZip unavailable.';
		}
		$archive = new PclZip( $zip );
		$result  = $archive->extract( PCLZIP_OPT_PATH, $plugins_dir );
		if ( 0 === $result ) {
			return 'PclZip extract failed: ' . $archive->errorInfo( true );
		}
	}

	mt_mcp_guard_log( 'Restored ' . $plugin_file . ' from ' . basename( $zip ) . ( ! empty( $pending['auto'] ) ? ' (auto dead-man)' : ' (manual)' ) );
	return true;
}

/**
 * Fire dead-man rollback if deadline passed.
 */
function mt_mcp_guard_maybe_auto_rollback() {
	$pending = mt_mcp_guard_read_pending();
	if ( ! $pending || empty( $pending['deadline_ts'] ) ) {
		return;
	}
	if ( ! empty( $pending['confirmed'] ) ) {
		mt_mcp_guard_clear_pending();
		return;
	}
	if ( time() < (int) $pending['deadline_ts'] ) {
		return;
	}

	$pending['auto'] = true;
	$result = mt_mcp_guard_restore_from_pending( $pending );
	if ( true === $result ) {
		$pending['rolled_back_at'] = gmdate( 'c' );
		$pending['auto_rolled_back'] = true;
		// Leave a receipt then clear active pending.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( mt_mcp_guard_dir() . '/last-auto-rollback.json', wp_json_encode( $pending ) );
		mt_mcp_guard_clear_pending();

		// Best-effort: update session option if main plugin data exists.
		if ( function_exists( 'get_option' ) && ! empty( $pending['session_id'] ) && ! empty( $pending['id'] ) ) {
			$all = get_option( 'mt_mcp_update_sessions', array() );
			if ( is_array( $all ) && isset( $all[ $pending['session_id'] ]['queue'][ $pending['id'] ] ) ) {
				$all[ $pending['session_id'] ]['queue'][ $pending['id'] ]['status'] = 'rolled_back';
				$all[ $pending['session_id'] ]['queue'][ $pending['id'] ]['error']  = 'Auto-rolled back by MT MCP Update Guard (no confirm before deadline).';
				$all[ $pending['session_id'] ]['log'][] = array(
					'ts'      => gmdate( 'c' ),
					'event'   => 'auto_rolled_back',
					'message' => 'Dead-man timer fired',
					'id'      => $pending['id'],
				);
				update_option( 'mt_mcp_update_sessions', $all, false );
			}
		}
	} else {
		mt_mcp_guard_log( 'Auto-rollback failed: ' . $result );
	}
}
add_action( 'muplugins_loaded', 'mt_mcp_guard_maybe_auto_rollback', 0 );

/* -------------------------------------------------------------------------
 * WP-CLI (available even with --skip-plugins because this is a mu-plugin)
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * MT MCP update guard CLI (survives --skip-plugins).
	 */
	class MT_MCP_Guard_CLI {

		/**
		 * Show armed dead-man pending rollback, if any.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mt-mcp-guard pending
		 *     wp --skip-plugins mt-mcp-guard pending
		 *
		 * @when after_wp_load
		 */
		public function pending() {
			$p = mt_mcp_guard_read_pending();
			if ( ! $p ) {
				WP_CLI::success( 'No pending dead-man rollback armed.' );
				return;
			}
			$left = (int) $p['deadline_ts'] - time();
			WP_CLI::line( 'session:  ' . ( isset( $p['session_id'] ) ? $p['session_id'] : '' ) );
			WP_CLI::line( 'plugin:   ' . ( isset( $p['id'] ) ? $p['id'] : '' ) );
			WP_CLI::line( 'zip:      ' . ( isset( $p['zip'] ) ? $p['zip'] : '' ) );
			WP_CLI::line( 'deadline: ' . ( isset( $p['deadline'] ) ? $p['deadline'] : '' ) . ' (' . $left . 's left)' );
		}

		/**
		 * Confirm the last update is OK (disarm dead-man timer).
		 *
		 * ## OPTIONS
		 *
		 * [--session=<id>]
		 * : Optional session id check.
		 *
		 * [--id=<plugin>]
		 * : Optional plugin file check.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mt-mcp-guard confirm
		 *     wp --skip-plugins mt-mcp-guard confirm
		 *
		 * @when after_wp_load
		 */
		public function confirm( $args, $assoc ) {
			$p = mt_mcp_guard_read_pending();
			if ( ! $p ) {
				WP_CLI::warning( 'Nothing pending to confirm.' );
				return;
			}
			if ( ! empty( $assoc['session'] ) && isset( $p['session_id'] ) && $assoc['session'] !== $p['session_id'] ) {
				WP_CLI::error( 'Pending session mismatch.' );
			}
			if ( ! empty( $assoc['id'] ) && isset( $p['id'] ) && $assoc['id'] !== $p['id'] ) {
				WP_CLI::error( 'Pending plugin id mismatch.' );
			}
			mt_mcp_guard_clear_pending();
			WP_CLI::success( 'Dead-man timer disarmed.' );
		}

		/**
		 * Roll back the armed package (or a specific session/plugin zip).
		 *
		 * ## OPTIONS
		 *
		 * [--session=<id>]
		 * : Update session id (reads snapshot from session store).
		 *
		 * [--id=<plugin>]
		 * : Plugin basename e.g. akismet/akismet.php
		 *
		 * [--zip=<path>]
		 * : Explicit snapshot zip path.
		 *
		 * [--yes]
		 * : Skip confirm.
		 *
		 * ## EXAMPLES
		 *
		 *     wp --skip-plugins mt-mcp-guard rollback
		 *     wp --skip-plugins mt-mcp-guard rollback --session=upd_abc --id=eps-301-redirects/eps-301-redirects.php
		 *
		 * @when after_wp_load
		 */
		public function rollback( $args, $assoc ) {
			$pending = mt_mcp_guard_read_pending();

			if ( ! empty( $assoc['zip'] ) && ! empty( $assoc['id'] ) ) {
				$pending = array(
					'id'  => $assoc['id'],
					'zip' => $assoc['zip'],
				);
			} elseif ( ! empty( $assoc['session'] ) && ! empty( $assoc['id'] ) ) {
				$all = get_option( 'mt_mcp_update_sessions', array() );
				$sid = $assoc['session'];
				$pid = $assoc['id'];
				if ( empty( $all[ $sid ]['queue'][ $pid ]['snapshot'] ) ) {
					WP_CLI::error( 'No snapshot on that session/plugin.' );
				}
				$pending = array(
					'session_id' => $sid,
					'id'         => $pid,
					'zip'        => $all[ $sid ]['queue'][ $pid ]['snapshot'],
				);
			} elseif ( ! $pending ) {
				WP_CLI::error( 'No pending dead-man rollback. Pass --session and --id, or --zip and --id.' );
			}

			WP_CLI::confirm( 'Restore ' . $pending['id'] . ' from ' . basename( $pending['zip'] ) . '?', $assoc );

			$result = mt_mcp_guard_restore_from_pending( $pending );
			if ( true !== $result ) {
				WP_CLI::error( $result );
			}
			mt_mcp_guard_clear_pending();

			if ( ! empty( $pending['session_id'] ) && ! empty( $pending['id'] ) ) {
				$all = get_option( 'mt_mcp_update_sessions', array() );
				if ( isset( $all[ $pending['session_id'] ]['queue'][ $pending['id'] ] ) ) {
					$all[ $pending['session_id'] ]['queue'][ $pending['id'] ]['status'] = 'rolled_back';
					$all[ $pending['session_id'] ]['log'][] = array(
						'ts'      => gmdate( 'c' ),
						'event'   => 'rolled_back',
						'message' => 'Restored via wp mt-mcp-guard rollback',
						'id'      => $pending['id'],
					);
					update_option( 'mt_mcp_update_sessions', $all, false );
				}
			}

			WP_CLI::success( 'Rolled back ' . $pending['id'] . '. Remove any *.mt-mcp-broken-* dirs under plugins when satisfied.' );
		}
	}

	WP_CLI::add_command( 'mt-mcp-guard', 'MT_MCP_Guard_CLI' );
}
