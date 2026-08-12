<?php
/**
 * Re-Consent Helper.
 *
 * Shared helpers for the re-consent menu item and shortcode so the button
 * label is resolved and translated in a single place.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 1.2.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\Multilingual\Translation_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Helper
 *
 * @since 1.2.1
 */
class Helper {
	/**
	 * Get the translated re-consent button label.
	 *
	 * Reads the configured `reconsent_button_label` and runs it through the
	 * multilingual integration. Shared by the nav-menu item and the shortcode
	 * so both render the same translated label.
	 *
	 * @return string Translated label, or '' when no label is configured.
	 * @since 1.2.1
	 */
	public static function translated_reconsent_label(): string {
		$label = Settings::get( 'reconsent_button_label' );

		if ( ! is_string( $label ) || $label === '' ) {
			return '';
		}

		return Translation_Filter::translate_string( $label, 'surecookie_reconsent_button_label' );
	}
}
