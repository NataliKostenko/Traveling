<?php
/**
 * SureCookie Analytics - BSF Analytics Integration
 *
 * @package SureCookie\Admin
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Admin;

use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\AssistedScan\Telemetry as AssistedScanTelemetry;
use SureCookie\Inc\Modules\Auth\Controller as AuthController;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;
use SureCookie\Inc\Modules\SiteScanner\Utils as ScannerUtils;
use SureCookie\Inc\Traits\GetInstance;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics class.
 *
 * Handles BSF Analytics integration: event tracking, stats payload, and KPI collection.
 *
 * @since 0.0.1-beta.1
 */
class Analytics {
	use GetInstance;

	/**
	 * Analytics events schema version. Bump whenever an existing event's shape
	 * (value or properties) changes, and add a `<new-version> => [ 'event' ]`
	 * entry to RESHAPED_EVENTS_BY_VERSION so the installed base re-emits it once.
	 * v1 = original schema.
	 *
	 * @since 1.2.0
	 */
	private const ANALYTICS_EVENTS_VERSION = 2;

	/**
	 * Events reshaped in each schema version, keyed by the version that introduced
	 * the change. The migration re-emits the events for every version a site is
	 * newly crossing, so each entry fires exactly once per site and older entries
	 * are never replayed - nothing here needs to be removed on a future bump.
	 *
	 * @since 1.2.0
	 */
	private const RESHAPED_EVENTS_BY_VERSION = [
		2 => [ 'banner_configured' ],
	];

	/**
	 * Events tracker.
	 *
	 * @var \BSF_Analytics_Events|null
	 */
	private static $events_tracker = null;

	/**
	 * Plugin version recorded before this request, captured on `plugins_loaded`
	 * (before Maintenance updates `surecookie_saved_version` on `admin_init`).
	 * Lets detect_state_events() identify upgrades even though it now runs on
	 * `admin_init`.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $pre_upgrade_version = '';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	public function __construct() {
		// Stats payload filter.
		add_filter( 'bsf_core_stats', [ $this, 'add_analytics_data' ] );

		// Only run analytics in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Load Astra Notices for opt-in UI.
		if ( ! class_exists( 'BSF_Admin_Notices' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/astra-notices/class-bsf-admin-notices.php';
		}

		add_filter(
			'uds_survey_allowed_screens',
			static function () {
				return [ 'plugins' ];
			}
		);

		// Load BSF Analytics library.
		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-loader.php';
		}

		if ( ! class_exists( 'BSF_Analytics_Loader' ) ) {
			return;
		}

		/** @var \BSF_Analytics_Loader $loader */
		$loader = \BSF_Analytics_Loader::get_instance();
		// Upstream docblock types $data as string, but the implementation pushes it onto an array of entity configs - see class-bsf-analytics-loader.php::set_entity().

		$deactivation_surveys = [
			[
				'id'                => 'deactivation-survey-surecookie',
				'popup_logo'        => SURECOOKIE_URL . 'assets/images/surecookie--brand-colored.svg',
				'plugin_slug'       => 'surecookie',
				'popup_title'       => 'Quick Feedback',
				'support_url'       => Helper::get_marketing_link( 'contact/', 'deactivation_survey_support' ),
				'popup_description' => 'If you have a moment, please share why you are deactivating SureCookie:',
				'show_on_screens'   => [ 'plugins', 'plugins-network' ],
				'plugin_version'    => SURECOOKIE_VERSION,
			],
		];

		// Capture Pro deactivations too when Pro is active.
		if ( defined( 'SURECOOKIE_PRO_VERSION' ) ) {
			$deactivation_surveys[] = [
				'id'                => 'deactivation-survey-surecookie-pro',
				'popup_logo'        => SURECOOKIE_URL . 'assets/images/surecookie--brand-colored.svg',
				'plugin_slug'       => 'surecookie-pro',
				'popup_title'       => 'Quick Feedback',
				'support_url'       => Helper::get_marketing_link( 'contact/', 'deactivation_survey_support' ),
				'popup_description' => 'If you have a moment, please share why you are deactivating SureCookie premium version:',
				'show_on_screens'   => [ 'plugins', 'plugins-network' ],
				'plugin_version'    => SURECOOKIE_PRO_VERSION,
			];
		}

