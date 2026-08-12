<?php
/**
 * Presto Player - Block Handler
 *
 * Wraps every server-rendered Presto Player block (YouTube, Vimeo, Bunny.net,
 * self-hosted video, audio) in a SureCookie placeholder. The original Presto
 * markup is stashed inside an inert `<template>` element so its `<presto-player>`
 * custom element is never upgraded (no third-party iframe loads, no cookies set)
 * until accept.
 *
 * Gating is intentionally consent-agnostic on the server (cache-safe): the block
 * is always wrapped regardless of the request's consent cookie, so a full-page
 * cache can't serve an un-gated player warmed by a consented visitor.
 * consentManager.js restores the block for visitors who have already consented,
 * cloning the template's content back into the DOM. Presto's runtime - already
 * loaded because its render_callback ran - then auto-upgrades the new
 * `<presto-player>` element. Same UX as raw YouTube/Vimeo embeds: in-place
 * restore, no reload. This mirrors the core script blocker's model.
 *
 * @package SureCookie\Inc\Integrations\PrestoPlayer
 * @since 1.2.4
 */

namespace SureCookie\Inc\Integrations\PrestoPlayer;

use SureCookie\Inc\Modules\ScriptBlocking\Utils;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Block_Handler class.
 *
 * @since 1.2.4
 */
class Block_Handler {
	use GetInstance;

	/**
	 * Container blocks that render through `render_block` but build the player
	 * indirectly (Media Hub / reusable video, playlist, popup media) - so the
	 * inner provider block never reaches this filter. Handled by resolving the
	 * referenced video's provider from its post.
	 */
	private const CONTAINER_BLOCKS = [
		'presto-player/reusable-display',
		'presto-player/media-hub',
		'presto-player/playlist',
		'presto-player/popup-trigger',
	];

	/**
	 * Map of Presto Player block names → service / category / label.
	 */
	private const PROVIDER_MAP = [
		'presto-player/youtube'     => [
			'service'  => 'youtube',
			'category' => 'marketing',
			'label'    => 'YouTube',
		],
		'presto-player/vimeo'       => [
			'service'  => 'vimeo',
			'category' => 'marketing',
			'label'    => 'Vimeo',
		],
		'presto-player/bunny'       => [
			'service'  => 'bunny',
			'category' => 'marketing',
			'label'    => 'Bunny.net',
		],
		'presto-player/self-hosted' => [
			'service'  => 'self-hosted',
			'category' => 'functional',
			'label'    => 'Video',
		],
		'presto-player/audio'       => [
			'service'  => 'audio',
			'category' => 'functional',
			'label'    => 'Audio',
		],
	];

	/**
	 * True while rendering a block nested inside a Presto popup. Popups render
	 * their player through the WP Interactivity API in a `wp_footer` template,
	 * which the placeholder/restore mechanism can't safely wrap - so we leave
	 * popup videos to Presto (they stay inert in that template until opened).
	 *
	 * @var bool
	 */
	private $in_popup = false;

	/**
	 * Constructor.
	 *
	 * @since 1.2.4
	 */
	private function __construct() {
		add_filter( 'render_block', [ $this, 'wrap_block' ], 10, 2 );
		add_filter( 'render_block_context', [ $this, 'flag_popup_descendant' ], 10, 3 );
	}

	/**
	 * Flag whether the block about to render is a direct child of a popup, using
	 * the parent block passed to `render_block_context`.
	 *
	 * @since 1.2.4
	 * @param array<string, mixed> $context      Block context.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @param \WP_Block|null       $parent_block The parent block, if any.
	 * @return array<string, mixed>
	 */
	public function flag_popup_descendant( $context, $parsed_block, $parent_block ): array {
		// Only skip the footer player (popup-media child); the popup trigger is
		// gated normally so blocking it stops the popup from ever opening.
		$this->in_popup = $parent_block instanceof \WP_Block
			&& $parent_block->name === 'presto-player/popup-media';
		return is_array( $context ) ? $context : [];
	}

