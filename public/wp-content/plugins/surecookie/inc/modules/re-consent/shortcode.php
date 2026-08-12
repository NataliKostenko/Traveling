<?php
/**
 * Re-Consent Shortcode.
 *
 * Registers [surecookie_reconsent_button] shortcode that renders a
 * "Cookie Preferences" button to open the consent preferences modal.
 *
 * @package SureCookie\Inc\Modules\ReConsent
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ReConsent;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcode
 *
 * @since 0.0.1
 */
class Shortcode {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		add_shortcode( 'surecookie_reconsent_button', [ $this, 'render' ] );
	}

	/**
	 * Render the re-consent button shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 * @since 0.0.1
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'label' => '',
				'class' => '',
			],
			$atts,
			'surecookie_reconsent_button'
		);

		/**
		 * Filter whether to show the cookie preferences shortcode button.
		 *
		 * Return false to completely prevent the button from rendering.
		 *
		 * @param bool                 $show Whether to show the button. Default true.
		 * @param array<string, mixed> $atts Shortcode attributes.
		 *
		 * @since 0.0.1
		 */
		if ( ! apply_filters( 'surecookie_show_reconsent_button', true, $atts ) ) {
			return '';
		}

		$label = ! empty( $atts['label'] )
			? sanitize_text_field( $atts['label'] )
			: Helper::translated_reconsent_label();

		$extra_class = ! empty( $atts['class'] )
			? ' ' . implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $atts['class'] ) ) )
			: '';

		return sprintf(
			'<a href="#" role="button" class="surecookie-reconsent-btn%s" data-surecookie-reconsent="true">%s</a>',
			esc_attr( $extra_class ),
			esc_html( $label )
		);
	}
}
