<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-site content packages: export a post (+ referenced media) from one
 * mt-mcp site and import it on another with URL rewrite and attachment remap.
 */
class McpTransfer {

	const PACKAGE_VERSION = 1;
	const MAX_MEDIA       = 40;
	const MAX_FILE_BYTES  = 15728640; // 15MB per file when embedding

	/**
	 * Build an export package for a post.
	 *
	 * @param array $args id|url, include_media (default true), embed_media (default false — include base64; otherwise source URLs only)
	 */
	public static function export_post( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}

		$include_media = ! isset( $args['include_media'] ) || ! empty( $args['include_media'] );
		$embed_media   = ! empty( $args['embed_media'] );

		$handlers = new McpToolHandlers();
		$full     = $handlers->get_post(
			array(
				'id'      => $post_id,
				'include' => array( 'core', 'taxonomies', 'featured_image', 'yoast', 'acf' ),
			)
		);

		$media_ids = array();
		if ( ! empty( $full['featured_image']['id'] ) ) {
			$media_ids[] = (int) $full['featured_image']['id'];
		}
		$media_ids = array_merge( $media_ids, self::collect_attachment_ids_from_content( $post->post_content ) );
		if ( ! empty( $full['acf'] ) ) {
			$media_ids = array_merge( $media_ids, self::collect_attachment_ids_from_value( $full['acf'] ) );
		}
		$media_ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );

		if ( count( $media_ids ) > self::MAX_MEDIA ) {
			$media_ids = array_slice( $media_ids, 0, self::MAX_MEDIA );
		}

		$media = array();
		if ( $include_media ) {
			foreach ( $media_ids as $aid ) {
				$item = self::export_attachment( $aid, $embed_media );
				if ( $item ) {
					$media[] = $item;
				}
			}
		}

		return array(
			'package_version' => self::PACKAGE_VERSION,
			'exported_at'     => gmdate( 'c' ),
			'source'          => array(
				'site_url'    => home_url( '/' ),
				'site_name'   => get_bloginfo( 'name' ),
				'environment' => McpSupport::get_environment(),
				'post_id'     => $post_id,
				'permalink'   => get_permalink( $post_id ),
			),
			'post'            => array(
				'post_type'   => $post->post_type,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_status' => $post->post_status,
				'post_content'=> $post->post_content,
				'post_excerpt'=> $post->post_excerpt,
				'menu_order'  => (int) $post->menu_order,
				'parent_slug' => $post->post_parent ? get_post_field( 'post_name', $post->post_parent ) : '',
				'taxonomies'  => isset( $full['taxonomies'] ) ? $full['taxonomies'] : array(),
				'yoast'       => isset( $full['yoast'] ) ? $full['yoast'] : array(),
				'acf'         => isset( $full['acf'] ) ? $full['acf'] : array(),
				'featured_image_source_id' => ! empty( $full['featured_image']['id'] ) ? (int) $full['featured_image']['id'] : null,
			),
			'media'           => $media,
			'map_hints'       => array(
				'prefer_match_by' => 'slug',
				'source_upload_url' => trailingslashit( home_url( '/wp-content/uploads' ) ),
			),
		);
	}

	private static function export_attachment( $id, $embed ) {
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return null;
		}
		$file = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$item = array(
			'source_id'   => (int) $id,
			'source_url'  => $url,
			'filename'    => $file ? basename( $file ) : basename( (string) $url ),
			'mime'        => $att->post_mime_type,
			'title'       => get_the_title( $att ),
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $att->post_excerpt,
			'description' => $att->post_content,
			'sha256'      => ( $file && file_exists( $file ) ) ? hash_file( 'sha256', $file ) : null,
		);
		if ( $embed && $file && file_exists( $file ) && filesize( $file ) <= self::MAX_FILE_BYTES ) {
			$item['data_base64'] = base64_encode( file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return $item;
	}

	/**
	 * Import a package onto this site.
	 *
	 * Args:
	 * - package (required): export payload
	 * - mode: update|create (default update — match by slug, else create)
	 * - target_status: override status (e.g. draft)
	 * - source_url / target_url: host rewrite (defaults: package.source.site_url → this home_url)
	 * - dry_run: bool
	 * - skip_media: bool
	 */
	public static function import_post( $args ) {
		if ( empty( $args['package'] ) || ! is_array( $args['package'] ) ) {
			throw new \Exception( 'Provide package (output of export_post).' );
		}
		$pkg = $args['package'];
		if ( empty( $pkg['post'] ) || ! is_array( $pkg['post'] ) ) {
			throw new \Exception( 'Invalid package: missing post.' );
		}

		$mode          = isset( $args['mode'] ) ? $args['mode'] : 'update';
		$dry_run       = ! empty( $args['dry_run'] );
		$skip_media    = ! empty( $args['skip_media'] );
		$target_status = isset( $args['target_status'] ) ? $args['target_status'] : null;

		$source_url = ! empty( $args['source_url'] )
			? trailingslashit( $args['source_url'] )
			: ( ! empty( $pkg['source']['site_url'] ) ? trailingslashit( $pkg['source']['site_url'] ) : '' );
		$target_url = ! empty( $args['target_url'] )
			? trailingslashit( $args['target_url'] )
			: trailingslashit( home_url( '/' ) );

		$post_data = $pkg['post'];
		$slug      = isset( $post_data['post_name'] ) ? $post_data['post_name'] : '';
		$type      = isset( $post_data['post_type'] ) ? $post_data['post_type'] : 'page';

		$existing_id = 0;
		if ( 'create' !== $mode && $slug ) {
			$found = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $found ) {
				$existing_id = (int) $found[0];
			}
		}
		if ( ! empty( $args['target_id'] ) ) {
			$existing_id = (int) $args['target_id'];
		}

		// Plan media map first.
		$id_map   = array(); // source_id => target_id
		$url_map  = array(); // source_url => target_url
		$media_plan = array();
		if ( ! $skip_media && ! empty( $pkg['media'] ) && is_array( $pkg['media'] ) ) {
			foreach ( $pkg['media'] as $m ) {
				$plan = self::plan_media_item( $m, $dry_run );
				$media_plan[] = $plan;
				if ( ! empty( $plan['target_id'] ) && ! empty( $m['source_id'] ) ) {
					$id_map[ (int) $m['source_id'] ] = (int) $plan['target_id'];
				}
				if ( ! empty( $m['source_url'] ) && ! empty( $plan['target_url'] ) ) {
					$url_map[ $m['source_url'] ] = $plan['target_url'];
				}
			}
		}

		$content = isset( $post_data['post_content'] ) ? $post_data['post_content'] : '';
		$excerpt = isset( $post_data['post_excerpt'] ) ? $post_data['post_excerpt'] : '';
		$acf     = isset( $post_data['acf'] ) && is_array( $post_data['acf'] ) ? $post_data['acf'] : array();
		$yoast   = isset( $post_data['yoast'] ) && is_array( $post_data['yoast'] ) ? $post_data['yoast'] : array();

		$content = self::rewrite_urls( $content, $source_url, $target_url, $url_map );
		$excerpt = self::rewrite_urls( $excerpt, $source_url, $target_url, $url_map );
		$content = self::rewrite_attachment_ids_in_content( $content, $id_map );
		$acf     = self::rewrite_value( $acf, $source_url, $target_url, $url_map, $id_map );
		$yoast   = self::rewrite_value( $yoast, $source_url, $target_url, $url_map, $id_map );

		$featured_target = null;
		if ( ! empty( $post_data['featured_image_source_id'] ) && isset( $id_map[ (int) $post_data['featured_image_source_id'] ] ) ) {
			$featured_target = $id_map[ (int) $post_data['featured_image_source_id'] ];
		}

		$status = $target_status ? $target_status : ( isset( $post_data['post_status'] ) ? $post_data['post_status'] : 'draft' );

		$core = array(
			'post_type'    => $type,
			'post_title'   => isset( $post_data['post_title'] ) ? $post_data['post_title'] : '',
			'post_name'    => $slug,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'menu_order'   => isset( $post_data['menu_order'] ) ? (int) $post_data['menu_order'] : 0,
		);

		if ( $dry_run ) {
			return array(
				'dry_run'     => true,
				'action'      => $existing_id ? 'update' : 'create',
				'target_id'   => $existing_id ?: null,
				'core'        => array(
					'post_title'  => $core['post_title'],
					'post_name'   => $core['post_name'],
					'post_type'   => $core['post_type'],
					'post_status' => $core['post_status'],
				),
				'source_url'  => $source_url,
				'target_url'  => $target_url,
				'media_plan'  => $media_plan,
				'id_map'      => $id_map,
				'url_rewrites'=> array_slice( $url_map, 0, 50, true ),
				'featured_image_id' => $featured_target,
			);
		}

		// Actually import media that still needs uploading.
		foreach ( $media_plan as $i => $plan ) {
			if ( ! empty( $plan['needs_upload'] ) && ! empty( $pkg['media'][ $i ] ) ) {
				$uploaded = self::upload_media_item( $pkg['media'][ $i ] );
				$media_plan[ $i ] = array_merge( $plan, $uploaded, array( 'needs_upload' => false ) );
				if ( ! empty( $uploaded['target_id'] ) && ! empty( $pkg['media'][ $i ]['source_id'] ) ) {
					$id_map[ (int) $pkg['media'][ $i ]['source_id'] ] = (int) $uploaded['target_id'];
				}
				if ( ! empty( $pkg['media'][ $i ]['source_url'] ) && ! empty( $uploaded['target_url'] ) ) {
					$url_map[ $pkg['media'][ $i ]['source_url'] ] = $uploaded['target_url'];
				}
			}
		}

		// Re-apply rewrites with final maps (in case dry plan reused existing and upload filled gaps).
		$content = isset( $post_data['post_content'] ) ? $post_data['post_content'] : '';
		$excerpt = isset( $post_data['post_excerpt'] ) ? $post_data['post_excerpt'] : '';
		$acf     = isset( $post_data['acf'] ) && is_array( $post_data['acf'] ) ? $post_data['acf'] : array();
		$yoast   = isset( $post_data['yoast'] ) && is_array( $post_data['yoast'] ) ? $post_data['yoast'] : array();
		$content = self::rewrite_urls( $content, $source_url, $target_url, $url_map );
		$excerpt = self::rewrite_urls( $excerpt, $source_url, $target_url, $url_map );
		$content = self::rewrite_attachment_ids_in_content( $content, $id_map );
		$acf     = self::rewrite_value( $acf, $source_url, $target_url, $url_map, $id_map );
		$yoast   = self::rewrite_value( $yoast, $source_url, $target_url, $url_map, $id_map );
		$core['post_content'] = $content;
		$core['post_excerpt'] = $excerpt;

		if ( ! empty( $post_data['featured_image_source_id'] ) && isset( $id_map[ (int) $post_data['featured_image_source_id'] ] ) ) {
			$featured_target = $id_map[ (int) $post_data['featured_image_source_id'] ];
		}

		if ( $existing_id ) {
			$core['ID'] = $existing_id;
			$result_id  = wp_update_post( $core, true );
		} else {
			$result_id = wp_insert_post( $core, true );
		}
		if ( is_wp_error( $result_id ) ) {
			throw new \Exception( $result_id->get_error_message() );
		}
		$post_id = (int) $result_id;

		if ( ! empty( $post_data['taxonomies'] ) && is_array( $post_data['taxonomies'] ) ) {
			foreach ( $post_data['taxonomies'] as $tax => $terms ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$names = array();
				foreach ( (array) $terms as $t ) {
					if ( is_array( $t ) && isset( $t['name'] ) ) {
						$names[] = $t['name'];
					} elseif ( is_string( $t ) ) {
						$names[] = $t;
					}
				}
				if ( $names ) {
					wp_set_post_terms( $post_id, $names, $tax );
				}
			}
		}

		foreach ( $yoast as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				McpSupport::set_yoast_value( $key, $value, $post_id );
			}
		}

		if ( $acf && function_exists( 'update_field' ) ) {
			foreach ( $acf as $key => $value ) {
				update_field( $key, $value, $post_id );
			}
		}

		if ( $featured_target ) {
			set_post_thumbnail( $post_id, $featured_target );
		}

		$handlers = new McpToolHandlers();
		$out      = $handlers->get_post( array( 'id' => $post_id ) );
		$out['imported'] = true;
		$out['action']   = $existing_id ? 'update' : 'create';
		$out['media_plan'] = $media_plan;
		$out['id_map']     = $id_map;
		return $out;
	}

	private static function plan_media_item( $m, $dry_run ) {
		$existing = self::find_existing_attachment( $m );
		if ( $existing ) {
			return array(
				'source_id'   => isset( $m['source_id'] ) ? (int) $m['source_id'] : null,
				'target_id'   => $existing,
				'target_url'  => wp_get_attachment_url( $existing ),
				'action'      => 'reuse',
				'needs_upload'=> false,
			);
		}
		return array(
			'source_id'    => isset( $m['source_id'] ) ? (int) $m['source_id'] : null,
			'source_url'   => isset( $m['source_url'] ) ? $m['source_url'] : null,
			'filename'     => isset( $m['filename'] ) ? $m['filename'] : null,
			'action'       => 'upload',
			'needs_upload' => true,
			'target_id'    => null,
			'target_url'   => null,
			'dry_run'      => $dry_run,
		);
	}

	private static function find_existing_attachment( $m ) {
		if ( ! empty( $m['sha256'] ) ) {
			$q = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 20,
					'meta_key'       => '_mt_mcp_sha256',
					'meta_value'     => $m['sha256'],
					'fields'         => 'ids',
				)
			);
			if ( $q->posts ) {
				return (int) $q->posts[0];
			}
		}
		if ( ! empty( $m['filename'] ) ) {
			$filename = $m['filename'];
			$q = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 5,
					's'              => pathinfo( $filename, PATHINFO_FILENAME ),
					'fields'         => 'ids',
				)
			);
			foreach ( $q->posts as $aid ) {
				$file = get_attached_file( $aid );
				if ( $file && basename( $file ) === $filename ) {
					return (int) $aid;
				}
			}
		}
		return 0;
	}

	private static function upload_media_item( $m ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = '';
		if ( ! empty( $m['data_base64'] ) ) {
			$bytes = base64_decode( $m['data_base64'] );
			if ( false === $bytes ) {
				throw new \Exception( 'Invalid base64 for media ' . ( isset( $m['filename'] ) ? $m['filename'] : '' ) );
			}
			$tmp = wp_tempnam( isset( $m['filename'] ) ? $m['filename'] : 'upload.bin' );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} elseif ( ! empty( $m['source_url'] ) ) {
			$downloaded = download_url( $m['source_url'], 60 );
			if ( is_wp_error( $downloaded ) ) {
				throw new \Exception( 'Failed to download ' . $m['source_url'] . ': ' . $downloaded->get_error_message() );
			}
			$tmp = $downloaded;
		} else {
			throw new \Exception( 'Media item missing data_base64 and source_url.' );
		}

		$filename = ! empty( $m['filename'] ) ? $m['filename'] : basename( $tmp );
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file_array, 0, null, array(
			'post_title'   => isset( $m['title'] ) ? $m['title'] : $filename,
			'post_excerpt' => isset( $m['caption'] ) ? $m['caption'] : '',
			'post_content' => isset( $m['description'] ) ? $m['description'] : '',
		) );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \Exception( $id->get_error_message() );
		}
		if ( isset( $m['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $m['alt'] );
		}
		if ( ! empty( $m['sha256'] ) ) {
			update_post_meta( $id, '_mt_mcp_sha256', $m['sha256'] );
		} else {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				update_post_meta( $id, '_mt_mcp_sha256', hash_file( 'sha256', $file ) );
			}
		}

		return array(
			'target_id'  => (int) $id,
			'target_url' => wp_get_attachment_url( $id ),
			'action'     => 'uploaded',
		);
	}

	public static function collect_attachment_ids_from_content( $content ) {
		$ids = array();
		if ( preg_match_all( '/wp-image-(\d+)/', (string) $content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}
		if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([\d,\s]+)["\']/', (string) $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/\s*,\s*/', $list ) as $id ) {
					if ( $id ) {
						$ids[] = (int) $id;
					}
				}
			}
		}
		// URLs under uploads → attachment id.
		if ( preg_match_all( '#https?://[^"\'\s]+/wp-content/uploads/[^"\'\s]+#', (string) $content, $m ) ) {
			foreach ( $m[0] as $url ) {
				$id = attachment_url_to_postid( $url );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	public static function collect_attachment_ids_from_value( $value ) {
		$ids = array();
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$p = get_post( (int) $value );
			if ( $p && 'attachment' === $p->post_type ) {
				$ids[] = (int) $value;
			}
			return $ids;
		}
		if ( is_array( $value ) ) {
			// ACF image array shape.
			if ( isset( $value['ID'] ) && isset( $value['url'] ) ) {
				$ids[] = (int) $value['ID'];
				return $ids;
			}
			if ( isset( $value['id'] ) && isset( $value['url'] ) ) {
				$ids[] = (int) $value['id'];
				return $ids;
			}
			foreach ( $value as $v ) {
				$ids = array_merge( $ids, self::collect_attachment_ids_from_value( $v ) );
			}
		}
		return $ids;
	}

	private static function rewrite_urls( $text, $source_url, $target_url, $url_map ) {
		$text = (string) $text;
		foreach ( $url_map as $from => $to ) {
			if ( $from && $to ) {
				$text = str_replace( $from, $to, $text );
				// Also try without protocol-relative variants.
				$text = str_replace( set_url_scheme( $from, 'https' ), $to, $text );
				$text = str_replace( set_url_scheme( $from, 'http' ), $to, $text );
			}
		}
		if ( $source_url && $target_url && $source_url !== $target_url ) {
			$text = str_replace( $source_url, $target_url, $text );
			$text = str_replace( untrailingslashit( $source_url ), untrailingslashit( $target_url ), $text );
			$src_host = wp_parse_url( $source_url, PHP_URL_HOST );
			$tgt_host = wp_parse_url( $target_url, PHP_URL_HOST );
			if ( $src_host && $tgt_host ) {
				$text = str_replace( '//' . $src_host, '//' . $tgt_host, $text );
			}
		}
		return $text;
	}

	private static function rewrite_attachment_ids_in_content( $content, $id_map ) {
		if ( ! $id_map ) {
			return $content;
		}
		$content = preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $m ) use ( $id_map ) {
				$old = (int) $m[1];
				return isset( $id_map[ $old ] ) ? 'wp-image-' . $id_map[ $old ] : $m[0];
			},
			$content
		);
		$content = preg_replace_callback(
			'/data-id=["\'](\d+)["\']/',
			function ( $m ) use ( $id_map ) {
				$old = (int) $m[1];
				return isset( $id_map[ $old ] ) ? 'data-id="' . $id_map[ $old ] . '"' : $m[0];
			},
			$content
		);
		return $content;
	}

	private static function rewrite_value( $value, $source_url, $target_url, $url_map, $id_map ) {
		if ( is_string( $value ) ) {
			return self::rewrite_urls( $value, $source_url, $target_url, $url_map );
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( (string) $value ) ) ) {
			$i = (int) $value;
			return isset( $id_map[ $i ] ) ? $id_map[ $i ] : $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// ACF image array.
		if ( isset( $value['ID'] ) && isset( $value['url'] ) ) {
			$old = (int) $value['ID'];
			if ( isset( $id_map[ $old ] ) ) {
				$new_id = $id_map[ $old ];
				$value['ID']  = $new_id;
				$value['id']  = $new_id;
				$value['url'] = wp_get_attachment_url( $new_id );
				if ( isset( $value['sizes'] ) && is_array( $value['sizes'] ) ) {
					// Drop size URLs; ACF will regenerate on read. Keep structure minimal.
					unset( $value['sizes'] );
				}
			} else {
				$value['url'] = self::rewrite_urls( $value['url'], $source_url, $target_url, $url_map );
			}
			return $value;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::rewrite_value( $v, $source_url, $target_url, $url_map, $id_map );
		}
		return $out;
	}
}
