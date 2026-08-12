<?php
/**
 * Onboarding page for SureCookie plugin.
 *
 * @package    SureCookie
 * @subpackage SureCookie\Admin
 * @since      0.0.1
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\LocalizeData;

defined( 'ABSPATH' ) || exit;

/**
 * Onboarding page for SureCookie plugin.
 *
 * This file renders the onboarding page content.
 *
 * @since 0.0.1
 */
class Onboarding {
	use GetInstance;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->setup();
	}

	/**
	 * Setup initializer.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function setup(): void {
		add_action( 'admin_menu', [ $this, 'register_onboarding_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_onboarding_assets' ] );
	}

	/**
	 * Register the onboarding page.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function register_onboarding_page(): void {
		if ( ! current_user_can( SURECOOKIE_CAPABILITY ) ) {
			return;
		}

		add_submenu_page(
			'surecookie-hidden',
			__( 'Onboarding', 'surecookie' ),
			'',
			SURECOOKIE_CAPABILITY,
			'surecookie-onboarding',
			[ $this, 'render_hidden_settings_page' ]
		);
	}

	/**
	 * Render the hidden settings page.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function render_hidden_settings_page(): void {
		if ( ! current_user_can( SURECOOKIE_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'surecookie' ) );
		}

		?>
		<div id="surecookie-admin-root" class="surecookie-styles"></div>
		<?php
	}

	/**
	 * Enqueue onboarding assets.
	 *
	 * @since 0.0.1
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_onboarding_assets( $hook ): void {
		$allowed_hooks = [
			'admin_page_surecookie-onboarding',
		];

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		$asset_file = SURECOOKIE_DIR . 'build/onboarding.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'surecookie-onboarding-script',
			SURECOOKIE_URL . 'build/onboarding.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? SURECOOKIE_VERSION,
			true
		);
		wp_set_script_translations( 'surecookie-onboarding-script', 'surecookie', SURECOOKIE_DIR . 'languages' );

		wp_enqueue_style(
			'surecookie-onboarding-shared-styles',
			SURECOOKIE_URL . 'assets/css/' . Get::shared_style_css(),
			[],
			SURECOOKIE_VERSION
		);

		wp_enqueue_style(
			'surecookie-onboarding-style',
			SURECOOKIE_URL . 'build/' . Get::onboarding_style_css(),
			[ 'surecookie-onboarding-shared-styles' ],
			$asset['version'] ?? SURECOOKIE_VERSION
		);

		// Localize onboarding data using utility.
		LocalizeData::localize_onboarding_script( 'surecookie-onboarding-script' );
	}
}
