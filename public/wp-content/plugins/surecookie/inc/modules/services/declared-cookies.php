<?php
/**
 * Declared Cookies catalog.
 *
 * A blocked third-party embed (YouTube, Vimeo, Google Maps, reCAPTCHA, ...) never
 * loads before consent, so it never sets its cookies and the scanner cannot
 * observe them - leaving them absent from the cookie manager and policy even
 * though the service is present and blocked.
 *
 * This bridges that gap: when a scan detects a known service's script or iframe,
 * the cookies it is documented to set are *declared* from a bundled catalog
 * (data/service-cookies.json), merged into the scanned-cookie store flagged
 * `source => 'declared'`, so they surface to visitors while the embed stays blocked.
 *
 * @package SureCookie\Inc\Modules\Services
 * @since 1.2.5
 */

namespace SureCookie\Inc\Modules\Services;

use SureCookie\Inc\Functions\Cookie_Identity;
use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Declared_Cookies
 *
 * Loads the declared-cookie catalog and seeds cookies for detected services.
 *
 * @since 1.2.5
 */
class Declared_Cookies {
	use GetInstance;

	/**
	 * Shortest literal prefix a catalog wildcard name may match on, so a stray
	 * pattern cannot swallow unrelated cookies.
	 *
	 * @since 1.3.0
	 */
	private const MIN_WILDCARD_PREFIX = 3;

	/**
	 * Memoized catalog provider lookup: [ exact, prefixes ].
	 *
	 * @var array{0: array<string, string>, 1: array<string, string>}|null
	 * @since 1.3.0
	 */
	private ?array $provider_index = null;

	/**
	 * Get the declared-cookie catalog (service slug => cookie definitions).
	 *
	 * Delegates to Service_Cookies_Source, which resolves the transient / file
	 * cache / bundled floor and applies the `surecookie_declared_service_cookies`
	 * filter exactly once. The `_meta` documentation key is stripped upstream.
	 *
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>> Cookies keyed by service slug.
	 */
	public function get_catalog(): array {
		return Service_Cookies_Source::get_instance()->get_catalog();
	}

	/**
	 * Prune stored declared cookies no longer present in the catalog.
	 *
	 * Only `source => 'declared'` rows are considered (observed + custom
	 * untouched). A declared cookie is kept while its `declared:{slug}:{name}`
	 * signature or its name+domain is still in the catalog. Runs after a remote
	 * refresh and each scan so catalog removals propagate.
	 *
	 * @since 1.2.5
	 * @return void
	 */
	public function reconcile_declared_cookies(): void {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		[ $valid_signatures, $valid_name_domain ] = $this->catalog_lookup();

		$changed = false;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				// Leave observed + custom cookies untouched.
				if ( ! is_array( $cookie ) || ( $cookie['source'] ?? '' ) !== 'declared' ) {
					continue;
				}

				$signature   = (string) ( $cookie['signature_id'] ?? '' );
				$name_domain = Cookie_Identity::key_for( $cookie );

				$present = ( $signature !== '' && isset( $valid_signatures[ $signature ] ) )
					|| isset( $valid_name_domain[ $name_domain ] );

				if ( ! $present ) {
					unset( $stored[ $category ][ $index ] );
					$changed = true;
				}
			}

