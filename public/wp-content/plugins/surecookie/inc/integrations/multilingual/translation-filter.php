<?php
/**
 * Multilingual Integration - Translation Filter
 *
 * Applies WPML/Polylang translations to localized data
 * (frontend banner + admin preview) before it reaches JS.
 *
 * @package SureCookie\Inc\Integrations\Multilingual
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\Multilingual;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Translation_Filter class.
 *
 * @since 0.0.1-beta.1
 */
class Translation_Filter {
	use GetInstance;

	/**
	 * WPML/Polylang string context - must match String_Registration.
	 */
	private const STRING_CONTEXT = 'SureCookie';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		add_filter( 'surecookie_frontend_localize_data', [ $this, 'translate_frontend_data' ] );
		add_filter( 'surecookie_admin_localize_data', [ $this, 'translate_admin_data' ] );
	}

	/**
	 * Apply translations to frontend localized data.
	 *
	 * @param array<string, mixed> $data Frontend localized data.
	 * @return array<string, mixed> Translated data.
	 * @since 0.0.1-beta.1
	 */
	public function translate_frontend_data( array $data ): array {
		if ( ! Init::is_polylang_active() && ! Init::is_wpml_active() ) {
			return $data;
		}

		$data = $this->translate_settings( $data );
		return $this->translate_category_array( $data, 'cookie_categories' );
	}

	/**
	 * Apply translations to admin localized data.
	 *
	 * Translates category labels (camelCase key) and the `defaults`
	 * placeholder map surfaced to the banner-content editor.
	 *
	 * @param array<string, mixed> $data Admin localized data.
	 * @return array<string, mixed> Translated data.
	 * @since 1.0.0
	 */
	public function translate_admin_data( array $data ): array {
		if ( ! Init::is_polylang_active() && ! Init::is_wpml_active() ) {
			return $data;
		}

		$data = $this->translate_category_array( $data, 'cookieCategories' );
		return $this->translate_defaults( $data );
	}

	/**
	 * Translate a single string using whichever plugin is active.
	 *
	 * Shared entry point: besides the banner localize-data filters in this
	 * class, server-rendered surfaces (cookie-policy shortcode, re-consent
	 * menu item and shortcode) call this statically so the WPML/Polylang
	 * branching and sanitization live in one place. String names must match
	 * those registered by String_Registration.
	 *
	 * Polylang keys by source string; WPML keys by context + name.
	 *
	 * @param string $value        Original string value.
	 * @param string $name         String name used during registration (surecookie_*).
	 * @param bool   $is_multiline Whether the string may contain HTML.
	 * @return string Translated string, sanitized appropriately.
	 * @since 1.0.0
	 */
	public static function translate_string( string $value, string $name, bool $is_multiline = false ): string {
		$translated = $value;

		if ( Init::is_polylang_active() ) {
			$translated = pll__( $value );
		} elseif ( Init::is_wpml_active() ) {
			$translated = (string) apply_filters( 'wpml_translate_single_string', $value, self::STRING_CONTEXT, $name );
		}

		return $is_multiline
			? wp_kses_post( $translated )
			: sanitize_text_field( $translated );
	}

	/**
	 * Translate setting string values.
	 *
	 * @param array<string, mixed> $data Frontend data.
	 * @return array<string, mixed>
	 * @since 0.0.1-beta.1
	 */
	private function translate_settings( array $data ): array {
		foreach ( Init::TRANSLATABLE_KEYS as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}

			$is_multiline = $key === 'message_description';
			$data[ $key ] = self::translate_string( $data[ $key ], 'surecookie_' . $key, $is_multiline );
		}

		return $data;
	}

	/**
	 * Translate the admin `defaults` placeholder map.
	 *
	 * Mirrors the frontend setting-string translation so admin preview
	 * fallbacks render in the active language.
	 *
	 * @param array<string, mixed> $data Admin data.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function translate_defaults( array $data ): array {
		if ( empty( $data['defaults'] ) || ! is_array( $data['defaults'] ) ) {
			return $data;
		}

		foreach ( $data['defaults'] as $key => $value ) {
			if ( ! is_string( $value ) || $value === '' || ! in_array( $key, Init::TRANSLATABLE_KEYS, true ) ) {
				continue;
			}

			$is_multiline             = $key === 'message_description';
			$data['defaults'][ $key ] = self::translate_string( $value, 'surecookie_' . $key, $is_multiline );
		}

		return $data;
	}

	/**
	 * Translate name/description for a list of cookie categories.
	 *
	 * Shared by frontend (`cookie_categories`) and admin (`cookieCategories`)
	 * payloads. Skips entries whose id sanitizes to an empty key so the
	 * translation lookup matches what String_Registration registered.
	 *
	 * @param array<string, mixed> $data       Payload to mutate.
	 * @param string               $array_key  Top-level key holding the category list.
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function translate_category_array( array $data, string $array_key ): array {
		if ( empty( $data[ $array_key ] ) || ! is_array( $data[ $array_key ] ) ) {
			return $data;
		}

		foreach ( $data[ $array_key ] as $index => $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$cat_id = sanitize_key( $category['id'] ?? $index );
			if ( $cat_id === '' ) {
				continue;
			}

			if ( ! empty( $category['name'] ) && is_string( $category['name'] ) ) {
				$data[ $array_key ][ $index ]['name'] = self::translate_string(
					$category['name'],
					'surecookie_cat_' . $cat_id . '_name'
				);
			}

			if ( ! empty( $category['description'] ) && is_string( $category['description'] ) ) {
				$data[ $array_key ][ $index ]['description'] = self::translate_string(
					$category['description'],
					'surecookie_cat_' . $cat_id . '_description',
					true
				);
			}
		}

		return $data;
	}
}
