<?php
/**
 * Options
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Utils;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Validate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Options - this class is for all plugin configuration settings.
 *
 * @since 0.0.1
 */
class Options {
	/**
	 * Get SureCookie's settings dataset with including type, default.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_all_configurations() {
		return apply_filters(
			'surecookie_plugin_settings_dataset',
			[
				'banner_enabled'                => [
					'type'    => 'bool',
					'default' => true,
				],
				'preview_enabled'               => [
					'type'    => 'bool',
					'default' => true,
				],
				'message_heading'               => [
					'type'    => 'string',
					'default' => '',
				],
				'message_description'           => [
					'type'    => 'rich_text',
					'default' => __( 'We use cookies to improve your experience and understand how you use our site. You can review your choices at any time.', 'surecookie' ),
				],

				'banner_position'               => [
					'type'    => 'string',
					'default' => 'bottom',
				],

				'banner_logo'                   => [
					'type'    => 'string',
					'default' => '',
				],
				'accept_btn_text'               => [
					'type'    => 'string',
					'default' => __( 'Only Essential', 'surecookie' ),
				],
				'accept_all_enabled'            => [
					'type'    => 'bool',
					'default' => true,
				],

				'accept_all_btn_text'           => [
					'type'    => 'string',
					'default' => __( 'Accept All', 'surecookie' ),
				],
				'decline_btn_text'              => [
					'type'    => 'string',
					'default' => __( 'Decline', 'surecookie' ),
				],
				'settings_btn_text'             => [
					'type'    => 'string',
					'default' => __( 'Cookie Settings', 'surecookie' ),
				],

				'button_order'                  => [
					'type'    => 'string',
					'default' => 'accept_all,accept,preferences,decline',
				],
				'compliance_law'                => [
					'type'    => 'array',
					'default' => [
						'id'   => '1',
						'name' => 'GDPR',
					],
				],
				'notice_type'                   => [
					'type'    => 'string',
					'default' => 'banner',
				],
				'notice_position'               => [
					'type'    => 'string',
					'default' => 'bottom',
				],

				'show_preview'                  => [
					'type'    => 'bool',
					'default' => true,
				],
				'cookie_categories'             => [
					'type'    => 'array',
					'default' => Get::default_cookie_categories(),
				],
				'custom_cookies'                => [
					'type'    => 'array',
					'default' => [],
				],
				'consent_logging_enabled'       => [
					'type'    => 'bool',
					'default' => true,
				],
				'consent_log_retention'         => [
					'type'    => 'string',
					'default' => '365_days',
				],
				'consent_duration_days'         => [
					'type'    => 'int',
					'default' => 365,
				],
				// Unix timestamp of the last admin "Renew consent" action. Consents
				// recorded before this are treated as stale so the banner reappears.
				'consent_renewed_at'            => [
					'type'    => 'int',
					'default' => 0,
				],
				'color_palette'                 => [
					'type'    => 'string',
					'default' => 'green-lime',
				],
				'banner_width'                  => [
					'type'    => 'int',
					'default' => 650,
				],
				'preferences_btn_text'          => [
					'type'    => 'string',
					'default' => __( 'Preferences', 'surecookie' ),
				],
				'preferences_modal_heading'     => [
					'type'    => 'string',
					'default' => __( 'Privacy Preference', 'surecookie' ),
				],
				'preferences_modal_description' => [
					'type'    => 'rich_text',
					'default' => __( 'We use cookies and similar technologies to help personalize content, tailor and measure ads, and provide a better experience.', 'surecookie' ),
				],
				'scan_pages'                    => [
					'type'    => 'array',
					'default' => [],
				],
				'blocking_enabled'              => [
					'type'    => 'bool',
					'default' => true,
				],
				'top_level_menu_enabled'        => [
					'type'    => 'bool',
					'default' => true,
				],
				'banner_animation'              => [
					'type'    => 'string',
					'default' => 'fade',
				],
				'banner_overlay_enabled'        => [
					'type'    => 'bool',
					'default' => false,
				],
				'reconsent_button_label'        => [
					'type'    => 'string',
					'default' => __( 'Cookie Preferences', 'surecookie' ),
				],
				'reconsent_menu_id'             => [
					'type'    => 'string',
					'default' => '',
				],
				'cookie_policy_page_id'         => [
					'type'    => 'int',
					'default' => 0,
				],
				'custom_css'                    => [
					'type'    => 'stylesheet',
					'default' => '',
				],
				'delete_data_on_uninstall'      => [
					'type'    => 'bool',
					'default' => false,
				],
				'enable_mcp'                    => [
					'type'    => 'bool',
					'default' => false,
				],
				'excluded_scan_resources'       => [
					'type'    => 'array',
					'default' => [],
				],
				'resource_category_overrides'   => [
					'type'    => 'array',
					'default' => [],
				],
				'custom_blocked_scripts'        => [
					'type'    => 'array',
					'default' => [],
				],
				'consent_model'                 => [
					'type'    => 'string',
					'default' => 'opt-in',
				],
				'total_logs'                    => [
					'type'    => 'int',
					'default' => 0,
				],

				// Automatic Scanning (Free base). Pro adds the Weekly frequency + email/apply/guard keys.
				'auto_scan_enabled'             => [
					'type'    => 'bool',
					'default' => false,
				],
				'auto_scan_frequency'           => [
					'type'    => 'string',
					'default' => 'monthly',
				],
				'auto_scan_scope'               => [
					'type'    => 'string',
					'default' => 'same_as_manual',
				],
				'auto_scan_pages'               => [
					'type'    => 'array',
					'default' => [],
				],
			]
		);
	}

	/**
	 * Get frontend related settings. Skipping backend related settings & non-relevant configurations.
	 *
	 * @return array<string>
	 * @since 0.0.1
	 */
	public static function get_frontend_options() {
		return apply_filters(
			'surecookie_frontend_setting_keys',
			[
				'message_heading',
				'message_description',
				'notice_type',
				'notice_position',
				'banner_width',
				'banner_enabled',
				'banner_logo',
				'accept_all_enabled',
				'accept_btn_text',
				'accept_all_btn_text',
				'decline_btn_text',
				'button_order',
				'preferences_btn_text',
				'preferences_modal_heading',
				'preferences_modal_description',
				'cookie_categories',
				'custom_cookies',
				'consent_logging_enabled',
				'consent_duration_days',
				'consent_renewed_at',
				'banner_animation',
				'banner_overlay_enabled',
				'reconsent_button_label',
				'consent_model',
			]
		);
	}

	/**
	 * Get option type.
	 *
	 * @param string $option Option.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public static function get_option_type( $option ) {
		$settings = self::get_all_configurations();
		return isset( $settings[ $option ]['type'] ) && Validate::not_empty( $settings[ $option ]['type'] ) ? $settings[ $option ]['type'] : 'string';
	}

	/**
	 * Get the keys of every option storing rich text (HTML). Derived, not
	 * hardcoded, so a new rich_text field is covered automatically.
	 *
	 * @return array<string>
	 * @since 1.3.1
	 */
	public static function get_rich_text_options() {
		$configs = array_filter(
			self::get_all_configurations(),
			static fn( $config ) => ( $config['type'] ?? '' ) === 'rich_text'
		);

		return array_map( 'strval', array_keys( $configs ) );
	}
}
