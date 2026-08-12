<?php
/**
 * Google Consent Mode - Consent Handler
 *
 * Handles default consent script output and consent state updates.
 *
 * @package SureCookie
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\ConsentState;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent_Handler class.
 *
 * Outputs default consent script and handles consent state management.
 *
 * @since 0.0.0-alpha.1
 */
class Consent_Handler {
	use GetInstance;

	/**
	 * Pre-generated consent script HTML.
	 *
	 * @var string
	 */
	private $consent_script = '';

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private function __construct() {
		// Use output buffering to inject consent script at start of <head>.
		// This guarantees execution before any tracking scripts (Critical Fix #4).
		add_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );

		// Warn admins when Google Site Kit's own Consent Mode is active (it
		// conflicts with ours). Wired on admin_notices directly: the frontend
		// output-buffering path never runs on wp-admin, so the conflict notice
		// would otherwise never render.
		add_action( 'admin_notices', [ $this, 'maybe_render_site_kit_conflict_notice' ] );
	}

	/**
	 * Start output buffering to inject consent script.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function start_output_buffer(): void {
		// Skip on admin pages.
		if ( is_admin() ) {
			return;
		}

		// Skip if GCM not enabled.
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}

		// Pre-generate the consent script before starting the output buffer.
		// This avoids calling ob_start() inside the buffer callback (which PHP forbids).
		try {
			$this->consent_script = $this->generate_consent_script();
		} catch ( \Exception $e ) {
			$this->consent_script = '';
		}

		// Start output buffering.
		ob_start( [ $this, 'inject_consent_script' ] );
	}

	/**
	 * Inject consent script into HTML buffer.
	 *
	 * @param string $buffer HTML buffer.
	 * @return string Modified HTML buffer with consent script.
	 * @since 0.0.0-alpha.1
	 */
	public function inject_consent_script( $buffer ): string {
		// Check for conflicts before injecting.
		if ( $this->has_consent_mode_conflict() ) {
			return $buffer;
		}

		// No URL/signature gate: detection can never see server-side or first-party
		// tag setups (#864), and defaults must precede any tag - GCM on means inject.

		// Inject pre-generated script immediately after <head> tag.
		$script = $this->consent_script;
		$buffer = preg_replace_callback(
			'/(<head[^>]*>)/i',
			static function ( $matches ) use ( $script ) {
				return $matches[1] . $script;
			},
			$buffer,
			1
		) ?? $buffer;

		return $buffer;
	}

	/**
	 * Show admin notice about Google Site Kit conflict.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function show_site_kit_conflict_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'SureCookie:', 'surecookie' ); ?></strong>
				<?php
				esc_html_e(
					'Google Site Kit\'s Consent Mode is active. Please disable it in Site Kit settings to use SureCookie\'s implementation.',
					'surecookie'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin_notices callback: warn when Google Site Kit's Consent Mode conflicts
	 * with SureCookie's, but only while our GCM is enabled. Runs on wp-admin,
	 * where the frontend buffering path that detects the conflict never executes.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function maybe_render_site_kit_conflict_notice(): void {
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}
		if ( ! $this->has_consent_mode_conflict() ) {
			return;
		}
		$this->show_site_kit_conflict_notice();
	}

	/**
	 * Generate consent script HTML.
	 *
	 * @return string Consent script HTML.
	 * @since 0.0.0-alpha.1
	 */
	private function generate_consent_script(): string {
		// ::preferences() normalizes each value with a strict `=== true` check and
		// rejects the whole cookie if any required key isn't a real boolean, so a
		// tampered cookie with string "false" can't bypass the ternary in
		// map_to_google_consent(). ::has_recorded_choice() distinguishes
		// "no/invalid cookie" from "user declined all" for region-default skip.
		$cookie_preferences = ConsentState::preferences();
		$has_recorded       = ConsentState::has_recorded_choice();

		// Check for Global Privacy Control (CCPA) - Critical Fix #3.
		$gpc_enabled = $this->is_gpc_enabled();

		$default_denied = [
			'essential'  => true,
			'functional' => false,
			'analytics'  => false,
			'marketing'  => false,
		];

		if ( $gpc_enabled ) {
			$preferences = $default_denied;
		} elseif ( $cookie_preferences !== null ) {
			$preferences = $cookie_preferences;
		} else {
			$preferences = $this->global_default_preferences( $default_denied );
		}

		// Map to Google consent parameters.
		$consent_state = $this->map_to_google_consent( $preferences );

		// Get settings with validation.
		$wait_time = $this->validate_wait_time( Settings::get( 'gcm_wait_for_update' ) );

		// Add required parameters (Critical Fix #1 - wait_for_update).
		$consent_state['wait_for_update'] = $wait_time;

		// Add optional parameters.
		$consent_state['ads_data_redaction'] = true;
		$consent_state['url_passthrough']    = true;

		// JSON encoding flags for XSS prevention (security hardening).
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		$json_state = wp_json_encode( $consent_state, $json_flags );

		// Fall back to a safe empty object if encoding fails (e.g., malformed UTF-8 in a filtered value).
		// Prevents a JS syntax error ("gtag('consent', 'default', );") from halting script execution.
		if ( $json_state === false ) {
			$json_state = '{}';
		}

		// Build script HTML.
		$output  = '<script data-cfasync="false" data-surecookie-gcm="default">' . "\n";
		$output .= 'window.dataLayer = window.dataLayer || [];' . "\n";
		$output .= 'function gtag(){dataLayer.push(arguments);}' . "\n";
		$output .= 'gtag(\'consent\', \'default\', ' . $json_state . ');' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already JSON-encoded with XSS flags.

		// Region-specific consent defaults (Google Consent Mode v2 region parameter).
		$output .= $this->generate_region_consent_defaults( $has_recorded, $gpc_enabled, $wait_time, $json_flags );

		$output .= '</script>';

		return $output;
	}

	/**
	 * Generate region-specific consent default calls.
	 *
	 * Uses Google's `region` parameter to set different consent defaults per region.
	 * Example: EU visitors get all-denied defaults while other regions may allow analytics.
	 *
	 * @see https://developers.google.com/tag-platform/security/guides/consent#region-specific_behavior
	 *
	 * @param bool $has_recorded_choice Whether the visitor has already recorded a consent choice.
	 * @param bool $gpc_enabled         Whether GPC is active.
	 * @param int  $wait_time           Validated wait time in ms.
	 * @param int  $json_flags          JSON encoding flags.
	 * @return string Additional gtag consent default calls for regions.
	 * @since 0.0.1-beta.1
	 */
	private function generate_region_consent_defaults( bool $has_recorded_choice, $gpc_enabled, $wait_time, $json_flags ): string {
		// If user already has consent or GPC is active, region defaults are not needed because the global default already reflects the correct state.
		if ( $gpc_enabled || $has_recorded_choice ) {
			return '';
		}

		$region_defaults = Settings::get( 'gcm_region_defaults' );

		if ( empty( $region_defaults ) || ! is_array( $region_defaults ) ) {
			return '';
		}

		// Limit to 50 rules maximum to prevent abuse.
		$region_defaults = array_slice( $region_defaults, 0, 50 );

		// Build array of prepared consent-state configs (not JS strings).
		$region_configs = [];

		foreach ( $region_defaults as $preset ) {
			// Defense-in-depth: skip non-array entries so $preset[...] offset access below never runs on a scalar/null.
			if ( ! is_array( $preset ) ) {
				continue;
			}

			if ( empty( $preset['region'] ) || ! is_array( $preset['region'] ) ) {
				continue;
			}

			// Sanitize and validate region codes.
			$regions = array_filter(
				array_map(
					static function ( $code ) {
						$code = strtoupper( trim( sanitize_text_field( $code ) ) );
						return preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $code ) ? $code : '';
					},
					$preset['region']
				)
			);

			if ( empty( $regions ) ) {
				continue;
			}

			// Limit to 10 region codes per rule.
			$regions = array_slice( $regions, 0, 10 );

			// Build preferences with strict boolean resolution (handles Sanitize::array() string round-trip).
			$preferences = [
				'essential'  => true,
				'functional' => $this->resolve_preset_bool( $preset['functional'] ?? null ),
				'analytics'  => $this->resolve_preset_bool( $preset['analytics'] ?? null ),
				'marketing'  => $this->resolve_preset_bool( $preset['marketing'] ?? null ),
			];

			$consent_state                    = $this->map_to_google_consent( $preferences );
			$consent_state['region']          = array_values( $regions );
			$consent_state['wait_for_update'] = $wait_time;

			$region_configs[] = $consent_state;
		}

		/**
		 * Filter: Allow developers to modify region-specific consent default configs.
		 *
		 * Return an array of consent-state arrays (not raw JS strings). Each element
		 * has the same shape as a gtag consent default call. The return value is
		 * JSON-encoded here with XSS flags - do NOT return JS strings.
		 *
		 * @param array<int, array<string, mixed>> $region_configs Array of per-region consent configs.
		 * @param int                              $wait_time      Validated wait time in ms.
		 * @since 0.0.1-beta.1
		 */
		$region_configs = apply_filters( 'surecookie_gcm_region_consent_defaults', $region_configs, $wait_time );

		// Bail gracefully if filter returns unexpected type.
		if ( ! is_array( $region_configs ) ) {
			return '';
		}

		$output = '';
		foreach ( $region_configs as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$json_state = wp_json_encode( $config, $json_flags );

			// Skip entries that fail to encode instead of emitting invalid JS that halts the script block.
			if ( $json_state === false ) {
				continue;
			}

			$output .= 'gtag(\'consent\', \'default\', ' . $json_state . ');' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already JSON-encoded with XSS flags.
		}

		return $output;
	}

	/**
	 * Resolve the GLOBAL consent default for a visitor with no recorded choice.
	 *
	 * `gcm_default_consent` carries the admin's per-category granted/denied
	 * choice (all denied by default). Region rules emitted after the global
	 * default always override it for their regions (gtag resolves the most
	 * specific match client-side), and a recorded choice or GPC wins over
	 * everything.
	 *
	 * @param array<string, bool> $default_denied The all-denied baseline.
	 * @since 1.3.1
	 * @return array<string, bool>
	 */
	private function global_default_preferences( array $default_denied ): array {
		$defaults = Settings::get( 'gcm_default_consent' );

		if ( ! is_array( $defaults ) ) {
			return $default_denied;
		}

		return [
			'essential'  => true,
			'functional' => $this->resolve_preset_bool( $defaults['functional'] ?? null ),
			'analytics'  => $this->resolve_preset_bool( $defaults['analytics'] ?? null ),
			'marketing'  => $this->resolve_preset_bool( $defaults['marketing'] ?? null ),
		];
	}

	/**
	 * Check if Global Privacy Control (GPC) is enabled.
	 *
	 * Critical Fix #3: GPC support for CCPA compliance.
	 *
	 * @return bool True if GPC signal detected.
	 * @since 0.0.0-alpha.1
	 */
	private function is_gpc_enabled(): bool {
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && $_SERVER['HTTP_SEC_GPC'] === '1';
	}

	/**
	 * Map SureCookie categories to Google consent parameters.
	 *
	 * @param array<string, mixed> $preferences User preferences.
	 * @return array<string, mixed> Google consent state.
	 * @since 0.0.0-alpha.1
	 */
	private function map_to_google_consent( $preferences ): array {
		$mapping = [
			'ad_storage'              => $preferences['marketing'] ? 'granted' : 'denied',
			'ad_user_data'            => $preferences['marketing'] ? 'granted' : 'denied',
			'ad_personalization'      => $preferences['marketing'] ? 'granted' : 'denied',
			'personalization_storage' => $preferences['marketing'] ? 'granted' : 'denied',
			'analytics_storage'       => $preferences['analytics'] ? 'granted' : 'denied',
			'functionality_storage'   => $preferences['functional'] ? 'granted' : 'denied',
			'security_storage'        => 'granted', // Always granted (essential).
		];

		/**
		 * Filter: Allow developers to customize category mapping.
		 *
		 * @param array $mapping    Google consent parameters.
		 * @param array $preferences User category preferences.
		 * @since 0.0.0-alpha.1
		 */
		$filtered = apply_filters( 'surecookie_google_consent_mapping', $mapping, $preferences );

		// Fall back to the original mapping if a filter returns a non-array, to avoid silent GCM misconfiguration.
		return is_array( $filtered ) ? $filtered : $mapping;
	}

	/**
	 * Validate wait time.
	 *
	 * Critical Fix #5: Input validation.
	 *
	 * @param int $value Wait time in milliseconds.
	 * @return int Validated wait time (0-2000ms).
	 * @since 0.0.0-alpha.1
	 */
	private function validate_wait_time( $value ): int {
		$int_value = absint( $value );
		return min( max( $int_value, 0 ), 2000 ); // Clamp to 0-2000ms range.
	}

	/**
	 * Check for Google Consent Mode conflicts with other plugins.
	 *
	 * Critical Fix #10: Google Site Kit conflict detection.
	 *
	 * @return bool True if conflict detected.
	 * @since 0.0.0-alpha.1
	 */
	private function has_consent_mode_conflict(): bool {
		// Pure detection (no side effects): Site Kit present AND its Consent Mode
		// enabled. Callers act on the result - the frontend skips injecting our
		// script; the admin conflict notice is wired separately in the constructor
		// (previously an add_action here, which never fired since this only runs
		// during frontend output buffering).
		if ( class_exists( 'Google\Site_Kit\Core\Consent_Mode\Consent_Mode' ) ) {
			$sitekit_options = get_option( 'googlesitekit_consent_mode', [] );
			return ! empty( $sitekit_options['enabled'] );
		}

		return false;
	}

	/**
	 * Resolve a preset boolean value from mixed input.
	 *
	 * Handles both native PHP booleans and the string representations
	 * produced by Sanitize::array() ("1" for true, "" for false).
	 *
	 * @param mixed $value Raw preset value.
	 * @return bool Resolved boolean.
	 * @since 0.0.1-beta.1
	 */
	private function resolve_preset_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return $value === '1' || $value === 1;
	}
}
