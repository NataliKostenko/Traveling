<?php
/**
 * Elementor SureForms Payment History widget.
 *
 * @package sureforms.
 * @since 2.12.2
 */

namespace SRFM\Inc\Page_Builders\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use SRFM\Inc\Payments\Payment_History_Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SureForms Elementor widget that displays the Payment History (parity with the srfm/payment-history block).
 *
 * @since 2.12.2
 */
class Payment_History_Widget extends Widget_Base {
	/**
	 * Get widget name.
	 *
	 * @since 2.12.2
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'srfm-payment-history';
	}

	/**
	 * Get widget title.
	 *
	 * @since 2.12.2
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Payment History', 'sureforms' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 2.12.2
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-price-list srfm-elementor-widget-icon';
	}

	/**
	 * Get widget categories.
	 *
	 * @since 2.12.2
	 * @return array<string> Widget categories.
	 */
	public function get_categories() {
		return [ 'sureforms-elementor' ];
	}

	/**
	 * Get widget keywords.
	 *
	 * @since 2.12.2
	 * @return array<string> Widget keywords.
	 */
	public function get_keywords() {
		return [
			'sureforms',
			'payment',
			'payment history',
			'subscription',
			'transactions',
		];
	}

	/**
	 * Register widget controls.
	 *
	 * @since 2.12.2
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'srfm_payment_history_section',
			[
				'label' => __( 'Payment History', 'sureforms' ),
			]
		);

		$this->add_control(
			'per_page',
			[
				'label'   => __( 'Items per page', 'sureforms' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'default' => 10,
			]
		);

		$this->add_control(
			'show_subscription',
			[
				'label'        => __( 'Show Subscriptions', 'sureforms' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'sureforms' ),
				'label_off'    => __( 'Hide', 'sureforms' ),
				'return_value' => 'true',
				'default'      => 'true',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output on the frontend.
	 *
	 * Delegates to the Payment_History_Shortcode, mirroring the Gutenberg block.
	 *
	 * @since 2.12.2
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ! is_array( $settings ) ) {
			return;
		}

		$per_page = absint( $settings['per_page'] ?? 10 );
		if ( $per_page <= 0 ) {
			$per_page = 10;
		}

		$atts = [
			'per_page'          => strval( $per_page ),
			'show_subscription' => ! empty( $settings['show_subscription'] ) && 'true' === $settings['show_subscription'] ? 'true' : 'false',
		];

		echo Payment_History_Shortcode::get_instance()->render( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped within the shortcode renderer.
	}
}
