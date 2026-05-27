<?php
/**
 * CPT management admin page.
 *
 * Handles list view, new/edit form, and delete action for custom post
 * types registered through Promptless CPT Pages. Uses standard WordPress
 * admin patterns (admin notices, nonces, the form-table CSS, submit_button
 * helper). All persistence goes through PRE_CPT_Registry; this class only
 * deals with the UI layer.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
 *
 * Justification for the file-level disable above:
 *
 * This admin-page class follows the standard WordPress dispatcher-handler
 * pattern. The render() and handle_action() methods read $_GET / $_POST to
 * decide which handler to dispatch to (handle_save, handle_delete, etc.).
 * The actual nonce verification happens inside each handler method via
 * check_admin_referer(). Plugin Check's static analyzer cannot trace
 * verification across method boundaries, which produces many false-positive
 * NonceVerification and ValidatedSanitizedInput warnings on the dispatcher
 * accesses. File-level disable applied here with this documentation; search
 * the file for check_admin_referer / wp_verify_nonce to see the actual
 * verification points. All sanitization is applied at the handler boundary
 * via sanitize_key / sanitize_text_field / sanitize_textarea_field / etc.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CPT management page renderer.
 */
class PRE_Admin_CPTs {

	/**
	 * Action name for the save form.
	 */
	const ACTION_SAVE   = 'pre_save_cpt';
	const ACTION_DELETE = 'pre_delete_cpt';

	/**
	 * Notice queued for display on the next render. Stored in transient
	 * keyed on user ID so it survives the POST-redirect-GET cycle.
	 *
	 * Shape: array( 'type' => 'success'|'error', 'message' => string ).
	 *
	 * @var array|null
	 */
	private $notice = null;

	/**
	 * Form values pre-filled from a failed POST. Lets the user fix
	 * validation errors without retyping everything.
	 *
	 * @var array|null
	 */
	private $form_values = null;

	/**
	 * Validation error from a failed save. Rendered above the form.
	 *
	 * @var WP_Error|null
	 */
	private $form_error = null;

	/**
	 * Handle form submission and delete actions. Called from PRE_Admin
	 * before any output.
	 */
	public function handle_action() {
		if ( ! PRE_Capabilities::current_user_can_manage() ) {
			return;
		}

		// POST: save (new or edit).
		if ( isset( $_POST['action'] ) && $_POST['action'] === self::ACTION_SAVE ) {
			$this->handle_save();
			return;
		}

		// GET: delete (with nonce).
		if (
			isset( $_GET['action'], $_GET['cpt'], $_GET['_wpnonce'] )
			&& sanitize_key( wp_unslash( $_GET['action'] ) ) === 'delete'
		) {
			$this->handle_delete();
			return;
		}

		// Pull a notice queued from a previous request.
		$user_id = get_current_user_id();
		$key     = 'pre_admin_notice_' . $user_id;
		$notice  = get_transient( $key );
		if ( $notice ) {
			$this->notice = $notice;
			delete_transient( $key );
		}
	}

	/**
	 * Render the page. Dispatches to list, new, or edit view based on the
	 * `action` query arg.
	 */
	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		echo '<div class="wrap pre-admin">';

		$this->render_notice();

