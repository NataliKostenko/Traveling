<?php
/**
 * Settings class
 *
 * Handles installed products related REST API endpoints for the SureCookie plugin.
 *
 * @package SureCookie\Inc\API
 */

namespace SureCookie\Inc\API;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\SendJson;
use SureCookie\Inc\Functions\Settings as FunctionsSettings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Settings
 *
 * Handles this related REST API endpoints.
 */
class Settings extends Base {
	use GetInstance;

	/**
	 * Route Get Admin Settings
	 */
	protected const ADMIN_SETTINGS = '/admin/settings';

	/**
	 * Route Get Frontend Settings
	 */
	protected const FRONTEND_SETTINGS = '/frontend/settings';

	/**
	 * Register API routes.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->get_api_namespace(),
			self::ADMIN_SETTINGS,
			[
				'methods'             => WP_REST_Server::READABLE, // Admin -- GET method.
				'callback'            => [ $this, 'get_admin_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::ADMIN_SETTINGS,
			[
				'methods'             => WP_REST_Server::CREATABLE, // Admin -- POST method.
				'callback'            => [ $this, 'update_admin_settings' ],
				'permission_callback' => [ $this, 'validate_permission' ],
				'args'                => [
					'data' => [
						'required' => true,
						'type'     => 'object',
					],
				],
			]
		);

		register_rest_route(
			$this->get_api_namespace(),
			self::FRONTEND_SETTINGS,
			[
				'methods'             => WP_REST_Server::READABLE, // Frontend -- GET method.
				'callback'            => [ $this, 'get_frontend_settings' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Get admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_admin_settings( $request ): void {
		$data                           = FunctionsSettings::get();
		$data['surecookie_usage_optin'] = Get::option( 'surecookie_usage_optin' ) === 'yes' ? true : false;

		$data        = apply_filters( 'surecookie_get_admin_settings_data', $data );
		$decode_data = Helper::decode_html_entities_recursive( $data ) ?? $data;

		// Re-sanitize after entity decoding, else the decode undoes the sanitizer:
		// &#64;import → @import for CSS, &lt;img onerror=…&gt; → live markup for rich text.
		if ( isset( $decode_data['custom_css'] ) && is_string( $decode_data['custom_css'] ) ) {
			$decode_data['custom_css'] = Sanitize::stylesheet( $decode_data['custom_css'] );
		}

		$decode_data = Sanitize::rich_text_keys_after_decode( $decode_data );

		SendJson::success( [ 'data' => $decode_data ] );
	}

	/**
	 * Update admin settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function update_admin_settings( $request ): void {
		$data = $request->get_param( 'data' );
		if ( empty( $data ) ) {
			SendJson::error( [ 'message' => __( 'No data found', 'surecookie' ) ] );
		}

		$previous_top_level = FunctionsSettings::get( 'top_level_menu_enabled' );

		// Defense-in-depth: custom CSS is inlined to every frontend page, so treat it like WP Core treats Customizer Additional CSS - require unfiltered_html. On multisite, sub-admins without the cap keep the previously saved value; the sanitizer is the fallback line.
		if ( is_array( $data ) && array_key_exists( 'custom_css', $data ) && ! current_user_can( 'unfiltered_html' ) ) {
			unset( $data['custom_css'] );
		}

		// Before processing plugin settings data compatibility.
		do_action( 'surecookie_admin_settings_before_processing', $data );

		$data = apply_filters( 'surecookie_update_admin_settings_data', $data );

		$sanitized_settings = Sanitize::settings( $data );
		$sanitized_settings = $this->update_usage_tracking( $sanitized_settings );

		Update::option( SURECOOKIE_SETTINGS_OPTION, $sanitized_settings );

		// After processing plugin settings data compatibility.
		do_action( 'surecookie_admin_settings_after_processing', $sanitized_settings );

		$response = [
			'message'      => __( 'Settings updated', 'surecookie' ),
			'redirect_url' => Get::menu_redirect_url( $sanitized_settings, $previous_top_level ),
		];

		if ( empty( $response['redirect_url'] ) ) {
			unset( $response['redirect_url'] );
		}

		SendJson::success( $response );
	}

	/**
	 * Update usage tracking setting based on opt-in status.
	 *
	 * @param array<string, mixed> $data The current settings dataset.
	 * @since 0.0.1-beta.1
	 * @return array<string, mixed> The modified settings dataset.
	 */
	public function update_usage_tracking( $data ): array {
		if ( ! isset( $data['surecookie_usage_optin'] ) ) {
			return $data;
		}

		$enable_contribution = $data['surecookie_usage_optin'] ? 'yes' : 'no';
		update_option( 'surecookie_usage_optin', $enable_contribution );
		unset( $data['surecookie_usage_optin'] );

		return $data;
	}

	/**
	 * Get frontend settings
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @since 0.0.1
	 * @return void
	 */
	public function get_frontend_settings( $request ): void {
		$public_settings = FunctionsSettings::get_public_settings_dataset();

		$frontend_settings = apply_filters( 'surecookie_get_frontend_settings_data', $public_settings );
		$decode_data       = Helper::decode_html_entities_recursive( $frontend_settings ) ?? $frontend_settings;

		// Public route - the decode above must not hand an anonymous caller
		// markup that kses had already neutralized.
		$decode_data = Sanitize::rich_text_keys_after_decode( $decode_data );

		SendJson::success( [ 'data' => $decode_data ] );
	}
}
