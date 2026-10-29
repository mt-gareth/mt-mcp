<?php

namespace MT\Mcp;

/**
 * Public (unauthenticated) endpoints used by agents for client-facing flows:
 * signed draft preview links and one-shot media upload URLs.
 */
class McpPublic {

	const PREVIEW_QUERY  = 'mt_mcp_preview';
	const UPLOAD_QUERY   = 'mt_mcp_upload';
	const PREVIEW_TTL    = WEEK_IN_SECONDS;
	const UPLOAD_TTL     = HOUR_IN_SECONDS;
	const STATE_TTL      = 15 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_upload' ), 0 );
		add_action( 'pre_get_posts', array( $this, 'maybe_preview_query' ) );
		add_filter( 'the_posts', array( $this, 'maybe_inject_preview_post' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'preview_banner_css' ), 1 );
		add_action( 'wp_footer', array( $this, 'preview_banner' ) );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^mt-mcp-preview/([^/]+)/?$', 'index.php?' . self::PREVIEW_QUERY . '=$matches[1]', 'top' );
		add_rewrite_rule( '^mt-mcp-upload/([^/]+)/?$', 'index.php?' . self::UPLOAD_QUERY . '=$matches[1]', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::PREVIEW_QUERY;
		$vars[] = self::UPLOAD_QUERY;
		return $vars;
	}

	/**
	 * Create a shareable draft preview URL (no WP login required).
	 *
	 * @param int $post_id Post ID.
	 * @param int $ttl     Seconds.
	 * @return array{url:string,token:string,expires_at:string,post_id:int}
	 */
	public static function create_preview_link( $post_id, $ttl = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		$ttl   = $ttl ? max( MINUTE_IN_SECONDS, min( (int) $ttl, 30 * DAY_IN_SECONDS ) ) : self::PREVIEW_TTL;
		$token = bin2hex( random_bytes( 24 ) );
		$key   = self::preview_transient_key( $token );
		$payload = array(
			'post_id' => (int) $post_id,
			'exp'     => time() + $ttl,
			'user_id' => get_current_user_id(),
		);
		set_transient( $key, $payload, $ttl );
		$url = home_url( '/mt-mcp-preview/' . rawurlencode( $token ) . '/' );
		return array(
			'post_id'    => (int) $post_id,
			'token'      => $token,
			'url'        => $url,
			'expires_at' => gmdate( 'c', $payload['exp'] ),
			'ttl'        => $ttl,
			'status'     => $post->post_status,
			'title'      => get_the_title( $post ),
		);
	}

	public static function revoke_preview_link( $token ) {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( ! $token ) {
			throw new \Exception( 'Invalid token.' );
		}
		delete_transient( self::preview_transient_key( $token ) );
		return array( 'revoked' => true, 'token' => $token );
	}

	/**
	 * One-shot upload ticket for large/Figma assets (avoids base64 in chat).
	 *
	 * @param array $args alt (required), filename?, parent_post_id?, max_bytes?, ttl?, title?
	 * @return array
	 */
	public static function create_upload_ticket( $args ) {
		$alt = isset( $args['alt'] ) ? trim( (string) $args['alt'] ) : '';
		if ( '' === $alt ) {
			throw new \Exception( 'alt is required for upload tickets.' );
		}
		$ttl       = isset( $args['ttl'] ) ? max( MINUTE_IN_SECONDS, min( (int) $args['ttl'], DAY_IN_SECONDS ) ) : self::UPLOAD_TTL;
		$max_bytes = isset( $args['max_bytes'] ) ? max( 1024, min( (int) $args['max_bytes'], 50 * 1024 * 1024 ) ) : ( 25 * 1024 * 1024 );
		$token     = bin2hex( random_bytes( 24 ) );
		$payload   = array(
			'alt'            => $alt,
			'title'          => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '',
			'filename'       => isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '',
			'parent_post_id' => isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0,
			'max_bytes'      => $max_bytes,
			'exp'            => time() + $ttl,
			'user_id'        => get_current_user_id(),
			'used'           => false,
		);
		set_transient( self::upload_transient_key( $token ), $payload, $ttl );
		$url = home_url( '/mt-mcp-upload/' . rawurlencode( $token ) . '/' );
		return array(
			'token'      => $token,
			'url'        => $url,
			'method'     => 'POST',
			'expires_at' => gmdate( 'c', $payload['exp'] ),
			'max_bytes'  => $max_bytes,
			'alt'        => $alt,
			'hint'       => 'POST multipart/form-data with field "file" (or raw body + Content-Type). One-shot; expires after use or ttl.',
		);
	}

