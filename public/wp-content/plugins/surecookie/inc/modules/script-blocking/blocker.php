<?php
/**
 * Script and Content Blocker.
 *
 * Modifies page output to block third-party scripts and iframes until consent.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Blocker
 *
 * Handles output buffering, script modification, and iframe placeholders.
 *
 * @since 0.0.1
 */
class Blocker {
	use GetInstance;

	/**
	 * Script `type` values the browser executes. Mirrors the allowlist in
	 * `src/utils/consentManager.js` so blocker output round-trips correctly.
	 */
	private const EXECUTABLE_SCRIPT_TYPES = [ 'text/javascript', 'module', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'importmap', 'speculationrules' ];

	/**
	 * Reserved blocking category for newly-detected trackers held by the Pro
	 * Compliance Guard. Resources in this category are blocked until an admin
	 * reviews them and are NEVER released by visitor consent. Mirrored in
	 * `src/utils/consentManager.js` (`isAllowed`) and the Pro Guard
	 * (`Guard::QUARANTINE_CATEGORY`).
	 */
	private const QUARANTINE_CATEGORY = 'quarantine';

	/**
	 * Marker comment printed at the very start of `wp_footer`, giving the
	 * buffer processor a precise footer boundary for region-constrained rules.
	 * Always stripped from the final output.
	 */
	private const FOOTER_MARKER = '<!--surecookie:footer-->';

	/**
	 * Per-request cache of iframe-pattern lookup map (shared across
	 * block_iframes / block_embeds / block_objects).
	 *
	 * @var array<string, array{name: string, category: string, label: string, path?: string, location?: string}>|null
	 */
	private ?array $iframe_patterns_cache = null;

	/**
	 * Per-request cache of admin category overrides ({ domain => category }),
	 * from the `resource_category_overrides` setting. Lets an admin recategorize
	 * a detected resource so consent gating follows the chosen category.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $category_overrides = null;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Open a full-page output buffer for the resolved template so the finished
	 * HTML can be post-processed, then return the template unchanged so
	 * WordPress renders it normally.
	 *
	 * @param string|null $template Resolved template path.
	 * @since 0.0.1
	 * @since 1.2.2 Buffer the real template output instead of pre-rendering it,
	 *              so WordPress 7.0's on-demand block-style hoisting is preserved.
	 * @return string
	 */
	public function intercept_template( ?string $template ): string {
		if ( ! $this->should_process() || $template === null || $template === '' || ! file_exists( $template ) ) {
			return $template ?? '';
		}

		/*
		 * Open a full-page output buffer and let WordPress include the *real*
		 * template normally, then post-process the finished HTML in the buffer
		 * callback.
		 *
		 * We must NOT render the template ourselves here. Rendering the page
		 * inside this filter (and returning a blank template) runs ahead of
		 * WordPress 7.0's own template-enhancement output buffer, which is
		 * started later at the `wp_before_include_template` action and hoists
		 * on-demand block styles from the body back up into the <head>. Doing
		 * our own early render bypassed that hoisting, leaving classic-theme
		 * block/global styles stranded in the <body> in the wrong cascade order
		 * (e.g. the theme's `body .is-layout-grid{display:grid}` then overrode a
		 * block's responsive `display:flex`, collapsing multi-column layouts).
		 *
		 * By opening our buffer first and returning the real template, WordPress
		 * renders and enhances the page as usual - its buffer nests inside ours -
		 * and our callback processes the already-corrected HTML.
		 */
		ob_start( [ $this, 'process_buffer' ] );

		return $template;
	}

