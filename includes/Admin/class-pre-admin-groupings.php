<?php
/**
 * Per-CPT grouping definition admin page.
 *
 * Reachable via admin.php?page=pcptpages-groupings&cpt={slug}. Lists groupings
 * defined for the given CPT, lets the admin add/edit/remove definitions.
 * Each definition specifies: identifier (key + label), default presentation
 * (variant, position, max items), default source mode (manual / child_posts /
 * taxonomy_match-with-config), and per-item field requirements.
 *
 * All persistence goes through PCPTPages_Grouping_Registry; this class is the
 * UI layer only.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
 *
 * Justification: This admin class follows a dispatcher-handler pattern.
 * Dispatcher methods read $_GET / $_POST to route to handlers; the actual
 * nonce verification happens inside each handler via check_admin_referer().
 * Plugin Check's static analyzer cannot trace verification across method
 * boundaries — search for check_admin_referer in this file to see the
 * actual verification points. All sanitization is applied at the handler
 * boundary via sanitize_key / sanitize_text_field / etc.
 *
 * The slow_db_query_meta_key disable handles a separate false positive:
 * the meta_match source-mode configuration stores a meta key name as a
 * config value (the user picks which meta key the auto-source will match
 * against at render time). That's data assignment, not a query — but the
 * static analyzer fires on the string "meta_key" appearing in the code.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grouping definition admin page.
 */
class PCPTPages_Admin_Groupings {

	const ACTION_SAVE   = 'pcptpages_save_grouping';
	const ACTION_DELETE = 'pcptpages_delete_grouping';

	/**
	 * Form values stashed from a failed POST so render_form() can pre-fill.
	 *
	 * @var array|null
	 */
	private $form_values = null;

	/**
	 * Validation error from a failed save.
	 *
	 * @var WP_Error|null
	 */
	private $form_error = null;

	/**
	 * The CPT slug this page operates against. Determined from the URL on
	 * every request and validated against the registry.
	 *
	 * @var string
	 */
	private $cpt_slug = '';

	/**
	 * Handle save / delete actions before any output.
	 */
	public function handle_action() {
		if ( ! PCPTPages_Capabilities::current_user_can_manage() ) {
			return;
		}

		$this->cpt_slug = isset( $_GET['cpt'] ) ? sanitize_key( wp_unslash( $_GET['cpt'] ) ) : '';
		if ( $this->cpt_slug === '' ) {
			return;
		}

		// POST: save grouping definition.
		if ( isset( $_POST['action'] ) && $_POST['action'] === self::ACTION_SAVE ) {
			$this->handle_save();
			return;
		}

		// GET: delete grouping (with nonce).
		if (
			isset( $_GET['action'], $_GET['grouping'], $_GET['_wpnonce'] )
			&& sanitize_key( wp_unslash( $_GET['action'] ) ) === 'delete-grouping'
		) {
			$this->handle_delete();
			return;
		}
	}