	/**
	 * Two-phase confirm token for destructive ops.
	 *
	 * @param string $operation Operation name.
	 * @param array  $payload   Opaque payload consumed on apply.
	 * @param int    $ttl       Seconds.
	 * @return array{state_token:string,expires_at:string,operation:string,preview:array}
	 */
	public static function create_state_token( $operation, $payload, $preview = array(), $ttl = null ) {
		$ttl   = $ttl ? max( 60, min( (int) $ttl, HOUR_IN_SECONDS ) ) : self::STATE_TTL;
		$token = bin2hex( random_bytes( 20 ) );
		set_transient(
			self::state_transient_key( $token ),
			array(
				'operation' => sanitize_key( $operation ),
				'payload'   => $payload,
				'exp'       => time() + $ttl,
				'user_id'   => get_current_user_id(),
			),
			$ttl
		);
		return array(
			'state_token' => $token,
			'expires_at'  => gmdate( 'c', time() + $ttl ),
			'operation'   => sanitize_key( $operation ),
			'preview'     => $preview,
			'hint'        => 'Re-call the same tool with state_token to apply. Token is one-shot.',
		);
	}

	/**
	 * Consume a state token (one-shot). Returns payload or throws.
	 *
	 * @param string $token     Token.
	 * @param string $operation Expected operation.
	 * @return array
	 */
	public static function consume_state_token( $token, $operation ) {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( ! $token ) {
			throw new \Exception( 'Missing or invalid state_token.' );
		}
		$key  = self::state_transient_key( $token );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) ) {
			throw new \Exception( 'state_token expired or already used.' );
		}
		if ( ! empty( $data['exp'] ) && time() > (int) $data['exp'] ) {
			throw new \Exception( 'state_token expired.' );
		}
		if ( sanitize_key( $operation ) !== sanitize_key( $data['operation'] ) ) {
			throw new \Exception( 'state_token operation mismatch.' );
		}
		if ( ! empty( $data['user_id'] ) && get_current_user_id() && (int) $data['user_id'] !== get_current_user_id() ) {
			throw new \Exception( 'state_token was issued to a different user.' );
		}
		return isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();
	}

	private static function preview_transient_key( $token ) {
		return 'mt_mcp_prev_' . hash( 'sha256', $token );
	}

	private static function upload_transient_key( $token ) {
		return 'mt_mcp_up_' . hash( 'sha256', $token );
	}

	private static function state_transient_key( $token ) {
		return 'mt_mcp_st_' . hash( 'sha256', $token );
	}

	private function preview_token_from_request() {
		$token = get_query_var( self::PREVIEW_QUERY );
		if ( ! $token && isset( $_GET[ self::PREVIEW_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = wp_unslash( $_GET[ self::PREVIEW_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		return $token ? $token : '';
	}

	private function resolve_preview_post_id() {
		$token = $this->preview_token_from_request();
		if ( ! $token ) {
			return 0;
		}
		$data = get_transient( self::preview_transient_key( $token ) );
		if ( ! is_array( $data ) || empty( $data['post_id'] ) ) {
			return 0;
		}
		if ( ! empty( $data['exp'] ) && time() > (int) $data['exp'] ) {
			delete_transient( self::preview_transient_key( $token ) );
			return 0;
		}
		return (int) $data['post_id'];
	}

	public function maybe_preview_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$post_id = $this->resolve_preview_post_id();
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$query->set( 'p', $post_id );
		$query->set( 'post_type', $post->post_type );
		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'future', 'private' ) );
		$query->is_singular = true;
		$query->is_single   = ( 'page' !== $post->post_type );
		$query->is_page     = ( 'page' === $post->post_type );
		$query->is_home     = false;
		$query->is_404      = false;
	}

	public function maybe_inject_preview_post( $posts, $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}
		$post_id = $this->resolve_preview_post_id();
		if ( ! $post_id ) {
			return $posts;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $posts;
		}
		return array( $post );
	}

	public function preview_banner_css() {
		if ( ! $this->resolve_preview_post_id() ) {
			return;
		}
		echo '<style id="mt-mcp-preview-banner">.mt-mcp-preview-banner{position:fixed;z-index:99999;left:0;right:0;top:0;background:#111;color:#fff;font:14px/1.4 system-ui,sans-serif;padding:10px 16px;text-align:center}.mt-mcp-preview-banner strong{color:#f6c344}body{padding-top:44px !important}</style>';
	}

	public function preview_banner() {
		$post_id = $this->resolve_preview_post_id();
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		echo '<div class="mt-mcp-preview-banner" role="status">MT MCP draft preview · <strong>' . esc_html( get_the_title( $post ) ) . '</strong> · status: ' . esc_html( $post->post_status ) . ' · not publicly indexed</div>';
	}

	public function maybe_handle_upload() {
		$token = get_query_var( self::UPLOAD_QUERY );
		if ( ! $token && isset( $_GET[ self::UPLOAD_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = wp_unslash( $_GET[ self::UPLOAD_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$path = untrailingslashit( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ) );
		if ( ! $token && preg_match( '#/mt-mcp-upload/([a-f0-9]+)/?$#i', $path, $m ) ) {
			$token = $m[1];
		}
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( ! $token ) {
			return;
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, Content-Disposition' );
			status_header( 204 );
			exit;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Allow: POST, OPTIONS' );
			echo wp_json_encode( array( 'error' => 'POST a file to this one-shot upload URL.' ) );
			exit;
		}

		$key  = self::upload_transient_key( $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! empty( $data['used'] ) ) {
			status_header( 410 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'Upload token expired or already used.' ) );
			exit;
		}
		if ( ! empty( $data['exp'] ) && time() > (int) $data['exp'] ) {
			delete_transient( $key );
			status_header( 410 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'Upload token expired.' ) );
			exit;
		}

		// Mark used immediately (one-shot).
		$data['used'] = true;
		set_transient( $key, $data, MINUTE_IN_SECONDS );
		delete_transient( $key );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp      = '';
		$filename = ! empty( $data['filename'] ) ? $data['filename'] : '';

		try {
			if ( ! empty( $_FILES['file'] ) && is_array( $_FILES['file'] ) ) {
				$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! empty( $file['error'] ) ) {
					throw new \Exception( 'Upload error code ' . (int) $file['error'] );
				}
				if ( (int) $file['size'] > (int) $data['max_bytes'] ) {
					throw new \Exception( 'File exceeds max_bytes (' . (int) $data['max_bytes'] . ').' );
				}
				$tmp      = $file['tmp_name'];
				$filename = $filename ? $filename : sanitize_file_name( $file['name'] );
				// media_handle_sideload expects a path we own; copy from php tmp.
				$owned = wp_tempnam( $filename );
				if ( ! @copy( $tmp, $owned ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					throw new \Exception( 'Could not stage uploaded file.' );
				}
				$tmp = $owned;
			} else {
				$raw = file_get_contents( 'php://input' );
				if ( false === $raw || '' === $raw ) {
					throw new \Exception( 'Provide multipart file field "file" or a raw request body.' );
				}
				if ( strlen( $raw ) > (int) $data['max_bytes'] ) {
					throw new \Exception( 'File exceeds max_bytes (' . (int) $data['max_bytes'] . ').' );
				}
				if ( ! $filename ) {
					$ct = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';
					$filename = ( false !== strpos( $ct, 'png' ) ) ? 'upload.png' : ( ( false !== strpos( $ct, 'jpeg' ) || false !== strpos( $ct, 'jpg' ) ) ? 'upload.jpg' : 'upload.bin' );
				}
				$tmp = wp_tempnam( $filename );
				file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			if ( ! empty( $data['user_id'] ) ) {
				wp_set_current_user( (int) $data['user_id'] );
			}

			$id = media_handle_sideload(
				array(
					'name'     => sanitize_file_name( $filename ),
					'tmp_name' => $tmp,
				),
				isset( $data['parent_post_id'] ) ? (int) $data['parent_post_id'] : 0,
				null,
				array(
					'post_title' => $data['title'] ? $data['title'] : pathinfo( $filename, PATHINFO_FILENAME ),
				)
			);
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new \Exception( $id->get_error_message() );
			}
			update_post_meta( $id, '_wp_attachment_image_alt', $data['alt'] );
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				update_post_meta( $id, '_mt_mcp_sha256', hash_file( 'sha256', $file ) );
			}

			$handlers = new McpToolHandlers();
			$out      = $handlers->get_media( array( 'id' => $id ) );
			$out['uploaded'] = true;
			$out['via']      = 'upload_ticket';

			status_header( 201 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Access-Control-Allow-Origin: *' );
			echo wp_json_encode( $out );
			exit;
		} catch ( \Throwable $e ) {
			status_header( 400 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => $e->getMessage() ) );
			exit;
		}
	}
}
