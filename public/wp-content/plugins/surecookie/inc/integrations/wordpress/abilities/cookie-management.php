<?php
/**
 * Cookie Management Ability
 *
 * Multi-action ability for custom cookie CRUD and scanned cookie operations.
 * Delegates business logic to the shared CookieService.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Services\CookieService;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieManagement
 *
 * Provides cookie listing, custom cookie CRUD, and scanned cookie recategorization.
 *
 * @since 0.0.1-alpha.1
 */
class CookieManagement extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : [];

		try {
			$action  = $input['action'] ?? '';
			$service = new CookieService();

			switch ( $action ) {
				case 'list_scanned':
					return $service->get_scanned_cookies();

				case 'list_custom':
					return $service->get_custom_cookies();

				case 'create_custom':
					return $service->create_custom_cookie( $input );

				case 'update_custom':
					$cookie_id = sanitize_text_field( $input['cookie_id'] ?? '' );
					return $service->update_custom_cookie( $cookie_id, $input );

				case 'delete_custom':
					$cookie_id = sanitize_text_field( $input['cookie_id'] ?? '' );
					return $service->delete_custom_cookie( $cookie_id );

				case 'recategorize_scanned':
					$cookie_name      = sanitize_text_field( $input['cookie_name'] ?? '' );
					$current_category = sanitize_text_field( $input['current_category'] ?? '' );
					$new_category     = sanitize_text_field( $input['new_category'] ?? '' );
					$domain           = sanitize_text_field( $input['domain'] ?? '' );
					return $service->update_scanned_cookie_category( $cookie_name, $current_category, $new_category, $domain );

				default:
					return [
						'success' => false,
						'message' => __( 'Invalid action specified.', 'surecookie' ),
					];
			}
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'message' => __( 'An unexpected error occurred while managing cookies.', 'surecookie' ),
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/cookie-management';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Cookie Management', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Manage cookies detected and defined by SureCookie. Actions: "list_scanned" returns all cookies found by the site scanner with their name, category, provider, and description. "list_custom" returns manually defined cookies. "create_custom" adds a new custom cookie definition (requires name and category). "update_custom" modifies an existing custom cookie by cookie_id. "delete_custom" permanently removes a custom cookie definition by cookie_id — this cannot be undone. "recategorize_scanned" moves a scanned cookie from one category to another. Use surecookie/cookie-categories with action "list" to discover valid category IDs before creating or recategorizing cookies.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 3.0,
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
			'instructions'    => 'DESTRUCTIVE — the "delete_custom" action permanently removes a custom cookie definition and cannot be undone. Always ask the user to confirm before deleting and show them the cookie name and ID. For "create_custom", call "list_custom" first to avoid creating duplicate cookie definitions. For "recategorize_scanned", call "list_scanned" first to verify the cookie exists in the specified current category. The "list_scanned" and "list_custom" actions are safe to call at any time.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'           => [
					'type'        => 'string',
					'enum'        => [
						'list_scanned',
						'list_custom',
						'create_custom',
						'update_custom',
						'delete_custom',
						'recategorize_scanned',
					],
					'description' => __( 'The cookie management action to perform.', 'surecookie' ),
				],
				'cookie_id'        => [
					'type'        => 'string',
					'description' => __( 'Cookie ID for update/delete operations.', 'surecookie' ),
				],
				'name'             => [
					'type'        => 'string',
					'description' => __( 'Cookie name (required for create).', 'surecookie' ),
				],
				'category'         => [
					'type'        => 'string',
					'description' => __( 'Cookie category ID (required for create).', 'surecookie' ),
				],
				'description'      => [
					'type'        => 'string',
					'description' => __( 'Cookie description.', 'surecookie' ),
				],
				'duration'         => [
					'type'        => 'string',
					'description' => __( 'Cookie duration in days.', 'surecookie' ),
				],
				'provider'         => [
					'type'        => 'string',
					'description' => __( 'Cookie provider.', 'surecookie' ),
				],
				'purpose'          => [
					'type'        => 'string',
					'description' => __( 'Cookie purpose.', 'surecookie' ),
				],
				'domain'           => [
					'type'        => 'string',
					'description' => __( 'Cookie domain. For recategorize_scanned, supply it when two scanned cookies share a name so the right one is moved.', 'surecookie' ),
				],
				'cookie_name'      => [
					'type'        => 'string',
					'description' => __( 'Scanned cookie name (for recategorize_scanned action).', 'surecookie' ),
				],
				'current_category' => [
					'type'        => 'string',
					'description' => __( 'Current category ID (for recategorize_scanned action).', 'surecookie' ),
				],
				'new_category'     => [
					'type'        => 'string',
					'description' => __( 'Target category ID (for recategorize_scanned action).', 'surecookie' ),
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
					'description' => __( 'Result data varying by action.', 'surecookie' ),
				],
			],
		];
	}
}