	/**
	 * Wrap rendered Presto blocks in a consent placeholder.
	 *
	 * Pass-through for non-Presto blocks, popup descendants, admin/AJAX/REST
	 * renders, and when blocking is globally off (geo-rules, etc.). Wrapping is
	 * consent-agnostic on the server (cache-safe); consentManager.js restores the
	 * block client-side for visitors who have already consented.
	 *
	 * @since 1.2.4
	 * @param string               $block_content The rendered block markup.
	 * @param array<string, mixed> $block         The block array (`blockName`, `attrs`, ...).
	 * @return string
	 */
	public function wrap_block( $block_content, $block ): string {
		// Cast once - render_block can pass non-string $block_content for some
		// dynamic blocks; the method return type requires string.
		$block_content = (string) $block_content;
		$block_name    = is_array( $block ) ? (string) ( $block['blockName'] ?? '' ) : '';

		$is_container = in_array( $block_name, self::CONTAINER_BLOCKS, true );
		if ( $block_name === '' || ( ! isset( self::PROVIDER_MAP[ $block_name ] ) && ! $is_container ) ) {
			return $block_content;
		}

		// Inside a popup: leave it to Presto (its Interactivity footer template
		// keeps the player inert until the popup is opened anyway).
		if ( $this->in_popup ) {
			return $block_content;
		}

		// Skip the editor preview (REST), admin pages, AJAX - admins need to
		// see the actual player while configuring blocks.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $block_content;
		}

		if ( ! Utils::is_blocking_enabled() || ! Utils::should_process_based_on_geo() ) {
			return $block_content;
		}

		// Let the scanner see the real player. Unlike a blocked <script>/<iframe>,
		// the placeholder below keeps only the service name and category - the
		// provider URL is gone - so a gated Presto block is undetectable by any
		// scan, and its host (e.g. a Bunny Stream delivery zone) never reaches the
		// cookie catalog. The Blocker short-circuits on the same token, but that
		// only covers the output buffer, and `render_block` runs before it.
		if ( Utils::is_scan_bypass_request() ) {
			return $block_content;
		}

		$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

		$provider = $is_container
			? $this->resolve_container_provider( $block_name, $attributes, $block )
			: $this->resolve_provider( $block_name, $attributes );
		if ( $provider === null ) {
			return $block_content;
		}

		/**
		 * Filter: Allow bypassing Presto-block consent gating for a specific block.
		 *
		 * @since 1.2.4
		 * @param bool                                                     $skip       True to skip blocking (default false).
		 * @param string                                                   $block_name Presto block name.
		 * @param array<string, mixed>                                     $attributes Block attributes.
		 * @param array{service: string, category: string, label: string}  $provider   Resolved provider info.
		 */
		if ( apply_filters( 'surecookie_skip_presto_block', false, $block_name, $attributes, $provider ) ) {
			return $block_content;
		}

