<?php
/**
 * Single-post renderer for Post Runtime Engine.
 *
 * Orchestrates the page structure (hero / above_main / main / below_main /
 * sidebar / footer) and renders each grouping with the correct layout
 * variant. Output is escaped at every interpolation point; the underlying
 * data has already been validated through PRE_Validator on save.
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
 *       updated_option hook bumps pre_groupings_changed_{cpt_slug}).
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
 *     - `pre_render_cache_enabled` (bool, $post): force-disable per post.
 *     - `pre_render_cache_lifetime` (int, $post): TTL in seconds; default
 *       1 hour. Caching plugins (WP Rocket, W3TC) cache the full page so
 *       this layer matters most for pages without those plugins active.
 */
class PRE_Renderer {

	/**
	 * Transient key prefix for cached renders.
	 *
	 * @var string
	 */
	const CACHE_KEY_PREFIX = 'pre_render_';

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
	 * @var PRE_Source_Resolver
	 */
	private $source_resolver;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->source_resolver = new PRE_Source_Resolver();
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
	 * `pre_groupings_{cpt_slug}` option is updated. Read at render time;
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
		$prefix = PRE_Grouping_Registry::OPTION_PREFIX;
		if ( strpos( $option, $prefix ) !== 0 ) {
			return;
		}
		$cpt_slug = substr( $option, strlen( $prefix ) );
		if ( $cpt_slug === '' ) {
			return;
		}
		update_option( 'pre_groupings_changed_' . $cpt_slug, time() );
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
			&& apply_filters( 'pre_render_cache_enabled', true, $post );

		if ( ! $cache_enabled ) {
			$this->render_internal( $post );
			return;
		}

		$cache_key    = self::CACHE_KEY_PREFIX . (int) $post->ID;
		$defs_changed = (int) get_option( 'pre_groupings_changed_' . $post->post_type, 0 );
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
			'pre_render_cache_lifetime',
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
		$plugin      = pre();
		$definitions = $plugin->groupings ? $plugin->groupings->get_all( $post->post_type ) : array();
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

		?>
		<article id="post-<?php echo esc_attr( $post->ID ); ?>" <?php post_class( 'pre-single pre-single--' . sanitize_html_class( $post->post_type ) ); ?>>
			<?php $this->render_hero( $post ); ?>

			<div class="<?php echo esc_attr( $body_class ); ?>">
				<div class="pre-body__main">
					<?php foreach ( $above_main as $g ) : ?>
						<?php $this->render_grouping( $g ); ?>
					<?php endforeach; ?>

					<?php if ( post_type_supports( $post->post_type, 'editor' ) ) : ?>
						<div class="pre-content">
							<?php
							// the_content() handles autop, blocks, shortcodes,
							// embeds — same filters any theme uses. We just
							// need to set up postdata first.
							setup_postdata( $post );
							the_content();
							wp_reset_postdata();
							?>
						</div>
					<?php endif; ?>

					<?php foreach ( $below_main as $g ) : ?>
						<?php $this->render_grouping( $g ); ?>
					<?php endforeach; ?>
				</div>

				<?php if ( $has_sidebar ) : ?>
					<aside class="pre-body__sidebar">
						<?php foreach ( $sidebar as $g ) : ?>
							<?php $this->render_grouping( $g ); ?>
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
	 * @param WP_Post $post Post.
	 */
	private function render_hero( WP_Post $post ) {
		$has_thumbnail = has_post_thumbnail( $post->ID );
		$excerpt       = $post->post_excerpt;
		$title         = get_the_title( $post );

		?>
		<header class="pre-hero">
			<div class="pre-hero__inner">
				<h1 class="pre-hero__title"><?php echo esc_html( $title ); ?></h1>

				<?php if ( $excerpt !== '' ) : ?>
					<p class="pre-hero__excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>

				<?php if ( $has_thumbnail ) : ?>
					<div class="pre-hero__media">
						<?php
						// Alt-text fallback: when the attachment has no
						// alt text saved at upload time, use the post
						// title. Empty alt on a content image is an
						// accessibility issue — a meaningful description
						// is always better than the screen reader saying
						// nothing.
						$thumb_id  = get_post_thumbnail_id( $post->ID );
						$saved_alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
						$args      = array( 'class' => 'pre-hero__image' );
						if ( $saved_alt === '' ) {
							$args['alt'] = $title;
						}
						echo get_the_post_thumbnail( $post->ID, 'large', $args );
						?>
					</div>
				<?php endif; ?>
			</div>
		</header>
		<?php
	}

	/**
	 * Render a single grouping using its effective variant.
	 *
	 * @param array $g Resolved grouping (see render() for shape).
	 */
	private function render_grouping( array $g ) {
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
					<?php $this->render_item( $item, $variant ); ?>
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
	 * @param array  $item    Item data ({image_id, icon_id, heading, supporting_text, link, link_post_id}).
	 * @param string $variant The grouping's effective variant.
	 */
	private function render_item( array $item, $variant ) {
		$image_id        = isset( $item['image_id'] ) ? (int) $item['image_id'] : 0;
		$icon_id         = isset( $item['icon_id'] ) ? (string) $item['icon_id'] : '';
		$heading         = isset( $item['heading'] ) ? (string) $item['heading'] : '';
		$supporting_text = isset( $item['supporting_text'] ) ? (string) $item['supporting_text'] : '';
		$link            = isset( $item['link'] ) ? (string) $item['link'] : '';
		$link_post_id    = isset( $item['link_post_id'] ) ? (int) $item['link_post_id'] : 0;

		// If we have a post-ID reference, resolve it through WordPress's
		// permalink resolver. This is what makes the link domain-portable.
		// get_permalink() returns false for trashed/non-existent posts — in
		// that case we fall back to the stored URL string so the item still
		// renders something the user can investigate.
		if ( $link_post_id > 0 ) {
			$resolved = get_permalink( $link_post_id );
			if ( is_string( $resolved ) && $resolved !== '' ) {
				$link = $resolved;
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

			$image_html = wp_get_attachment_image(
				$image_id,
				$is_featured_card ? 'large' : 'medium',
				false,
				$image_args
			);

			// wp_get_attachment_image returns '' when the attachment is gone.
			// Skipping the wrapper in that case avoids a phantom layout slot.
			if ( $image_html !== '' ) {
				$media_html = $image_html;
			}
		} elseif ( $icon_id !== '' && PRE_Icon_Library::has( $icon_id ) ) {
			// Icon ID stored but the icon was removed from the library after
			// the data was saved — PRE_Icon_Library::has() returns false and
			// this branch is skipped. Item renders without media; graceful
			// degradation rather than fatal error.
			$media_html = PRE_Icon_Library::render( $icon_id, 'pre-grouping__icon' );
			$media_class_modifier = ' pre-grouping__media--icon';
		}

		?>
		<li class="<?php echo esc_attr( $item_classes ); ?>">
			<?php if ( $media_html !== '' ) : ?>
				<div class="pre-grouping__media<?php echo esc_attr( $media_class_modifier ); ?>">
					<?php echo $media_html; // SVG/img already escaped at source. ?>
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
				<a class="pre-grouping__link-overlay" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $heading !== '' ? $heading : __( 'View item', 'post-runtime-engine' ) ); ?>"></a>
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
			<h2 class="pre-footer__heading"><?php esc_html_e( 'Related', 'post-runtime-engine' ); ?></h2>
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
