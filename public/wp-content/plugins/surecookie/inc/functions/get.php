<?php
/**
 * Get
 *
 * @package SureCookie
 * @since 0.0.1
 */

namespace SureCookie\Inc\Functions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get - This class will handle all functions to get data from database.
 *
 * @since 0.0.1
 */
class Get {
	/**
	 * Check whether current locale direction is RTL.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_rtl(): bool {
		return function_exists( 'is_rtl' ) && is_rtl();
	}

	/**
	 * Get option
	 * This function will get option
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value.
	 * @param string $format      Format.
	 *
	 * @since 0.0.1
	 * @return mixed
	 */
	public static function option( $option_name, $default = false, $format = 'string' ) {
		$get = get_option( $option_name, $default );

		if ( $format === 'array' ) {
			$validate = Validate::array( $get );
			return empty( $validate ) || ! is_array( $validate ) ? $default : $validate;
		}

		return $get;
	}

	/**
	 * Get the post types selectable in a SureCookie picker.
	 *
	 * Single source of truth for both the Cookie Policy page picker
	 * ($context = 'policy') and the Site Scanner page picker ($context =
	 * 'scanner'). Both default to the `page` post type; the list is extensible
	 * per surface via the `surecookie_searchable_post_types` filter. The result
	 * is always intersected with public post types and stripped of `attachment`
	 * so a filter can never surface private, non-existent, or media types.
	 *
	 * @param string $context Picker surface: 'policy' or 'scanner'.
	 * @since 1.2.4
	 * @return array<int, string> Indexed array of post type slugs.
	 */
	public static function searchable_post_types( string $context = 'policy' ): array {
		$post_types = [ 'page' => 'page' ];

		/**
		 * Filter the post types selectable in a SureCookie picker.
		 *
		 * Fires for the Cookie Policy picker ($context = 'policy') and the Site
		 * Scanner picker ($context = 'scanner'). Defaults to ['page'] for both.
		 * Return a superset to opt additional PUBLIC post types in; branch on
		 * $context to target a single surface.
		 *
		 * Example - add a `product` CPT to the SCANNER only (functions.php):
		 *
		 *     add_filter( 'surecookie_searchable_post_types', function ( $types, $context ) {
		 *         if ( 'scanner' === $context ) {   // omit the check to target both surfaces
		 *             $types['product'] = 'product'; // must be registered public => true
		 *         }
		 *         return $types;
		 *     }, 10, 2 );
		 *
		 * @param array<string, string> $post_types Post type slugs keyed by slug.
		 * @param string                $context    'policy' | 'scanner'.
		 * @since 1.2.4 Added the $context parameter.
		 * @since 0.0.1-beta.2
		 */
		$post_types = (array) apply_filters( 'surecookie_searchable_post_types', $post_types, $context );

		// Guard against non-public / non-existent slugs a filter may have added,
		// and drop media - attachment titles are noise in a page picker. Strip
		// by value with array_diff (not unset by key): array_intersect preserves
		// the filter's keys, so an indexed return like ['page','attachment']
		// would keep numeric keys and slip past a keyed unset.
		$post_types = array_intersect( $post_types, get_post_types( [ 'public' => true ] ) );
		$post_types = array_diff( $post_types, [ 'attachment' ] );

		return array_values( $post_types );
	}

	/**
	 * Get cookie categories definitions.
	 *
	 * Single source of truth for all cookie category descriptions.
	 * Returns associative array format to match database storage.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, string|bool>>
	 */
	public static function default_cookie_categories() {
		return [
			'essential'     => [
				'id'          => 'essential',
				'name'        => __( 'Essential Cookies', 'surecookie' ),
				'description' => __( 'Required for basic website functionality.', 'surecookie' ),
				'required'    => true,
			],
			'functional'    => [
				'id'          => 'functional',
				'name'        => __( 'Functional Cookies', 'surecookie' ),
				'description' => __( 'Enable features like saved preferences and personalized content.', 'surecookie' ),
				'required'    => false,
			],
			'analytics'     => [
				'id'          => 'analytics',
				'name'        => __( 'Analytics Cookies', 'surecookie' ),
				'description' => __( 'Help us understand how visitors use our site so we can improve it.', 'surecookie' ),
				'required'    => false,
			],
			'marketing'     => [
				'id'          => 'marketing',
				'name'        => __( 'Marketing Cookies', 'surecookie' ),
				'description' => __( 'Used to show you relevant ads based on your interests.', 'surecookie' ),
				'required'    => false,
			],
			'uncategorized' => [
				'id'          => 'uncategorized',
				'name'        => __( 'Uncategorized Cookies', 'surecookie' ),
				'description' => __( 'Cookies that haven\'t been categorized yet.', 'surecookie' ),
				'required'    => false,
			],
		];
	}

