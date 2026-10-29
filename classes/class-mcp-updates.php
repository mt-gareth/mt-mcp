<?php

namespace MT\Mcp;

/**
 * Plugin / core update sessions: list → confirm → one-at-a-time update
 * with zip snapshots for rollback and smoke checks between steps.
 */
class McpUpdates {

	const OPTION_ALLOW_UPDATES = 'mt_mcp_allow_updates';
	const OPTION_SESSIONS      = 'mt_mcp_update_sessions';
	const SESSION_TTL          = 86400; // 24h
	const MAX_SESSIONS         = 10;
	const ROLLBACK_DIR         = 'mt-mcp-rollbacks';

	public static function updates_allowed() {
		return (bool) get_option( self::OPTION_ALLOW_UPDATES );
	}

	public static function assert_updates_allowed() {
		if ( ! self::updates_allowed() ) {
			throw new \Exception( 'MCP plugin/core updates are disabled. Enable "Allow MCP plugin/core updates" in Settings → MT MCP.' );
		}
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_core' ) ) {
			throw new \Exception( 'Current user cannot update_plugins / update_core.' );
		}
	}

	/**
	 * Refresh update transients and return available updates.
	 *
	 * @param array $args Optional force refresh.
	 * @return array
	 */
	public static function list_updates( $args = array() ) {
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_core' ) ) {
			throw new \Exception( 'Current user cannot view updates.' );
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! empty( $args['force_check'] ) ) {
			wp_version_check( array(), true );
			wp_update_plugins();
		}

		$items = array();

		$plugin_updates = get_plugin_updates();
		if ( is_array( $plugin_updates ) ) {
			foreach ( $plugin_updates as $file => $plugin ) {
				$new = isset( $plugin->update ) ? $plugin->update : null;
				$items[] = array(
					'type'        => 'plugin',
					'id'          => $file,
					'slug'        => isset( $new->slug ) ? $new->slug : dirname( $file ),
					'name'        => isset( $plugin->Name ) ? $plugin->Name : $file,
					'version'     => isset( $plugin->Version ) ? $plugin->Version : '',
					'new_version' => ( $new && isset( $new->new_version ) ) ? $new->new_version : '',
					'package'     => ( $new && ! empty( $new->package ) ),
					'auto_update' => ( $new && ! empty( $new->autoupdate ) ),
					'is_mt_mcp'   => ( 'mt-mcp/mt-mcp.php' === $file || false !== strpos( $file, 'mt-mcp.php' ) ),
				);
			}
		}

		$core = get_core_updates( array( 'dismissed' => false ) );
		if ( is_array( $core ) ) {
			foreach ( $core as $update ) {
				if ( empty( $update->response ) || 'latest' === $update->response || 'development' === $update->response ) {
					continue;
				}
				$items[] = array(
					'type'        => 'core',
					'id'          => 'wordpress',
					'slug'        => 'wordpress',
					'name'        => 'WordPress Core',
					'version'     => get_bloginfo( 'version' ),
					'new_version' => isset( $update->version ) ? $update->version : '',
					'package'     => ! empty( $update->download ),
					'locale'      => isset( $update->locale ) ? $update->locale : '',
					'response'    => isset( $update->response ) ? $update->response : '',
				);
			}
		}

		return array(
			'updates_allowed' => self::updates_allowed(),
			'environment'     => McpSupport::get_environment(),
			'count'           => count( $items ),
			'items'           => $items,
			'hint'            => 'Confirm which ids to update, then start_update_session with approved ids. Prefer staging; core rollback may require host backup.',
		);
	}

	public static function get_sessions() {
		$all = get_option( self::OPTION_SESSIONS, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function save_sessions( $all ) {
		$now = time();
		$out = array();
		foreach ( $all as $id => $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}
			$exp = isset( $session['expires_ts'] ) ? (int) $session['expires_ts'] : 0;
			if ( $exp && $exp < $now && in_array( $session['status'], array( 'completed', 'aborted' ), true ) ) {
				continue;
			}
			$out[ $id ] = $session;
		}
		if ( count( $out ) > self::MAX_SESSIONS ) {
			uasort(
				$out,
				static function ( $a, $b ) {
					return strcmp( isset( $a['created_at'] ) ? $a['created_at'] : '', isset( $b['created_at'] ) ? $b['created_at'] : '' );
				}
			);
			$out = array_slice( $out, -self::MAX_SESSIONS, null, true );
		}
		update_option( self::OPTION_SESSIONS, $out, false );
	}

	public static function get_session( $session_id ) {
		$session_id = sanitize_text_field( (string) $session_id );
		$all        = self::get_sessions();
		return ( $session_id && isset( $all[ $session_id ] ) ) ? $all[ $session_id ] : null;
	}

	public static function put_session( $session ) {
		$all = self::get_sessions();
		$all[ $session['id'] ] = $session;
		self::save_sessions( $all );
		return $session;
	}

	/**
	 * @param array $args approved ids, smoke_urls, etc.
	 * @return array
	 */
	public static function start_session( $args ) {
		self::assert_updates_allowed();

		$approved_ids = array();
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$approved_ids = array_map( 'strval', $args['ids'] );
		}
		if ( empty( $approved_ids ) ) {
			throw new \Exception( 'Provide ids: plugin file paths and/or "wordpress" from list_updates.' );
		}

		$available = self::list_updates( array( 'force_check' => ! empty( $args['force_check'] ) ) );
		$by_id     = array();
		foreach ( $available['items'] as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$queue = array();
		foreach ( $approved_ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				throw new \Exception( 'No available update for id "' . $id . '". Re-run list_updates.' );
			}
			$item = $by_id[ $id ];
			if ( ! empty( $item['is_mt_mcp'] ) && empty( $args['allow_self_update'] ) ) {
				throw new \Exception( 'Refusing to update MT MCP itself in-session unless allow_self_update=true (can break the agent mid-flight).' );
			}
			if ( 'core' === $item['type'] && ! current_user_can( 'update_core' ) ) {
				throw new \Exception( 'Current user cannot update_core.' );
			}
			if ( 'plugin' === $item['type'] && ! current_user_can( 'update_plugins' ) ) {
				throw new \Exception( 'Current user cannot update_plugins.' );
			}
			$queue[ $id ] = array(
				'type'        => $item['type'],
				'id'          => $item['id'],
				'slug'        => $item['slug'],
				'name'        => $item['name'],
				'from'        => $item['version'],
				'to'          => $item['new_version'],
				'status'      => 'pending',
				'snapshot'    => null,
				'updated_at'  => null,
				'error'       => null,
				'checks'      => null,
			);
		}

		$smoke_urls = array();
		if ( ! empty( $args['smoke_urls'] ) && is_array( $args['smoke_urls'] ) ) {
			foreach ( $args['smoke_urls'] as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url ) {
					$smoke_urls[] = $url;
				}
			}
		}
		if ( empty( $smoke_urls ) ) {
			$smoke_urls[] = home_url( '/' );
		}

		$form_probe = null;
		if ( ! empty( $args['form_probe'] ) && is_array( $args['form_probe'] ) ) {
			$form_probe = array(
				'url'            => isset( $args['form_probe']['url'] ) ? esc_url_raw( $args['form_probe']['url'] ) : '',
				'method'         => isset( $args['form_probe']['method'] ) ? strtoupper( sanitize_key( $args['form_probe']['method'] ) ) : 'POST',
				'body'           => isset( $args['form_probe']['body'] ) && is_array( $args['form_probe']['body'] ) ? $args['form_probe']['body'] : array(),
				'success_needle' => isset( $args['form_probe']['success_needle'] ) ? (string) $args['form_probe']['success_needle'] : '',
				'expect_status'  => isset( $args['form_probe']['expect_status'] ) ? (int) $args['form_probe']['expect_status'] : 200,
			);
			if ( ! $form_probe['url'] ) {
				$form_probe = null;
			}
		}

		$edit_probe = isset( $args['edit_probe_post_id'] ) ? (int) $args['edit_probe_post_id'] : 0;

		$session_id = 'upd_' . bin2hex( random_bytes( 8 ) );
		$session    = array(
			'id'                 => $session_id,
			'status'             => 'active',
			'created_at'         => gmdate( 'c' ),
			'expires_ts'         => time() + self::SESSION_TTL,
			'user_id'            => get_current_user_id(),
			'environment'        => McpSupport::get_environment(),
			'smoke_urls'         => $smoke_urls,
			'form_probe'         => $form_probe,
			'edit_probe_post_id' => $edit_probe > 0 ? $edit_probe : null,
			'queue'              => $queue,
			'log'                => array(
				array(
					'ts'      => gmdate( 'c' ),
					'event'   => 'started',
					'message' => 'Session created with ' . count( $queue ) . ' package(s).',
				),
			),
			'report'             => null,
		);

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_start' => array(
					'ids'        => array_keys( $queue ),
					'smoke_urls' => $smoke_urls,
					'form_probe' => (bool) $form_probe,
					'edit_probe' => $edit_probe,
				),
			);
		}

		self::put_session( $session );
		return array(
			'session_id' => $session_id,
			'status'     => 'active',
			'queue'      => array_values( $queue ),
			'smoke_urls' => $smoke_urls,
			'next'       => 'Call update_package with session_id and the first pending id. After each update, run_smoke_checks. On failure, rollback_package.',
		);
	}

	public static function summarize_session( $session ) {
		$pending = 0;
		$updated = 0;
		$failed  = 0;
		$rolled  = 0;
		foreach ( $session['queue'] as $item ) {
			if ( 'pending' === $item['status'] ) {
				$pending++;
			} elseif ( 'updated' === $item['status'] ) {
				$updated++;
			} elseif ( 'failed' === $item['status'] ) {
				$failed++;
			} elseif ( 'rolled_back' === $item['status'] ) {
				$rolled++;
			}
		}
		return array(
			'session_id' => $session['id'],
			'status'     => $session['status'],
			'created_at' => $session['created_at'],
			'counts'     => array(
				'pending'     => $pending,
				'updated'     => $updated,
				'failed'      => $failed,
				'rolled_back' => $rolled,
				'total'       => count( $session['queue'] ),
			),
			'queue'      => array_values( $session['queue'] ),
			'smoke_urls' => $session['smoke_urls'],
			'form_probe' => ! empty( $session['form_probe'] ),
			'edit_probe_post_id' => $session['edit_probe_post_id'],
			'log'        => isset( $session['log'] ) ? array_slice( $session['log'], -20 ) : array(),
			'report'     => isset( $session['report'] ) ? $session['report'] : null,
		);
	}

	private static function append_log( &$session, $event, $message, $extra = array() ) {
		$session['log'][] = array_merge(
			array(
				'ts'      => gmdate( 'c' ),
				'event'   => $event,
				'message' => $message,
			),
			$extra
		);
	}

	public static function rollback_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			throw new \Exception( 'Uploads dir unavailable: ' . $upload['error'] );
		}
		$dir = trailingslashit( $upload['basedir'] ) . self::ROLLBACK_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \Exception( 'Could not create rollback directory.' );
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Deny from all\n" );
		}
		$idx = $dir . '/index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php\n// Silence.\n" );
		}
		return $dir;
	}

	/**
	 * Zip a plugin directory for rollback.
	 *
	 * @param string $plugin_file Main plugin file relative to plugins dir.
	 * @return string Absolute path to zip.
	 */
	public static function snapshot_plugin( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$plugin_file = plugin_basename( $plugin_file );
		$root        = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		if ( '.' === dirname( $plugin_file ) ) {
			// Single-file plugin.
			$root = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! file_exists( $root ) ) {
				throw new \Exception( 'Plugin file not found for snapshot.' );
			}
			$dir  = self::rollback_dir();
			$dest = $dir . '/' . sanitize_file_name( basename( $plugin_file, '.php' ) ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
			$zip  = new \PclZip( $dest );
			$result = $zip->create( $root, PCLZIP_OPT_REMOVE_PATH, WP_PLUGIN_DIR );
			if ( 0 === $result ) {
				throw new \Exception( 'Snapshot zip failed: ' . $zip->errorInfo( true ) );
			}
			return $dest;
		}
		if ( ! is_dir( $root ) ) {
			throw new \Exception( 'Plugin directory not found for snapshot: ' . dirname( $plugin_file ) );
		}
		$dir  = self::rollback_dir();
		$slug = dirname( $plugin_file );
		$dest = $dir . '/' . sanitize_file_name( $slug ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip  = new \PclZip( $dest );
		$result = $zip->create( $root, PCLZIP_OPT_REMOVE_PATH, WP_PLUGIN_DIR );
		if ( 0 === $result ) {
			throw new \Exception( 'Snapshot zip failed: ' . $zip->errorInfo( true ) );
		}
		return $dest;
	}

	/**
	 * Update one package in a session.
	 *
	 * @param array $args session_id, id, dry_run.
	 * @return array
	 */
	public static function update_package( $args ) {
		self::assert_updates_allowed();
		$session_id = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$id         = isset( $args['id'] ) ? (string) $args['id'] : '';
		$session    = self::get_session( $session_id );
		if ( ! $session || 'active' !== $session['status'] ) {
			throw new \Exception( 'Active update session not found.' );
		}
		if ( ! isset( $session['queue'][ $id ] ) ) {
			throw new \Exception( 'id not in this session queue.' );
		}
		$item = $session['queue'][ $id ];
		if ( 'pending' !== $item['status'] && empty( $args['force'] ) ) {
			throw new \Exception( 'Package status is "' . $item['status'] . '". Use force=true to retry, or pick another pending id.' );
		}

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'session_id' => $session_id,
				'would_update' => $item,
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		WP_Filesystem();

		try {
			if ( 'plugin' === $item['type'] ) {
				$was_active = is_plugin_active( $id );
				$snapshot   = self::snapshot_plugin( $id );
				$session['queue'][ $id ]['snapshot'] = $snapshot;

				$skin     = new \Automatic_Upgrader_Skin();
				$upgrader = new \Plugin_Upgrader( $skin );
				$result   = $upgrader->upgrade( $id );
				if ( is_wp_error( $result ) ) {
					throw new \Exception( $result->get_error_message() );
				}
				if ( false === $result ) {
					$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
					throw new \Exception( 'Plugin upgrade failed.' . ( $messages ? ' ' . implode( ' ', $messages ) : '' ) );
				}
				if ( $was_active && ! is_plugin_active( $id ) ) {
					activate_plugin( $id, '', false, true );
				}
				wp_clean_plugins_cache( true );

				$session['queue'][ $id ]['status']     = 'updated';
				$session['queue'][ $id ]['updated_at'] = gmdate( 'c' );
				$session['queue'][ $id ]['error']      = null;
				self::append_log( $session, 'updated', $item['name'] . ' ' . $item['from'] . ' → ' . $item['to'], array( 'id' => $id ) );
			} elseif ( 'core' === $item['type'] ) {
				if ( ! current_user_can( 'update_core' ) ) {
					throw new \Exception( 'Cannot update_core.' );
				}
				// Core: no local zip rollback — require explicit acknowledge.
				if ( empty( $args['acknowledge_core_no_zip_rollback'] ) ) {
					throw new \Exception( 'Core updates have no MCP zip rollback. Re-call with acknowledge_core_no_zip_rollback=true and ensure a host backup exists.' );
				}
				wp_version_check( array(), true );
				$updates = get_core_updates();
				$offer   = null;
				if ( is_array( $updates ) ) {
					foreach ( $updates as $u ) {
						if ( ! empty( $u->response ) && 'latest' !== $u->response && 'development' !== $u->response ) {
							$offer = $u;
							break;
						}
					}
				}
				if ( ! $offer ) {
					throw new \Exception( 'No core update offer available.' );
				}
				$skin     = new \Automatic_Upgrader_Skin();
				$upgrader = new \Core_Upgrader( $skin );
				$result   = $upgrader->upgrade( $offer );
				if ( is_wp_error( $result ) ) {
					throw new \Exception( $result->get_error_message() );
				}
				$session['queue'][ $id ]['status']     = 'updated';
				$session['queue'][ $id ]['updated_at'] = gmdate( 'c' );
				$session['queue'][ $id ]['snapshot']   = null;
				$session['queue'][ $id ]['error']      = null;
				self::append_log( $session, 'updated', 'WordPress core → ' . $item['to'] . ' (no zip rollback)', array( 'id' => $id ) );
			} else {
				throw new \Exception( 'Unknown package type.' );
			}
		} catch ( \Throwable $e ) {
			$session['queue'][ $id ]['status'] = 'failed';
			$session['queue'][ $id ]['error']  = $e->getMessage();
			self::append_log( $session, 'failed', $e->getMessage(), array( 'id' => $id ) );
			self::put_session( $session );
			throw $e;
		}

		self::put_session( $session );
		return array(
			'session_id' => $session_id,
			'id'         => $id,
			'status'     => 'updated',
			'item'       => $session['queue'][ $id ],
			'next'       => 'run_smoke_checks with session_id (and optionally after=id). On failure, rollback_package.',
		);
	}

	public static function rollback_package( $args ) {
		self::assert_updates_allowed();
		$session_id = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$id         = isset( $args['id'] ) ? (string) $args['id'] : '';
		$session    = self::get_session( $session_id );
		if ( ! $session ) {
			throw new \Exception( 'Update session not found.' );
		}
		if ( ! isset( $session['queue'][ $id ] ) ) {
			throw new \Exception( 'id not in this session queue.' );
		}
		$item = $session['queue'][ $id ];
		if ( 'core' === $item['type'] ) {
			throw new \Exception( 'Cannot zip-rollback WordPress core via MCP. Restore from host backup (e.g. WP Engine checkpoint).' );
		}
		$zip = isset( $item['snapshot'] ) ? $item['snapshot'] : '';
		if ( ! $zip || ! file_exists( $zip ) ) {
			throw new \Exception( 'No snapshot zip for this package. Host restore required.' );
		}

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'session_id' => $session_id,
				'would_rollback' => array(
					'id'   => $id,
					'from' => $item['to'],
					'to'   => $item['from'],
					'zip'  => basename( $zip ),
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		WP_Filesystem();
		$was_active = is_plugin_active( $id );
		// Remove current plugin files then install from zip.
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		// delete_plugins expects array of plugin files.
		if ( function_exists( 'delete_plugins' ) ) {
			$del = delete_plugins( array( $id ) );
			if ( is_wp_error( $del ) ) {
				throw new \Exception( 'Could not remove current plugin before rollback: ' . $del->get_error_message() );
			}
		}
		$result = $upgrader->install( $zip );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		if ( false === $result ) {
			throw new \Exception( 'Rollback install failed.' );
		}
		if ( $was_active ) {
			activate_plugin( $id, '', false, true );
		}
		wp_clean_plugins_cache( true );

		$session['queue'][ $id ]['status'] = 'rolled_back';
		$session['queue'][ $id ]['error']  = null;
		self::append_log( $session, 'rolled_back', $item['name'] . ' restored from snapshot', array( 'id' => $id ) );
		self::put_session( $session );

		return array(
			'session_id' => $session_id,
			'id'         => $id,
			'status'     => 'rolled_back',
			'item'       => $session['queue'][ $id ],
			'note'       => 'Plugin restored from pre-update zip. Re-run smoke checks.',
		);
	}

	/**
	 * Smoke checks: URLs, optional form probe, optional edit+undo.
	 *
	 * @param array $args session_id, level=quick|full, after=id.
	 * @return array
	 */
	public static function run_smoke_checks( $args ) {
		$session_id = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$session    = self::get_session( $session_id );
		if ( ! $session ) {
			throw new \Exception( 'Update session not found.' );
		}
		$level = isset( $args['level'] ) ? sanitize_key( $args['level'] ) : 'quick';
		if ( ! in_array( $level, array( 'quick', 'full' ), true ) ) {
			$level = 'quick';
		}

		$checks = array();
		$ok     = true;

		$urls = $session['smoke_urls'];
		if ( 'quick' === $level && count( $urls ) > 3 ) {
			$urls = array_slice( $urls, 0, 3 );
		}
		foreach ( $urls as $url ) {
			$ssl = apply_filters( 'mt_mcp_sslverify', true, $url );
			$res = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => $ssl,
					'headers'     => array( 'User-Agent' => 'MT-MCP-smoke/' . ( defined( 'MT_MCP_VERSION' ) ? MT_MCP_VERSION : '1' ) ),
				)
			);
			if ( is_wp_error( $res ) ) {
				$checks[] = array( 'key' => 'url', 'url' => $url, 'ok' => false, 'error' => $res->get_error_message() );
				$ok = false;
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$fatal = ( false !== stripos( $body, 'fatal error' ) || false !== stripos( $body, 'there has been a critical error' ) );
			$row_ok = $code >= 200 && $code < 400 && ! $fatal;
			$checks[] = array(
				'key'    => 'url',
				'url'    => $url,
				'ok'     => $row_ok,
				'status' => $code,
				'fatal'  => $fatal,
			);
			if ( ! $row_ok ) {
				$ok = false;
			}
		}

		if ( 'full' === $level && ! empty( $session['form_probe'] ) ) {
			$probe = $session['form_probe'];
			$ssl   = apply_filters( 'mt_mcp_sslverify', true, $probe['url'] );
			$res   = wp_remote_request(
				$probe['url'],
				array(
					'method'    => $probe['method'] ? $probe['method'] : 'POST',
					'timeout'   => 20,
					'sslverify' => $ssl,
					'body'      => $probe['body'],
				)
			);
			if ( is_wp_error( $res ) ) {
				$checks[] = array( 'key' => 'form_probe', 'ok' => false, 'error' => $res->get_error_message() );
				$ok = false;
			} else {
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = (string) wp_remote_retrieve_body( $res );
				$expect = (int) $probe['expect_status'];
				$needle_ok = ( '' === $probe['success_needle'] ) || ( false !== strpos( $body, $probe['success_needle'] ) );
				$row_ok = ( $code === $expect || ( $code >= 200 && $code < 400 && $needle_ok ) ) && $needle_ok;
				$checks[] = array(
					'key'    => 'form_probe',
					'ok'     => $row_ok,
					'status' => $code,
					'needle' => $needle_ok,
				);
				if ( ! $row_ok ) {
					$ok = false;
				}
			}
		}

		$edit_result = null;
		if ( 'full' === $level && ! empty( $session['edit_probe_post_id'] ) ) {
			$post_id = (int) $session['edit_probe_post_id'];
			try {
				McpSupport::assert_can_edit_post( $post_id );
				$before = McpSupport::capture_undo_before(
					'update_post',
					array(
						'id'   => $post_id,
						'core' => array( 'post_title' => get_the_title( $post_id ) ),
					)
				);
				$marker = ' MT-MCP-SMOKE-' . wp_generate_password( 6, false );
				$post   = get_post( $post_id );
				$title  = $post->post_title . $marker;
				$r      = wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $title,
					),
					true
				);
				if ( is_wp_error( $r ) ) {
					throw new \Exception( $r->get_error_message() );
				}
				$handlers = new McpToolHandlers();
				$written  = array(
					'id'    => $post_id,
					'title' => get_the_title( $post_id ),
				);
				$undo = McpSupport::record_change( 'update_post', $before, $written );
				$undo_ok = false;
				$undo_token = null;
				if ( $undo && ! empty( $undo['token'] ) ) {
					$undo_token = $undo['token'];
					$handlers->undo_change( array( 'token' => $undo_token, 'force' => true ) );
					$undo_ok = true;
				} else {
					// Fallback restore title.
					wp_update_post(
						array(
							'ID'         => $post_id,
							'post_title' => $post->post_title,
						)
					);
					$undo_ok = true;
				}
				$edit_result = array(
					'ok'         => $undo_ok,
					'post_id'    => $post_id,
					'undo_token' => $undo_token,
				);
				$checks[] = array( 'key' => 'edit_undo', 'ok' => $undo_ok, 'post_id' => $post_id );
				if ( ! $undo_ok ) {
					$ok = false;
				}
			} catch ( \Throwable $e ) {
				$checks[] = array( 'key' => 'edit_undo', 'ok' => false, 'error' => $e->getMessage() );
				$ok = false;
			}
		}

		$payload = array(
			'session_id' => $session_id,
			'level'      => $level,
			'ok'         => $ok,
			'checks'     => $checks,
			'edit'       => $edit_result,
			'hint'       => $ok
				? ( 'quick' === $level ? 'Quick checks passed. Continue update_package for next pending id, or run_smoke_checks level=full after all updates.' : 'Full suite passed. Call finish_update_session for the report.' )
				: 'Smoke checks failed. Consider rollback_package for the last updated id, or abort_update_session.',
		);

		$after = isset( $args['after'] ) ? (string) $args['after'] : '';
		if ( $after && isset( $session['queue'][ $after ] ) ) {
			$session['queue'][ $after ]['checks'] = array(
				'level' => $level,
				'ok'    => $ok,
				'at'    => gmdate( 'c' ),
			);
		}
		self::append_log( $session, 'smoke', ( $ok ? 'passed' : 'failed' ) . ' (' . $level . ')' );
		self::put_session( $session );

		return $payload;
	}

	public static function finish_session( $args ) {
		$session_id = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$session    = self::get_session( $session_id );
		if ( ! $session ) {
			throw new \Exception( 'Update session not found.' );
		}
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'completed';
		if ( ! in_array( $status, array( 'completed', 'aborted' ), true ) ) {
			$status = 'completed';
		}
		$session['status'] = $status;
		$report            = self::build_report( $session );
		$session['report'] = $report;
		self::append_log( $session, $status, 'Session ' . $status );
		self::put_session( $session );
		return array(
			'session_id' => $session_id,
			'status'     => $status,
			'report'     => $report,
		);
	}

	public static function build_report( $session ) {
		$rows = array();
		foreach ( $session['queue'] as $item ) {
			$rows[] = array(
				'type'     => $item['type'],
				'name'     => $item['name'],
				'id'       => $item['id'],
				'from'     => $item['from'],
				'to'       => $item['to'],
				'status'   => $item['status'],
				'error'    => $item['error'],
				'has_snapshot' => ! empty( $item['snapshot'] ),
				'checks'   => $item['checks'],
			);
		}
		return array(
			'session_id'  => $session['id'],
			'environment' => $session['environment'],
			'created_at'  => $session['created_at'],
			'finished_at' => gmdate( 'c' ),
			'status'      => $session['status'],
			'packages'    => $rows,
			'log'         => $session['log'],
			'notes'       => array(
				'Core updates cannot be zip-rolled-back via MCP — use host backup if needed.',
				'Plugin rollbacks use pre-update zips in uploads/' . self::ROLLBACK_DIR . '.',
			),
		);
	}
}