			$stored[ $category ] = array_values( $stored[ $category ] );
		}

		if ( $changed ) {
			Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $stored );
		}
	}

	/**
	 * Fill in a provider for stored scanned cookies that have none.
	 *
	 * Earlier scans read the provider from a key the scan API never sends, so
	 * stored rows kept it empty. A re-scan fixes it, but until then match the
	 * bundled catalog by name+domain. Only fills blanks - an existing provider is
	 * never overwritten, so this is safe to run after a scan resolved the vendor.
	 *
	 * @since 1.3.0
	 * @return int Number of cookies given a provider.
	 */
	public function backfill_missing_providers(): int {
		$stored = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return 0;
		}

		$filled = 0;

		foreach ( $stored as $category => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $index => $cookie ) {
				if ( ! is_array( $cookie ) || ! empty( $cookie['provider'] ) ) {
					continue;
				}

				$provider = $this->catalog_provider_for( $cookie );
				if ( $provider === '' ) {
					continue;
				}

				$stored[ $category ][ $index ]['provider'] = $provider;
				$filled++;
			}
		}

		if ( $filled > 0 ) {
			Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $stored );
		}

		return $filled;
	}

	/**
	 * Resolve the catalog's provider for one cookie, or '' when unknown.
	 *
	 * Shared by scan ingest and the one-time backfill so both agree with the
	 * catalog and a re-scan can never downgrade a row the backfill already resolved.
	 *
	 * @param array<string, mixed> $cookie Cookie with at least a name, ideally a domain.
	 * @since 1.3.0
	 * @return string
	 */
	public function catalog_provider_for( array $cookie ): string {
		$name = Cookie_Identity::normalize_name( (string) ( $cookie['name'] ?? '' ) );
		if ( $name === '' ) {
			return '';
		}

		[ $exact, $prefixes ] = $this->catalog_provider_index();

		// Exact name+domain first, then name-only (a first-party entry's domain is
		// inferred from this host, so it need not match the tag's), then prefixes.
		$provider = $exact[ Cookie_Identity::key_for( $cookie ) ] ?? $exact[ $name ] ?? '';
		if ( $provider !== '' ) {
			return $provider;
		}

		foreach ( $prefixes as $prefix => $prefix_provider ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return $prefix_provider;
			}
		}

		return '';
	}

	/**
	 * Build declared cookies (grouped by category) for the services detected in a
	 * set of scan-result pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 1.2.5
	 * @return array<string, array<int, array<string, mixed>>> Declared cookies grouped by category id.
	 */
	public function build_from_pages( array $pages ): array {
		$catalog = $this->get_catalog();
		if ( empty( $catalog ) ) {
			return [];
		}

		$matcher = Service_Matcher::get_instance();
		$urls    = $matcher->collect_resource_urls( $pages );
		if ( empty( $urls ) ) {
			return [];
		}

		$matched_services = $matcher->match_services( array_keys( $catalog ), $urls );

		// Do not re-declare a service the admin explicitly removed from the Known
		// Services library until they add it back (suppression sticks).
		$installed        = Installed_Services::get_instance();
		$matched_services = array_values(
			array_filter(
				$matched_services,
				static fn( string $slug ): bool => ! $installed->is_suppressed( $slug )
			)
		);
		if ( empty( $matched_services ) ) {
			return [];
		}

		$valid_categories    = Get::default_cookie_categories_keys();
		$cookies_by_category = [];

		foreach ( $matched_services as $service ) {
			foreach ( $catalog[ $service ] as $definition ) {
				if ( ! is_array( $definition ) || empty( $definition['name'] ) ) {
					continue;
				}

				$cookie   = $this->transform( $service, $definition, $valid_categories );
				$category = $cookie['category'];

				$cookies_by_category[ $category ][] = $cookie;
			}
		}

		return $cookies_by_category;
	}

	/**
	 * Build (and memoize) the catalog provider lookup, returning [ exact, prefixes ].
	 *
	 * `exact` is keyed by name+domain, plus a name-only key for first-party
	 * entries: their domain is this site's host and the tag may use a different
	 * label of it (Analytics scopes `_ga` to the registrable domain, so
	 * `shop.example.com` observes `.example.com`). The name-only key is unambiguous
	 * - no catalog cookie name is claimed by more than one service.
	 *
	 * `prefixes` handles pattern rows (`_ga_<container-id>`, `_hjSessionUser_*`,
	 * `_dc_gtm_UA-*`) whose literal names never equal a real name: the wildcard
	 * marker is stripped and the prefix matched with str_starts_with. Prefixes
	 * below the minimum length are dropped so a stray pattern swallows nothing.
	 *
	 * @since 1.3.0
	 * @return array{0: array<string, string>, 1: array<string, string>}
	 */
	private function catalog_provider_index(): array {
		if ( $this->provider_index !== null ) {
			return $this->provider_index;
		}

		$exact    = [];
		$prefixes = [];

		foreach ( $this->get_catalog() as $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] ) || empty( $cookie['provider'] ) ) {
					continue;
				}

				$provider = (string) $cookie['provider'];
				$name     = Cookie_Identity::normalize_name( (string) $cookie['name'] );

				$wildcard_at = strcspn( $name, '*<' );

				if ( $wildcard_at < strlen( $name ) ) {
					$prefix = substr( $name, 0, $wildcard_at );

					if ( strlen( $prefix ) >= self::MIN_WILDCARD_PREFIX ) {
						$prefixes[ $prefix ] = $provider;
					}

					continue;
				}

				$exact[ Cookie_Identity::key_for( $cookie ) ] = $provider;

				if ( Cookie_Identity::is_first_party( $cookie ) ) {
					$exact[ $name ] = $provider;
				}
			}
		}

		// Longest prefix first so the most specific pattern wins.
		uksort( $prefixes, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$this->provider_index = [ $exact, $prefixes ];

		return $this->provider_index;
	}

	/**
	 * Build the signature and name+domain lookup sets from the current catalog.
	 *
	 * @since 1.2.5
	 * @return array{0: array<string, true>, 1: array<string, true>}
	 */
	private function catalog_lookup(): array {
		$valid_signatures  = [];
		$valid_name_domain = [];

		foreach ( $this->get_catalog() as $slug => $cookies ) {
			if ( ! is_array( $cookies ) ) {
				continue;
			}

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
					continue;
				}

				$name = (string) $cookie['name'];

				$valid_signatures[ 'declared:' . $slug . ':' . $name ]    = true;
				$valid_name_domain[ Cookie_Identity::key_for( $cookie ) ] = true;
			}
		}

		return [ $valid_signatures, $valid_name_domain ];
	}

	/**
	 * Transform a catalog definition into the stored scanned-cookie shape used by
	 * Sync::store_all_cookies_from_agent_app().
	 *
	 * @param string               $service          Service slug.
	 * @param array<string, mixed> $definition       Catalog cookie definition.
	 * @param array<int, string>   $valid_categories Allowed category ids.
	 * @since 1.2.5
	 * @return array<string, mixed>
	 */
	private function transform( string $service, array $definition, array $valid_categories ): array {
		$name     = (string) $definition['name'];
		$category = (string) ( $definition['category'] ?? 'uncategorized' );

		if ( ! in_array( $category, $valid_categories, true ) ) {
			$category = 'uncategorized';
		}

		$duration_days = isset( $definition['duration_days'] ) ? absint( $definition['duration_days'] ) : 0;
		$expires       = $duration_days > 0
			? gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$duration_days} days" ) )
			: null;

		$cookie = [
			'name'         => $name,
			'value'        => '',
			'domain'       => (string) ( $definition['domain'] ?? '' ),
			'path'         => '/',
			'expires'      => $expires,
			'httpOnly'     => false,
			'secure'       => true,
			// Third-party embed cookies are sent cross-site.
			'sameSite'     => 'none',
			'category'     => $category,
			// The catalog knows the lifetime in days.
			'duration'     => $duration_days > 0 ? (string) $duration_days : '',
			'provider'     => (string) ( $definition['provider'] ?? '' ),
			'description'  => (string) ( $definition['description'] ?? '' ),
			'purpose'      => (string) ( $definition['purpose'] ?? '' ),
			// Deterministic id so re-scans replace (never duplicate) the declared row.
			'signature_id' => 'declared:' . $service . ':' . $name,
			// Marks the cookie as declared-from-catalog rather than runtime-observed.
			'source'       => 'declared',
		];

		// Carry the marker so the scan-time merge knows this domain was inferred
		// and can match by name; conditional so third-party rows keep their shape.
		if ( Cookie_Identity::is_first_party( $definition ) ) {
			$cookie[ Cookie_Identity::FIRST_PARTY_FLAG ] = true;
		}

		return $cookie;
	}
}
