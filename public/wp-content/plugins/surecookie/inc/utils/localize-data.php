<?php
/**
 * Localize Data Utility
 *
 * Centralized utility for managing wp_localize_script data
 *
 * @package SureCookie\Inc\Utils
 */

namespace SureCookie\Inc\Utils;

use SureCookie\Admin\Product_Promotion;
use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Sanitize;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Integrations\WpConsentApi\Consent_Handler;
use SureCookie\Inc\Modules\AssistedScan\Utils as AssistedScanUtils;
use SureCookie\Inc\Modules\Nudges\Utils;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils as SiteScannerUtils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class LocalizeData
 *
 * Handles all localized script data for admin and frontend
 */
class LocalizeData {
	/**
	 * Maximum age, in hourly buckets, for which an expired token may be exchanged for
	 * a fresh one (~30 days). Covers long page-cache TTLs (WP Rocket's 10-day default,
	 * long CDN edge caches) while still requiring a token genuinely issued by this site -
	 * a forged token matches no bucket at any age.
	 *
	 * @since 1.2.1
	 */
	private const STALE_TOKEN_MAX_AGE_BUCKETS = 720;
	/**
	 * Get keyed map of UTM-tagged outbound marketing links.
	 *
	 * Each entry is `[ path, utm_content ]`, expanded via {@see Helper::get_marketing_link()}
	 * into a full URL with the standard source/medium/campaign defaults. Keys are consumed
	 * by the React helper `getSureCookieLink()`. Includes informational links (docs, support,
	 * branding) and Pro pricing CTAs (keys prefixed `pricing_`); marketing reports attribute
	 * by `utm_content`.
	 *
	 * @return array<string, string> Map of edge identifier => URL.
	 * @since 0.0.1-beta.2
	 */
	public static function get_website_links(): array {
		$entries = [
			// Informational / outbound marketing links.
			'help_center'                    => [ 'docs/', 'help_center' ],
			'quick_access_help_center'       => [ 'docs/', 'quick_access_help_center' ],
			'whats_new'                      => [ 'whats-new/', 'whats_new' ],
			'whats_new_rss'                  => [ 'whats-new/feed/', 'whats_new_rss' ],
			'admin_privacy_policy'           => [ 'terms-and-conditions/', 'admin_terms_conditions' ],
			'onboarding_privacy_policy'      => [ 'terms-and-conditions/', 'onboarding_terms_conditions' ],
			'banner_branding'                => [ '', 'banner_branding' ],
			'preferences_modal_branding'     => [ '', 'preferences_modal_branding' ],
			'resource_blocking_docs'         => [ 'docs/resource-blocking/', 'resource_blocking_docs' ],
			'scan_cookies_learn_more'        => [ 'docs/', 'scan_cookies_learn_more' ],
			'auth_drawer_learn_more'         => [ 'docs/', 'auth_drawer_learn_more' ],
			'scanning_logs_support'          => [ 'support/', 'scanning_logs_support' ],
			'deactivation_survey_support'    => [ 'contact/', 'deactivation_survey_support' ],
			'usage_optin'                    => [ 'share-usage-data/', 'usage_optin' ],
			'scan_booster'                   => [ 'pricing/', 'plugin_limit_banner' ],

			// Pro upgrade CTAs - all point at /pricing/, attributed by `utm_content`.
			'pricing_submenu_link_upgrade'   => [ 'pricing/', 'pricing_submenu_link_upgrade' ],
			'pricing_admin_toolbar_upgrade'  => [ 'pricing/', 'pricing_admin_toolbar_upgrade' ],
			'pricing_scan_cookies_banner'    => [ 'pricing/', 'pricing_scan_cookies_banner' ],
			'pricing_known_services'         => [ 'pricing/', 'pricing_known_services' ],
			'pricing_hide_branding_settings' => [ 'pricing/', 'pricing_hide_branding_settings' ],
			'pricing_reconsent_banner'       => [ 'pricing/', 'pricing_reconsent_banner' ],
			'pricing_onboarding_scan'        => [ 'pricing/', 'pricing_onboarding_scan' ],
			'pricing_onboarding_finish'      => [ 'pricing/', 'pricing_onboarding_finish' ],
			'pricing_quick_access'           => [ 'pricing/', 'pricing_quick_access' ],
			'pricing_upgrade_cpt'            => [ 'pricing/', 'pricing_upgrade_cpt' ],
			'pricing_upgrade_notice'         => [ 'pricing/', 'pricing_upgrade_notice' ],
			'pricing_geo_rules_banner'       => [ 'pricing/', 'pricing_geo_rules_banner' ],
			'pricing_hide_banner_pages'      => [ 'pricing/', 'pricing_hide_banner_pages' ],
			'pricing_learn_upgrade'          => [ 'pricing/', 'pricing_learn_upgrade' ],
		];

		$links = [];
		foreach ( $entries as $key => [ $path, $content ] ) {
			$links[ $key ] = Helper::get_marketing_link( $path, $content );
		}

		/**
		 * Filter the map of UTM-tagged outbound surecookie.com links before it is exposed to JS.
		 *
		 * Allows pro/addon plugins to override or extend marketing URL attribution.
		 *
		 * @since 0.0.1-beta.2
		 *
		 * @param array<string, string> $links Map of edge identifier => UTM-tagged URL.
		 */
		return apply_filters( 'surecookie_website_links', $links );
	}

