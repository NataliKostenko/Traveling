<?php
/**
 * Scan-Detected Scripts Merger.
 *
 * Merges scan-detected third-party resources into the known scripts
 * database via the surecookie_known_scripts filter.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.0-alpha.2
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scan_Scripts
 *
 * Hooks into the known_scripts filter to merge scan-detected resources.
 *
 * @since 0.0.0-alpha.2
 */
class Scan_Scripts {
	use GetInstance;

	/**
	 * Cached scanned resources data.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached_resources = null;

	/**
	 * Cached excluded domains list (avoids repeated DB reads on every script/iframe).
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $cached_excluded = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private function __construct() {
		add_filter( 'surecookie_known_scripts', [ $this, 'merge_scan_detected_resources' ], 20 );

		// Skip blocking for scripts/iframes whose src matches an excluded domain.
		// Kind-specific callbacks so a "script"-scoped exclusion never skips an
		// iframe on the same host, and vice versa.
		add_filter( 'surecookie_skip_script', [ $this, 'should_skip_excluded_script' ], 10, 2 );
		add_filter( 'surecookie_skip_iframe', [ $this, 'should_skip_excluded_iframe' ], 10, 2 );
	}

	/**
	 * Merge scan-detected resources into the known scripts dataset.
	 *
	 * @param array<string, array<string, mixed>> $scripts Known scripts by category.
	 * @since 0.0.0-alpha.2
	 * @return array<string, array<string, mixed>> Merged scripts.
	 */
	public function merge_scan_detected_resources( array $scripts ): array {
		$resources = $this->get_scanned_resources();

		if ( empty( $resources ) ) {
			return $scripts;
		}

		$excluded_domains = $this->get_excluded_domains();

		// Build a flat list of all existing patterns to avoid duplicates.
		$existing_patterns = $this->build_existing_pattern_index( $scripts );

		// Merge scan-detected scripts.
		foreach ( $resources['scripts'] ?? [] as $resource ) {
			$this->merge_resource( $scripts, $resource, 'scripts', $excluded_domains, $existing_patterns );
		}

		// Merge scan-detected iframes.
		foreach ( $resources['iframes'] ?? [] as $resource ) {
			$this->merge_resource( $scripts, $resource, 'iframes', $excluded_domains, $existing_patterns );
		}

		return $scripts;
	}

	/**
	 * Clear the static cache (useful after scan results are updated).
	 *
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	public static function clear_cache(): void {
		self::$cached_resources = null;
		self::$cached_excluded  = null;
	}

	/**
	 * `surecookie_skip_script` callback: skip a script whose src matches a
	 * script-scoped (or legacy bare-domain) exclusion.
	 *
	 * @since 1.3.0
	 * @param bool   $skip Whether the resource is already marked to skip.
	 * @param string $src  The script src.
	 * @return bool
	 */
	public function should_skip_excluded_script( bool $skip, string $src ): bool {
		return $this->should_skip_excluded_resource( $skip, $src, 'script' );
	}

	/**
	 * `surecookie_skip_iframe` callback: skip an iframe whose src matches an
	 * iframe-scoped (or legacy bare-domain) exclusion.
	 *
	 * @since 1.3.0
	 * @param bool   $skip Whether the resource is already marked to skip.
	 * @param string $src  The iframe src.
	 * @return bool
	 */
	public function should_skip_excluded_iframe( bool $skip, string $src ): bool {
		return $this->should_skip_excluded_resource( $skip, $src, 'iframe' );
	}