		// Always wrap server-side (cache-safe): the rendered HTML must not vary on
		// the visitor's consent cookie, or a full-page cache warmed by a consented
		// visitor would serve un-gated players to everyone. consentManager.js
		// restores the block in place for visitors who have already consented - the
		// same model the core script blocker uses for iframes/scripts.
		return $this->build_placeholder( $provider, $attributes, $block_content );
	}

	/**
	 * Build the placeholder wrapper. Visible overlay + inert `<template>`
	 * carrying the original Presto markup for client-side restoration.
	 *
	 * @since 1.2.4
	 * @param array{service: string, category: string, label: string} $provider      Resolved provider info.
	 * @param array<string, mixed>                                    $attributes    Block attributes.
	 * @param string                                                  $block_content Original rendered block.
	 * @return string
	 */
	private function build_placeholder( array $provider, array $attributes, string $block_content ): string {
		$service  = $provider['service'];
		$category = $provider['category'];

		$is_audio      = $service === 'audio';
		$wrapper_style = $this->aspect_ratio_style( $is_audio, $attributes );

		$out  = '<div class="surecookie-placeholder surecookie-placeholder-presto surecookie-placeholder-' . esc_attr( $service ) . '"';
		$out .= ' data-surecookie-name="' . esc_attr( $service ) . '"';
		$out .= ' data-surecookie-category="' . esc_attr( $category ) . '"';
		if ( $wrapper_style !== '' ) {
			$out .= ' style="' . esc_attr( $wrapper_style ) . '"';
		}
		$out .= '>';

		$out .= '<div class="surecookie-placeholder-content">';
		$out .= '<p class="surecookie-placeholder-text">';
		$out .= esc_html( $this->placeholder_message( $provider['label'] ) );
		$out .= '</p>';
		// aria-label carries the provider name so multiple blocked embeds don't
		// all expose the identical accessible name "Accept & Load".
		$button_aria = sprintf(
			/* translators: %s: Provider name (e.g., YouTube, Vimeo) */
			__( 'Accept and load %s content', 'surecookie' ),
			$provider['label']
		);
		$out .= '<button type="button" class="surecookie-placeholder-button" data-surecookie-category="' . esc_attr( $category ) . '" aria-label="' . esc_attr( $button_aria ) . '">';
		$out .= esc_html__( 'Accept & Load', 'surecookie' );
		$out .= '</button>';
		$out .= '</div>';

		// Inert template - custom elements inside don't upgrade until cloned.
		// consentManager.js clones the content into the placeholder's position
		// on accept; Presto's runtime then upgrades the new <presto-player>.
		$out .= '<template class="surecookie-presto-restore">' . $block_content . '</template>';

		$out .= '</div>';

		return $out;
	}

	/**
	 * Inline `aspect-ratio` style derived from block attributes, so the
	 * placeholder covers the same visual footprint Presto would have.
	 *
	 * @since 1.2.4
	 * @param bool                 $is_audio   True for audio blocks (no aspect ratio).
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string CSS declaration (with trailing semicolon) or empty string.
	 */
	private function aspect_ratio_style( bool $is_audio, array $attributes ): string {
		if ( $is_audio ) {
			return '';
		}

		$ratio = isset( $attributes['aspect_ratio'] ) && is_string( $attributes['aspect_ratio'] )
			? trim( $attributes['aspect_ratio'] )
			: '';

		// Presto stores ratios like "16:9", "4:3", "auto", "". Default to 16:9 for video.
		if ( $ratio === '' || $ratio === 'auto' || ! preg_match( '/^\d+\s*:\s*\d+$/', $ratio ) ) {
			$ratio = '16:9';
		}

		return 'aspect-ratio:' . str_replace( ':', '/', $ratio ) . ';';
	}

	/**
	 * Build the translated placeholder message for a provider label.
	 *
	 * Generic media labels ("Video", "Audio") get a self-contained sentence so
	 * they never read as "requires Video cookies". Brand-name labels (YouTube,
	 * Vimeo, Bunny.net) are proper nouns dropped into the branded message.
	 *
	 * @since 1.2.4
	 * @param string $label Raw label from PROVIDER_MAP / container resolution.
	 * @return string
	 */
	private function placeholder_message( string $label ): string {
		if ( $label === 'Audio' ) {
			return __( 'This audio is blocked until you accept cookies to load it.', 'surecookie' );
		}

		if ( $label === 'Video' ) {
			return __( 'This video is blocked until you accept cookies to load it.', 'surecookie' );
		}

		return sprintf(
			/* translators: %s: Service name (e.g., YouTube, Vimeo, Bunny.net). */
			__( 'This content is blocked because it requires %s cookies.', 'surecookie' ),
			$label
		);
	}

	/**
	 * Resolve provider info from the block name, after filter overrides.
	 *
	 * @since 1.2.4
	 * @param string               $block_name Presto block name.
	 * @param array<string, mixed> $attributes Block attributes (for filter context).
	 * @return array{service: string, category: string, label: string}|null
	 */
	private function resolve_provider( string $block_name, array $attributes ): ?array {
		/**
		 * Filter: Override the default Presto block → service/category map.
		 *
		 * @since 1.2.4
		 * @param array<string, array{service: string, category: string, label: string}> $map        Provider map.
		 * @param string                                                                  $block_name Presto block name.
		 * @param array<string, mixed>                                                    $attributes Block attributes.
		 */
		$map = apply_filters( 'surecookie_presto_provider_map', self::PROVIDER_MAP, $block_name, $attributes );

		if ( ! is_array( $map ) || ! isset( $map[ $block_name ] ) || ! is_array( $map[ $block_name ] ) ) {
			return null;
		}

		$entry = $map[ $block_name ];
		if ( empty( $entry['service'] ) || empty( $entry['category'] ) || empty( $entry['label'] ) ) {
			return null;
		}

		return [
			'service'  => (string) $entry['service'],
			'category' => (string) $entry['category'],
			'label'    => (string) $entry['label'],
		];
	}

	/**
	 * Resolve provider info for a container block (reusable / media-hub / playlist).
	 *
	 * @since 1.2.4
	 * @param string               $block_name Container block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $block      Full block array (for playlist inner items).
	 * @return array{service: string, category: string, label: string} Always fail-closed (never null).
	 */
	private function resolve_container_provider( string $block_name, array $attributes, array $block ): array {
		if ( $block_name === 'presto-player/playlist' ) {
			return $this->resolve_playlist_provider( $block );
		}

		// Popup trigger: gating the trigger button/thumbnail blocks the whole
		// popup (it can't open). The video lives in a sibling block, so the
		// provider isn't knowable here - gate under marketing (popups are
		// typically third-party video) with a generic label.
		if ( $block_name === 'presto-player/popup-trigger' ) {
			return [
				'service'  => 'presto-popup',
				'category' => 'marketing',
				'label'    => 'Video',
			];
		}

		// reusable-display / media-hub: gate by the referenced video's provider.
		// Fail closed when the provider can't be resolved (deleted video, unmapped
		// provider, or a renamed Presto model class - init.php intentionally gates
		// only on PRESTO_PLAYER_PLUGIN_FILE, not on \ReusableVideo existing). Mirror
		// the playlist / popup-trigger defaults so a container is never left ungated.
		$inner_name = $this->reusable_inner_block_name( isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0 );
		$provider   = $inner_name === '' ? null : $this->resolve_provider( $inner_name, $attributes );

		return $provider ?? [
			'service'  => 'presto-reusable',
			'category' => 'marketing',
			'label'    => 'Video',
		];
	}

	/**
	 * Look up the inner provider block name of a reusable video post, using
	 * Presto's own model so URL-based provider detection is respected.
	 *
	 * @since 1.2.4
	 * @param int $video_id Reusable video post ID.
	 * @return string Inner block name (e.g. `presto-player/youtube`), or '' when unresolved.
	 */
	private function reusable_inner_block_name( int $video_id ): string {
		if ( $video_id <= 0 || ! class_exists( '\PrestoPlayer\Models\ReusableVideo' ) ) {
			return '';
		}

		$inner = ( new \PrestoPlayer\Models\ReusableVideo( $video_id ) )->getBlock();
		return is_array( $inner ) ? (string) ( $inner['blockName'] ?? '' ) : '';
	}

	/**
	 * Resolve provider info for a playlist. Playlists render their items as JSON
	 * for a JS component (no per-item render_block), so the whole playlist is
	 * gated. Category is the strictest across items (marketing wins). Any item
	 * that resolves to marketing - or any item that cannot be resolved at all -
	 * forces the whole playlist to marketing, so it is never left ungated or
	 * silently downgraded to functional.
	 *
	 * @since 1.2.4
	 * @param array<string, mixed> $block Full playlist block array.
	 * @return array{service: string, category: string, label: string}
	 */
	private function resolve_playlist_provider( array $block ): array {
		$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
		$category     = 'functional';
		// Raw label - placeholder_message() keys on it, then translates the full
		// sentence, so keep it untranslated here.
		$label = 'Video';
		$found = false;

		foreach ( $inner_blocks as $item ) {
			$attrs = is_array( $item['attrs'] ?? null ) ? $item['attrs'] : [];
			$entry = $this->resolve_provider(
				$this->reusable_inner_block_name( isset( $attrs['id'] ) ? absint( $attrs['id'] ) : 0 ),
				[]
			);
			// An unresolvable item may itself be a marketing provider (orphaned or
			// deleted reference, renamed Presto model). Fail closed: gate the whole
			// playlist as marketing rather than risk downgrading it to functional.
			if ( $entry === null ) {
				return [
					'service'  => 'playlist',
					'category' => 'marketing',
					'label'    => $label,
				];
			}
			if ( ! $found ) {
				$label = $entry['label'];
				$found = true;
			}
			if ( $entry['category'] === 'marketing' ) {
				return [
					'service'  => 'playlist',
					'category' => 'marketing',
					'label'    => $entry['label'],
				];
			}
		}

		return [
			'service'  => 'playlist',
			'category' => $found ? $category : 'marketing',
			'label'    => $label,
		];
	}
}