	/**
	 * Translatable default strings exposed to the admin UI as placeholder fallbacks.
	 *
	 * @return array<string, string>
	 * @since 0.0.1-beta.2
	 */
	public static function get_translatable_defaults(): array {
		$keys = [ 'message_heading', 'message_description', 'preferences_modal_heading', 'preferences_modal_description' ];
		return array_map( 'strval', array_intersect_key( Settings::get_settings_defaults(), array_flip( $keys ) ) );
	}

	/**
	 * Get admin localized data
	 *
	 * @return array<string, mixed> Admin localized data
	 * @since 0.0.1
	 */
	public static function get_admin_data(): array {
		$promotional_plugins = Product_Promotion::get_instance()->get_promotional_plugins_filtered();
		$shuffle_id          = ! empty( $promotional_plugins ) ? wp_rand( 0, count( $promotional_plugins ) - 1 ) : 0;
		$cron_status         = Helper::are_crons_available();
		$installed_time      = (int) get_option( 'surecookie_usage_installed_time', 0 );
		$consent_counts      = ConsentLog::count_all_actions( '', '' );
		$recent_consent_logs = ConsentLog::count_recent_logs();
		$active_threshold    = ConsentLog::active_site_log_threshold();
		$total_consent_logs  = (int) ( $consent_counts['total'] ?? 0 );

		// Show upgrade notice if either the install age gate or the consent logs gate has been passed.
		$has_install_age_gate_passed  = $installed_time > 0 && ( time() - $installed_time ) >= DAY_IN_SECONDS;
		$has_consent_logs_gate_passed = $total_consent_logs >= 10;
		$should_show_upgrade_notice   = $has_install_age_gate_passed || $has_consent_logs_gate_passed;

		return apply_filters(
			'surecookie_localized_admin_data',
			[
				// AJAX & REST API.
				'ajaxurl'                    => admin_url( 'admin-ajax.php' ),
				'adminUrl'                   => admin_url(),
				'nonce'                      => wp_create_nonce( 'wp_rest' ),

				// Plugin data.
				'promotionalPlugins'         => $promotional_plugins,
				'promotionalShuffleId'       => $shuffle_id,
				'cookieCategories'           => Settings::get( 'cookie_categories' ),
				'colorPalettes'              => Get::color_palette_codes(),
				'nudges'                     => Utils::get_instance()->get_nudges(),
				'site_activity'              => [
					'active'      => $recent_consent_logs >= $active_threshold,
					'recent_logs' => $recent_consent_logs,
					'threshold'   => $active_threshold,
					'window_days' => ConsentLog::active_site_window_days(),
				],
				'is_pro_active'              => Helper::is_pro_active(),
				'is_pro_installed'           => Helper::is_pro_installed(),
				'website_links'              => self::get_website_links(),
				'core_version'               => SURECOOKIE_VERSION,
				'eu_countries'               => Get::eu_countries(),
				'navMenus'                   => Get::nav_menus(),

				// Timezone data.
				'siteTimezone'               => wp_timezone_string(),
				'gmtOffset'                  => (float) get_option( 'gmt_offset' ),

				// Common data. Kept for back-compat with existing consumers; now UTM-tagged via the marketing-link helper.
				'defaults'                   => self::get_translatable_defaults(),
				'helpLink'                   => Helper::get_marketing_link( 'docs/', 'help_center' ),
				'whatsNewLink'               => Helper::get_marketing_link( 'whats-new/', 'whats_new' ),
				'termsConditionsURL'         => Helper::get_marketing_link( 'terms-and-conditions/', 'admin_terms_conditions' ),
				'maxScanPages'               => SiteScannerUtils::get_max_scan_pages(),

				// Assisted Scan: a browser-collected fallback for sites our scanner cannot reach.
				'assistedScanEnabled'        => AssistedScanUtils::is_enabled(),
				'assistedMaxScanPages'       => AssistedScanUtils::get_max_pages(),

				// Cron related data.
				'isCronAvailable'            => (bool) $cron_status,
				'cronType'                   => $cron_status ? $cron_status : 'unavailable',
				'is_local_site'              => SaasClient::is_local_site(),
				'show_upgrade_notice'        => $should_show_upgrade_notice,

				// Admin & user data.
				'wp_dashboard_url'           => admin_url( 'admin.php' ),
				'onboarding_complete_status' => get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) ? 'yes' : 'no',
				'can_manage_settings'        => current_user_can( SURECOOKIE_CAPABILITY ),
			]
		);
	}

	/**
	 * Get frontend localized data
	 *
	 * @return array<string, mixed> Frontend localized data.
	 * @since 0.0.1
	 */
	public static function get_frontend_data(): array {
		$data = array_merge(
			Settings::get_public_settings_dataset(),
			[
				'eu_countries'             => Get::eu_countries(),
				'scanned_cookies'          => Get::all_scanned_cookies(),
				'cookie_policy_page_url'   => Get::cookie_policy_page_details()['url'] ?? '',
				'cookie_policy_page_title' => Get::cookie_policy_page_details()['title'] ?? '',
				'locale'                   => get_locale(),
				'siteTimezone'             => wp_timezone_string(),
				'gmtOffset'                => (float) get_option( 'gmt_offset' ),
				'consent_log_token'        => self::get_consent_log_token(),
				// Full endpoint URLs (not a root to join) so the frontend fetch() works on plain-permalink sites too.
				'api'                      => [
					'create_log' => esc_url_raw( rest_url( 'surecookie/v1/consent-logs/create-log' ) ),
					'token'      => esc_url_raw( rest_url( 'surecookie/v1/consent-logs/token' ) ),
				],
				// Exposed so frontend banner / preferences modal branding links carry UTM attribution.
				'website_links'            => self::get_website_links(),
				// Static UI chrome localized server-side - the public bundle
				// ships without wp-i18n, so it cannot translate at runtime.
				'branding_powered_by'      => __( 'Powered by', 'surecookie' ),
			]
		);

		// Override consent_model with the geo-resolved value so the frontend JS gets the
		// right model for this visitor's location. Only when the WP Consent API initialized.
		if ( did_action( 'surecookie_wp_consent_api_initialized' ) ) {
			$data['consent_model'] = Consent_Handler::get_active_consent_model();
		}

		// wp_localize_script() html_entity_decode()s every top-level scalar, so
		// pre-empt it here - this payload reaches every anonymous visitor.
		return Sanitize::rich_text_keys_after_decode( $data );
	}

	/**
	 * Token issued to the frontend to prove a request came from a real page load.
	 *
	 * Stateless HMAC of the current 1-hour bucket signed with `wp_salt('auth')`.
	 * No DB I/O, no race condition; survives page caching within the validity window.
	 *
	 * @since 0.0.1-beta.2
	 * @return string Hex-encoded HMAC.
	 */
	public static function get_consent_log_token(): string {
		return self::consent_log_token_for_bucket( self::current_consent_log_bucket() );
	}

	/**
	 * Validate a consent-log token against the current and 23 prior buckets.
	 *
	 * 24 buckets (~24 h) covers the typical page-cache TTL on production sites
	 * while still bounding replay if a token leaks.
	 *
	 * @since 0.0.1-beta.2
	 * @param string $token Token from the inbound request.
	 * @return bool
	 */
	public static function is_valid_consent_log_token( string $token ): bool {
		if ( $token === '' ) {
			return false;
		}

		$bucket = self::current_consent_log_bucket();
		for ( $i = 0; $i <= 23; $i++ ) {
			if ( hash_equals( self::consent_log_token_for_bucket( $bucket - $i ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a token was genuinely issued by this site within the last ~30 days,
	 * including still-valid tokens.
	 *
	 * Used by the fresh-token exchange endpoint: HTML from a long-lived page cache carries
	 * an expired token that would silently drop the consent-log audit trail. Exchanging it
	 * preserves the "came from a real page load" property without shortening cacheability.
	 *
	 * @since 1.2.1
	 * @param string $token Token from the inbound request.
	 * @return bool
	 */
	public static function is_recently_issued_consent_log_token( string $token ): bool {
		// Tokens are 64-char lowercase-hex HMACs. Reject anything else before the ~720-iteration
		// loop so malformed input costs O(1) instead of a full bucket sweep.
		if ( strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
			return false;
		}

		$bucket = self::current_consent_log_bucket();
		for ( $i = 0; $i <= self::STALE_TOKEN_MAX_AGE_BUCKETS; $i++ ) {
			if ( hash_equals( self::consent_log_token_for_bucket( $bucket - $i ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get onboarding localized data
	 *
	 * @return array<string, mixed> Onboarding localized data.
	 * @since 0.0.1
	 */
	public static function get_onboarding_data(): array {
		$cron_status  = Helper::are_crons_available();
		$current_user = wp_get_current_user();

		return [
			'termsConditionsURL' => Helper::get_marketing_link( 'terms-and-conditions/', 'onboarding_terms_conditions' ),
			'is_pro_active'      => Helper::is_pro_active(),
			'is_pro_installed'   => Helper::is_pro_installed(),
			'adminUrl'           => admin_url(),
			'website_links'      => self::get_website_links(),
			'maxScanPages'       => SiteScannerUtils::get_max_scan_pages(),
			'isCronAvailable'    => (bool) $cron_status,
			'cronType'           => $cron_status ? $cron_status : 'unavailable',
			'is_local_site'      => SaasClient::is_local_site(),
			'websiteLeadDetails' => [
				'firstName' => sanitize_text_field( $current_user->first_name ?? '' ),
				'lastName'  => sanitize_text_field( $current_user->last_name ?? '' ),
				'email'     => sanitize_email( $current_user->user_email ?? '' ),
			],
		];
	}

	/**
	 * Localize admin script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_admin_script( string $script_handle, string $object_name = 'surecookieAdminData' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_admin_localize_data', self::get_admin_data() )
		);
	}

	/**
	 * Localize frontend script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_frontend_script( string $script_handle, string $object_name = 'surecookiePublicSettings' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_frontend_localize_data', self::get_frontend_data() )
		);
	}

	/**
	 * Localize onboarding script
	 *
	 * @param string $script_handle Script handle.
	 * @param string $object_name   Object name for JavaScript.
	 * @return void
	 * @since 0.0.1
	 */
	public static function localize_onboarding_script( string $script_handle, string $object_name = 'surecookieOnboardingData' ): void {
		wp_localize_script(
			$script_handle,
			$object_name,
			apply_filters( 'surecookie_onboarding_localize_data', self::get_onboarding_data() )
		);
	}

	/**
	 * Current 1-hour bucket index.
	 *
	 * @since 0.0.1-beta.2
	 * @return int
	 */
	private static function current_consent_log_bucket(): int {
		return (int) floor( time() / HOUR_IN_SECONDS );
	}

	/**
	 * HMAC of a bucket index keyed with the site's auth salt.
	 *
	 * Includes the current blog id so multisite networks (where wp_salt is
	 * shared network-wide) issue distinct tokens per sub-site.
	 *
	 * @since 0.0.1-beta.2
	 * @param int $bucket Bucket index.
	 * @return string
	 */
	private static function consent_log_token_for_bucket( int $bucket ): string {
		$message = 'surecookie_consent_log|' . get_current_blog_id() . '|' . $bucket;
		return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
	}
}