	/**
	 * Skip blocking when the resource src matches an excluded entry of the same
	 * kind (or a legacy bare-domain entry, which applies to any kind).
	 *
	 * Exclusions are keyed per (kind, domain) so the per-resource "Do not block"
	 * toggle on a script does not also unblock the iframe on the same host.
	 *
	 * @since 0.0.0-alpha.2
	 * @param bool   $skip Whether the resource is already marked to skip.
	 * @param string $src  The resource URL (script src or iframe src).
	 * @param string $kind Resource kind ('script'|'iframe').
	 * @return bool
	 */
	public function should_skip_excluded_resource( bool $skip, string $src, string $kind = 'any' ): bool {
		if ( $skip || empty( $src ) ) {
			return $skip;
		}

		foreach ( $this->get_excluded_domains_cached() as $entry ) {
			[ $entry_kind, $domain ] = $this->parse_scoped_key( (string) $entry );
			if ( $domain === '' || ( $entry_kind !== 'any' && $entry_kind !== $kind ) ) {
				continue;
			}
			if ( strpos( $src, $domain ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a scan-detected domain of a given kind is excluded from blocking.
	 *
	 * @param string             $domain   Scan-detected resource domain.
	 * @param string             $kind     Resource kind ('script'|'iframe').
	 * @param array<int, string> $excluded Excluded entries (scoped or legacy).
	 * @since 1.3.0
	 * @return bool
	 */
	private function is_domain_excluded( string $domain, string $kind, array $excluded ): bool {
		foreach ( $excluded as $entry ) {
			[ $entry_kind, $entry_domain ] = $this->parse_scoped_key( (string) $entry );
			if ( $entry_domain === '' || ( $entry_kind !== 'any' && $entry_kind !== $kind ) ) {
				continue;
			}
			if ( $entry_domain === $domain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Split a scoped exclusion entry into [ kind, domain ]. "script::host" /
	 * "iframe::host" scope to a kind; a bare "host" is legacy and applies to
	 * any kind. Mirrors Blocker::parse_scoped_key (shared key format).
	 *
	 * @param string $key Stored entry.
	 * @since 1.3.0
	 * @return array{0: string, 1: string} [ kind, domain ].
	 */
	private function parse_scoped_key( string $key ): array {
		$key = trim( $key );
		$pos = strpos( $key, '::' );
		if ( $pos === false ) {
			return [ 'any', $key ];
		}
		return [ substr( $key, 0, $pos ), substr( $key, $pos + 2 ) ];
	}

	/**
	 * Merge a single resource into the scripts array.
	 *
	 * @param array<mixed>         $scripts          Known scripts (by reference).
	 * @param array<string, mixed> $resource         Scan-detected resource.
	 * @param string               $type             Resource type ('scripts' or 'iframes').
	 * @param array<int, string>   $excluded_domains Domains excluded from blocking.
	 * @param array<string, bool>  $existing_patterns  Index of existing patterns.
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	private function merge_resource( array &$scripts, array $resource, string $type, array $excluded_domains, array $existing_patterns ): void {
		$domain   = $resource['domain'] ?? '';
		$category = $resource['category'] ?? 'marketing';

		if ( empty( $domain ) ) {
			return;
		}

		// Skip if excluded by admin. Kind-scoped: a script exclusion does not
		// stop the iframe on the same host from being blocked, and vice versa.
		$kind = $type === 'iframes' ? 'iframe' : 'script';
		if ( $this->is_domain_excluded( (string) $domain, $kind, $excluded_domains ) ) {
			return;
		}

		// Skip if this domain already exists in known-scripts patterns.
		if ( isset( $existing_patterns[ $domain ] ) ) {
			return;
		}

		// Ensure the category exists.
		if ( ! isset( $scripts[ $category ] ) ) {
			$scripts[ $category ] = [];
		}

		// Build a unique service key from the domain.
		$service_key = 'scan_' . str_replace( [ '.', '-' ], '_', $domain );

		// Add to the appropriate type array.
		$entry = [
			'label' => $resource['vendor'] ?? $domain,
		];

		if ( $type === 'iframes' ) {
			$entry['iframes'] = [ $domain ];
		} else {
			$entry['scripts'] = [ $domain ];
		}

		$scripts[ $category ][ $service_key ] = $entry;
	}

	/**
	 * Build an index of existing patterns for fast duplicate checks.
	 *
	 * @param array<string, array<string, mixed>> $scripts Known scripts by category.
	 * @since 0.0.0-alpha.2
	 * @return array<string, bool>
	 */
	private function build_existing_pattern_index( array $scripts ): array {
		$index = [];

		foreach ( $scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service ) {
				foreach ( $service['scripts'] ?? [] as $pattern ) {
					$index[ $pattern ] = true;
				}
				foreach ( $service['iframes'] ?? [] as $pattern ) {
					$index[ $pattern ] = true;
				}
			}
		}

		return $index;
	}

	/**
	 * Get excluded domains with static cache (called once per page load, then reused).
	 *
	 * @since 0.0.0-alpha.2
	 * @return array<int, string>
	 */
	private function get_excluded_domains_cached(): array {
		if ( self::$cached_excluded !== null ) {
			return self::$cached_excluded;
		}

		self::$cached_excluded = $this->get_excluded_domains();
		return self::$cached_excluded;
	}

	/**
	 * Get scanned resources from the database (with static cache).
	 *
	 * @since 0.0.0-alpha.2
	 * @return array<string, mixed>
	 */
	private function get_scanned_resources(): array {
		if ( self::$cached_resources !== null ) {
			return self::$cached_resources;
		}

		$resources = get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] );

		if ( ! is_array( $resources ) ) {
			$resources = [];
		}

		self::$cached_resources = $resources;

		return self::$cached_resources;
	}

	/**
	 * Get domains excluded from scan-based blocking.
	 *
	 * @since 0.0.0-alpha.2
	 * @return array<int, string>
	 */
	private function get_excluded_domains(): array {
		$excluded = Settings::get( 'excluded_scan_resources' );

		if ( ! is_array( $excluded ) ) {
			return [];
		}

		return $excluded;
	}
}