		$loader->set_entity(
			[ // @phpstan-ignore argument.type
				'surecookie' => [
					'product_name'        => 'SureCookie',
					'path'                => SURECOOKIE_DIR . 'inc/lib/bsf-analytics',
					'author'              => 'Brainstorm Force',
					'time_to_display'     => '+24 hours',
					// IMPORTANT: Must be array of arrays - library iterates and passes each to show_feedback_form().
					'deactivation_survey' => apply_filters( 'surecookie_deactivation_survey_data', $deactivation_surveys ),
				],
			]
		);

		// Detect version change vs `surecookie_saved_version` (owned by Maintenance), before
		// the daily throttle gate so an update is never missed. Note: a frontend-first upgrade
		// or BSF_Analytics_Events load failure may skip `plugin_updated` - accepted tradeoff.
		$saved_version             = get_option( 'surecookie_saved_version', '' );
		$this->pre_upgrade_version = is_string( $saved_version ) ? $saved_version : '';
		if ( ! empty( $saved_version ) && $saved_version !== SURECOOKIE_VERSION ) {
			delete_transient( 'surecookie_state_events_checked' );
		}

		// One-time re-emit of reshaped events for the installed base (runs before
		// the throttle gate so it can bust the transient and re-queue this load).
		$this->maybe_migrate_events_schema();

