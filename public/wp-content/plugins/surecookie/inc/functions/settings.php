<?php
/**
 * Settings
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

use SureCookie\Inc\Utils\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings - this class will handle all setting related values.
 *
 * @since 0.0.1
 */
class Settings {
	/**
	 * Default values.
	 *
	 * @var array<string, mixed>
	 */
	private static $defaults = [];

	/**
	 * Settings dataset - with defaults + DB merged preference.
	 *
	 * @var array<string, mixed>
	 */
	private static $settings_dataset = [];

	/**
	 * Get plugin default settings.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_settings_defaults() {
		if ( Validate::array( self::$defaults ) && Validate::not_empty( self::$defaults ) ) {
			return apply_filters( 'surecookie_settings_defaults', self::$defaults );
		}

		$settings_dataset = Options::get_all_configurations();

		$default_settings = [];

		foreach ( $settings_dataset as $key => $value ) {
			$default_settings[ $key ] = $value['default'];
		}

		self::$defaults = $default_settings;

		return apply_filters( 'surecookie_settings_defaults', self::$defaults );
	}

	/**
	 * Get settings dataset - with defaults + DB merged preference.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_settings_dataset() {
		if ( Validate::array( self::$settings_dataset ) && Validate::not_empty( self::$settings_dataset ) ) {
			return apply_filters( 'surecookie_settings_dataset', self::$settings_dataset );
		}

		self::$settings_dataset = wp_parse_args( Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' ), self::get_settings_defaults() );
		return apply_filters( 'surecookie_settings_dataset', self::$settings_dataset );
	}

	/**
	 * Get public settings dataset - Retrieve only frontend oriented settings.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_public_settings_dataset() {
		$settings = self::get();

		$public_settings = [];
		$public_keys     = Options::get_frontend_options();

		foreach ( $public_keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$public_settings[ $key ] = $settings[ $key ];
			} else {
				$public_settings[ $key ] = '';
			}
		}

		return apply_filters( 'surecookie_public_settings', $public_settings );
	}

	/**
	 * Get settings as per configuration wise.
	 *
	 * @param string $key Key to get the value.
	 * @since 0.0.1
	 * @return mixed
	 */
	public static function get( $key = '' ) {
		$default_values = self::get_settings_defaults();

		if ( ! Validate::array( $default_values ) ) {
			return [];
		}

		$setting_db = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );
		$response   = Validate::array( $setting_db ) ? array_merge( $default_values, $setting_db ) : $default_values;

		return $key === '' ? $response : ( $response[ $key ] ?? null );
	}

	/**
	 * Update settings.
	 *
	 * @param  string $key      The option key.
	 * @param  mixed  $value    Option value to update.
	 * @return bool             Return the option value
	 *
	 * @since 0.0.1
	 */
	public static function update( $key, $value = true ) {
		if ( empty( $key ) ) {
			return false;
		}

		$setting_db = Get::option( SURECOOKIE_SETTINGS_OPTION, [], 'array' );

		if ( ! Validate::array( $setting_db ) ) {
			$setting_db = [];
		}

		// If the value is same as existing, return true. No need to update.
		if ( self::get( $key ) === $value ) {
			return true;
		}

		$setting_db[ $key ] = self::get_cleaned_value( $key, $value );
		return Update::option( SURECOOKIE_SETTINGS_OPTION, $setting_db );
	}

	/**
	 * Get cleaned value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @return mixed
	 */
	public static function get_cleaned_value( $key, $value ) {
		$option_type = Options::get_option_type( $key );

		switch ( $option_type ) {
			case 'bool':
				return Sanitize::boolean( $value );
			case 'int':
				return Sanitize::integer( $value );
			case 'array':
				return Sanitize::array( $value );
			case 'stylesheet':
				return Sanitize::stylesheet( $value );
			case 'hex_color':
				return Sanitize::hex_color( $value );
			case 'rich_text':
				return Sanitize::rich_text( $value );
			case 'string':
			default:
				return Sanitize::text( $value );
		}
	}
}
