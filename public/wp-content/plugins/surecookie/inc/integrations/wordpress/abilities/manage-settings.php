<?php
/**
 * Manage Settings Ability
 *
 * Single getter-setter ability for all SureCookie plugin settings.
 * Dynamically builds its JSON Schema from Options::get_all_configurations(),
 * so new settings are automatically available without modifying this file.
 *
 * @link       https://developer.wordpress.org/apis/abilities-api/
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Integrations/Wordpress/Abilities
 * @since      0.0.1-alpha.1
 */

namespace SureCookie\Inc\Integrations\Wordpress\Abilities;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Wordpress\Base;
use SureCookie\Inc\Utils\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ManageSettings
 *
 * Provides a unified get/set interface for all plugin settings.
 *
 * @since 0.0.1-alpha.1
 */
class ManageSettings extends Base {
	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input The validated input data.
	 */
	public function execute( $input = null ) {
		try {
			$action = $input['action'] ?? 'get';

			if ( $action === 'set' ) {
				return $this->handle_set( $input );
			}

			return $this->handle_get( $input );
		} catch ( \Throwable $e ) {
			return [
				'success'  => false,
				'message'  => __( 'An unexpected error occurred while managing settings.', 'surecookie' ),
				'settings' => [],
			];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_name(): string {
		return 'surecookie/manage-settings';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_label(): string {
		return __( 'Manage Settings', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_description(): string {
		return __( 'Get or update SureCookie plugin settings. Use action "get" to retrieve current settings (optionally filter by specific keys), or action "set" to update one or more settings. Available settings include banner appearance, layout and position, consent logging toggle, default compliance law (GDPR/CCPA), cookie policy page, Google Consent Mode, and more. Settings are stored in the WordPress options table and take effect immediately on the live site. When using "set", only the provided keys are updated — omitted keys remain unchanged.', 'surecookie' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_annotations(): array {
		return [
			'priority'        => 2.0,
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'instructions'    => 'For the "get" action, safe to call at any time to read current configuration. For the "set" action, always call with action "get" first to show the user current values, then confirm the proposed changes before applying. Settings take effect immediately on the live cookie consent banner visible to all site visitors.',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'   => [
					'type'        => 'string',
					'enum'        => [ 'get', 'set' ],
					'description' => __( 'The action to perform: "get" to retrieve settings, "set" to update settings.', 'surecookie' ),
				],
				'keys'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional. For "get" action, specify setting keys to retrieve. If empty, returns all settings.', 'surecookie' ),
				],
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'For "set" action, an object of setting key-value pairs to update.', 'surecookie' ),
					'properties'           => self::build_settings_properties(),
					'additionalProperties' => false,
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
				'success'  => [
					'type'        => 'boolean',
					'description' => __( 'Whether the operation succeeded.', 'surecookie' ),
				],
				'message'  => [
					'type'        => 'string',
					'description' => __( 'A human-readable message describing the result.', 'surecookie' ),
				],
				'settings' => [
					'type'        => 'object',
					'description' => __( 'The current settings after the operation.', 'surecookie' ),
				],
			],
		];
	}

	/**
	 * Handle the "get" action.
	 *
	 * Returns all settings or a filtered subset when specific keys are requested.
	 *
	 * @param array<string, mixed> $input Input data.
	 * @return array{success: bool, message: string, settings: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_get( array $input ): array {
		$all_settings = Settings::get();
		$keys         = $input['keys'] ?? [];

		if ( ! empty( $keys ) && is_array( $keys ) ) {
			$filtered = [];
			foreach ( $keys as $key ) {
				$key = sanitize_text_field( $key );
				if ( isset( $all_settings[ $key ] ) ) {
					$filtered[ $key ] = $all_settings[ $key ];
				}
			}
			$all_settings = $filtered;
		}

		return [
			'success'  => true,
			'message'  => __( 'Settings retrieved successfully.', 'surecookie' ),
			'settings' => $all_settings,
		];
	}

	/**
	 * Handle the "set" action.
	 *
	 * Validates keys against the known configuration schema,
	 * rejects unknown keys, and delegates sanitization to
	 * Settings::update() which uses get_cleaned_value().
	 *
	 * @param array<string, mixed> $input Input data.
	 * @return array{success: bool, message: string, settings: array<string, mixed>}
	 * @since 0.0.1-alpha.1
	 */
	private function handle_set( array $input ): array {
		$settings_to_update = $input['settings'] ?? [];

		if ( empty( $settings_to_update ) || ! is_array( $settings_to_update ) ) {
			return [
				'success'  => false,
				'message'  => __( 'No settings provided to update.', 'surecookie' ),
				'settings' => [],
			];
		}

		// Validate keys against known configuration.
		$configurations = Options::get_all_configurations();
		$valid_keys     = array_keys( $configurations );
		$updated_keys   = [];
		$denied_keys    = [];

		foreach ( $settings_to_update as $key => $value ) {
			$key = sanitize_text_field( $key );

			if ( ! in_array( $key, $valid_keys, true ) ) {
				continue;
			}

			// Mirror the REST endpoint's gate (inc/api/settings.php): custom CSS
			// is inlined on every frontend page, so it requires unfiltered_html.
			if ( $key === 'custom_css' && ! current_user_can( 'unfiltered_html' ) ) {
				$denied_keys[] = $key;
				continue;
			}

			Settings::update( $key, $value );
			$updated_keys[] = $key;
		}

		if ( empty( $updated_keys ) ) {
			return [
				'success'  => false,
				'message'  => empty( $denied_keys )
					? __( 'No valid settings keys provided.', 'surecookie' )
					: sprintf(
						/* translators: %s: comma-separated setting keys */
						__( 'Permission denied for: %s. Updating custom_css requires the unfiltered_html capability.', 'surecookie' ),
						implode( ', ', $denied_keys )
					),
				'settings' => [],
			];
		}

		$message = sprintf(
			/* translators: %d: number of settings updated */
			__( '%d setting(s) updated successfully.', 'surecookie' ),
			count( $updated_keys )
		);

		if ( ! empty( $denied_keys ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated setting keys */
				__( 'Skipped (permission denied): %s.', 'surecookie' ),
				implode( ', ', $denied_keys )
			);
		}

		return [
			'success'  => true,
			'message'  => $message,
			'settings' => Settings::get(),
		];
	}

	/**
	 * Build JSON Schema properties from Options configurations.
	 *
	 * Dynamically generates the schema so new settings added to
	 * Options::get_all_configurations() are automatically available
	 * without modifying this file.
	 *
	 * @return array<string, array<string, string|array<int, string>>>
	 * @since 0.0.1-alpha.1
	 */
	private static function build_settings_properties(): array {
		$configurations = Options::get_all_configurations();
		$properties     = [];

		$type_map = [
			'bool'   => 'boolean',
			'int'    => 'integer',
			'string' => 'string',
			// PHP 'array' settings hold both lists and maps, so accept either.
			'array'  => [ 'array', 'object' ],
		];

		foreach ( $configurations as $key => $config ) {
			$php_type    = $config['type'] ?? 'string';
			$schema_type = $type_map[ $php_type ] ?? 'string';

			$properties[ $key ] = [
				'type'        => $schema_type,
				'description' => sprintf(
					/* translators: %s: setting key name */
					__( 'Plugin setting: %s', 'surecookie' ),
					$key
				),
			];
		}

		return $properties;
	}
}
