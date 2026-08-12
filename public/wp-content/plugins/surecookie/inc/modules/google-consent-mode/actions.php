<?php
/**
 * Google Consent Mode Module Actions Initialization.
 *
 * @package SureCookie\Inc\Modules\GoogleConsentMode
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Actions class.
 *
 * @since 0.0.0-alpha.1
 */
class Actions {
	use GetInstance;

	/**
	 * GCM setting keys exposed to the frontend settings payload.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const GCM_SETTING_KEYS = [
		'gcm_enabled',
		'gcm_wait_for_update',
		'gcm_default_consent',
		'gcm_region_defaults',
	];

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Add Google Consent Mode settings to plugin settings dataset.
	 *
	 * @param array<string, mixed> $settings The current settings dataset.
	 * @return array<string, mixed>
	 * @since 0.0.0-alpha.1
	 */
	public function add_gcm_settings_to_dataset( $settings ) {
		$gcm_settings = [
			'gcm_enabled'         => [
				'type'    => 'bool',
				'default' => false,
			],
			'gcm_wait_for_update' => [
				'type'    => 'int',
				'default' => 500,
			],
			'gcm_default_consent' => [
				'type'    => 'array',
				'default' => [
					'functional' => false,
					'analytics'  => false,
					'marketing'  => false,
				],
				'group'   => 'gcm',
			],
			'gcm_region_defaults' => [
				'type'    => 'array',
				'default' => [
					[
						'region'     => [ 'EU' ],
						'analytics'  => false,
						'marketing'  => false,
						'functional' => false,
					],
				],
			],
		];

		return array_merge( $settings, $gcm_settings );
	}

	/**
	 * Add Google Consent Mode settings to frontend options.
	 *
	 * @param array<string> $frontend_keys The current frontend setting keys.
	 * @return array<string>
	 * @since 0.0.0-alpha.1
	 */
	public function add_gcm_frontend_options( $frontend_keys ) {
		return array_merge( $frontend_keys, self::GCM_SETTING_KEYS );
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	private function init_hooks(): void {
		// Add Google Consent Mode settings to plugin configuration dataset.
		add_filter( 'surecookie_plugin_settings_dataset', [ $this, 'add_gcm_settings_to_dataset' ] );

		// Add Google Consent Mode settings to frontend options.
		add_filter( 'surecookie_frontend_setting_keys', [ $this, 'add_gcm_frontend_options' ] );
	}
}
