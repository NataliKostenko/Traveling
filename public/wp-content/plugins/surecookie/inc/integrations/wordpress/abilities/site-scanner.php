<?php
/**
 * Site Scanner Ability
 *
 * Multi-action ability for managing the SureCookie cookie scanner.
 * Supports checking scan status, initiating scans with optional page URLs,
 * retrieving progress logs, and cancelling active scans.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Modules\SiteScanner\Cron;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SiteScanner
 *
 * Provides scan management capabilities.
 *
 * @since 0.0.1-alpha.1
 */
class SiteScanner extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action = $input['action'] ?? 'status';

			switch ( $action ) {
				case 'status':
					return $this->handle_status();

				case 'start':
					$pages = $input['pages'] ?? [];
					return $this->handle_start( $pages );

				case 'logs':
					return $this->handle_logs();

				case 'cancel':
					return $this->handle_cancel();

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid scanner action.', 'surecookie' ),
						'data'    => [],
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing the site scanner.', 'surecookie' ),
				'data'    => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/site-scanner';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Site Scanner', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Manage the SureCookie site cookie scanner. Actions: "status" returns the current scan state (idle, in_progress, completed) with progress data — safe to call at any time. "start" initiates a new cookie scan that contacts an external scanning service to detect cookies on site pages; optionally specify page URLs to scan, otherwise all site pages are scanned. Only one scan can run at a time. "logs" retrieves detailed scan progress log entries — safe to call at any time. "cancel" stops an active scan; any progress from the current scan is lost. Scans typically take a few minutes depending on the number of pages.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 2.0,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
			'instructions'    => 'The "status" and "logs" actions are safe to call at any time. The "start" action initiates a cookie scan that contacts an external scanning service — always confirm with the user before starting a scan. If a scan is already in progress, the start action will fail gracefully. The "cancel" action stops an active scan and any in-progress scan data may be lost — confirm with the user before cancelling.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action' => [
					'type'        => 'string',
					'enum'        => [ 'status', 'start', 'logs', 'cancel' ],
					'description' => __( 'The scanner action: "status" to check progress, "start" to initiate a scan, "logs" to retrieve progress logs, "cancel" to stop an active scan.', 'surecookie' ),
				],
				'pages'  => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional. For "start" action, specify page URLs to scan. If empty, scans all site pages.', 'surecookie' ),
				],
			],
			'required'   => [ 'action' ],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'data'    => [
					'type'        => 'object',
					'description' => __( 'Scanner result data.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Handle the "status" action.
	 *
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_status(): array {
		$cron = Cron::get_instance();

		return [
			'success' => true,
			'message' => __( 'Scan status retrieved.', 'surecookie' ),
			'data'    => $cron->get_scan_status(),
		];
	}

	/**
	 * Handle the "start" action.
	 *
	 * @param array<int, string> $pages Optional array of page URLs to scan.
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_start( array $pages = [] ): array {
		$saas_client = SaasClient::get_instance();

		if ( $saas_client->is_scan_in_progress() ) {
			return [
				'success' => false,
				'message' => __( 'A scan is already in progress.', 'surecookie' ),
				'data'    => [],
			];
		}

		// Sanitize page URLs.
		$sanitized_pages = array_map( 'esc_url_raw', array_filter( $pages ) );

		// Store scan pages if provided.
		if ( ! empty( $sanitized_pages ) ) {
			Settings::update( 'scan_pages', $sanitized_pages );
		}

		Logger::get_instance()->cleanup_logs();

		$cron   = Cron::get_instance();
		$result = $cron->start_site_scanning();

		// Surface the real outcome: on local/dev sites, when the plan limit is
		// hit, or on a SaaS/network failure, start_scan() blocks the scan and
		// returns success=false — don't report a scan that never started.
		if ( empty( $result['success'] ) ) {
			$data = [];
			foreach ( [ 'code', 'limit', 'remaining' ] as $key ) {
				if ( isset( $result[ $key ] ) ) {
					$data[ $key ] = $result[ $key ];
				}
			}

			return [
				'success' => false,
				'message' => $result['message'] ?? __( 'Failed to start cookie scan.', 'surecookie' ),
				'data'    => $data,
			];
		}

		return [
			'success' => true,
			'message' => __( 'Cookie scan has been initiated.', 'surecookie' ),
			'data'    => [],
		];
	}

	/**
	 * Handle the "logs" action.
	 *
	 * @return array{success: bool, message: string, data: mixed}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_logs(): array {
		return [
			'success' => true,
			'message' => __( 'Scan logs retrieved.', 'surecookie' ),
			'data'    => Logger::get_instance()->get_log(),
		];
	}

	/**
	 * Handle the "cancel" action.
	 *
	 * @return array{success: bool, message: string, data: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_cancel(): array {
		$cron      = Cron::get_instance();
		$cancelled = $cron->cancel_scan();

		return [
			'success' => $cancelled,
			'message' => $cancelled
				? __( 'Scan cancelled successfully.', 'surecookie' )
				: __( 'No active scan to cancel.', 'surecookie' ),
			'data'    => [],
		];
	}
}
