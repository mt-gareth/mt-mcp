<?php

namespace MT\Mcp;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-provider SEO field adapter (Yoast, Rank Math, SEOPress).
 */
class McpSeo {

	const PROVIDERS = array( 'yoast', 'rankmath', 'seopress' );

	/**
	 * Which SEO plugins are active.
	 *
	 * @return string[]
	 */
	public static function active_providers() {
		$out = array();
		if ( class_exists( 'WPSEO_Meta' ) || defined( 'WPSEO_VERSION' ) ) {
			$out[] = 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$out[] = 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ) ) {
			$out[] = 'seopress';
		}
		return $out;
	}

	public static function primary_provider() {
		$active = self::active_providers();
		return $active ? $active[0] : null;
	}

	/**
	 * Normalized SEO fields for a post across providers.
	 */
	public static function get_fields( $post_id, $provider = null ) {
		$provider = $provider ? $provider : self::primary_provider();
		if ( ! $provider ) {
			return array( 'provider' => null, 'fields' => array() );
		}
		switch ( $provider ) {
			case 'yoast':
				return array( 'provider' => 'yoast', 'fields' => McpSupport::get_yoast_fields( $post_id ) );
			case 'rankmath':
				return array( 'provider' => 'rankmath', 'fields' => self::get_rankmath_fields( $post_id ) );
			case 'seopress':
				return array( 'provider' => 'seopress', 'fields' => self::get_seopress_fields( $post_id ) );
			default:
				return array( 'provider' => null, 'fields' => array() );
		}
	}

	public static function set_fields( $post_id, $fields, $provider = null ) {
		$provider = $provider ? $provider : self::primary_provider();
		if ( ! $provider ) {
			throw new \Exception( 'No SEO plugin active (Yoast / Rank Math / SEOPress).' );
		}
		switch ( $provider ) {
			case 'yoast':
				foreach ( $fields as $key => $value ) {
					McpSupport::set_yoast_value( $key, $value, $post_id );
				}
				break;
			case 'rankmath':
				self::set_rankmath_fields( $post_id, $fields );
				break;
			case 'seopress':
				self::set_seopress_fields( $post_id, $fields );
				break;
			default:
				throw new \Exception( 'Unknown SEO provider: ' . $provider );
		}
		return self::get_fields( $post_id, $provider );
	}

	private static function get_rankmath_fields( $post_id ) {
		$map = array(
			'title'       => 'rank_math_title',
			'metadesc'    => 'rank_math_description',
			'focuskw'     => 'rank_math_focus_keyword',
			'canonical'   => 'rank_math_canonical_url',
			'robots'      => 'rank_math_robots',
		);
		$out = array();
		foreach ( $map as $norm => $meta ) {
			$out[ $norm ] = get_post_meta( $post_id, $meta, true );
		}
		return $out;
	}

	private static function set_rankmath_fields( $post_id, $fields ) {
		$map = array(
			'title'       => 'rank_math_title',
			'metadesc'    => 'rank_math_description',
			'description' => 'rank_math_description',
			'focuskw'     => 'rank_math_focus_keyword',
			'canonical'   => 'rank_math_canonical_url',
		);
		foreach ( $fields as $key => $value ) {
			if ( isset( $map[ $key ] ) ) {
				update_post_meta( $post_id, $map[ $key ], $value );
			}
		}
	}

	private static function get_seopress_fields( $post_id ) {
		return array(
			'title'     => get_post_meta( $post_id, '_seopress_titles_title', true ),
			'metadesc'  => get_post_meta( $post_id, '_seopress_titles_desc', true ),
			'focuskw'   => get_post_meta( $post_id, '_seopress_analysis_target_kw', true ),
			'canonical' => get_post_meta( $post_id, '_seopress_robots_canonical', true ),
			'noindex'   => get_post_meta( $post_id, '_seopress_robots_index', true ),
		);
	}

	private static function set_seopress_fields( $post_id, $fields ) {
		$map = array(
			'title'       => '_seopress_titles_title',
			'metadesc'    => '_seopress_titles_desc',
			'description' => '_seopress_titles_desc',
			'focuskw'     => '_seopress_analysis_target_kw',
			'canonical'   => '_seopress_robots_canonical',
		);
		foreach ( $fields as $key => $value ) {
			if ( isset( $map[ $key ] ) ) {
				update_post_meta( $post_id, $map[ $key ], $value );
			}
		}
	}

	/**
	 * Extract JSON-LD blocks from rendered post HTML (best-effort).
	 */
	public static function extract_json_ld( $post_id ) {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			throw new \Exception( 'Post has no permalink.' );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'mt_mcp_sslverify', true, $url ),
				'headers'   => array( 'User-Agent' => 'MT-MCP-SchemaValidator/1.0' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}
		$html = wp_remote_retrieve_body( $response );
		$blocks = array();
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$decoded = json_decode( trim( $raw ), true );
				$blocks[] = array(
					'valid_json' => null !== $decoded,
					'json_error' => null === $decoded ? json_last_error_msg() : null,
					'data'       => $decoded,
					'raw_chars'  => strlen( $raw ),
				);
			}
		}
		return array(
			'url'    => $url,
			'count'  => count( $blocks ),
			'blocks' => $blocks,
		);
	}
}
