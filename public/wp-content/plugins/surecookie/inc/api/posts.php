<?php
/**
 * Posts API.
 *
 * REST endpoints for searching posts for the Cookie Policy and Site Scanner
 * page pickers and fetching a single post by ID. Both surfaces default to the
 * `page` post type; developers extend the searchable list per surface (via the
 * request `context` arg) using the `surecookie_searchable_post_types` filter -
 * see Get::searchable_post_types().
 *
 * @package SureCookie\Inc\API
 * @since 0.0.1-beta.2
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Posts
 *
 * @since 0.0.1-beta.2
 */
class Posts extends Base {
	use GetInstance;

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			'/posts/search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_posts' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
						'description'       => 'Maximum number of results returned per post type.',
					],
					'context'  => [
						'type'              => 'string',
						'default'           => 'policy',
						'enum'              => [ 'policy', 'scanner' ],
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			'/posts/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_by_id' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'id'      => [
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'context' => [
						'type'              => 'string',
						'default'           => 'policy',
						'enum'              => [ 'policy', 'scanner' ],
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Search published posts for a page picker, grouped by post type.
	 *
	 * Both the Cookie Policy picker (context = 'policy') and the Site Scanner
	 * picker (context = 'scanner') default to the `page` post type; the allowed
	 * list is extensible per surface via the `surecookie_searchable_post_types`
	 * filter. One capped query runs per allowed post type so every type is
	 * represented in the results, and each row is tagged with its post type so
	 * the client can group the dropdown. Within a type, results are ordered
	 * alphabetically when no search term is given, or by relevance otherwise.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function search_posts( WP_REST_Request $request ): void {
		// Values are already sanitized and validated by route arg definitions.
		$search   = $request->get_param( 'search' );
		$per_type = $request->get_param( 'per_page' ); // Cap is applied per post type.
		$context  = $request->get_param( 'context' );

		$allowed_post_types = Get::searchable_post_types( $context );

		// Guard: WP_Query treats `'post_type' => []` as `'post'`, which would silently
		// include the default post type even if a filter attempted to remove it.
		if ( empty( $allowed_post_types ) ) {
			SendJson::success( [ 'data' => [] ] );
			return;
		}

		$posts = [];

		// One query per type keeps every group represented - a broad search term
		// can't let one dominant type crowd the others out of a single shared cap.
		foreach ( $allowed_post_types as $post_type ) {
			$type_object = get_post_type_object( $post_type );
			$type_label  = $type_object instanceof \WP_Post_Type
				? $type_object->labels->name
				: ucfirst( $post_type );

			$args = [
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_type,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true, // Skips COUNT query - pagination not needed here.
				'update_post_term_cache' => false, // Skip term cache for performance.
				'update_post_meta_cache' => false, // Skip meta cache for performance.
			];

			if ( ! empty( $search ) ) {
				$args['s']       = $search;
				$args['orderby'] = 'relevance';
				unset( $args['order'] );
			}

			foreach ( ( new \WP_Query( $args ) )->posts as $post ) {
				if ( ! ( $post instanceof \WP_Post ) ) {
					continue;
				}
				$posts[] = [
					'id'         => $post->ID,
					// Use the raw stored title instead of get_the_title() - that function runs the
					// `the_title` filter chain (SEO/translation plugins) which we don't want for an
					// admin JSON picker where the raw title is the source of truth.
					'title'      => wp_strip_all_tags( $post->post_title ),
					'type'       => $post->post_type,
					'type_label' => $type_label,
				];
			}
		}

		SendJson::success( [ 'data' => $posts ] );
	}

	/**
	 * Return basic data for a single published post regardless of post type.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function get_post_by_id( WP_REST_Request $request ): void {
		$post_id = $request->get_param( 'id' ); // Already absint by sanitize_callback.
		$post    = get_post( $post_id );

		// Return 404 for missing, non-published, OR disallowed post type so that draft/private/
		// structural IDs (attachments, nav items, blocks) are not confirmed to exist. Using the
		// same 404 for every disallowed case preserves the non-enumeration property.
		// instanceof narrows the type from WP_Post|array|null to WP_Post for PHPStan.
		if (
			! ( $post instanceof \WP_Post )
			|| $post->post_status !== 'publish'
			|| ! in_array( $post->post_type, Get::searchable_post_types( $request->get_param( 'context' ) ), true )
		) {
			SendJson::error( [ 'message' => __( 'Post not found.', 'surecookie' ) ], 404 );
			return;
		}

		// Only return a permalink when it resolves to the same host as the site. A rogue
		// `post_link` filter or custom rewrite could otherwise steer admins toward an
		// off-site URL which then ships to every visitor as the "Cookie Policy" link.
		// Mirrors the host-match pattern in Get::cookie_policy_page_details().
		$permalink = get_permalink( $post->ID );
		$link      = '';

		if ( is_string( $permalink ) && $permalink !== '' ) {
			$parsed_permalink = wp_parse_url( $permalink );
			$parsed_home      = wp_parse_url( home_url() );

			if (
				! empty( $parsed_permalink['host'] )
				&& ! empty( $parsed_home['host'] )
				&& strtolower( $parsed_permalink['host'] ) === strtolower( $parsed_home['host'] )
			) {
				$link = esc_url_raw( $permalink );
			}
		}

		$type_object = get_post_type_object( $post->post_type );

		SendJson::success(
			[
				'id'         => $post->ID,
				'title'      => wp_strip_all_tags( $post->post_title ),
				'status'     => $post->post_status,
				'link'       => $link,
				'type'       => $post->post_type,
				'type_label' => $type_object instanceof \WP_Post_Type ? $type_object->labels->name : ucfirst( $post->post_type ),
			]
		);
	}
}
