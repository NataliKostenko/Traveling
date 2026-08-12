<?php
/**
 * Utils Script Blocking.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Modules\SiteScanner\SaasClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils
 *
 * @since 0.0.1
 */
class Utils {
	/**
	 * Check whether script and content blocking feature is enabled.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_blocking_enabled(): bool {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$banner_enabled  = (bool) Settings::get( 'banner_enabled' );
		$feature_enabled = (bool) Settings::get( 'blocking_enabled' );
		$status          = $banner_enabled && $feature_enabled;

		$cached = (bool) apply_filters( 'surecookie_is_blocking_enabled', $status );

		return $cached;
	}

	/**
	 * Check if blocking should be processed based on geo-location rules.
	 *
	 * @since 0.0.1
	 * @return bool True if blocking should proceed, false to bypass.
	 */
	public static function should_process_based_on_geo(): bool {
		return (bool) apply_filters( 'surecookie_should_process_blocking_geo', true );
	}

	/**
	 * Whether this request is the SaaS scanner presenting a valid bypass token.
	 *
	 * Lives here rather than in the Blocker because gating happens in more than
	 * one place: the Blocker rewrites the output buffer, while integrations such
	 * as Presto Player replace their markup in `render_block`, well before the
	 * buffer is filtered. Any code that hides a third-party resource must honour
	 * the same bypass, otherwise a scan sees a consent placeholder instead of the
	 * embed and the resource is never detected.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_scan_bypass_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict regex below rejects anything but [0-9a-f]{64}.
		$header_value = $_SERVER['HTTP_X_SURECOOKIE_SCAN'] ?? '';

		if ( ! is_string( $header_value ) || ! preg_match( '/^[0-9a-f]{64}$/i', $header_value ) ) {
			return false;
		}

		$stored_token = get_transient( SaasClient::SCAN_BYPASS_TRANSIENT_KEY );

		// Fail closed if the transient is corrupted or returns a non-string (object cache edge cases).
		if ( ! is_string( $stored_token ) || ! preg_match( '/^[0-9a-f]{64}$/i', $stored_token ) ) {
			return false;
		}

		return hash_equals( $stored_token, $header_value );
	}
}
