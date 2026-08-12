<?php
/**
 * Service Matcher.
 *
 * Maps detected resource URLs to known-service slugs using the SAME blocking
 * patterns the Blocker uses (read via the `surecookie_known_scripts` filter), so
 * declared-cookie seeding, the Detected Resources overlay, the library's
 * "detected" state, and the "Add as Service" smart-detect CTAs all agree on what
 * a scan actually detected.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.3.0
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Matcher
 *
 * @since 1.3.0
 */
class Service_Matcher {
	use GetInstance;

	/**
	 * Collect the lower-cased third-party script/iframe URLs reported across pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 1.3.0
	 * @return array<int, string>
	 */
	public function collect_resource_urls( array $pages ): array {
		$urls = [];

		foreach ( $pages as $page ) {
			foreach ( $page['scripts'] ?? [] as $script ) {
				$candidate = (string) ( $script['url'] ?? $script['domain'] ?? '' );
				if ( $candidate !== '' ) {
					$urls[] = strtolower( $candidate );
				}
			}

			foreach ( $page['iframes'] ?? [] as $iframe ) {
				$candidate = (string) ( $iframe['src'] ?? $iframe['domain'] ?? '' );
				if ( $candidate !== '' ) {
					$urls[] = strtolower( $candidate );
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Determine which of the given service slugs are present, by testing each
	 * service's script/iframe URL patterns against the detected resource URLs.
	 *
	 * @param array<int, string> $service_slugs Slugs to test (catalog keys). Empty = all.
	 * @param array<int, string> $urls          Detected resource URLs (lower-cased).
	 * @since 1.3.0
	 * @return array<int, string> Matched service slugs.
	 */
	public function match_services( array $service_slugs, array $urls ): array {
		$patterns_by_service = $this->get_service_patterns( $service_slugs );
		$matched             = [];

		foreach ( $patterns_by_service as $service => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( $pattern === '' ) {
					continue;
				}

				foreach ( $urls as $url ) {
					if ( strpos( $url, $pattern ) !== false ) {
						$matched[] = $service;
						continue 3;
					}
				}
			}
		}

		return $matched;
	}

	/**
	 * The first known-service slug whose blocking pattern matches a single URL,
	 * or '' when none does. Used by the per-row "Add as Service" smart-detect CTA.
	 *
	 * @param string $url URL / domain to test.
	 * @since 1.3.0
	 * @return string Matched slug, or '' if no match.
	 */
	public function match_url( string $url ): string {
		$url = strtolower( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$matched = $this->match_services( [], [ $url ] );

		return $matched[0] ?? '';
	}

	/**
	 * Collect the script + iframe URL patterns for the requested services from the
	 * catalog blocking view, so matching keys off the same patterns the blocker
	 * uses. Reads the catalog view directly (not the surecookie_known_scripts
	 * filter) so it excludes the scan-merged synthetic rows and needs no provider.
	 *
	 * @param array<int, string> $service_slugs Slugs to look up. Empty = every service.
	 * @since 1.3.0
	 * @return array<string, array<int, string>> Patterns keyed by service slug.
	 */
	public function get_service_patterns( array $service_slugs = [] ): array {
		/** @var array<string, array<string, mixed>> $all_scripts */
		$all_scripts = Services_Source::get_instance()->get_blocking_view();
		$wanted      = $service_slugs === [] ? null : array_fill_keys( $service_slugs, true );
		$patterns    = [];

		foreach ( $all_scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $slug => $definition ) {
				if ( ( $wanted !== null && ! isset( $wanted[ $slug ] ) ) || ! is_array( $definition ) ) {
					continue;
				}

				$service_patterns = array_merge(
					is_array( $definition['scripts'] ?? null ) ? $definition['scripts'] : [],
					is_array( $definition['iframes'] ?? null ) ? $definition['iframes'] : []
				);

				$patterns[ $slug ] = array_map(
					static fn( $pattern ): string => strtolower( (string) $pattern ),
					$service_patterns
				);
			}
		}

		return $patterns;
	}

	/**
	 * Same as get_service_patterns() but keeps the script vs iframe kind, so
	 * callers that place a pattern in the right bucket (e.g. the Detected
	 * Resources overlay) use the catalog's authoritative type instead of
	 * guessing it from the URL.
	 *
	 * @param array<int, string> $service_slugs Slugs to look up. Empty = every service.
	 * @since 1.3.0
	 * @return array<string, array{scripts: array<int, string>, iframes: array<int, string>}>
	 */
	public function get_service_patterns_by_kind( array $service_slugs = [] ): array {
		/** @var array<string, array<string, mixed>> $all_scripts */
		$all_scripts = Services_Source::get_instance()->get_blocking_view();
		$wanted      = $service_slugs === [] ? null : array_fill_keys( $service_slugs, true );
		$lower       = static fn( $list ): array => array_map(
			static fn( $pattern ): string => strtolower( (string) $pattern ),
			is_array( $list ) ? array_values( $list ) : []
		);
		$patterns    = [];

		foreach ( $all_scripts as $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $slug => $definition ) {
				if ( ( $wanted !== null && ! isset( $wanted[ $slug ] ) ) || ! is_array( $definition ) ) {
					continue;
				}

				$patterns[ $slug ] = [
					'scripts' => $lower( $definition['scripts'] ?? [] ),
					'iframes' => $lower( $definition['iframes'] ?? [] ),
				];
			}
		}

		return $patterns;
	}
}
