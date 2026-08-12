<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all plugin data if the user has opted in via
 * Settings > General Settings > "Delete Data on Uninstall".
 *
 * IMPORTANT: This file runs WITHOUT the plugin loaded.
 * No constants, classes, or autoloader are available.
 * All option keys are hardcoded and must be kept in sync
 * with SURECOOKIE_* constants in surecookie.php and runtime option keys.
 *
 * @link       https://surecookie.com
 * @since      0.0.1
 *
 * @package    SureCookie
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Check if the user has opted in to data deletion.
 *
 * @return bool
 */
function surecookie_should_delete_data() {
	$settings = get_option( 'surecookie_settings', [] );
	if ( ! is_array( $settings ) ) {
		return false;
	}
	return isset( $settings['delete_data_on_uninstall'] )
		&& $settings['delete_data_on_uninstall'] === true;
}

/*
 * Deletion is checked per-site (not globally) so each multisite sub-site's own
 * preference is respected. See surecookie_should_delete_data() usage below.
 */

/**
 * All known option keys created by SureCookie.
 *
 * Sync with: SURECOOKIE_* constants in surecookie.php + runtime option keys.
 *
 * @return array<string>
 */
function surecookie_get_option_keys() {
	return [
		// Defined as constants in surecookie.php.
		'surecookie_settings',
		'surecookie_onboarding_user_details',
		'surecookie_onboarding_completed',
		'surecookie_scanned_details',
		'surecookie_scanned_cookies',
		'surecookie_scanned_logs',
		'surecookie_scanned_resources',
		'surecookie_cookie_category_memory',
		'surecookie_nudges',
		// Runtime options (not constants).
		'surecookie_do_activation_redirect',
		'surecookie_cron_test_ok',
		'surecookie_active_scan',
		'surecookie_saved_version',
		// BSF Analytics options.
		'surecookie_usage_optin',
		'surecookie_usage_installed_time',
		'surecookie_tracked_version',
		'surecookie_first_scan_started_flag',
		'surecookie_first_scan_pages_count',
		'surecookie_first_scan_completed_flag',
		'surecookie_first_scan_pages_scanned',
		// Assisted Scan analytics flags (Modules\AssistedScan\Telemetry) + blocked-scanner
		// flag (SaasClient::BLOCKED_FLAG_OPTION). No autoloader - keep literals in sync.
		'surecookie_assisted_scan_started_flag',
		'surecookie_assisted_scan_completed_flag',
		'surecookie_assisted_scan_abandoned_flag',
		'surecookie_assisted_scan_stats',
		'surecookie_cloud_scan_blocked_flag',
		// Assisted Scan in-flight session state (Modules\AssistedScan\Session).
		'surecookie_assisted_scan',
		// Issue #473 - scanner SaaS credentials.
		'surecookie_site_credentials',
		'surecookie_last_known_quota',
		// Issue #742 - connected billing-portal account (Auth\Controller::SETTINGS_KEY).
		'surecookie_auth',
		'surecookie_onboarding_lead',
		'surecookie_first_successful_scan',
		'surecookie_db_version',
		// Migration completion ledger (Core\Maintenance::LEDGER_OPTION). A single
		// row covering every migration, so adding a migration never adds a key here.
		'surecookie_migrations',
		// Pre-ledger per-migration flags (Maintenance::LEGACY_FLAGS). Deleted during ledger
		// adoption on the first upgraded run; listed here for a site that uninstalls before
		// ever upgrading. Safe to drop ~two releases after the ledger ships.
		'surecookie_consent_log_unique_key_migrated',
		'surecookie_services_backfill_v1',
		'surecookie_cookie_provider_backfill_v1',
		'surecookie_cookie_category_memory_backfill_v1',
		// Known Services installed registry (Modules\Services\Installed_Services).
		'surecookie_installed_services',
		// BSF Analytics event queue + schema version.
		'surecookie_analytics_events_version',
		'surecookie_usage_events_pending',
		'surecookie_usage_events_pushed',
		'surecookie_first_auto_scan_frequency',
		'surecookie_first_auto_scan_started_flag',
		// Pro activation redirect.
		'surecookie_pro_redirect_on_activation',
	];
}

/**
 * Delete all plugin options for the current site.
 *
 * @return void
 */
function surecookie_delete_options(): void {
	foreach ( surecookie_get_option_keys() as $option ) {
		delete_option( $option );
	}

	// Per-user "last viewed consent log" pointers are stored as
	// surecookie_last_viewed_consent_log_<user_id>; the user-id suffix is unknowable in
	// advance, so delete by prefix (specific enough to never match Pro's keys).
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'surecookie_last_viewed_consent_log_' ) . '%'
		)
	);
}

