<?php
/**
 * Post-edit-screen meta box for grouping values.
 *
 * For every CPT registered through Promptless CPT Pages, adds a meta box
 * to its post-edit screen. Each grouping defined for the CPT renders as
 * its own editor section — manual sources get an items editor with
 * add/remove/reorder + icon/image picker + heading/supporting text/link
 * inputs, auto sources get a read-only placeholder explaining the
 * resolution at render time.
 *
 * On save, all grouping data is collected from $_POST, normalized into
 * the canonical groupings shape, and persisted via PCPTPages_Post_Data which
 * runs strict validation. Validation failures are surfaced as a queued
 * admin notice on the next page load (post still saves with the previous
 * grouping state intact via the backup mechanism).
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 *
 * Justification: The save_groupings() handler verifies the nonce via
 * wp_verify_nonce on $_POST[self::NONCE_NAME] before processing any other
 * $_POST data. Plugin Check's static analyzer flags the $_POST reads
 * because it cannot trace that verification gate. All grouping field
 * values flow through PCPTPages_Validator before persistence (canonical
 * sanitization happens at the validator boundary, not at the $_POST
 * read site).
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta box renderer + save handler.
 */
class PCPTPages_Meta_Box {

	const META_BOX_ID  = 'pcptpages-groupings';
	const NONCE_NAME   = 'pcptpages_meta_box_nonce';
	const NONCE_ACTION = 'pcptpages_save_groupings';