	/**
	 * Get default cookie categories keys only.
	 *
	 * @since 0.0.1
	 * @return array<string>
	 */
	public static function default_cookie_categories_keys() {
		return array_keys( self::default_cookie_categories() );
	}

	/**
	 * Get unique ID - Applicable for cookies, categories.
	 *
	 * @param string $prefix Prefix.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function unique_id( $prefix = '' ) {
		// Must be collision-free even when called many times within the same
		// second (e.g. importing a whole service's cookies in one go); time()
		// alone repeats and callers key arrays by this id, so entries clobbered.
		return $prefix . wp_generate_uuid4();
	}

	/**
	 * Get redirect URL if menu location changed.
	 *
	 * @param array<string, mixed> $sanitized_settings Sanitized settings payload.
	 * @param mixed                $previous_top_level Previous value for top level menu setting.
	 * @return string
	 */
	public static function menu_redirect_url( array $sanitized_settings, $previous_top_level ): string {
		if ( ! array_key_exists( 'top_level_menu_enabled', $sanitized_settings ) ) {
			return '';
		}

		$next_top_level = (bool) $sanitized_settings['top_level_menu_enabled'];
		if ( $next_top_level === (bool) $previous_top_level ) {
			return '';
		}

		$page_fragment = SURECOOKIE_PREFIX . '#/settings/general';

		return $next_top_level
			? admin_url( 'admin.php?page=' . $page_fragment )
			: admin_url( 'options-general.php?page=' . $page_fragment );
	}

	/**
	 * Get formatted custom cookies - depending on cookie categorize separate out all custom cookies and return it to consider under scanned result.
	 *
	 * @since 0.0.1
	 * @return array<list<array<string, mixed>>> Formatted custom cookies.
	 */
	public static function formatted_custom_cookies() {
		$custom_cookies = Settings::get( 'custom_cookies' );

		if ( empty( $custom_cookies ) ) {
			return [];
		}

		$formatted_cookies = [];

		foreach ( $custom_cookies as $_custom_cookie ) {
			$formatted_cookies[ $_custom_cookie['category'] ] [] = [
				'name'        => $_custom_cookie['name'],
				'description' => $_custom_cookie['description'],
				'domain'      => $_custom_cookie['domain'],
				'value'       => $_custom_cookie['value'] ?? '',
				'path'        => $_custom_cookie['path'] ?? '/',
				'expires'     => $_custom_cookie['expires'],
				// Custom cookies are authored with an explicit lifetime in days;
				// carry it through so consumers show the day count instead of re-deriving it from the absolute 'expires' timestamp.
				'duration'    => $_custom_cookie['duration'] ?? '',
				'secure'      => $_custom_cookie['secure'] ?? false,
				'httpOnly'    => $_custom_cookie['httpOnly'] ?? false,
				'provider'    => $_custom_cookie['provider'] ?? '',
				'purpose'     => $_custom_cookie['purpose'] ?? '',
			];
		}

		return $formatted_cookies;
	}