/**
 * Delete all plugin transients for the current site.
 *
 * @return void
 */
function surecookie_delete_transients(): void {
	delete_transient( 'surecookie_services' );
	// Legacy key from the pre-refactor known-scripts cache; kept so an uninstall
	// after upgrading from an older version still clears any lingering row.
	delete_transient( 'surecookie_known_scripts' );
	delete_transient( 'surecookie_geo_cache' );
	delete_transient( 'surecookie_state_events_checked' );
	// Issue #473 - scanner registration / verification transients.
	delete_transient( 'surecookie_registering_site' );
	delete_transient( 'surecookie_pending_verification' );
	// Mirror of SaasClient::SCAN_BYPASS_TRANSIENT_KEY (no autoloader here).
	delete_transient( 'surecookie_scan_bypass_token' );
	delete_transient( 'surecookie_scan_quota' );
	// Automatic-scanning runner state (Runner::ACTIVE_TRANSIENT / LOCK_TRANSIENT).
	delete_transient( 'surecookie_auto_scan_active' );
	delete_transient( 'surecookie_auto_scan_lock' );
	// Google Consent Mode service detection cache (ServiceDetector::TRANSIENT_KEY).
	delete_transient( 'surecookie_gcm_services_detected' );
	// Migration runner lock + schema-upgrade backoff (Core\Maintenance).
	delete_transient( 'surecookie_migrations_lock' );
	delete_transient( 'surecookie_db_upgrade_backoff' );
	// The schema backoff is network-scoped on multisite (Maintenance::maybe_upgrade_db).
	if ( is_multisite() ) {
		delete_site_transient( 'surecookie_db_upgrade_backoff' );
	}
}

/**
 * Unschedule all plugin cron events for the current site.
 *
 * @return void
 */
function surecookie_unschedule_crons(): void {
	wp_clear_scheduled_hook( 'surecookie_cleanup_consent_logs' );
	wp_clear_scheduled_hook( 'surecookie_poll_scan_status' );
	// Automatic-scanning events (Scheduler::RUN_HOOK / Runner::RETRY_HOOK).
	wp_clear_scheduled_hook( 'surecookie_auto_scan_run' );
	wp_clear_scheduled_hook( 'surecookie_auto_scan_retry' );
	// Services-dataset daily refresh (Services\Cron::REFRESH_HOOK).
	wp_clear_scheduled_hook( 'surecookie_refresh_datasets' );
	// First-party loopback service detection (SaaSClient::FIRST_PARTY_DETECT_HOOK).
	wp_clear_scheduled_hook( 'surecookie_first_party_detect' );
}

/**
 * Drop the consent log table for the current site.
 *
 * @return void
 */
function surecookie_drop_tables(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecookie_consent_log" );
}

/**
 * Delete the file cache directory (wp-content/uploads/surecookie/).
 *
 * @return void
 */
function surecookie_delete_cache_files(): void {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'surecookie';

	if ( ! is_dir( $cache_dir ) ) {
		return;
	}

	// Use WP_Filesystem_Direct explicitly - WP_Filesystem() can silently
	// fail on FTP/SSH servers where credentials are unavailable at uninstall.
	if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	}
	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	$filesystem = new WP_Filesystem_Direct( [] );
	$filesystem->delete( $cache_dir, true );
}

/**
 * Run all cleanup for a single site.
 *
 * @return void
 */
function surecookie_uninstall_site(): void {
	surecookie_delete_options();
	surecookie_delete_transients();
	surecookie_unschedule_crons();
	surecookie_drop_tables();
	surecookie_delete_cache_files();
}

/*
 * Main execution - multisite-aware cleanup.
 */
if ( is_multisite() ) {
	global $wpdb;

	// Get all active (non-archived, non-spam, non-deleted) site IDs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$blog_ids = $wpdb->get_col(
		"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = '0' AND spam = '0' AND deleted = '0'"
	);

	if ( is_array( $blog_ids ) ) {
		// An empty list means nothing was cleaned, so the network row must stay.
		$all_sites_cleaned = ! empty( $blog_ids );

		foreach ( $blog_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			if ( surecookie_should_delete_data() ) {
				surecookie_uninstall_site();
			} else {
				$all_sites_cleaned = false;
			}
			restore_current_blog();
		}

		/*
		 * surecookie_db_version is stored with update_network_option() on multisite (see
		 * Maintenance::store_db_version), so the per-blog delete_option() above never removes
		 * it. Only drop the network row once every site is cleaned - else a site that opted
		 * out loses its schema-version tracking.
		 */
		if ( $all_sites_cleaned ) {
			delete_network_option( null, 'surecookie_db_version' );
		}
	}
} elseif ( surecookie_should_delete_data() ) {
	surecookie_uninstall_site();
}
