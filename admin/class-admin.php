<?php

namespace MT\Mcp;

/**
 * Settings → MT MCP admin UI.
 */
class Admin {

	const PAGE_SLUG = 'mt-mcp';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_mt_mcp_save_setting', array( $this, 'ajax_save_setting' ) );
		add_action( 'admin_post_mt_mcp_revoke_grant', array( $this, 'handle_revoke_grant' ) );
		add_action( 'admin_post_mt_mcp_revoke_user', array( $this, 'handle_revoke_user' ) );
		add_action( 'admin_post_mt_mcp_export_audit_csv', array( $this, 'handle_export_audit_csv' ) );
		add_action( 'admin_post_mt_mcp_simulate_tool', array( $this, 'handle_simulate_tool' ) );
		add_action( 'admin_post_mt_mcp_undo_change', array( $this, 'handle_undo_change' ) );
		add_action( 'update_option_' . McpSupport::OPTION_ENABLED, array( $this, 'on_enabled_change' ), 10, 2 );
	}

	public function register_menu() {
		add_options_page(
			'MT MCP',
			'MT MCP',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'mt-mcp-admin',
			MT_MCP_URL . 'admin/assets/admin.css',
			array(),
			MT_MCP_VERSION
		);
		wp_enqueue_script(
			'mt-mcp-admin',
			MT_MCP_URL . 'admin/assets/admin.js',
			array(),
			MT_MCP_VERSION,
			true
		);
		wp_localize_script(
			'mt-mcp-admin',
			'mtMcpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mt_mcp_save_setting' ),
				'envLabels' => McpSupport::ENVIRONMENTS,
			)
		);
	}

	/**
	 * Autosave a single setting (or tool groups / webhook) from the Settings tab.
	 */
	public function ajax_save_setting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( 'mt_mcp_save_setting', 'nonce' );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : null;

		switch ( $key ) {
			case 'enabled':
				$val = ! empty( $raw ) && '0' !== (string) $raw && 'false' !== (string) $raw;
				update_option( McpSupport::OPTION_ENABLED, $val ? '1' : '' );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'allow_writes':
				$val = ! empty( $raw ) && '0' !== (string) $raw && 'false' !== (string) $raw;
				update_option( McpSupport::OPTION_ALLOW_WRITES, $val ? '1' : '' );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'pause_writes':
				$val = ! empty( $raw ) && '0' !== (string) $raw && 'false' !== (string) $raw;
				update_option( McpSupport::OPTION_PAUSE_WRITES, $val ? '1' : '' );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'force_draft':
				$val = ! empty( $raw ) && '0' !== (string) $raw && 'false' !== (string) $raw;
				update_option( McpSupport::OPTION_FORCE_DRAFT, $val ? '1' : '' );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'site_brief':
				$val = sanitize_textarea_field( (string) $raw );
				if ( strlen( $val ) > 2000 ) {
					wp_send_json_error( array( 'message' => 'Site Brief max 2000 characters.' ), 400 );
				}
				update_option( McpSupport::OPTION_SITE_BRIEF, $val, false );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'dcr_extra_hosts':
				$val = sanitize_text_field( (string) $raw );
				update_option( McpSupport::OPTION_DCR_HOSTS, $val, false );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'environment':
				if ( defined( 'MT_MCP_ENVIRONMENT' ) ) {
					wp_send_json_error( array( 'message' => 'Environment is locked by wp-config.' ), 400 );
				}
				$val = strtolower( sanitize_text_field( (string) $raw ) );
				if ( ! isset( McpSupport::ENVIRONMENTS[ $val ] ) ) {
					wp_send_json_error( array( 'message' => 'Invalid environment.' ), 400 );
				}
				update_option( McpSupport::OPTION_ENVIRONMENT, $val );
				wp_send_json_success(
					array(
						'key'   => $key,
						'value' => $val,
						'label' => McpSupport::ENVIRONMENTS[ $val ],
					)
				);

			case 'auth_mode':
				$val = sanitize_key( (string) $raw );
				if ( ! in_array( $val, array( 'both', 'oauth' ), true ) ) {
					wp_send_json_error( array( 'message' => 'Invalid auth mode.' ), 400 );
				}
				update_option( McpAuth::OPTION_AUTH_MODE, $val );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'webhook_url':
				$val = esc_url_raw( trim( (string) $raw ) );
				update_option( McpSupport::OPTION_WEBHOOK_URL, $val );
				wp_send_json_success( array( 'key' => $key, 'value' => $val ) );

			case 'tool_groups':
				$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
				if ( ! is_array( $decoded ) ) {
					$decoded = array();
				}
				$out = array();
				foreach ( McpSupport::TOOL_GROUPS as $group_key => $label ) {
					$out[ $group_key ] = ! empty( $decoded[ $group_key ] ) ? '1' : '';
				}
				update_option( McpSupport::OPTION_TOOL_GROUPS, $out );
				wp_send_json_success( array( 'key' => $key, 'value' => $out ) );

			default:
				wp_send_json_error( array( 'message' => 'Unknown setting.' ), 400 );
		}
	}

	public function on_enabled_change( $old, $new ) {
		if ( $new && ! $old ) {
			delete_option( McpSupport::OPTION_FLUSHED );
			McpServer::flush_rewrites_now();
		}
		if ( ! $new && $old ) {
			delete_option( McpSupport::OPTION_FLUSHED );
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		// Legacy tab redirects.
		if ( in_array( $tab, array( 'connect', 'auth' ), true ) ) {
			$tab = 'overview';
		}
		if ( 'tools' === $tab ) {
			$tab = 'settings';
		}

		$tabs = array(
			'overview'   => 'Overview',
			'settings'   => 'Settings',
			'changes'    => 'Changes',
			'activity'   => 'Activity',
			'playground' => 'Playground',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		$enabled = (bool) get_option( McpSupport::OPTION_ENABLED );
		$writes  = (bool) get_option( McpSupport::OPTION_ALLOW_WRITES );
		$env     = McpSupport::get_environment();
		?>
		<div class="wrap mt-mcp-wrap">
			<header class="mt-mcp-header">
				<div>
					<p class="mt-mcp-eyebrow">Motion Tactic</p>
					<h1>MT MCP <span class="mt-mcp-ver">v<?php echo esc_html( MT_MCP_VERSION ); ?></span></h1>
					<p class="mt-mcp-lede">Connect AI agents to this WordPress site as a real user via OAuth.</p>
				</div>
				<div class="mt-mcp-status">
					<span class="mt-mcp-pill <?php echo $enabled ? 'is-on' : 'is-off'; ?>" data-pill="enabled">
						<?php echo $enabled ? 'Server on' : 'Server off'; ?>
					</span>
					<span class="mt-mcp-pill <?php echo $writes ? 'is-warn' : ''; ?>" data-pill="writes">
						Writes <?php echo $writes ? 'on' : 'off'; ?>
					</span>
					<span class="mt-mcp-pill" data-pill="environment"><?php echo esc_html( McpSupport::ENVIRONMENTS[ $env ] ); ?></span>
				</div>
			</header>

			<nav class="mt-mcp-tabs" aria-label="MT MCP sections">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="mt-mcp-tab <?php echo $tab === $key ? 'is-active' : ''; ?>"
					   href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $key ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="mt-mcp-panel">
				<?php
				switch ( $tab ) {
					case 'settings':
						$this->render_settings();
						break;
					case 'changes':
						$this->render_changes();
						break;
					case 'activity':
						$this->render_activity();
						break;
					case 'playground':
						$this->render_playground();
						break;
					default:
						$this->render_overview();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Site-specific MCP server key for agent configs (avoids clashing mt-mcp entries).
	 * e.g. propio.d.mt.com → propio-local-mcp
	 *      www.maptician.com (production) → maptician-prod-mcp
	 */
	public static function suggested_server_key() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = $host ? strtolower( $host ) : 'site';
		$env  = McpSupport::get_environment();

		// Local MT sandboxes: {client}.d.mt.com
		if ( preg_match( '/^([a-z0-9-]+)\.d\.mt\.com$/i', $host, $m ) ) {
			$base = $m[1] . '-local';
		} elseif ( preg_match( '/^([a-z0-9-]+)\.(dev|stg|staging)\./i', $host, $m ) ) {
			$base = $m[1] . '-' . ( 'stg' === $m[2] || 'staging' === $m[2] ? 'staging' : 'dev' );
		} else {
			// Strip www / www2 so www.maptician.com → maptician, not www.
			$host_brand = preg_replace( '/^www\d*\./i', '', $host );
			if ( preg_match( '/^([a-z0-9-]+)\./i', $host_brand, $m ) ) {
				$base = $m[1];
			} elseif ( preg_match( '/^([a-z0-9-]+)$/i', $host_brand, $m ) ) {
				$base = $m[1];
			} else {
				$base = sanitize_title( get_bloginfo( 'name' ) );
				$base = $base ? $base : 'site';
			}

			$suffix = self::environment_key_suffix( $env );
			if ( $suffix ) {
				$base .= '-' . $suffix;
			}
		}

		$base = preg_replace( '/[^a-z0-9-]+/', '-', $base );
		$base = trim( $base, '-' );
		return $base . '-mcp';
	}

	/**
	 * Short env token for MCP server keys (production → prod).
	 */
	private static function environment_key_suffix( $env ) {
		$map = array(
			'production'  => 'prod',
			'staging'     => 'staging',
			'development' => 'dev',
		);
		return isset( $map[ $env ] ) ? $map[ $env ] : '';
	}

	private function mcp_url() {
		return McpSupport::mcp_resource_url();
	}

	private function oauth_config_json() {
		$server_key = self::suggested_server_key();
		$config     = array(
			'mcpServers' => array(
				$server_key => array(
					'url' => $this->mcp_url(),
				),
			),
		);
		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private function settings_tab_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=settings' );
	}

	private function overview_tab_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=overview' );
	}

	private function render_overview() {
		$enabled    = (bool) get_option( McpSupport::OPTION_ENABLED );
		$server_key = self::suggested_server_key();
		$grants     = McpOAuth::list_active_grants();
		?>
		<?php if ( ! $enabled ) : ?>
			<section class="mt-mcp-card mt-mcp-card-warn">
				<h2>MCP server is off</h2>
				<p class="mt-mcp-help">Agents can’t connect until the server is enabled.</p>
				<p><a class="button button-primary" href="<?php echo esc_url( $this->settings_tab_url() ); ?>">Enable in Settings</a></p>
			</section>
		<?php endif; ?>

		<section class="mt-mcp-card mt-mcp-card-hero">
			<h2>Server URL</h2>
			<p class="mt-mcp-help">Paste this into Claude’s connector settings (Custom connector URL).</p>
			<div class="mt-mcp-code-wrap">
				<input type="text" readonly class="mt-mcp-code" value="<?php echo esc_attr( $this->mcp_url() ); ?>" onclick="this.select();" />
				<button type="button" class="button button-primary mt-mcp-copy" data-copy-target="prev"><?php esc_html_e( 'Copy URL', 'mt-mcp' ); ?></button>
			</div>
		</section>

		<section class="mt-mcp-card">
			<h2>Cursor config</h2>
			<p class="mt-mcp-help">Paste into Cursor’s <code>~/.cursor/mcp.json</code> or a project <code>.cursor/mcp.json</code>. On first connect, Cursor opens a browser to sign in as a WordPress user.</p>
			<div class="mt-mcp-code-wrap">
				<textarea readonly rows="9" class="mt-mcp-code mt-mcp-code-lg" onclick="this.select();"><?php echo esc_textarea( $this->oauth_config_json() ); ?></textarea>
				<button type="button" class="button mt-mcp-copy" data-copy-target="prev"><?php esc_html_e( 'Copy', 'mt-mcp' ); ?></button>
			</div>
			<p class="mt-mcp-help">Server key <code><?php echo esc_html( $server_key ); ?></code> is unique to this site so multiple WordPress MCPs don’t collide.</p>
		</section>

		<section class="mt-mcp-card">
			<h2>Active OAuth grants</h2>
			<?php if ( empty( $grants ) ) : ?>
				<p class="mt-mcp-help">No agents connected yet. Add the JSON above in Cursor, toggle the server on, then approve the consent screen.</p>
			<?php else : ?>
				<?php $this->render_grants_table( $grants ); ?>
			<?php endif; ?>
			<p class="mt-mcp-help">To cut off an agent: Revoke here, and remove Application Passwords under Users → Profile. Uninstalling the plugin does not revoke App Passwords.</p>
		</section>

		<?php
		$review = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => array( 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 10,
				'meta_key'               => '_mt_mcp_needs_review',
				'meta_value'             => '1',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		?>
		<section class="mt-mcp-card">
			<h2>Needs review</h2>
			<p class="mt-mcp-help">Drafts created while Force draft is on. Agents can also call <code>list_needs_review</code> / <code>review_draft</code>.</p>
			<?php if ( ! $review->have_posts() ) : ?>
				<p class="mt-mcp-help">Inbox empty.</p>
			<?php else : ?>
				<ul class="mt-mcp-help">
					<?php
					while ( $review->have_posts() ) :
						$review->the_post();
						?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link() ); ?>"><?php the_title(); ?></a>
							— <?php echo esc_html( get_post_status() ); ?>
							· <a href="<?php echo esc_url( get_preview_post_link() ); ?>">Preview</a>
						</li>
					<?php endwhile; ?>
					<?php wp_reset_postdata(); ?>
				</ul>
				<?php if ( $review->found_posts > 10 ) : ?>
					<p class="mt-mcp-help"><?php echo esc_html( (string) ( $review->found_posts - 10 ) ); ?> more…</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_grants_table( $grants ) {
		?>
		<table class="mt-mcp-table">
			<thead>
				<tr>
					<th>User</th>
					<th>Client</th>
					<th>Scope</th>
					<th>Expires</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $grants as $g ) : ?>
					<tr>
						<td><?php echo esc_html( $g['user_login'] ); ?></td>
						<td><?php echo esc_html( $g['client_name'] ); ?></td>
						<td><code><?php echo esc_html( $g['scope'] ); ?></code></td>
						<td><?php echo esc_html( $g['expires'] ); ?></td>
						<td>
							<a class="button button-small"
							   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mt_mcp_revoke_grant&hash=' . rawurlencode( $g['hash'] ) ), 'mt_mcp_revoke_grant' ) ); ?>"
							   onclick="return confirm('Revoke this grant?');">Revoke</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_settings() {
		$url        = $this->mcp_url();
		$rest       = rest_url( 'mt-mcp/v1' );
		$flushed    = (bool) get_option( McpSupport::OPTION_FLUSHED );
		$enabled    = (bool) get_option( McpSupport::OPTION_ENABLED );
		$locked     = defined( 'MT_MCP_ENVIRONMENT' );
		$stored     = get_option( McpSupport::OPTION_ENVIRONMENT, 'unknown' );
		$mode       = McpAuth::get_auth_mode();
		$groups     = McpSupport::enabled_groups();
		$webhook    = (string) get_option( McpSupport::OPTION_WEBHOOK_URL, '' );
		$app_user   = wp_get_current_user();
		$server_key = self::suggested_server_key();
		$app_config = array(
			'mcpServers' => array(
				$server_key => array(
					'url'     => $url,
					'headers' => array(
						'Authorization' => 'Bearer ' . $app_user->user_login . ':<APPLICATION_PASSWORD>',
					),
				),
			),
		);
		?>
		<div class="mt-mcp-settings" id="mt-mcp-settings">
			<p class="mt-mcp-autosave-hint">Changes save automatically. <span class="mt-mcp-save-status" aria-live="polite"></span></p>

			<section class="mt-mcp-card">
				<h2>Server</h2>
				<p class="mt-mcp-help">Expose this WordPress site as an MCP resource. Agents discover OAuth automatically on first connect.</p>

				<label class="mt-mcp-toggle">
					<input type="checkbox"
						   class="mt-mcp-autosave"
						   data-key="enabled"
						   value="1"
						   <?php checked( $enabled ); ?> />
					<span>Enable MCP server</span>
				</label>

				<div class="mt-mcp-endpoints">
					<div>
						<span class="mt-mcp-label">MCP URL</span>
						<code><?php echo esc_html( $url ); ?></code>
					</div>
					<div>
						<span class="mt-mcp-label">REST fallback</span>
						<code><?php echo esc_html( $rest ); ?></code>
					</div>
				</div>

				<?php if ( $enabled && ! $flushed ) : ?>
					<p class="mt-mcp-notice">If <code>/mt-mcp</code> 404s, visit Permalinks and click Save to flush rewrites. REST always works.</p>
				<?php endif; ?>
			</section>

			<section class="mt-mcp-card">
				<h2>Environment</h2>
				<p class="mt-mcp-help">Told to agents via <code>get_site_context</code>. Use Staging on staging hosts; Production on live.</p>
				<select class="mt-mcp-autosave" data-key="environment" <?php disabled( $locked ); ?>>
					<?php foreach ( McpSupport::ENVIRONMENTS as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $stored, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $locked ) : ?>
					<p class="mt-mcp-help">Locked by <code>MT_MCP_ENVIRONMENT</code> in wp-config.</p>
				<?php else : ?>
					<p class="mt-mcp-help">Optional override: <code>define( 'MT_MCP_ENVIRONMENT', 'staging' );</code></p>
				<?php endif; ?>
			</section>

			<section class="mt-mcp-card">
				<h2>Writes</h2>
				<label class="mt-mcp-toggle">
					<input type="checkbox"
						   class="mt-mcp-autosave"
						   data-key="allow_writes"
						   value="1"
						   <?php checked( get_option( McpSupport::OPTION_ALLOW_WRITES ) ); ?> />
					<span>Allow MCP write tools</span>
				</label>
				<p class="mt-mcp-help">Off by default. When on, create/update/trash and related tools appear — still gated by the signed-in user’s capabilities and OAuth scopes.</p>

				<label class="mt-mcp-toggle">
					<input type="checkbox"
						   class="mt-mcp-autosave"
						   data-key="pause_writes"
						   value="1"
						   <?php checked( get_option( McpSupport::OPTION_PAUSE_WRITES ) ); ?> />
					<span>Pause writes (incident freeze)</span>
				</label>
				<p class="mt-mcp-help">Blocks all write tools except <code>set_write_gates</code>. Reads still work.</p>

				<label class="mt-mcp-toggle">
					<input type="checkbox"
						   class="mt-mcp-autosave"
						   data-key="force_draft"
						   value="1"
						   <?php checked( get_option( McpSupport::OPTION_FORCE_DRAFT ) ); ?> />
					<span>Force draft on create</span>
				</label>
				<p class="mt-mcp-help">New posts are forced to draft and marked <code>_mt_mcp_needs_review</code> for human Preview/Publish.</p>

				<label class="mt-mcp-field">
					<span>Site Brief (agent-immutable owner rules)</span>
					<textarea class="large-text mt-mcp-autosave"
							  rows="3"
							  data-key="site_brief"
							  placeholder="e.g. Never publish without client approval. Keep phone number in Site Options."><?php echo esc_textarea( (string) get_option( McpSupport::OPTION_SITE_BRIEF, '' ) ); ?></textarea>
				</label>
				<p class="mt-mcp-help">Injected into MCP initialize instructions. Max 2000 characters.</p>

				<label class="mt-mcp-field">
					<span>DCR extra redirect hosts</span>
					<input type="text"
						   class="regular-text mt-mcp-autosave"
						   data-key="dcr_extra_hosts"
						   value="<?php echo esc_attr( (string) get_option( McpSupport::OPTION_DCR_HOSTS, '' ) ); ?>"
						   placeholder="tunnel.example.com, staging.local" />
				</label>
				<p class="mt-mcp-help">Comma-separated hosts allowed for OAuth Dynamic Client Registration redirect URIs beyond https / localhost.</p>

				<label class="mt-mcp-field">
					<span>Write-batch webhook URL (optional)</span>
					<div class="mt-mcp-webhook-row">
						<input type="url"
							   id="mt-mcp-webhook-url"
							   class="regular-text"
							   data-key="webhook_url"
							   data-saved="<?php echo esc_attr( $webhook ); ?>"
							   value="<?php echo esc_attr( $webhook ); ?>"
							   placeholder="https://hooks.example.com/mt-mcp" />
						<button type="button" class="button button-primary mt-mcp-webhook-save" hidden>Save</button>
					</div>
				</label>
				<p class="mt-mcp-help">POSTed when an agent calls <code>notify_write_batch</code>. No secrets are sent.</p>
			</section>

			<section class="mt-mcp-card">
				<h2>Authentication</h2>
				<p class="mt-mcp-help">OAuth 2.1 is the default. Application Passwords are optional for scripted clients.</p>

				<div class="mt-mcp-radio-grid">
					<label class="mt-mcp-radio <?php echo 'oauth' === $mode ? 'is-selected' : ''; ?>">
						<input type="radio" class="mt-mcp-autosave" name="mt_mcp_auth_mode" data-key="auth_mode" value="oauth" <?php checked( $mode, 'oauth' ); ?> />
						<strong>OAuth 2.1 only</strong>
						<span>Recommended. Browser login + consent with PKCE for Cursor / Claude.</span>
					</label>
					<label class="mt-mcp-radio <?php echo 'both' === $mode ? 'is-selected' : ''; ?>">
						<input type="radio" class="mt-mcp-autosave" name="mt_mcp_auth_mode" data-key="auth_mode" value="both" <?php checked( $mode, 'both' ); ?> />
						<strong>OAuth + Application Passwords</strong>
						<span>Also allow Users → Profile → Application Passwords for scripts.</span>
					</label>
				</div>

				<details class="mt-mcp-details">
					<summary>Application Password config (advanced)</summary>
					<p class="mt-mcp-help">Create a password under Users → Profile → Application Passwords. Replace the placeholder. Do not commit real passwords.</p>
					<textarea readonly rows="11" class="mt-mcp-code" onclick="this.select();"><?php echo esc_textarea( wp_json_encode( $app_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
				</details>
			</section>

			<section class="mt-mcp-card">
				<h2>Tool groups</h2>
				<p class="mt-mcp-help">Unchecked groups are hidden from <code>tools/list</code> and rejected on <code>tools/call</code>.</p>
				<div class="mt-mcp-check-grid" id="mt-mcp-tool-groups">
					<?php foreach ( McpSupport::TOOL_GROUPS as $key => $label ) : ?>
						<label class="mt-mcp-check">
							<input type="checkbox"
								   class="mt-mcp-tool-group"
								   data-group="<?php echo esc_attr( $key ); ?>"
								   value="1"
								   <?php checked( ! empty( $groups[ $key ] ) ); ?> />
							<span><?php echo esc_html( $label ); ?></span>
							<code><?php echo esc_html( $key ); ?></code>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<details class="mt-mcp-details mt-mcp-details-block">
				<summary>Discovery endpoints</summary>
				<ul class="mt-mcp-list">
					<li><code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></code></li>
					<li><code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource/mt-mcp' ) ); ?></code> <span class="description">(Claude.ai)</span></li>
					<li><code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code></li>
					<li><code><?php echo esc_html( home_url( '/mt-mcp/oauth/authorize' ) ); ?></code></li>
					<li><code><?php echo esc_html( rest_url( 'mt-mcp/v1/oauth/token' ) ); ?></code></li>
					<li><code><?php echo esc_html( rest_url( 'mt-mcp/v1/oauth/register' ) ); ?></code></li>
					<li>Health (no auth): <code><?php echo esc_html( rest_url( 'mt-mcp/v1/health' ) ); ?></code></li>
				</ul>
				<p class="mt-mcp-help">If Claude.ai shows <strong>403</strong> while Cursor works, allowlist Anthropic’s egress <code>160.79.104.0/21</code> in Cloudflare / WP Engine WAF for <code>/mt-mcp*</code> and <code>/.well-known/oauth-*</code>.</p>
			</details>
		</div>
		<?php
	}

	private function render_changes() {
		$entries = McpSupport::get_undo_journal( 40 );
		$notice  = get_transient( 'mt_mcp_undo_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mt_mcp_undo_notice_' . get_current_user_id() );
		}
		?>
		<section class="mt-mcp-card">
			<h2>Per-change undo</h2>
			<p class="mt-mcp-help">Snapshots from MCP writes (72h). Restore reverts content only — OAuth grants and MCP settings are never touched.</p>
			<?php if ( is_array( $notice ) ) : ?>
				<p class="<?php echo empty( $notice['ok'] ) ? 'mt-mcp-notice' : 'mt-mcp-help'; ?>"><?php echo esc_html( $notice['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $entries ) ) : ?>
				<p class="mt-mcp-help">No undoable changes yet. Write tools like <code>update_post</code> return an <code>undo.token</code>.</p>
			<?php else : ?>
				<table class="mt-mcp-table">
					<thead>
						<tr>
							<th>When</th>
							<th>Tool</th>
							<th>Label</th>
							<th>User</th>
							<th>Drift</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) :
							$sum = McpSupport::summarize_change( $entry, false );
							?>
							<tr>
								<td><?php echo esc_html( $sum['ts'] ); ?></td>
								<td><code><?php echo esc_html( $sum['tool'] ); ?></code></td>
								<td><?php echo esc_html( $sum['label'] ); ?><?php echo ! empty( $sum['undone'] ) ? ' <em>(undone)</em>' : ''; ?></td>
								<td><?php echo esc_html( $sum['user'] ? $sum['user'] : '—' ); ?></td>
								<td><?php echo ! empty( $sum['drift'] ) ? 'yes' : '—'; ?></td>
								<td>
									<?php if ( empty( $sum['undone'] ) ) : ?>
										<a class="button button-small"
										   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mt_mcp_undo_change&token=' . rawurlencode( $sum['token'] ) . ( ! empty( $sum['drift'] ) ? '&force=1' : '' ) ), 'mt_mcp_undo_change' ) ); ?>"
										   onclick="return confirm('Restore before-state for this change?');">Undo</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	public function handle_undo_change() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'mt_mcp_undo_change' );
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$force = ! empty( $_GET['force'] );
		$handlers = new McpToolHandlers();
		$ok = true;
		$message = 'Change restored.';
		try {
			$handlers->undo_change(
				array(
					'token' => $token,
					'force' => $force,
				)
			);
		} catch ( \Throwable $e ) {
			$ok = false;
			$message = $e->getMessage();
		}
		set_transient(
			'mt_mcp_undo_notice_' . get_current_user_id(),
			array( 'ok' => $ok, 'message' => $message ),
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=changes' ) );
		exit;
	}

	private function render_activity() {
		$log = McpSupport::get_audit_log( 50 );
		$csv = wp_nonce_url( admin_url( 'admin-post.php?action=mt_mcp_export_audit_csv' ), 'mt_mcp_export_audit_csv' );
		?>
		<section class="mt-mcp-card">
			<h2>Recent tool calls</h2>
			<p class="mt-mcp-help">HMAC-signed ring buffer (last <?php echo (int) McpSupport::AUDIT_MAX_ENTRIES; ?>). <a class="button button-small" href="<?php echo esc_url( $csv ); ?>">Export CSV</a></p>
			<?php if ( empty( $log ) ) : ?>
				<p class="mt-mcp-help">No activity yet.</p>
			<?php else : ?>
				<table class="mt-mcp-table">
					<thead>
						<tr>
							<th>When</th>
							<th>Tool</th>
							<th>User</th>
							<th>OK</th>
							<th>Sig</th>
							<th>Note</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['ts'] ); ?></td>
								<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
								<td><?php echo esc_html( ! empty( $row['user'] ) ? $row['user'] : '—' ); ?></td>
								<td><?php echo ! empty( $row['ok'] ) ? '✓' : '✗'; ?></td>
								<td><?php echo McpSupport::verify_audit_entry( $row ) ? '✓' : '✗'; ?></td>
								<td><?php echo esc_html( isset( $row['message'] ) ? $row['message'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_playground() {
		$result = get_transient( 'mt_mcp_simulate_result_' . get_current_user_id() );
		delete_transient( 'mt_mcp_simulate_result_' . get_current_user_id() );
		$tools = array( 'get_site_context', 'list_post_types', 'seo_audit', 'content_review', 'get_site_health', 'suggest_internal_links' );
		?>
		<section class="mt-mcp-card">
			<h2>Simulate agent (dry-run)</h2>
			<p class="mt-mcp-help">Runs a read tool as <em>your</em> admin user without going through MCP. Useful for sanity checks.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mt_mcp_simulate_tool' ); ?>
				<input type="hidden" name="action" value="mt_mcp_simulate_tool" />
				<label class="mt-mcp-field">
					<span>Tool</span>
					<select name="tool">
						<?php foreach ( $tools as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="mt-mcp-field">
					<span>Args JSON (optional)</span>
					<textarea name="args_json" rows="4" class="large-text code" placeholder='{"post_type":"page"}'></textarea>
				</label>
				<?php submit_button( 'Run dry simulation', 'secondary' ); ?>
			</form>
			<?php if ( $result ) : ?>
				<h3>Result</h3>
				<textarea readonly rows="16" class="mt-mcp-code"><?php echo esc_textarea( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
			<?php endif; ?>
		</section>
		<?php
	}

	public function handle_export_audit_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mt_mcp_export_audit_csv' );
		$csv = McpSupport::audit_log_csv( McpSupport::AUDIT_MAX_ENTRIES );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mt-mcp-audit.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_simulate_tool() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mt_mcp_simulate_tool' );
		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		$args = array();
		if ( ! empty( $_POST['args_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['args_json'] ), true );
			if ( is_array( $decoded ) ) {
				$args = $decoded;
			}
		}
		$handlers = new McpToolHandlers();
		try {
			if ( ! method_exists( $handlers, $tool ) ) {
				throw new \Exception( 'Unknown or non-simulatable tool.' );
			}
			$result = call_user_func( array( $handlers, $tool ), $args );
		} catch ( \Throwable $e ) {
			$result = array( 'error' => $e->getMessage() );
		}
		set_transient( 'mt_mcp_simulate_result_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=playground' ) );
		exit;
	}

	public function handle_revoke_grant() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mt_mcp_revoke_grant' );
		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		if ( $hash ) {
			McpOAuth::revoke_grant_by_hash_prefix( $hash );
		}
		wp_safe_redirect( $this->overview_tab_url() );
		exit;
	}

	public function handle_revoke_user() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mt_mcp_revoke_user' );
		$uid = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		if ( $uid ) {
			McpOAuth::revoke_all_for_user( $uid );
		}
		wp_safe_redirect( $this->overview_tab_url() );
		exit;
	}
}