		// State-based events, throttled once per day. Deferred to `admin_init` (not inline on
		// `plugins_loaded`) so add-ons registering a `surecookie_detect_state_events` listener
		// on `init` are attached before detection fires its extension hook.
		add_action( 'admin_init', [ $this, 'maybe_detect_state_events' ] );
	}

	// ============================================
	// Event Tracker
	// ============================================

	/**
	 * Get shared event tracker instance.
	 *
	 * @since 0.0.1-beta.1
	 * @return \BSF_Analytics_Events|null
	 */
	public static function events() {
		if ( ! class_exists( 'BSF_Analytics_Events' ) ) {
			require_once SURECOOKIE_DIR . 'inc/lib/bsf-analytics/class-bsf-analytics-events.php';
		}

		if ( self::$events_tracker === null ) {
			self::$events_tracker = new \BSF_Analytics_Events( 'surecookie' );
		}

		return self::$events_tracker;
	}

	// ============================================
	// Stats Payload
	// ============================================

	/**
	 * Add SureCookie analytics data to the BSF core stats payload.
	 *
	 * @param array<string, mixed> $stats_data Existing stats data.
	 * @return array<string, mixed> Modified stats data.
	 * @since 0.0.1-beta.1
	 */
	public function add_analytics_data( $stats_data ) {
		$events = self::events();

		$stats_data['plugin_data']['surecookie'] = [
			'free_version'  => SURECOOKIE_VERSION,
			'site_language' => get_locale(),

			// One-time events (flushed from pending queue).
			'events_record' => $events ? $events->flush_pending() : [],

			// Daily KPIs (last 2 days).
			'kpi_records'   => $this->get_kpi_tracking_data(),

			// Get some total important data.
			'total_scans'   => $this->get_total_scans(),
			'total_logs'    => (int) Settings::get( 'total_logs' ),
		];

		return $stats_data;
	}

	// ============================================
	// State Event Detection
	// ============================================

	/**
	 * Run state-event detection once per day (throttle gate).
	 *
	 * Hooked to `admin_init` so add-on listeners registered during component load
	 * (on `init`) are attached before detect_state_events() fires its hook.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_detect_state_events(): void {
		if ( get_transient( 'surecookie_state_events_checked' ) === false ) {
			$this->detect_state_events();
		}
	}

	/**
	 * Re-emit reshaped events once per analytics schema-version bump.
	 *
	 * When the stored schema version is older than the code's, the affected
	 * events are removed from the pushed dedup list and the throttle transient is
	 * cleared so detect_state_events() re-queues them with their new shape on this
	 * same load. Idempotent: a no-op once the stored version matches.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function maybe_migrate_events_schema(): void {
		$stored = (int) get_option( 'surecookie_analytics_events_version', 0 );

		// Collect events reshaped in every version newer than the stored one. Already-migrated
		// versions are skipped, so an event never replays - nothing to prune on a future bump.
		$to_flush = [];
		foreach ( self::RESHAPED_EVENTS_BY_VERSION as $version => $event_names ) {
			if ( $version > $stored ) {
				$to_flush = array_merge( $to_flush, $event_names );
			}
		}

		if ( $to_flush === [] ) {
			return; // Already up to date - nothing to re-emit.
		}

		$events = self::events();

		if ( $events === null ) {
			// Tracker class not loaded yet - retry next load without bumping.
			return;
		}

		// flush_pushed() with names re-opens only those events; the early return
		// above guarantees we never pass [] (which would clear ALL dedup).
		$events->flush_pushed( array_values( array_unique( $to_flush ) ) );
		delete_transient( 'surecookie_state_events_checked' );

		update_option( 'surecookie_analytics_events_version', self::ANALYTICS_EVENTS_VERSION, false );
	}

	/**
	 * Detect and queue state-based events on admin page load.
	 *
	 * Runs on every admin load but throttled by a daily transient.
	 * BSF_Analytics_Events dedup prevents duplicate tracking.
	 *
	 * @since 0.0.1-beta.1
	 * @return void
	 */
	private function detect_state_events(): void {
		$events = self::events();

		if ( $events === null ) {
			// BSF_Analytics_Events class not loaded - do NOT set transient; retry next load.
			return;
		}

		// Class is available - set throttle transient so we don't re-run for 24h.
		set_transient( 'surecookie_state_events_checked', 1, DAY_IN_SECONDS );

		// ── 1. plugin_activated ──────────────────────────────────────────
		$install_time = get_option( 'surecookie_usage_installed_time', 0 );
		if ( ! $install_time ) {
			update_option( 'surecookie_usage_installed_time', time(), false );
		}

		$bsf_referrers = get_option( 'bsf_product_referers', [] );
		$source        = ! empty( $bsf_referrers['surecookie'] )
			? sanitize_text_field( $bsf_referrers['surecookie'] )
			: 'self';

		// Emit a fixed flag as the event_value, carry the version in properties['version'].
		// Keeps the KPI breakdown one row per event, not one per release (@since 1.2.4).
		$events->track(
			'plugin_activated',
			'activated',
			[
				'version' => SURECOOKIE_VERSION,
				'source'  => $source,
			]
		);

		// ── 2. plugin_updated ────────────────────────────────────────────
		// Use the version captured on `plugins_loaded` (before Maintenance::init updates
		// `surecookie_saved_version` on `admin_init`), preserving the pre-upgrade version.
		$saved_version = $this->pre_upgrade_version;
		if ( $saved_version !== SURECOOKIE_VERSION && ! empty( $saved_version ) ) {
			$events->flush_pushed( [ 'plugin_updated' ] );
			$events->track(
				'plugin_updated',
				'updated',
				[
					'from_version' => $saved_version,
					'to_version'   => SURECOOKIE_VERSION,
				]
			);
		}

		// ── user_active_version (recurring version heartbeat) ────────────
		// Reports the version each ACTIVE site is on. $force re-queues it every cycle so the
		// latest version wins and it survives the one-time dedup (like plugin_updated). The
		// one event whose value stays the version by design - the KPI breakdown then shows
		// active installs per release (@since 1.2.4).
		$events->track( 'user_active_version', SURECOOKIE_VERSION, [], true );

		// ── 3. onboarding_completed ──────────────────────────────────────
		if ( get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) ) {
			$events->track( 'onboarding_completed', 'completed', [ 'version' => SURECOOKIE_VERSION ] );
		}

		// ── 4. onboarding_skipped ────────────────────────────────────────
		// Fires when onboarding has not been completed after 3+ days since install.
		$days_since_install = $this->get_days_since_install();
		if ( ! get_option( SURECOOKIE_ONBOARDING_COMPLETED_OPTION, false ) && $days_since_install >= 3 ) {
			$events->track(
				'onboarding_skipped',
				'skipped',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
				]
			);
		}

		// ── 5. banner_configured ─────────────────────────────────────────
		// Fires when admin settings have been saved at least once. Carries the compliance
		// law (always set; default GDPR) to capture the GDPR/CCPA/LGPD distribution here.
		if ( get_option( SURECOOKIE_SETTINGS_OPTION ) !== false ) {
			$compliance_law = Settings::get( 'compliance_law' );
			$law_name       = is_array( $compliance_law ) ? ( $compliance_law['name'] ?? '' ) : '';
			$events->track(
				'banner_configured',
				'configured',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
					'compliance_law'     => (string) $law_name,
				]
			);
		}

		// ── 6. script_blocking_disabled ──────────────────────────────────
		// `blocking_enabled` defaults to true, so an "enabled" event fires for nearly every
		// site. The meaningful signal is the rare cohort that turns blocking OFF.
		if ( ! (bool) Settings::get( 'blocking_enabled' ) ) {
			$events->track( 'script_blocking_disabled', 'disabled' );
		}

		// ── 7. first_scan_started ────────────────────────────────────────
		if ( get_option( 'surecookie_first_scan_started_flag', false ) ) {
			$pages_count = (int) get_option( 'surecookie_first_scan_pages_count', 0 );
			$events->track(
				'first_scan_started',
				'started',
				[
					'version'     => SURECOOKIE_VERSION,
					'pages_count' => (string) $pages_count,
				]
			);
		}

		// ── 8. first_scan_completed (ACTIVATION EVENT) ───────────────────
		if ( get_option( 'surecookie_first_scan_completed_flag', false ) ) {
			$pages_scanned = (int) get_option( 'surecookie_first_scan_pages_scanned', 0 );
			$events->track(
				'first_scan_completed',
				'completed',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
					'pages_scanned'      => (string) $pages_scanned,
				]
			);
		}

		// ── 9. first_consent_recorded ────────────────────────────────────
		// Fires when the first real visitor consent is stored in the database.
		$first_consent = Settings::get( 'total_logs' );
		if ( $first_consent > 0 ) {
			$events->track(
				'first_consent_recorded',
				'recorded',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
				]
			);
		}

		// ── 10. consent_logging_enabled ──────────────────────────────────
		if ( (bool) Settings::get( 'consent_logging_enabled' ) ) {
			$events->track( 'consent_logging_enabled', 'enabled' );
		}

		// ── 11. first_custom_cookie_added ────────────────────────────────
		// Fires when the user has manually added at least one custom cookie.
		$custom_cookies = Settings::get( 'custom_cookies' );
		if ( ! empty( $custom_cookies ) && is_array( $custom_cookies ) ) {
			$events->track(
				'first_custom_cookie_added',
				'added',
				[ 'count' => (string) count( $custom_cookies ) ]
			);
		}

		// ── 12. upgrade_banner_dismissed ─────────────────────────────────
		// Fires once the user dismissed the pro upgrade nudge at least once. Key off the
		// count: the nudge's `display` flag stays true until the 2nd dismissal.
		$nudges = get_option( SURECOOKIE_NUDGES, [] );
		if ( ! empty( $nudges['upgrade_banner']['count'] ) ) {
			$events->track(
				'upgrade_banner_dismissed',
				'dismissed',
				[ 'count' => (string) $nudges['upgrade_banner']['count'] ]
			);
		}

		// ── 13. consent_log_report_nudge_dismissed ───────────────────────
		// Symmetric to the upgrade nudge - the second registered nudge type.
		if ( ! empty( $nudges['consent_log_report']['count'] ) ) {
			$events->track(
				'consent_log_report_nudge_dismissed',
				'dismissed',
				[ 'count' => (string) $nudges['consent_log_report']['count'] ]
			);
		}

		// ── 14. google_consent_mode_enabled ──────────────────────────────
		if ( (bool) Settings::get( 'gcm_enabled' ) ) {
			$events->track( 'google_consent_mode_enabled', 'enabled' );
		}

		// ── 15. account_connected ────────────────────────────────────────
		// Fires once the site has linked its SureCookie SaaS account (required for
		// cloud scanning). `tier` segments this near-universal event by plan.
		if ( AuthController::get_instance()->get_account_ref() !== null ) {
			$events->track(
				'account_connected',
				'connected',
				[ 'tier' => (string) ScannerUtils::get_plan() ]
			);
		}

		// ── 16. auto_scanning_enabled ────────────────────────────────────
		if ( (bool) Settings::get( 'auto_scan_enabled' ) ) {
			$events->track(
				'auto_scanning_enabled',
				'enabled',
				[ 'frequency' => (string) Settings::get( 'auto_scan_frequency' ) ]
			);
		}

		// ── 17. first_auto_scan_started ──────────────────────────────────
		// Fires once the scheduler has actually run an automatic scan (the
		// realized-value milestone - vs auto_scanning_enabled, which is intent).
		if ( get_option( 'surecookie_first_auto_scan_started_flag', false ) ) {
			$events->track(
				'first_auto_scan_started',
				'started',
				[
					'version'   => SURECOOKIE_VERSION,
					'frequency' => (string) get_option( 'surecookie_first_auto_scan_frequency', '' ),
				]
			);
		}

		// ── 18. mcp_server_enabled ───────────────────────────────────────
		if ( (bool) Settings::get( 'enable_mcp' ) ) {
			$events->track( 'mcp_server_enabled', 'enabled' );
		}

		// ── 19. opt_out_model_enabled ────────────────────────────────────
		if ( Settings::get( 'consent_model' ) === 'opt-out' ) {
			$events->track( 'opt_out_model_enabled', 'opt-out' );
		}

		// ── 20. cookie_policy_page_configured ────────────────────────────
		// Emit a fixed 'configured' flag, never the page ID. The value only needs to signal
		// a policy page is assigned; the raw ID made every site a distinct event_value,
		// flooding the KPI breakdown with one row per ID (@since 1.2.4).
		if ( (int) Settings::get( 'cookie_policy_page_id' ) > 0 ) {
			$events->track( 'cookie_policy_page_configured', 'configured' );
		}

		// ── 21. custom_css_applied ───────────────────────────────────────
		if ( trim( (string) Settings::get( 'custom_css' ) ) !== '' ) {
			$events->track( 'custom_css_applied', 'applied' );
		}

		// ── 22. reconsent_button_configured ──────────────────────────────
		// Keyed off the assigned menu - the button label is always defaulted.
		if ( (string) Settings::get( 'reconsent_menu_id' ) !== '' ) {
			$events->track( 'reconsent_button_configured', 'configured' );
		}

		// ── 23. banner_customized ────────────────────────────────────────
		// Fires when the banner's visual configuration diverges from defaults.
		$banner_signals = [];
		if ( (string) Settings::get( 'banner_logo' ) !== '' ) {
			$banner_signals[] = 'logo';
		}
		if ( Settings::get( 'banner_animation' ) !== 'fade' ) {
			$banner_signals[] = 'animation';
		}
		if ( (bool) Settings::get( 'banner_overlay_enabled' ) ) {
			$banner_signals[] = 'overlay';
		}
		if ( Settings::get( 'notice_type' ) !== 'banner' ) {
			$banner_signals[] = 'notice_type';
		}
		if ( ! empty( $banner_signals ) ) {
			$events->track(
				'banner_customized',
				'customized',
				[ 'signals' => implode( ',', $banner_signals ) ]
			);
		}

		// ── 24. cloud_scan_blocked ───────────────────────────────────────
		// The site's host served our scanner a challenge instead of the page. Across the
		// installed base this measures how badly scanner reachability needs fixing - and
		// it's why Assisted Scan exists, so it's tracked whether or not the fallback was used.
		if ( get_option( SaasClient::BLOCKED_FLAG_OPTION, false ) ) {
			$events->track(
				'cloud_scan_blocked',
				'blocked',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
				]
			);
		}

		// ── 25. assisted_scan_started ────────────────────────────────────
		// Reached for the browser-collected fallback at least once.
		if ( get_option( AssistedScanTelemetry::STARTED_FLAG, false ) ) {
			$events->track(
				'assisted_scan_started',
				'started',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
				]
			);
		}

		// ── 26. assisted_scan_completed ──────────────────────────────────
		// The recovery actually worked. `registered` separates the failure severities (an
		// unregistered site could never scan; a registered one merely had a scan blocked).
		// `adblock_suspected` flags known-incomplete results so they don't inflate success.
		if ( get_option( AssistedScanTelemetry::COMPLETED_FLAG, false ) ) {
			$stats = get_option( AssistedScanTelemetry::STATS_OPTION, [] );
			$stats = is_array( $stats ) ? $stats : [];

			$events->track(
				'assisted_scan_completed',
				'completed',
				[
					'version'            => SURECOOKIE_VERSION,
					'days_since_install' => (string) $days_since_install,
					'pages_walked'       => (string) (int) ( $stats['pages'] ?? 0 ),
					'cookies_found'      => (string) (int) ( $stats['cookies'] ?? 0 ),
					'services_found'     => (string) (int) ( $stats['services'] ?? 0 ),
					'adblock_suspected'  => empty( $stats['adblock_suspected'] ) ? 'no' : 'yes',
					'registered'         => empty( $stats['registered'] ) ? 'no' : 'yes',
				]
			);
		}

		// ── 27. assisted_scan_abandoned ──────────────────────────────────
		// A walk that stopped reporting and had to be closed out by the rescue pass, not
		// finished by the browser. The cohort to watch if the walk asks too much of people.
		if ( get_option( AssistedScanTelemetry::ABANDONED_FLAG, false ) ) {
			$events->track(
				'assisted_scan_abandoned',
				'abandoned',
				[ 'version' => SURECOOKIE_VERSION ]
			);
		}

		/**
		 * Fires after SureCookie has queued its own state-based events, letting
		 * add-ons (e.g. SureCookie Pro) push their events through the shared
		 * tracker. Runs once per throttled detection cycle.
		 *
		 * @since 1.2.0
		 * @param \BSF_Analytics_Events $events             Shared event tracker.
		 * @param int                   $days_since_install Days since plugin install.
		 */
		do_action( 'surecookie_detect_state_events', $events, $days_since_install );
	}

	// ============================================
	// KPI Tracking
	// ============================================

	/**
	 * Get KPI tracking data for the last 2 days (excluding today).
	 *
	 * @since 0.0.1-beta.1
	 * @return array<string, array<string, array<string, int>>> KPI records keyed by date.
	 */
	private function get_kpi_tracking_data(): array {
		$kpi_records = [];

		for ( $i = 1; $i <= 2; $i++ ) {
			$date = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );

			if ( ! $date ) {
				continue;
			}

			$kpi_records[ $date ] = [
				'numeric_values' => [
					'consent_logs' => $this->get_daily_consent_log_count( $date ),
				],
			];
		}

		return $kpi_records;
	}

	/**
	 * Get daily consent log entry count for a specific date.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @since 0.0.1-beta.1
	 * @return int Count of consent log entries for that date.
	 */
	private function get_daily_consent_log_count( string $date ): int {
		global $wpdb;

		$table = $wpdb->prefix . SURECOOKIE_CONSENT_LOG_DB;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE DATE(timestamp) = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);
	}

	// ============================================
	// Helpers
	// ============================================

	/**
	 * Get number of days since plugin installation.
	 *
	 * @since 0.0.1-beta.1
	 * @return int Days since install (0 if unknown).
	 */
	private function get_days_since_install(): int {
		$install_time = (int) get_option( 'surecookie_usage_installed_time', 0 );

		if ( $install_time <= 0 ) {
			return 0;
		}

		return (int) floor( ( time() - $install_time ) / DAY_IN_SECONDS );
	}

	/**
	 * Get total scans from the database.
	 *
	 * @since 0.0.1-beta.1
	 * @return int Total number of scans.
	 */
	private function get_total_scans(): int {
		$option = get_option(
			SURECOOKIE_SCANNED_DETAILS_OPTION,
			[
				'total_scans' => 0,
			]
		);

		return isset( $option['total_scans'] ) ? (int) $option['total_scans'] : 0;
	}
}