	/**
	 * Get color palette codes.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, string>>
	 */
	public static function color_palette_codes() {
		return apply_filters(
			'surecookie_color_palette_codes',
			[
				'blue-purple' => [
					'id'                => 'blue-purple',
					'name'              => 'Blue & Purple',
					'acceptButton'      => '#1463FF',
					'acceptButtonHover' => '#3B82F6',
					'declineButton'     => '#1463FF',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'purple-pink' => [
					'id'                => 'purple-pink',
					'name'              => 'Purple & Pink',
					'acceptButton'      => '#9335B6',
					'acceptButtonHover' => '#8528A7',
					'declineButton'     => '#9335B6',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'blue-cyan'   => [
					'id'                => 'blue-cyan',
					'name'              => 'Blue & Cyan',
					'acceptButton'      => '#2235DD',
					'acceptButtonHover' => '#1A2BC6',
					'declineButton'     => '#2235DD',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'green-lime'  => [
					'id'                => 'green-lime',
					'name'              => 'Green & Lime',
					'acceptButton'      => '#377A00',
					'acceptButtonHover' => '#2F6A00',
					'declineButton'     => '#377A00',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'red-orange'  => [
					'id'                => 'red-orange',
					'name'              => 'Red & Orange',
					'acceptButton'      => '#E11B14',
					'acceptButtonHover' => '#C00902',
					'declineButton'     => '#E11B14',
					'bgColor'           => '#FFFFFF',
					'textColor'         => '#374151',
				],
				'dark-blue'   => [
					'id'                => 'dark-blue',
					'name'              => 'Dark & Blue',
					'acceptButton'      => '#0285FF',
					'acceptButtonHover' => '#0177E3',
					'declineButton'     => '#0285FF',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-green'  => [
					'id'                => 'dark-green',
					'name'              => 'Dark & Green',
					'acceptButton'      => '#1FB4A6',
					'acceptButtonHover' => '#0DAF9F',
					'declineButton'     => '#1FB4A6',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-pink'   => [
					'id'                => 'dark-pink',
					'name'              => 'Dark & Pink',
					'acceptButton'      => '#FB5FAB',
					'acceptButtonHover' => '#EA569D',
					'declineButton'     => '#FB5FAB',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
				'dark-orange' => [
					'id'                => 'dark-orange',
					'name'              => 'Dark & Orange',
					'acceptButton'      => '#DCA54A',
					'acceptButtonHover' => '#D09A41',
					'declineButton'     => '#DCA54A',
					'bgColor'           => '#111827',
					'textColor'         => '#E5E7EB',
				],
			]
		);
	}

	/**
	 * Get EU/EEA countries list (ISO 3166-1 alpha-2 codes).
	 *
	 * Single source of truth for EU countries used in geo-targeting.
	 * This includes EU members, EEA countries, and other European countries
	 * commonly included for GDPR compliance purposes.
	 *
	 * @since 0.0.1
	 * @return array<string> Array of country codes.
	 */
	public static function eu_countries(): array {
		return apply_filters(
			'surecookie_eu_countries',
			[
				'AL',
				'AD',
				'AT',
				'BY',
				'BE',
				'BA',
				'BG',
				'HR',
				'CY',
				'CZ',
				'DK',
				'EE',
				'FI',
				'FR',
				'DE',
				'GR',
				'HU',
				'IS',
				'IE',
				'IT',
				'LV',
				'LI',
				'LT',
				'LU',
				'MK',
				'MT',
				'MD',
				'MC',
				'ME',
				'NL',
				'NO',
				'PL',
				'PT',
				'RO',
				'RU',
				'SM',
				'RS',
				'SK',
				'SI',
				'ES',
				'SE',
				'CH',
				'UA',
				'GB',
				'VA',
			]
		);
	}

	/**
	 * Get nav menus list for re-consent menu integration.
	 *
	 * @since 0.0.1
	 * @return array<int, array{id: string, name: string}> Array of nav menu items with id and name.
	 */
	public static function nav_menus(): array {
		$nav_menus = wp_get_nav_menus();
		$list      = [];

		if ( ! is_array( $nav_menus ) ) {
			return [];
		}

		foreach ( $nav_menus as $menu ) {
			$list[] = [
				'id'   => (string) $menu->term_id,
				'name' => $menu->name,
			];
		}

		return $list;
	}

	/**
	 * Get scanned cookies as a flat array with category field added to each cookie.
	 *
	 * @since 0.0.1
	 * @return array<int, array<string, mixed>> Flat array of scanned cookies.
	 */
	public static function all_scanned_cookies(): array {
		$scanned_raw = self::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );

		if ( ! is_array( $scanned_raw ) ) {
			return [];
		}

		$flat = [];

		foreach ( $scanned_raw as $category_id => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}
			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}
				$flat[] = array_merge(
					$cookie,
					[
						'category' => $category_id,
						'type'     => 'scanned',
					]
				);
			}
		}

		return $flat;
	}

	/**
	 * Get CSS variables for the active color palette.
	 *
	 * @since 0.0.1
	 * @return string
	 */
	public static function palette_root_css(): string {
		$color_palette = sanitize_key( (string) Settings::get( 'color_palette' ) );
		$palette_codes = self::color_palette_codes();

		if ( ! isset( $palette_codes[ $color_palette ] ) ) {
			return '';
		}

		$palette_data = $palette_codes[ $color_palette ];

		/**
		 * Filters resolved palette colors before CSS-var emission.
		 *
		 * Array shape mirrors Get::color_palette_codes() entries.
		 *
		 * @since 1.0.0
		 * @param array<string, string> $palette_data  Resolved palette colors.
		 * @param string                $color_palette Active palette id.
		 */
		$filtered     = apply_filters( 'surecookie_resolved_palette_colors', $palette_data, $color_palette );
		$palette_data = is_array( $filtered ) ? $filtered : $palette_data;

		// Decline falls back to text color (not accept) so a missing
		// secondary never visually collapses accept and decline together.
		$secondary_fallback = $palette_data['textColor'] ?? '#374151';

		$css_variables = [
			'--surecookie-banner-primary-color'       => $palette_data['acceptButton'] ?? '#1463FF',
			'--surecookie-banner-primary-hover-color' => $palette_data['acceptButtonHover'] ?? '#3B82F6',
			'--surecookie-banner-secondary-color'     => $palette_data['declineButton'] ?? $secondary_fallback,
			'--surecookie-banner-text-color'          => $palette_data['textColor'] ?? '#374151',
			'--surecookie-banner-background-color'    => $palette_data['bgColor'] ?? '#FFFFFF',
		];

		$css_output = ':root {';
		foreach ( $css_variables as $variable => $value ) {
			$sanitized_value = sanitize_hex_color( (string) $value );
			if ( empty( $sanitized_value ) ) {
				continue;
			}

			$css_output .= "\t{$variable}: {$sanitized_value};\n";
		}

		if ( $css_output === ':root {' ) {
			return '';
		}

		$css_output .= '}';
		$css_output .= "\n/* Color Palette: {$color_palette} */\n";

		return $css_output;
	}

	/**
	 * Get shared style which required for both frontend and in admin preview.
	 *
	 * @since 0.0.1
	 * @return string $style_file CSS file name depending on RTL or LTR.
	 */
	public static function shared_style_css() {
		$style_file = 'style.css';
		if ( self::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/style-rtl.css' ) ) {
			$style_file = 'style-rtl.css';
		}
		return $style_file;
	}

	/**
	 * Get admin style.
	 *
	 * @since 0.0.1
	 * @return string $admin_style_file CSS file name depending on RTL or LTR.
	 */
	public static function admin_style_css() {
		$admin_style_file = 'admin.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'build/admin-rtl.css' ) ) {
			$admin_style_file = 'admin-rtl.css';
		}
		return $admin_style_file;
	}

