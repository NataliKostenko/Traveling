<?php
/**
 * WP Consent API - Consent Handler
 *
 * Reads the SureCookie consent cookie on page load and bridges the consent
 * state to the WP Consent API so other plugins can check consent via
 * wp_has_consent().
 *
 * @package SureCookie\Inc\Integrations\WpConsentApi
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\WpConsentApi;

use SureCookie\Inc\Functions\ConsentState;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent_Handler class.
 *
 * @since 0.0.1-beta.1
 */
class Consent_Handler {
	use GetInstance;

	/**
	 * Map SureCookie categories to WP Consent API consent types.
	 *
	 * WP Consent API types: functional, preferences, statistics,
	 * statistics-anonymous, marketing.
	 *
	 * @since 0.0.1-beta.1
	 */
	private const CATEGORY_MAP = [
		'essential'  => 'functional',
		'functional' => 'preferences',
		'analytics'  => 'statistics',
		'marketing'  => 'marketing',
	];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		// Set default consent state on page load from the existing cookie.
		// Priority 15 on 'init' - runs after WP Consent API has loaded.
		add_action( 'init', [ $this, 'sync_consent_state' ], 15 );
	}

	/**
	 * Read the SureCookie consent cookie and push state to WP Consent API.
	 *
	 * This runs on every page load so that other plugins calling
	 * wp_has_consent('marketing') get the correct answer based on
	 * the user's SureCookie preferences.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	public function sync_consent_state(): void {
		if ( ! function_exists( 'wp_set_consent' ) ) {
			return;
		}

		$category_map      = self::get_full_category_map();
		$preferences       = ConsentState::preferences();
		$essential_wp_type = $category_map['essential'] ?? null;

		if ( empty( $preferences ) ) {
			$consent_model = self::get_active_consent_model();

			// Opt-out (CCPA): allow everything by default until user explicitly declines.
			$default_value = $consent_model === 'opt-out' ? 'allow' : 'deny';

			// De-duplicate WP types - custom categories may share the same WP type.
			$unique_wp_types = array_unique( array_values( $category_map ) );

			foreach ( $unique_wp_types as $wp_type ) {
				// Essential (functional) is always allowed regardless of consent model.
				$value = $essential_wp_type !== null && $wp_type === $essential_wp_type
					? 'allow'
					: $default_value;
				wp_set_consent( $wp_type, $value );
			}
			return;
		}

		// The map is many-to-one and wp_set_consent() is one cookie per WP type,
		// so first mapped category wins - as updateWpConsentApi() already does
		// in consentManager.js. Otherwise built-in `uncategorized` (which falls
		// back to `marketing`) re-allows marketing for a visitor who declined it.
		$assigned = [];

		foreach ( $category_map as $surecookie_cat => $wp_type ) {
			if ( isset( $assigned[ $wp_type ] ) ) {
				continue;
			}
			$assigned[ $wp_type ] = true;

			// Essential is always allowed - ignore tampered cookie values.
			$value = $surecookie_cat === 'essential'
				? 'allow'
				: ( ! empty( $preferences[ $surecookie_cat ] ) ? 'allow' : 'deny' );
			wp_set_consent( $wp_type, $value );
		}
	}

	/**
	 * Get the active consent model for the current visitor.
	 *
	 * Returns the core setting by default. The pro plugin's geo-location module
	 * can override this via the `surecookie_active_consent_model` filter to
	 * return the consent model from the matched geo preset.
	 *
	 * @return string 'opt-in' or 'opt-out'.
	 * @since 0.0.1-beta.1
	 */
	public static function get_active_consent_model(): string {
		$consent_model = Settings::get( 'consent_model' );

		if ( empty( $consent_model ) ) {
			$consent_model = 'opt-in';
		}

		/**
		 * Filter: Override the active consent model for the current visitor.
		 *
		 * Used by the geo-location module to return the consent model from
		 * the matched geo preset (e.g., 'opt-out' for CCPA regions).
		 *
		 * @param string $consent_model Current consent model ('opt-in' or 'opt-out').
		 * @since 0.0.1-beta.1
		 */
		$filtered = apply_filters( 'surecookie_active_consent_model', $consent_model );

		// Validate: only 'opt-in' or 'opt-out' are valid consent models.
		return in_array( $filtered, [ 'opt-in', 'opt-out' ], true ) ? $filtered : 'opt-in';
	}

	/**
	 * Get the WP Consent API category for a SureCookie category.
	 *
	 * @param string $surecookie_category SureCookie category slug.
	 * @return string WP Consent API consent type.
	 * @since 0.0.1-beta.1
	 */
	public static function map_category( string $surecookie_category ): string {
		/**
		 * Filter: Customize SureCookie → WP Consent API category mapping.
		 *
		 * @param array<string, string> $map Category mapping array.
		 * @since 0.0.1-beta.1
		 */
		$map = apply_filters( 'surecookie_wp_consent_api_category_map', self::CATEGORY_MAP );

		return $map[ $surecookie_category ] ?? 'marketing';
	}

	/**
	 * Build the full category map including any custom categories.
	 *
	 * Merges the default CATEGORY_MAP with custom categories from settings,
	 * mapping each custom category via the filterable map_category() method.
	 *
	 * @return array<string, string> Full SureCookie → WP Consent API category map.
	 * @since 0.0.1-beta.1
	 */
	public static function get_full_category_map(): array {
		$map        = self::CATEGORY_MAP;
		$categories = Settings::get( 'cookie_categories' );

		if ( ! is_array( $categories ) ) {
			return $map;
		}

		foreach ( $categories as $category ) {
			$cat_id = $category['id'] ?? '';
			if ( empty( $cat_id ) || isset( $map[ $cat_id ] ) ) {
				continue;
			}
			$map[ $cat_id ] = self::map_category( $cat_id );
		}

		return $map;
	}

}