	/**
	 * Process the output buffer.
	 *
	 * @param string $buffer HTML content.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	public function process_buffer( string $buffer ): string {
		// Skip empty buffers.
		if ( empty( $buffer ) ) {
			return $buffer;
		}

		// Check if this is HTML content.
		if ( ! $this->is_html( $buffer ) ) {
			return $buffer;
		}

		// Block scripts and embedded content (iframe/embed/object) if blocking is enabled.
		if ( Utils::is_blocking_enabled() ) {
			$buffer = $this->block_scripts( $buffer );
			$buffer = $this->block_iframes( $buffer );
			$buffer = $this->block_embeds( $buffer );
			$buffer = $this->block_objects( $buffer );
		}

		// The footer-boundary marker is internal - never ship it.
		return str_replace( self::FOOTER_MARKER, '', $buffer );
	}

	/**
	 * Print the footer-boundary marker consumed by split_html_regions().
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function mark_footer_start(): void {
		if ( Utils::is_blocking_enabled() ) {
			echo self::FOOTER_MARKER; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML comment constant.
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function register_hooks(): void {
		add_filter( 'template_include', [ $this, 'intercept_template' ], PHP_INT_MAX );

		// Before every other wp_footer callback, so all footer scripts land
		// after the marker. Stripped again in process_buffer().
		add_action( 'wp_footer', [ $this, 'mark_footer_start' ], -PHP_INT_MAX );
	}

	/**
	 * Check if blocking should run.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	private function should_process(): bool {
		// Check if blocking feature is enabled.
		if ( ! Utils::is_blocking_enabled() ) {
			return false;
		}

		// Skip in admin area.
		if ( is_admin() ) {
			return false;
		}

		// Skip AJAX requests.
		if ( wp_doing_ajax() ) {
			return false;
		}

		// Skip REST API requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Skip feeds.
		if ( is_feed() ) {
			return false;
		}

		// Check content type header.
		$headers_list = headers_list();
		foreach ( $headers_list as $header ) {
			if ( stripos( $header, 'Content-Type:' ) === 0 ) {
				// Skip JSON responses.
				if ( stripos( $header, 'application/json' ) !== false ) {
					return false;
				}
				// Skip XML responses.
				if ( stripos( $header, 'application/xml' ) !== false || stripos( $header, 'text/xml' ) !== false ) {
					return false;
				}
			}
		}

		// Check geo-location rules - bypass blocking if user is not in a configured region -- This ensures script/content blocking respects the same geo-targeting rules as the consent banner.
		if ( ! Utils::should_process_based_on_geo() ) {
			return false;
		}

		// Allow the SaaS scanner to bypass blocking so it sees the full unblocked page.
		if ( $this->is_scan_bypass_request() ) {
			// Record that the scanner's request actually reached WordPress. If a
			// scan finishes with zero findings and zero reach, the host firewall
			// blocked the crawl at the edge (see SaasClient::classify_scan_outcome).
			do_action( 'surecookie_scanner_request_detected' );
			return false;
		}

		/**
		 * Filter whether blocking should run.
		 *
		 * @since 0.0.1
		 * @param bool $should_process Whether to process the output.
		 */
		return apply_filters( 'surecookie_should_block_scripts', true );
	}

	/**
	 * Check if buffer is HTML content.
	 *
	 * @param string $buffer Content to check.
	 * @since 0.0.1
	 * @return bool
	 */
	private function is_html( string $buffer ): bool {
		$trimmed = ltrim( $buffer );

		// Check if starts with HTML-like content.
		if ( empty( $trimmed ) ) {
			return false;
		}

		// Skip JSON (starts with { or [).
		if ( $trimmed[0] === '{' || $trimmed[0] === '[' ) {
			return false;
		}

		// Should start with < for HTML/XML.
		if ( $trimmed[0] !== '<' ) {
			return false;
		}

		// Check for HTML doctype or html tag.
		if ( preg_match( '/^<!DOCTYPE\s+html|^<html/i', $trimmed ) ) {
			return true;
		}

		// Check for common HTML tags.
		if ( preg_match( '/^<(head|body|div|script|style|meta|link)/i', $trimmed ) ) {
			return true;
		}

		return true;
	}

	/**
	 * Find and block matching scripts in HTML.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	private function block_scripts( string $html ): string {
		$all_scripts = apply_filters( 'surecookie_known_scripts', [] );

		if ( empty( $all_scripts ) ) {
			return $html;
		}

		// Build patterns for matching.
		$patterns = $this->build_script_patterns( $all_scripts );

		if ( empty( $patterns ) ) {
			return $html;
		}

		// Region-constrained rules (head|body|footer) need per-segment passes;
		// the common case (no constraints) keeps the single whole-page pass.
		$has_constraint = false;
		foreach ( $patterns as $info ) {
			if ( $info['location'] !== 'any' ) {
				$has_constraint = true;
				break;
			}
		}

		if ( ! $has_constraint ) {
			return $this->rewrite_script_tags( $html, $patterns );
		}

		$out = '';
		foreach ( $this->split_html_regions( $html ) as $segment ) {
			$region_patterns = array_filter(
				$patterns,
				static function ( $info ) use ( $segment ) {
					$location = $info['location'];
					return $location === 'any' || in_array( $location, $segment['accepts'], true );
				}
			);

			$out .= empty( $region_patterns )
				? $segment['html']
				: $this->rewrite_script_tags( $segment['html'], $region_patterns );
		}

		return $out;
	}

	/**
	 * Run the script-tag rewrite pass over one HTML chunk.
	 *
	 * @param string               $html     HTML content.
	 * @param array<string, mixed> $patterns Pattern lookup map.
	 * @since 1.3.0
	 * @return string Modified HTML.
	 */
	private function rewrite_script_tags( string $html, array $patterns ): string {
		$result = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_script_tag( $matches, $patterns );
			},
			$html
		);

		return $result ?? $html;
	}

	/**
	 * Split the page into head / body / footer segments for region-constrained
	 * matching. Head ends at `</head>`; footer starts at the marker printed by
	 * `mark_footer_start()`. When a boundary is missing, its region folds into
	 * the surrounding segment (which then accepts that region's rules too), so
	 * a constrained rule can never silently stop blocking.
	 *
	 * @param string $html HTML content.
	 * @since 1.3.0
	 * @return array<int, array{html: string, accepts: array<int, string>}>
	 */
	private function split_html_regions( string $html ): array {
		$head_end     = stripos( $html, '</head>' );
		$footer_start = strpos( $html, self::FOOTER_MARKER );

		$segments = [];

		if ( $head_end !== false ) {
			$segments[]  = [
				'html'    => substr( $html, 0, $head_end ),
				'accepts' => [ 'head' ],
			];
			$rest_offset = $head_end;
		} else {
			$rest_offset = 0;
		}

		$body_accepts = $head_end === false ? [ 'head', 'body' ] : [ 'body' ];

		if ( $footer_start !== false && $footer_start >= $rest_offset ) {
			$segments[] = [
				'html'    => substr( $html, $rest_offset, $footer_start - $rest_offset ),
				'accepts' => $body_accepts,
			];
			$segments[] = [
				'html'    => substr( $html, $footer_start ),
				'accepts' => [ 'footer' ],
			];
		} else {
			$segments[] = [
				'html'    => substr( $html, $rest_offset ),
				'accepts' => array_merge( $body_accepts, [ 'footer' ] ),
			];
		}

		return $segments;
	}

	/**
	 * Get the iframe-pattern lookup map, computed once per request.
	 *
	 * Shared by block_iframes / block_embeds / block_objects so the known-scripts
	 * payload is iterated only once per HTTP response. Cache is kept on the
	 * Blocker singleton, which is instantiated fresh per PHP request.
	 *
	 * @since 0.0.1-beta.2
	 * @return array<string, array{name: string, category: string, label: string, path?: string, location?: string}>
	 */
	private function get_iframe_patterns(): array {
		if ( $this->iframe_patterns_cache !== null ) {
			return $this->iframe_patterns_cache;
		}

		$all_scripts = apply_filters( 'surecookie_known_scripts', [] );

		if ( empty( $all_scripts ) ) {
			$this->iframe_patterns_cache = [];
			return $this->iframe_patterns_cache;
		}

		$this->iframe_patterns_cache = $this->build_iframe_patterns( $all_scripts );

		return $this->iframe_patterns_cache;
	}

	/**
	 * Find and block matching iframes in HTML.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1
	 * @return string Modified HTML.
	 */
	private function block_iframes( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// Use regex to find and modify iframe tags.
		$result = preg_replace_callback(
			'/<iframe\b([^>]*)(?:>(.*?)<\/iframe>|\s*\/>)/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_iframe_tag( $matches, $patterns );
			},
			$html
		);

		return $result ?? $html;
	}

	/**
	 * Find and block matching <embed> tags in HTML.
	 *
	 * Reuses the `iframes[]` pattern array from blocking-scripts.json since
	 * <iframe>, <embed>, and <object> share URL semantics.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1-beta.2
	 * @return string Modified HTML.
	 */
	private function block_embeds( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// <embed> is always self-closing in practice.
		$result = preg_replace_callback(
			'/<embed\b([^>]*)\/?>/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_embed_tag( $matches, $patterns );
			},
			$html
		);

		return $result ?? $html;
	}

	/**
	 * Find and block matching <object> tags in HTML.
	 *
	 * Handles both `<object data="…">…</object>`, legacy
	 * `<object><param name="movie|src|url" value="…"></object>` (Flash-style),
	 * AND self-closed XHTML-style `<object … />`.
	 *
	 * @param string $html HTML content.
	 * @since 0.0.1-beta.2
	 * @return string Modified HTML.
	 */
	private function block_objects( string $html ): string {
		$patterns = $this->get_iframe_patterns();

		if ( empty( $patterns ) ) {
			return $html;
		}

		// Covers both self-closed `<object … />` and full `<object …>…</object>` forms,
		// mirroring how block_iframes() handles its two shapes.
		$result = preg_replace_callback(
			'/<object\b([^>]*?)(?:\s*\/>|>(.*?)<\/object>)/is',
			function ( $matches ) use ( $patterns ) {
				return $this->process_object_tag( $matches, $patterns );
			},
			$html
		);

		return $result ?? $html;
	}

	/**
	 * Build patterns for script matching.
	 *
	 * @param array<string, array<string, mixed>> $all_scripts Known scripts by category.
	 * @since 0.0.1
	 * @return array<string, array{name: string, category: string, label: string, location: string, path: string}>
	 */
	private function build_script_patterns( array $all_scripts ): array {
		$patterns = [];

		foreach ( $all_scripts as $category => $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service_key => $service ) {
				if ( empty( $service['scripts'] ) || ! is_array( $service['scripts'] ) ) {
					continue;
				}

				foreach ( $service['scripts'] as $pattern ) {
					$patterns[ $pattern ] = [
						'name'     => $service_key,
						'category' => $category,
						'label'    => $service['label'] ?? $service_key,
						// Optional page-region constraint (head|body|footer).
						'location' => $service['location'] ?? 'any',
						// Optional narrowing constraint: the resource must
						// also contain this path/pattern.
						'path'     => (string) ( $service['path'] ?? '' ),
					];
				}
			}
		}

		return $patterns;
	}

	/**
	 * Build patterns for iframe matching.
	 *
	 * @param array<string, array<string, mixed>> $all_scripts Known scripts by category.
	 * @since 0.0.1
	 * @return array<string, array{name: string, category: string, label: string, path?: string, location?: string}>
	 */
	private function build_iframe_patterns( array $all_scripts ): array {
		$patterns = [];

		foreach ( $all_scripts as $category => $services ) {
			if ( ! is_array( $services ) ) {
				continue;
			}

			foreach ( $services as $service_key => $service ) {
				if ( empty( $service['iframes'] ) || ! is_array( $service['iframes'] ) ) {
					continue;
				}

				foreach ( $service['iframes'] as $pattern ) {
					$patterns[ $pattern ] = [
						'name'     => $service_key,
						'category' => $category,
						'label'    => $service['label'] ?? $service_key,
						// Optional narrowing constraint (see build_script_patterns).
						'path'     => (string) ( $service['path'] ?? '' ),
					];
				}
			}
		}

		return $patterns;
	}

	/**
	 * Process a single script tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return string Modified script tag.
	 */
	private function process_script_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];
		$content    = $matches[2] ?? '';

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-category' ) !== false ) {
			return $full_tag;
		}

		// Never block SureCookie's own inline scripts. WordPress emits our
		// localized data as id="{handle}-js-{extra,before,after}" (e.g.
		// surecookie-public-js-extra, which defines window.surecookiePublicSettings).
		// That payload embeds tracker-domain strings - cookie domains and the
		// blocking patterns themselves - for client-side use, so matching inline
		// CONTENT would self-match a blocking pattern and neutralize the consent
		// runtime's own bootstrap. Our handles are always "surecookie"-prefixed;
		// no third-party script carries that id.
		if ( preg_match( '/\bid\s*=\s*["\']surecookie[-_]/i', $attributes ) ) {
			return $full_tag;
		}

		$type = $this->extract_script_type( $attributes );
		if ( $type === 'text/plain' ) {
			return $full_tag;
		}
		if ( $type !== null && ! in_array( $type, self::EXECUTABLE_SCRIPT_TYPES, true ) ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		// Try to match against known scripts.
		$match_result = $this->match_pattern( $src, $content, $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Honor an admin category override for this resource.
		$match_result['category'] = $this->resolve_category( $src, $match_result['category'], 'script' );

		// Check if this script should be skipped.
		$skip = apply_filters(
			'surecookie_skip_script',
			false,
			$src,
			$match_result['name'],
			$match_result['category']
		);

		if ( $skip ) {
			return $full_tag;
		}

		// Skip required category.
		if ( $this->can_category_skip( $match_result['category'] ) ) {
			return $full_tag;
		}

		// Modify the script tag.
		return $this->modify_script_tag(
			$attributes,
			$content,
			$src,
			$match_result['name'],
			$match_result['category']
		);
	}

	/**
	 * Process a single iframe tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return string Modified iframe tag with placeholder.
	 */
	private function process_iframe_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-src' ) !== false ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		if ( empty( $src ) ) {
			return $full_tag;
		}

		// Try to match against known iframe patterns.
		$match_result = $this->match_pattern( $src, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Honor an admin category override for this resource.
		$match_result['category'] = $this->resolve_category( $src, $match_result['category'], 'iframe' );

		// Check if this iframe should be skipped.
		$skip = apply_filters(
			'surecookie_skip_iframe',
			false,
			$src,
			$match_result['name'],
			$match_result['category']
		);

		if ( $skip ) {
			return $full_tag;
		}

		// Skip required category.
		if ( $this->can_category_skip( $match_result['category'] ) ) {
			return $full_tag;
		}

		// Build blocked iframe with placeholder.
		return $this->build_embedded_placeholder(
			'iframe',
			$attributes,
			$src,
			$match_result['name'],
			$match_result['category'],
			$match_result['label']
		);
	}

	/**
	 * Process a single <embed> tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1-beta.2
	 * @return string Modified embed tag with placeholder, or original tag if not blocked.
	 */
	private function process_embed_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-src' ) !== false ) {
			return $full_tag;
		}

		// Extract src attribute.
		$src = '';
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $attributes, $src_match ) ) {
			$src = $src_match[1];
		}

		if ( empty( $src ) ) {
			return $full_tag;
		}

		// Same-origin skip - don't wrap legitimate in-house PDFs/SVGs.
		if ( $this->is_same_origin_url( $src ) ) {
			return $full_tag;
		}

		$match_result = $this->match_pattern( $src, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Honor an admin category override for this resource.
		$match_result['category'] = $this->resolve_category( $src, $match_result['category'], 'iframe' );

		/**
		 * Filter: Allow bypassing <embed> blocking for a specific URL/service.
		 *
		 * @param bool   $skip     Whether to skip blocking (default false).
		 * @param string $src      <embed> src URL.
		 * @param string $name     Matched service key.
		 * @param string $category Matched service category.
		 * @since 0.0.1-beta.2
		 */
		$skip = apply_filters(
			'surecookie_skip_embed',
			false,
			$src,
			$match_result['name'],
			$match_result['category']
		);

		if ( $skip ) {
			return $full_tag;
		}

		if ( $this->can_category_skip( $match_result['category'] ) ) {
			return $full_tag;
		}

		return $this->build_embedded_placeholder(
			'embed',
			$attributes,
			$src,
			$match_result['name'],
			$match_result['category'],
			$match_result['label']
		);
	}

	/**
	 * Process a single <object> tag.
	 *
	 * @param array<int, string>                                                                                    $matches  Regex matches.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1-beta.2
	 * @return string Modified object tag with placeholder, or original tag if not blocked.
	 */
	private function process_object_tag( array $matches, array $patterns ): string {
		$full_tag   = $matches[0];
		$attributes = $matches[1];
		$inner      = $matches[2] ?? '';

		// Skip if already blocked.
		if ( strpos( $attributes, 'data-surecookie-data' ) !== false ) {
			return $full_tag;
		}

		// Extract data attribute (primary URL source).
		$data = '';
		if ( preg_match( '/(?<![-\w])data\s*=\s*["\']([^"\']+)["\']/i', $attributes, $data_match ) ) {
			$data = $data_match[1];
		}

		// If no data= attribute, look for <param name="movie|src|url" value="..."> child.
		if ( empty( $data ) && ! empty( $inner ) ) {
			if ( preg_match( '/<param\b[^>]*\bname\s*=\s*["\'](?:movie|src|url)["\'][^>]*\bvalue\s*=\s*["\']([^"\']+)["\']/is', $inner, $param_match ) ) {
				$data = $param_match[1];
			} elseif ( preg_match( '/<param\b[^>]*\bvalue\s*=\s*["\']([^"\']+)["\'][^>]*\bname\s*=\s*["\'](?:movie|src|url)["\']/is', $inner, $param_match ) ) {
				$data = $param_match[1];
			}
		}

		if ( empty( $data ) ) {
			return $full_tag;
		}

		// Same-origin skip.
		if ( $this->is_same_origin_url( $data ) ) {
			return $full_tag;
		}

		$match_result = $this->match_pattern( $data, '', $patterns );

		if ( $match_result === null ) {
			return $full_tag;
		}

		// Honor an admin category override for this resource.
		$match_result['category'] = $this->resolve_category( $data, $match_result['category'], 'iframe' );

		/**
		 * Filter: Allow bypassing <object> blocking for a specific URL/service.
		 *
		 * @param bool   $skip     Whether to skip blocking (default false).
		 * @param string $data     <object> resource URL.
		 * @param string $name     Matched service key.
		 * @param string $category Matched service category.
		 * @since 0.0.1-beta.2
		 */
		$skip = apply_filters(
			'surecookie_skip_object',
			false,
			$data,
			$match_result['name'],
			$match_result['category']
		);

		if ( $skip ) {
			return $full_tag;
		}

		if ( $this->can_category_skip( $match_result['category'] ) ) {
			return $full_tag;
		}

		// Neutralize any <param> child that carries the URL so the browser
		// doesn't refetch Flash/legacy media while the object is hidden.
		$neutralized_inner = $this->neutralize_object_params( $inner );

		return $this->build_embedded_placeholder(
			'object',
			$attributes,
			$data,
			$match_result['name'],
			$match_result['category'],
			$match_result['label'],
			$neutralized_inner
		);
	}

	/**
	 * Rename `name` attribute on <param> elements carrying Flash-style URLs so
	 * the hidden <object> placeholder doesn't still fetch them. Restoration in
	 * consentManager.js renames the attribute back when consent is given.
	 *
	 * @param string $inner Inner HTML of the <object> tag.
	 * @since 0.0.1-beta.2
	 * @return string Neutralized inner HTML.
	 */
	private function neutralize_object_params( string $inner ): string {
		if ( trim( $inner ) === '' ) {
			return $inner;
		}

		$result = preg_replace_callback(
			'/<param\b([^>]*)(\/?>)/is',
			static function ( $matches ) {
				$attrs   = $matches[1];
				$closing = $matches[2];

				if ( preg_match( '/name\s*=\s*["\'](?:movie|src|url)["\']/i', $attrs ) !== 1 ) {
					return $matches[0];
				}

				// phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
				$new_attrs = preg_replace(
					'/(^|\s)name(\s*=)/i',
					'$1data-surecookie-param-name$2',
					$attrs
				) ?? $attrs;

				return '<param' . $new_attrs . $closing;
			},
			$inner
		);

		return $result ?? $inner;
	}

	/**
	 * Check whether a URL has the same host as the current site.
	 *
	 * Used to avoid wrapping first-party PDFs/SVGs embedded via <embed>/<object>.
	 * Uses exact-host match (no eTLD+1 / Public Suffix List lookup).
	 *
	 * @param string $url Resource URL.
	 * @since 0.0.1-beta.2
	 * @return bool True if URL is same-origin (exact host), relative, or a data: URI.
	 */
	private function is_same_origin_url( string $url ): bool {
		if ( $url === '' ) {
			return false;
		}

		// Non-HTTP schemes and relative URLs - not third-party tracking.
		if ( preg_match( '/^(?:about|blob|data|file|javascript|mailto|tel):/i', $url ) === 1 ) {
			return true;
		}

		// Protocol-relative URL - normalize so wp_parse_url resolves host.
		if ( strpos( $url, '//' ) === 0 ) {
			$url = 'http:' . $url;
		}

		// Relative URLs (no scheme) are inherently same-origin.
		if ( preg_match( '/^https?:/i', $url ) !== 1 ) {
			return true;
		}

		$embed_host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $embed_host ) || empty( $site_host ) ) {
			return false;
		}

		return strcasecmp( (string) $embed_host, (string) $site_host ) === 0;
	}

	/**
	 * Check if a category can be skipped.
	 *
	 * @param string $category Category name.
	 * @since 0.0.1
	 * @return bool True if category can be skipped, false otherwise.
	 */
	private function can_category_skip( string $category ): bool {
		$skippable_categories = apply_filters( 'surecookie_skippable_categories', [ 'essential' ] );
		if ( in_array( $category, $skippable_categories, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Admin per-domain category overrides ({ domain => category }), read once
	 * per request. Invalid rows are skipped.
	 *
	 * @since 1.3.0
	 * @return array<string, string>
	 */
	private function get_category_overrides(): array {
		if ( $this->category_overrides !== null ) {
			return $this->category_overrides;
		}

		$this->category_overrides = [];
		$stored                   = Settings::get( 'resource_category_overrides' );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $domain => $category ) {
				$domain   = trim( (string) $domain );
				$category = trim( (string) $category );
				if ( $domain !== '' && $category !== '' ) {
					$this->category_overrides[ $domain ] = $category;
				}
			}
		}

		return $this->category_overrides;
	}

	/**
	 * Apply an admin category override to a matched resource when its src
	 * contains an overridden domain, so consent gating follows the admin choice.
	 *
	 * Overrides are keyed per (kind, domain) so a script and an iframe on the
	 * same host stay independent. An exact-kind override wins; a legacy
	 * bare-domain override applies to any kind. `$kind` is 'script' for scripts
	 * and 'iframe' for iframe/embed/object resources.
	 *
	 * @param string $src      Resource URL.
	 * @param string $category Category resolved from the pattern match.
	 * @param string $kind     Resource kind ('script'|'iframe').
	 * @since 1.3.0
	 * @return string Overridden category, or the original when no override matches.
	 */
	private function resolve_category( string $src, string $category, string $kind = 'any' ): string {
		if ( $src === '' ) {
			return $category;
		}
		$legacy = null;
		foreach ( $this->get_category_overrides() as $key => $target ) {
			[ $entry_kind, $domain ] = $this->parse_scoped_key( (string) $key );
			if ( $domain === '' || strpos( $src, $domain ) === false ) {
				continue;
			}
			if ( $entry_kind === $kind ) {
				return $target; // Exact-kind override wins.
			}
			if ( $entry_kind === 'any' && $legacy === null ) {
				$legacy = $target; // Legacy bare-domain key applies to any kind.
			}
		}
		return $legacy ?? $category;
	}

	/**
	 * Split a scoped override key into [ kind, domain ].
	 *
	 * Keys are stored as "script::host" or "iframe::host" so a script and an
	 * iframe on the same host can be gated independently; a bare "host" (no
	 * "::") is a legacy key that applies to any kind.
	 *
	 * @param string $key Stored key.
	 * @since 1.3.0
	 * @return array{0: string, 1: string} [ kind ('script'|'iframe'|'any'), domain ].
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
	 * Match a URL against known patterns.
	 *
	 * @param string                                                                                                $src      URL to match.
	 * @param string                                                                                                $content  Content to match.
	 * @param array<string, array{name: string, category: string, label: string, path?: string, location?: string}> $patterns Pattern mappings.
	 * @since 0.0.1
	 * @return array{name: string, category: string, label: string, path?: string, location?: string}|null Match result or null.
	 */
	private function match_pattern( string $src, string $content, array $patterns ): ?array {
		foreach ( $patterns as $pattern => $info ) {
			$matched =
				( ! empty( $src ) && strpos( $src, $pattern ) !== false ) ||
				( ! empty( $content ) && strpos( $content, $pattern ) !== false );

			if ( ! $matched ) {
				continue;
			}

			// Optional narrowing constraint: the resource must ALSO contain
			// the rule's path/pattern (e.g. host + `/fbevents.js`).
			$path = (string) ( $info['path'] ?? '' );
			if ( $path !== '' ) {
				$path_matched =
					( ! empty( $src ) && stripos( $src, $path ) !== false ) ||
					( ! empty( $content ) && stripos( $content, $path ) !== false );
				if ( ! $path_matched ) {
					continue;
				}
			}

			return $info;
		}

		return null;
	}

	/**
	 * Modify a script tag to block it.
	 *
	 * @param string $attributes Original attributes string.
	 * @param string $content    Inline script content.
	 * @param string $src        Original src attribute.
	 * @param string $name       Service name.
	 * @param string $category   Category name.
	 * @since 0.0.1
	 * @return string Modified script tag.
	 */
	private function modify_script_tag( string $attributes, string $content, string $src, string $name, string $category ): string {
		// Map API category names to SureCookie category names.
		$mapped_category = $this->map_category( $category );

		$type          = $this->extract_script_type( $attributes );
		$original_type = $type !== null && in_array( $type, self::EXECUTABLE_SCRIPT_TYPES, true ) ? $type : 'none';

		// Strip any type attribute (quoted or unquoted HTML5 form).
		$new_attributes = preg_replace( '/\s*type\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attributes ) ?? $attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.

		// Remove src attribute (will be stored in data attribute).
		if ( ! empty( $src ) ) {
			$new_attributes = preg_replace( '/\s*src\s*=\s*["\'][^"\']*["\']/i', '', $new_attributes ) ?? $new_attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
		}

		// Build new script tag.
		$new_tag  = '<script type="text/plain"';
		$new_tag .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';
		$new_tag .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$new_tag .= ' data-surecookie-original-type="' . esc_attr( $original_type ) . '"';

		if ( ! empty( $src ) ) {
			$new_tag .= ' data-surecookie-src="' . esc_url( $src ) . '"';
		}

		// Add remaining attributes.
		$new_attributes = trim( (string) $new_attributes );
		if ( ! empty( $new_attributes ) ) {
			$new_tag .= ' ' . $new_attributes;
		}

		$new_tag .= '>' . $content . '</script>';

		return $new_tag;
	}

	/**
	 * Extract the normalized script `type` from a tag's attribute string.
	 *
	 * Handles quoted (`type="module"`, `type='module'`) and unquoted HTML5
	 * (`type=module`) forms, lowercases, trims whitespace, and strips MIME
	 * parameters (`text/javascript; charset=utf-8` → `text/javascript`).
	 *
	 * @param string $attributes Raw attributes string from the opening tag.
	 * @since 0.0.1-beta.2
	 * @return string|null Normalized type, or null when no (or empty) type attribute.
	 */
	private function extract_script_type( string $attributes ): ?string {
		if ( ! preg_match( '/type\s*=(?|\s*"([^"]*)"|\s*\'([^\']*)\'|([^\s>]+))/i', $attributes, $match ) ) {
			return null;
		}
		if ( $match[1] === '' ) {
			return null;
		}
		return strtolower( trim( explode( ';', $match[1], 2 )[0] ) );
	}

	/**
	 * Build an embedded-content placeholder for a blocked iframe/embed/object tag.
	 *
	 * Renders the same overlay UX ("This content is blocked… Accept & Load")
	 * for all three tag types; only the hidden inner element differs. The URL
	 * is stored in `data-surecookie-src` for iframe/embed (both use `src=`) and
	 * `data-surecookie-data` for object (uses `data=` attribute). consentManager.js
	 * restores the URL when the user consents.
	 *
	 * @param string $tag        One of 'iframe', 'embed', 'object'.
	 * @param string $attributes Original tag attributes string.
	 * @param string $url        Original resource URL (src for iframe/embed, data for object).
	 * @param string $name       Matched service key.
	 * @param string $category   Matched category.
	 * @param string $label      Human-readable vendor label.
	 * @param string $inner      For <object>, the neutralized inner HTML. Ignored otherwise.
	 * @since 0.0.1-beta.2
	 * @return string Placeholder HTML.
	 */
	private function build_embedded_placeholder(
		string $tag,
		string $attributes,
		string $url,
		string $name,
		string $category,
		string $label,
		string $inner = ''
	): string {
		$mapped_category = $this->map_category( $category );

		// Normalize tag.
		$tag = in_array( $tag, [ 'iframe', 'embed', 'object' ], true ) ? $tag : 'iframe';

		// Strip the URL attribute from the original attributes. <iframe>/<embed>
		// use src=; <object> uses data=.
		$url_attr       = $tag === 'object' ? 'data' : 'src';
		$new_attributes = preg_replace( '/\s*(?<![-\w])' . $url_attr . '\s*=\s*["\'][^"\']*["\']/i', '', $attributes ) ?? $attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.

		// Extract any existing `style` attribute from the original tag and strip it
		// so we can merge its value with our `display:none;` declaration. Emitting
		// two `style` attributes is invalid HTML - the HTML parser keeps only the
		// first, which would silently discard our `display:none;` and leave the
		// blocked element visible.
		$existing_style = '';
		if ( preg_match( '/style\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', (string) $new_attributes, $style_match ) === 1 ) {
			$existing_style = trim( $style_match[1] !== '' ? $style_match[1] : ( $style_match[2] ?? '' ) );
			$new_attributes = preg_replace( '/\s*style\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', (string) $new_attributes ) ?? $new_attributes; // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- No /e modifier used, safe replacement.
		}

		// Append `display:none;` so it always wins the cascade for inline styles
		// (later declarations override earlier ones for the same property).
		$combined_style = $existing_style;
		if ( $combined_style !== '' && substr( $combined_style, -1 ) !== ';' ) {
			$combined_style .= ';';
		}
		$combined_style .= 'display:none;';

		// Attribute used to stash the URL on the hidden element for later restore.
		$data_url_attr = $tag === 'object' ? 'data-surecookie-data' : 'data-surecookie-src';

		// Embed dimensions from the tag, used only as a pre-paint fallback size.
		$original_width  = preg_match( '/(?:^|\s)width\s*=\s*["\']?(\d+)/i', $attributes, $w_match ) === 1 ? (int) $w_match[1] : 0;
		$original_height = preg_match( '/(?:^|\s)height\s*=\s*["\']?(\d+)/i', $attributes, $h_match ) === 1 ? (int) $h_match[1] : 0;

		// Fallback only; consentManager.matchPlaceholderSizes() then sets the
		// exact height from the embed's real (often CSS-driven) rendered box.
		if ( $original_height > 0 ) {
			$wrapper_style = sprintf( 'width:100%%;height:%dpx;', $original_height );
		} else {
			$wrapper_style = 'width:100%;min-height:160px;';
		}

		// Outer placeholder wrapper.
		$placeholder  = '<div class="surecookie-placeholder surecookie-placeholder-' . esc_attr( $name ) . '"';
		$placeholder .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$placeholder .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';
		if ( $original_width > 0 ) {
			$placeholder .= ' data-surecookie-width="' . esc_attr( (string) $original_width ) . '"';
		}
		if ( $original_height > 0 ) {
			$placeholder .= ' data-surecookie-height="' . esc_attr( (string) $original_height ) . '"';
		}
		$placeholder .= ' style="' . esc_attr( $wrapper_style ) . '"';
		$placeholder .= '>';

		// Placeholder overlay (text + Accept & Load button). Quarantined trackers
		// (Pro Compliance Guard) are held until an admin reviews them and can never
		// be released by visitor consent (see ConsentManager.isAllowed), so the
		// "Accept & Load" button is omitted - it would be a dead control - and the
		// copy stays neutral rather than implying consent would load the content.
		$is_quarantined = $mapped_category === self::QUARANTINE_CATEGORY;
		$placeholder   .= '<div class="surecookie-placeholder-content">';
		$placeholder   .= '<p class="surecookie-placeholder-text">';
		if ( $is_quarantined ) {
			$placeholder .= esc_html__( 'This content is currently blocked.', 'surecookie' );
		} else {
			$placeholder .= sprintf(
				/* translators: %s: Service name (e.g., YouTube, Google Maps) */
				esc_html__( 'This content is blocked because it requires %s cookies.', 'surecookie' ),
				esc_html( $label )
			);
		}
		$placeholder .= '</p>';
		if ( ! $is_quarantined ) {
			// aria-label carries the service name so multiple blocked embeds on
			// one page don't all expose the identical accessible name "Accept &
			// Load" to screen readers.
			$button_aria = sprintf(
				/* translators: %s: Service name (e.g., YouTube, Google Maps) */
				__( 'Accept and load %s content', 'surecookie' ),
				$label
			);
			$placeholder .= '<button type="button" class="surecookie-placeholder-button" data-surecookie-category="' . esc_attr( $mapped_category ) . '" aria-label="' . esc_attr( $button_aria ) . '">';
			$placeholder .= esc_html__( 'Accept & Load', 'surecookie' );
			$placeholder .= '</button>';
		}
		$placeholder .= '</div>';

		// Hidden element (restored on consent).
		$placeholder .= '<' . $tag;
		$placeholder .= ' ' . $data_url_attr . '="' . esc_url( $url ) . '"';
		$placeholder .= ' data-surecookie-name="' . esc_attr( $name ) . '"';
		$placeholder .= ' data-surecookie-category="' . esc_attr( $mapped_category ) . '"';

		$trimmed_attrs = trim( (string) $new_attributes );
		if ( $trimmed_attrs !== '' ) {
			$placeholder .= ' ' . $trimmed_attrs;
		}

		$placeholder .= ' style="' . esc_attr( $combined_style ) . '"';

		if ( $tag === 'embed' ) {
			// <embed> is void / self-closing.
			$placeholder .= ' />';
		} else {
			$placeholder .= '>' . $inner . '</' . $tag . '>';
		}

		$placeholder .= '</div>';

		return $placeholder;
	}

	/**
	 * Map API category names to SureCookie category names.
	 *
	 * @param string $category Original category name.
	 * @since 0.0.1
	 * @return string Mapped category name.
	 */
	private function map_category( string $category ): string {
		return apply_filters( 'surecookie_map_category', $category, $category );
	}

	/**
	 * Check if this request is from the SaaS scanner with a valid bypass token.
	 *
	 * @since 0.0.0-alpha.2
	 * @since 0.0.1-beta.2 Strict 64-char hex validation; replaces sanitize_text_field().
	 * @since 1.3.0 Delegates to Utils so integrations that gate outside the output buffer share it.
	 * @return bool
	 */
	private function is_scan_bypass_request(): bool {
		return Utils::is_scan_bypass_request();
	}
}