	/**
	 * Get onboarding style.
	 *
	 * @since 0.0.1
	 * @return string $onboarding_style_file CSS file name depending on RTL or LTR.
	 */
	public static function onboarding_style_css() {
		$onboarding_style_file = 'onboarding.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'build/onboarding-rtl.css' ) ) {
			$onboarding_style_file = 'onboarding-rtl.css';
		}
		return $onboarding_style_file;
	}

	/**
	 * Get jodit style.
	 *
	 * @since 1.1.0
	 * @return string $jodit_style_file CSS file name depending on RTL or LTR.
	 */
	public static function jodit_style_css() {
		$jodit_style_file = 'jodit.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/jodit-rtl.css' ) ) {
			$jodit_style_file = 'jodit-rtl.css';
		}
		return $jodit_style_file;
	}

	/**
	 * Get cookie-policy style.
	 *
	 * @since 1.1.0
	 * @return string $cookie_policy_style_file CSS file name depending on RTL or LTR.
	 */
	public static function cookie_policy_style_css() {
		$cookie_policy_style_file = 'cookie-policy.css';
		if ( Get::is_rtl() && file_exists( SURECOOKIE_DIR . 'assets/css/cookie-policy-rtl.css' ) ) {
			$cookie_policy_style_file = 'cookie-policy-rtl.css';
		}
		return $cookie_policy_style_file;
	}

	/**
	 * Get cookie policy page details (URL and title).
	 *
	 * @since 0.0.1
	 * @return array<string, string> Associative array with 'url' and 'title' keys.
	 */
	public static function cookie_policy_page_details(): array {
		$data = [
			'url'   => '',
			'title' => '',
		];

		$policy_page_id = self::resolve_translated_policy_page_id( absint( Settings::get( 'cookie_policy_page_id' ) ) );

		if ( $policy_page_id > 0 ) {
			$page = get_post( $policy_page_id );
			if ( $page instanceof \WP_Post && $page->post_status === 'publish' ) {
				$permalink = get_permalink( $page );
				if ( ! $permalink ) {
					return $data;
				}

				$parsed_url = wp_parse_url( $permalink );
				$home_url   = wp_parse_url( home_url() );

				if ( ! empty( $parsed_url['host'] ) && isset( $home_url['host'] ) && strtolower( $parsed_url['host'] ) === strtolower( $home_url['host'] ) ) {
					$data['url']   = esc_url( $permalink );
					$data['title'] = sanitize_text_field( get_the_title( $page ) );
				}
			}
		}

		return $data;
	}

	/**
	 * Resolve cookie policy page ID for the current language.
	 *
	 * Supports WPML and Polylang translation APIs when available.
	 *
	 * @param int $page_id Source page ID from settings.
	 * @return int
	 * @since 1.2.1
	 */
	private static function resolve_translated_policy_page_id( int $page_id ): int {
		if ( $page_id <= 0 ) {
			return 0;
		}

		$wpml_page_id = apply_filters( 'wpml_object_id', $page_id, 'page', true );

		if ( is_numeric( $wpml_page_id ) && (int) $wpml_page_id > 0 && (int) $wpml_page_id !== $page_id ) {
			return (int) $wpml_page_id;
		}

		if ( function_exists( 'pll_get_post' ) ) {
			$polylang_page_id = pll_get_post( $page_id );
			if ( is_numeric( $polylang_page_id ) && (int) $polylang_page_id > 0 ) {
				return (int) $polylang_page_id;
			}
		}

		return $page_id;
	}
}
