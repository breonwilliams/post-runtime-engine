<?php
/**
 * Post Fields admin page for Post Runtime Engine (v1.1).
 *
 * Per-CPT management UI for the second field type — scalar post fields.
 * Mirrors PRE_Admin_Groupings exactly: list view + new/edit form, server-
 * rendered, save via admin-post with nonce, GET-with-nonce for delete.
 *
 * The "live preview pane" from the design contract (§ 8) is intentionally
 * deferred to a follow-up phase: the existing groupings admin doesn't
 * include one, and shipping a live preview on the new page alone would
 * be UX inconsistent. Real client demand can drive the follow-up.
 *
 * URL: admin.php?page=pre-post-fields&cpt={slug}
 *
 * @package PostRuntimeEngine
 * @since 1.1.0
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
 *
 * Justification: This admin class follows a dispatcher-handler pattern.
 * Dispatcher methods read $_GET / $_POST to route to handlers; the actual
 * nonce verification happens inside each handler via check_admin_referer().
 * Plugin Check's static analyzer cannot trace verification across method
 * boundaries — search for check_admin_referer in this file to see the
 * actual verification points. All sanitization is applied at the handler
 * boundary via sanitize_key / sanitize_text_field / etc.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Post Fields admin page and handles its save / delete /
 * reorder actions.
 */
class PRE_Admin_Post_Fields {

	const ACTION_SAVE    = 'pre_save_post_field';
	const ACTION_DELETE  = 'pre_delete_post_field';
	const ACTION_REORDER = 'pre_reorder_post_fields';
	const NOTICE_KEY     = 'pre_post_fields_notice';

	/**
	 * Form values stashed from a failed POST so the form view can pre-fill
	 * after a validation error.
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
	 * Active CPT slug, resolved from the URL on every request.
	 *
	 * @var string
	 */
	private $cpt_slug = '';