	/**
	 * Constructor. Wires WordPress hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the meta box for every CPT managed by this plugin.
	 */
	public function register() {
		$plugin = pcptpages();
		if ( ! $plugin->cpts ) {
			return;
		}

		foreach ( $plugin->cpts->get_all() as $slug => $cpt ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Post Runtime Groupings', 'promptless-cpt-pages' ),
				array( $this, 'render' ),
				$slug,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Enqueue CSS / JS for the meta box on post-edit screens for our CPTs.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only enqueue on post.php and post-new.php.
		if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
			return;
		}

		// Confirm we're editing a CPT this plugin manages.
		$post_type = $this->current_post_type();
		if ( ! $post_type ) {
			return;
		}

		$plugin = pcptpages();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post_type ) ) {
			return;
		}

		// WordPress media library for the image picker.
		wp_enqueue_media();

		wp_enqueue_style(
			'pcptpages-admin',
			PCPTPages_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PCPTPages_VERSION
		);

		wp_enqueue_script(
			'pcptpages-meta-box',
			PCPTPages_PLUGIN_URL . 'assets/js/meta-box.js',
			// jquery-ui-autocomplete is bundled with WP core; it powers the
			// link field's post search. Listing it here pulls in the widget +
			// its position dependency automatically.
			array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete' ),
			PCPTPages_VERSION,
			true
		);

		// Iconify web-component bundle from the jsdelivr CDN. Required so
		// the icon picker preview can render Iconify codes (`mdi:home`,
		// `logos:wordpress`, etc.) live as the user types. The component
		// self-registers; no init code needed. Same module Promptless WP
		// uses, so when both plugins are active the browser caches one copy.
		// Type="module" because iconify-icon ships as an ES module — WordPress
		// honors the script_loader_tag filter so adding the attribute via
		// wp_script_add_data() makes WP emit type="module". Bundled
		// locally at assets/js/iconify-icon.min.js — see comment in
		// PCPTPages_Frontend_Assets::enqueue() for rationale.
		wp_enqueue_script(
			'pcptpages-iconify-icon',
			PCPTPages_PLUGIN_URL . 'assets/js/iconify-icon.min.js',
			array(),
			'2.1.0',
			true
		);
		wp_script_add_data( 'pcptpages-iconify-icon', 'type', 'module' );

		// Localize icon SVGs, REST search endpoint, and nonce so the JS can
		// render previews and run authenticated post-search queries without a
		// page-roundtrip per character.
		wp_localize_script(
			'pcptpages-meta-box',
			'pcptpagesMetaBox',
			array(
				'icons'         => $this->icon_data_for_js(),
				'mediaTitle'    => __( 'Choose Image', 'promptless-cpt-pages' ),
				'mediaButton'   => __( 'Use this image', 'promptless-cpt-pages' ),
				'galleryTitle'  => __( 'Add gallery images', 'promptless-cpt-pages' ),
				'galleryButton' => __( 'Add to gallery', 'promptless-cpt-pages' ),
				// /wp/v2/search returns matches from any post type whose
				// show_in_rest=true. The X-WP-Nonce header authenticates the
				// request as the current user so private/draft posts the user
				// can_edit appear in suggestions.
				'searchUrl'   => esc_url_raw( rest_url( 'wp/v2/search' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'i18n'        => array(
					'remove'  => __( 'Remove', 'promptless-cpt-pages' ),
					'pickImg' => __( 'Add image', 'promptless-cpt-pages' ),
					'change'  => __( 'Replace image', 'promptless-cpt-pages' ),
					'clear'   => __( 'Clear', 'promptless-cpt-pages' ),
				),
			)
		);
	}

	/**
	 * Render the meta box for a post.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ) {
		$plugin = pcptpages();

		// Defensive: confirm the post type is one we manage.
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			echo '<p>' . esc_html__( 'This post type is not managed by Promptless CPT Pages.', 'promptless-cpt-pages' ) . '</p>';
			return;
		}

		$definitions = $plugin->groupings ? $plugin->groupings->get_all( $post->post_type ) : array();
		$values      = $plugin->post_data ? $plugin->post_data->get_groupings( $post->id ?? $post->ID ) : array();

		// Index the post's stored grouping data by grouping_key for easy lookup.
		$values_by_key = array();
		foreach ( $values as $entry ) {
			if ( ! empty( $entry['grouping_key'] ) ) {
				$values_by_key[ $entry['grouping_key'] ] = $entry;
			}
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( empty( $definitions ) ) {
			$groupings_url = add_query_arg(
				array(
					'page' => PCPTPages_Admin::PAGE_GROUPINGS,
					'cpt'  => $post->post_type,
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="pre-meta-empty">
				<p>
					<?php esc_html_e( 'No groupings are defined for this post type yet.', 'promptless-cpt-pages' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $groupings_url ); ?>" class="button">
						<?php esc_html_e( 'Define groupings →', 'promptless-cpt-pages' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="pre-meta-box">
			<?php foreach ( $definitions as $key => $def ) : ?>
				<?php
				$entry = isset( $values_by_key[ $key ] ) ? $values_by_key[ $key ] : array();
				$this->render_grouping_section( $post->post_type, $key, $def, $entry );
				?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render one grouping editor section.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @param string $key      Grouping key.
	 * @param array  $def      Grouping definition.
	 * @param array  $entry    Stored grouping entry for the post (or empty array).
	 */
	private function render_grouping_section( $cpt_slug, $key, array $def, array $entry ) {
		$position         = isset( $entry['position'] ) && $entry['position'] !== null
			? $entry['position']
			: '';
		$variant_override = isset( $entry['variant_override'] ) && $entry['variant_override'] !== null
			? $entry['variant_override']
			: '';
		$items            = isset( $entry['items'] ) && is_array( $entry['items'] ) ? $entry['items'] : array();

		// Resolve the effective source for this grouping. Per-post override
		// is allowed only for variant and position in v1.0; source comes
		// from the definition. (User can change it later in the Groupings
		// admin page.)
		$source       = $def['default_source'] ?? 'manual';
		$is_manual    = is_string( $source ) && $source === 'manual';
		$max_items    = isset( $def['max_items'] ) ? (int) $def['max_items'] : 0; // 0 = no cap.
		$edit_def_url = add_query_arg(
			array(
				'page'     => PCPTPages_Admin::PAGE_GROUPINGS,
				'cpt'      => $cpt_slug,
				'action'   => 'edit',
				'grouping' => $key,
			),
			admin_url( 'admin.php' )
		);

		// Default variant from the grouping definition; passed to the JS layer
		// so it can determine the *effective* variant (override or default)
		// and toggle the icon-only-variant UI state without a roundtrip.
		// Mirrors the same fallback chain the renderer uses (PCPTPages_Renderer
		// lines 295, 637), so the meta box state and the rendered output
		// agree on what variant is in effect.
		$default_variant = isset( $def['default_variant'] ) ? (string) $def['default_variant'] : 'compact-grid';

		?>
		<div class="pre-meta-grouping" data-grouping-key="<?php echo esc_attr( $key ); ?>" data-max-items="<?php echo esc_attr( (string) $max_items ); ?>" data-default-variant="<?php echo esc_attr( $default_variant ); ?>">
			<header class="pre-meta-grouping__header">
				<h3>
					<?php echo esc_html( $def['label'] ?? $key ); ?>
					<code class="pre-meta-grouping__key"><?php echo esc_html( $key ); ?></code>
				</h3>
				<a class="pre-meta-grouping__edit-link" href="<?php echo esc_url( $edit_def_url ); ?>">
					<?php esc_html_e( 'Edit definition →', 'promptless-cpt-pages' ); ?>
				</a>
			</header>

			<?php if ( ! empty( $def['description'] ) ) : ?>
				<p class="pre-meta-grouping__description"><?php echo esc_html( $def['description'] ); ?></p>
			<?php endif; ?>

			<div class="pre-meta-grouping__overrides">
				<label>
					<?php esc_html_e( 'Position', 'promptless-cpt-pages' ); ?>
					<select name="pcptpages_groupings[<?php echo esc_attr( $key ); ?>][position]">
						<option value=""><?php
							/* translators: %s: default position from grouping definition */
							printf( esc_html__( 'Default (%s)', 'promptless-cpt-pages' ), esc_html( $def['default_position'] ?? '—' ) );
						?></option>
						<option value="above_main" <?php selected( $position, 'above_main' ); ?>><?php esc_html_e( 'Above main', 'promptless-cpt-pages' ); ?></option>
						<option value="below_main" <?php selected( $position, 'below_main' ); ?>><?php esc_html_e( 'Below main', 'promptless-cpt-pages' ); ?></option>
						<option value="sidebar" <?php selected( $position, 'sidebar' ); ?>><?php esc_html_e( 'Sidebar', 'promptless-cpt-pages' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Variant', 'promptless-cpt-pages' ); ?>
					<select name="pcptpages_groupings[<?php echo esc_attr( $key ); ?>][variant_override]">
						<option value=""><?php
							/* translators: %s: default variant from grouping definition */
							printf( esc_html__( 'Default (%s)', 'promptless-cpt-pages' ), esc_html( $def['default_variant'] ?? '—' ) );
						?></option>
						<option value="compact-grid" <?php selected( $variant_override, 'compact-grid' ); ?>><?php esc_html_e( 'Compact grid', 'promptless-cpt-pages' ); ?></option>
						<option value="card-grid" <?php selected( $variant_override, 'card-grid' ); ?>><?php esc_html_e( 'Card grid', 'promptless-cpt-pages' ); ?></option>
						<option value="featured-card" <?php selected( $variant_override, 'featured-card' ); ?>><?php esc_html_e( 'Featured card', 'promptless-cpt-pages' ); ?></option>
						<option value="horizontal-row" <?php selected( $variant_override, 'horizontal-row' ); ?>><?php esc_html_e( 'Horizontal row', 'promptless-cpt-pages' ); ?></option>
						<option value="gallery" <?php selected( $variant_override, 'gallery' ); ?>><?php esc_html_e( 'Gallery', 'promptless-cpt-pages' ); ?></option>
					</select>
				</label>
			</div>

			<?php if ( $is_manual ) : ?>
				<div class="pre-meta-items">
					<h4><?php esc_html_e( 'Items', 'promptless-cpt-pages' ); ?></h4>

					<?php
					// Grouping-level icon-only note. Shown ONCE per grouping when
					// the effective variant (override or default) is one that
					// drops uploaded images at render time (compact-grid or
					// horizontal-row — PCPTPages_Renderer::render_item line 637-645).
					// Toggled by meta-box.js evaluateGroupingVariant() via the
					// .is-icon-only class on the grouping wrapper. Previously
					// this was rendered per-item, which produced 29x duplicate
					// noise on the demo page (~2,580px of repeated text) for
					// no extra information — variant is a grouping-level
					// decision, the message belongs at grouping scope.
					?>
					<p class="pre-meta-grouping__icon-only-note" hidden>
						<?php esc_html_e( 'This layout uses icons only — uploaded images are not displayed. Change the Variant above to "Card grid" or "Featured card" to use images.', 'promptless-cpt-pages' ); ?>
					</p>

					<?php
					// Grouping-level gallery note — counterpart of the icon-only
					// note above. Toggled by meta-box.js evaluateGroupingVariant()
					// via the .is-gallery class when the effective variant is
					// gallery (GALLERY_VARIANT_DESIGN.md §2/§5).
					?>
					<p class="pre-meta-grouping__gallery-note" hidden>
						<?php esc_html_e( 'Gallery layout: each item is a photo (heading becomes an optional caption; icons, supporting text, and links are not displayed). Use "Add images" to select multiple photos at once.', 'promptless-cpt-pages' ); ?>
					</p>

					<ol class="pre-items-list" data-grouping-key="<?php echo esc_attr( $key ); ?>">
						<?php foreach ( $items as $i => $item ) : ?>
							<?php $this->render_item_row( $key, $i, $item ); ?>
						<?php endforeach; ?>
					</ol>

					<button type="button" class="button pre-add-item" data-grouping-key="<?php echo esc_attr( $key ); ?>">
						+ <?php esc_html_e( 'Add item', 'promptless-cpt-pages' ); ?>
					</button>

					<?php
					// Gallery bulk-add: opens wp.media in multi-select mode and
					// creates one item per selected image (contract §5 — "ten
					// photos become ten items in one media-library trip").
					// Hidden unless the effective variant is gallery; handled by
					// meta-box.js addGalleryImages().
					?>
					<button type="button" class="button pre-add-gallery-images" data-grouping-key="<?php echo esc_attr( $key ); ?>" hidden>
						+ <?php esc_html_e( 'Add images', 'promptless-cpt-pages' ); ?>
					</button>

					<template class="pre-item-template" data-grouping-key="<?php echo esc_attr( $key ); ?>">
						<?php $this->render_item_row( $key, '__INDEX__', array() ); ?>
					</template>
				</div>
			<?php else : ?>
				<div class="pre-meta-auto-source">
					<p>
						<?php
						if ( is_string( $source ) && $source === 'child_posts' ) {
							esc_html_e( 'This grouping is auto-populated from this post\'s child posts. Items will be resolved at render time.', 'promptless-cpt-pages' );
						} elseif ( is_array( $source ) && ( $source['type'] ?? '' ) === 'taxonomy_match' ) {
							/* translators: %s: taxonomy slug */
							printf( esc_html__( 'This grouping is auto-populated from posts sharing the "%s" taxonomy. Items will be resolved at render time.', 'promptless-cpt-pages' ), esc_html( $source['taxonomy'] ?? '?' ) );
						} elseif ( is_array( $source ) && ( $source['type'] ?? '' ) === 'meta_match' ) {
							/* translators: %s: post-meta key */
							printf( esc_html__( 'This grouping is auto-populated from posts whose "%s" post-meta value matches this post. Items will be resolved at render time.', 'promptless-cpt-pages' ), esc_html( $source['meta_key'] ?? '?' ) );
						} else {
							esc_html_e( 'This grouping uses an auto source. Items will be resolved at render time.', 'promptless-cpt-pages' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one item row inside a manual-source grouping.
	 *
	 * Layout (CSS grid in admin.css):
	 *   ┌─────────────────────────────────────────────────────────┐
	 *   │ [⋮]  [🏠]   Heading                              [×]   │  hover-revealed handle + remove
	 *   │      ──────  Supporting text                            │
	 *   │      Icon ▾  Link                                       │
	 *   │      Image│Clear                                        │
	 *   └─────────────────────────────────────────────────────────┘
	 *
	 * - Drag handle (left rail, dashicon, fade-in on hover)
	 * - Media column: preview tile + icon dropdown + image controls stacked
	 * - Fields column: heading, supporting text, link with placeholders
	 * - Remove button absolute-positioned top-right (hover-revealed)
	 *
	 * @param string     $key   Grouping key.
	 * @param int|string $index Item index (or "__INDEX__" for the JS template).
	 * @param array      $item  Item data.
	 */
	private function render_item_row( $key, $index, array $item ) {
		$image_id        = isset( $item['image_id'] ) ? (int) $item['image_id'] : 0;
		$icon_id         = isset( $item['icon_id'] ) ? (string) $item['icon_id'] : '';
		$heading         = isset( $item['heading'] ) ? (string) $item['heading'] : '';
		$supporting_text = isset( $item['supporting_text'] ) ? (string) $item['supporting_text'] : '';
		$link            = isset( $item['link'] ) ? (string) $item['link'] : '';
		$link_post_id    = isset( $item['link_post_id'] ) ? (int) $item['link_post_id'] : 0;

		$thumb_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		$base = 'pcptpages_groupings[' . $key . '][items][' . $index . ']';

		?>
		<li class="pre-item">
			<span class="pre-item__handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'promptless-cpt-pages' ); ?>">
				<span class="dashicons dashicons-menu" aria-hidden="true"></span>
			</span>

			<div class="pre-item__media">
				<div class="pre-item__preview" data-icon-id="<?php echo esc_attr( $icon_id ); ?>">
					<?php
					if ( $image_id > 0 && $thumb_url ) {
						echo '<img src="' . esc_url( $thumb_url ) . '" alt="">';
					} elseif ( $icon_id !== '' && PCPTPages_Icon_Library::is_valid_id( $icon_id ) ) {
						// PCPTPages_Icon_Library::render() returns either a <span> wrapper
						// with esc_attr()'d class + plugin-curated SVG (legacy
						// icons) OR a <iconify-icon> web component with the
						// icon attribute esc_attr()'d (Iconify codes). The
						// web component fetches SVG from api.iconify.design at
						// paint time; legacy SVGs ship inline. Output is
						// intentionally raw HTML for both paths.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo PCPTPages_Icon_Library::render( $icon_id );
					} else {
						echo '<span class="pre-item__preview-empty" aria-hidden="true">+</span>';
					}
					?>
				</div>

				<div class="pre-item__media-controls">
					<?php
					// Iconify text input — replaces the curated dropdown. Accepts
					// either a legacy curated ID (`home`, `users`) or an Iconify
					// code (`mdi:home`, `logos:wordpress`). Storage is the same
					// field as before so existing post data continues to render.
					// See PCPTPages_Icon_Library::is_iconify_format() for the validation
					// pattern; meta-box.js validates the same shape client-side
					// for instant feedback.
					?>
					<input
						type="text"
						class="pre-item__icon-input"
						name="<?php echo esc_attr( $base ); ?>[icon_id]"
						value="<?php echo esc_attr( $icon_id ); ?>"
						placeholder="<?php esc_attr_e( 'mdi:home or "home"', 'promptless-cpt-pages' ); ?>"
						maxlength="100"
						aria-label="<?php esc_attr_e( 'Icon — Iconify code or curated ID', 'promptless-cpt-pages' ); ?>"
						spellcheck="false"
						autocomplete="off">

					<p class="pre-item__icon-help description">
						<?php
						printf(
							wp_kses(
								/* translators: %s: Iconify icon-sets URL */
								__( 'Any <a href="%s" target="_blank" rel="noopener">Iconify code</a> or pick one below.', 'promptless-cpt-pages' ),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							),
							esc_url( 'https://icon-sets.iconify.design/' )
						);
						?>
					</p>

					<?php
					// Curated icon dropdown — compact alternative to the previous
					// visual quickpick grid (replaced 2026-05-16). Same 53
					// built-ins, grouped under <optgroup> by category so they
					// stay scannable. Selecting an option mirrors the value
					// into the text input above and updates the preview tile.
					// The JS handler is shared with the manual-type path so the
					// preview stays in sync regardless of how the value arrived.
					// Curated IDs render inline SVG (no network); Iconify codes
					// typed manually render via <iconify-icon> web component.
					$grouped_icons = PCPTPages_Icon_Library::get_grouped_by_category();
					?>
					<select
						class="pre-item__icon-select"
						aria-label="<?php esc_attr_e( 'Pick a common icon', 'promptless-cpt-pages' ); ?>">
						<option value=""><?php esc_html_e( '— Pick a common icon —', 'promptless-cpt-pages' ); ?></option>
						<?php foreach ( $grouped_icons as $category => $icons_in_category ) : ?>
							<optgroup label="<?php echo esc_attr( $category ); ?>">
								<?php foreach ( $icons_in_category as $icon_key => $icon ) : ?>
									<option value="<?php echo esc_attr( $icon_key ); ?>" <?php selected( $icon_id, $icon_key ); ?>>
										<?php echo esc_html( $icon['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>

					<div class="pre-item__media-buttons">
						<button type="button" class="button-link pre-pick-image">
							<?php echo $image_id > 0 ? esc_html__( 'Replace image', 'promptless-cpt-pages' ) : esc_html__( 'Add image', 'promptless-cpt-pages' ); ?>
						</button>
						<button type="button" class="button-link pre-clear-media">
							<?php esc_html_e( 'Clear', 'promptless-cpt-pages' ); ?>
						</button>
					</div>
				</div>

				<input type="hidden" class="pre-item__image-input" name="<?php echo esc_attr( $base ); ?>[image_id]" value="<?php echo esc_attr( (string) $image_id ); ?>">
			</div>

			<div class="pre-item__fields">
				<input
					type="text"
					class="pre-item__heading"
					name="<?php echo esc_attr( $base ); ?>[heading]"
					value="<?php echo esc_attr( $heading ); ?>"
					placeholder="<?php esc_attr_e( 'Heading', 'promptless-cpt-pages' ); ?>"
					aria-label="<?php esc_attr_e( 'Heading', 'promptless-cpt-pages' ); ?>"
					maxlength="200">
				<textarea
					class="pre-item__supporting"
					rows="2"
					name="<?php echo esc_attr( $base ); ?>[supporting_text]"
					placeholder="<?php esc_attr_e( 'Supporting text', 'promptless-cpt-pages' ); ?>"
					aria-label="<?php esc_attr_e( 'Supporting text', 'promptless-cpt-pages' ); ?>"
					maxlength="1000"><?php echo esc_textarea( $supporting_text ); ?></textarea>
				<input
					type="text"
					class="pre-item__link"
					name="<?php echo esc_attr( $base ); ?>[link]"
					value="<?php echo esc_attr( $link ); ?>"
					placeholder="<?php esc_attr_e( 'Search posts, or paste https://, /path, #anchor, tel:, mailto:', 'promptless-cpt-pages' ); ?>"
					aria-label="<?php esc_attr_e( 'Link — type a post name to search, or paste a URL', 'promptless-cpt-pages' ); ?>"
					autocomplete="off">
				<?php /*
					Site-portable internal link: when the autocomplete picks a
					post, we capture its ID here. The renderer prefers
					get_permalink(link_post_id) over the URL string above,
					which makes the stored data survive domain migrations and
					permalink-structure changes. Cleared on any manual edit
					of the visible link field (see meta-box.js) — once the
					user types over the URL, we can no longer guarantee it
					still maps to the same post.
				*/ ?>
				<input
					type="hidden"
					class="pre-item__link-post-id"
					name="<?php echo esc_attr( $base ); ?>[link_post_id]"
					value="<?php echo esc_attr( $link_post_id > 0 ? (string) $link_post_id : '' ); ?>">
			</div>

			<button type="button" class="pre-item__remove pre-remove-item" aria-label="<?php esc_attr_e( 'Remove item', 'promptless-cpt-pages' ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</li>
		<?php
	}

	/**
	 * Save handler. Runs on save_post for any post — we filter to
	 * Post-Runtime-managed CPTs ourselves.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Confirm this is a CPT we manage.
		$plugin = pcptpages();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Verify nonce. wp_verify_nonce is pluggable in WordPress core, so
		// the value MUST be sanitized (sanitize_text_field) before being
		// passed — wp_unslash alone isn't enough per WP.org guidelines.
		if ( ! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// Capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Pull the raw POSTed groupings.
		$raw = isset( $_POST['pcptpages_groupings'] ) && is_array( $_POST['pcptpages_groupings'] )
			? wp_unslash( $_POST['pcptpages_groupings'] )
			: array();

		$definitions = $plugin->groupings ? $plugin->groupings->get_all( $post->post_type ) : array();
		$groupings   = array();

		foreach ( $raw as $key => $entry ) {
			$key = sanitize_key( $key );
			if ( ! isset( $definitions[ $key ] ) ) {
				// Orphaned grouping (definition was removed). Skip — the
				// validator would reject it anyway.
				continue;
			}

			$def    = $definitions[ $key ];
			$source = $def['default_source'] ?? 'manual';

			$out = array(
				'grouping_key'     => $key,
				'position'         => null,
				'variant_override' => null,
				'source'           => $source,
				'items'            => array(),
			);

			if ( ! empty( $entry['position'] ) ) {
				$out['position'] = sanitize_key( $entry['position'] );
			}
			if ( ! empty( $entry['variant_override'] ) ) {
				$out['variant_override'] = sanitize_key( $entry['variant_override'] );
			}

			// Items only collected for manual source. Auto sources keep
			// items: [] (validator enforces this).
			$is_manual = is_string( $source ) && $source === 'manual';

			if ( $is_manual && isset( $entry['items'] ) && is_array( $entry['items'] ) ) {
				foreach ( $entry['items'] as $raw_item ) {
					if ( ! is_array( $raw_item ) ) {
						continue;
					}

					$image_id        = isset( $raw_item['image_id'] ) ? (int) $raw_item['image_id'] : 0;
					// icon_id accepts BOTH legacy curated IDs (sanitize_key shape)
					// AND Iconify codes (`collection:name` — sanitize_key would
					// strip the colon and break Iconify). sanitize_text_field +
					// the validator's is_valid_id() check is the right shape:
					// strip control chars / extra whitespace, then let the
					// validator enforce the strict pattern. Anything that fails
					// validation is rejected at save with a meaningful error,
					// not silently transformed into garbage here.
					$icon_id         = isset( $raw_item['icon_id'] ) ? trim( sanitize_text_field( (string) $raw_item['icon_id'] ) ) : '';
					$heading         = isset( $raw_item['heading'] ) ? sanitize_text_field( $raw_item['heading'] ) : '';
					$supporting_text = isset( $raw_item['supporting_text'] ) ? sanitize_textarea_field( $raw_item['supporting_text'] ) : '';
					$link            = isset( $raw_item['link'] ) ? trim( (string) $raw_item['link'] ) : '';
					$link_post_id    = isset( $raw_item['link_post_id'] ) ? (int) $raw_item['link_post_id'] : 0;

					// Defensive: if the autocomplete captured a link_post_id
					// but the visible URL has since been edited away from
					// that post's permalink, drop the post_id. The renderer
					// would otherwise prefer the stale post_id and confuse
					// the user. Cheap check — just confirms the link still
					// matches the resolved permalink.
					if ( $link_post_id > 0 && $link !== '' ) {
						$expected = get_permalink( $link_post_id );
						if ( ! is_string( $expected ) || untrailingslashit( $expected ) !== untrailingslashit( $link ) ) {
							$link_post_id = 0;
						}
					}

					// An entirely empty row (no heading, no link, no image,
					// no icon) is treated as "deleted by clearing" — drop it
					// silently rather than reject the whole save.
					if (
						$heading === ''
						&& $supporting_text === ''
						&& $link === ''
						&& $icon_id === ''
						&& $image_id === 0
					) {
						continue;
					}

					$out['items'][] = array(
						'image_id'        => $image_id > 0 ? $image_id : null,
						'icon_id'         => $icon_id !== '' ? $icon_id : null,
						'heading'         => $heading,
						'supporting_text' => $supporting_text !== '' ? $supporting_text : null,
						'link'            => $link !== '' ? $link : null,
						'link_post_id'    => $link_post_id > 0 ? $link_post_id : null,
					);
				}
			}

			$groupings[] = $out;
		}

		// Persist via PCPTPages_Post_Data which validates strictly.
		if ( ! $plugin->post_data ) {
			return;
		}

		$result = $plugin->post_data->set_groupings( $post_id, $groupings, 'admin' );

		if ( is_wp_error( $result ) ) {
			$user_id = get_current_user_id();
			set_transient(
				'pcptpages_admin_notice_' . $user_id,
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: validation error message */
						__( 'Post Runtime: groupings could not be saved — %s', 'promptless-cpt-pages' ),
						$result->get_error_message()
					),
				),
				60
			);
		}
	}

	/**
	 * Whether we're on a post-edit screen for a managed CPT.
	 *
	 * @return string|null Post type slug or null.
	 */
	private function current_post_type() {
		// On post.php, the post type is on $_GET['post'] or in the loaded post.
		if ( isset( $_GET['post'] ) ) {
			$pt = get_post_type( (int) $_GET['post'] );
			return $pt ?: null;
		}
		if ( isset( $_GET['post_type'] ) ) {
			return sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}
		// On post-new.php with no post_type, defaults to 'post'.
		return 'post';
	}

	/**
	 * Build the icon library data structure for JS consumption. Strips
	 * fields the JS doesn't need (tags) to keep the payload small.
	 *
	 * @return array<string,array{label:string,svg:string}>
	 */
	private function icon_data_for_js() {
		$result = array();
		foreach ( PCPTPages_Icon_Library::get_all() as $id => $icon ) {
			$result[ $id ] = array(
				'label' => $icon['label'],
				'svg'   => $icon['svg'],
			);
		}
		return $result;
	}
}