	/**
	 * Render the page. Dispatches to list, new, or edit view based on the
	 * `action` query arg.
	 */
	public function render() {
		$this->cpt_slug = isset( $_GET['cpt'] ) ? sanitize_key( wp_unslash( $_GET['cpt'] ) ) : '';

		echo '<div class="wrap pre-admin">';

		// Confirm the CPT exists before doing anything else.
		$plugin = pcptpages();
		$cpt    = $plugin->cpts ? $plugin->cpts->get( $this->cpt_slug ) : null;

		if ( $this->cpt_slug === '' || ! $cpt ) {
			echo '<h1>' . esc_html__( 'Manage Groupings', 'promptless-cpt-pages' ) . '</h1>';
			echo '<p>' . esc_html__( 'No CPT specified, or that CPT is not registered. Open this page from the Post Types list.', 'promptless-cpt-pages' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PCPTPages_Admin::PAGE_CPTS ) ) . '">';
			echo esc_html__( '← Back to Post Types', 'promptless-cpt-pages' );
			echo '</a></p>';
			echo '</div>';
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( $action === 'new' ) {
			$this->render_form( 'new', $cpt );
		} elseif ( $action === 'edit' && ! empty( $_GET['grouping'] ) ) {
			$grouping_key = sanitize_key( wp_unslash( $_GET['grouping'] ) );
			$this->render_form( 'edit', $cpt, $grouping_key );
		} else {
			$this->render_list( $cpt );
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// List view
	// -----------------------------------------------------------------------

	/**
	 * Render the list of groupings for the current CPT.
	 *
	 * @param array $cpt CPT definition.
	 */
	private function render_list( array $cpt ) {
		$plugin    = pcptpages();
		$groupings = $plugin->groupings ? $plugin->groupings->get_all( $this->cpt_slug ) : array();

		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: CPT plural label */
			printf( esc_html__( 'Groupings for "%s"', 'promptless-cpt-pages' ), esc_html( $cpt['label_plural'] ?? $this->cpt_slug ) );
			?>
		</h1>
		<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add New', 'promptless-cpt-pages' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PCPTPages_Admin::PAGE_CPTS ) ); ?>" class="page-title-action">
			<?php esc_html_e( '← Post Types', 'promptless-cpt-pages' ); ?>
		</a>
		<hr class="wp-header-end">

		<p class="description">
			<?php esc_html_e( 'Groupings are the structured data fields each post in this CPT can include. Each grouping renders as an ordered list of icon/heading/text/link items via one of four layout variants.', 'promptless-cpt-pages' ); ?>
		</p>

		<?php if ( empty( $groupings ) ) : ?>
			<div class="pre-empty-state">
				<p>
					<?php esc_html_e( 'No groupings defined for this CPT yet.', 'promptless-cpt-pages' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Define your first grouping', 'promptless-cpt-pages' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped pre-groupings-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Key', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Label', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Variant', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Position', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Source', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Max items', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'promptless-cpt-pages' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $groupings as $key => $def ) : ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( $def['label'] ?? '' ); ?></td>
							<td><?php echo esc_html( $def['default_variant'] ?? '' ); ?></td>
							<td><?php echo esc_html( $def['default_position'] ?? '' ); ?></td>
							<td><?php echo esc_html( $this->describe_source( $def['default_source'] ?? 'manual' ) ); ?></td>
							<td><?php echo esc_html( isset( $def['max_items'] ) && $def['max_items'] !== null ? (string) $def['max_items'] : '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->url( array( 'action' => 'edit', 'grouping' => $key ) ) ); ?>">
									<?php esc_html_e( 'Edit', 'promptless-cpt-pages' ); ?>
								</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $this->delete_url( $key ) ); ?>"
									class="pre-delete-link"
									onclick="return confirm('<?php echo esc_js( __( 'Remove this grouping definition? Posts that already filled in items for this grouping will keep their data — the grouping just won\'t render until you redefine it.', 'promptless-cpt-pages' ) ); ?>');">
									<?php esc_html_e( 'Remove', 'promptless-cpt-pages' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php
		endif;
	}

	/**
	 * Format a `default_source` value for display in the list column.
	 *
	 * @param mixed $source Source value (string or array).
	 * @return string
	 */
	private function describe_source( $source ) {
		if ( is_string( $source ) ) {
			return $source;
		}
		if ( is_array( $source ) && isset( $source['type'] ) ) {
			if ( $source['type'] === 'taxonomy_match' ) {
				$tax = $source['taxonomy'] ?? '?';
				return 'taxonomy_match: ' . $tax;
			}
			if ( $source['type'] === 'meta_match' ) {
				$key = $source['meta_key'] ?? '?';
				return 'meta_match: ' . $key;
			}
			return $source['type'];
		}
		return '—';
	}

	// -----------------------------------------------------------------------
	// Form view
	// -----------------------------------------------------------------------

	/**
	 * Render the new/edit form.
	 *
	 * @param string $mode         'new' or 'edit'.
	 * @param array  $cpt          The parent CPT definition.
	 * @param string $grouping_key The grouping key (edit mode only).
	 */
	private function render_form( $mode, array $cpt, $grouping_key = '' ) {
		$plugin   = pcptpages();
		$is_edit  = ( $mode === 'edit' );
		$existing = null;

		if ( $is_edit ) {
			$existing = $plugin->groupings ? $plugin->groupings->get( $this->cpt_slug, $grouping_key ) : null;
			if ( ! $existing ) {
				echo '<h1>' . esc_html__( 'Edit Grouping', 'promptless-cpt-pages' ) . '</h1>';
				echo '<p>' . esc_html__( 'That grouping does not exist for this CPT.', 'promptless-cpt-pages' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( $this->url() ) . '">' . esc_html__( '← Back to Groupings', 'promptless-cpt-pages' ) . '</a></p>';
				return;
			}
		}

		$values = $this->form_values
			?: ( $is_edit ? $this->definition_to_form_values( $existing ) : $this->default_form_values() );

		// Form action URL preserves the page + cpt + action (and grouping key on edit)
		// for the same reason as the CPT form: validation errors don't redirect, so
		// the URL must already be set up for the form view to re-render.
		$form_action_args = array( 'page' => PCPTPages_Admin::PAGE_GROUPINGS, 'cpt' => $this->cpt_slug );
		if ( $is_edit ) {
			$form_action_args['action']   = 'edit';
			$form_action_args['grouping'] = $grouping_key;
		} else {
			$form_action_args['action'] = 'new';
		}
		$form_action_url = add_query_arg( $form_action_args, admin_url( 'admin.php' ) );

		?>
		<h1><?php
			echo esc_html( $is_edit
				? sprintf(
					/* translators: %1$s: grouping key, %2$s: CPT plural label */
					__( 'Edit Grouping: %1$s (in %2$s)', 'promptless-cpt-pages' ),
					$grouping_key,
					$cpt['label_plural'] ?? $this->cpt_slug
				)
				: sprintf(
					/* translators: %s: CPT plural label */
					__( 'New Grouping for %s', 'promptless-cpt-pages' ),
					$cpt['label_plural'] ?? $this->cpt_slug
				)
			);
		?></h1>

		<?php if ( $this->form_error instanceof WP_Error ) : ?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $this->form_error->get_error_message() ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $form_action_url ); ?>" class="pre-grouping-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<?php wp_nonce_field( self::ACTION_SAVE, 'pcptpages_nonce' ); ?>

			<h2 class="title"><?php esc_html_e( 'Identifier', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pcptpages_grouping_key"><?php esc_html_e( 'Key', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pcptpages_grouping_key"
							name="key"
							class="regular-text code"
							value="<?php echo esc_attr( $values['key'] ); ?>"
							<?php echo $is_edit ? 'readonly' : ''; ?>
							required>
						<p class="description">
							<?php esc_html_e( 'Lowercase letters, numbers, and underscores only. Used by Cowork and the connector to reference this grouping.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pcptpages_grouping_label"><?php esc_html_e( 'Label', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pcptpages_grouping_label"
							name="label"
							class="regular-text"
							value="<?php echo esc_attr( $values['label'] ); ?>"
							required
							maxlength="200">
						<p class="description">
							<?php esc_html_e( 'Human-readable label shown above the grouping when rendered (e.g., "Quick Specs", "Amenities", "Practice Areas").', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pcptpages_grouping_description"><?php esc_html_e( 'Description', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<textarea
							id="pcptpages_grouping_description"
							name="description"
							class="large-text"
							rows="2"><?php echo esc_textarea( $values['description'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Optional. Helps editors remember what content belongs in this grouping. Not displayed on the frontend.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Default presentation', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pcptpages_default_variant"><?php esc_html_e( 'Default variant', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pcptpages_default_variant" name="default_variant" required>
							<?php foreach ( $this->variants_with_labels() as $variant => $label ) : ?>
								<option value="<?php echo esc_attr( $variant ); ?>" <?php selected( $values['default_variant'], $variant ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'How this grouping renders by default. Editors can override per-post on the post edit screen.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<?php
				// Gallery-only: tile aspect ratio. Row visibility follows the
				// Default variant select (admin-groupings.js), mirroring the
				// source-row toggle pattern. Vocabulary reuses the shared
				// aspect enum (matches archive_image_aspect + Promptless
				// section aspect controls — one aspect language).
				// GALLERY_VARIANT_DESIGN.md §3 (amended).
				?>
				<tr class="pre-gallery-row">
					<th scope="row">
						<label for="pcptpages_gallery_image_aspect"><?php esc_html_e( 'Gallery tile aspect ratio', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pcptpages_gallery_image_aspect" name="gallery_image_aspect">
							<?php
							$aspect_labels = array(
								'16:9' => __( '16:9 — wide (default; interiors, vehicles, landscapes)', 'promptless-cpt-pages' ),
								'4:3'  => __( '4:3 — standard (product and property photography)', 'promptless-cpt-pages' ),
								'1:1'  => __( '1:1 — square (portfolio grids, food)', 'promptless-cpt-pages' ),
								'4:5'  => __( '4:5 — portrait (people, tall subjects)', 'promptless-cpt-pages' ),
							);
							$current_aspect = $values['gallery_image_aspect'] ?? '16:9';
							foreach ( $aspect_labels as $aspect => $label ) :
								?>
								<option value="<?php echo esc_attr( $aspect ); ?>" <?php selected( $current_aspect, $aspect ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Crop shape for gallery tiles. Applies only when the variant is Gallery. The lightbox always shows the full uncropped image.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pcptpages_default_position"><?php esc_html_e( 'Default position', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pcptpages_default_position" name="default_position" required>
							<?php foreach ( $this->positions_with_labels() as $position => $label ) : ?>
								<option value="<?php echo esc_attr( $position ); ?>" <?php selected( $values['default_position'], $position ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Where this grouping renders relative to the post\'s main content.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pcptpages_max_items"><?php esc_html_e( 'Max items', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="pcptpages_max_items"
							name="max_items"
							class="small-text"
							value="<?php echo esc_attr( $values['max_items'] === null ? '' : (string) $values['max_items'] ); ?>"
							min="1"
							max="100">
						<p class="description">
							<?php esc_html_e( 'Optional cap on items per post. Leave blank for no cap (up to the global maximum of 100). Required = 1 when default variant is featured-card.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Default source', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pcptpages_source_type"><?php esc_html_e( 'Source mode', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pcptpages_source_type" name="source_type">
							<option value="manual" <?php selected( $values['source_type'], 'manual' ); ?>>
								<?php esc_html_e( 'Manual — items entered per post', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="child_posts" <?php selected( $values['source_type'], 'child_posts' ); ?>>
								<?php esc_html_e( 'Child posts — auto-populated from this post\'s children (CPT must be hierarchical)', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="taxonomy_match" <?php selected( $values['source_type'], 'taxonomy_match' ); ?>>
								<?php esc_html_e( 'Taxonomy match — auto-populated from posts sharing a taxonomy term', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="meta_match" <?php selected( $values['source_type'], 'meta_match' ); ?>>
								<?php esc_html_e( 'Meta match — auto-populated from posts whose meta field equals this post\'s value', 'promptless-cpt-pages' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'For "child_posts" the parent CPT must have hierarchical = true. For "taxonomy_match" specify a taxonomy slug below. For "meta_match" specify the post-meta key that identifies related posts (e.g., _agent_id, _employer_id).', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr class="pre-source-row pre-source-row--taxonomy">
					<th scope="row">
						<label for="pcptpages_source_taxonomy"><?php esc_html_e( 'Taxonomy slug', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pcptpages_source_taxonomy"
							name="source_taxonomy"
							class="regular-text code"
							value="<?php echo esc_attr( $values['source_taxonomy'] ); ?>">
						<p class="description">
							<?php esc_html_e( 'Only used when source mode is "taxonomy_match". Must be a taxonomy registered with WordPress.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr class="pre-source-row pre-source-row--meta">
					<th scope="row">
						<label for="pcptpages_source_meta_key"><?php esc_html_e( 'Post-meta key', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pcptpages_source_meta_key"
							name="source_meta_key"
							class="regular-text code"
							value="<?php echo esc_attr( $values['source_meta_key'] ); ?>"
							maxlength="64">
						<p class="description">
							<?php esc_html_e( 'Only used when source mode is "meta_match". The resolver reads the current post\'s value for this meta key and returns other posts in the same CPT whose value matches. Underscore-prefixed keys (private meta) are allowed. Examples: _agent_id, _employer_id, _business_id.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr class="pre-source-row pre-source-row--auto">
					<th scope="row">
						<label for="pcptpages_source_limit"><?php esc_html_e( 'Item limit', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="pcptpages_source_limit"
							name="source_limit"
							class="small-text"
							value="<?php echo esc_attr( $values['source_limit'] === null ? '' : (string) $values['source_limit'] ); ?>"
							min="1"
							max="100">
						<p class="description">
							<?php esc_html_e( 'Maximum items the auto-populated source returns. Defaults to 6.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr class="pre-source-row pre-source-row--auto">
					<th scope="row"><?php esc_html_e( 'Self-reference', 'promptless-cpt-pages' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="source_exclude_self" value="1" <?php checked( $values['source_exclude_self'], true ); ?>>
							<?php esc_html_e( 'Exclude the current post from results', 'promptless-cpt-pages' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Field requirements', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Per-item requirements', 'promptless-cpt-pages' ); ?></th>
					<td>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="heading_required" value="1" <?php checked( $values['heading_required'], true ); ?>>
							<?php esc_html_e( 'Heading is required', 'promptless-cpt-pages' ); ?>
						</label>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="supporting_text_required" value="1" <?php checked( $values['supporting_text_required'], true ); ?>>
							<?php esc_html_e( 'Supporting text is required', 'promptless-cpt-pages' ); ?>
						</label>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="link_required" value="1" <?php checked( $values['link_required'], true ); ?>>
							<?php esc_html_e( 'Link is required', 'promptless-cpt-pages' ); ?>
						</label>
						<label style="display:block; margin-bottom:4px;">
							<input type="checkbox" name="icon_or_image_required" value="1" <?php checked( $values['icon_or_image_required'], true ); ?>>
							<?php esc_html_e( 'Icon or image is required', 'promptless-cpt-pages' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Heading defaults to required because most variants depend on it. Adjust the others to match the data shape this grouping needs (e.g., a featured-card agent grouping should require image and supporting text).', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( $is_edit ? __( 'Save changes', 'promptless-cpt-pages' ) : __( 'Define grouping', 'promptless-cpt-pages' ) ); ?>

			<p>
				<a class="button" href="<?php echo esc_url( $this->url() ); ?>">
					<?php esc_html_e( '← Cancel', 'promptless-cpt-pages' ); ?>
				</a>
			</p>
		</form>
		<?php
		// The source-type row visibility toggle script lives in
		// assets/js/admin-groupings.js, enqueued by PCPTPages_Admin::enqueue_assets()
		// when $current_page === PAGE_GROUPINGS. No inline <script> here per
		// WordPress.org Plugin Check guidelines.
	}

	// -----------------------------------------------------------------------
	// Action handlers
	// -----------------------------------------------------------------------

	/**
	 * Handle a save (new or edit) submission.
	 */
	private function handle_save() {
		check_admin_referer( self::ACTION_SAVE, 'pcptpages_nonce' );

		$plugin = pcptpages();
		if ( ! $plugin->groupings ) {
			$this->queue_notice( 'error', __( 'Internal error: grouping registry not available.', 'promptless-cpt-pages' ) );
			$this->redirect( $this->url() );
		}

		$values = $this->collect_form_values();

		// Build the definition payload from form values.
		$definition = $this->form_values_to_definition( $values );

		$result = $plugin->groupings->define( $this->cpt_slug, $definition );

		if ( is_wp_error( $result ) ) {
			$this->form_values = $values;
			$this->form_error  = $result;
			return;
		}

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %s: grouping key */
				__( 'Grouping "%s" saved.', 'promptless-cpt-pages' ),
				$values['key']
			)
		);

		$this->redirect( $this->url() );
	}

	/**
	 * Handle a delete (remove grouping) action.
	 */
	private function handle_delete() {
		$grouping_key = sanitize_key( wp_unslash( $_GET['grouping'] ) );

		check_admin_referer( self::ACTION_DELETE . '_' . $grouping_key );

		$plugin = pcptpages();
		if ( ! $plugin->groupings ) {
			$this->queue_notice( 'error', __( 'Internal error: grouping registry not available.', 'promptless-cpt-pages' ) );
			$this->redirect( $this->url() );
		}

		$result = $plugin->groupings->remove( $this->cpt_slug, $grouping_key );

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( 'error', $result->get_error_message() );
		} else {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %s: grouping key */
					__( 'Grouping "%s" removed.', 'promptless-cpt-pages' ),
					$grouping_key
				)
			);
		}

		$this->redirect( $this->url() );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Collect and shape POSTed form values. Sanitization here is conservative —
	 * actual validation is done by PCPTPages_Validator on save.
	 *
	 * @return array
	 */
	private function collect_form_values() {
		return array(
			'key'                      => isset( $_POST['key'] ) ? trim( wp_unslash( $_POST['key'] ) ) : '',
			'label'                    => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'description'              => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'default_variant'          => isset( $_POST['default_variant'] ) ? sanitize_key( wp_unslash( $_POST['default_variant'] ) ) : '',
			// Aspect values contain a colon (16:9) — sanitize_key would strip
			// it, so use sanitize_text_field; the validator enum-checks it.
			'gallery_image_aspect'     => isset( $_POST['gallery_image_aspect'] ) ? sanitize_text_field( wp_unslash( $_POST['gallery_image_aspect'] ) ) : '16:9',
			'default_position'         => isset( $_POST['default_position'] ) ? sanitize_key( wp_unslash( $_POST['default_position'] ) ) : '',
			'max_items'                => isset( $_POST['max_items'] ) && $_POST['max_items'] !== '' ? max( 1, min( 100, (int) $_POST['max_items'] ) ) : null,
			'source_type'              => isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'manual',
			'source_taxonomy'          => isset( $_POST['source_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['source_taxonomy'] ) ) : '',
			// Meta keys allow a single leading underscore (private-meta convention)
			// so we sanitize trim + lowercase manually rather than using
			// sanitize_key (which would strip the leading underscore).
			'source_meta_key'          => isset( $_POST['source_meta_key'] )
				? strtolower( trim( wp_unslash( (string) $_POST['source_meta_key'] ) ) )
				: '',
			'source_limit'             => isset( $_POST['source_limit'] ) && $_POST['source_limit'] !== '' ? max( 1, min( 100, (int) $_POST['source_limit'] ) ) : null,
			'source_exclude_self'      => ! empty( $_POST['source_exclude_self'] ),
			'heading_required'         => ! empty( $_POST['heading_required'] ),
			'supporting_text_required' => ! empty( $_POST['supporting_text_required'] ),
			'link_required'            => ! empty( $_POST['link_required'] ),
			'icon_or_image_required'   => ! empty( $_POST['icon_or_image_required'] ),
		);
	}

	/**
	 * Convert form values into a grouping definition payload that
	 * PCPTPages_Grouping_Registry::define() expects.
	 *
	 * @param array $values Form values.
	 * @return array
	 */
	private function form_values_to_definition( array $values ) {
		$definition = array(
			'key'                      => $values['key'],
			'label'                    => $values['label'],
			'description'              => $values['description'],
			'default_variant'          => $values['default_variant'],
			'gallery_image_aspect'     => $values['gallery_image_aspect'],
			'default_position'         => $values['default_position'],
			'heading_required'         => $values['heading_required'],
			'supporting_text_required' => $values['supporting_text_required'],
			'link_required'            => $values['link_required'],
			'icon_or_image_required'   => $values['icon_or_image_required'],
		);

		if ( $values['max_items'] !== null ) {
			$definition['max_items'] = $values['max_items'];
		}

		// Build the default_source value. String form for manual / child_posts;
		// object form for taxonomy_match + meta_match. Validator decides if
		// the shape is OK.
		switch ( $values['source_type'] ) {
			case 'manual':
				$definition['default_source'] = 'manual';
				break;
			case 'child_posts':
				$definition['default_source'] = 'child_posts';
				break;
			case 'taxonomy_match':
				$source = array(
					'type'         => 'taxonomy_match',
					'taxonomy'     => $values['source_taxonomy'],
					'exclude_self' => $values['source_exclude_self'],
				);
				if ( $values['source_limit'] !== null ) {
					$source['limit'] = $values['source_limit'];
				}
				$definition['default_source'] = $source;
				break;
			case 'meta_match':
				$source = array(
					'type'         => 'meta_match',
					'meta_key'     => $values['source_meta_key'],
					'exclude_self' => $values['source_exclude_self'],
				);
				if ( $values['source_limit'] !== null ) {
					$source['limit'] = $values['source_limit'];
				}
				$definition['default_source'] = $source;
				break;
			default:
				// Pass through whatever the user submitted so the validator
				// can return a meaningful error.
				$definition['default_source'] = $values['source_type'];
		}

		return $definition;
	}

	/**
	 * Convert a stored grouping definition into form values for the edit form.
	 *
	 * @param array $definition Grouping definition.
	 * @return array
	 */
	private function definition_to_form_values( array $definition ) {
		$source = $definition['default_source'] ?? 'manual';

		$values = array(
			'key'                      => $definition['key'] ?? '',
			'label'                    => $definition['label'] ?? '',
			'description'              => $definition['description'] ?? '',
			'default_variant'          => $definition['default_variant'] ?? 'compact-grid',
			'gallery_image_aspect'     => $definition['gallery_image_aspect'] ?? '16:9',
			'default_position'         => $definition['default_position'] ?? 'above_main',
			'max_items'                => $definition['max_items'] ?? null,
			'source_type'              => is_array( $source ) ? ( $source['type'] ?? 'manual' ) : (string) $source,
			'source_taxonomy'          => is_array( $source ) ? ( $source['taxonomy'] ?? '' ) : '',
			'source_meta_key'          => is_array( $source ) ? ( $source['meta_key'] ?? '' ) : '',
			'source_limit'             => is_array( $source ) ? ( $source['limit'] ?? null ) : null,
			'source_exclude_self'      => is_array( $source ) ? ( $source['exclude_self'] ?? true ) : true,
			'heading_required'         => isset( $definition['heading_required'] ) ? (bool) $definition['heading_required'] : true,
			'supporting_text_required' => isset( $definition['supporting_text_required'] ) ? (bool) $definition['supporting_text_required'] : false,
			'link_required'            => isset( $definition['link_required'] ) ? (bool) $definition['link_required'] : false,
			'icon_or_image_required'   => isset( $definition['icon_or_image_required'] ) ? (bool) $definition['icon_or_image_required'] : false,
		);

		return $values;
	}

	/**
	 * Default form values for a new grouping.
	 *
	 * @return array
	 */
	private function default_form_values() {
		return array(
			'key'                      => '',
			'label'                    => '',
			'description'              => '',
			'default_variant'          => 'compact-grid',
			'gallery_image_aspect'     => '16:9',
			'default_position'         => 'above_main',
			'max_items'                => null,
			'source_type'              => 'manual',
			'source_taxonomy'          => '',
			'source_limit'             => null,
			'source_exclude_self'      => true,
			'heading_required'         => true,
			'supporting_text_required' => false,
			'link_required'             => false,
			'icon_or_image_required'   => false,
		);
	}

	/**
	 * Layout variant slugs paired with human-readable labels.
	 *
	 * @return array<string,string>
	 */
	private function variants_with_labels() {
		return array(
			'compact-grid'   => __( 'Compact grid (icon + heading, multi-column)', 'promptless-cpt-pages' ),
			'card-grid'      => __( 'Card grid (icon + heading + supporting text, multi-column)', 'promptless-cpt-pages' ),
			'featured-card'  => __( 'Featured card (image + heading + text + CTA, single item)', 'promptless-cpt-pages' ),
			'horizontal-row' => __( 'Horizontal row (inline chips for at-a-glance specs)', 'promptless-cpt-pages' ),
			'gallery'        => __( 'Gallery (photo grid with lightbox; heading = optional caption)', 'promptless-cpt-pages' ),
		);
	}

	/**
	 * Position slugs paired with human-readable labels.
	 *
	 * @return array<string,string>
	 */
	private function positions_with_labels() {
		return array(
			'above_main' => __( 'Above main content', 'promptless-cpt-pages' ),
			'below_main' => __( 'Below main content', 'promptless-cpt-pages' ),
			'sidebar'    => __( 'Sidebar (sticky on desktop, stacked on mobile)', 'promptless-cpt-pages' ),
		);
	}

	/**
	 * Render whatever notice is queued for this request.
	 */
	public function render_notice() {
		$user_id = get_current_user_id();
		$key     = 'pcptpages_admin_notice_' . $user_id;
		$notice  = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$type    = isset( $notice['type'] ) ? $notice['type'] : 'info';
		$message = isset( $notice['message'] ) ? $notice['message'] : '';
		$class   = $type === 'success' ? 'notice-success' : ( $type === 'error' ? 'notice-error' : 'notice-info' );
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Queue a notice for the next request.
	 *
	 * @param string $type    'success' | 'error' | 'info'.
	 * @param string $message Message body.
	 */
	private function queue_notice( $type, $message ) {
		$user_id = get_current_user_id();
		set_transient(
			'pcptpages_admin_notice_' . $user_id,
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}

	/**
	 * Build a URL to the groupings page with optional extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => PCPTPages_Admin::PAGE_GROUPINGS,
					'cpt'  => $this->cpt_slug,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a delete URL for a grouping with nonce.
	 *
	 * @param string $grouping_key Grouping key.
	 * @return string
	 */
	private function delete_url( $grouping_key ) {
		return wp_nonce_url(
			$this->url( array( 'action' => 'delete-grouping', 'grouping' => $grouping_key ) ),
			self::ACTION_DELETE . '_' . $grouping_key
		);
	}

	/**
	 * Redirect helper. Wraps wp_safe_redirect for testability.
	 *
	 * @param string $url Target URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
