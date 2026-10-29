<?php

namespace MT\Mcp;

/**
 * Implementations for every MCP tool exposed by McpServer.
 */
class McpToolHandlers {

	const MAX_PER_PAGE = 100;
	const MAX_BATCH    = 50;

	public function get_site_context( $args = array() ) {
		$theme = wp_get_theme();
		$env   = McpSupport::get_environment();
		$user  = wp_get_current_user();
		$auth  = array(
			'authenticated' => (bool) ( $user && $user->ID ),
			'user_id'       => $user && $user->ID ? (int) $user->ID : 0,
			'user_login'    => $user && $user->ID ? $user->user_login : '',
			'display_name'  => $user && $user->ID ? $user->display_name : '',
			'roles'         => $user && $user->ID ? array_values( $user->roles ) : array(),
			'auth_mode'     => McpAuth::get_auth_mode(),
		);
		return array(
			'site'              => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url( '/' ),
				'admin_url'   => admin_url(),
				'mcp_url'     => home_url( '/mt-mcp' ),
				'rest_mcp_url'=> rest_url( 'mt-mcp/v1' ),
				'timezone'    => wp_timezone_string(),
				'language'    => get_locale(),
				'environment' => $env,
				'environment_label' => McpSupport::ENVIRONMENTS[ $env ],
				'environment_source' => defined( 'MT_MCP_ENVIRONMENT' ) ? 'constant' : 'option',
			),
			'versions'          => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'mcp'       => defined( 'MT_MCP_VERSION' ) ? MT_MCP_VERSION : '3.0.0',
				'plugin'    => 'mt-mcp',
			),
			'auth'              => $auth,
			'theme'             => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
			),
			'reading'           => array(
				'show_on_front'  => get_option( 'show_on_front' ),
				'page_on_front'  => (int) get_option( 'page_on_front' ),
				'page_for_posts' => (int) get_option( 'page_for_posts' ),
				'posts_per_page' => (int) get_option( 'posts_per_page' ),
			),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'plugins'           => McpSupport::active_plugins_summary(),
			'integrations'      => array(
				'acf'         => function_exists( 'get_fields' ),
				'yoast'       => class_exists( 'WPSEO_Meta' ) || defined( 'WPSEO_VERSION' ),
				'rankmath'    => class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ),
				'seopress'    => defined( 'SEOPRESS_VERSION' ),
				'redirects'   => McpSupport::redirects_backend(),
			),
			'mcp'               => array(
				'writes_enabled' => (bool) get_option( McpSupport::OPTION_ALLOW_WRITES ),
				'pause_writes'   => McpSupport::writes_paused(),
				'force_draft'    => McpSupport::force_draft_enabled(),
				'site_brief'     => McpSupport::site_brief(),
				'tool_groups'    => McpSupport::enabled_groups(),
				'three_gates'    => McpSupport::three_gate_status(),
				'undo_ttl_hours' => (int) ( McpSupport::UNDO_TTL_SECONDS / 3600 ),
				'oauth'          => true,
				'seo_providers'  => McpSeo::active_providers(),
			),
		);
	}

	public function list_post_types( $args = array() ) {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $type ) {
			$out[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'hierarchical' => (bool) $type->hierarchical,
				'rest_base'    => $type->rest_base ? $type->rest_base : $type->name,
			);
		}
		return $out;
	}

	public function list_posts( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			throw new \Exception( 'Unknown or missing post_type. Call list_post_types first.' );
		}

		$per_page = isset( $args['per_page'] ) ? min( (int) $args['per_page'], self::MAX_PER_PAGE ) : 20;
		$page     = isset( $args['page'] ) ? max( (int) $args['page'], 1 ) : 1;

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => isset( $args['status'] ) ? $args['status'] : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		$query = new \WP_Query( $query_args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post ),
				'modified' => $post->post_modified,
			);
		}

		return array(
			'post_type'   => $post_type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'posts'       => $out,
		);
	}

	public function search_posts( $args ) {
		$per_page = isset( $args['per_page'] ) ? min( (int) $args['per_page'], self::MAX_PER_PAGE ) : 20;
		$page     = isset( $args['page'] ) ? max( (int) $args['page'], 1 ) : 1;

		$post_type = 'any';
		if ( ! empty( $args['post_type'] ) ) {
			$post_type = is_array( $args['post_type'] ) ? array_map( 'sanitize_key', $args['post_type'] ) : sanitize_key( $args['post_type'] );
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => isset( $args['status'] ) ? $args['status'] : 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => isset( $args['orderby'] ) ? $args['orderby'] : 'modified',
			'order'          => isset( $args['order'] ) ? $args['order'] : 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}
		if ( ! empty( $args['modified_after'] ) ) {
			$query_args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $args['modified_after'],
				),
			);
		}
		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['term'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => sanitize_key( $args['taxonomy'] ),
					'field'    => is_numeric( $args['term'] ) ? 'term_id' : 'slug',
					'terms'    => $args['term'],
				),
			);
		}

		$meta_query = array();
		if ( ! empty( $args['acf_field'] ) ) {
			$field = sanitize_text_field( $args['acf_field'] );
			$clause = array( 'key' => $field );
			if ( array_key_exists( 'acf_value', $args ) ) {
				$clause['value'] = $args['acf_value'];
				$clause['compare'] = isset( $args['acf_compare'] ) ? $args['acf_compare'] : '=';
			} elseif ( ! empty( $args['acf_empty'] ) ) {
				$clause['compare'] = 'NOT EXISTS';
			}
			$meta_query[] = $clause;
		}
		if ( $meta_query ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new \WP_Query( $query_args );
		$posts = array();
		foreach ( $query->posts as $post ) {
			$row = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'post_type'=> $post->post_type,
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post ),
				'modified' => $post->post_modified,
			);

			if ( ! empty( $args['acf_field'] ) && function_exists( 'get_field' ) ) {
				$row['acf_field'] = $args['acf_field'];
				$row['acf_value'] = get_field( $args['acf_field'], $post->ID );
			}

			$include_row = true;
			if ( ! empty( $args['missing_required_acf'] ) && function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field' ) ) {
				$missing = array();
				foreach ( acf_get_field_groups( array( 'post_id' => $post->ID ) ) as $g ) {
					$fields = acf_get_fields( $g );
					if ( ! is_array( $fields ) ) {
						continue;
					}
					foreach ( $fields as $f ) {
						if ( empty( $f['required'] ) || empty( $f['name'] ) ) {
							continue;
						}
						$val = get_field( $f['name'], $post->ID );
						if ( null === $val || false === $val || '' === $val || array() === $val ) {
							$missing[] = $f['name'];
						}
					}
				}
				$row['missing_required_acf'] = $missing;
				$include_row = ! empty( $missing );
			}

			if ( ! empty( $args['missing_yoast'] ) && class_exists( 'WPSEO_Meta' ) ) {
				$title = \WPSEO_Meta::get_value( 'title', $post->ID );
				$desc  = \WPSEO_Meta::get_value( 'metadesc', $post->ID );
				$row['missing_yoast_title'] = ( '' === (string) $title );
				$row['missing_yoast_metadesc'] = ( '' === (string) $desc );
				$include_row = $include_row && ( $row['missing_yoast_title'] || $row['missing_yoast_metadesc'] );
			}

			if ( $include_row ) {
				$posts[] = $row;
			}
		}

		// When filtering missing_yoast client-side from a page of results,
		// totals reflect the WP_Query page, not the filtered count — note it.
		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'filter_note' => ! empty( $args['missing_yoast'] ) ? 'missing_yoast filters within each page of results; paginate to scan the full site.' : null,
			'posts'       => $posts,
		);
	}

	public function get_post( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$post    = McpSupport::assert_can_read_post( $post_id );

		$include = isset( $args['include'] ) && is_array( $args['include'] )
			? $args['include']
			: array( 'core', 'taxonomies', 'featured_image', 'yoast', 'acf' );

		$data = array(
			'id'        => $post->ID,
			'post_type' => $post->post_type,
			'permalink' => get_permalink( $post ),
		);

		if ( in_array( 'core', $include, true ) ) {
			$full    = $post->post_content;
			$offset  = isset( $args['content_offset'] ) ? max( 0, (int) $args['content_offset'] ) : 0;
			$max     = isset( $args['max_content_chars'] ) ? (int) $args['max_content_chars'] : 0;
			$total   = strlen( $full );
			$content = $offset > 0 ? substr( $full, $offset ) : $full;
			$truncated = false;
			$next_offset = null;
			if ( $max > 0 && strlen( $content ) > $max ) {
				$content     = substr( $content, 0, $max );
				$truncated   = true;
				$next_offset = $offset + $max;
			}
			$data = array_merge(
				$data,
				array(
					'title'      => get_the_title( $post ),
					'slug'       => $post->post_name,
					'status'     => $post->post_status,
					'date'       => $post->post_date,
					'modified'   => $post->post_modified,
					'author'     => array(
						'id'   => (int) $post->post_author,
						'name' => get_the_author_meta( 'display_name', $post->post_author ),
					),
					'excerpt'    => $post->post_excerpt,
					'content'    => $content,
					'content_truncated' => $truncated,
					'content_offset'   => $offset,
					'content_total'    => $total,
					'content_next_offset' => $next_offset,
					'parent'     => (int) $post->post_parent,
					'menu_order' => (int) $post->menu_order,
				)
			);
		}

		if ( in_array( 'featured_image', $include, true ) ) {
			$thumb_id = get_post_thumbnail_id( $post );
			$data['featured_image'] = $thumb_id
				? array(
					'id'  => $thumb_id,
					'url' => wp_get_attachment_url( $thumb_id ),
					'alt' => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
				)
				: null;
		}

		if ( in_array( 'taxonomies', $include, true ) ) {
			$data['taxonomies'] = array();
			foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $tax ) {
				$terms = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'all' ) );
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				$data['taxonomies'][ $tax ] = array_map(
					function ( $t ) {
						return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
					},
					$terms
				);
			}
		}

		if ( in_array( 'yoast', $include, true ) ) {
			$data['yoast'] = McpSupport::get_yoast_fields( $post->ID );
		}

		if ( in_array( 'acf', $include, true ) ) {
			$acf = function_exists( 'get_fields' ) ? ( get_fields( $post->ID ) ?: array() ) : null;
			if ( is_array( $acf ) && ! empty( $args['exclude_acf_fields'] ) && is_array( $args['exclude_acf_fields'] ) ) {
				foreach ( $args['exclude_acf_fields'] as $skip ) {
					unset( $acf[ $skip ] );
				}
			}
			if ( is_array( $acf ) && ! empty( $args['only_acf_fields'] ) && is_array( $args['only_acf_fields'] ) ) {
				$acf = array_intersect_key( $acf, array_flip( $args['only_acf_fields'] ) );
			}
			$data['acf'] = $acf;
		}

		if ( in_array( 'raw_meta', $include, true ) || ! empty( $args['include_raw_meta'] ) ) {
			$filtered = array();
			foreach ( get_post_meta( $post->ID ) as $key => $vals ) {
				if ( 0 === strpos( $key, '_yoast_wpseo_' ) ) {
					continue;
				}
				$filtered[ $key ] = 1 === count( $vals ) ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', $vals );
			}
			$data['raw_meta'] = $filtered;
		}

		return $data;
	}

	public function create_post( $args ) {
		$dry_run = ! empty( $args['dry_run'] );
		$type    = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( ! post_type_exists( $type ) ) {
			throw new \Exception( 'Unknown post_type.' );
		}
		if ( empty( $args['post_title'] ) && empty( $args['core']['post_title'] ) ) {
			throw new \Exception( 'Provide post_title (or core.post_title).' );
		}

		$core = isset( $args['core'] ) && is_array( $args['core'] ) ? $args['core'] : array();
		$status = isset( $args['post_status'] ) ? $args['post_status'] : ( isset( $core['post_status'] ) ? $core['post_status'] : 'draft' );
		$forced_draft = false;
		if ( McpSupport::force_draft_enabled() && 'draft' !== $status && empty( $args['allow_non_draft'] ) ) {
			$status = 'draft';
			$forced_draft = true;
		}
		$insert = array(
			'post_type'    => $type,
			'post_title'   => isset( $args['post_title'] ) ? $args['post_title'] : ( isset( $core['post_title'] ) ? $core['post_title'] : '' ),
			'post_content' => isset( $args['post_content'] ) ? $args['post_content'] : ( isset( $core['post_content'] ) ? $core['post_content'] : '' ),
			'post_excerpt' => isset( $args['post_excerpt'] ) ? $args['post_excerpt'] : ( isset( $core['post_excerpt'] ) ? $core['post_excerpt'] : '' ),
			'post_status'  => $status,
			'post_name'    => isset( $args['post_name'] ) ? $args['post_name'] : ( isset( $core['post_name'] ) ? $core['post_name'] : '' ),
		);

		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'would_create' => $insert,
				'taxonomies' => isset( $args['taxonomies'] ) ? $args['taxonomies'] : null,
				'yoast' => isset( $args['yoast'] ) ? $args['yoast'] : null,
				'acf' => isset( $args['acf'] ) ? array_keys( (array) $args['acf'] ) : null,
			);
		}

		$post_id = wp_insert_post( $insert, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \Exception( $post_id->get_error_message() );
		}

		$this->apply_post_buckets( $post_id, $args, false );

		if ( ! empty( $args['featured_image_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $args['featured_image_id'] );
		}

		if ( $forced_draft || McpSupport::force_draft_enabled() ) {
			update_post_meta( $post_id, '_mt_mcp_needs_review', '1' );
		}
		$result = $this->get_post( array( 'id' => $post_id ) );
		$result['created'] = true;
		$result['forced_draft'] = $forced_draft;
		$result['needs_review'] = (bool) get_post_meta( $post_id, '_mt_mcp_needs_review', true );
		return $result;
	}

	public function update_post( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		McpSupport::assert_can_edit_post( $post_id );
		$before  = $this->snapshot_for_diff( $post_id, $args );
		$dry_run = ! empty( $args['dry_run'] );

		if ( $dry_run ) {
			$planned = array();
			foreach ( array( 'core', 'taxonomies', 'yoast', 'acf', 'meta' ) as $bucket ) {
				if ( ! empty( $args[ $bucket ] ) ) {
					$planned[ $bucket ] = $args[ $bucket ];
				}
			}
			if ( ! empty( $args['featured_image_id'] ) ) {
				$planned['featured_image_id'] = (int) $args['featured_image_id'];
			}
			return array(
				'dry_run'  => true,
				'post_id'  => $post_id,
				'planned'  => $planned,
				'before'   => $before,
			);
		}

		$changed = $this->apply_post_buckets( $post_id, $args, true );

		if ( array_key_exists( 'featured_image_id', $args ) ) {
			$fid = (int) $args['featured_image_id'];
			if ( $fid > 0 ) {
				set_post_thumbnail( $post_id, $fid );
			} else {
				delete_post_thumbnail( $post_id );
			}
			$changed[] = 'featured_image';
		}

		$after = $this->snapshot_for_diff( $post_id, $args );
		$full  = $this->get_post( array( 'id' => $post_id ) );
		$verification = $this->verify_write_buckets( $args, $after );

		return array_merge(
			$full,
			array(
				'changed'      => $changed,
				'diff'         => McpSupport::diff_values( $before, $after ),
				'verification' => $verification,
			)
		);
	}

	/**
	 * Compare requested ACF/core/yoast values to what actually persisted.
	 *
	 * @param array $args Tool args with optional buckets.
	 * @param array $after Snapshot from snapshot_for_diff.
	 * @return array{status:string,fields:array}
	 */
	private function verify_write_buckets( $args, $after ) {
		$fields = array();
		$status = 'applied';
		foreach ( array( 'core', 'yoast', 'acf' ) as $bucket ) {
			if ( empty( $args[ $bucket ] ) || ! is_array( $args[ $bucket ] ) ) {
				continue;
			}
			$actual_bucket = isset( $after[ $bucket ] ) && is_array( $after[ $bucket ] ) ? $after[ $bucket ] : array();
			foreach ( $args[ $bucket ] as $key => $intended ) {
				$actual = array_key_exists( $key, $actual_bucket ) ? $actual_bucket[ $key ] : null;
				$norm_intended = $this->normalize_for_verify( $intended );
				$norm_actual   = $this->normalize_for_verify( $actual );
				if ( null === $actual && null !== $intended && '' !== $intended && array() !== $intended ) {
					$field_status = 'silent_drop';
					$status = 'silent_drop' === $status || 'applied' === $status ? 'silent_drop' : $status;
				} elseif ( $norm_intended !== $norm_actual ) {
					$field_status = 'silent_modify';
					if ( 'silent_drop' !== $status ) {
						$status = 'silent_modify';
					}
				} else {
					$field_status = 'applied';
				}
				$fields[] = array(
					'bucket'   => $bucket,
					'key'      => $key,
					'status'   => $field_status,
					'intended' => $intended,
					'actual'   => $actual,
				);
			}
		}
		if ( empty( $fields ) ) {
			$status = 'applied';
		}
		return array(
			'status' => $status,
			'fields' => $fields,
		);
	}

	private function normalize_for_verify( $value ) {
		if ( is_bool( $value ) || is_null( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) && ! is_string( $value ) ) {
			return 0 + $value;
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	public function trash_post( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		McpSupport::assert_can_delete_post( $post_id );
		$post    = get_post( $post_id );
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_trash' => array(
					'id' => $post_id,
					'title' => get_the_title( $post ),
					'status' => $post->post_status,
				),
			);
		}
		$force = ! empty( $args['force_delete'] );
		if ( $force && empty( $args['_confirmed'] ) && empty( $args['state_token'] ) && empty( $args['confirm'] ) ) {
			return McpPublic::create_state_token(
				'force_delete_post',
				array( 'post_id' => $post_id ),
				array(
					'id'     => $post_id,
					'title'  => get_the_title( $post ),
					'status' => $post->post_status,
					'action' => 'permanent_delete',
				)
			);
		}
		if ( $force && ! empty( $args['state_token'] ) ) {
			McpPublic::consume_state_token( $args['state_token'], 'force_delete_post' );
		}
		$result = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );
		if ( ! $result ) {
			throw new \Exception( 'Failed to trash/delete post.' );
		}
		return array(
			'id'      => $post_id,
			'deleted' => $force,
			'trashed' => ! $force,
		);
	}

	public function duplicate_post( $args ) {
		$source_id = McpSupport::resolve_post_id( $args );
		$source    = McpSupport::assert_can_read_post( $source_id );
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'Current user cannot create posts.' );
		}
		$status = isset( $args['post_status'] ) ? sanitize_key( $args['post_status'] ) : 'draft';
		$title  = isset( $args['post_title'] ) ? $args['post_title'] : $source->post_title . ' (Copy)';
		$include_acf = ! isset( $args['include_acf'] ) || ! empty( $args['include_acf'] );
		$include_tax = ! isset( $args['include_taxonomies'] ) || ! empty( $args['include_taxonomies'] );
		$include_featured = ! isset( $args['include_featured'] ) || ! empty( $args['include_featured'] );
		$include_meta = ! empty( $args['include_meta'] );

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_duplicate' => array(
					'source_id' => $source_id,
					'post_type' => $source->post_type,
					'post_title'=> $title,
					'post_status' => $status,
					'include_acf' => $include_acf,
					'include_taxonomies' => $include_tax,
					'include_featured' => $include_featured,
					'include_meta' => $include_meta,
				),
			);
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_title'   => $title,
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'post_status'  => $status,
				'post_parent'  => (int) $source->post_parent,
				'menu_order'   => (int) $source->menu_order,
				'post_author'  => get_current_user_id() ? get_current_user_id() : (int) $source->post_author,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			throw new \Exception( $new_id->get_error_message() );
		}

		if ( $include_tax ) {
			foreach ( get_object_taxonomies( $source->post_type ) as $tax ) {
				$terms = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
		}

		if ( $include_featured ) {
			$thumb = get_post_thumbnail_id( $source_id );
			if ( $thumb ) {
				set_post_thumbnail( $new_id, $thumb );
			}
		}

		if ( $include_acf && function_exists( 'get_fields' ) && function_exists( 'update_field' ) ) {
			$fields = get_fields( $source_id );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $key => $value ) {
					update_field( $key, $value, $new_id );
				}
			}
		}

		if ( $include_meta ) {
			foreach ( get_post_meta( $source_id ) as $key => $vals ) {
				if ( 0 === strpos( $key, '_' ) && 0 !== strpos( $key, '_yoast_wpseo_' ) ) {
					continue;
				}
				foreach ( $vals as $val ) {
					add_post_meta( $new_id, $key, maybe_unserialize( $val ) );
				}
			}
		}

		$result = $this->get_post( array( 'id' => $new_id ) );
		$result['duplicated_from'] = $source_id;
		$result['created'] = true;
		return $result;
	}

	public function find_replace( $args ) {
		$find = isset( $args['find'] ) ? (string) $args['find'] : '';
		$replace = isset( $args['replace'] ) ? (string) $args['replace'] : '';
		if ( '' === $find ) {
			throw new \Exception( 'Provide find string.' );
		}
		if ( ! array_key_exists( 'replace', $args ) ) {
			throw new \Exception( 'Provide replace string (may be empty).' );
		}
		$scopes = isset( $args['scopes'] ) && is_array( $args['scopes'] ) ? $args['scopes'] : array( 'content' );
		$scopes = array_values( array_intersect( array_map( 'sanitize_key', $scopes ), array( 'content', 'excerpt', 'title', 'acf', 'meta' ) ) );
		if ( empty( $scopes ) ) {
			$scopes = array( 'content' );
		}
		$expected = isset( $args['expected_count'] ) ? (int) $args['expected_count'] : null;
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		$ids = array();
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids = array_map( 'intval', $args['ids'] );
		} elseif ( ! empty( $args['id'] ) || ! empty( $args['url'] ) || ! empty( $args['post_id'] ) ) {
			$ids = array( McpSupport::resolve_post_id( $args ) );
		} else {
			throw new \Exception( 'Provide id/url or ids[].' );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( count( $ids ) > self::MAX_BATCH ) {
			throw new \Exception( 'Max ' . self::MAX_BATCH . ' posts per find_replace.' );
		}

		$matches = array();
		$total = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			if ( $post_type && $post->post_type !== $post_type ) {
				continue;
			}
			$post_matches = array();
			foreach ( $scopes as $scope ) {
				if ( 'content' === $scope ) {
					$count = substr_count( $post->post_content, $find );
					if ( $count ) {
						$post_matches[] = array( 'scope' => 'content', 'count' => $count );
						$total += $count;
					}
				} elseif ( 'excerpt' === $scope ) {
					$count = substr_count( $post->post_excerpt, $find );
					if ( $count ) {
						$post_matches[] = array( 'scope' => 'excerpt', 'count' => $count );
						$total += $count;
					}
				} elseif ( 'title' === $scope ) {
					$count = substr_count( $post->post_title, $find );
					if ( $count ) {
						$post_matches[] = array( 'scope' => 'title', 'count' => $count );
						$total += $count;
					}
				} elseif ( 'acf' === $scope && function_exists( 'get_fields' ) ) {
					$fields = get_fields( $post_id );
					$acf_hits = $this->count_string_in_value( $fields, $find );
					if ( $acf_hits['count'] ) {
						$post_matches[] = array( 'scope' => 'acf', 'count' => $acf_hits['count'], 'paths' => $acf_hits['paths'] );
						$total += $acf_hits['count'];
					}
				} elseif ( 'meta' === $scope ) {
					$meta = get_post_meta( $post_id );
					$meta_hits = 0;
					$keys = array();
					foreach ( $meta as $key => $vals ) {
						if ( 0 === strpos( $key, '_yoast' ) || 0 === strpos( $key, 'field_' ) ) {
							continue;
						}
						foreach ( $vals as $val ) {
							$n = substr_count( (string) maybe_unserialize( $val ), $find );
							if ( $n ) {
								$meta_hits += $n;
								$keys[] = $key;
							}
						}
					}
					if ( $meta_hits ) {
						$post_matches[] = array( 'scope' => 'meta', 'count' => $meta_hits, 'keys' => array_values( array_unique( $keys ) ) );
						$total += $meta_hits;
					}
				}
			}
			if ( $post_matches ) {
				$nearest = array();
				if ( in_array( 'content', $scopes, true ) && false !== strpos( $post->post_content, $find ) ) {
					$pos = strpos( $post->post_content, $find );
					$start = max( 0, $pos - 40 );
					$nearest[] = array(
						'scope' => 'content',
						'snippet' => substr( $post->post_content, $start, strlen( $find ) + 80 ),
					);
				}
				$matches[] = array(
					'id' => $post_id,
					'title' => get_the_title( $post ),
					'hits' => $post_matches,
					'nearest' => $nearest,
				);
			}
		}

		if ( null !== $expected && $expected !== $total ) {
			return array(
				'dry_run' => true,
				'ok' => false,
				'error' => 'expected_count mismatch',
				'expected_count' => $expected,
				'found_count' => $total,
				'matches' => $matches,
				'find' => $find,
				'replace' => $replace,
			);
		}

		if ( ! empty( $args['dry_run'] ) || empty( $args['apply'] ) ) {
			return array(
				'dry_run' => true,
				'ok' => true,
				'found_count' => $total,
				'matches' => $matches,
				'find' => $find,
				'replace' => $replace,
				'scopes' => $scopes,
				'hint' => 'Re-call with apply:true (and dry_run:false) to write. Prefer expected_count for safety.',
			);
		}

		$updated = array();
		foreach ( $matches as $match ) {
			$post_id = $match['id'];
			$post = get_post( $post_id );
			$changed = array();
			foreach ( $match['hits'] as $hit ) {
				$scope = $hit['scope'];
				if ( 'content' === $scope ) {
					wp_update_post( array( 'ID' => $post_id, 'post_content' => str_replace( $find, $replace, $post->post_content ) ) );
					$changed[] = 'content';
				} elseif ( 'excerpt' === $scope ) {
					wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => str_replace( $find, $replace, $post->post_excerpt ) ) );
					$changed[] = 'excerpt';
				} elseif ( 'title' === $scope ) {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => str_replace( $find, $replace, $post->post_title ) ) );
					$changed[] = 'title';
				} elseif ( 'acf' === $scope && function_exists( 'get_fields' ) && function_exists( 'update_field' ) ) {
					$fields = get_fields( $post_id );
					$replaced = $this->replace_string_in_value( $fields, $find, $replace );
					foreach ( $replaced['changed_keys'] as $key ) {
						update_field( $key, $replaced['value'][ $key ], $post_id );
					}
					$changed[] = 'acf';
				} elseif ( 'meta' === $scope ) {
					foreach ( $hit['keys'] as $key ) {
						$val = get_post_meta( $post_id, $key, true );
						if ( is_string( $val ) ) {
							update_post_meta( $post_id, $key, str_replace( $find, $replace, $val ) );
						}
					}
					$changed[] = 'meta';
				}
			}
			$updated[] = array( 'id' => $post_id, 'changed' => $changed );
		}
		return array(
			'applied' => true,
			'found_count' => $total,
			'updated' => $updated,
			'find' => $find,
			'replace' => $replace,
		);
	}

	private function count_string_in_value( $value, $find, $path = '' ) {
		$count = 0;
		$paths = array();
		if ( is_string( $value ) ) {
			$n = substr_count( $value, $find );
			if ( $n ) {
				$count += $n;
				$paths[] = $path ?: '(root)';
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$p = $path ? ( $path . '.' . $k ) : (string) $k;
				$sub = $this->count_string_in_value( $v, $find, $p );
				$count += $sub['count'];
				$paths = array_merge( $paths, $sub['paths'] );
			}
		}
		return array( 'count' => $count, 'paths' => $paths );
	}

	private function replace_string_in_value( $value, $find, $replace ) {
		$changed_keys = array();
		if ( ! is_array( $value ) ) {
			return array( 'value' => $value, 'changed_keys' => $changed_keys );
		}
		$out = array();
		foreach ( $value as $key => $val ) {
			if ( is_string( $val ) && false !== strpos( $val, $find ) ) {
				$out[ $key ] = str_replace( $find, $replace, $val );
				$changed_keys[] = $key;
			} elseif ( is_array( $val ) ) {
				$nested = $this->replace_string_in_value( $val, $find, $replace );
				$out[ $key ] = $nested['value'];
				if ( $nested['changed_keys'] || ( is_string( wp_json_encode( $val ) ) && false !== strpos( wp_json_encode( $val ), $find ) && $nested['value'] !== $val ) ) {
					if ( $nested['value'] !== $val ) {
						$changed_keys[] = $key;
					}
				}
			} else {
				$out[ $key ] = $val;
			}
		}
		return array( 'value' => $out, 'changed_keys' => array_values( array_unique( $changed_keys ) ) );
	}

	public function preflight_publish( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		$blockers = array();
		$warnings = array();
		$checks = array();

		$title = get_the_title( $post );
		$checks['title'] = array( 'ok' => '' !== trim( wp_strip_all_tags( $title ) ), 'value' => $title );
		if ( ! $checks['title']['ok'] ) {
			$blockers[] = 'Missing title';
		}

		$thumb = get_post_thumbnail_id( $post_id );
		$checks['featured_image'] = array( 'ok' => (bool) $thumb, 'id' => $thumb ? (int) $thumb : null );
		if ( ! $thumb ) {
			$warnings[] = 'No featured image';
		} else {
			$alt = get_post_meta( $thumb, '_wp_attachment_image_alt', true );
			$checks['featured_alt'] = array( 'ok' => '' !== trim( (string) $alt ), 'alt' => $alt );
			if ( '' === trim( (string) $alt ) ) {
				$warnings[] = 'Featured image missing alt text';
			}
		}

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field' ) ) {
			$missing_required = array();
			foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $g ) {
				$fields = acf_get_fields( $g );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $f ) {
					if ( empty( $f['required'] ) || empty( $f['name'] ) ) {
						continue;
					}
					$val = get_field( $f['name'], $post_id );
					$empty = ( null === $val || false === $val || '' === $val || array() === $val );
					if ( $empty ) {
						$missing_required[] = array( 'name' => $f['name'], 'label' => $f['label'], 'group' => $g['title'] );
					}
				}
			}
			$checks['acf_required'] = array( 'ok' => empty( $missing_required ), 'missing' => $missing_required );
			if ( $missing_required ) {
				$blockers[] = 'Required ACF fields empty: ' . implode( ', ', array_map( function ( $m ) { return $m['name']; }, $missing_required ) );
			}
		}

		$yoast = McpSupport::get_yoast_fields( $post_id );
		$title_seo = isset( $yoast['title'] ) ? trim( (string) $yoast['title'] ) : '';
		$desc_seo  = isset( $yoast['metadesc'] ) ? trim( (string) $yoast['metadesc'] ) : '';
		$checks['seo_title'] = array( 'ok' => '' !== $title_seo, 'value' => $title_seo, 'length' => strlen( $title_seo ) );
		$checks['seo_metadesc'] = array( 'ok' => '' !== $desc_seo, 'value' => $desc_seo, 'length' => strlen( $desc_seo ) );
		if ( '' === $title_seo ) {
			$warnings[] = 'Missing SEO title';
		} elseif ( strlen( $title_seo ) > 60 ) {
			$warnings[] = 'SEO title longer than 60 chars';
		}
		if ( '' === $desc_seo ) {
			$warnings[] = 'Missing SEO meta description';
		} elseif ( strlen( $desc_seo ) > 160 ) {
			$warnings[] = 'SEO metadesc longer than 160 chars';
		}

		try {
			$schema = $this->validate_schema_ld( array( 'id' => $post_id ) );
			$invalid = array();
			if ( ! empty( $schema['blocks'] ) ) {
				foreach ( $schema['blocks'] as $i => $block ) {
					if ( empty( $block['valid_json'] ) ) {
						$invalid[] = array(
							'index' => $i,
							'json_error' => isset( $block['json_error'] ) ? $block['json_error'] : 'invalid',
						);
					}
				}
			}
			$checks['schema_ld'] = array(
				'ok'     => empty( $invalid ),
				'blocks' => isset( $schema['count'] ) ? (int) $schema['count'] : 0,
				'invalid'=> $invalid,
			);
			if ( empty( $schema['count'] ) ) {
				$warnings[] = 'No application/ld+json blocks found on public URL';
			} elseif ( $invalid ) {
				$warnings[] = 'Schema.org JSON-LD parse issues found';
			}
		} catch ( \Throwable $e ) {
			$checks['schema_ld'] = array( 'ok' => false, 'error' => $e->getMessage() );
			$warnings[] = 'Could not fetch public schema: ' . $e->getMessage();
		}

		return array(
			'id'       => $post_id,
			'title'    => $title,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'ready'    => empty( $blockers ),
			'blockers' => $blockers,
			'warnings' => $warnings,
			'checks'   => $checks,
		);
	}

	private function apply_post_buckets( $post_id, $args, $require_existing ) {
		if ( $require_existing && ! get_post( $post_id ) ) {
			throw new \Exception( 'Post not found.' );
		}

		$changed = array();

		if ( ! empty( $args['core'] ) && is_array( $args['core'] ) ) {
			$update = array( 'ID' => $post_id );
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
				if ( array_key_exists( $field, $args['core'] ) ) {
					$update[ $field ] = $args['core'][ $field ];
				}
			}
			if ( count( $update ) > 1 ) {
				$r = wp_update_post( $update, true );
				if ( is_wp_error( $r ) ) {
					throw new \Exception( $r->get_error_message() );
				}
				$changed[] = 'core';
			}
		}

		if ( ! empty( $args['taxonomies'] ) && is_array( $args['taxonomies'] ) ) {
			foreach ( $args['taxonomies'] as $tax => $terms ) {
				if ( taxonomy_exists( $tax ) ) {
					wp_set_post_terms( $post_id, $terms, $tax );
					$changed[] = 'taxonomy:' . $tax;
				}
			}
		}

		if ( ! empty( $args['yoast'] ) && is_array( $args['yoast'] ) ) {
			foreach ( $args['yoast'] as $key => $value ) {
				McpSupport::set_yoast_value( $key, $value, $post_id );
			}
			$changed[] = 'yoast';
		}

		if ( ! empty( $args['acf'] ) && is_array( $args['acf'] ) && function_exists( 'update_field' ) ) {
			foreach ( $args['acf'] as $key => $value ) {
				update_field( $key, $value, $post_id );
			}
			$changed[] = 'acf';
		}

		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			foreach ( $args['meta'] as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
			$changed[] = 'meta';
		}

		return $changed;
	}

	private function snapshot_for_diff( $post_id, $args ) {
		$snap = array();
		if ( ! empty( $args['core'] ) ) {
			$p = get_post( $post_id );
			$snap['core'] = array(
				'post_title'   => $p->post_title,
				'post_content' => $p->post_content,
				'post_excerpt' => $p->post_excerpt,
				'post_status'  => $p->post_status,
				'post_name'    => $p->post_name,
			);
		}
		if ( ! empty( $args['yoast'] ) ) {
			$all = McpSupport::get_yoast_fields( $post_id );
			$snap['yoast'] = array_intersect_key( $all, $args['yoast'] );
		}
		if ( ! empty( $args['acf'] ) && function_exists( 'get_fields' ) ) {
			$all = get_fields( $post_id ) ?: array();
			$snap['acf'] = array_intersect_key( $all, $args['acf'] );
		}
		if ( ! empty( $args['taxonomies'] ) ) {
			$snap['taxonomies'] = array();
			foreach ( array_keys( $args['taxonomies'] ) as $tax ) {
				$terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'names' ) );
				$snap['taxonomies'][ $tax ] = is_wp_error( $terms ) ? array() : $terms;
			}
		}
		return $snap;
	}

	public function list_taxonomies( $args = array() ) {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[] = array( 'name' => $tax->name, 'label' => $tax->label, 'post_types' => $tax->object_type );
		}
		return $out;
	}

	public function get_terms( $args ) {
		$tax = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			throw new \Exception( 'Unknown or missing taxonomy. Call list_taxonomies first.' );
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			throw new \Exception( $terms->get_error_message() );
		}
		return array_map(
			function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => (int) $t->parent );
			},
			$terms
		);
	}

	public function create_term( $args ) {
		$tax = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		if ( ! $tax || ! taxonomy_exists( $tax ) ) {
			throw new \Exception( 'Unknown or missing taxonomy.' );
		}
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( ! $name ) {
			throw new \Exception( 'Provide name.' );
		}
		$term_args = array();
		if ( ! empty( $args['slug'] ) ) {
			$term_args['slug'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['parent'] ) ) {
			$term_args['parent'] = (int) $args['parent'];
		}
		if ( isset( $args['description'] ) ) {
			$term_args['description'] = wp_kses_post( $args['description'] );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_create' => array_merge( array( 'taxonomy' => $tax, 'name' => $name ), $term_args ),
			);
		}
		$result = wp_insert_term( $name, $tax, $term_args );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		$term = get_term( (int) $result['term_id'], $tax );
		return array(
			'created' => true,
			'id'      => (int) $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
			'taxonomy'=> $tax,
			'parent'  => (int) $term->parent,
			'count'   => (int) $term->count,
		);
	}

	public function update_term( $args ) {
		$tax = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		$term_id = 0;
		if ( ! empty( $args['id'] ) ) {
			$term_id = (int) $args['id'];
		} elseif ( ! empty( $args['term_id'] ) ) {
			$term_id = (int) $args['term_id'];
		}
		if ( ! $term_id ) {
			throw new \Exception( 'Provide id (term_id).' );
		}
		$term = McpSupport::assert_can_edit_term( $term_id, $tax );
		$tax = $term->taxonomy;
		$update = array();
		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$update[ $field ] = 'parent' === $field ? (int) $args[ $field ] : $args[ $field ];
			}
		}
		if ( empty( $update ) ) {
			throw new \Exception( 'Provide at least one of name, slug, description, parent.' );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'id'      => $term_id,
				'taxonomy'=> $tax,
				'before'  => array(
					'name' => $term->name,
					'slug' => $term->slug,
					'description' => $term->description,
					'parent' => (int) $term->parent,
				),
				'planned' => $update,
			);
		}
		$result = wp_update_term( $term_id, $tax, $update );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		$term = get_term( $term_id, $tax );
		return array(
			'updated' => true,
			'id'      => (int) $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
			'taxonomy'=> $tax,
			'parent'  => (int) $term->parent,
			'description' => $term->description,
			'count'   => (int) $term->count,
		);
	}

	public function delete_term( $args ) {
		$tax = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : '';
		$term_id = ! empty( $args['id'] ) ? (int) $args['id'] : ( ! empty( $args['term_id'] ) ? (int) $args['term_id'] : 0 );
		if ( ! $term_id ) {
			throw new \Exception( 'Provide id (term_id).' );
		}
		$term = McpSupport::assert_can_edit_term( $term_id, $tax );
		$tax = $term->taxonomy;
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_delete' => array(
					'id' => $term_id,
					'name' => $term->name,
					'slug' => $term->slug,
					'taxonomy' => $tax,
					'count' => (int) $term->count,
				),
			);
		}
		$result = wp_delete_term( $term_id, $tax );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}
		if ( ! $result ) {
			throw new \Exception( 'Failed to delete term.' );
		}
		return array( 'id' => $term_id, 'taxonomy' => $tax, 'deleted' => true, 'name' => $term->name );
	}

	public function describe_post_type( $args ) {
		$type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		if ( ! $type || ! post_type_exists( $type ) ) {
			throw new \Exception( 'Unknown or missing post_type.' );
		}
		$obj = get_post_type_object( $type );
		$counts = wp_count_posts( $type );
		$count_out = array();
		if ( $counts ) {
			foreach ( get_object_vars( $counts ) as $status => $n ) {
				$count_out[ $status ] = (int) $n;
			}
		}
		$taxonomies = array();
		foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
			$taxonomies[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
			);
		}
		$acf_groups = array();
		if ( function_exists( 'acf_get_field_groups' ) ) {
			foreach ( acf_get_field_groups( array( 'post_type' => $type ) ) as $g ) {
				$acf_groups[] = array(
					'key'      => $g['key'],
					'title'    => $g['title'],
					'location' => McpSupport::summarize_acf_location( isset( $g['location'] ) ? $g['location'] : array() ),
				);
			}
		}
		$templates = array();
		if ( 'page' === $type || ! empty( $obj->hierarchical ) ) {
			$templates = array_keys( wp_get_theme()->get_page_templates( null, $type ) );
		}
		return array(
			'name'         => $type,
			'label'        => $obj->label,
			'labels'       => array(
				'singular_name' => isset( $obj->labels->singular_name ) ? $obj->labels->singular_name : $type,
				'add_new_item'  => isset( $obj->labels->add_new_item ) ? $obj->labels->add_new_item : '',
			),
			'hierarchical' => (bool) $obj->hierarchical,
			'public'       => (bool) $obj->public,
			'show_in_rest' => (bool) $obj->show_in_rest,
			'rest_base'    => $obj->rest_base ? $obj->rest_base : $type,
			'supports'     => get_all_post_type_supports( $type ),
			'has_archive'  => (bool) $obj->has_archive,
			'menu_icon'    => $obj->menu_icon,
			'counts'       => $count_out,
			'taxonomies'   => $taxonomies,
			'acf_groups'   => $acf_groups,
			'page_templates' => $templates,
		);
	}

	public function list_acf_field_groups( $args = array() ) {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}
		$filter = array();
		if ( ! empty( $args['post_type'] ) ) {
			$filter['post_type'] = sanitize_key( $args['post_type'] );
		}
		$groups = acf_get_field_groups( $filter );
		$out    = array();
		foreach ( $groups as $g ) {
			$out[] = array(
				'key'      => $g['key'],
				'title'    => $g['title'],
				'active'   => ! empty( $g['active'] ),
				'location' => McpSupport::summarize_acf_location( isset( $g['location'] ) ? $g['location'] : array() ),
				'location_raw' => isset( $g['location'] ) ? $g['location'] : array(),
			);
		}
		return $out;
	}

	public function list_acf_options_pages( $args = array() ) {
		if ( ! function_exists( 'acf_get_options_pages' ) && ! function_exists( 'acf_get_options_page' ) ) {
			// Fall through — some ACF builds only expose via acf_get_options_pages.
		}
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}
		$pages = array();
		if ( function_exists( 'acf_get_options_pages' ) ) {
			$raw = acf_get_options_pages();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $page ) {
					if ( ! is_array( $page ) ) {
						continue;
					}
					$slug = isset( $page['menu_slug'] ) ? $page['menu_slug'] : ( isset( $page['post_id'] ) ? $page['post_id'] : '' );
					$post_id = isset( $page['post_id'] ) ? $page['post_id'] : 'option';
					$groups = acf_get_field_groups( array( 'options_page' => $slug ) );
					$pages[] = array(
						'slug'        => $slug,
						'page'        => $post_id,
						'post_id'     => $post_id,
						'title'       => isset( $page['page_title'] ) ? $page['page_title'] : ( isset( $page['menu_title'] ) ? $page['menu_title'] : $slug ),
						'menu_title'  => isset( $page['menu_title'] ) ? $page['menu_title'] : '',
						'parent'      => isset( $page['parent_slug'] ) ? $page['parent_slug'] : '',
						'capability'  => isset( $page['capability'] ) ? $page['capability'] : '',
						'group_count' => is_array( $groups ) ? count( $groups ) : 0,
						'group_keys'  => is_array( $groups ) ? array_values( array_filter( array_map( function ( $g ) {
							return isset( $g['key'] ) ? $g['key'] : null;
						}, $groups ) ) ) : array(),
					);
				}
			}
		}
		// Also surface options_page location rules from field groups when registry is empty.
		if ( empty( $pages ) ) {
			$seen = array();
			foreach ( acf_get_field_groups() as $g ) {
				$loc = McpSupport::summarize_acf_location( isset( $g['location'] ) ? $g['location'] : array() );
				foreach ( $loc as $or_group ) {
					foreach ( $or_group as $rule ) {
						if ( isset( $rule['param'] ) && 'options_page' === $rule['param'] && ! empty( $rule['value'] ) ) {
							$slug = $rule['value'];
							if ( isset( $seen[ $slug ] ) ) {
								continue;
							}
							$seen[ $slug ] = true;
							$pages[] = array(
								'slug'    => $slug,
								'page'    => $slug,
								'post_id' => $slug,
								'title'   => $slug,
								'source'  => 'location_rule',
								'group_keys' => array( $g['key'] ),
							);
						}
					}
				}
			}
		}
		return array( 'pages' => $pages, 'count' => count( $pages ) );
	}

	public function get_acf_schema( $args ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}

		$filter = array();
		if ( ! empty( $args['post_type'] ) ) {
			$filter['post_type'] = sanitize_key( $args['post_type'] );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$filter = array(); // prefer groups for this post
			$groups = acf_get_field_groups( array( 'post_id' => (int) $args['post_id'] ) );
		} elseif ( ! empty( $args['options_page'] ) ) {
			$groups = acf_get_field_groups( array( 'options_page' => $args['options_page'] ) );
		} elseif ( ! empty( $args['group_key'] ) ) {
			$g = acf_get_field_group( $args['group_key'] );
			$groups = $g ? array( $g ) : array();
		} else {
			$groups = acf_get_field_groups( $filter );
		}

		$out = array();
		foreach ( $groups as $g ) {
			$fields = acf_get_fields( $g );
			$described = array();
			if ( is_array( $fields ) ) {
				foreach ( $fields as $f ) {
					$d = McpSupport::describe_acf_field( $f );
					if ( $d ) {
						$described[] = $d;
					}
				}
			}
			$out[] = array(
				'key'      => $g['key'],
				'title'    => $g['title'],
				'location' => McpSupport::summarize_acf_location( isset( $g['location'] ) ? $g['location'] : array() ),
				'fields'   => $described,
			);
		}
		return array( 'groups' => $out );
	}

	public function get_options_page( $args ) {
		if ( ! function_exists( 'get_fields' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}
		$page = isset( $args['page'] ) ? $args['page'] : 'option';
		// ACF options pages use 'option' or 'options' or a custom post_id string.
		$post_id = ( 'options' === $page || 'option' === $page ) ? 'option' : $page;
		$fields  = get_fields( $post_id );
		return array(
			'page'   => $page,
			'fields' => $fields ? $fields : array(),
		);
	}

	public function update_options_page( $args ) {
		if ( ! function_exists( 'update_field' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}
		if ( empty( $args['fields'] ) || ! is_array( $args['fields'] ) ) {
			throw new \Exception( 'Provide fields: { field_name: value }.' );
		}
		$page    = isset( $args['page'] ) ? $args['page'] : 'option';
		$post_id = ( 'options' === $page || 'option' === $page ) ? 'option' : $page;

		if ( ! empty( $args['dry_run'] ) ) {
			$before = function_exists( 'get_fields' ) ? ( get_fields( $post_id ) ?: array() ) : array();
			return array(
				'dry_run' => true,
				'page'    => $page,
				'planned' => $args['fields'],
				'before'  => array_intersect_key( $before, $args['fields'] ),
			);
		}

		$before = function_exists( 'get_fields' ) ? ( get_fields( $post_id ) ?: array() ) : array();
		foreach ( $args['fields'] as $key => $value ) {
			update_field( $key, $value, $post_id );
		}
		$after = function_exists( 'get_fields' ) ? ( get_fields( $post_id ) ?: array() ) : array();
		$verification = $this->verify_write_buckets(
			array( 'acf' => $args['fields'] ),
			array( 'acf' => array_intersect_key( $after, $args['fields'] ) )
		);

		return array(
			'page'         => $page,
			'changed'      => array_keys( $args['fields'] ),
			'diff'         => McpSupport::diff_values(
				array_intersect_key( $before, $args['fields'] ),
				array_intersect_key( $after, $args['fields'] )
			),
			'verification' => $verification,
			'fields'       => $after,
		);
	}

	public function bulk_update_yoast( $args ) {
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( empty( $items ) ) {
			throw new \Exception( 'Provide items: [{url|id, title?, description?, focuskw?, ...}].' );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			throw new \Exception( 'Max ' . self::MAX_BATCH . ' items per request.' );
		}
		$dry_run = ! empty( $args['dry_run'] );
		$results = array();

		foreach ( $items as $item ) {
			try {
				$post_id = McpSupport::resolve_post_id( $item );
			} catch ( \Exception $e ) {
				$results[] = array( 'status' => 'not_found', 'item' => $item );
				continue;
			}

			$old = McpSupport::get_yoast_fields( $post_id );
			$planned = array();
			$map = array(
				'title'       => 'title',
				'description' => 'metadesc',
				'metadesc'    => 'metadesc',
				'focuskw'     => 'focuskw',
				'canonical'   => 'canonical',
			);
			foreach ( $map as $in => $yoast_key ) {
				if ( array_key_exists( $in, $item ) ) {
					$planned[ $yoast_key ] = $item[ $in ];
				}
			}
			// Also accept any raw yoast key under "yoast".
			if ( ! empty( $item['yoast'] ) && is_array( $item['yoast'] ) ) {
				$planned = array_merge( $planned, $item['yoast'] );
			}

			if ( empty( $planned ) ) {
				$results[] = array( 'status' => 'invalid', 'post_id' => $post_id, 'message' => 'No SEO fields provided.' );
				continue;
			}

			$warnings = array();
			if ( isset( $planned['title'] ) && mb_strlen( (string) $planned['title'] ) > 60 ) {
				$warnings[] = 'title exceeds 60 characters (' . mb_strlen( (string) $planned['title'] ) . ')';
			}
			if ( isset( $planned['metadesc'] ) && mb_strlen( (string) $planned['metadesc'] ) > 160 ) {
				$warnings[] = 'description exceeds 160 characters (' . mb_strlen( (string) $planned['metadesc'] ) . ')';
			}

			if ( ! $dry_run ) {
				foreach ( $planned as $key => $value ) {
					McpSupport::set_yoast_value( $key, $value, $post_id );
				}
			}

			$new = $dry_run ? array_merge( $old, $planned ) : McpSupport::get_yoast_fields( $post_id );
			$results[] = array(
				'status'   => $dry_run ? 'dry_run' : 'updated',
				'post_id'  => $post_id,
				'url'      => get_permalink( $post_id ),
				'old'      => array_intersect_key( $old, $planned ),
				'new'      => array_intersect_key( $new, $planned ),
				'warnings' => $warnings,
			);
		}

		return array( 'dry_run' => $dry_run, 'results' => $results );
	}

	public function seo_audit( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		$status    = isset( $args['status'] ) ? $args['status'] : 'publish';
		$per_page  = isset( $args['per_page'] ) ? min( (int) $args['per_page'], self::MAX_PER_PAGE ) : 50;
		$page      = isset( $args['page'] ) ? max( (int) $args['page'], 1 ) : 1;

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$issues = array();
		$summary = array(
			'missing_title'   => 0,
			'missing_metadesc'=> 0,
			'missing_focuskw' => 0,
			'title_too_long'  => 0,
			'metadesc_too_long'=> 0,
			'noindex'         => 0,
			'ok'              => 0,
		);

		foreach ( $query->posts as $post ) {
			$yoast   = McpSupport::get_yoast_fields( $post->ID );
			$item_issues = array();
			$title = (string) $yoast['title'];
			$desc  = (string) $yoast['metadesc'];
			$kw    = (string) $yoast['focuskw'];

			if ( '' === $title ) {
				$item_issues[] = 'missing_title';
				$summary['missing_title']++;
			} elseif ( mb_strlen( $title ) > 60 ) {
				$item_issues[] = 'title_too_long';
				$summary['title_too_long']++;
			}
			if ( '' === $desc ) {
				$item_issues[] = 'missing_metadesc';
				$summary['missing_metadesc']++;
			} elseif ( mb_strlen( $desc ) > 160 ) {
				$item_issues[] = 'metadesc_too_long';
				$summary['metadesc_too_long']++;
			}
			if ( '' === $kw ) {
				$item_issues[] = 'missing_focuskw';
				$summary['missing_focuskw']++;
			}
			if ( '1' === (string) $yoast['meta-robots-noindex'] ) {
				$item_issues[] = 'noindex';
				$summary['noindex']++;
			}

			if ( empty( $item_issues ) ) {
				$summary['ok']++;
			} else {
				$issues[] = array(
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'url'    => get_permalink( $post ),
					'issues' => $item_issues,
					'yoast'  => array(
						'title'    => $title,
						'metadesc' => $desc,
						'focuskw'  => $kw,
					),
				);
			}
		}

		return array(
			'post_type'   => $post_type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'summary'     => $summary,
			'issues'      => $issues,
		);
	}

	public function list_media( $args ) {
		$per_page = isset( $args['per_page'] ) ? min( (int) $args['per_page'], self::MAX_PER_PAGE ) : 20;
		$page     = isset( $args['page'] ) ? max( (int) $args['page'], 1 ) : 1;
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}
		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = $args['mime_type'];
		}
		$query = new \WP_Query( $query_args );
		$out   = array();
		foreach ( $query->posts as $att ) {
			$out[] = array(
				'id'    => $att->ID,
				'title' => get_the_title( $att ),
				'url'   => wp_get_attachment_url( $att ) ?: null,
				'mime'  => $att->post_mime_type,
				'alt'   => get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
				'date'  => $att->post_date,
			);
		}
		return array(
			'page' => $page,
			'per_page' => $per_page,
			'total' => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'media' => $out,
		);
	}

	public function get_media( $args ) {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			throw new \Exception( 'Attachment not found.' );
		}
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'          => $id,
			'title'       => get_the_title( $att ),
			'caption'     => $att->post_excerpt,
			'description' => $att->post_content,
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'url'         => wp_get_attachment_url( $id ),
			'mime'        => $att->post_mime_type,
			'metadata'    => $meta,
		);
	}

	public function update_media( $args ) {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			throw new \Exception( 'Attachment not found.' );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'id' => $id,
				'planned' => array_intersect_key( $args, array_flip( array( 'title', 'caption', 'description', 'alt' ) ) ),
			);
		}
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = $args['title'];
		}
		if ( isset( $args['caption'] ) ) {
			$update['post_excerpt'] = $args['caption'];
		}
		if ( isset( $args['description'] ) ) {
			$update['post_content'] = $args['description'];
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( array_key_exists( 'alt', $args ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $args['alt'] );
		}
		return $this->get_media( array( 'id' => $id ) );
	}

	public function list_menus( $args = array() ) {
		$menus = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$loc_by_id = array();
		foreach ( $locations as $loc => $menu_id ) {
			$loc_by_id[ $menu_id ][] = $loc;
		}
		$out = array();
		foreach ( $menus as $menu ) {
			$out[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'count'     => (int) $menu->count,
				'locations' => isset( $loc_by_id[ $menu->term_id ] ) ? $loc_by_id[ $menu->term_id ] : array(),
			);
		}
		return array( 'menus' => $out, 'locations' => $locations );
	}

	public function get_menu( $args ) {
		$menu_id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( ! $menu_id && ! empty( $args['slug'] ) ) {
			$menu = wp_get_nav_menu_object( $args['slug'] );
			$menu_id = $menu ? (int) $menu->term_id : 0;
		}
		if ( ! $menu_id && ! empty( $args['location'] ) ) {
			$locations = get_nav_menu_locations();
			$menu_id = isset( $locations[ $args['location'] ] ) ? (int) $locations[ $args['location'] ] : 0;
		}
		if ( ! $menu_id ) {
			throw new \Exception( 'Provide menu id, slug, or location.' );
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			throw new \Exception( 'Menu not found.' );
		}
		$items = wp_get_nav_menu_items( $menu_id );
		$out_items = array();
		if ( $items ) {
			foreach ( $items as $item ) {
				$out_items[] = array(
					'id'        => (int) $item->ID,
					'title'     => $item->title,
					'url'       => $item->url,
					'type'      => $item->type,
					'object'    => $item->object,
					'object_id' => (int) $item->object_id,
					'parent'    => (int) $item->menu_item_parent,
					'order'     => (int) $item->menu_order,
					'target'    => $item->target,
					'classes'   => $item->classes,
				);
			}
		}
		return array(
			'id'    => (int) $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'items' => $out_items,
		);
	}

	public function create_menu( $args ) {
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( ! $name ) {
			throw new \Exception( 'Provide name for the new menu.' );
		}
		$location = ! empty( $args['location'] ) ? sanitize_key( $args['location'] ) : '';
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run'  => true,
				'would_create' => array(
					'name'     => $name,
					'location' => $location ? $location : null,
				),
			);
		}
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			throw new \Exception( $menu_id->get_error_message() );
		}
		$assigned = null;
		if ( $location ) {
			$assigned = $this->assign_menu_location( (int) $menu_id, $location );
		}
		$out = $this->get_menu( array( 'id' => (int) $menu_id ) );
		$out['created'] = true;
		if ( null !== $assigned ) {
			$out['location_assigned'] = $assigned;
		}
		return $out;
	}

	public function update_menu( $args ) {
		$menu_id = McpSupport::resolve_menu_id( $args );
		$menu    = wp_get_nav_menu_object( $menu_id );
		$planned = array();
		if ( isset( $args['name'] ) && '' !== $args['name'] ) {
			$planned['name'] = sanitize_text_field( $args['name'] );
		}
		$assign_locations = array();
		if ( ! empty( $args['location'] ) ) {
			$assign_locations[] = sanitize_key( $args['location'] );
		}
		if ( ! empty( $args['locations'] ) && is_array( $args['locations'] ) ) {
			foreach ( $args['locations'] as $loc ) {
				$assign_locations[] = sanitize_key( $loc );
			}
		}
		$assign_locations = array_values( array_unique( array_filter( $assign_locations ) ) );
		$clear_locations  = ! empty( $args['clear_locations'] );
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run'           => true,
				'id'                => $menu_id,
				'current_name'      => $menu->name,
				'planned'           => $planned,
				'assign_locations'  => $assign_locations,
				'clear_locations'   => $clear_locations,
			);
		}
		if ( ! empty( $planned['name'] ) ) {
			$result = wp_update_nav_menu_object(
				$menu_id,
				array(
					'menu-name' => $planned['name'],
				)
			);
			if ( is_wp_error( $result ) ) {
				throw new \Exception( $result->get_error_message() );
			}
		}
		$locations = get_nav_menu_locations();
		if ( $clear_locations ) {
			foreach ( $locations as $loc => $id ) {
				if ( (int) $id === $menu_id ) {
					unset( $locations[ $loc ] );
				}
			}
		}
		foreach ( $assign_locations as $loc ) {
			$locations[ $loc ] = $menu_id;
		}
		if ( $clear_locations || $assign_locations ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}
		$out = $this->get_menu( array( 'id' => $menu_id ) );
		$out['updated'] = true;
		$out['locations'] = array_keys( array_filter( get_nav_menu_locations(), function ( $id ) use ( $menu_id ) {
			return (int) $id === $menu_id;
		} ) );
		return $out;
	}

	public function delete_menu( $args ) {
		$menu_id = McpSupport::resolve_menu_id( $args );
		$menu    = wp_get_nav_menu_object( $menu_id );
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_delete' => array(
					'id'    => $menu_id,
					'name'  => $menu->name,
					'slug'  => $menu->slug,
					'count' => (int) $menu->count,
				),
			);
		}
		$deleted = wp_delete_nav_menu( $menu_id );
		if ( is_wp_error( $deleted ) ) {
			throw new \Exception( $deleted->get_error_message() );
		}
		if ( ! $deleted ) {
			throw new \Exception( 'Failed to delete menu.' );
		}
		return array(
			'id'      => $menu_id,
			'deleted' => true,
			'name'    => $menu->name,
		);
	}

	public function update_menu_items( $args ) {
		$menu_id = McpSupport::resolve_menu_id( $args );
		$items   = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( empty( $items ) ) {
			throw new \Exception( 'Provide items: [{action: add|update|delete, ...}].' );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			throw new \Exception( 'Max ' . self::MAX_BATCH . ' menu item operations per request.' );
		}

		$planned    = array();
		$validation = array();
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				throw new \Exception( "items[$i] must be an object." );
			}
			$action = isset( $item['action'] ) ? sanitize_key( $item['action'] ) : 'add';
			if ( ! in_array( $action, array( 'add', 'update', 'delete' ), true ) ) {
				throw new \Exception( "items[$i].action must be add, update, or delete." );
			}
			$row = array( 'action' => $action, 'index' => $i );
			if ( in_array( $action, array( 'update', 'delete' ), true ) ) {
				$item_id = isset( $item['id'] ) ? (int) $item['id'] : ( isset( $item['item_id'] ) ? (int) $item['item_id'] : 0 );
				if ( ! $item_id ) {
					throw new \Exception( "items[$i] requires id for update/delete." );
				}
				$row['id'] = $item_id;
			}
			if ( 'delete' !== $action ) {
				$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'custom';
				if ( ! in_array( $type, array( 'custom', 'post_type', 'taxonomy' ), true ) ) {
					$type = 'custom';
				}
				$row['type']      = $type;
				$row['title']     = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
				$row['url']       = isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';
				$row['object']    = isset( $item['object'] ) ? sanitize_key( $item['object'] ) : '';
				$row['object_id'] = isset( $item['object_id'] ) ? (int) $item['object_id'] : 0;
				$row['parent']    = isset( $item['parent'] ) ? (int) $item['parent'] : ( isset( $item['menu_item_parent'] ) ? (int) $item['menu_item_parent'] : 0 );
				$row['order']     = isset( $item['order'] ) ? (int) $item['order'] : ( isset( $item['position'] ) ? (int) $item['position'] : 0 );
				$row['target']    = isset( $item['target'] ) ? sanitize_text_field( $item['target'] ) : '';
				$row['classes']   = isset( $item['classes'] ) ? $item['classes'] : '';
				$row['attr_title']= isset( $item['attr_title'] ) ? sanitize_text_field( $item['attr_title'] ) : '';
				$row['description'] = isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '';
				$row['xfn']       = isset( $item['xfn'] ) ? sanitize_text_field( $item['xfn'] ) : '';
				$validation[] = $this->validate_menu_item_link( $row, $i );
			}
			$planned[] = $row;
		}

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run'    => true,
				'menu_id'    => $menu_id,
				'planned'    => $planned,
				'validation' => $validation,
			);
		}

		$results = array();
		foreach ( $planned as $row ) {
			if ( 'delete' === $row['action'] ) {
				$ok = wp_delete_post( $row['id'], true );
				$results[] = array(
					'action'  => 'delete',
					'id'      => $row['id'],
					'deleted' => (bool) $ok,
				);
				continue;
			}
			$db_id = ( 'update' === $row['action'] && ! empty( $row['id'] ) ) ? (int) $row['id'] : 0;
			$classes = $row['classes'];
			if ( is_array( $classes ) ) {
				$classes = implode( ' ', array_map( 'sanitize_html_class', $classes ) );
			} else {
				$classes = sanitize_text_field( (string) $classes );
			}
			$menu_item_data = array(
				'menu-item-title'     => $row['title'],
				'menu-item-url'       => $row['url'],
				'menu-item-type'      => $row['type'],
				'menu-item-object'    => $row['object'] ? $row['object'] : ( 'custom' === $row['type'] ? 'custom' : '' ),
				'menu-item-object-id' => $row['object_id'],
				'menu-item-parent-id' => $row['parent'],
				'menu-item-position'  => $row['order'],
				'menu-item-status'    => 'publish',
				'menu-item-target'    => $row['target'],
				'menu-item-classes'   => $classes,
				'menu-item-attr-title'=> $row['attr_title'],
				'menu-item-description' => $row['description'],
				'menu-item-xfn'       => $row['xfn'],
			);
			if ( 'custom' === $row['type'] && empty( $menu_item_data['menu-item-object'] ) ) {
				$menu_item_data['menu-item-object'] = 'custom';
			}
			$item_id = wp_update_nav_menu_item( $menu_id, $db_id, $menu_item_data );
			if ( is_wp_error( $item_id ) ) {
				throw new \Exception( $item_id->get_error_message() );
			}
			$results[] = array(
				'action' => $row['action'],
				'id'     => (int) $item_id,
				'title'  => $row['title'],
			);
		}

		$menu = $this->get_menu( array( 'id' => $menu_id ) );
		$menu['updated_items'] = $results;
		$menu['validation']    = $validation;
		return $menu;
	}

	private function assign_menu_location( $menu_id, $location ) {
		$locations = get_nav_menu_locations();
		$locations[ $location ] = (int) $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return $location;
	}

	private function validate_menu_item_link( $row, $index ) {
		$issues = array();
		$type   = $row['type'];
		if ( 'post_type' === $type ) {
			if ( empty( $row['object_id'] ) || ! get_post( $row['object_id'] ) ) {
				$issues[] = 'object_id does not resolve to a post';
			} elseif ( $row['object'] && get_post_type( $row['object_id'] ) !== $row['object'] ) {
				$issues[] = 'object_id post_type does not match object';
			}
		} elseif ( 'taxonomy' === $type ) {
			if ( empty( $row['object_id'] ) ) {
				$issues[] = 'taxonomy items need object_id (term_id)';
			} else {
				$term = get_term( $row['object_id'], $row['object'] ? $row['object'] : '' );
				if ( ! $term || is_wp_error( $term ) ) {
					$issues[] = 'object_id does not resolve to a term';
				}
			}
		} else {
			$url = $row['url'];
			if ( ! $url ) {
				$issues[] = 'custom items should include url';
			} elseif ( preg_match( '#^(https?:)?//#i', $url ) ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $host ) {
					$issues[] = 'url host is invalid';
				} elseif ( false === strpos( $host, '.' ) && ! in_array( strtolower( $host ), array( 'localhost' ), true ) ) {
					$issues[] = 'url host looks invalid (missing TLD)';
				}
			} elseif ( 0 !== strpos( $url, '/' ) && 0 !== strpos( $url, '#' ) && 0 !== strpos( $url, '?' ) ) {
				$issues[] = 'url should be absolute, site-relative (/...), or #/#query';
			}
		}
		return array(
			'index'  => $index,
			'ok'     => empty( $issues ),
			'issues' => $issues,
		);
	}

	public function get_options( $args ) {
		$allowed = McpSupport::allowed_option_keys();
		$keys    = ! empty( $args['keys'] ) && is_array( $args['keys'] ) ? $args['keys'] : $allowed;
		$out     = array();
		foreach ( $keys as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$out[ $key ] = get_option( $key );
		}
		return array(
			'allowed_keys' => $allowed,
			'options'      => $out,
		);
	}

	public function update_options( $args ) {
		if ( empty( $args['options'] ) || ! is_array( $args['options'] ) ) {
			throw new \Exception( 'Provide options: { key: value }.' );
		}
		$allowed = McpSupport::allowed_option_keys();
		$planned = array();
		$rejected = array();
		foreach ( $args['options'] as $key => $value ) {
			if ( McpSupport::is_protected_option_key( $key ) ) {
				$rejected[ $key ] = 'protected';
				continue;
			}
			if ( in_array( $key, $allowed, true ) ) {
				$planned[ $key ] = $value;
			} else {
				$rejected[ $key ] = 'not_allowlisted';
			}
		}
		if ( empty( $planned ) ) {
			throw new \Exception(
				'No allowlisted option keys in request.'
				. ( $rejected ? ' Rejected: ' . wp_json_encode( $rejected ) : ' Call get_options to see allowed_keys.' )
			);
		}
		if ( ! empty( $args['dry_run'] ) ) {
			$before = array();
			foreach ( $planned as $k => $v ) {
				$before[ $k ] = get_option( $k );
			}
			return array( 'dry_run' => true, 'planned' => $planned, 'before' => $before );
		}
		$before = array();
		$after  = array();
		foreach ( $planned as $k => $v ) {
			$before[ $k ] = get_option( $k );
			update_option( $k, $v );
			$after[ $k ] = get_option( $k );
		}
		return array(
			'changed' => array_keys( $planned ),
			'diff'    => McpSupport::diff_values( $before, $after ),
			'options' => $after,
		);
	}

	public function list_redirects( $args = array() ) {
		$backend = McpSupport::redirects_backend();
		if ( ! $backend ) {
			return array( 'backend' => null, 'redirects' => array(), 'message' => 'No supported redirects plugin detected (Redirection or Yoast Premium).' );
		}
		if ( 'redirection' === $backend ) {
			return $this->list_redirects_redirection( $args );
		}
		return $this->list_redirects_yoast( $args );
	}

	private function list_redirects_redirection( $args ) {
		if ( ! class_exists( 'Red_Item' ) ) {
			throw new \Exception( 'Redirection plugin not available.' );
		}
		$items = \Red_Item::get_filtered( array() );
		// API varies by version — fall back to get_all if needed.
		if ( ! is_array( $items ) && method_exists( 'Red_Item', 'get_all' ) ) {
			$items = \Red_Item::get_all();
		}
		$out = array();
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'to_json' ) ) {
					$out[] = $item->to_json();
				} elseif ( is_object( $item ) ) {
					$out[] = array(
						'id'     => isset( $item->id ) ? $item->id : null,
						'url'    => isset( $item->url ) ? $item->url : null,
						'action_data' => isset( $item->action_data ) ? $item->action_data : null,
						'match_type' => isset( $item->match_type ) ? $item->match_type : null,
						'action_type' => isset( $item->action_type ) ? $item->action_type : null,
						'action_code' => isset( $item->action_code ) ? $item->action_code : null,
						'enabled' => isset( $item->status ) ? ( 'enabled' === $item->status ) : true,
					);
				}
			}
		}
		return array( 'backend' => 'redirection', 'redirects' => $out );
	}

	private function list_redirects_yoast( $args ) {
		$redirects = $this->yoast_redirects_map();
		$out       = array();
		foreach ( $redirects as $origin => $data ) {
			$out[] = array(
				'origin' => $origin,
				'target' => is_array( $data ) && isset( $data['url'] ) ? $data['url'] : $data,
				'type'   => is_array( $data ) && isset( $data['type'] ) ? (int) $data['type'] : 301,
			);
		}
		return array( 'backend' => 'yoast', 'redirects' => $out, 'count' => count( $out ) );
	}

	/**
	 * Normalize Yoast Premium redirects option into origin => {url,type}.
	 * Older/newer Yoast shapes may be keyed by origin or a numeric list with origin keys.
	 */
	private function yoast_redirects_map() {
		$raw = get_option( 'wpseo-premium-redirects-base', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$map = array();
		foreach ( $raw as $key => $data ) {
			if ( is_array( $data ) && isset( $data['origin'] ) ) {
				$origin = (string) $data['origin'];
				$map[ $origin ] = array(
					'url'  => isset( $data['url'] ) ? $data['url'] : ( isset( $data['target'] ) ? $data['target'] : '' ),
					'type' => isset( $data['type'] ) ? (int) $data['type'] : 301,
				);
				continue;
			}
			// Associative origin => payload (possibly string target).
			if ( is_string( $key ) || ( is_int( $key ) && ! is_array( $data ) ) ) {
				$origin = (string) $key;
				if ( is_array( $data ) ) {
					$map[ $origin ] = array(
						'url'  => isset( $data['url'] ) ? $data['url'] : '',
						'type' => isset( $data['type'] ) ? (int) $data['type'] : 301,
					);
				} else {
					$map[ $origin ] = array( 'url' => (string) $data, 'type' => 301 );
				}
			}
		}
		return $map;
	}

	private function save_yoast_redirects_map( array $map ) {
		// Yoast Premium expects a list of {origin,url,type,format} objects (not origin-keyed).
		$list = array();
		foreach ( $map as $origin => $data ) {
			$list[] = array(
				'origin' => (string) $origin,
				'url'    => is_array( $data ) && isset( $data['url'] ) ? (string) $data['url'] : (string) $data,
				'type'   => is_array( $data ) && isset( $data['type'] ) ? (int) $data['type'] : 301,
				'format' => is_array( $data ) && ! empty( $data['format'] ) ? (string) $data['format'] : 'plain',
			);
		}
		update_option( 'wpseo-premium-redirects-base', $list, false );
	}

	public function create_redirect( $args ) {
		$backend = McpSupport::redirects_backend();
		if ( ! $backend ) {
			throw new \Exception( 'No supported redirects plugin detected.' );
		}
		$from = isset( $args['from'] ) ? $args['from'] : ( isset( $args['origin'] ) ? $args['origin'] : '' );
		$to   = isset( $args['to'] ) ? $args['to'] : ( isset( $args['target'] ) ? $args['target'] : '' );
		$code = isset( $args['code'] ) ? (int) $args['code'] : 301;
		if ( ! $from || ! $to ) {
			throw new \Exception( 'Provide from and to.' );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array( 'dry_run' => true, 'backend' => $backend, 'from' => $from, 'to' => $to, 'code' => $code );
		}
		if ( 'yoast' === $backend ) {
			$redirects = $this->yoast_redirects_map();
			$redirects[ $from ] = array( 'url' => $to, 'type' => $code );
			$this->save_yoast_redirects_map( $redirects );
			return array( 'backend' => 'yoast', 'created' => array( 'from' => $from, 'to' => $to, 'code' => $code ) );
		}
		if ( class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'create' ) ) {
			$item = \Red_Item::create(
				array(
					'url'         => $from,
					'action_data' => array( 'url' => $to ),
					'action_type' => 'url',
					'match_type'  => 'url',
					'action_code' => $code,
					'group_id'    => isset( $args['group_id'] ) ? (int) $args['group_id'] : 1,
				)
			);
			if ( is_wp_error( $item ) ) {
				throw new \Exception( $item->get_error_message() );
			}
			return array( 'backend' => 'redirection', 'created' => true, 'item' => method_exists( $item, 'to_json' ) ? $item->to_json() : array( 'from' => $from, 'to' => $to ) );
		}
		throw new \Exception( 'Could not create redirect with the detected backend.' );
	}

	public function update_redirect( $args ) {
		// For Yoast: from is the key; for Redirection: id required.
		$backend = McpSupport::redirects_backend();
		if ( 'yoast' === $backend ) {
			$from = isset( $args['from'] ) ? $args['from'] : '';
			$to   = isset( $args['to'] ) ? $args['to'] : null;
			$code = isset( $args['code'] ) ? (int) $args['code'] : null;
			$redirects = $this->yoast_redirects_map();
			if ( ! isset( $redirects[ $from ] ) ) {
				throw new \Exception( 'Redirect origin not found.' );
			}
			if ( ! empty( $args['dry_run'] ) ) {
				return array( 'dry_run' => true, 'from' => $from, 'current' => $redirects[ $from ], 'to' => $to, 'code' => $code );
			}
			if ( null !== $to ) {
				$redirects[ $from ]['url'] = $to;
			}
			if ( null !== $code ) {
				$redirects[ $from ]['type'] = $code;
			}
			if ( ! empty( $args['new_from'] ) && $args['new_from'] !== $from ) {
				$redirects[ $args['new_from'] ] = $redirects[ $from ];
				unset( $redirects[ $from ] );
				$from = $args['new_from'];
			}
			$this->save_yoast_redirects_map( $redirects );
			return array( 'backend' => 'yoast', 'updated' => array( 'from' => $from, 'data' => $redirects[ $from ] ) );
		}
		throw new \Exception( 'update_redirect currently supports Yoast Premium redirects. For Redirection plugin, delete + create, or use the Redirection UI.' );
	}

	public function delete_redirect( $args ) {
		$backend = McpSupport::redirects_backend();
		if ( 'yoast' === $backend ) {
			$from = isset( $args['from'] ) ? $args['from'] : '';
			$redirects = $this->yoast_redirects_map();
			if ( ! isset( $redirects[ $from ] ) ) {
				throw new \Exception( 'Redirect origin not found.' );
			}
			if ( ! empty( $args['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_delete' => array( 'from' => $from, 'data' => $redirects[ $from ] ) );
			}
			unset( $redirects[ $from ] );
			$this->save_yoast_redirects_map( $redirects );
			return array( 'backend' => 'yoast', 'deleted' => $from );
		}
		if ( 'redirection' === $backend && ! empty( $args['id'] ) && class_exists( 'Red_Item' ) ) {
			$item = \Red_Item::get_by_id( (int) $args['id'] );
			if ( ! $item ) {
				throw new \Exception( 'Redirect not found.' );
			}
			if ( ! empty( $args['dry_run'] ) ) {
				return array( 'dry_run' => true, 'would_delete' => (int) $args['id'] );
			}
			$item->delete();
			return array( 'backend' => 'redirection', 'deleted' => (int) $args['id'] );
		}
		throw new \Exception( 'Provide from (Yoast) or id (Redirection).' );
	}

	public function content_review( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		$status    = isset( $args['status'] ) ? $args['status'] : 'publish';
		$per_page  = isset( $args['per_page'] ) ? min( (int) $args['per_page'], self::MAX_PER_PAGE ) : 50;
		$page      = isset( $args['page'] ) ? max( (int) $args['page'], 1 ) : 1;

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		$summary = array(
			'empty_content'     => 0,
			'missing_excerpt'   => 0,
			'missing_featured'  => 0,
			'missing_yoast'     => 0,
			'draft_or_private'  => 0,
			'ok'                => 0,
		);

		foreach ( $query->posts as $post ) {
			$flags = array();
			if ( '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
				$flags[] = 'empty_content';
				$summary['empty_content']++;
			}
			if ( '' === trim( (string) $post->post_excerpt ) ) {
				$flags[] = 'missing_excerpt';
				$summary['missing_excerpt']++;
			}
			if ( ! get_post_thumbnail_id( $post ) ) {
				$flags[] = 'missing_featured';
				$summary['missing_featured']++;
			}
			$yoast = McpSupport::get_yoast_fields( $post->ID );
			if ( '' === (string) $yoast['title'] || '' === (string) $yoast['metadesc'] ) {
				$flags[] = 'missing_yoast';
				$summary['missing_yoast']++;
			}
			if ( in_array( $post->post_status, array( 'draft', 'private', 'pending' ), true ) ) {
				$flags[] = 'draft_or_private';
				$summary['draft_or_private']++;
			}

			$acf_empty = array();
			if ( function_exists( 'get_fields' ) && ! empty( $args['check_acf'] ) ) {
				$fields = get_fields( $post->ID );
				if ( is_array( $fields ) ) {
					foreach ( $fields as $k => $v ) {
						if ( null === $v || '' === $v || array() === $v ) {
							$acf_empty[] = $k;
						}
					}
				}
			}

			if ( empty( $flags ) && empty( $acf_empty ) ) {
				$summary['ok']++;
			}

			$items[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'url'       => get_permalink( $post ),
				'status'    => $post->post_status,
				'flags'     => $flags,
				'empty_acf' => $acf_empty,
			);
		}

		return array(
			'post_type'   => $post_type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'summary'     => $summary,
			'items'       => $items,
		);
	}

	public function get_audit_log( $args ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		return array( 'entries' => McpSupport::get_audit_log( $limit ) );
	}

	/**
	 * Upload media from a remote URL or base64 payload.
	 * Args: url? | data_base64? + filename?, title?, alt?, caption?, description?, dry_run?
	 */
	public function upload_media( $args ) {
		if ( empty( $args['url'] ) && empty( $args['data_base64'] ) ) {
			throw new \Exception( 'Provide url or data_base64 (+ filename).' );
		}
		if ( ! empty( $args['url'] ) && ( ! array_key_exists( 'alt', $args ) || '' === trim( (string) $args['alt'] ) ) ) {
			throw new \Exception( 'Remote URL uploads require a non-empty alt string.' );
		}
		$safe_url = null;
		if ( ! empty( $args['url'] ) ) {
			$safe_url = McpSupport::assert_safe_remote_url( $args['url'] );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run'  => true,
				'would_upload' => array(
					'url'        => $safe_url,
					'filename'   => isset( $args['filename'] ) ? $args['filename'] : null,
					'has_base64' => ! empty( $args['data_base64'] ),
					'title'      => isset( $args['title'] ) ? $args['title'] : null,
					'alt'        => isset( $args['alt'] ) ? $args['alt'] : null,
					'ssrf_checked' => (bool) $safe_url,
				),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = '';
		$filename = isset( $args['filename'] ) ? $args['filename'] : '';

		if ( ! empty( $args['data_base64'] ) ) {
			$bytes = base64_decode( $args['data_base64'] );
			if ( false === $bytes ) {
				throw new \Exception( 'Invalid data_base64.' );
			}
			if ( strlen( $bytes ) > McpTransfer::MAX_FILE_BYTES ) {
				throw new \Exception( 'File exceeds max size (' . McpTransfer::MAX_FILE_BYTES . ' bytes).' );
			}
			if ( ! $filename ) {
				$filename = 'upload.bin';
			}
			$tmp = wp_tempnam( $filename );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} else {
			// Block redirect-based SSRF: download without following redirects.
			$response = wp_remote_get(
				$safe_url,
				array(
					'timeout'     => 60,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'mt_mcp_sslverify', true, $safe_url ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				throw new \Exception( 'Refusing redirected URL (SSRF guard).' );
			}
			if ( $code < 200 || $code >= 300 ) {
				throw new \Exception( 'Remote URL returned HTTP ' . $code . '.' );
			}
			$bytes = wp_remote_retrieve_body( $response );
			if ( '' === $bytes ) {
				throw new \Exception( 'Remote URL returned an empty body.' );
			}
			if ( strlen( $bytes ) > McpTransfer::MAX_FILE_BYTES ) {
				throw new \Exception( 'File exceeds max size (' . McpTransfer::MAX_FILE_BYTES . ' bytes).' );
			}
			if ( ! $filename ) {
				$filename = basename( wp_parse_url( $safe_url, PHP_URL_PATH ) );
				if ( ! $filename ) {
					$filename = 'download.bin';
				}
			}
			$tmp = wp_tempnam( $filename );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload(
			$file_array,
			isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0,
			null,
			array(
				'post_title'   => isset( $args['title'] ) ? $args['title'] : pathinfo( $filename, PATHINFO_FILENAME ),
				'post_excerpt' => isset( $args['caption'] ) ? $args['caption'] : '',
				'post_content' => isset( $args['description'] ) ? $args['description'] : '',
			)
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \Exception( $id->get_error_message() );
		}
		if ( array_key_exists( 'alt', $args ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $args['alt'] );
		}
		$file = get_attached_file( $id );
		if ( $file && file_exists( $file ) ) {
			update_post_meta( $id, '_mt_mcp_sha256', hash_file( 'sha256', $file ) );
		}

		$out = $this->get_media( array( 'id' => $id ) );
		$out['uploaded'] = true;
		return $out;
	}

	public function list_revisions( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		if ( ! get_post( $post_id ) ) {
			throw new \Exception( 'Post not found.' );
		}
		$revisions = wp_get_post_revisions( $post_id, array( 'orderby' => 'date', 'order' => 'DESC' ) );
		$out = array();
		foreach ( $revisions as $rev ) {
			$out[] = array(
				'id'       => $rev->ID,
				'date'     => $rev->post_date,
				'modified' => $rev->post_modified,
				'author'   => array(
					'id'   => (int) $rev->post_author,
					'name' => get_the_author_meta( 'display_name', $rev->post_author ),
				),
				'title'    => $rev->post_title,
			);
		}
		return array(
			'post_id'   => $post_id,
			'count'     => count( $out ),
			'revisions' => $out,
		);
	}

	public function get_revision( $args ) {
		$rev_id = isset( $args['revision_id'] ) ? (int) $args['revision_id'] : ( isset( $args['id'] ) ? (int) $args['id'] : 0 );
		$rev    = wp_get_post_revision( $rev_id );
		if ( ! $rev ) {
			throw new \Exception( 'Revision not found.' );
		}
		return array(
			'id'            => $rev->ID,
			'parent'        => (int) $rev->post_parent,
			'date'          => $rev->post_date,
			'author'        => array(
				'id'   => (int) $rev->post_author,
				'name' => get_the_author_meta( 'display_name', $rev->post_author ),
			),
			'post_title'    => $rev->post_title,
			'post_content'  => $rev->post_content,
			'post_excerpt'  => $rev->post_excerpt,
		);
	}

	public function restore_revision( $args ) {
		$rev_id = isset( $args['revision_id'] ) ? (int) $args['revision_id'] : ( isset( $args['id'] ) ? (int) $args['id'] : 0 );
		$rev    = wp_get_post_revision( $rev_id );
		if ( ! $rev ) {
			throw new \Exception( 'Revision not found.' );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_restore' => array(
					'revision_id' => $rev_id,
					'parent'      => (int) $rev->post_parent,
					'title'       => $rev->post_title,
					'date'        => $rev->post_date,
				),
			);
		}
		$result = wp_restore_post_revision( $rev_id );
		if ( ! $result || is_wp_error( $result ) ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'Restore failed.';
			throw new \Exception( $msg );
		}
		return array(
			'restored'    => true,
			'revision_id' => $rev_id,
			'post_id'     => (int) $rev->post_parent,
			'post'        => $this->get_post( array( 'id' => (int) $rev->post_parent, 'include' => array( 'core', 'yoast' ) ) ),
		);
	}

	public function get_site_health( $args = array() ) {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$health = \WP_Site_Health::get_instance();
		$tests  = array();
		$critical = 0;
		$recommended = 0;
		$good = 0;

		// Prefer get_tests() structure when available.
		$available = method_exists( $health, 'get_tests' ) ? $health->get_tests() : array();
		$direct = isset( $available['direct'] ) ? $available['direct'] : array();

		foreach ( $direct as $key => $test ) {
			if ( empty( $test['test'] ) ) {
				continue;
			}
			$callback = $test['test'];
			try {
				if ( is_string( $callback ) && method_exists( $health, 'get_test_' . $callback ) ) {
					$result = call_user_func( array( $health, 'get_test_' . $callback ) );
				} elseif ( is_callable( $callback ) ) {
					$result = call_user_func( $callback );
				} else {
					continue;
				}
			} catch ( \Throwable $e ) {
				$result = array(
					'label'  => $key,
					'status' => 'recommended',
					'description' => $e->getMessage(),
				);
			}
			if ( ! is_array( $result ) ) {
				continue;
			}
			$status = isset( $result['status'] ) ? $result['status'] : 'good';
			if ( 'critical' === $status ) {
				$critical++;
			} elseif ( 'recommended' === $status ) {
				$recommended++;
			} else {
				$good++;
			}
			$tests[] = array(
				'key'         => $key,
				'label'       => isset( $result['label'] ) ? wp_strip_all_tags( $result['label'] ) : $key,
				'status'      => $status,
				'description' => isset( $result['description'] ) ? wp_strip_all_tags( $result['description'] ) : '',
			);
		}

		$core_updates = get_core_updates();
		$plugin_updates = get_plugin_updates();
		$theme_updates  = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();

		return array(
			'environment' => McpSupport::get_environment(),
			'summary'     => array(
				'good'        => $good,
				'recommended' => $recommended,
				'critical'    => $critical,
			),
			'tests'       => $tests,
			'updates'     => array(
				'core'    => is_array( $core_updates ) ? array_values( $core_updates ) : array(),
				'plugins' => count( (array) $plugin_updates ),
				'themes'  => count( (array) $theme_updates ),
				'plugin_list' => array_map(
					function ( $file, $data ) {
						return array(
							'file' => $file,
							'name' => isset( $data->Name ) ? $data->Name : $file,
							'current' => isset( $data->Version ) ? $data->Version : null,
							'new' => ( isset( $data->update ) && isset( $data->update->new_version ) ) ? $data->update->new_version : null,
						);
					},
					array_keys( (array) $plugin_updates ),
					array_values( (array) $plugin_updates )
				),
			),
			'php'         => array(
				'version' => PHP_VERSION,
				'memory_limit' => ini_get( 'memory_limit' ),
				'max_execution_time' => ini_get( 'max_execution_time' ),
			),
			'wordpress'   => array(
				'version' => get_bloginfo( 'version' ),
				'multisite' => is_multisite(),
				'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			),
		);
	}

	public function export_post( $args ) {
		return McpTransfer::export_post( $args );
	}

	public function import_post( $args ) {
		return McpTransfer::import_post( $args );
	}

	/**
	 * Generate a Cursor SKILL.md tailored to this site.
	 */
	public function generate_cursor_skill( $args = array() ) {
		$ctx = $this->get_site_context();
		$env = $ctx['site']['environment'];
		$types = $this->list_post_types();
		$type_names = array_map(
			function ( $t ) {
				return $t['name'] . ' (' . $t['label'] . ')';
			},
			$types
		);

		$acf_sections = array();
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$focus_types = ! empty( $args['post_types'] ) && is_array( $args['post_types'] )
				? $args['post_types']
				: array( 'page' );
			foreach ( $focus_types as $pt ) {
				try {
					$schema = $this->get_acf_schema( array( 'post_type' => $pt ) );
				} catch ( \Throwable $e ) {
					continue;
				}
				$lines = array( "### ACF for `{$pt}`" );
				foreach ( $schema['groups'] as $g ) {
					$lines[] = '- **' . $g['title'] . '** (`' . $g['key'] . '`)';
					foreach ( $g['fields'] as $f ) {
						$lines[] = '  - `' . $f['name'] . '` (' . $f['type'] . ')' . ( ! empty( $f['layouts'] ) ? ' — flexible layouts: ' . implode( ', ', array_map( function ( $l ) { return $l['name']; }, $f['layouts'] ) ) : '' );
					}
				}
				$acf_sections[] = implode( "\n", $lines );
			}
		}

		$menus = $this->list_menus();
		$menu_lines = array();
		foreach ( $menus['menus'] as $m ) {
			$locs = $m['locations'] ? implode( ', ', $m['locations'] ) : 'unassigned';
			$menu_lines[] = '- ' . $m['name'] . ' (id ' . $m['id'] . ') — locations: ' . $locs;
		}

		$writes = ! empty( $ctx['mcp']['writes_enabled'] ) ? 'enabled' : 'disabled';
		$slug = sanitize_title( $ctx['site']['name'] );
		if ( ! $slug ) {
			$slug = 'mt-site';
		}

		$skill = <<<MD
---
name: mt-site-{$slug}
description: How to work on {$ctx['site']['name']} via mt-mcp. Use when editing content, SEO, ACF, or reviewing this WordPress site.
---

# {$ctx['site']['name']} ({$ctx['site']['environment_label']})

## Connection
- Site URL: {$ctx['site']['url']}
- MCP URL: {$ctx['site']['mcp_url']}
- REST MCP: {$ctx['site']['rest_mcp_url']}
- Environment: **{$env}** (source: {$ctx['site']['environment_source']})
- MCP writes: **{$writes}**

## Stack
- WordPress {$ctx['versions']['wordpress']} / PHP {$ctx['versions']['php']}
- Theme: {$ctx['theme']['name']} {$ctx['theme']['version']} (`{$ctx['theme']['stylesheet']}`)
- ACF: {$this->bool_word( $ctx['integrations']['acf'] )}
- Yoast: {$this->bool_word( $ctx['integrations']['yoast'] )}
- Redirects backend: {$this->null_word( $ctx['integrations']['redirects'] )}

## Content types
{$this->bullet_list( $type_names )}

## Reading settings
- show_on_front: {$ctx['reading']['show_on_front']}
- page_on_front: {$ctx['reading']['page_on_front']}
- page_for_posts: {$ctx['reading']['page_for_posts']}

## Menus
{$this->bullet_or_none( $menu_lines )}

{$this->join_sections( $acf_sections )}

## Agent workflow
1. Call `get_site_context` at the start of every session and confirm environment.
2. Prefer `dry_run: true` on write tools before applying changes.
3. For ACF flexible content: call `get_acf_schema` / `get_post`, then mirror shapes exactly on `update_post`.
4. For SEO bulk work: `seo_audit` → draft fixes → `bulk_update_yoast` with dry_run → apply.
5. Staging → production page moves: `export_post` on source (optionally `embed_media: true`), then `import_post` on target with dry_run first.
6. After risky edits: `list_revisions` / `restore_revision` if needed.

## Conventions
- On **production**, be conservative: prefer drafts, dry_run, and small batches.
- Never commit API keys to git; use gitignored MCP config.
- Regenerate this skill with `generate_cursor_skill` when ACF schemas or site structure change.
MD;

		$suggested_path = '.cursor/skills/mt-site-' . $slug . '/SKILL.md';
		return array(
			'suggested_path' => $suggested_path,
			'environment'    => $env,
			'skill_markdown' => $skill,
		);
	}

	/* ---- New tools (FEATURES.md) ---- */

	public function bulk_update_seo( $args ) {
		$provider = isset( $args['provider'] ) ? sanitize_key( $args['provider'] ) : McpSeo::primary_provider();
		$items    = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		$dry_run  = ! empty( $args['dry_run'] );
		if ( empty( $items ) ) {
			throw new \Exception( 'items array required.' );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			throw new \Exception( 'Max ' . self::MAX_BATCH . ' items per batch.' );
		}
		$results = array();
		foreach ( $items as $item ) {
			$post_id = McpSupport::resolve_post_id( $item );
			$before  = McpSeo::get_fields( $post_id, $provider );
			$fields  = array();
			foreach ( array( 'title', 'metadesc', 'description', 'focuskw', 'canonical' ) as $k ) {
				if ( isset( $item[ $k ] ) ) {
					$fields[ $k ] = $item[ $k ];
				}
			}
			if ( ! empty( $item['seo'] ) && is_array( $item['seo'] ) ) {
				$fields = array_merge( $fields, $item['seo'] );
			}
			if ( ! $dry_run ) {
				McpSeo::set_fields( $post_id, $fields, $provider );
			}
			$after = $dry_run
				? array( 'provider' => $provider, 'fields' => array_merge( $before['fields'], $fields ) )
				: McpSeo::get_fields( $post_id, $provider );
			$results[] = array(
				'id'     => $post_id,
				'dry_run'=> $dry_run,
				'diff'   => McpSupport::diff_values( $before['fields'], $after['fields'] ),
			);
		}
		return array( 'provider' => $provider, 'results' => $results );
	}

	public function suggest_internal_links( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		$text = wp_strip_all_tags( $post->post_title . ' ' . $post->post_content );
		$words = preg_split( '/\W+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = array_values( array_unique( array_filter( $words, function ( $w ) {
			return strlen( $w ) > 4;
		} ) ) );
		$words = array_slice( $words, 0, 12 );
		if ( empty( $words ) ) {
			return array( 'id' => $post_id, 'suggestions' => array() );
		}
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				's'              => implode( ' ', array_slice( $words, 0, 5 ) ),
				'post__not_in'   => array( $post_id ),
			)
		);
		$suggestions = array();
		foreach ( $query->posts as $p ) {
			$suggestions[] = array(
				'id'    => $p->ID,
				'title' => get_the_title( $p ),
				'url'   => get_permalink( $p ),
				'type'  => $p->post_type,
			);
		}
		return array(
			'id'          => $post_id,
			'seed_terms'  => $words,
			'suggestions' => $suggestions,
		);
	}

	public function validate_schema_ld( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		return McpSeo::extract_json_ld( $post_id );
	}

	public function sitemap_diff( $args = array() ) {
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 40;
		$types = get_post_types( array( 'public' => true ), 'names' );
		$query = new \WP_Query(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$issues  = array();
		$checked = 0;
		$ssl     = null;
		foreach ( $query->posts as $id ) {
			$url = get_permalink( $id );
			if ( ! $url ) {
				continue;
			}
			if ( null === $ssl ) {
				$ssl = apply_filters( 'mt_mcp_sslverify', true, $url );
			}
			$checked++;
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 3,
					'redirection' => 0,
					'sslverify'   => $ssl,
				)
			);
			if ( is_wp_error( $response ) ) {
				$issues[] = array( 'id' => $id, 'url' => $url, 'issue' => 'request_error', 'detail' => $response->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$loc  = wp_remote_retrieve_header( $response, 'location' );
			if ( $code >= 400 ) {
				$issues[] = array( 'id' => $id, 'url' => $url, 'issue' => 'http_' . $code );
			} elseif ( $code >= 300 && $loc ) {
				$issues[] = array( 'id' => $id, 'url' => $url, 'issue' => 'redirect', 'location' => $loc, 'code' => $code );
			}
			if ( count( $issues ) >= 50 ) {
				break;
			}
		}
		return array(
			'checked'     => $checked,
			'total'       => (int) $query->found_posts,
			'limit'       => $limit,
			'issue_count' => count( $issues ),
			'issues'      => $issues,
			'truncated'   => $query->found_posts > $checked || count( $issues ) >= 50,
		);
	}

	public function bulk_fix_media_alt( $args ) {
		$dry_run = ! empty( $args['dry_run'] );
		$limit   = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$hint    = isset( $args['hint_template'] ) ? (string) $args['hint_template'] : '{title}';
		$query   = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				),
			)
		);
		$planned = array();
		foreach ( $query->posts as $att ) {
			$alt = str_replace(
				array( '{title}', '{filename}' ),
				array( $att->post_title, basename( get_attached_file( $att->ID ) ) ),
				$hint
			);
			$alt = trim( wp_strip_all_tags( $alt ) );
			$planned[] = array( 'id' => $att->ID, 'proposed_alt' => $alt );
			if ( ! $dry_run && $alt ) {
				update_post_meta( $att->ID, '_wp_attachment_image_alt', $alt );
			}
		}
		return array(
			'dry_run' => $dry_run,
			'count'   => count( $planned ),
			'items'   => $planned,
			'note'    => 'Template-based filler. Pass vision-model alts via update_media per id when available.',
		);
	}

	public function media_alt_report( $args = array() ) {
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$mime  = isset( $args['mime_type'] ) ? sanitize_text_field( $args['mime_type'] ) : 'image';
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			),
		);
		if ( $mime ) {
			$query_args['post_mime_type'] = $mime;
		}
		$missing_q = new \WP_Query( $query_args );
		$missing_ids = array_map( 'intval', $missing_q->posts );

		$total_q = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'post_mime_type'         => $mime ? $mime : null,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( array_slice( $missing_ids, 0, $limit ) as $id ) {
			$att = get_post( $id );
			$items[] = array(
				'id'       => $id,
				'title'    => $att ? $att->post_title : '',
				'url'      => wp_get_attachment_url( $id ),
				'filename' => basename( (string) get_attached_file( $id ) ),
				'mime'     => get_post_mime_type( $id ),
			);
		}

		return array(
			'total_attachments' => (int) $total_q->found_posts,
			'missing_alt_total' => (int) $missing_q->found_posts,
			'missing_alt_ids'   => $missing_ids,
			'items'             => $items,
			'limit'             => $limit,
			'truncated'         => $missing_q->found_posts > count( $missing_ids ),
			'hint'              => 'Pass missing_alt_ids into bulk_fill_media_alt or update_media per id.',
		);
	}

	public function schedule_post( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$dry_run = ! empty( $args['dry_run'] );
		$when    = isset( $args['when'] ) ? strtotime( $args['when'] ) : false;
		$action  = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : 'publish';
		if ( ! $when ) {
			throw new \Exception( 'Provide when as a strtotime-compatible datetime.' );
		}
		if ( ! in_array( $action, array( 'publish', 'draft', 'private' ), true ) ) {
			throw new \Exception( 'action must be publish, draft, or private.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		$gmt = gmdate( 'Y-m-d H:i:s', $when );
		$local = get_date_from_gmt( $gmt );
		$update = array(
			'ID'            => $post_id,
			'post_status'   => ( 'publish' === $action && $when > time() ) ? 'future' : $action,
			'post_date'     => $local,
			'post_date_gmt' => $gmt,
		);
		if ( $dry_run ) {
			return array( 'dry_run' => true, 'planned' => $update );
		}
		$r = wp_update_post( $update, true );
		if ( is_wp_error( $r ) ) {
			throw new \Exception( $r->get_error_message() );
		}
		return array( 'id' => $post_id, 'status' => get_post_status( $post_id ), 'post_date_gmt' => get_post_field( 'post_date_gmt', $post_id ) );
	}

	public function export_posts( $args ) {
		$ids = isset( $args['ids'] ) && is_array( $args['ids'] ) ? array_map( 'intval', $args['ids'] ) : array();
		if ( empty( $ids ) && ! empty( $args['post_type'] ) ) {
			$q = new \WP_Query(
				array(
					'post_type'      => sanitize_key( $args['post_type'] ),
					'post_status'    => isset( $args['status'] ) ? $args['status'] : 'publish',
					'posts_per_page' => isset( $args['limit'] ) ? min( 50, (int) $args['limit'] ) : 20,
					'fields'         => 'ids',
				)
			);
			$ids = $q->posts;
		}
		if ( empty( $ids ) ) {
			throw new \Exception( 'Provide ids[] or post_type.' );
		}
		$packages = array();
		foreach ( $ids as $id ) {
			$packages[] = McpTransfer::export_post(
				array(
					'id'            => $id,
					'include_media' => ! isset( $args['include_media'] ) || ! empty( $args['include_media'] ),
					'embed_media'   => ! empty( $args['embed_media'] ),
				)
			);
		}
		return array(
			'package_version' => McpTransfer::PACKAGE_VERSION,
			'count'           => count( $packages ),
			'packages'        => $packages,
		);
	}

	public function import_posts( $args ) {
		$packages = isset( $args['packages'] ) && is_array( $args['packages'] ) ? $args['packages'] : array();
		if ( empty( $packages ) && ! empty( $args['package']['packages'] ) ) {
			$packages = $args['package']['packages'];
		}
		if ( empty( $packages ) ) {
			throw new \Exception( 'packages array required.' );
		}
		$dry_run = ! empty( $args['dry_run'] );
		$results = array();
		foreach ( $packages as $pkg ) {
			$results[] = McpTransfer::import_post(
				array_merge(
					$args,
					array(
						'package' => $pkg,
						'dry_run' => $dry_run,
					)
				)
			);
		}
		return array( 'dry_run' => $dry_run, 'results' => $results );
	}

	public function check_import_conflicts( $args ) {
		$package = isset( $args['package'] ) ? $args['package'] : null;
		if ( ! is_array( $package ) || empty( $package['post']['post_name'] ) ) {
			throw new \Exception( 'package with post.post_name required.' );
		}
		$slug = $package['post']['post_name'];
		$type = isset( $package['post']['post_type'] ) ? $package['post']['post_type'] : 'page';
		$existing = get_page_by_path( $slug, OBJECT, $type );
		if ( ! $existing ) {
			return array( 'conflict' => false, 'slug' => $slug );
		}
		$src_content = isset( $package['post']['post_content'] ) ? $package['post']['post_content'] : '';
		$diverged = $existing->post_content !== $src_content;
		return array(
			'conflict'          => true,
			'slug'              => $slug,
			'target_id'         => $existing->ID,
			'target_url'        => get_permalink( $existing ),
			'content_diverged'  => $diverged,
			'target_modified'   => $existing->post_modified_gmt,
			'source_exported_at'=> isset( $package['exported_at'] ) ? $package['exported_at'] : null,
		);
	}

	public function dedupe_attachments( $args = array() ) {
		$dry_run = ! empty( $args['dry_run'] );
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT meta_value AS sha, COUNT(*) AS c, GROUP_CONCAT(post_id) AS ids
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_mt_mcp_sha256' AND meta_value <> ''
			 GROUP BY meta_value HAVING c > 1
			 LIMIT 50",
			ARRAY_A
		);
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$ids = array_map( 'intval', explode( ',', $row['ids'] ) );
			sort( $ids );
			$keep = array_shift( $ids );
			$groups[] = array(
				'sha256'   => $row['sha'],
				'keep_id'  => $keep,
				'duplicate_ids' => $ids,
			);
			if ( ! $dry_run ) {
				foreach ( $ids as $dup ) {
					wp_delete_attachment( $dup, true );
				}
			}
		}
		return array( 'dry_run' => $dry_run, 'groups' => $groups );
	}

	public function export_audit_log( $args = array() ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
		return array(
			'format' => 'csv',
			'csv'    => McpSupport::audit_log_csv( $limit ),
			'entries'=> McpSupport::get_audit_log( $limit ),
		);
	}

	public function notify_write_batch( $args = array() ) {
		$url = get_option( McpSupport::OPTION_WEBHOOK_URL );
		if ( ! $url ) {
			throw new \Exception( 'No webhook URL configured in Settings → MT MCP.' );
		}
		$user = wp_get_current_user();
		$payload = array(
			'event'       => 'mt_mcp.write_batch',
			'site'        => home_url( '/' ),
			'environment' => McpSupport::get_environment(),
			'user'        => $user && $user->ID ? $user->user_login : '',
			'summary'     => isset( $args['summary'] ) ? sanitize_text_field( $args['summary'] ) : '',
			'tools'       => isset( $args['tools'] ) ? (array) $args['tools'] : array(),
			'post_ids'    => isset( $args['post_ids'] ) ? array_map( 'intval', (array) $args['post_ids'] ) : array(),
			'ts'          => gmdate( 'c' ),
		);
		$dry_run = ! empty( $args['dry_run'] );
		if ( $dry_run ) {
			return array( 'dry_run' => true, 'payload' => $payload, 'url' => $url );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}
		return array(
			'ok'     => true,
			'status' => (int) wp_remote_retrieve_response_code( $response ),
		);
	}

	private function bool_word( $v ) {
		return $v ? 'yes' : 'no';
	}

	private function null_word( $v ) {
		return $v ? (string) $v : 'none';
	}

	private function bullet_list( $items ) {
		if ( empty( $items ) ) {
			return '- (none)';
		}
		return implode( "\n", array_map( function ( $i ) { return '- ' . $i; }, $items ) );
	}

	private function bullet_or_none( $items ) {
		return $this->bullet_list( $items );
	}

	private function join_sections( $sections ) {
		return $sections ? implode( "\n\n", $sections ) : "## ACF\n- (ACF not active or no groups for requested types)";
	}

	public function create_draft_preview( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		$ttl = isset( $args['ttl'] ) ? (int) $args['ttl'] : null;
		if ( ! empty( $args['dry_run'] ) ) {
			$post = get_post( $post_id );
			return array(
				'dry_run' => true,
				'would_create' => array(
					'post_id' => $post_id,
					'title'   => $post ? get_the_title( $post ) : '',
					'status'  => $post ? $post->post_status : '',
					'ttl'     => $ttl ? $ttl : McpPublic::PREVIEW_TTL,
				),
			);
		}
		return McpPublic::create_preview_link( $post_id, $ttl );
	}

	public function revoke_draft_preview( $args ) {
		$token = isset( $args['token'] ) ? $args['token'] : '';
		return McpPublic::revoke_preview_link( $token );
	}

	public function prepare_media_upload( $args ) {
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'would_create_ticket' => array(
					'alt' => isset( $args['alt'] ) ? $args['alt'] : null,
					'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
					'max_bytes' => isset( $args['max_bytes'] ) ? (int) $args['max_bytes'] : ( 25 * 1024 * 1024 ),
				),
			);
		}
		return McpPublic::create_upload_ticket( $args );
	}

	public function import_redirects( $args ) {
		$items = array();
		if ( ! empty( $args['items'] ) && is_array( $args['items'] ) ) {
			$items = $args['items'];
		} elseif ( ! empty( $args['csv'] ) && is_string( $args['csv'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', trim( $args['csv'] ) );
			foreach ( $lines as $i => $line ) {
				$line = trim( $line );
				if ( '' === $line || 0 === strpos( $line, '#' ) ) {
					continue;
				}
				// Allow from,to,code
				$cols = str_getcsv( $line );
				if ( count( $cols ) < 2 ) {
					throw new \Exception( "CSV line " . ( $i + 1 ) . " needs at least from,to." );
				}
				if ( 0 === $i && in_array( strtolower( $cols[0] ), array( 'from', 'source', 'origin' ), true ) ) {
					continue; // header
				}
				$items[] = array(
					'from' => $cols[0],
					'to'   => $cols[1],
					'code' => isset( $cols[2] ) && $cols[2] !== '' ? (int) $cols[2] : 301,
				);
			}
		}
		if ( empty( $items ) ) {
			throw new \Exception( 'Provide items:[{from,to,code?}] or csv string.' );
		}
		if ( count( $items ) > 200 ) {
			throw new \Exception( 'Max 200 redirects per import_redirects call.' );
		}

		$planned = array();
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				throw new \Exception( "items[$i] must be an object." );
			}
			$from = isset( $item['from'] ) ? $item['from'] : ( isset( $item['origin'] ) ? $item['origin'] : '' );
			$to   = isset( $item['to'] ) ? $item['to'] : ( isset( $item['target'] ) ? $item['target'] : '' );
			$code = isset( $item['code'] ) ? (int) $item['code'] : 301;
			if ( ! $from || ! $to ) {
				throw new \Exception( "items[$i] requires from and to." );
			}
			$planned[] = array( 'from' => $from, 'to' => $to, 'code' => $code );
		}

		$dry = ! empty( $args['dry_run'] ) || empty( $args['apply'] );
		if ( $dry && empty( $args['state_token'] ) ) {
			$token = McpPublic::create_state_token(
				'import_redirects',
				array( 'items' => $planned ),
				array( 'count' => count( $planned ), 'sample' => array_slice( $planned, 0, 5 ) )
			);
			return array_merge(
				array(
					'dry_run' => true,
					'backend' => McpSupport::redirects_backend(),
					'count'   => count( $planned ),
					'items'   => $planned,
				),
				$token
			);
		}

		if ( ! empty( $args['state_token'] ) ) {
			$payload = McpPublic::consume_state_token( $args['state_token'], 'import_redirects' );
			$planned = isset( $payload['items'] ) ? $payload['items'] : $planned;
		} elseif ( empty( $args['apply'] ) ) {
			throw new \Exception( 'Pass apply:true with state_token from the dry_run preview, or dry_run first.' );
		}

		$results = array();
		foreach ( $planned as $row ) {
			try {
				$results[] = array_merge(
					array( 'ok' => true ),
					$this->create_redirect(
						array(
							'from' => $row['from'],
							'to'   => $row['to'],
							'code' => $row['code'],
						)
					)
				);
			} catch ( \Throwable $e ) {
				$results[] = array( 'ok' => false, 'from' => $row['from'], 'error' => $e->getMessage() );
			}
		}
		$ok = count( array_filter( $results, function ( $r ) { return ! empty( $r['ok'] ); } ) );
		return array(
			'applied' => true,
			'ok'      => $ok,
			'failed'  => count( $results ) - $ok,
			'results' => $results,
		);
	}

	public function acf_media_audit( $args ) {
		if ( ! function_exists( 'get_fields' ) || ! function_exists( 'acf_get_field_groups' ) ) {
			throw new \Exception( 'ACF is not active on this site.' );
		}
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		if ( ! post_type_exists( $post_type ) ) {
			throw new \Exception( 'Unknown post_type.' );
		}
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 40;
		$status = isset( $args['status'] ) ? $args['status'] : 'any';
		$query  = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$image_types = array( 'image', 'gallery', 'file', 'photo', 'image_aspect_ratio_crop' );
		$issues = array();
		$checked_fields = 0;

		foreach ( $query->posts as $post_id ) {
			$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
			foreach ( $groups as $g ) {
				$fields = acf_get_fields( $g );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				$this->audit_acf_fields_media( $fields, $post_id, '', $image_types, $issues, $checked_fields );
			}
			if ( count( $issues ) >= 100 ) {
				break;
			}
		}

		return array(
			'post_type'      => $post_type,
			'posts_scanned'  => count( $query->posts ),
			'fields_checked' => $checked_fields,
			'issue_count'    => count( $issues ),
			'issues'         => $issues,
			'truncated'      => count( $issues ) >= 100 || $query->found_posts > $limit,
		);
	}

	private function audit_acf_fields_media( $fields, $post_id, $prefix, $image_types, &$issues, &$checked_fields ) {
		foreach ( $fields as $f ) {
			if ( empty( $f['name'] ) || empty( $f['type'] ) ) {
				continue;
			}
			$path = $prefix ? ( $prefix . '.' . $f['name'] ) : $f['name'];
			$type = $f['type'];
			if ( in_array( $type, $image_types, true ) ) {
				$checked_fields++;
				$val = function_exists( 'get_field' ) ? get_field( $f['name'], $post_id ) : null;
				$this->collect_media_issues( $val, $post_id, $path, $type, $issues );
			}
			if ( ! empty( $f['sub_fields'] ) && is_array( $f['sub_fields'] ) && in_array( $type, array( 'group', 'repeater', 'flexible_content' ), true ) ) {
				// For nested structures, get_field on parent and walk values with schema.
				$parent_val = function_exists( 'get_field' ) ? get_field( $f['name'], $post_id ) : null;
				$this->walk_acf_value_for_media( $parent_val, $f, $post_id, $path, $image_types, $issues, $checked_fields );
			}
			if ( 'flexible_content' === $type && ! empty( $f['layouts'] ) ) {
				$parent_val = function_exists( 'get_field' ) ? get_field( $f['name'], $post_id ) : null;
				$this->walk_acf_value_for_media( $parent_val, $f, $post_id, $path, $image_types, $issues, $checked_fields );
			}
		}
	}

	private function walk_acf_value_for_media( $value, $field_schema, $post_id, $path, $image_types, &$issues, &$checked_fields ) {
		if ( empty( $value ) ) {
			return;
		}
		$type = isset( $field_schema['type'] ) ? $field_schema['type'] : '';
		if ( 'repeater' === $type && is_array( $value ) && ! empty( $field_schema['sub_fields'] ) ) {
			foreach ( $value as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $field_schema['sub_fields'] as $sub ) {
					$name = isset( $sub['name'] ) ? $sub['name'] : '';
					if ( ! $name || ! array_key_exists( $name, $row ) ) {
						continue;
					}
					$sub_path = $path . '[' . $i . '].' . $name;
					if ( in_array( $sub['type'], $image_types, true ) ) {
						$checked_fields++;
						$this->collect_media_issues( $row[ $name ], $post_id, $sub_path, $sub['type'], $issues );
					} elseif ( in_array( $sub['type'], array( 'group', 'repeater', 'flexible_content' ), true ) ) {
						$this->walk_acf_value_for_media( $row[ $name ], $sub, $post_id, $sub_path, $image_types, $issues, $checked_fields );
					}
				}
			}
		} elseif ( 'group' === $type && is_array( $value ) && ! empty( $field_schema['sub_fields'] ) ) {
			foreach ( $field_schema['sub_fields'] as $sub ) {
				$name = isset( $sub['name'] ) ? $sub['name'] : '';
				if ( ! $name || ! array_key_exists( $name, $value ) ) {
					continue;
				}
				$sub_path = $path . '.' . $name;
				if ( in_array( $sub['type'], $image_types, true ) ) {
					$checked_fields++;
					$this->collect_media_issues( $value[ $name ], $post_id, $sub_path, $sub['type'], $issues );
				} elseif ( in_array( $sub['type'], array( 'group', 'repeater', 'flexible_content' ), true ) ) {
					$this->walk_acf_value_for_media( $value[ $name ], $sub, $post_id, $sub_path, $image_types, $issues, $checked_fields );
				}
			}
		} elseif ( 'flexible_content' === $type && is_array( $value ) && ! empty( $field_schema['layouts'] ) ) {
			$layouts = array();
			foreach ( $field_schema['layouts'] as $layout ) {
				if ( ! empty( $layout['name'] ) ) {
					$layouts[ $layout['name'] ] = $layout;
				}
			}
			foreach ( $value as $i => $row ) {
				if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
					continue;
				}
				$layout_name = $row['acf_fc_layout'];
				if ( empty( $layouts[ $layout_name ]['sub_fields'] ) ) {
					continue;
				}
				foreach ( $layouts[ $layout_name ]['sub_fields'] as $sub ) {
					$name = isset( $sub['name'] ) ? $sub['name'] : '';
					if ( ! $name || ! array_key_exists( $name, $row ) ) {
						continue;
					}
					$sub_path = $path . '[' . $i . ':' . $layout_name . '].' . $name;
					if ( in_array( $sub['type'], $image_types, true ) ) {
						$checked_fields++;
						$this->collect_media_issues( $row[ $name ], $post_id, $sub_path, $sub['type'], $issues );
					} elseif ( in_array( $sub['type'], array( 'group', 'repeater', 'flexible_content' ), true ) ) {
						$this->walk_acf_value_for_media( $row[ $name ], $sub, $post_id, $sub_path, $image_types, $issues, $checked_fields );
					}
				}
			}
		}
	}

	private function collect_media_issues( $val, $post_id, $path, $type, &$issues ) {
		$ids = array();
		if ( empty( $val ) && '0' !== $val && 0 !== $val ) {
			return;
		}
		if ( 'gallery' === $type && is_array( $val ) ) {
			foreach ( $val as $item ) {
				$ids[] = $this->acf_media_to_id( $item );
			}
		} else {
			$ids[] = $this->acf_media_to_id( $val );
		}
		foreach ( $ids as $att_id ) {
			if ( ! $att_id ) {
				$issues[] = array(
					'post_id' => (int) $post_id,
					'path'    => $path,
					'type'    => $type,
					'issue'   => 'invalid_or_empty_id',
					'value'   => is_scalar( $val ) ? $val : '[complex]',
				);
				continue;
			}
			$att = get_post( $att_id );
			if ( ! $att || 'attachment' !== $att->post_type ) {
				$issues[] = array(
					'post_id'       => (int) $post_id,
					'path'          => $path,
					'type'          => $type,
					'attachment_id' => $att_id,
					'issue'         => 'missing_attachment',
				);
				continue;
			}
			$file = get_attached_file( $att_id );
			if ( ! $file || ! file_exists( $file ) ) {
				$issues[] = array(
					'post_id'       => (int) $post_id,
					'path'          => $path,
					'type'          => $type,
					'attachment_id' => $att_id,
					'issue'         => 'missing_file',
				);
			}
			if ( 0 === strpos( (string) get_post_mime_type( $att_id ), 'image/' ) ) {
				$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
				if ( '' === trim( (string) $alt ) ) {
					$issues[] = array(
						'post_id'       => (int) $post_id,
						'path'          => $path,
						'type'          => $type,
						'attachment_id' => $att_id,
						'issue'         => 'missing_alt',
						'url'           => wp_get_attachment_url( $att_id ),
					);
				}
			}
		}
	}

	private function acf_media_to_id( $val ) {
		if ( is_numeric( $val ) ) {
			return (int) $val;
		}
		if ( is_array( $val ) ) {
			if ( isset( $val['ID'] ) ) {
				return (int) $val['ID'];
			}
			if ( isset( $val['id'] ) ) {
				return (int) $val['id'];
			}
		}
		return 0;
	}

	public function set_write_gates( $args ) {
		$changed = array();
		if ( array_key_exists( 'pause_writes', $args ) ) {
			$val = ! empty( $args['pause_writes'] );
			update_option( McpSupport::OPTION_PAUSE_WRITES, $val ? '1' : '' );
			$changed['pause_writes'] = $val;
		}
		if ( array_key_exists( 'force_draft', $args ) ) {
			$val = ! empty( $args['force_draft'] );
			update_option( McpSupport::OPTION_FORCE_DRAFT, $val ? '1' : '' );
			$changed['force_draft'] = $val;
		}
		if ( array_key_exists( 'site_brief', $args ) ) {
			$brief = sanitize_textarea_field( (string) $args['site_brief'] );
			if ( strlen( $brief ) > 2000 ) {
				throw new \Exception( 'site_brief max 2000 characters.' );
			}
			update_option( McpSupport::OPTION_SITE_BRIEF, $brief, false );
			$changed['site_brief'] = $brief;
		}
		if ( empty( $changed ) ) {
			throw new \Exception( 'Provide pause_writes and/or force_draft and/or site_brief.' );
		}
		return array(
			'updated'      => $changed,
			'pause_writes' => McpSupport::writes_paused(),
			'force_draft'  => McpSupport::force_draft_enabled(),
			'site_brief'   => McpSupport::site_brief(),
		);
	}

	public function confirm_dangerous_op( $args ) {
		$token = isset( $args['state_token'] ) ? $args['state_token'] : '';
		$op    = isset( $args['operation'] ) ? sanitize_key( $args['operation'] ) : '';
		if ( ! $token || ! $op ) {
			throw new \Exception( 'Provide operation and state_token from a dry_run preview.' );
		}
		$payload = McpPublic::consume_state_token( $token, $op );
		if ( 'force_delete_post' === $op ) {
			$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
			return $this->trash_post( array( 'id' => $post_id, 'force_delete' => true, '_confirmed' => true ) );
		}
		if ( 'import_redirects' === $op ) {
			return $this->import_redirects( array( 'items' => isset( $payload['items'] ) ? $payload['items'] : array(), 'apply' => true ) );
		}
		throw new \Exception( 'Unsupported operation for confirm_dangerous_op: ' . $op );
	}

	public function connection_doctor( $args = array() ) {
		$checks = array();
		$base   = home_url( '/' );
		$mcp    = home_url( '/mt-mcp' );
		$rest   = rest_url( 'mt-mcp/v1' );
		$ssl    = apply_filters( 'mt_mcp_sslverify', true, $base );

		$https = ( 0 === strpos( $base, 'https://' ) );
		$checks[] = array( 'key' => 'https', 'ok' => $https, 'detail' => $https ? 'Site URL is https' : 'Site URL is not https' );

		$well_known = array(
			'oauth_protected_resource' => home_url( '/.well-known/oauth-protected-resource' ),
			'oauth_authorization_server' => home_url( '/.well-known/oauth-authorization-server' ),
		);
		foreach ( $well_known as $label => $url ) {
			$res = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => $ssl ) );
			if ( is_wp_error( $res ) ) {
				$checks[] = array( 'key' => $label, 'ok' => false, 'detail' => $res->get_error_message(), 'url' => $url );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$loc  = wp_remote_retrieve_header( $res, 'location' );
			$ok   = ( 200 === $code );
			$detail = 'HTTP ' . $code;
			if ( $code >= 300 && $code < 400 ) {
				$detail .= ' redirect to ' . $loc . ' (canonical redirects break OAuth discovery)';
			}
			$checks[] = array( 'key' => $label, 'ok' => $ok, 'detail' => $detail, 'url' => $url, 'cache_control' => wp_remote_retrieve_header( $res, 'cache-control' ) );
		}

		$mcp_probe = wp_remote_request(
			$mcp,
			array(
				'method'      => 'OPTIONS',
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => $ssl,
				'headers'     => array( 'User-Agent' => 'python-httpx/0.27 MT-MCP-Doctor' ),
			)
		);
		if ( is_wp_error( $mcp_probe ) ) {
			$checks[] = array( 'key' => 'mcp_endpoint', 'ok' => false, 'detail' => $mcp_probe->get_error_message(), 'url' => $mcp );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $mcp_probe );
			$checks[] = array(
				'key'    => 'mcp_endpoint',
				'ok'     => in_array( $code, array( 200, 204, 401 ), true ),
				'detail' => 'OPTIONS HTTP ' . $code . ' (401 expected without auth)',
				'url'    => $mcp,
				'www_authenticate' => wp_remote_retrieve_header( $mcp_probe, 'www-authenticate' ),
				'cache_control' => wp_remote_retrieve_header( $mcp_probe, 'cache-control' ),
			);
		}

		$rest_probe = wp_remote_get( trailingslashit( $rest ) . 'health', array( 'timeout' => 8, 'sslverify' => $ssl ) );
		if ( is_wp_error( $rest_probe ) ) {
			$checks[] = array( 'key' => 'rest_health', 'ok' => false, 'detail' => $rest_probe->get_error_message() );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $rest_probe );
			$body = json_decode( wp_remote_retrieve_body( $rest_probe ), true );
			$checks[] = array(
				'key'    => 'rest_health',
				'ok'     => 200 === $code,
				'detail' => 'HTTP ' . $code,
				'body'   => is_array( $body ) ? McpSupport::redact_secrets( $body ) : null,
			);
		}

		$auth_header_note = 'If a WAF strips Authorization, OAuth/App Passwords fail. Check Host/WPE/Sucuri logs for python-httpx UA blocks.';
		$checks[] = array(
			'key'    => 'waf_hints',
			'ok'     => true,
			'detail' => $auth_header_note,
			'user_agents_to_allow' => array( 'python-httpx', 'Cursor', 'claude-user' ),
		);

		$gates = McpSupport::three_gate_status();
		$checks[] = array(
			'key'    => 'writes',
			'ok'     => (bool) get_option( McpSupport::OPTION_ALLOW_WRITES ) && ! McpSupport::writes_paused(),
			'detail' => sprintf(
				'allow_writes=%s pause_writes=%s force_draft=%s',
				get_option( McpSupport::OPTION_ALLOW_WRITES ) ? 'yes' : 'no',
				McpSupport::writes_paused() ? 'yes' : 'no',
				McpSupport::force_draft_enabled() ? 'yes' : 'no'
			),
		);
		$checks[] = array(
			'key'    => 'three_gates',
			'ok'     => ! empty( $gates['server_enabled'] ),
			'detail' => $gates['note'],
			'status' => $gates,
		);

		$failed = array_values( array_filter( $checks, function ( $c ) { return empty( $c['ok'] ); } ) );
		$report = array(
			'site'    => McpSupport::redact_secrets( array(
				'name' => get_bloginfo( 'name' ),
				'url'  => $base,
				'mcp'  => $mcp,
				'rest' => $rest,
				'env'  => McpSupport::get_environment(),
				'plugin_version' => defined( 'MT_MCP_VERSION' ) ? MT_MCP_VERSION : '',
			) ),
			'summary' => array(
				'ok'     => empty( $failed ),
				'passed' => count( $checks ) - count( $failed ),
				'failed' => count( $failed ),
			),
			'checks'  => $checks,
			'paste_for_support' => wp_json_encode(
				McpSupport::redact_secrets(
					array(
						'summary' => array( 'ok' => empty( $failed ), 'failed' => count( $failed ) ),
						'failed_keys' => array_map( function ( $c ) { return $c['key']; }, $failed ),
						'site_url' => $base,
						'env' => McpSupport::get_environment(),
						'version' => defined( 'MT_MCP_VERSION' ) ? MT_MCP_VERSION : '',
					)
				)
			),
		);
		return $report;
	}


	public function list_needs_review( $args = array() ) {
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 40;
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => $limit,
				'meta_key'       => '_mt_mcp_needs_review',
				'meta_value'     => '1',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		$items = array();
		foreach ( $query->posts as $id ) {
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			$items[] = array(
				'id'       => (int) $id,
				'title'    => get_the_title( $p ),
				'status'   => $p->post_status,
				'type'     => $p->post_type,
				'modified' => $p->post_modified,
				'url'      => get_permalink( $p ),
			);
		}
		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
			'hint'  => 'Use review_draft with action publish|discard|keep, or create_draft_preview to share.',
		);
	}

	public function review_draft( $args ) {
		$post_id = McpSupport::resolve_post_id( $args );
		McpSupport::assert_can_edit_post( $post_id );
		$action = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : '';
		if ( ! in_array( $action, array( 'publish', 'discard', 'keep' ), true ) ) {
			throw new \Exception( 'action must be publish, discard, or keep.' );
		}
		$post = get_post( $post_id );
		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'id'      => $post_id,
				'action'  => $action,
				'status'  => $post->post_status,
			);
		}
		if ( 'publish' === $action ) {
			$r = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $r ) ) {
				throw new \Exception( $r->get_error_message() );
			}
			delete_post_meta( $post_id, '_mt_mcp_needs_review' );
			return array( 'id' => $post_id, 'action' => 'publish', 'status' => 'publish' );
		}
		if ( 'discard' === $action ) {
			McpSupport::assert_can_delete_post( $post_id );
			wp_trash_post( $post_id );
			delete_post_meta( $post_id, '_mt_mcp_needs_review' );
			return array( 'id' => $post_id, 'action' => 'discard', 'trashed' => true );
		}
		delete_post_meta( $post_id, '_mt_mcp_needs_review' );
		return array( 'id' => $post_id, 'action' => 'keep', 'status' => $post->post_status, 'needs_review' => false );
	}

	public function list_changes( $args = array() ) {
		$limit   = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$entries = McpSupport::get_undo_journal( $limit );
		$items   = array();
		foreach ( $entries as $entry ) {
			$items[] = McpSupport::summarize_change( $entry, false );
		}
		return array(
			'items' => $items,
			'ttl_hours' => (int) ( McpSupport::UNDO_TTL_SECONDS / 3600 ),
			'hint'  => 'Use get_change for before/written/now; undo_change with token to restore. Never touches OAuth/MCP credentials.',
		);
	}

	public function get_change( $args ) {
		$token = isset( $args['token'] ) ? sanitize_text_field( (string) $args['token'] ) : '';
		if ( '' === $token ) {
			throw new \Exception( 'Provide token from list_changes or a write response undo.token.' );
		}
		$entry = McpSupport::get_change( $token );
		if ( ! $entry ) {
			throw new \Exception( 'Change not found or expired.' );
		}
		return McpSupport::summarize_change( $entry, true );
	}

	public function undo_change( $args ) {
		$token = isset( $args['token'] ) ? sanitize_text_field( (string) $args['token'] ) : '';
		if ( '' === $token ) {
			throw new \Exception( 'Provide undo token.' );
		}
		$entry = McpSupport::get_change( $token );
		if ( ! $entry ) {
			throw new \Exception( 'Change not found or expired (72h TTL).' );
		}
		if ( ! empty( $entry['undone'] ) ) {
			throw new \Exception( 'This change was already undone.' );
		}
		$force   = ! empty( $args['force'] );
		$dry_run = ! empty( $args['dry_run'] );
		$summary = McpSupport::summarize_change( $entry, true );
		if ( ! empty( $summary['drift'] ) && ! $force && ! $dry_run ) {
			throw new \Exception( 'Live state drifted from the written snapshot (after_hash mismatch). Re-call with force=true to restore anyway, or inspect with get_change.' );
		}
		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'token'   => $token,
				'would_restore' => $summary['before'],
				'drift'   => ! empty( $summary['drift'] ),
			);
		}

		$action = isset( $entry['action'] ) ? $entry['action'] : 'update';
		$before = isset( $summary['before'] ) ? $summary['before'] : null;
		$written = isset( $summary['written'] ) ? $summary['written'] : null;

		if ( 'create' === $action ) {
			$id = 0;
			if ( is_array( $written ) && ! empty( $written['id'] ) ) {
				$id = (int) $written['id'];
			} elseif ( ! empty( $entry['object']['id'] ) ) {
				$id = (int) $entry['object']['id'];
			}
			if ( ! $id ) {
				throw new \Exception( 'Cannot undo create: missing post id.' );
			}
			McpSupport::assert_can_delete_post( $id );
			wp_trash_post( $id );
			$restored = array( 'action' => 'create_undone', 'id' => $id, 'trashed' => true );
		} elseif ( 'trash' === $action ) {
			if ( ! is_array( $before ) || empty( $before['id'] ) ) {
				throw new \Exception( 'Cannot undo trash: missing before snapshot.' );
			}
			$id = (int) $before['id'];
			McpSupport::assert_can_edit_post( $id );
			wp_untrash_post( $id );
			$this->apply_post_snapshot( $before );
			$restored = array( 'action' => 'trash_undone', 'id' => $id, 'status' => get_post_status( $id ) );
		} else {
			if ( ! is_array( $before ) || empty( $before['kind'] ) ) {
				throw new \Exception( 'Cannot undo: missing before snapshot.' );
			}
			if ( 'post' === $before['kind'] ) {
				McpSupport::assert_can_edit_post( (int) $before['id'] );
				$this->apply_post_snapshot( $before );
				$restored = array( 'action' => 'update_undone', 'kind' => 'post', 'id' => (int) $before['id'] );
			} elseif ( 'media' === $before['kind'] ) {
				$this->apply_media_snapshot( $before );
				$restored = array( 'action' => 'update_undone', 'kind' => 'media', 'id' => (int) $before['id'] );
			} elseif ( 'options_page' === $before['kind'] ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'manage_options required to undo options_page.' );
				}
				$this->apply_options_page_snapshot( $before );
				$restored = array( 'action' => 'update_undone', 'kind' => 'options_page', 'page' => $before['page'] );
			} else {
				throw new \Exception( 'Unsupported snapshot kind for undo.' );
			}
		}

		$journal = get_option( McpSupport::OPTION_UNDO_JOURNAL, array() );
		if ( is_array( $journal ) && isset( $journal[ $token ] ) ) {
			$journal[ $token ]['undone']    = true;
			$journal[ $token ]['undone_at'] = gmdate( 'c' );
			$journal[ $token ]['undone_by'] = get_current_user_id();
			update_option( McpSupport::OPTION_UNDO_JOURNAL, $journal, false );
		}

		return array_merge(
			$restored,
			array(
				'token'  => $token,
				'undone' => true,
				'note'   => 'Restored before-state. MCP OAuth credentials and plugin settings were not modified.',
			)
		);
	}

	/**
	 * Restore a post snapshot. Never writes MCP/OAuth options.
	 *
	 * @param array $snap From snapshot_post.
	 */
	private function apply_post_snapshot( $snap ) {
		$id = (int) $snap['id'];
		if ( ! get_post( $id ) ) {
			throw new \Exception( 'Post #' . $id . ' no longer exists; cannot restore.' );
		}
		$core = isset( $snap['core'] ) && is_array( $snap['core'] ) ? $snap['core'] : array();
		$update = array( 'ID' => $id );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
			if ( array_key_exists( $field, $core ) ) {
				$update[ $field ] = $core[ $field ];
			}
		}
		$r = wp_update_post( $update, true );
		if ( is_wp_error( $r ) ) {
			throw new \Exception( $r->get_error_message() );
		}
		if ( array_key_exists( 'featured_image_id', $snap ) ) {
			$fid = (int) $snap['featured_image_id'];
			if ( $fid > 0 ) {
				set_post_thumbnail( $id, $fid );
			} else {
				delete_post_thumbnail( $id );
			}
		}
		if ( ! empty( $snap['taxonomies'] ) && is_array( $snap['taxonomies'] ) ) {
			foreach ( $snap['taxonomies'] as $tax => $term_ids ) {
				if ( taxonomy_exists( $tax ) ) {
					wp_set_post_terms( $id, array_map( 'intval', (array) $term_ids ), $tax );
				}
			}
		}
		if ( ! empty( $snap['yoast'] ) && is_array( $snap['yoast'] ) ) {
			foreach ( $snap['yoast'] as $key => $value ) {
				McpSupport::set_yoast_value( $key, $value, $id );
			}
		}
		if ( ! empty( $snap['acf'] ) && is_array( $snap['acf'] ) && function_exists( 'update_field' ) ) {
			foreach ( $snap['acf'] as $key => $value ) {
				update_field( $key, $value, $id );
			}
		}
		if ( array_key_exists( 'needs_review', $snap ) ) {
			if ( $snap['needs_review'] ) {
				update_post_meta( $id, '_mt_mcp_needs_review', '1' );
			} else {
				delete_post_meta( $id, '_mt_mcp_needs_review' );
			}
		}
	}

	private function apply_media_snapshot( $snap ) {
		$id = (int) $snap['id'];
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			throw new \Exception( 'Attachment #' . $id . ' not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new \Exception( 'Cannot edit attachment #' . $id . '.' );
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => isset( $snap['title'] ) ? $snap['title'] : $att->post_title,
				'post_excerpt' => isset( $snap['caption'] ) ? $snap['caption'] : $att->post_excerpt,
				'post_content' => isset( $snap['description'] ) ? $snap['description'] : $att->post_content,
			)
		);
		if ( array_key_exists( 'alt', $snap ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $snap['alt'] );
		}
	}

	private function apply_options_page_snapshot( $snap ) {
		if ( ! function_exists( 'update_field' ) ) {
			throw new \Exception( 'ACF required to restore options_page.' );
		}
		$page    = isset( $snap['page'] ) ? $snap['page'] : 'option';
		$post_id = ( 'options' === $page || 'option' === $page ) ? 'option' : $page;
		$fields  = isset( $snap['fields'] ) && is_array( $snap['fields'] ) ? $snap['fields'] : array();
		foreach ( $fields as $key => $value ) {
			// Never restore MCP / OAuth option keys via ACF field names.
			if ( is_string( $key ) && 0 === strpos( $key, 'mt_mcp_' ) ) {
				continue;
			}
			update_field( $key, $value, $post_id );
		}
	}

	public function manage_connections( $args ) {
		$action = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : 'list';
		$connections = McpSupport::get_connections();
		if ( 'list' === $action ) {
			return array( 'connections' => array_values( $connections ) );
		}
		if ( 'upsert' === $action ) {
			$name = isset( $args['name'] ) ? sanitize_title( $args['name'] ) : '';
			$url  = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
			$env  = isset( $args['environment'] ) ? sanitize_key( $args['environment'] ) : 'staging';
			if ( ! $name || ! $url ) {
				throw new \Exception( 'Provide name and url.' );
			}
			$connections[ $name ] = array(
				'name'        => $name,
				'label'       => isset( $args['label'] ) ? sanitize_text_field( $args['label'] ) : $name,
				'url'         => untrailingslashit( $url ),
				'mcp_url'     => isset( $args['mcp_url'] ) ? esc_url_raw( $args['mcp_url'] ) : untrailingslashit( $url ) . '/mt-mcp',
				'environment' => $env,
				'notes'       => isset( $args['notes'] ) ? sanitize_text_field( $args['notes'] ) : '',
			);
			McpSupport::save_connections( $connections );
			return array( 'saved' => $connections[ $name ], 'connections' => array_values( $connections ) );
		}
		if ( 'delete' === $action ) {
			$name = isset( $args['name'] ) ? sanitize_title( $args['name'] ) : '';
			if ( ! $name || ! isset( $connections[ $name ] ) ) {
				throw new \Exception( 'Unknown connection name.' );
			}
			unset( $connections[ $name ] );
			McpSupport::save_connections( $connections );
			return array( 'deleted' => $name, 'connections' => array_values( $connections ) );
		}
		throw new \Exception( 'action must be list, upsert, or delete.' );
	}

}
