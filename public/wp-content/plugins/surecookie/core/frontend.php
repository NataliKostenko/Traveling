<?php
/**
 * App handler for SureCookie plugin.
 *
 * @package    SureCookie
 * @subpackage SureCookie/Core
 * @since      0.0.1
 */

namespace SureCookie\Core;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Validate;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\LocalizeData;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend handler for SureCookie plugin.
 *
 * This class handles the registration and rendering of the admin menu.
 *
 * @since 0.0.1
 */
class Frontend {
	use GetInstance;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
		// The banner is mounted client-side into an initially empty root, so its stylesheet
		// is never needed for first paint - load it off the critical path (non-render-blocking).
		add_filter( 'style_loader_tag', [ $this, 'async_public_style' ], 10, 3 );
		// Use wp_body_open to put the banner root at the very top of the body for better accessibility.
		// Fallback to wp_footer if the theme doesn't support wp_body_open.
		if ( function_exists( 'wp_body_open' ) ) {
			add_action( 'wp_body_open', [ $this, 'add_public_root' ], 1 );
		} else {
			add_action( 'wp_footer', [ $this, 'add_public_root' ] );
		}
	}

	/**
	 * Add public root div to footer.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function add_public_root(): void {
		echo '<div id="surecookie-public-root"></div>';
	}

	/**
	 * Load the public banner stylesheet without blocking render.
	 *
	 * The consent banner is mounted client-side by the deferred public bundle into an
	 * initially empty root ( see add_public_root() ), so its stylesheet is never required
	 * for first paint. Serving it with media="print" and swapping to media="all" once it
	 * finishes keeps the request off the critical path while it still downloads at page
	 * start. Keyed on the handle, so both style.css and its RTL twin are covered, with a
	 * noscript fallback so the banner stays styled when JavaScript is unavailable.
	 *
	 * @since 1.2.3
	 *
	 * @param string $tag    The full HTML link tag for the enqueued stylesheet.
	 * @param string $handle The stylesheet's registered handle.
	 * @param string $href   The stylesheet source URL.
	 * @return string The original tag, or the non-render-blocking variant for our handle.
	 */
	public function async_public_style( string $tag, string $handle, string $href ): string {
		if ( $handle !== 'surecookie-public' ) {
			return $tag;
		}

		return sprintf(
			'<link rel="stylesheet" id="%1$s-css" href="%2$s" media="print" onload="this.media=\'all\';this.onload=null;" />' . "\n" .
			'<noscript><link rel="stylesheet" href="%2$s" media="all" /></noscript>' . "\n",
			esc_attr( $handle ),
			esc_url( $href )
		);
	}

	/**
	 * Enqueue public assets.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function enqueue_public_assets(): void {
		if ( ! $this->should_enqueue_public_assets() ) {
			return;
		}

		// Check if script file exists before enqueuing.
		$base_directory = plugin_dir_path( SURECOOKIE_FILE ) . 'build/';
		$assets_file    = $base_directory . 'public.asset.php';
		if ( ! file_exists( $assets_file ) ) {
			return;
		}
		$assets_info = include $assets_file;

		wp_enqueue_style(
			'surecookie-public',
			SURECOOKIE_URL . 'assets/css/' . Get::shared_style_css(),
			[],
			SURECOOKIE_VERSION
		);

		// Add palette CSS variables from stored option.
		$palette_css = Sanitize::stylesheet( Get::palette_root_css() );
		if ( ! empty( $palette_css ) ) {
			wp_add_inline_style( 'surecookie-public', wp_strip_all_tags( $palette_css ) );
		}

		// Add custom CSS if available.
		$custom_css = Sanitize::stylesheet( (string) Settings::get( 'custom_css' ) );
		if ( Validate::not_empty( $custom_css ) ) {
			wp_add_inline_style( 'surecookie-public', "/* SureCookie Custom CSS - Public */\n" . wp_strip_all_tags( $custom_css ) );
		}

		/**
		 * Enqueue or register additional frontend scripts before the main public script.
		 *
		 * @param array<string, mixed> $assets_info Build asset metadata for surecookie-public.
		 */
		do_action( 'surecookie_public_enqueue_scripts', $assets_info );

		$public_dependencies = apply_filters( 'surecookie_public_script_dependencies', $assets_info['dependencies'] ?? [] );
		$public_dependencies = is_array( $public_dependencies ) ? $public_dependencies : ( $assets_info['dependencies'] ?? [] );

		// Enqueue the JavaScript file. Never use 'async' here: the pro/third-party
		// filter scripts are dependencies of this handle and must execute first, which 'defer' preserves (DOM order) and 'async' does not.
		wp_enqueue_script(
			'surecookie-public',
			SURECOOKIE_URL . 'build/public.js',
			$public_dependencies,
			$assets_info['version'] ?? SURECOOKIE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// A script chain only gets `defer` when every handle in it declares the intent, so opt the shared core dependencies in as well. WordPress
		// auto-downgrades any of them to blocking if another plugin enqueues a non-deferred dependent - graceful degradation, never breakage.
		// wp-i18n is deliberately excluded: wp_set_script_translations() injects a parse-time inline script that requires the wp.i18n global. Since core
		// wp-i18n depends on wp-hooks, wp-hooks is auto-downgraded to blocking for now - the intent below starts working once wp-i18n leaves the chain.
		foreach ( [ 'react', 'react-dom', 'react-jsx-runtime', 'wp-element', 'wp-escape-html', 'wp-hooks' ] as $core_handle ) {
			if ( wp_script_is( $core_handle, 'registered' ) ) {
				wp_script_add_data( $core_handle, 'strategy', 'defer' );
			}
		}
		wp_set_script_translations( 'surecookie-public', 'surecookie', SURECOOKIE_DIR . 'languages' );

		// Localize frontend data using utility.
		LocalizeData::localize_frontend_script( 'surecookie-public' );
	}

	/**
	 * Whether the public frontend assets should be enqueued for this request.
	 *
	 * Keeps the bundle loading when the banner is globally disabled but a
	 * re-consent entry point is still active on the page.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	public function should_enqueue_public_assets(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( Settings::get( 'banner_enabled' ) ) {
			return true;
		}

		return $this->has_active_reconsent_surface();
	}

	/**
	 * Whether the current request exposes any re-consent entry point.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	private function has_active_reconsent_surface(): bool {
		if ( Validate::not_empty( Settings::get( 'reconsent_menu_id' ) ) ) {
			return true;
		}

		if ( Settings::get( 'floating_widget_enabled' ) === true ) {
			return true;
		}

		return $this->current_page_has_reconsent_shortcode();
	}

	/**
	 * Whether the current page content contains the re-consent shortcode.
	 *
	 * @since 1.2.4
	 * @return bool
	 */
	private function current_page_has_reconsent_shortcode(): bool {
		if ( ! function_exists( 'has_shortcode' ) ) {
			return false;
		}

		global $post, $wp_query;

		$posts = [];

		if ( isset( $post ) && is_object( $post ) ) {
			$posts[] = $post;
		}

		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			$posts = array_merge( $posts, $wp_query->posts );
		}

		foreach ( $posts as $maybe_post ) {
			if ( ! is_object( $maybe_post ) || empty( $maybe_post->post_content ) ) {
				continue;
			}

			if ( has_shortcode( (string) $maybe_post->post_content, 'surecookie_reconsent_button' ) ) {
				return true;
			}
		}

		return false;
	}
}
