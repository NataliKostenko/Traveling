<?php
/**
 * Site Scanner API class
 *
 * Handles site scanning related REST API endpoints.
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\SiteScanner;

use SureCookie\Inc\API\Base;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Api
 *
 * @package SureCookie\Inc\Modules\SiteScanner
 * @since 0.0.1
 */
class Api extends Base {
	use GetInstance;

	/**
	 * Route for initiating site-scanning (starts SaaS scan).
	 */
	protected const INITIATE_SCAN = '/site-scanner/initiate';

	/**
	 * Route for getting scan status.
	 */
	protected const SCAN_STATUS = '/site-scanner/status';

	/**
	 * Route for getting scan logs.
	 */
	protected const GET_LOGS = '/site-scanner/get-logs';

	/**
	 * Route for cancelling a scan.
	 */
	protected const CANCEL_SCAN = '/site-scanner/cancel';

	/**
	 * Route for fetching the daily scan quota.
	 *
	 * @since 0.0.1-beta.2
	 */
	protected const QUOTA = '/site-scanner/quota';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		// Start scan endpoint (triggers SaaS API).
		register_rest_route(
			$this->get_api_namespace(),
			self::INITIATE_SCAN,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'start_scan' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		// Scan progress logs getting endpoint.
		register_rest_route(
			$this->get_api_namespace(),
			self::GET_LOGS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_scan_progress_logs' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		// Scan status endpoint.
		register_rest_route(
			$this->get_api_namespace(),
			self::SCAN_STATUS,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_scan_status' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		// Cancel scan endpoint.
		register_rest_route(
			$this->get_api_namespace(),
			self::CANCEL_SCAN,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'cancel_scan' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		// Quota endpoint - returns the cached daily scan quota; ?refresh=1 forces a SaaS round-trip.
		register_rest_route(
			$this->get_api_namespace(),
			self::QUOTA,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_quota' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'refresh' => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * Get current scan status.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_scan_status( $request ): void {
		$cron   = Cron::get_instance();
		$status = $cron->get_scan_status();

		SendJson::success( [ 'data' => $status ] );
	}

	/**
	 * Get scan progress logs.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_scan_progress_logs( $request ): void {
		$logs = Logger::get_instance()->get_log();
		SendJson::success( [ 'data' => $logs ] );
	}

	/**
	 * Get the daily scan quota - cache-first, with `?refresh=1` busting the cache.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API request object.
	 * @since 0.0.1-beta.2
	 * @return void
	 */
	public function get_quota( $request ): void {
		$saas_client = SaasClient::get_instance();
		$refresh     = (bool) $request->get_param( 'refresh' );

		if ( ! $refresh ) {
			$cached = $saas_client->get_cached_quota();
			if ( ! empty( $cached ) ) {
				// Strip the `_plan` sentinel before returning the quota payload -
				// SaaS-reported plan goes back as a sibling, not nested in quota.
				$cached_plan = $saas_client->get_cached_plan();
				unset( $cached['_plan'] );
				SendJson::success(
					[
						'data' => [
							'quota' => $cached,
							'plan'  => $cached_plan !== '' ? $cached_plan : Utils::get_plan(),
							'fresh' => false,
						],
					]
				);
				return;
			}
		}

		// Refresh requested OR cold start: hit SaaS and surface error_code/message
		// so the frontend can distinguish auth failure from a transient outage.
		$result = $saas_client->get_quota();
		SendJson::success(
			[
				'data' => [
					'quota'      => $result['quota'] ?? [],
					'plan'       => $result['plan'] ?? Utils::get_plan(),
					'fresh'      => ! empty( $result['success'] ),
					'error_code' => $result['error_code'] ?? null,
					'message'    => empty( $result['success'] ) ? ( $result['message'] ?? null ) : null,
				],
			]
		);
	}

	/**
	 * Cancel the current scan.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function cancel_scan( $request ): void {
		$cron      = Cron::get_instance();
		$cancelled = $cron->cancel_scan();

		if ( $cancelled ) {
			SendJson::success(
				[
					'message' => __( 'Scan cancelled successfully.', 'surecookie' ),
				]
			);
		} else {
			SendJson::error(
				[
					'message' => __( 'No active scan to cancel.', 'surecookie' ),
				]
			);
		}
	}

	/**
	 * Generate sitemap cache (cron-based).
	 *
	 * Uses SaaS API for cookie scanning.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST API request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function start_scan( $request ): void {
		try {
			$data = [
				'scan_pages' => $request->get_param( 'pages' ),
			];

			$sanitized_settings = Sanitize::settings( $data );
			Update::option( SURECOOKIE_SETTINGS_OPTION, $sanitized_settings );

			// Check if scan is already in progress.
			$saas_client = SaasClient::get_instance();

			if ( $saas_client->is_scan_in_progress() ) {
				SendJson::error(
					[
						'code'    => 'scan_in_progress',
						'message' => __( 'A scan is already in progress. Please wait for it to complete.', 'surecookie' ),
					]
				);
				return;
			}

			// Cleanup old logs to save new ones.
			Logger::get_instance()->cleanup_logs();

			// Get pages to scan and validate before starting.
			$pages_to_scan = Cron::get_instance()->get_pages_urls_to_scan();

			if ( empty( $pages_to_scan ) ) {
				SendJson::error(
					[
						'code'    => 'no_pages',
						'message' => __( 'No pages selected for scanning.', 'surecookie' ),
					]
				);
				return;
			}

			// Start scan via SaaS API directly to catch errors.
			$saas_client = SaasClient::get_instance();
			$result      = $saas_client->start_scan( $pages_to_scan );

			if ( ! $result['success'] ) {
				// Return error with code and rate limit details from SaaS response.
				SendJson::error(
					[
						'code'      => $result['code'] ?? 'scan_failed',
						'message'   => $result['message'] ?? __( 'Failed to start scan.', 'surecookie' ),
						'limit'     => $result['limit'] ?? null,
						'used'      => $result['used'] ?? null,
						'remaining' => $result['remaining'] ?? 0,
					]
				);
				return;
			}

			SendJson::success(
				[
					'message'       => __( 'Cookie scan has been initiated.', 'surecookie' ),
					'description'   => __( 'The scan is running. You will be notified when it completes.', 'surecookie' ),
					// Surfaced so the UI can tell the user some pages were skipped
					// because the shared daily page budget ran out (auto-trim).
					'pages_scanned' => $result['pages_scanned'] ?? null,
					'pages_dropped' => $result['pages_dropped'] ?? 0,
				]
			);
		} catch ( \Exception $e ) {
			SendJson::error(
				[
					'message' => sprintf(
							/* translators: %s: Error message */
						__( 'Failed to start site scanner: %s', 'surecookie' ),
						$e->getMessage(),
					),
				]
			);
		}
	}

}
