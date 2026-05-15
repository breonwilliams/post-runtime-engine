<?php
/**
 * Post-edit-screen meta box for grouping values.
 *
 * For every CPT registered through Post Runtime Engine, adds a meta box
 * to its post-edit screen. Each grouping defined for the CPT renders as
 * its own editor section — manual sources get an items editor with
 * add/remove/reorder + icon/image picker + heading/supporting text/link
 * inputs, auto sources get a read-only placeholder explaining the
 * resolution at render time.
 *
 * On save, all grouping data is collected from $_POST, normalized into
 * the canonical groupings shape, and persisted via PRE_Post_Data which
 * runs strict validation. Validation failures are surfaced as a queued
 * admin notice on the next page load (post still saves with the previous
 * grouping state intact via the backup mechanism).
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta box renderer + save handler.
 */
class PRE_Meta_Box {

	const META_BOX_ID  = 'pre-groupings';
	const NONCE_NAME   = 'pre_meta_box_nonce';
	const NONCE_ACTION = 'pre_save_groupings';

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
		$plugin = pre();
		if ( ! $plugin->cpts ) {
			return;
		}

		foreach ( $plugin->cpts->get_all() as $slug => $cpt ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Post Runtime Groupings', 'post-runtime-engine' ),
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

		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post_type ) ) {
			return;
		}

		// WordPress media library for the image picker.
		wp_enqueue_media();

		wp_enqueue_style(
			'pre-admin',
			PRE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PRE_VERSION
		);

		wp_enqueue_script(
			'pre-meta-box',
			PRE_PLUGIN_URL . 'assets/js/meta-box.js',
			// jquery-ui-autocomplete is bundled with WP core; it powers the
			// link field's post search. Listing it here pulls in the widget +
			// its position dependency automatically.
			array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete' ),
			PRE_VERSION,
			true
		);

		// Iconify web-component bundle from the jsdelivr CDN. Required so
		// the icon picker preview can render Iconify codes (`mdi:home`,
		// `logos:wordpress`, etc.) live as the user types. The component
		// self-registers; no init code needed. Same module Promptless WP
		// uses, so when both plugins are active the browser caches one copy.
		// Type="module" because iconify-icon ships as an ES module — WordPress
		// honors the script_loader_tag filter so adding the attribute via
		// wp_script_add_data() makes WP emit type="module".
		wp_enqueue_script(
			'pre-iconify-icon',
			'https://cdn.jsdelivr.net/npm/iconify-icon@2.1.0/dist/iconify-icon.min.js',
			array(),
			'2.1.0',
			true
		);
		wp_script_add_data( 'pre-iconify-icon', 'type', 'module' );

		// Localize icon SVGs, REST search endpoint, and nonce so the JS can
		// render previews and run authenticated post-search queries without a
		// page-roundtrip per character.
		wp_localize_script(
			'pre-meta-box',
			'preMetaBox',
			array(
				'icons'       => $this->icon_data_for_js(),
				'mediaTitle'  => __( 'Choose Image', 'post-runtime-engine' ),
				'mediaButton' => __( 'Use this image', 'post-runtime-engine' ),
				// /wp/v2/search returns matches from any post type whose
				// show_in_rest=true. The X-WP-Nonce header authenticates the
				// request as the current user so private/draft posts the user
				// can_edit appear in suggestions.
				'searchUrl'   => esc_url_raw( rest_url( 'wp/v2/search' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'i18n'        => array(
					'remove'  => __( 'Remove', 'post-runtime-engine' ),
					'pickImg' => __( 'Add image', 'post-runtime-engine' ),
					'change'  => __( 'Replace image', 'post-runtime-engine' ),
					'clear'   => __( 'Clear', 'post-runtime-engine' ),
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
		$plugin = pre();

		// Defensive: confirm the post type is one we manage.
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			echo '<p>' . esc_html__( 'This post type is not managed by Post Runtime Engine.', 'post-runtime-engine' ) . '</p>';
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
					'page' => PRE_Admin::PAGE_GROUPINGS,
					'cpt'  => $post->post_type,
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="pre-meta-empty">
				<p>
					<?php esc_html_e( 'No groupings are defined for this post type yet.', 'post-runtime-engine' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $groupings_url ); ?>" class="button">
						<?php esc_html_e( 'Define groupings →', 'post-runtime-engine' ); ?>
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
				'page'     => PRE_Admin::PAGE_GROUPINGS,
				'cpt'      => $cpt_slug,
				'action'   => 'edit',
				'grouping' => $key,
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="pre-meta-grouping" data-grouping-key="<?php echo esc_attr( $key ); ?>" data-max-items="<?php echo esc_attr( (string) $max_items ); ?>">
			<header class="pre-meta-grouping__header">
				<h3>
					<?php echo esc_html( $def['label'] ?? $key ); ?>
					<code class="pre-meta-grouping__key"><?php echo esc_html( $key ); ?></code>
				</h3>
				<a class="pre-meta-grouping__edit-link" href="<?php echo esc_url( $edit_def_url ); ?>">
					<?php esc_html_e( 'Edit definition →', 'post-runtime-engine' ); ?>
				</a>
			</header>

			<?php if ( ! empty( $def['description'] ) ) : ?>
				<p class="pre-meta-grouping__description"><?php echo esc_html( $def['description'] ); ?></p>
			<?php endif; ?>

			<div class="pre-meta-grouping__overrides">
				<label>
					<?php esc_html_e( 'Position', 'post-runtime-engine' ); ?>
					<select name="pre_groupings[<?php echo esc_attr( $key ); ?>][position]">
						<option value=""><?php
							/* translators: %s: default position from grouping definition */
							printf( esc_html__( 'Default (%s)', 'post-runtime-engine' ), esc_html( $def['default_position'] ?? '—' ) );
						?></option>
						<option value="above_main" <?php selected( $position, 'above_main' ); ?>><?php esc_html_e( 'Above main', 'post-runtime-engine' ); ?></option>
						<option value="below_main" <?php selected( $position, 'below_main' ); ?>><?php esc_html_e( 'Below main', 'post-runtime-engine' ); ?></option>
						<option value="sidebar" <?php selected( $position, 'sidebar' ); ?>><?php esc_html_e( 'Sidebar', 'post-runtime-engine' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Variant', 'post-runtime-engine' ); ?>
					<select name="pre_groupings[<?php echo esc_attr( $key ); ?>][variant_override]">
						<option value=""><?php
							/* translators: %s: default variant from grouping definition */
							printf( esc_html__( 'Default (%s)', 'post-runtime-engine' ), esc_html( $def['default_variant'] ?? '—' ) );
						?></option>
						<option value="compact-grid" <?php selected( $variant_override, 'compact-grid' ); ?>><?php esc_html_e( 'Compact grid', 'post-runtime-engine' ); ?></option>
						<option value="card-grid" <?php selected( $variant_override, 'card-grid' ); ?>><?php esc_html_e( 'Card grid', 'post-runtime-engine' ); ?></option>
						<option value="featured-card" <?php selected( $variant_override, 'featured-card' ); ?>><?php esc_html_e( 'Featured card', 'post-runtime-engine' ); ?></option>
						<option value="horizontal-row" <?php selected( $variant_override, 'horizontal-row' ); ?>><?php esc_html_e( 'Horizontal row', 'post-runtime-engine' ); ?></option>
					</select>
				</label>
			</div>

			<?php if ( $is_manual ) : ?>
				<div class="pre-meta-items">
					<h4><?php esc_html_e( 'Items', 'post-runtime-engine' ); ?></h4>
					<ol class="pre-items-list" data-grouping-key="<?php echo esc_attr( $key ); ?>">
						<?php foreach ( $items as $i => $item ) : ?>
							<?php $this->render_item_row( $key, $i, $item ); ?>
						<?php endforeach; ?>
					</ol>

					<button type="button" class="button pre-add-item" data-grouping-key="<?php echo esc_attr( $key ); ?>">
						+ <?php esc_html_e( 'Add item', 'post-runtime-engine' ); ?>
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
							esc_html_e( 'This grouping is auto-populated from this post\'s child posts. Items will be resolved at render time.', 'post-runtime-engine' );
						} elseif ( is_array( $source ) && ( $source['type'] ?? '' ) === 'taxonomy_match' ) {
							/* translators: %s: taxonomy slug */
							printf( esc_html__( 'This grouping is auto-populated from posts sharing the "%s" taxonomy. Items will be resolved at render time.', 'post-runtime-engine' ), esc_html( $source['taxonomy'] ?? '?' ) );
						} elseif ( is_array( $source ) && ( $source['type'] ?? '' ) === 'meta_match' ) {
							/* translators: %s: post-meta key */
							printf( esc_html__( 'This grouping is auto-populated from posts whose "%s" post-meta value matches this post. Items will be resolved at render time.', 'post-runtime-engine' ), esc_html( $source['meta_key'] ?? '?' ) );
						} else {
							esc_html_e( 'This grouping uses an auto source. Items will be resolved at render time.', 'post-runtime-engine' );
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

		$base = 'pre_groupings[' . $key . '][items][' . $index . ']';

		?>
		<li class="pre-item">
			<span class="pre-item__handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'post-runtime-engine' ); ?>">
				<span class="dashicons dashicons-menu" aria-hidden="true"></span>
			</span>

			<div class="pre-item__media">
				<div class="pre-item__preview" data-icon-id="<?php echo esc_attr( $icon_id ); ?>">
					<?php
					if ( $image_id > 0 && $thumb_url ) {
						echo '<img src="' . esc_url( $thumb_url ) . '" alt="">';
					} elseif ( $icon_id !== '' && PRE_Icon_Library::is_valid_id( $icon_id ) ) {
						// PRE_Icon_Library::render() returns either a <span> wrapper
						// with esc_attr()'d class + plugin-curated SVG (legacy
						// icons) OR a <iconify-icon> web component with the
						// icon attribute esc_attr()'d (Iconify codes). The
						// web component fetches SVG from api.iconify.design at
						// paint time; legacy SVGs ship inline. Output is
						// intentionally raw HTML for both paths.
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo PRE_Icon_Library::render( $icon_id );
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
					// See PRE_Icon_Library::is_iconify_format() for the validation
					// pattern; meta-box.js validates the same shape client-side
					// for instant feedback.
					?>
					<input
						type="text"
						class="pre-item__icon-input"
						name="<?php echo esc_attr( $base ); ?>[icon_id]"
						value="<?php echo esc_attr( $icon_id ); ?>"
						placeholder="<?php esc_attr_e( 'mdi:home or "home"', 'post-runtime-engine' ); ?>"
						maxlength="100"
						aria-label="<?php esc_attr_e( 'Icon — Iconify code or curated ID', 'post-runtime-engine' ); ?>"
						spellcheck="false"
						autocomplete="off">

					<p class="pre-item__icon-help description">
						<?php
						printf(
							/* translators: %s: Iconify icon-sets URL */
							wp_kses(
								__( 'Any <a href="%s" target="_blank" rel="noopener">Iconify code</a> (200,000+ icons) or a curated quick-pick below.', 'post-runtime-engine' ),
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

					<div class="pre-item__icon-quickpicks" role="group" aria-label="<?php esc_attr_e( 'Common icons quick-pick', 'post-runtime-engine' ); ?>">
						<?php
						// Curated quick-pick row — small visual grid of the 53
						// built-ins. Clicking a button writes the LEGACY ID into
						// the text input above (legacy IDs continue to ship
						// inline SVG; no network request for the curated set).
						// JS keeps the input + preview in sync; users can still
						// type any Iconify code manually.
						foreach ( PRE_Icon_Library::get_all() as $icon_key => $icon ) :
							$is_selected = ( $icon_id === $icon_key );
							$button_class = 'pre-item__icon-quickpick' . ( $is_selected ? ' is-selected' : '' );
							?>
							<button
								type="button"
								class="<?php echo esc_attr( $button_class ); ?>"
								data-icon-id="<?php echo esc_attr( $icon_key ); ?>"
								title="<?php echo esc_attr( $icon['label'] ); ?>"
								aria-label="<?php echo esc_attr( $icon['label'] ); ?>"
								aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo PRE_Icon_Library::render( $icon_key );
								?>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="pre-item__media-buttons">
						<button type="button" class="button-link pre-pick-image">
							<?php echo $image_id > 0 ? esc_html__( 'Replace image', 'post-runtime-engine' ) : esc_html__( 'Add image', 'post-runtime-engine' ); ?>
						</button>
						<button type="button" class="button-link pre-clear-media">
							<?php esc_html_e( 'Clear', 'post-runtime-engine' ); ?>
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
					placeholder="<?php esc_attr_e( 'Heading', 'post-runtime-engine' ); ?>"
					aria-label="<?php esc_attr_e( 'Heading', 'post-runtime-engine' ); ?>"
					maxlength="200">
				<textarea
					class="pre-item__supporting"
					rows="2"
					name="<?php echo esc_attr( $base ); ?>[supporting_text]"
					placeholder="<?php esc_attr_e( 'Supporting text', 'post-runtime-engine' ); ?>"
					aria-label="<?php esc_attr_e( 'Supporting text', 'post-runtime-engine' ); ?>"
					maxlength="1000"><?php echo esc_textarea( $supporting_text ); ?></textarea>
				<input
					type="text"
					class="pre-item__link"
					name="<?php echo esc_attr( $base ); ?>[link]"
					value="<?php echo esc_attr( $link ); ?>"
					placeholder="<?php esc_attr_e( 'Search posts, or paste https://, /path, #anchor, tel:, mailto:', 'post-runtime-engine' ); ?>"
					aria-label="<?php esc_attr_e( 'Link — type a post name to search, or paste a URL', 'post-runtime-engine' ); ?>"
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

			<button type="button" class="pre-item__remove pre-remove-item" aria-label="<?php esc_attr_e( 'Remove item', 'post-runtime-engine' ); ?>">
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
		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		// Capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Pull the raw POSTed groupings.
		$raw = isset( $_POST['pre_groupings'] ) && is_array( $_POST['pre_groupings'] )
			? wp_unslash( $_POST['pre_groupings'] )
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

		// Persist via PRE_Post_Data which validates strictly.
		if ( ! $plugin->post_data ) {
			return;
		}

		$result = $plugin->post_data->set_groupings( $post_id, $groupings, 'admin' );

		if ( is_wp_error( $result ) ) {
			$user_id = get_current_user_id();
			set_transient(
				'pre_admin_notice_' . $user_id,
				array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: validation error message */
						__( 'Post Runtime: groupings could not be saved — %s', 'post-runtime-engine' ),
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
		foreach ( PRE_Icon_Library::get_all() as $id => $icon ) {
			$result[ $id ] = array(
				'label' => $icon['label'],
				'svg'   => $icon['svg'],
			);
		}
		return $result;
	}
}
