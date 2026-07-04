<?php
/**
 * Single-post renderer for Promptless CPT Pages.
 *
 * Orchestrates the page structure (hero / above_main / main / below_main /
 * sidebar / footer) and renders each grouping with the correct layout
 * variant. Output is escaped at every interpolation point; the underlying
 * data has already been validated through PCPTPages_Validator on save.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a registered CPT single-post page.
 *
 * Caching layer:
 *
 *   The full rendered HTML of each post is cached as a transient keyed on
 *   the post ID. The cache value carries `post_modified` and a per-CPT
 *   `groupings_changed` timestamp; both must match before a cached render
 *   is served. This way two events automatically invalidate the cache:
 *
 *     - The post is re-saved (post_modified bumps).
 *     - Any grouping definition for the post's CPT is updated (the
 *       updated_option hook bumps pcptpages_groupings_changed_{cpt_slug}).
 *
 *   Active invalidation also fires on save_post / before_delete_post /
 *   set_object_terms — these keep the transient store cleaner but aren't
 *   strictly required for correctness; the timestamp comparison would
 *   already orphan stale entries on the next read.
 *
 *   Users with edit_post capability bypass the cache so admin previews
 *   are always fresh. The connector's preview endpoint passes
 *   $use_cache=false explicitly for the same reason.
 *
 *   Filters:
 *     - `pcptpages_render_cache_enabled` (bool, $post): force-disable per post.
 *     - `pcptpages_render_cache_lifetime` (int, $post): TTL in seconds; default
 *       1 hour. Caching plugins (WP Rocket, W3TC) cache the full page so
 *       this layer matters most for pages without those plugins active.
 *
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query
 * phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
 *
 * Justification: The "related posts" footer block legitimately uses
 * tax_query (to find sibling posts sharing a taxonomy term) and
 * post__not_in (to exclude the current post from the related list).
 * These are documented WordPress query patterns flagged as advisory.
 */
class PCPTPages_Renderer {

	/**
	 * Transient key prefix for cached renders.
	 *
	 * @var string
	 */
	const CACHE_KEY_PREFIX = 'pcptpages_render_';

