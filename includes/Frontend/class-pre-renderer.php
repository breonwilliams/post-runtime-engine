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
 */
class PRE_Renderer {

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
	 * Render the full single-post page for the given post.
	 *
	 * @param WP_Post $post Post to render.
	 */
	public function render( WP_Post $post ) {
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

		?>
		<header class="pre-hero">
			<div class="pre-hero__inner">
				<h1 class="pre-hero__title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>

				<?php if ( $excerpt !== '' ) : ?>
					<p class="pre-hero__excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>

				<?php if ( $has_thumbnail ) : ?>
					<div class="pre-hero__media">
						<?php echo get_the_post_thumbnail( $post->ID, 'large', array( 'class' => 'pre-hero__image' ) ); ?>
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

		?>
		<li class="<?php echo esc_attr( $item_classes ); ?>">
			<?php if ( $image_id > 0 ) : ?>
				<div class="pre-grouping__media">
					<?php
					echo wp_get_attachment_image(
						$image_id,
						$is_featured_card ? 'large' : 'medium',
						false,
						array( 'class' => 'pre-grouping__image' )
					);
					?>
				</div>
			<?php elseif ( $icon_id !== '' && PRE_Icon_Library::has( $icon_id ) ) : ?>
				<div class="pre-grouping__media pre-grouping__media--icon">
					<?php echo PRE_Icon_Library::render( $icon_id, 'pre-grouping__icon' ); ?>
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
				<?php foreach ( $related as $rp ) : ?>
					<li class="pre-footer__item">
						<a class="pre-footer__link" href="<?php echo esc_url( get_permalink( $rp ) ); ?>">
							<?php if ( has_post_thumbnail( $rp->ID ) ) : ?>
								<div class="pre-footer__media">
									<?php echo get_the_post_thumbnail( $rp->ID, 'medium', array( 'class' => 'pre-footer__image' ) ); ?>
								</div>
							<?php endif; ?>
							<h3 class="pre-footer__title"><?php echo esc_html( get_the_title( $rp ) ); ?></h3>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</footer>
		<?php
	}
}
