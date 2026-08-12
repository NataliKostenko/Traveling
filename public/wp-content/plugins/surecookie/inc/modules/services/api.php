<?php
/**
 * Known Services API.
 *
 * Powers the Known Services library: a full catalog listing (every service,
 * including cookieless ones, with tier + counts) plus the installed + detected
 * state, and the atomic add/remove endpoints. Blocking is independent of this
 * registry, so these endpoints only manage the declared/policy state.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Api - the Known Services REST controller (GET/POST/DELETE /known-services).
 *
 * @since 1.3.0
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Route path.
	 *
	 * @since 1.3.0
	 */
	protected const ROUTE = '/known-services';

	/**
	 * Register API routes.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_known_services' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::ROUTE . '/(?P<slug>[a-z0-9-]+)',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE, // POST: install.
					'callback'            => [ $this, 'install_service' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE, // DELETE: uninstall.
					'callback'            => [ $this, 'uninstall_service' ],
					'permission_callback' => [ $this, 'validate_permission' ],
				],
			]
		);
	}

	/**
	 * Full catalog + installed + detected state for the library.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @since 1.3.0
	 * @return WP_REST_Response { services:[], installed:[], detected:[] }.
	 */
	public function get_known_services( $request ): WP_REST_Response {
		$catalog   = Services_Source::get_instance()->get_catalog();
		$installed = Installed_Services::get_instance()->get_installed();
		$services  = [];

		foreach ( $catalog as $slug => $service ) {
			if ( ! is_string( $slug ) || ! is_array( $service ) ) {
				continue;
			}

			$scripts = array_values( (array) ( $service['patterns']['scripts'] ?? [] ) );
			$iframes = array_values( (array) ( $service['patterns']['iframes'] ?? [] ) );
			$cookies = array_values( (array) ( $service['cookies'] ?? [] ) );

			$services[] = [
				'slug'        => $slug,
				'label'       => (string) ( $service['label'] ?? $slug ),
				'description' => (string) ( $service['description'] ?? '' ),
				'category'    => (string) ( $service['category'] ?? 'uncategorized' ),
				'pro'         => ! empty( $service['pro'] ),
				'cookieCount' => count( $cookies ),
				'scriptCount' => count( $scripts ),
				'iframeCount' => count( $iframes ),
				'blockable'   => $scripts !== [] || $iframes !== [],
				'cookies'     => $cookies,
				'resources'   => [
					'scripts' => $scripts,
					'iframes' => $iframes,
				],
			];
		}

		return new WP_REST_Response(
			[
				'services'  => $services,
				'installed' => array_values( $installed ),
				'detected'  => $this->detected_slugs( array_keys( $catalog ) ),
			],
			200
		);
	}

	/**
	 * Install a known service: declare its cookies + record the registry entry.
	 * Pro services require an active Pro license (enforced here, not just in UI).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request (slug URL param).
	 * @since 1.3.0
	 * @return WP_REST_Response
	 */
	public function install_service( $request ): WP_REST_Response {
		$slug    = (string) $request->get_param( 'slug' );
		$catalog = Services_Source::get_instance()->get_catalog();

		if ( ! isset( $catalog[ $slug ] ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'code'    => 'unknown_service',
					'message' => __( 'Unknown service.', 'surecookie' ),
				],
				404
			);
		}

		if ( ! empty( $catalog[ $slug ]['pro'] ) && ! Helper::is_pro_active() ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'code'    => 'pro_required',
					'message' => __( 'This is a Pro service. Upgrade to add it.', 'surecookie' ),
				],
				403
			);
		}

		$result = Installed_Services::get_instance()->install( $slug );

		return new WP_REST_Response( $result, empty( $result['success'] ) ? 400 : 200 );
	}

	/**
	 * Uninstall a known service: remove its declared cookies + registry entry.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request (slug URL param).
	 * @since 1.3.0
	 * @return WP_REST_Response
	 */
	public function uninstall_service( $request ): WP_REST_Response {
		$slug   = (string) $request->get_param( 'slug' );
		$result = Installed_Services::get_instance()->uninstall( $slug );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Catalog slugs detected in the most recent scan's resources.
	 *
	 * @param array<int, string> $slugs Catalog slugs to test.
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	private function detected_slugs( array $slugs ): array {
		$resources = (array) get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] );
		$matcher   = Service_Matcher::get_instance();
		$urls      = $matcher->collect_resource_urls( [ $resources ] );

		return array_values( $matcher->match_services( $slugs, $urls ) );
	}
}
