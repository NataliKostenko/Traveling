<?php
/**
 * Consent Logs Ability
 *
 * Read-only ability for querying consent logs with pagination and filtering.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Integrations\Wordpress\Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ConsentLogs
 *
 * Provides read-only access to consent log records.
 *
 * @since 0.0.1-alpha.1
 */
class ConsentLogs extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$limit   = min( 100, max( 1, absint( $input['limit'] ?? 10 ) ) );
			$page    = max( 1, absint( $input['page'] ?? 1 ) );
			$search  = sanitize_text_field( $input['search'] ?? '' );
			$action  = sanitize_text_field( $input['action'] ?? '' );
			$country = sanitize_text_field( $input['country'] ?? '' );
			$offset  = ( $page - 1 ) * $limit;

			// wpdb returns every column as a string and the rows carry extra
			// columns, so normalize to the exact shape the output schema
			// declares — the MCP adapter validates against it.
			$logs = array_map(
				static function ( array $row ): array {
					return [
						'id'          => (int) ( $row['id'] ?? 0 ),
						'ip_address'  => (string) ( $row['ip_address'] ?? '' ),
						'session_id'  => (string) ( $row['session_id'] ?? '' ),
						'preferences' => (string) ( $row['preferences'] ?? '' ),
						'action'      => (string) ( $row['action'] ?? '' ),
						'timestamp'   => (string) ( $row['timestamp'] ?? '' ),
						'country'     => (string) ( $row['country'] ?? '' ),
					];
				},
				ConsentLog::get_filtered( $search, $action, $country, $limit, $offset )
			);

			$counts = ConsentLog::count_all_actions( $search, $country, $action );

			return [
				'success' => true,
				'message' => __( 'Consent logs retrieved successfully.', 'surecookie' ),
				'logs'    => $logs,
				'count'   => [
					'total'          => $counts['total'] ?? 0,
					'total_accepted' => $counts['accepted'] ?? 0,
					'total_declined' => $counts['declined'] ?? 0,
					'total_partial'  => $counts['partially_accepted'] ?? 0,
				],
			];
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while retrieving consent logs.', 'surecookie' ),
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/consent-logs';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Consent Logs', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Query and retrieve consent logs recorded by SureCookie. Supports pagination (max 100 per page), filtering by consent action type (accepted, declined, partially_accepted), filtering by country name, and search by IP address or session ID. Returns log entries with ID, IP address, session ID, consent preferences, action type, timestamp, and country. Also returns aggregate counts of total, accepted, declined, and partial consent actions. Results are ordered by most recent first. Note: IP addresses are personal data under GDPR — handle with care.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 1.0,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'instructions'    => 'Safe to call at any time. Read-only query of consent log records stored in the database. Use pagination parameters to avoid large result sets. IP addresses in results are personal data — do not expose them unnecessarily to the user unless specifically requested.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'page'    => [
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'surecookie' ),
				],
				'limit'   => [
					'type'        => 'integer',
					'description' => __( 'Number of logs per page (1-100). Default: 10.', 'surecookie' ),
				],
				'search'  => [
					'type'        => 'string',
					'description' => __( 'Search by IP address or session ID.', 'surecookie' ),
				],
				'action'  => [
					'type'        => 'string',
					'enum'        => [ 'accepted', 'declined', 'partially_accepted' ],
					'description' => __( 'Filter by consent action type.', 'surecookie' ),
				],
				'country' => [
					'type'        => 'string',
					'description' => __( 'Filter by country name.', 'surecookie' ),
				],
			],
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
					'description' => __( 'Whether the query succeeded.', 'surecookie' ),
				],
				'message' => [
					'type'        => 'string',
					'description' => __( 'Result message.', 'surecookie' ),
				],
				'logs'    => [
					'type'        => 'array',
					'description' => __( 'Array of consent log entries.', 'surecookie' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'integer' ],
							'ip_address'  => [ 'type' => 'string' ],
							'session_id'  => [ 'type' => 'string' ],
							'preferences' => [ 'type' => 'string' ],
							'action'      => [ 'type' => 'string' ],
							'timestamp'   => [ 'type' => 'string' ],
							'country'     => [ 'type' => 'string' ],
						],
					],
				],
				'count'   => [
					'type'       => 'object',
					'properties' => [
						'total'          => [ 'type' => 'integer' ],
						'total_accepted' => [ 'type' => 'integer' ],
						'total_declined' => [ 'type' => 'integer' ],
						'total_partial'  => [ 'type' => 'integer' ],
					],
				],
			],
		];
	}
}