	/**
	 * Default cache TTL — 1 hour. Short enough that referenced-attachment
	 * deletions and other indirect-dependency changes self-heal quickly;
	 * long enough to make cache hits common for typical traffic.
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Source resolver dependency.
	 *
	 * @var PCPTPages_Source_Resolver
	 */
	private $source_resolver;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->source_resolver = new PCPTPages_Source_Resolver();
	}

	/**
	 * Wire cache-invalidation hooks. Called once from the main plugin
	 * bootstrap; idempotent if called more than once (WP de-dupes
	 * add_action by callable).
	 */
	public static function init_cache_invalidation() {
		// Active invalidation on the most common change paths. These keep
		// the transient store clean; correctness is also guaranteed by
		// the cache-key timestamp comparison on read.
		add_action( 'save_post', array( __CLASS__, 'invalidate_post_cache' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'invalidate_post_cache' ), 10, 1 );
		add_action( 'set_object_terms', array( __CLASS__, 'invalidate_post_cache' ), 10, 1 );

		// When a CPT's grouping definitions change, bump a per-CPT
		// timestamp. The next read of every cached post in that CPT will
		// see a mismatched timestamp and re-render. We hook the generic
		// updated/added option actions (rather than per-CPT-named hooks)
		// because grouping options are dynamically named.
		add_action( 'updated_option', array( __CLASS__, 'maybe_bump_groupings_changed' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'maybe_bump_groupings_changed_on_add' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'maybe_bump_groupings_changed_on_delete' ), 10, 1 );

		// When a CPT DEFINITION changes (hero_layout, hero_theme, hero_width,
		// archive flags, labels …), bump the same per-CPT timestamp. All CPT
		// definitions live in the single `pcptpages_cpts` option, which does
		// NOT match the groupings prefix above — before this hook existed,
		// definition edits left stale cached renders for up to the cache TTL
		// (pre-existing bug fixed as part of docs/HERO_CONTRAST_DESIGN.md).
		// We reuse the groupings_changed timestamp rather than adding a
		// parallel key so cache-key composition stays single-sourced.
		add_action( 'pcptpages_cpt_registered', array( __CLASS__, 'bump_cpt_changed' ), 10, 1 );
		add_action( 'pcptpages_cpt_unregistered', array( __CLASS__, 'bump_cpt_changed' ), 10, 1 );
	}

	/**
	 * Bump the per-CPT change timestamp when a CPT definition is registered,
	 * updated, or removed, so cached renders of that CPT's posts invalidate
	 * on next read.
	 *
	 * @param string $cpt_slug CPT slug passed by pcptpages_cpt_(un)registered.
	 */
	public static function bump_cpt_changed( $cpt_slug ) {
		if ( ! is_string( $cpt_slug ) || $cpt_slug === '' ) {
			return;
		}
		update_option( 'pcptpages_groupings_changed_' . sanitize_key( $cpt_slug ), time() );
	}

	/**
	 * Bust a single post's cached render. Safe to call with any post_id —
	 * if no transient exists, this is a no-op.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate_post_cache( $post_id ) {
		delete_transient( self::CACHE_KEY_PREFIX . (int) $post_id );
	}

	/**
	 * Bump the per-CPT `groupings_changed` timestamp when a
	 * `pcptpages_groupings_{cpt_slug}` option is updated. Read at render time;
	 * mismatched values force a re-render.
	 *
	 * @param string $option    Option name being updated.
	 * @param mixed  $old_value Previous value (unused).
	 * @param mixed  $value     New value (unused).
	 */
	public static function maybe_bump_groupings_changed( $option, $old_value = null, $value = null ) {
		if ( ! is_string( $option ) ) {
			return;
		}
		$prefix = PCPTPages_Grouping_Registry::OPTION_PREFIX;
		if ( strpos( $option, $prefix ) !== 0 ) {
			return;
		}
		$cpt_slug = substr( $option, strlen( $prefix ) );
		if ( $cpt_slug === '' ) {
			return;
		}
		update_option( 'pcptpages_groupings_changed_' . $cpt_slug, time() );
	}

	/**
	 * `added_option` action signature is ($option, $value) — adapter for
	 * maybe_bump_groupings_changed which expects three params.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	public static function maybe_bump_groupings_changed_on_add( $option, $value = null ) {
		self::maybe_bump_groupings_changed( $option, null, $value );
	}

	/**
	 * `deleted_option` action signature is ($option) — adapter.
	 *
	 * @param string $option Option name.
	 */
	public static function maybe_bump_groupings_changed_on_delete( $option ) {
		self::maybe_bump_groupings_changed( $option );
	}

	/**
	 * Render the full single-post page for the given post.
	 *
	 * @param WP_Post $post      Post to render.
	 * @param bool    $use_cache Whether to use the render cache. Defaults
	 *                           to true; the connector preview endpoint
	 *                           passes false to force a fresh render.
	 */
	public function render( WP_Post $post, $use_cache = true ) {
		// Logged-in users with edit_post capability always get a fresh
		// render — they may have just saved data and want immediate
		// visual confirmation, not a cached version from before the save.
		$can_edit = current_user_can( 'edit_post', $post->ID );

		/**
		 * Filter whether the render cache is active for this post.
		 *
		 * Defaults to true unless the user can edit the post. Site
		 * operators with bespoke caching needs (e.g. always-fresh CPTs)
		 * can return false here.
		 *
		 * @param bool    $enabled Whether caching is on.
		 * @param WP_Post $post    The post being rendered.
		 */
		$cache_enabled = $use_cache
			&& ! $can_edit
			&& apply_filters( 'pcptpages_render_cache_enabled', true, $post );

		if ( ! $cache_enabled ) {
			$this->render_internal( $post );
			return;
		}

		$cache_key    = self::CACHE_KEY_PREFIX . (int) $post->ID;
		$defs_changed = (int) get_option( 'pcptpages_groupings_changed_' . $post->post_type, 0 );
		$cached       = get_transient( $cache_key );

		if ( is_array( $cached )
			&& isset( $cached['html'], $cached['post_modified'], $cached['defs_changed'] )
			&& $cached['post_modified'] === $post->post_modified
			&& (int) $cached['defs_changed'] === $defs_changed
		) {
			// Cache hit. Echo the cached HTML and append a debug comment
			// when WP_DEBUG is on.
			echo $cached['html']; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				echo "\n<!-- pre-render-cache: HIT -->\n";
			}
			return;
		}

		// Cache miss. Render fresh, capture, store.
		ob_start();
		$this->render_internal( $post );
		$html = ob_get_clean();

		/**
		 * Filter the render-cache TTL in seconds.
		 *
		 * @param int     $lifetime Seconds. Default HOUR_IN_SECONDS.
		 * @param WP_Post $post     The post being cached.
		 */
		$lifetime = (int) apply_filters(
			'pcptpages_render_cache_lifetime',
			self::DEFAULT_CACHE_LIFETIME,
			$post
		);

		set_transient(
			$cache_key,
			array(
				'html'          => $html,
				'post_modified' => $post->post_modified,
				'defs_changed'  => $defs_changed,
			),
			$lifetime
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo "\n<!-- pre-render-cache: MISS -->\n";
		}
	}

	/**
	 * Actual render logic, separated from cache-handling. Public-facing
	 * code calls render() (which optionally caches); the connector's
	 * preview endpoint calls render( $post, false ) to bypass.
	 *
	 * @param WP_Post $post Post to render.
	 */
	private function render_internal( WP_Post $post ) {
		$plugin      = pcptpages();
		$cpt_def     = $plugin->cpts ? $plugin->cpts->get( $post->post_type ) : null;
		$definitions = $plugin->groupings ? $plugin->groupings->get_all( $post->post_type ) : array();

		// Hero layout flows from the CPT definition. Falls back to 'stacked'
		// + 'left' + 'square' for any CPT registered before these fields
		// existed. Aspect only applies to split layouts; stacked is always
		// 16:9 regardless of the stored aspect.
		$hero_layout         = is_array( $cpt_def ) && ! empty( $cpt_def['hero_layout'] )
			? $cpt_def['hero_layout']
			: 'stacked';
		$hero_image_position = is_array( $cpt_def ) && ! empty( $cpt_def['hero_image_position'] )
			? $cpt_def['hero_image_position']
			: 'left';
		$hero_image_aspect   = is_array( $cpt_def ) && ! empty( $cpt_def['hero_image_aspect'] )
			? $cpt_def['hero_image_aspect']
			: 'square';

		// Hero contrast/width (docs/HERO_CONTRAST_DESIGN.md). 'inherit' and
		// 'contained' are the no-op defaults — they emit no extra classes,
		// keeping markup byte-identical to pre-Phase-A output for every CPT
		// that hasn't opted in.
		$hero_theme = is_array( $cpt_def ) && ! empty( $cpt_def['hero_theme'] )
			? $cpt_def['hero_theme']
			: 'inherit';
		$hero_width = is_array( $cpt_def ) && ! empty( $cpt_def['hero_width'] )
			? $cpt_def['hero_width']
			: 'contained';
		$hero_overlay_focus = is_array( $cpt_def ) && ! empty( $cpt_def['hero_overlay_focus'] )
			? $cpt_def['hero_overlay_focus']
			: 'center';

		// CPT-level default icon. Used as a fallback when:
		//   - a grouping item has neither image_id nor icon_id set (any
		//     variant — gives every auto-source row a baseline visual cue
		//     without requiring per-post _pcptpages_icon meta)
		//   - the variant is icon-only (compact-grid, horizontal-row) and
		//     the item only has an image_id (variant intent overrides the
		//     item's image; icon falls through to default)
		// Validated against PCPTPages_Icon_Library at registration time, so we
		// don't need to re-validate here. Passed down to render_grouping
		// → render_item so each item resolution sees the same default.
		$cpt_default_icon = is_array( $cpt_def ) && ! empty( $cpt_def['default_icon'] )
			? (string) $cpt_def['default_icon']
			: '';
		$values      = $plugin->post_data ? $plugin->post_data->get_groupings( $post->ID ) : array();

		// Index post values by grouping_key for easy lookup.
		$values_by_key = array();
		foreach ( $values as $entry ) {
			if ( ! empty( $entry['grouping_key'] ) ) {
				$values_by_key[ $entry['grouping_key'] ] = $entry;
			}
		}

		// Resolve each defined grouping into its rendered shape (effective
		// position, effective variant, resolved items).
		$resolved = array();
		foreach ( $definitions as $key => $def ) {
			$entry             = isset( $values_by_key[ $key ] ) ? $values_by_key[ $key ] : array();
			$position          = ! empty( $entry['position'] ) ? $entry['position'] : ( $def['default_position'] ?? 'above_main' );
			$variant           = ! empty( $entry['variant_override'] ) ? $entry['variant_override'] : ( $def['default_variant'] ?? 'compact-grid' );
			$items             = $this->source_resolver->resolve( $entry, $def, $post );

			$resolved[ $key ] = array(
				'key'      => $key,
				'def'      => $def,
				'entry'    => $entry,
				'position' => $position,
				'variant'  => $variant,
				'items'    => $items,
			);
		}

		// Bucket by position for layout.
		$above_main = array();
		$below_main = array();
		$sidebar    = array();
		foreach ( $resolved as $g ) {
			switch ( $g['position'] ) {
				case 'sidebar':
					$sidebar[] = $g;
					break;
				case 'below_main':
					$below_main[] = $g;
					break;
				case 'above_main':
				default:
					$above_main[] = $g;
					break;
			}
		}

		$has_sidebar = ! empty( $sidebar );
		$body_class  = $has_sidebar ? 'pre-body pre-body--with-sidebar' : 'pre-body';

		// A full-bleed hero needs overflow coordination on the article itself
		// (overflow-x: clip kills the 100vw scrollbar sliver) — a child class
		// alone can't provide that, hence the container-level class.
		$article_classes = 'pre-single pre-single--' . sanitize_html_class( $post->post_type );
		if ( 'full' === $hero_width ) {
			$article_classes .= ' pre-single--hero-full';
		}

		?>
		<article id="post-<?php echo esc_attr( $post->ID ); ?>" <?php post_class( $article_classes ); ?>>
			<?php $this->render_hero( $post, $hero_layout, $hero_image_position, $hero_image_aspect, $hero_theme, $hero_width, $hero_overlay_focus ); ?>

			<div class="<?php echo esc_attr( $body_class ); ?>">
				<div class="pre-body__main">
					<?php foreach ( $above_main as $g ) : ?>
						<?php $this->render_grouping( $g, $cpt_default_icon ); ?>
					<?php endforeach; ?>

					<?php if ( post_type_supports( $post->post_type, 'editor' ) ) : ?>
						<div class="pre-content">
							<?php
							// Why both `$GLOBALS['post'] = $post` AND
							// `setup_postdata( $post )`:
							//
							//   the_content() ultimately calls get_post()
							//   without arguments, which reads the global
							//   `$post`. setup_postdata( $post ) ONLY
							//   updates the derived globals ($id, $page,
							//   $authordata, $more, $pages, $multipage,
							//   $preview, etc.) — it does NOT set the
							//   global $post itself. In a normal theme
							//   loop, the_post() updates the global $post
							//   AND calls setup_postdata; outside a loop
							//   (e.g. our REST preview endpoint, or
							//   render() being called from a theme template
							//   that hasn't started the loop yet), neither
							//   global is set unless we set it explicitly.
							//
							//   Without the GLOBALS assignment, the_content()
							//   silently returned empty in REST preview
							//   contexts, producing an empty <div class=
							//   "pre-content"></div> in the rendered HTML
							//   — discovered during the 2026-05-10 connector
							//   pressure test on the staging site.
							//
							//   Backup-and-restore the global so we don't
							//   leave the request thread with a $post that
							//   doesn't match its calling context (e.g.
							//   shortcode renders in widgets / sidebars
							//   that come after this render).
							$pcptpages_render_original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
							$GLOBALS['post']          = $post;
							setup_postdata( $post );
							the_content();
							wp_reset_postdata();
							$GLOBALS['post'] = $pcptpages_render_original_post;
							?>
						</div>
					<?php endif; ?>

					<?php foreach ( $below_main as $g ) : ?>
						<?php $this->render_grouping( $g, $cpt_default_icon ); ?>
					<?php endforeach; ?>
				</div>

				<?php if ( $has_sidebar ) : ?>
					<aside class="pre-body__sidebar">
						<?php foreach ( $sidebar as $g ) : ?>
							<?php $this->render_grouping( $g, $cpt_default_icon ); ?>
						<?php endforeach; ?>
					</aside>
				<?php endif; ?>
			</div>

			<?php $this->render_related_footer( $post ); ?>
		</article>
		<?php
	}

	/**
	 * Render the hero block: title + featured image + excerpt.
	 *
	 * Two layouts:
	 *
	 *   stacked (default) — featured image is a 16:9 banner above the
	 *   title + excerpt block. Suits editorial CPTs (events, courses,
	 *   articles) where the image acts as a visual lead-in.
	 *
	 *   split — featured image is side-by-side with the title + excerpt
	 *   on desktop (50/50 grid, 1:1 aspect ratio). Stacks to single column
	 *   on mobile. Suits profile-shaped CPTs (real estate listings,
	 *   attorney bios, team members) where the image and text carry
	 *   equal visual weight. The image_position flag swaps left/right.
	 *
	 * Without a featured image, both layouts collapse to a clean text-
	 * only hero — no empty image slot. The .pre-hero--has-image class
	 * is added when an image is present so CSS can branch its grid
	 * behavior on it.
	 *
	 * Document order: image first when present, then text block. Mobile
	 * stacks naturally in this order; desktop split with image-right
	 * uses CSS `order` to flip the visual position without changing
	 * screen-reader reading order.
	 *
	 * @param WP_Post $post           Post being rendered.
	 * @param string  $layout         'stacked' | 'split' | 'overlay'.
	 * @param string  $image_position 'left' | 'right' (only meaningful for split).
	 * @param string  $image_aspect   'square' | 'landscape' | 'wide' (only meaningful for split).
	 * @param string  $theme          'inherit' | 'light' | 'dark' (docs/HERO_CONTRAST_DESIGN.md).
	 * @param string  $width          'contained' | 'full' (docs/HERO_CONTRAST_DESIGN.md).
	 * @param string  $overlay_focus  'top' | 'center' | 'bottom' (only meaningful for overlay).
	 */
	private function render_hero( WP_Post $post, $layout = 'stacked', $image_position = 'left', $image_aspect = 'square', $theme = 'inherit', $width = 'contained', $overlay_focus = 'center' ) {
		$has_thumbnail = has_post_thumbnail( $post->ID );
		$excerpt       = $post->post_excerpt;
		$title         = get_the_title( $post );

		// Defensive normalization — the renderer should never crash on a
		// stored CPT definition with malformed values, even if the
		// validator somehow let a bad value through.
		$layout         = in_array( $layout, array( 'stacked', 'split', 'overlay' ), true ) ? $layout : 'stacked';
		$image_position = in_array( $image_position, array( 'left', 'right' ), true ) ? $image_position : 'left';
		$image_aspect   = in_array( $image_aspect, array( 'square', 'landscape', 'wide' ), true ) ? $image_aspect : 'square';
		$theme          = in_array( $theme, PCPTPages_Validator::HERO_THEMES, true ) ? $theme : 'inherit';
		$width          = in_array( $width, PCPTPages_Validator::HERO_WIDTHS, true ) ? $width : 'contained';
		$overlay_focus  = in_array( $overlay_focus, PCPTPages_Validator::HERO_OVERLAY_FOCUS, true ) ? $overlay_focus : 'center';

		// Overlay requires an image by definition — text sits ON the photo.
		// Posts without a featured image fall back to the stacked treatment
		// (honoring hero_theme/hero_width), never an empty dark band.
		// Contract: docs/HERO_CONTRAST_DESIGN.md § Phase B rendering.
		if ( 'overlay' === $layout && ! $has_thumbnail ) {
			$layout = 'stacked';
		}

		if ( 'overlay' === $layout ) {
			$this->render_hero_overlay( $post, $title, $excerpt, $overlay_focus, $width, $theme );
			return;
		}

		$hero_classes = array( 'pre-hero', 'pre-hero--' . $layout );
		if ( $layout === 'split' ) {
			$hero_classes[] = 'pre-hero--image-' . $image_position;
			// Aspect only meaningful for split — stacked is always 16:9.
			// Emit unconditionally on split so CSS can branch even when
			// the post has no thumbnail (the empty media slot still
			// reserves correct shape on the grid).
			$hero_classes[] = 'pre-hero--aspect-' . $image_aspect;
		}
		if ( $has_thumbnail ) {
			$hero_classes[] = 'pre-hero--has-image';
		}
		// Contrast band + width (docs/HERO_CONTRAST_DESIGN.md). 'inherit' and
		// 'contained' deliberately emit NOTHING — pre-Phase-A markup must
		// stay byte-identical for CPTs that haven't opted in.
		if ( 'inherit' !== $theme ) {
			$hero_classes[] = 'pre-hero--theme-' . $theme;
		}
		if ( 'full' === $width ) {
			$hero_classes[] = 'pre-hero--full';
		}

		// v1.1: render post fields if the CPT has any registered. We render
		// each position individually via render_position_html() — this is
		// the building-block API the card renderer exposes for exactly this
		// "inline at semantic points" use case. Previous versions extracted
		// positions from a single render() output using a regex; that
		// approach broke when a position contained nested divs (e.g. the
		// `progress` display type) because the non-greedy match stopped at
		// the wrong </div>. render_position_html is self-contained per
		// position with no string parsing involved.
		$card_renderer = new PCPTPages_Card_Renderer();

		?>
		<header class="<?php echo esc_attr( implode( ' ', $hero_classes ) ); ?>">
			<div class="pre-hero__inner">
				<?php if ( $has_thumbnail ) : ?>
					<div class="pre-hero__media">
						<?php
						// Alt-text fallback: attachment alt wins; fall back
						// to the post title when no alt is saved.
						$thumb_id  = get_post_thumbnail_id( $post->ID );
						$saved_alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
						$args      = array( 'class' => 'pre-hero__image' );
						if ( $saved_alt === '' ) {
							$args['alt'] = $title;
						}
						// Pick the size that matches the layout. Stacked is a
						// full-width banner so we want the largest available
						// source. Split is capped at 640px wide for the
						// largest aspect (16:9), so `large` (1024px max
						// bound) covers all three split aspect variants
						// without forcing the browser to scale down a 2MB
						// hero image.
						$image_size = $layout === 'stacked' ? 'full' : 'large';
						echo get_the_post_thumbnail( $post->ID, $image_size, $args );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $card_renderer->render_position_html( $post->ID, 'image_overlay', 'single_hero' );
						?>
					</div>
				<?php endif; ?>

				<div class="pre-hero__text">
					<?php
					// Headline-position fields render above the title (the
					// "price above the address" pattern).
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'headline', 'single_hero' );
					?>

					<h1 class="pre-hero__title"><?php echo esc_html( $title ); ?></h1>

					<?php
					// Subtitle-position fields render directly under the title.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'subtitle', 'single_hero' );
					?>

					<?php if ( $excerpt !== '' ) : ?>
						<p class="pre-hero__excerpt"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>

					<?php
					// meta_strip and footer_meta land below the excerpt
					// but inside the text column.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'meta_strip', 'single_hero' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'footer_meta', 'single_hero' );
					?>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Overlay hero (Phase B, docs/HERO_CONTRAST_DESIGN.md): the featured
	 * image fills the band, a fixed bottom-weighted scrim guarantees
	 * contrast, and the text block sits bottom-left on top of it.
	 *
	 * Only called when the post HAS a featured image — render_hero()
	 * falls back to the stacked treatment otherwise.
	 *
	 * Notes:
	 *   - hero_theme is IGNORED here by design: text always uses the dark
	 *     token set because it sits on a darkened photograph regardless of
	 *     page mode. The theme class is still emitted for markup
	 *     consistency; the CSS overlay rules win on specificity.
	 *   - The image is the LCP element by construction, so it ships with
	 *     loading=eager + fetchpriority=high (mirrors Promptless WP's hero
	 *     LCP handling). Band height is fixed by CSS — no layout shift.
	 *   - image_overlay post fields render in-flow at the TOP of the band
	 *     (same "badge over the image" semantics as other layouts); the
	 *     text block is pushed to the bottom with margin-top:auto.
	 *
	 * @param WP_Post $post          Post being rendered (has a thumbnail).
	 * @param string  $title         Pre-fetched post title.
	 * @param string  $excerpt       Raw post excerpt ('' when unset).
	 * @param string  $overlay_focus 'top' | 'center' | 'bottom' (normalized).
	 * @param string  $width         'contained' | 'full' (normalized).
	 * @param string  $theme         'inherit' | 'light' | 'dark' (normalized).
	 */
	private function render_hero_overlay( WP_Post $post, $title, $excerpt, $overlay_focus, $width, $theme ) {
		$card_renderer = new PCPTPages_Card_Renderer();

		$hero_classes = array(
			'pre-hero',
			'pre-hero--overlay',
			'pre-hero--focus-' . $overlay_focus,
			'pre-hero--has-image',
		);
		if ( 'inherit' !== $theme ) {
			$hero_classes[] = 'pre-hero--theme-' . $theme;
		}
		if ( 'full' === $width ) {
			$hero_classes[] = 'pre-hero--full';
		}

		// Alt-text fallback: attachment alt wins; fall back to the post
		// title when no alt is saved (same policy as the other layouts).
		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$saved_alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
		$args      = array(
			'class'         => 'pre-hero__image',
			'loading'       => 'eager',
			'fetchpriority' => 'high',
		);
		if ( $saved_alt === '' ) {
			$args['alt'] = $title;
		}

		?>
		<header class="<?php echo esc_attr( implode( ' ', $hero_classes ) ); ?>">
			<div class="pre-hero__backdrop" aria-hidden="true">
				<?php echo get_the_post_thumbnail( $post->ID, 'full', $args ); ?>
			</div>
			<div class="pre-hero__scrim" aria-hidden="true"></div>
			<div class="pre-hero__inner pre-hero__inner--overlay">
				<?php
				// image_overlay fields sit at the top of the band, in flow,
				// so they respect the band's padding in both widths.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $card_renderer->render_position_html( $post->ID, 'image_overlay', 'single_hero' );
				?>

				<div class="pre-hero__text pre-hero__text--overlay">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'headline', 'single_hero' );
					?>

					<h1 class="pre-hero__title"><?php echo esc_html( $title ); ?></h1>

					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'subtitle', 'single_hero' );
					?>

					<?php if ( $excerpt !== '' ) : ?>
						<p class="pre-hero__excerpt"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>

					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'meta_strip', 'single_hero' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $card_renderer->render_position_html( $post->ID, 'footer_meta', 'single_hero' );
					?>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Extract the markup for a single position container out of a
	 * PCPTPages_Card_Renderer-produced fields wrapper, so the single-post hero
	 * can interleave field positions with the post title, featured image,
	 * and excerpt.
	 *
	 * The card renderer produces one wrapper containing all positions in
	 * fixed order. For the single-post hero we want positions to FLANK
	 * the title and image, not all stack in one block. This helper does
	 * a simple regex extraction (the wrapper structure is deterministic,
	 * produced entirely by PCPTPages_Card_Renderer with no user input on the
	 * structure itself).
	 *
	 * Returns empty string when the requested position has no fields.
	 *
	 * @param string $fields_html The full output of PCPTPages_Card_Renderer::render.
	 * @param string $position    Position key (e.g. 'headline').
	 * @return string Position's container HTML or empty string.
	 */
	// Note: previous versions of this class had extract_position_field_html
	// and extract_overlay_field_html helpers that used a regex to slice
	// positions out of a single PCPTPages_Card_Renderer::render() output. They
	// were removed in v0.4.0 because the non-greedy regex `.*?</div>`
	// stopped at the first </div> it found, truncating positions whose
	// fields contained nested divs (specifically the `progress` display
	// type). The replacement is to call PCPTPages_Card_Renderer::render_position_html
	// once per position — no string parsing required, never had the bug.

	/**
	 * Render a single grouping using its effective variant.
	 *
	 * @param array  $g                Resolved grouping (see render() for shape).
	 * @param string $cpt_default_icon Optional CPT-level default icon ID; passed
	 *                                 through to render_item for fallback logic.
	 */
	private function render_grouping( array $g, $cpt_default_icon = '' ) {
		$variant = sanitize_html_class( $g['variant'] );
		$key     = sanitize_html_class( $g['key'] );
		$label   = isset( $g['def']['label'] ) ? $g['def']['label'] : '';
		$items   = $g['items'];

		// Empty groupings are hidden — no point rendering an empty section
		// container with just a heading.
		if ( empty( $items ) ) {
			return;
		}

		?>
		<section class="pre-grouping pre-grouping--<?php echo esc_attr( $variant ); ?>" data-grouping="<?php echo esc_attr( $key ); ?>">
			<?php if ( $label !== '' ) : ?>
				<h2 class="pre-grouping__label"><?php echo esc_html( $label ); ?></h2>
			<?php endif; ?>

			<ul class="pre-grouping__items">
				<?php foreach ( $items as $item ) : ?>
					<?php $this->render_item( $item, $variant, $cpt_default_icon ); ?>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Render a single grouping item.
	 *
	 * Link resolution: when an item has a stored `link_post_id`, that is
	 * the canonical reference for internal links. We resolve it via
	 * get_permalink() at render time, which makes stored data
	 * domain-portable — the same item works on staging, production, and
	 * after permalink-structure changes without database rewrites. The URL
	 * string is still rendered verbatim for non-internal links (anchors,
	 * tel:, mailto:, external URLs) and as a fallback when the referenced
	 * post has been trashed/deleted.
	 *
	 * @param array  $item             Item data ({image_id, icon_id, heading, supporting_text, link, link_post_id}).
	 * @param string $variant          The grouping's effective variant.
	 * @param string $cpt_default_icon CPT-level default icon ID for fallback.
	 */
	private function render_item( array $item, $variant, $cpt_default_icon = '' ) {
		$image_id        = isset( $item['image_id'] ) ? (int) $item['image_id'] : 0;
		$icon_id         = isset( $item['icon_id'] ) ? (string) $item['icon_id'] : '';
		$heading         = isset( $item['heading'] ) ? (string) $item['heading'] : '';
		$supporting_text = isset( $item['supporting_text'] ) ? (string) $item['supporting_text'] : '';
		$link            = isset( $item['link'] ) ? (string) $item['link'] : '';
		$link_post_id    = isset( $item['link_post_id'] ) ? (int) $item['link_post_id'] : 0;

		// If we have a post-ID reference, resolve it through WordPress's
		// permalink resolver. This is what makes the link domain-portable.
		// We also gate on the linked post being PUBLISHED — for non-existent
		// post_ids get_post() returns null (clear miss), but for trashed and
		// draft posts WordPress's get_permalink() still returns a valid
		// string (sometimes with __trashed appended, depending on rewrite
		// rules). Publishing a link to a trashed/draft destination would
		// produce a broken click; better to fall back to the stored literal
		// `link` field so the UI still renders something the user can
		// investigate. PT-9-1 in docs/PRESSURE_TESTS.md covers both cases.
		if ( $link_post_id > 0 ) {
			$linked = get_post( $link_post_id );
			if ( $linked instanceof WP_Post && $linked->post_status === 'publish' ) {
				$resolved = get_permalink( $linked );
				if ( is_string( $resolved ) && $resolved !== '' ) {
					$link = $resolved;
				}
			}
		}

		// Variants that display supporting text inline:
		$show_supporting = in_array( $variant, array( 'card-grid', 'featured-card' ), true );

		$is_featured_card = ( $variant === 'featured-card' );
		$is_linked        = ( $link !== '' );

		// Unified link affordance for v1: any linked item uses the
		// stretched-link pattern — the <a> covers the whole card and a
		// subtle arrow indicator signals clickability. The arrow is
		// suppressed on compact-grid and horizontal-row variants by CSS
		// (heading hover-color is enough cue at those sizes).
		//
		// Per-item CTA buttons with custom labels were considered but
		// require a `link_label` field that's deferred to v1.1 — see
		// docs/ROADMAP.md.
		$item_classes = 'pre-grouping__item';
		if ( $is_linked ) {
			$item_classes .= ' pre-grouping__item--linked';
		}

		// Variant-aware media resolution.
		//
		// compact-grid and horizontal-row are icon-only variants by design —
		// small icon affordances paired with one-line headings. A featured
		// image at this size loses its visual value (a 32px building photo
		// reads as a smudge, not a building) and breaks the row alignment
		// between iconed items and image-pulling items in the same grouping.
		// For these variants, we drop image_id entirely so the variant's
		// visual rhythm wins over the item's content shape. The user's
		// variant choice is a stronger statement of intent than a per-item
		// image upload — if they wanted images, they'd pick card-grid or
		// featured-card.
		//
		// Default-icon fallback: in any variant, if the item resolves to no
		// media at all (no image, no icon), fall back to a CPT-level
		// default_icon. For icon-only variants this means image-only items
		// get the default; for image-friendly variants it means iconless
		// items still have a baseline visual cue.
		//
		// Two-tier lookup for cross-CPT items:
		//   1) When the item links to another post via link_post_id, prefer
		//      the LINKED post's CPT default_icon. A "Lead Architect"
		//      featured-card on a Project page should reach for the
		//      Architect CPT's default_icon ('user'), not the host Project
		//      CPT's default_icon ('home') — the icon should describe what
		//      the item IS, not what the host page is about.
		//   2) Fall through to the host CPT's default_icon if no linked
		//      post or that linked post's CPT has no default set.
		// If neither is set, items render iconless — graceful degradation.
		// See critical_rules.cross_cpt_item_icons for the author-side rule.
		$resolve_default_icon = function () use ( $link_post_id, $cpt_default_icon ) {
			if ( $link_post_id > 0 ) {
				$linked_post = get_post( $link_post_id );
				if ( $linked_post instanceof WP_Post ) {
					$plugin = pcptpages();
					$linked_def = $plugin->cpts ? $plugin->cpts->get( $linked_post->post_type ) : null;
					if ( is_array( $linked_def ) && ! empty( $linked_def['default_icon'] ) ) {
						return (string) $linked_def['default_icon'];
					}
				}
			}
			return $cpt_default_icon;
		};

		$is_icon_only_variant = in_array( $variant, array( 'compact-grid', 'horizontal-row' ), true );
		if ( $is_icon_only_variant ) {
			$image_id = 0;
			if ( $icon_id === '' ) {
				$fallback = $resolve_default_icon();
				if ( $fallback !== '' ) {
					$icon_id = $fallback;
				}
			}
		} elseif ( $image_id === 0 && $icon_id === '' ) {
			$fallback = $resolve_default_icon();
			if ( $fallback !== '' ) {
				$icon_id = $fallback;
			}
		}

		// Resolve the media markup once. wp_get_attachment_image returns an
		// empty string when the attachment has been trashed/deleted — without
		// pre-checking, we'd emit an empty <div class="pre-grouping__media">
		// wrapper that creates phantom layout space. Instead we capture the
		// HTML and only emit the wrapper when there's something to display.
		// Same logic for icons: render only if both the ID exists in storage
		// AND the icon is still in the registry (defensive against icons
		// removed from the library after data was saved).
		$media_html = '';
		$media_class_modifier = '';
		if ( $image_id > 0 ) {
			// Alt-text precedence: attachment's own alt (set at upload time)
			// wins. Fall back to the item's heading only when the attachment
			// has no alt — empty alt on a content image is worse than a
			// reasonable description.
			$attachment_alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
			$image_args     = array( 'class' => 'pre-grouping__image' );
			if ( $attachment_alt === '' && $heading !== '' ) {
				$image_args['alt'] = $heading;
			}

			// Pick the smallest WP-registered size that comfortably covers
			// each variant's rendered slot. featured-card uses `large` (up to
			// 1024px wide) for its full-bleed image. card-grid uses `medium`
			// (~300px) for its card-wide thumbnail. compact-grid and
			// horizontal-row render the image as a small icon-affordance
			// thumbnail (24-32px wide), so even `thumbnail` (150px) is
			// 5-6x oversized — but the smaller registered sizes don't exist
			// on every install, and `thumbnail` is the only universally
			// available square crop. Browser still scales it down via
			// object-fit; bandwidth is negligible at 150px source.
			$image_size = 'medium';
			if ( $is_featured_card ) {
				$image_size = 'large';
			} elseif ( in_array( $variant, array( 'compact-grid', 'horizontal-row' ), true ) ) {
				$image_size = 'thumbnail';
			}

			$image_html = wp_get_attachment_image(
				$image_id,
				$image_size,
				false,
				$image_args
			);

			// wp_get_attachment_image returns '' when the attachment is gone.
			// Skipping the wrapper in that case avoids a phantom layout slot.
			// The --image modifier mirrors --icon so per-variant CSS can size
			// images and icons independently — compact-grid and horizontal-row
			// constrain images to icon-matching square thumbnails, while
			// card-grid and featured-card let images render at full size.
			// Without the modifier, a single .pre-grouping__media wrapper
			// would have to handle both cases via attribute selectors or
			// :has(), neither of which is as cache-friendly as a class.
			if ( $image_html !== '' ) {
				$media_html           = $image_html;
				$media_class_modifier = ' pre-grouping__media--image';
			}
		} elseif ( $icon_id !== '' && PCPTPages_Icon_Library::is_valid_id( $icon_id ) ) {
			// Icon ID stored. is_valid_id() recognizes BOTH legacy curated
			// IDs (e.g. "home", "users") AND Iconify codes (e.g. "mdi:home",
			// "logos:wordpress"). PCPTPages_Icon_Library::render() picks the right
			// code path — inline SVG span for legacy, <iconify-icon> web
			// component for Iconify codes. If the icon was removed from the
			// curated library AND the stored value doesn't match Iconify
			// format, is_valid_id() returns false and this branch is
			// skipped — item renders without media (graceful degradation).
			$media_html           = PCPTPages_Icon_Library::render( $icon_id, 'pre-grouping__icon' );
			$media_class_modifier = ' pre-grouping__media--icon';
		}

		?>
		<li class="<?php echo esc_attr( $item_classes ); ?>">
			<?php if ( $media_html !== '' ) : ?>
				<div class="pre-grouping__media<?php echo esc_attr( $media_class_modifier ); ?>">
					<?php
					// $media_html comes from PCPTPages_Icon_Library::render() or wp_get_attachment_image()
					// — both produce sanitized HTML. Class attributes are esc_attr()'d at source
					// and SVG content is plugin-curated (no user input). Output is intentionally raw.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $media_html;
					?>
				</div>
			<?php endif; ?>

			<div class="pre-grouping__content">
				<?php if ( $heading !== '' ) : ?>
					<h3 class="pre-grouping__heading"><?php echo esc_html( $heading ); ?></h3>
				<?php endif; ?>

				<?php if ( $show_supporting && $supporting_text !== '' ) : ?>
					<p class="pre-grouping__supporting"><?php echo esc_html( $supporting_text ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $is_linked ) : ?>
				<span class="pre-grouping__cta-arrow" aria-hidden="true">→</span>
				<a class="pre-grouping__link-overlay" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $heading !== '' ? $heading : __( 'View item', 'promptless-cpt-pages' ) ); ?>"></a>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Render the related-posts footer using the CPT's first registered
	 * taxonomy. Skips silently if the CPT has no taxonomies or the post
	 * has no terms.
	 *
	 * @param WP_Post $post Current post.
	 */
	private function render_related_footer( WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		if ( empty( $taxonomies ) ) {
			return;
		}

		$taxonomy = reset( $taxonomies ); // First registered taxonomy.

		$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$related = get_posts(
			array(
				'post_type'        => $post->post_type,
				'post_status'      => 'publish',
				'numberposts'      => 3,
				'exclude'          => array( $post->ID ),
				'suppress_filters' => false,
				'tax_query'        => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $terms,
					),
				),
			)
		);

		if ( empty( $related ) ) {
			return;
		}

		?>
		<footer class="pre-footer">
			<h2 class="pre-footer__heading"><?php esc_html_e( 'Related', 'promptless-cpt-pages' ); ?></h2>
			<ul class="pre-footer__list">
				<?php foreach ( $related as $rp ) :
					$rp_title = get_the_title( $rp );
					?>
					<li class="pre-footer__item">
						<a class="pre-footer__link" href="<?php echo esc_url( get_permalink( $rp ) ); ?>">
							<?php if ( has_post_thumbnail( $rp->ID ) ) :
								// Same alt-text fallback pattern as the
								// hero — saved alt wins, title fallback
								// when blank.
								$rp_thumb_id  = get_post_thumbnail_id( $rp->ID );
								$rp_saved_alt = trim( (string) get_post_meta( $rp_thumb_id, '_wp_attachment_image_alt', true ) );
								$rp_args      = array( 'class' => 'pre-footer__image' );
								if ( $rp_saved_alt === '' ) {
									$rp_args['alt'] = $rp_title;
								}
								?>
								<div class="pre-footer__media">
									<?php echo get_the_post_thumbnail( $rp->ID, 'medium', $rp_args ); ?>
								</div>
							<?php endif; ?>
							<h3 class="pre-footer__title"><?php echo esc_html( $rp_title ); ?></h3>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</footer>
		<?php
	}
}