		if ( $action === 'new' ) {
			$this->render_form( 'new' );
		} elseif ( $action === 'edit' && ! empty( $_GET['cpt'] ) ) {
			$cpt_slug = sanitize_key( wp_unslash( $_GET['cpt'] ) );
			$this->render_form( 'edit', $cpt_slug );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// List view
	// -----------------------------------------------------------------------

	/**
	 * Render the CPT list view.
	 */
	private function render_list() {
		$plugin = pre();
		$cpts   = $plugin->cpts ? $plugin->cpts->get_all() : array();

		?>
		<h1 class="wp-heading-inline">
			<?php esc_html_e( 'Post Types', 'promptless-cpt-pages' ); ?>
		</h1>
		<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add New', 'promptless-cpt-pages' ); ?>
		</a>
		<hr class="wp-header-end">

		<p class="description">
			<?php esc_html_e( 'Custom post types registered by Promptless CPT Pages. Each CPT can have its own grouping definitions and per-post structured data.', 'promptless-cpt-pages' ); ?>
		</p>

		<?php if ( empty( $cpts ) ) : ?>
			<div class="pre-empty-state">
				<p>
					<?php esc_html_e( 'No post types registered yet.', 'promptless-cpt-pages' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->url( array( 'action' => 'new' ) ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Register your first post type', 'promptless-cpt-pages' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped pre-cpts-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Slug', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Singular', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Plural', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Hierarchical', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Public', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Version', 'promptless-cpt-pages' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'promptless-cpt-pages' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cpts as $slug => $def ) : ?>
						<tr>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo esc_html( $def['label_singular'] ?? '' ); ?></td>
							<td><?php echo esc_html( $def['label_plural'] ?? '' ); ?></td>
							<td><?php echo ! empty( $def['hierarchical'] ) ? esc_html__( 'Yes', 'promptless-cpt-pages' ) : esc_html__( 'No', 'promptless-cpt-pages' ); ?></td>
							<td><?php echo ! empty( $def['public'] ) ? esc_html__( 'Yes', 'promptless-cpt-pages' ) : esc_html__( 'No', 'promptless-cpt-pages' ); ?></td>
							<td><?php echo esc_html( (string) ( $def['connector_version'] ?? 1 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->url( array( 'action' => 'edit', 'cpt' => $slug ) ) ); ?>">
									<?php esc_html_e( 'Edit', 'promptless-cpt-pages' ); ?>
								</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pre-groupings&cpt=' . $slug ) ); ?>">
									<?php esc_html_e( 'Groupings', 'promptless-cpt-pages' ); ?>
								</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pre-post-fields&cpt=' . $slug ) ); ?>">
									<?php esc_html_e( 'Post Fields', 'promptless-cpt-pages' ); ?>
								</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( $this->delete_url( $slug ) ); ?>"
									class="pre-delete-link"
									onclick="return confirm('<?php echo esc_js( __( 'Remove this post type registration? Existing posts of this type will remain in the database, but the type will no longer be queryable.', 'promptless-cpt-pages' ) ); ?>');">
									<?php esc_html_e( 'Remove', 'promptless-cpt-pages' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;

		// Surface a registration-failure notice if any CPTs failed to register
		// with WordPress (e.g., due to slug collision after a third-party
		// plugin started using the same slug). The action is fired by the
		// CPT registry on init; we capture it here for display.
		$registration_failures = $this->collect_registration_failures();
		if ( ! empty( $registration_failures ) ) :
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Some post types failed to register with WordPress this request:', 'promptless-cpt-pages' ); ?></strong>
				</p>
				<ul style="list-style: disc inside;">
					<?php foreach ( $registration_failures as $slug => $message ) : ?>
						<li><code><?php echo esc_html( $slug ); ?></code>: <?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		endif;
	}

	/**
	 * Collect any registration failures captured by the registry on init.
	 * Stored in a request-scoped static so we display them once per page load.
	 *
	 * @return array<string,string>
	 */
	private function collect_registration_failures() {
		static $failures = null;
		if ( $failures !== null ) {
			return $failures;
		}

		$failures = array();
		add_action(
			'pre_cpt_registration_failed',
			function ( $slug, $error ) use ( &$failures ) {
				$failures[ $slug ] = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
			},
			10,
			2
		);

		return $failures;
	}

	// -----------------------------------------------------------------------
	// Form view
	// -----------------------------------------------------------------------

	/**
	 * Render the new/edit form.
	 *
	 * @param string $mode 'new' or 'edit'.
	 * @param string $cpt_slug Existing CPT slug (edit mode only).
	 */
	private function render_form( $mode, $cpt_slug = '' ) {
		$plugin     = pre();
		$is_edit    = ( $mode === 'edit' );
		$existing   = null;

		if ( $is_edit ) {
			$existing = $plugin->cpts ? $plugin->cpts->get( $cpt_slug ) : null;
			if ( ! $existing ) {
				echo '<h1>' . esc_html__( 'Edit Post Type', 'promptless-cpt-pages' ) . '</h1>';
				echo '<p>' . esc_html__( 'That post type does not exist.', 'promptless-cpt-pages' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( $this->url() ) . '">' . esc_html__( '← Back to Post Types', 'promptless-cpt-pages' ) . '</a></p>';
				return;
			}
		}

		// Form values: prefer pre-filled values from a failed POST, then
		// fall back to existing definition (edit), then to sensible defaults
		// (new).
		$values = $this->form_values
			?: ( $is_edit ? $this->definition_to_form_values( $existing ) : $this->default_form_values() );

		?>
		<h1><?php
			echo esc_html( $is_edit
				? sprintf(
					/* translators: %s: post type slug */
					__( 'Edit Post Type: %s', 'promptless-cpt-pages' ),
					$cpt_slug
				)
				: __( 'Add New Post Type', 'promptless-cpt-pages' )
			);
		?></h1>

		<?php if ( $this->form_error instanceof WP_Error ) : ?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $this->form_error->get_error_message() ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// Form action URL preserves both `page` and `action` (and the cpt
		// slug, in edit mode). On a successful POST we redirect to the list
		// view; on a failed POST (validator error) we re-render the form
		// inline. The latter only works if $_GET['action'] is preserved
		// across the POST round-trip — hence the explicit query string.
		$form_action_url = $is_edit
			? add_query_arg(
				array( 'page' => PRE_Admin::PAGE_CPTS, 'action' => 'edit', 'cpt' => $cpt_slug ),
				admin_url( 'admin.php' )
			)
			: add_query_arg(
				array( 'page' => PRE_Admin::PAGE_CPTS, 'action' => 'new' ),
				admin_url( 'admin.php' )
			);
		?>
		<form method="post"
			action="<?php echo esc_url( $form_action_url ); ?>"
			class="pre-cpt-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="original_slug" value="<?php echo esc_attr( $cpt_slug ); ?>">
			<?php endif; ?>
			<?php wp_nonce_field( self::ACTION_SAVE, 'pre_nonce' ); ?>

			<h2 class="title"><?php esc_html_e( 'Basic info', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pre_slug"><?php esc_html_e( 'Slug', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_slug"
							name="slug"
							class="regular-text code"
							value="<?php echo esc_attr( $values['slug'] ); ?>"
							<?php echo $is_edit ? 'readonly' : ''; ?>
							required
							maxlength="20">
						<p class="description">
							<?php esc_html_e( 'Lowercase letters, numbers, and underscores only. Maximum 20 characters. This cannot be changed after creation.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_label_singular"><?php esc_html_e( 'Singular label', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_label_singular"
							name="label_singular"
							class="regular-text"
							value="<?php echo esc_attr( $values['label_singular'] ); ?>"
							required
							maxlength="200">
						<p class="description">
							<?php esc_html_e( 'Singular name shown in admin (e.g., "Listing").', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_label_plural"><?php esc_html_e( 'Plural label', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_label_plural"
							name="label_plural"
							class="regular-text"
							value="<?php echo esc_attr( $values['label_plural'] ); ?>"
							required
							maxlength="200">
						<p class="description">
							<?php esc_html_e( 'Plural name shown in admin (e.g., "Listings").', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_description"><?php esc_html_e( 'Description', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<textarea
							id="pre_description"
							name="description"
							class="large-text"
							rows="2"><?php echo esc_textarea( $values['description'] ); ?></textarea>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Behavior', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Visibility', 'promptless-cpt-pages' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="public" value="1" <?php checked( $values['public'], true ); ?>>
							<?php esc_html_e( 'Public', 'promptless-cpt-pages' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When checked, posts of this type appear in front-end queries and have public URLs.', 'promptless-cpt-pages' ); ?>
						</p>
						<br>
						<label>
							<input type="checkbox" name="has_archive" value="1" <?php checked( $values['has_archive'], true ); ?>>
							<?php esc_html_e( 'Has archive page', 'promptless-cpt-pages' ); ?>
						</label>
						<br>
						<label>
							<input type="checkbox" name="hierarchical" value="1" <?php checked( $values['hierarchical'], true ); ?>>
							<?php esc_html_e( 'Hierarchical (supports parent/child relationships)', 'promptless-cpt-pages' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Required for the child_posts grouping source mode.', 'promptless-cpt-pages' ); ?>
						</p>
						<br>
						<label>
							<input type="checkbox" name="show_in_rest" value="1" <?php checked( $values['show_in_rest'], true ); ?>>
							<?php esc_html_e( 'Available in REST API and block editor', 'promptless-cpt-pages' ); ?>
						</label>
						<br>
						<label>
							<input type="checkbox" name="show_in_menu" value="1" <?php checked( $values['show_in_menu'], true ); ?>>
							<?php esc_html_e( 'Show in admin menu', 'promptless-cpt-pages' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_menu_position"><?php esc_html_e( 'Menu position', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="pre_menu_position"
							name="menu_position"
							class="small-text"
							value="<?php echo esc_attr( (string) $values['menu_position'] ); ?>"
							min="1"
							max="100">
						<p class="description">
							<?php esc_html_e( 'Where in the admin menu to place this CPT. WordPress defaults: 5 = Posts, 20 = Pages, 25 = Comments. Higher numbers go further down.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_menu_icon"><?php esc_html_e( 'Menu icon', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_menu_icon"
							name="menu_icon"
							class="regular-text code"
							value="<?php echo esc_attr( $values['menu_icon'] ); ?>">
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: link to the dashicons reference */
									__( 'A %s name (e.g., "dashicons-admin-home"), an attachment URL, or a base64-encoded SVG.', 'promptless-cpt-pages' ),
									'<a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">Dashicon</a>'
								),
								array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Editor features', 'promptless-cpt-pages' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Supports', 'promptless-cpt-pages' ); ?></th>
					<td>
						<?php
						$supports_options = array(
							'title'           => __( 'Title', 'promptless-cpt-pages' ),
							'editor'          => __( 'Editor (main content)', 'promptless-cpt-pages' ),
							'thumbnail'       => __( 'Featured image', 'promptless-cpt-pages' ),
							'excerpt'         => __( 'Excerpt', 'promptless-cpt-pages' ),
							'author'          => __( 'Author', 'promptless-cpt-pages' ),
							'comments'        => __( 'Comments', 'promptless-cpt-pages' ),
							'revisions'       => __( 'Revisions', 'promptless-cpt-pages' ),
							'page-attributes' => __( 'Page attributes (parent, order)', 'promptless-cpt-pages' ),
							'custom-fields'   => __( 'Custom fields', 'promptless-cpt-pages' ),
						);
						foreach ( $supports_options as $key => $label ) {
							$checked = in_array( $key, $values['supports'], true );
							?>
							<label style="display: block; margin-bottom: 4px;">
								<input type="checkbox" name="supports[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
							<?php
						}
						?>
						<p class="description">
							<?php esc_html_e( 'Most CPTs need at minimum: title, editor, thumbnail, excerpt.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_taxonomies"><?php esc_html_e( 'Taxonomies', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_taxonomies"
							name="taxonomies"
							class="regular-text code"
							value="<?php echo esc_attr( implode( ', ', $values['taxonomies'] ) ); ?>">
						<p class="description">
							<?php esc_html_e( 'Comma-separated list of taxonomy slugs to attach to this post type. Taxonomies must be registered separately (custom taxonomy registration is a v1.1 feature).', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_capability_type"><?php esc_html_e( 'Capability type', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="pre_capability_type"
							name="capability_type"
							class="regular-text code"
							value="<?php echo esc_attr( $values['capability_type'] ); ?>">
						<p class="description">
							<?php esc_html_e( 'Defaults to "post" — posts of this type use the standard edit_posts / publish_posts / delete_posts capabilities. Change only if you understand WordPress capability mapping.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Hero', 'promptless-cpt-pages' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Controls the layout of the post title, excerpt, and featured image at the top of every single page of this CPT. Pick the shape that matches the content: split for profile-style content (real estate, attorney bios, team pages), stacked for editorial content (events, courses, articles).', 'promptless-cpt-pages' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pre_hero_layout"><?php esc_html_e( 'Hero layout', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pre_hero_layout" name="hero_layout">
							<option value="stacked" <?php selected( $values['hero_layout'], 'stacked' ); ?>>
								<?php esc_html_e( 'Stacked — featured image as a banner above the title (16:9)', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="split" <?php selected( $values['hero_layout'], 'split' ); ?>>
								<?php esc_html_e( 'Split — featured image side-by-side with title + excerpt (1:1)', 'promptless-cpt-pages' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'When the post has no featured image, both layouts collapse to a clean text-only hero — no empty image slot.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_hero_image_position"><?php esc_html_e( 'Image position', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pre_hero_image_position" name="hero_image_position">
							<option value="left" <?php selected( $values['hero_image_position'], 'left' ); ?>>
								<?php esc_html_e( 'Left', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="right" <?php selected( $values['hero_image_position'], 'right' ); ?>>
								<?php esc_html_e( 'Right', 'promptless-cpt-pages' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only applies when Hero layout is set to Split. Stacked layouts always place the image above the text.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pre_hero_image_aspect"><?php esc_html_e( 'Image aspect ratio', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pre_hero_image_aspect" name="hero_image_aspect">
							<option value="square" <?php selected( $values['hero_image_aspect'], 'square' ); ?>>
								<?php esc_html_e( 'Square (1:1) — headshots, profiles, team pages', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="landscape" <?php selected( $values['hero_image_aspect'], 'landscape' ); ?>>
								<?php esc_html_e( 'Landscape (4:3) — property photos, product shots', 'promptless-cpt-pages' ); ?>
							</option>
							<option value="wide" <?php selected( $values['hero_image_aspect'], 'wide' ); ?>>
								<?php esc_html_e( 'Wide (16:9) — cinematic banner imagery', 'promptless-cpt-pages' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only applies when Hero layout is set to Split. Pick the shape that matches the natural aspect of your photos so they crop cleanly. Stacked layouts always use a 16:9 banner.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Grouping defaults', 'promptless-cpt-pages' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Defaults that apply across every grouping rendered for this CPT. Useful when the same visual treatment fits all related-content groupings (sidebars, footers).', 'promptless-cpt-pages' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pre_default_icon"><?php esc_html_e( 'Default icon', 'promptless-cpt-pages' ); ?></label>
					</th>
					<td>
						<select id="pre_default_icon" name="default_icon">
							<option value="" <?php selected( $values['default_icon'], '' ); ?>>
								<?php esc_html_e( '— None —', 'promptless-cpt-pages' ); ?>
							</option>
							<?php foreach ( PRE_Icon_Library::get_grouped_by_category() as $category => $icons_in_category ) : ?>
								<optgroup label="<?php echo esc_attr( $category ); ?>">
									<?php foreach ( $icons_in_category as $icon_id => $icon ) : ?>
										<option value="<?php echo esc_attr( $icon_id ); ?>" <?php selected( $values['default_icon'], $icon_id ); ?>>
											<?php echo esc_html( $icon['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Used as a fallback visual cue when an item has no icon and no image — for example, when an auto-resolved related-listings sidebar pulls in items from posts that don\'t have a per-post icon set. Compact-grid and horizontal-row variants are icon-only by design and will use this when no per-item icon is set. Leave blank to render those items without media.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Archive card meta', 'promptless-cpt-pages' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Control which theme-rendered meta items appear under each card on the post-type archive page. Affects only the theme archive — the AISB PostGrid section has its own per-section toggles.', 'promptless-cpt-pages' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post date', 'promptless-cpt-pages' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="archive_show_post_date" value="1" <?php checked( ! empty( $values['archive_show_post_date'] ) ); ?>>
							<?php esc_html_e( 'Show the post create-date on archive cards', 'promptless-cpt-pages' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Turn off when this CPT already exposes a meaningful date via a post-field (e.g. an event CPT whose event_date is the date that matters). Showing both the post create-date AND a custom event date on the same card is duplicative.', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post author', 'promptless-cpt-pages' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="archive_show_post_author" value="1" <?php checked( ! empty( $values['archive_show_post_author'] ) ); ?>>
							<?php esc_html_e( 'Show the post author byline on archive cards', 'promptless-cpt-pages' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Turn off for CPTs where the author identity is irrelevant or noisy (directories, multi-author publications where every post is by the same admin user).', 'promptless-cpt-pages' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( $is_edit ? __( 'Save changes', 'promptless-cpt-pages' ) : __( 'Register post type', 'promptless-cpt-pages' ) ); ?>

			<p>
				<a class="button" href="<?php echo esc_url( $this->url() ); ?>">
					<?php esc_html_e( '← Cancel', 'promptless-cpt-pages' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	// -----------------------------------------------------------------------
	// Action handlers
	// -----------------------------------------------------------------------

	/**
	 * Handle a save (new or edit) submission.
	 */
	private function handle_save() {
		check_admin_referer( self::ACTION_SAVE, 'pre_nonce' );

		$values = $this->collect_form_values();

		$plugin = pre();
		if ( ! $plugin->cpts ) {
			$this->queue_notice( 'error', __( 'Internal error: CPT registry not available.', 'promptless-cpt-pages' ) );
			$this->redirect( $this->url() );
		}

		$result = $plugin->cpts->register( $values['slug'], $values );

		if ( is_wp_error( $result ) ) {
			// Stash values + error so render_form() can display them.
			$this->form_values = $values;
			$this->form_error  = $result;
			return;
		}

		$this->queue_notice(
			'success',
			sprintf(
				/* translators: %s: CPT slug */
				__( 'Post type "%s" saved.', 'promptless-cpt-pages' ),
				$values['slug']
			)
		);

		$this->redirect( $this->url() );
	}

	/**
	 * Handle a delete (unregister) action.
	 */
	private function handle_delete() {
		$cpt_slug = sanitize_key( wp_unslash( $_GET['cpt'] ) );

		check_admin_referer( self::ACTION_DELETE . '_' . $cpt_slug );

		$plugin = pre();
		if ( ! $plugin->cpts ) {
			$this->queue_notice( 'error', __( 'Internal error: CPT registry not available.', 'promptless-cpt-pages' ) );
			$this->redirect( $this->url() );
		}

		// Remove all groupings defined for this CPT before unregistering it.
		// Keeps the option table clean even though the post meta on existing
		// posts is intentionally preserved.
		if ( $plugin->groupings ) {
			$plugin->groupings->remove_all_for_cpt( $cpt_slug );
		}

		$result = $plugin->cpts->unregister( $cpt_slug );

		if ( is_wp_error( $result ) ) {
			$this->queue_notice( 'error', $result->get_error_message() );
		} else {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %s: CPT slug */
					__( 'Post type "%s" removed. Existing posts of this type are preserved in the database.', 'promptless-cpt-pages' ),
					$cpt_slug
				)
			);
		}

		$this->redirect( $this->url() );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Collect, sanitize, and shape the POSTed form values into a definition
	 * array suitable for PRE_CPT_Registry::register().
	 *
	 * Sanitization here is conservative — actual rejection of malformed
	 * input happens in the validator. Everything that passes through here
	 * has been escaped from text-shape attacks but may still fail validator
	 * checks (length, reserved slug, etc.).
	 *
	 * @return array
	 */
	private function collect_form_values() {
		$slug = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';

		$supports = array();
		if ( isset( $_POST['supports'] ) && is_array( $_POST['supports'] ) ) {
			foreach ( wp_unslash( $_POST['supports'] ) as $s ) {
				if ( is_string( $s ) ) {
					$supports[] = sanitize_key( $s );
				}
			}
		}

		$taxonomies = array();
		if ( isset( $_POST['taxonomies'] ) ) {
			$raw = wp_unslash( $_POST['taxonomies'] );
			if ( is_string( $raw ) && $raw !== '' ) {
				foreach ( preg_split( '/[\s,]+/', $raw ) as $tax ) {
					$tax = sanitize_key( $tax );
					if ( $tax !== '' ) {
						$taxonomies[] = $tax;
					}
				}
			}
		}

		// Hero fields — sanitize_key normalizes case + strips invalid chars
		// before the validator does its enum check. Unknown values fail the
		// validator with a typed error rather than corrupting storage.
		$hero_layout = isset( $_POST['hero_layout'] )
			? sanitize_key( wp_unslash( $_POST['hero_layout'] ) )
			: 'stacked';
		$hero_image_position = isset( $_POST['hero_image_position'] )
			? sanitize_key( wp_unslash( $_POST['hero_image_position'] ) )
			: 'left';
		$hero_image_aspect = isset( $_POST['hero_image_aspect'] )
			? sanitize_key( wp_unslash( $_POST['hero_image_aspect'] ) )
			: 'square';
		// default_icon is validated against PRE_Icon_Library by the validator
		// — sanitize_key strips invalid chars (icon IDs are snake_case) before
		// the lookup so malformed input fails with a typed error.
		$default_icon = isset( $_POST['default_icon'] )
			? sanitize_key( wp_unslash( $_POST['default_icon'] ) )
			: '';

		return array(
			'slug'                => is_string( $slug ) ? trim( $slug ) : '',
			'label_singular'      => isset( $_POST['label_singular'] ) ? sanitize_text_field( wp_unslash( $_POST['label_singular'] ) ) : '',
			'label_plural'        => isset( $_POST['label_plural'] ) ? sanitize_text_field( wp_unslash( $_POST['label_plural'] ) ) : '',
			'description'         => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'public'              => ! empty( $_POST['public'] ),
			'has_archive'         => ! empty( $_POST['has_archive'] ),
			'hierarchical'        => ! empty( $_POST['hierarchical'] ),
			'show_in_rest'        => ! empty( $_POST['show_in_rest'] ),
			'show_in_menu'        => ! empty( $_POST['show_in_menu'] ),
			'menu_position'       => isset( $_POST['menu_position'] ) ? max( 1, min( 100, (int) $_POST['menu_position'] ) ) : 25,
			'menu_icon'           => isset( $_POST['menu_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_icon'] ) ) : 'dashicons-admin-post',
			'supports'            => $supports,
			'taxonomies'          => $taxonomies,
			'capability_type'     => isset( $_POST['capability_type'] ) ? sanitize_key( wp_unslash( $_POST['capability_type'] ) ) : 'post',
			'hero_layout'         => $hero_layout,
			'hero_image_position' => $hero_image_position,
			'hero_image_aspect'   => $hero_image_aspect,
			'default_icon'        => $default_icon,
			// Archive card meta toggles. Checkboxes are unchecked when the
			// post param is missing, so the default behavior of `!empty()`
			// (which would treat missing as false) is intentional here —
			// once the form is rendered with these inputs, the user's
			// explicit choice always wins.
			'archive_show_post_date'   => ! empty( $_POST['archive_show_post_date'] ),
			'archive_show_post_author' => ! empty( $_POST['archive_show_post_author'] ),
		);
	}

	/**
	 * Render whatever notice is queued for this request.
	 */
	private function render_notice() {
		if ( ! is_array( $this->notice ) ) {
			return;
		}
		$type    = isset( $this->notice['type'] ) ? $this->notice['type'] : 'info';
		$message = isset( $this->notice['message'] ) ? $this->notice['message'] : '';
		$class   = $type === 'success' ? 'notice-success' : ( $type === 'error' ? 'notice-error' : 'notice-info' );
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Queue a notice for the next request (POST-redirect-GET cycle).
	 *
	 * @param string $type    'success' | 'error' | 'info'.
	 * @param string $message Plain-text message.
	 */
	private function queue_notice( $type, $message ) {
		$user_id = get_current_user_id();
		set_transient(
			'pre_admin_notice_' . $user_id,
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}

	/**
	 * Build a URL to the CPT page with optional query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => PRE_Admin::PAGE_CPTS ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a delete URL for a given CPT slug, with nonce.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return string
	 */
	private function delete_url( $cpt_slug ) {
		return wp_nonce_url(
			$this->url( array( 'action' => 'delete', 'cpt' => $cpt_slug ) ),
			self::ACTION_DELETE . '_' . $cpt_slug
		);
	}

	/**
	 * Redirect to a URL and exit. Wraps wp_safe_redirect for testability.
	 *
	 * @param string $url Target URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Convert a stored CPT definition into form values for the edit form.
	 *
	 * @param array $definition Definition from PRE_CPT_Registry::get().
	 * @return array
	 */
	private function definition_to_form_values( array $definition ) {
		return array(
			'slug'                => $definition['slug'] ?? '',
			'label_singular'      => $definition['label_singular'] ?? '',
			'label_plural'        => $definition['label_plural'] ?? '',
			'description'         => $definition['description'] ?? '',
			'public'              => ! empty( $definition['public'] ),
			'has_archive'         => ! empty( $definition['has_archive'] ),
			'hierarchical'        => ! empty( $definition['hierarchical'] ),
			'show_in_rest'        => ! empty( $definition['show_in_rest'] ),
			'show_in_menu'        => ! empty( $definition['show_in_menu'] ),
			'menu_position'       => (int) ( $definition['menu_position'] ?? 25 ),
			'menu_icon'           => $definition['menu_icon'] ?? 'dashicons-admin-post',
			'supports'            => is_array( $definition['supports'] ?? null ) ? $definition['supports'] : array(),
			'taxonomies'          => is_array( $definition['taxonomies'] ?? null ) ? $definition['taxonomies'] : array(),
			'capability_type'     => $definition['capability_type'] ?? 'post',
			'hero_layout'         => $definition['hero_layout'] ?? 'stacked',
			'hero_image_position' => $definition['hero_image_position'] ?? 'left',
			'hero_image_aspect'   => $definition['hero_image_aspect'] ?? 'square',
			'default_icon'        => $definition['default_icon'] ?? '',
			// Archive card meta toggles. Defaults to true (backward compatible
			// — existing CPTs without these keys behave exactly as before).
			'archive_show_post_date'   => array_key_exists( 'archive_show_post_date', $definition )
				? (bool) $definition['archive_show_post_date']
				: true,
			'archive_show_post_author' => array_key_exists( 'archive_show_post_author', $definition )
				? (bool) $definition['archive_show_post_author']
				: true,
		);
	}

	/**
	 * Default form values for a new CPT.
	 *
	 * @return array
	 */
	private function default_form_values() {
		return array(
			'slug'                => '',
			'label_singular'      => '',
			'label_plural'        => '',
			'description'         => '',
			'public'              => true,
			'has_archive'         => true,
			'hierarchical'        => false,
			'show_in_rest'        => true,
			'show_in_menu'        => true,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-admin-post',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'taxonomies'          => array(),
			'capability_type'     => 'post',
			'hero_layout'         => 'stacked',
			'hero_image_position' => 'left',
			'hero_image_aspect'   => 'square',
			'default_icon'        => '',
			'archive_show_post_date'   => true,
			'archive_show_post_author' => true,
		);
	}
}