	/**
	 * Dispatch POST / GET-action handlers before any output. Called on
	 * admin_init by PRE_Admin::handle_actions().
	 */
	public function handle_action() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			return;
		}

		$this->cpt_slug = isset( $_GET['cpt'] ) ? sanitize_key( wp_unslash( $_GET['cpt'] ) ) : '';
		if ( $this->cpt_slug === '' ) {
			return;
		}

		// POST: save field definition.
		if ( isset( $_POST['action'] ) && $_POST['action'] === self::ACTION_SAVE ) {
			$this->handle_save();
			return;
		}

		// POST: reorder field list (drag handles in the list view).
		if ( isset( $_POST['action'] ) && $_POST['action'] === self::ACTION_REORDER ) {
			$this->handle_reorder();
			return;
		}

		// GET: delete (with nonce).
		if (
			isset( $_GET['action'], $_GET['field'], $_GET['_wpnonce'] )
			&& sanitize_key( wp_unslash( $_GET['action'] ) ) === 'delete-post-field'
		) {
			$this->handle_delete();
			return;
		}
	}

	/**
	 * Render the page. Dispatches to list / new / edit based on the
	 * action query arg.
	 */
	public function render() {
		$this->cpt_slug = isset( $_GET['cpt'] ) ? sanitize_key( wp_unslash( $_GET['cpt'] ) ) : '';

		echo '<div class="wrap pre-admin">';

		$plugin = pre();
		$cpt    = $plugin->cpts ? $plugin->cpts->get( $this->cpt_slug ) : null;

		if ( $this->cpt_slug === '' || ! $cpt ) {
			echo '<h1>' . esc_html__( 'Manage Post Fields', 'post-runtime-engine' ) . '</h1>';
			echo '<p>' . esc_html__( 'No CPT specified, or that CPT is not registered. Open this page from the Post Types list.', 'post-runtime-engine' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PRE_Admin::PAGE_CPTS ) ) . '">';
			echo esc_html__( '← Back to Post Types', 'post-runtime-engine' );
			echo '</a></p>';
			echo '</div>';
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( $action === 'new' ) {
			$this->render_form( 'new', $cpt );
		} elseif ( $action === 'edit' && ! empty( $_GET['field'] ) ) {
			$field_key = sanitize_key( wp_unslash( $_GET['field'] ) );
			$this->render_form( 'edit', $cpt, $field_key );
		} else {
			$this->render_list( $cpt );
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// List view
	// -----------------------------------------------------------------------

	/**
	 * Render the list of post fields for the current CPT.
	 *
	 * @param array $cpt CPT definition.
	 */
	private function render_list( array $cpt ) {
		$plugin = pre();
		$fields = $plugin->post_fields ? $plugin->post_fields->get_all( $this->cpt_slug ) : array();
		$count  = count( $fields );
		$soft   = PRE_Validator::SOFT_FIELD_COUNT_WARNING;
		$hard   = (int) apply_filters( 'pre_max_post_fields_per_cpt', PRE_Validator::HARD_FIELD_COUNT_LIMIT );

		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: CPT plural label */
			printf( esc_html__( 'Post Fields for "%s"', 'post-runtime-engine' ), esc_html( $cpt['label_plural'] ?? $this->cpt_slug ) );
			?>
		</h1>
		<?php if ( $count < $hard ) : ?>
			<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'post-runtime-engine' ); ?>
			</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PRE_Admin::PAGE_CPTS ) ); ?>" class="page-title-action">
			<?php esc_html_e( '← Post Types', 'post-runtime-engine' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PRE_Admin::PAGE_GROUPINGS . '&cpt=' . $this->cpt_slug ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Groupings', 'post-runtime-engine' ); ?>
		</a>
		<hr class="wp-header-end">

		<p class="description">
			<?php esc_html_e( 'Post fields are scalar (single-value) data points that decorate the single-post hero AND any cards listing posts of this type. Each field picks a display type (currency, badge, date, etc.) and a position in both contexts. See the design contract in docs/POST_FIELDS_V1_1_DESIGN.md for the full enum.', 'post-runtime-engine' ); ?>
		</p>

		<?php if ( $count >= $soft ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					if ( $count >= $hard ) {
						printf(
							/* translators: %d: hard limit */
							esc_html__( 'This CPT has reached the maximum of %d post fields. Remove an unused field to add a new one.', 'post-runtime-engine' ),
							(int) $hard
						);
					} else {
						printf(
							/* translators: %1$d: current count, %2$d: soft warning threshold */
							esc_html__( 'This CPT has %1$d post fields. Cards display best with %2$d or fewer; beyond that the meta strip may wrap or truncate on smaller viewports.', 'post-runtime-engine' ),
							(int) $count,
							(int) $soft
						);
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $fields ) ) : ?>
			<div class="pre-empty-state">
				<p><?php esc_html_e( 'No post fields defined for this CPT yet.', 'post-runtime-engine' ); ?></p>
				<p>
					<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Define your first post field', 'post-runtime-engine' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $this->url() ); ?>" class="pre-post-fields-reorder">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REORDER ); ?>">
				<?php wp_nonce_field( self::ACTION_REORDER, 'pre_nonce' ); ?>

				<table class="wp-list-table widefat fixed striped pre-post-fields-table">
					<thead>
						<tr>
							<th class="pre-col-handle" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'post-runtime-engine' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Key', 'post-runtime-engine' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Label', 'post-runtime-engine' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Display type', 'post-runtime-engine' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Card position', 'post-runtime-engine' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hero position', 'post-runtime-engine' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'post-runtime-engine' ); ?></th>
						</tr>
					</thead>
					<tbody class="pre-post-fields-sortable">
						<?php foreach ( $fields as $key => $def ) : ?>
							<tr data-field-key="<?php echo esc_attr( $key ); ?>">
								<td class="pre-col-handle" aria-hidden="true">
									<span class="pre-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder', 'post-runtime-engine' ); ?>"></span>
									<input type="hidden" name="ordered_keys[]" value="<?php echo esc_attr( $key ); ?>">
								</td>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td><?php echo esc_html( $def['label'] ?? '' ); ?></td>
								<td><?php echo esc_html( $def['display_type'] ?? '' ); ?></td>
								<td><?php echo esc_html( $this->position_label( $def['card_position'] ?? 'hidden' ) ); ?></td>
								<td><?php echo esc_html( $this->position_label( $def['single_position'] ?? 'hidden' ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $this->url( array( 'action' => 'edit', 'field' => $key ) ) ); ?>">
										<?php esc_html_e( 'Edit', 'post-runtime-engine' ); ?>
									</a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $this->delete_url( $key ) ); ?>"
										class="pre-delete-link"
										onclick="return confirm('<?php echo esc_js( __( 'Remove this post field definition? Per-post values already saved on existing posts are preserved in the database — the field just won\'t render until you redefine it.', 'post-runtime-engine' ) ); ?>');">
										<?php esc_html_e( 'Remove', 'post-runtime-engine' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="pre-reorder-hint description">
					<?php esc_html_e( 'Drag rows to reorder. Click "Save order" when you\'re done. Order determines render order within each position (especially important for meta_strip).', 'post-runtime-engine' ); ?>
				</p>

				<?php submit_button( __( 'Save order', 'post-runtime-engine' ), 'secondary', 'save_order', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	// -----------------------------------------------------------------------
	// Form view
	// -----------------------------------------------------------------------

	/**
	 * Render the new / edit form.
	 *
	 * @param string $mode      'new' or 'edit'.
	 * @param array  $cpt       CPT definition.
	 * @param string $field_key Field key when editing.
	 */
	private function render_form( $mode, array $cpt, $field_key = '' ) {
		$plugin = pre();

		if ( $this->form_values !== null ) {
			// Repopulate from a failed POST.
			$values = $this->form_values;
		} elseif ( $mode === 'edit' ) {
			$existing = $plugin->post_fields ? $plugin->post_fields->get( $this->cpt_slug, $field_key ) : null;
			if ( ! $existing ) {
				echo '<h1>' . esc_html__( 'Edit Post Field', 'post-runtime-engine' ) . '</h1>';
				echo '<p>' . esc_html__( 'Post field not found.', 'post-runtime-engine' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( $this->url() ) . '">' . esc_html__( '← Back to Post Fields', 'post-runtime-engine' ) . '</a></p>';
				return;
			}
			$values = $this->definition_to_form_values( $existing );
		} else {
			$values = $this->default_form_values();
		}

		$is_edit       = ( $mode === 'edit' );
		$display_types = PRE_Validator::DISPLAY_TYPES;
		$positions     = PRE_Validator::FIELD_POSITIONS;
		$color_intents = PRE_Validator::COLOR_INTENTS;
		$date_formats  = PRE_Validator::DATE_FORMATS;

		?>
		<h1>
			<?php
			echo $is_edit
				? esc_html__( 'Edit Post Field', 'post-runtime-engine' )
				: esc_html__( 'Add Post Field', 'post-runtime-engine' );
			?>
		</h1>

		<p class="description">
			<?php
			/* translators: %s: CPT plural label */
			printf( esc_html__( 'For posts of type "%s".', 'post-runtime-engine' ), esc_html( $cpt['label_plural'] ?? $this->cpt_slug ) );
			?>
		</p>

		<?php if ( $this->form_error instanceof WP_Error ) : ?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $this->form_error->get_error_message() ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $this->url() ); ?>" class="pre-post-field-form" data-display-type="<?php echo esc_attr( $values['display_type'] ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="original_key" value="<?php echo esc_attr( $field_key ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( self::ACTION_SAVE, 'pre_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="pre-field-key"><?php esc_html_e( 'Key', 'post-runtime-engine' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input
								type="text"
								id="pre-field-key"
								name="key"
								class="regular-text"
								value="<?php echo esc_attr( $values['key'] ); ?>"
								<?php echo $is_edit ? 'readonly' : ''; ?>
								required>
							<p class="description">
								<?php esc_html_e( 'Lowercase letters, numbers, and underscores. Used as the meta key (_pre_field_{key}). Cannot be changed after creation.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pre-field-label"><?php esc_html_e( 'Label', 'post-runtime-engine' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input
								type="text"
								id="pre-field-label"
								name="label"
								class="regular-text"
								value="<?php echo esc_attr( $values['label'] ); ?>"
								required>
							<p class="description">
								<?php esc_html_e( 'Human-readable label, shown in the admin meta box.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pre-field-display-type"><?php esc_html_e( 'Display type', 'post-runtime-engine' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<select id="pre-field-display-type" name="display_type" class="pre-field-display-type-select">
								<?php foreach ( $display_types as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $values['display_type'], $type ); ?>>
										<?php echo esc_html( $this->display_type_label( $type ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Determines the visual treatment. Some attributes below only apply to specific types.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pre-field-card-position"><?php esc_html_e( 'Card position', 'post-runtime-engine' ); ?></label>
						</th>
						<td>
							<select id="pre-field-card-position" name="card_position">
								<?php foreach ( $positions as $pos ) : ?>
									<option value="<?php echo esc_attr( $pos ); ?>" <?php selected( $values['card_position'], $pos ); ?>>
										<?php echo esc_html( $this->position_label( $pos ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Where this field renders inside a card (PostGrid sections, archive pages, related-posts widgets).', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pre-field-single-position"><?php esc_html_e( 'Single-post hero position', 'post-runtime-engine' ); ?></label>
						</th>
						<td>
							<select id="pre-field-single-position" name="single_position">
								<?php foreach ( $positions as $pos ) : ?>
									<option value="<?php echo esc_attr( $pos ); ?>" <?php selected( $values['single_position'], $pos ); ?>>
										<?php echo esc_html( $this->position_label( $pos ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Where this field renders inside the single-post hero, alongside the post title and featured image.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="pre-field-description"><?php esc_html_e( 'Description', 'post-runtime-engine' ); ?></label>
						</th>
						<td>
							<textarea
								id="pre-field-description"
								name="description"
								class="large-text"
								rows="2"
								maxlength="<?php echo (int) PRE_Validator::MAX_FIELD_DESCRIPTION_LEN; ?>"><?php echo esc_textarea( $values['description'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional admin help text. Shown above the field in the post-edit meta box.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>

					<tr class="pre-field-cond pre-field-cond-required">
						<th scope="row"><?php esc_html_e( 'Required', 'post-runtime-engine' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="required" value="1" <?php checked( ! empty( $values['required'] ) ); ?>>
								<?php esc_html_e( 'Mark this field as required in the post-edit meta box.', 'post-runtime-engine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Affects admin UX only — the validator does not reject post saves with empty required fields.', 'post-runtime-engine' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php // Conditional sections — visibility toggled by post-fields-editor.js based on the selected display type. ?>

			<!-- Conditional: badge / multi_badge — color intent + options map -->
			<div class="pre-field-cond pre-field-cond-badge" data-shown-when="badge,multi_badge">
				<h2><?php esc_html_e( 'Badge attributes', 'post-runtime-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pre-field-color-intent"><?php esc_html_e( 'Default color intent', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<select id="pre-field-color-intent" name="color_intent">
									<?php foreach ( $color_intents as $intent ) : ?>
										<option value="<?php echo esc_attr( $intent ); ?>" <?php selected( $values['color_intent'], $intent ); ?>>
											<?php echo esc_html( ucfirst( $intent ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Maps to the --aisb-color-{intent} token. Individual option values can override this in the Options map below.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="pre-field-options-json"><?php esc_html_e( 'Options (JSON)', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<textarea
									id="pre-field-options-json"
									name="options_json"
									class="large-text code"
									rows="6"
									placeholder='{ "open": { "label": "Open now", "color_intent": "success" }, "closed": { "label": "Closed", "color_intent": "danger" } }'><?php echo esc_textarea( $values['options_json'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Optional. A JSON object mapping option keys to { label, color_intent? }. When defined, the post-edit meta box renders a select dropdown limited to these options. For multi_badge, lookups against this map per segment.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Conditional: meta_pair — icon picker -->
			<div class="pre-field-cond pre-field-cond-icon" data-shown-when="meta_pair">
				<h2><?php esc_html_e( 'Meta pair attributes', 'post-runtime-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pre-field-icon"><?php esc_html_e( 'Icon', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<select id="pre-field-icon" name="icon">
									<option value=""><?php esc_html_e( '— No icon —', 'post-runtime-engine' ); ?></option>
									<?php
									if ( class_exists( 'PRE_Icon_Library' ) ) {
										$grouped = PRE_Icon_Library::get_grouped_by_category();
										foreach ( $grouped as $category => $icons_in_category ) {
											echo '<optgroup label="' . esc_attr( $category ) . '">';
											foreach ( $icons_in_category as $icon_key => $icon ) {
												printf(
													'<option value="%s" %s>%s</option>',
													esc_attr( $icon_key ),
													selected( $values['icon'], $icon_key, false ),
													esc_html( $icon['label'] )
												);
											}
											echo '</optgroup>';
										}
									}
									?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Curated icon shown to the left of the value (e.g. 🛏 3 BR). Reuses the existing 53-icon library; extensible via the pre_icon_library filter.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Conditional: date — format mode -->
			<div class="pre-field-cond pre-field-cond-date" data-shown-when="date">
				<h2><?php esc_html_e( 'Date format', 'post-runtime-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pre-field-date-format"><?php esc_html_e( 'Format mode', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<select id="pre-field-date-format" name="date_format">
									<?php foreach ( $date_formats as $fmt ) : ?>
										<option value="<?php echo esc_attr( $fmt ); ?>" <?php selected( $values['date_format'], $fmt ); ?>>
											<?php echo esc_html( $this->date_format_label( $fmt ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="pre-field-date-format-string"><?php esc_html_e( 'Custom format string', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="pre-field-date-format-string"
									name="date_format_string"
									class="regular-text"
									value="<?php echo esc_attr( $values['date_format_string'] ); ?>"
									placeholder="F j, Y">
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to date format docs */
										esc_html__( 'PHP date format. See %s for the syntax. Only used when Format mode is "Custom".', 'post-runtime-engine' ),
										'<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener noreferrer">php.net</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Conditional: currency — currency code -->
			<div class="pre-field-cond pre-field-cond-currency" data-shown-when="currency,progress">
				<h2><?php esc_html_e( 'Currency', 'post-runtime-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pre-field-currency-code"><?php esc_html_e( 'Currency code', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<select id="pre-field-currency-code" name="currency_code">
									<option value=""><?php esc_html_e( '— Use site default —', 'post-runtime-engine' ); ?></option>
									<?php foreach ( PRE_Validator::SUPPORTED_CURRENCIES as $code ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $values['currency_code'], $code ); ?>>
											<?php echo esc_html( $code ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'ISO 4217 code. Leave empty to inherit from AISB Business Identity (if active) or the pre_currency option fallback.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="pre-field-value-suffix"><?php esc_html_e( 'Value suffix', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="pre-field-value-suffix"
									name="value_suffix"
									class="regular-text"
									value="<?php echo esc_attr( $values['value_suffix'] ?? '' ); ?>"
									placeholder="+"
									maxlength="<?php echo (int) PRE_Validator::MAX_VALUE_SUFFIX_LEN; ?>">
								<p class="description">
									<?php esc_html_e( 'Optional text appended after the formatted value. Examples: "+" for "starting at" pricing ($2,328+), "/mo" for subscriptions ($45/mo), "/night" for hotels ($120/night). Leave empty for plain currency formatting.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Conditional: rating / progress — max + unit label -->
			<div class="pre-field-cond pre-field-cond-rating" data-shown-when="rating,progress,number_with_label">
				<h2><?php esc_html_e( 'Numeric attributes', 'post-runtime-engine' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pre-field-max"><?php esc_html_e( 'Maximum', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="pre-field-max"
									name="max"
									class="small-text"
									value="<?php echo esc_attr( (string) ( $values['max'] ?? '' ) ); ?>"
									step="0.1">
								<p class="description">
									<?php esc_html_e( 'For rating: the top of the scale (default 5). For progress: the goal value when no per-post goal is set.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="pre-field-unit-label"><?php esc_html_e( 'Unit label', 'post-runtime-engine' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="pre-field-unit-label"
									name="unit_label"
									class="regular-text"
									value="<?php echo esc_attr( $values['unit_label'] ?? '' ); ?>"
									placeholder="sqft">
								<p class="description">
									<?php esc_html_e( 'For number_with_label: the unit suffix (e.g., "sqft", "BR", "miles"). For progress: optional suffix appended to the percentage.', 'post-runtime-engine' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php submit_button( $is_edit ? __( 'Update field', 'post-runtime-engine' ) : __( 'Create field', 'post-runtime-engine' ) ); ?>
			<a href="<?php echo esc_url( $this->url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'post-runtime-engine' ); ?></a>
		</form>
		<?php
	}

	// -----------------------------------------------------------------------
	// Action handlers
	// -----------------------------------------------------------------------

	/**
	 * Handle the save (new or edit) POST.
	 */
	private function handle_save() {
		check_admin_referer( self::ACTION_SAVE, 'pre_nonce' );

		$plugin = pre();
		if ( ! $plugin->post_fields ) {
			$this->queue_notice( 'error', __( 'Internal error: post field registry not available.', 'post-runtime-engine' ) );
			$this->redirect( $this->url() );
		}

		$values = $this->collect_form_values();

		// Build the definition payload from form values.
		$definition = $this->form_values_to_definition( $values );

		if ( is_wp_error( $definition ) ) {
			// Most likely a JSON parse error in options_json. Surface inline.
			$this->form_values = $values;
			$this->form_error  = $definition;
			return;
		}

		$result = $plugin->post_fields->define( $this->cpt_slug, $definition );

		if ( is_wp_error( $result ) ) {
			$this->form_values = $values;
			$this->form_error  = $result;
			return;
		}

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %s: field key */
				__( 'Post field "%s" saved.', 'post-runtime-engine' ),
				$values['key']
			)
		);

		$this->redirect( $this->url() );
	}

	/**
	 * Handle the reorder POST.
	 */
	private function handle_reorder() {
		check_admin_referer( self::ACTION_REORDER, 'pre_nonce' );

		$plugin = pre();
		if ( ! $plugin->post_fields ) {
			$this->queue_notice( 'error', __( 'Internal error: post field registry not available.', 'post-runtime-engine' ) );
			$this->redirect( $this->url() );
		}

		$ordered = isset( $_POST['ordered_keys'] ) && is_array( $_POST['ordered_keys'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['ordered_keys'] ) )
			: array();

		if ( empty( $ordered ) ) {
			$this->queue_notice( 'error', __( 'No field order received.', 'post-runtime-engine' ) );
			$this->redirect( $this->url() );
		}

		$result = $plugin->post_fields->reorder( $this->cpt_slug, $ordered );

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( 'error', $result->get_error_message() );
		} else {
			$this->queue_notice( 'success', __( 'Post field order updated.', 'post-runtime-engine' ) );
		}

		$this->redirect( $this->url() );
	}

	/**
	 * Handle the delete GET.
	 */
	private function handle_delete() {
		$field_key = sanitize_key( wp_unslash( $_GET['field'] ) );

		check_admin_referer( self::ACTION_DELETE . '_' . $field_key );

		$plugin = pre();
		if ( ! $plugin->post_fields ) {
			$this->queue_notice( 'error', __( 'Internal error: post field registry not available.', 'post-runtime-engine' ) );
			$this->redirect( $this->url() );
		}

		$result = $plugin->post_fields->remove( $this->cpt_slug, $field_key );

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( 'error', $result->get_error_message() );
		} else {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %s: field key */
					__( 'Post field "%s" removed.', 'post-runtime-engine' ),
					$field_key
				)
			);
		}

		$this->redirect( $this->url() );
	}

	// -----------------------------------------------------------------------
	// Form helpers
	// -----------------------------------------------------------------------

	/**
	 * Collect POSTed form values, conservatively sanitized. Real validation
	 * runs in PRE_Validator on save.
	 *
	 * @return array
	 */
	private function collect_form_values() {
		return array(
			'key'                => isset( $_POST['key'] ) ? trim( wp_unslash( $_POST['key'] ) ) : '',
			'label'              => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'description'        => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'display_type'       => isset( $_POST['display_type'] ) ? sanitize_key( wp_unslash( $_POST['display_type'] ) ) : 'text',
			'card_position'      => isset( $_POST['card_position'] ) ? sanitize_key( wp_unslash( $_POST['card_position'] ) ) : 'meta_strip',
			'single_position'    => isset( $_POST['single_position'] ) ? sanitize_key( wp_unslash( $_POST['single_position'] ) ) : 'meta_strip',
			'color_intent'       => isset( $_POST['color_intent'] ) ? sanitize_key( wp_unslash( $_POST['color_intent'] ) ) : 'neutral',
			'icon'               => isset( $_POST['icon'] ) ? sanitize_text_field( wp_unslash( $_POST['icon'] ) ) : '',
			'options_json'       => isset( $_POST['options_json'] ) ? trim( wp_unslash( $_POST['options_json'] ) ) : '',
			'date_format'        => isset( $_POST['date_format'] ) ? sanitize_key( wp_unslash( $_POST['date_format'] ) ) : 'absolute',
			'date_format_string' => isset( $_POST['date_format_string'] ) ? sanitize_text_field( wp_unslash( $_POST['date_format_string'] ) ) : '',
			'currency_code'      => isset( $_POST['currency_code'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['currency_code'] ) ) ) : '',
			'value_suffix'       => isset( $_POST['value_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['value_suffix'] ) ) : '',
			'max'                => isset( $_POST['max'] ) && $_POST['max'] !== '' ? (float) $_POST['max'] : 0,
			'unit_label'         => isset( $_POST['unit_label'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_label'] ) ) : '',
			'required'           => ! empty( $_POST['required'] ),
		);
	}

	/**
	 * Convert form-row values to a registry-shape definition. Parses the
	 * options_json string into an array.
	 *
	 * @param array $values Sanitized form values.
	 * @return array|WP_Error Definition or error on bad JSON.
	 */
	private function form_values_to_definition( array $values ) {
		$definition = array(
			'key'                => $values['key'],
			'label'              => $values['label'],
			'description'        => $values['description'],
			'display_type'       => $values['display_type'],
			'card_position'      => $values['card_position'],
			'single_position'    => $values['single_position'],
			'color_intent'       => $values['color_intent'],
			'icon'               => $values['icon'],
			'date_format'        => $values['date_format'],
			'date_format_string' => $values['date_format_string'],
			'currency_code'      => $values['currency_code'],
			'value_suffix'       => $values['value_suffix'] ?? '',
			'max'                => $values['max'],
			'unit_label'         => $values['unit_label'],
			'required'           => (bool) $values['required'],
			'options'            => array(),
		);

		if ( $values['options_json'] !== '' ) {
			$decoded = json_decode( $values['options_json'], true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				return new WP_Error(
					'pre_invalid_options_json',
					__( 'Options JSON is invalid. Expected an object like { "key": { "label": "Label", "color_intent": "success" } }.', 'post-runtime-engine' )
				);
			}
			$definition['options'] = $decoded;
		}

		return $definition;
	}

	/**
	 * Convert a stored definition back into form values for editing.
	 *
	 * @param array $definition Stored field definition.
	 * @return array
	 */
	private function definition_to_form_values( array $definition ) {
		$values = $this->default_form_values();

		foreach ( array( 'key', 'label', 'description', 'display_type', 'card_position', 'single_position', 'color_intent', 'icon', 'date_format', 'date_format_string', 'currency_code', 'value_suffix', 'unit_label' ) as $key ) {
			if ( isset( $definition[ $key ] ) ) {
				$values[ $key ] = $definition[ $key ];
			}
		}

		$values['required'] = ! empty( $definition['required'] );
		$values['max']      = isset( $definition['max'] ) ? (float) $definition['max'] : 0;
		$values['options_json'] = ! empty( $definition['options'] ) && is_array( $definition['options'] )
			? wp_json_encode( $definition['options'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: '';

		return $values;
	}

	/**
	 * Default values for a fresh definition.
	 *
	 * @return array
	 */
	private function default_form_values() {
		return array(
			'key'                => '',
			'label'              => '',
			'description'        => '',
			'display_type'       => 'text',
			'card_position'      => 'meta_strip',
			'single_position'    => 'meta_strip',
			'color_intent'       => 'neutral',
			'icon'               => '',
			'options_json'       => '',
			'date_format'        => 'absolute',
			'date_format_string' => '',
			'currency_code'      => '',
			'value_suffix'       => '',
			'max'                => 0,
			'unit_label'         => '',
			'required'           => false,
		);
	}

	/**
	 * Human label for a display-type enum value.
	 *
	 * @param string $type Display type.
	 * @return string
	 */
	private function display_type_label( $type ) {
		$labels = array(
			'currency'          => __( 'Currency ($1,250,000)', 'post-runtime-engine' ),
			'number_with_label' => __( 'Number with label (1,800 sqft)', 'post-runtime-engine' ),
			'badge'             => __( 'Badge (For sale)', 'post-runtime-engine' ),
			'meta_pair'         => __( 'Meta pair (🛏 3)', 'post-runtime-engine' ),
			'date'              => __( 'Date (May 20, 2026)', 'post-runtime-engine' ),
			'text'              => __( 'Text (plain string)', 'post-runtime-engine' ),
			'rating'            => __( 'Rating (★★★★☆ 4.8)', 'post-runtime-engine' ),
			'progress'          => __( 'Progress bar (65%)', 'post-runtime-engine' ),
			'multi_badge'       => __( 'Multi-badge (Vegan, GF, Quick)', 'post-runtime-engine' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Human label for a position enum value.
	 *
	 * @param string $position Position key.
	 * @return string
	 */
	private function position_label( $position ) {
		$labels = array(
			'image_overlay' => __( 'Image overlay (badge on image)', 'post-runtime-engine' ),
			'headline'      => __( 'Headline (prominent, above title)', 'post-runtime-engine' ),
			'subtitle'      => __( 'Subtitle (under title)', 'post-runtime-engine' ),
			'meta_strip'    => __( 'Meta strip (inline list)', 'post-runtime-engine' ),
			'footer_meta'   => __( 'Footer meta (bottom row)', 'post-runtime-engine' ),
			'hidden'        => __( 'Hidden in this context', 'post-runtime-engine' ),
		);
		return isset( $labels[ $position ] ) ? $labels[ $position ] : $position;
	}

	/**
	 * Human label for a date-format enum value.
	 *
	 * @param string $format Format key.
	 * @return string
	 */
	private function date_format_label( $format ) {
		$labels = array(
			'absolute' => __( 'Absolute (May 20, 2026)', 'post-runtime-engine' ),
			'relative' => __( 'Relative (2 days ago)', 'post-runtime-engine' ),
			'custom'   => __( 'Custom (use format string below)', 'post-runtime-engine' ),
		);
		return isset( $labels[ $format ] ) ? $labels[ $format ] : $format;
	}

	// -----------------------------------------------------------------------
	// Notices + URL helpers
	// -----------------------------------------------------------------------

	/**
	 * Render any queued notice (one-shot transient). Called by PRE_Admin
	 * before the page render.
	 */
	public function render_notice() {
		$notice = get_transient( self::NOTICE_KEY . '_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( self::NOTICE_KEY . '_' . get_current_user_id() );

		$type    = $notice['type'] ?? 'info';
		$message = $notice['message'] ?? '';
		$class   = $type === 'success' ? 'notice-success' : ( $type === 'error' ? 'notice-error' : 'notice-info' );

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Queue a notice for the next page load (POST-redirect-GET).
	 *
	 * @param string $type    'success' / 'error' / 'info'.
	 * @param string $message Message body.
	 */
	private function queue_notice( $type, $message ) {
		set_transient(
			self::NOTICE_KEY . '_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}

	/**
	 * Build a URL for this page with optional extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function url( $args = array() ) {
		$base = add_query_arg(
			array(
				'page' => PRE_Admin::PAGE_POST_FIELDS,
				'cpt'  => $this->cpt_slug,
			),
			admin_url( 'admin.php' )
		);
		if ( ! empty( $args ) ) {
			$base = add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * Build a nonce-protected delete URL.
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	private function delete_url( $field_key ) {
		return wp_nonce_url(
			$this->url(
				array(
					'action' => 'delete-post-field',
					'field'  => $field_key,
				)
			),
			self::ACTION_DELETE . '_' . $field_key
		);
	}

	/**
	 * Redirect helper that exits on success.
	 *
	 * @param string $url Target URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
