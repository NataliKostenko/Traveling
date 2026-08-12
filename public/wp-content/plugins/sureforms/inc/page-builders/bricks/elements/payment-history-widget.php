<?php
/**
 * Bricks SureForms Payment History element.
 *
 * @package sureforms.
 * @since 2.12.2
 */

namespace SRFM\Inc\Page_Builders\Bricks\Elements;

use SRFM\Inc\Payments\Payment_History_Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SureForms Bricks element that displays the Payment History (parity with the srfm/payment-history block).
 *
 * @since 2.12.2
 */
class Payment_History_Widget extends \Bricks\Element {
	/**
	 * Element category.
	 *
	 * @var string
	 */
	public $category = 'sureforms';

	/**
	 * Element name.
	 *
	 * @var string
	 */
	public $name = 'sureforms-payment-history';

	/**
	 * Element icon.
	 *
	 * @var string
	 */
	public $icon = 'ti-wallet';

	/**
	 * Get element label.
	 *
	 * @since 2.12.2
	 * @return string element label.
	 */
	public function get_label() {
		return __( 'Payment History', 'sureforms' );
	}

	/**
	 * Get element keywords.
	 *
	 * @since 2.12.2
	 * @return array<string> element keywords.
	 */
	public function get_keywords() {
		return [
			'sureforms',
			'payment',
			'payment history',
			'subscription',
		];
	}

	/**
	 * Set element controls.
	 *
	 * @since 2.12.2
	 * @return void
	 */
	public function set_controls() {
		$this->controls['per_page'] = [
			'tab'     => 'content',
			'label'   => __( 'Items per page', 'sureforms' ),
			'type'    => 'number',
			'min'     => 1,
			'default' => 10,
		];

		$this->controls['show_subscription'] = [
			'tab'     => 'content',
			'label'   => __( 'Show Subscriptions', 'sureforms' ),
			'type'    => 'checkbox',
			'default' => true,
		];
	}

	/**
	 * Render the element output.
	 *
	 * Delegates to the Payment_History_Shortcode, mirroring the Gutenberg block.
	 *
	 * @since 2.12.2
	 * @return void
	 */
	public function render() {
		$per_page = absint( $this->settings['per_page'] ?? 10 );
		if ( $per_page <= 0 ) {
			$per_page = 10;
		}

		$atts = [
			'per_page'          => strval( $per_page ),
			'show_subscription' => ! empty( $this->settings['show_subscription'] ) ? 'true' : 'false',
		];

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() returns escaped attributes.
		echo Payment_History_Shortcode::get_instance()->render( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped within the shortcode renderer.
		echo '</div>';
	}
}
