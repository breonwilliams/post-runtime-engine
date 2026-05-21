<?php
/**
 * Post-edit-screen meta box for post field values (v1.1).
 *
 * Companion to PRE_Meta_Box (which handles the v1.0 grouping items).
 * This meta box renders one input per registered post field on the CPT,
 * picking the right input shape per display type (currency = numeric with
 * symbol prefix, date = date picker, badge with options = select, etc.).
 * Also surfaces the per-field visibility toggles (Hide on card / Hide on
 * single-page hero).
 *
 * Save handler validates and writes via PRE_Post_Data::set_field_values
 * and set_field_visibility — both already enforce per-display-type rules
 * via PRE_Validator.
 *
 * @package PostRuntimeEngine
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Post Fields meta box on managed-CPT post-edit screens.
 */
class PRE_Meta_Box_Post_Fields {

	const META_BOX_ID  = 'pre-post-fields';
	const NONCE_NAME   = 'pre_post_fields_nonce';
	const NONCE_ACTION = 'pre_save_post_fields';

	/**
	 * Constructor. Wires WordPress hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 11, 2 );
	}

	/**
	 * Register the meta box for every CPT managed by this plugin that
	 * has at least one post field defined. Skipping CPTs without fields
	 * means no empty box clutter on posts that don't need it.
	 */
	public function register() {
		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->post_fields ) {
			return;
		}

		foreach ( $plugin->cpts->get_all() as $slug => $cpt ) {
			$fields = $plugin->post_fields->get_all( $slug );
			if ( empty( $fields ) ) {
				continue;
			}

			add_meta_box(
				self::META_BOX_ID,
				__( 'Post Fields', 'post-runtime-engine' ),
				array( $this, 'render' ),
				$slug,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the meta box for a post.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ) {
		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			echo '<p>' . esc_html__( 'This post type is not managed by Post Runtime Engine.', 'post-runtime-engine' ) . '</p>';
			return;
		}

		$fields = $plugin->post_fields ? $plugin->post_fields->get_all( $post->post_type ) : array();
		if ( empty( $fields ) ) {
			$url = add_query_arg(
				array( 'page' => PRE_Admin::PAGE_POST_FIELDS, 'cpt' => $post->post_type ),
				admin_url( 'admin.php' )
			);
			?>
			<div class="pre-meta-empty">
				<p><?php esc_html_e( 'No post fields are defined for this post type yet.', 'post-runtime-engine' ); ?></p>
				<p>
					<a href="<?php echo esc_url( $url ); ?>" class="button">
						<?php esc_html_e( 'Define post fields →', 'post-runtime-engine' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$values     = $plugin->post_data ? $plugin->post_data->get_field_values( $post->ID ) : array();
		$visibility = $plugin->post_data ? $plugin->post_data->get_field_visibility( $post->ID ) : array();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		?>
		<div class="pre-meta-box pre-meta-box--post-fields">
			<p class="description">
				<?php esc_html_e( 'These values render on this post\'s single page (in the hero) and on any cards listing posts of this type. Leave a field empty to skip it in both contexts.', 'post-runtime-engine' ); ?>
			</p>

			<?php foreach ( $fields as $key => $def ) : ?>
				<?php $this->render_field_row( $key, $def, $values[ $key ] ?? null, $visibility[ $key ] ?? array() ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single field row in the meta box.
	 *
	 * @param string $key        Field key.
	 * @param array  $def        Field definition.
	 * @param mixed  $value      Stored value (scalar or composite array).
	 * @param array  $visibility Visibility flags for this field.
	 */
	private function render_field_row( $key, array $def, $value, array $visibility ) {
		$display_type = $def['display_type'] ?? 'text';
		$label        = $def['label'] ?? $key;
		$description  = $def['description'] ?? '';
		$required     = ! empty( $def['required'] );
		$base_id      = 'pre-field-' . sanitize_html_class( $key );

		$card_hidden   = ! empty( $visibility['card_hidden'] );
		$single_hidden = ! empty( $visibility['single_hidden'] );

		?>
		<div class="pre-field-row" data-display-type="<?php echo esc_attr( $display_type ); ?>" data-field-key="<?php echo esc_attr( $key ); ?>">
			<div class="pre-field-row__label">
				<label for="<?php echo esc_attr( $base_id ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $required ) : ?>
						<span class="pre-field-row__required" aria-label="<?php esc_attr_e( 'Required', 'post-runtime-engine' ); ?>">*</span>
					<?php endif; ?>
				</label>
				<span class="pre-field-row__type-badge"><?php echo esc_html( $display_type ); ?></span>
			</div>

			<div class="pre-field-row__input">
				<?php $this->render_field_input( $base_id, $key, $def, $value ); ?>
			</div>

			<?php if ( $description !== '' ) : ?>
				<p class="pre-field-row__description description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<div class="pre-field-row__visibility">
				<label class="pre-field-row__visibility-toggle">
					<input
						type="checkbox"
						name="pre_field_visibility[<?php echo esc_attr( $key ); ?>][card_hidden]"
						value="1"
						<?php checked( $card_hidden ); ?>>
					<?php esc_html_e( 'Hide on cards', 'post-runtime-engine' ); ?>
				</label>
				<label class="pre-field-row__visibility-toggle">
					<input
						type="checkbox"
						name="pre_field_visibility[<?php echo esc_attr( $key ); ?>][single_hidden]"
						value="1"
						<?php checked( $single_hidden ); ?>>
					<?php esc_html_e( 'Hide in single-page hero', 'post-runtime-engine' ); ?>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the input control for a single field, choosing the input
	 * shape based on display type.
	 *
	 * @param string $base_id The HTML id for the primary input.
	 * @param string $key     Field key.
	 * @param array  $def     Field definition.
	 * @param mixed  $value   Stored value.
	 */
	private function render_field_input( $base_id, $key, array $def, $value ) {
		$display_type = $def['display_type'] ?? 'text';
		$name_primary = 'pre_field_values[' . $key . ']';

		// For composite types (rating, progress), the value is an array.
		// Extract primary + secondary for input population.
		$primary   = is_array( $value ) ? ( $value['value'] ?? '' ) : ( $value ?? '' );
		$secondary = null;
		if ( $display_type === 'rating' && is_array( $value ) ) {
			$secondary = $value['count'] ?? '';
		}
		if ( $display_type === 'progress' && is_array( $value ) ) {
			$secondary = $value['goal'] ?? '';
		}

		switch ( $display_type ) {
			case 'currency':
				$symbol = $this->guess_currency_symbol( $def );
				?>
				<div class="pre-input-currency">
					<span class="pre-input-currency__symbol"><?php echo esc_html( $symbol ); ?></span>
					<input
						type="number"
						step="0.01"
						id="<?php echo esc_attr( $base_id ); ?>"
						name="<?php echo esc_attr( $name_primary ); ?>"
						value="<?php echo esc_attr( (string) $primary ); ?>"
						placeholder="1250000">
				</div>
				<?php
				break;

			case 'number_with_label':
				$unit = $def['unit_label'] ?? $def['label'] ?? '';
				?>
				<div class="pre-input-number">
					<input
						type="number"
						step="0.01"
						id="<?php echo esc_attr( $base_id ); ?>"
						name="<?php echo esc_attr( $name_primary ); ?>"
						value="<?php echo esc_attr( (string) $primary ); ?>">
					<?php if ( $unit !== '' ) : ?>
						<span class="pre-input-number__unit"><?php echo esc_html( $unit ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				break;

			case 'badge':
				$options = is_array( $def['options'] ?? null ) ? $def['options'] : array();
				if ( ! empty( $options ) ) {
					?>
					<select
						id="<?php echo esc_attr( $base_id ); ?>"
						name="<?php echo esc_attr( $name_primary ); ?>">
						<option value=""><?php esc_html_e( '— Not set —', 'post-runtime-engine' ); ?></option>
						<?php foreach ( $options as $opt_key => $opt ) : ?>
							<option value="<?php echo esc_attr( $opt_key ); ?>" <?php selected( $primary, $opt_key ); ?>>
								<?php echo esc_html( $opt['label'] ?? $opt_key ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
				} else {
					?>
					<input
						type="text"
						id="<?php echo esc_attr( $base_id ); ?>"
						name="<?php echo esc_attr( $name_primary ); ?>"
						value="<?php echo esc_attr( (string) $primary ); ?>"
						class="regular-text"
						maxlength="100">
					<?php
				}
				break;

			case 'date':
				$date_value = '';
				if ( $primary !== '' ) {
					$ts = is_numeric( $primary ) ? (int) $primary : strtotime( (string) $primary );
					if ( $ts !== false ) {
						$date_value = gmdate( 'Y-m-d', $ts );
					}
				}
				?>
				<input
					type="date"
					id="<?php echo esc_attr( $base_id ); ?>"
					name="<?php echo esc_attr( $name_primary ); ?>"
					value="<?php echo esc_attr( $date_value ); ?>">
				<?php
				break;

			case 'meta_pair':
				?>
				<input
					type="text"
					id="<?php echo esc_attr( $base_id ); ?>"
					name="<?php echo esc_attr( $name_primary ); ?>"
					value="<?php echo esc_attr( (string) $primary ); ?>"
					class="regular-text">
				<?php
				break;

			case 'rating':
				$max = isset( $def['max'] ) && (float) $def['max'] > 0 ? (float) $def['max'] : 5;
				?>
				<div class="pre-input-rating">
					<label class="pre-input-rating__label">
						<?php esc_html_e( 'Value', 'post-runtime-engine' ); ?>
						<input
							type="number"
							step="0.1"
							min="0"
							max="<?php echo esc_attr( (string) $max ); ?>"
							id="<?php echo esc_attr( $base_id ); ?>"
							name="<?php echo esc_attr( $name_primary ); ?>[value]"
							value="<?php echo esc_attr( (string) $primary ); ?>">
						<span class="pre-input-rating__max">
							<?php
							/* translators: %s: max rating */
							printf( esc_html__( '/ %s', 'post-runtime-engine' ), esc_html( (string) $max ) );
							?>
						</span>
					</label>
					<label class="pre-input-rating__label">
						<?php esc_html_e( 'Review count', 'post-runtime-engine' ); ?>
						<input
							type="number"
							step="1"
							min="0"
							name="<?php echo esc_attr( $name_primary ); ?>[count]"
							value="<?php echo esc_attr( (string) ( $secondary ?? '' ) ); ?>"
							placeholder="0">
					</label>
				</div>
				<?php
				break;

			case 'progress':
				$max = isset( $def['max'] ) && (float) $def['max'] > 0 ? (float) $def['max'] : 100;
				?>
				<div class="pre-input-progress">
					<label class="pre-input-progress__label">
						<?php esc_html_e( 'Current', 'post-runtime-engine' ); ?>
						<input
							type="number"
							step="0.01"
							min="0"
							id="<?php echo esc_attr( $base_id ); ?>"
							name="<?php echo esc_attr( $name_primary ); ?>[value]"
							value="<?php echo esc_attr( (string) $primary ); ?>">
					</label>
					<label class="pre-input-progress__label">
						<?php esc_html_e( 'Goal', 'post-runtime-engine' ); ?>
						<input
							type="number"
							step="0.01"
							min="0"
							name="<?php echo esc_attr( $name_primary ); ?>[goal]"
							value="<?php echo esc_attr( (string) ( $secondary ?? '' ) ); ?>"
							placeholder="<?php echo esc_attr( (string) $max ); ?>">
					</label>
				</div>
				<?php
				break;

			case 'multi_badge':
				$as_string = is_array( $primary ) ? implode( ', ', $primary ) : (string) $primary;
				?>
				<input
					type="text"
					id="<?php echo esc_attr( $base_id ); ?>"
					name="<?php echo esc_attr( $name_primary ); ?>"
					value="<?php echo esc_attr( $as_string ); ?>"
					class="regular-text"
					placeholder="Vegan, GF, Quick">
				<p class="description">
					<?php esc_html_e( 'Comma-separated list. Each segment renders as its own pill.', 'post-runtime-engine' ); ?>
				</p>
				<?php
				break;

			case 'text':
			default:
				?>
				<input
					type="text"
					id="<?php echo esc_attr( $base_id ); ?>"
					name="<?php echo esc_attr( $name_primary ); ?>"
					value="<?php echo esc_attr( (string) $primary ); ?>"
					class="regular-text"
					maxlength="<?php echo (int) PRE_Validator::MAX_TEXT_VALUE_LEN; ?>">
				<?php
				break;
		}
	}

	/**
	 * Best-effort currency-symbol guess for the meta-box input prefix.
	 * Mirrors PRE_Card_Renderer's resolution chain (field → AISB →
	 * pre_currency → USD) and looks up a symbol per the same map. Kept
	 * separate from the renderer so the meta box doesn't depend on
	 * frontend classes.
	 *
	 * @param array $def Field definition.
	 * @return string
	 */
	private function guess_currency_symbol( array $def ) {
		$code = '';
		if ( ! empty( $def['currency_code'] ) ) {
			$code = strtoupper( $def['currency_code'] );
		} else {
			$aisb = get_option( 'aisb_business_settings', array() );
			if ( is_array( $aisb ) && ! empty( $aisb['currency'] ) ) {
				$code = strtoupper( $aisb['currency'] );
			} else {
				$code = strtoupper( (string) get_option( 'pre_currency', 'USD' ) );
				if ( $code === '' ) {
					$code = 'USD';
				}
			}
		}

		$symbols = array(
			'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥',
			'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'KRW' => '₩', 'INR' => '₹',
			'BRL' => 'R$', 'MXN' => 'MX$', 'ZAR' => 'R', 'TRY' => '₺', 'RUB' => '₽',
			'ILS' => '₪', 'THB' => '฿', 'PHP' => '₱',
		);
		return $symbols[ $code ] ?? $code;
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Post ID.
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

		// Confirm CPT is managed.
		$plugin = pre();
		if ( ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Nonce — gated on this meta box's specific nonce so we don't
		// process when only the groupings meta box was submitted.
		if (
			! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION )
		) {
			return;
		}

		// Capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Field values.
		$raw_values = isset( $_POST['pre_field_values'] ) && is_array( $_POST['pre_field_values'] )
			? wp_unslash( $_POST['pre_field_values'] )
			: array();

		// Normalize composite-type shapes so set_field_values gets the
		// array form it expects.
		$normalized = array();
		$field_defs = $plugin->post_fields ? $plugin->post_fields->get_all( $post->post_type ) : array();

		foreach ( $field_defs as $key => $def ) {
			if ( ! array_key_exists( $key, $raw_values ) ) {
				continue;
			}
			$raw = $raw_values[ $key ];
			$display_type = $def['display_type'] ?? 'text';

			if ( in_array( $display_type, array( 'rating', 'progress' ), true ) ) {
				if ( ! is_array( $raw ) ) {
					$normalized[ $key ] = $raw;
					continue;
				}
				$primary = $raw['value'] ?? '';
				$secondary_key = ( $display_type === 'rating' ) ? 'count' : 'goal';
				$secondary = $raw[ $secondary_key ] ?? '';
				$normalized[ $key ] = array(
					'value'           => $primary,
					$secondary_key    => $secondary,
				);
			} else {
				$normalized[ $key ] = $raw;
			}
		}

		if ( $plugin->post_data ) {
			$result = $plugin->post_data->set_field_values( $post_id, $normalized, 'admin' );
			if ( is_wp_error( $result ) ) {
				// Don't fatal — let the post save proceed; just log so the
				// admin can investigate. The values that DID validate will
				// have been written by the time the first error fired.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'PRE: post field save error on post ' . (int) $post_id . ': ' . $result->get_error_message() );
				}
			}
		}

		// Visibility overrides.
		$raw_visibility = isset( $_POST['pre_field_visibility'] ) && is_array( $_POST['pre_field_visibility'] )
			? wp_unslash( $_POST['pre_field_visibility'] )
			: array();

		// Normalize checkbox shape (browsers omit unchecked boxes from the
		// payload; convert any present truthy value to true).
		$visibility = array();
		foreach ( $raw_visibility as $field_key => $flags ) {
			if ( ! is_array( $flags ) ) {
				continue;
			}
			$entry = array();
			if ( ! empty( $flags['card_hidden'] ) ) {
				$entry['card_hidden'] = true;
			}
			if ( ! empty( $flags['single_hidden'] ) ) {
				$entry['single_hidden'] = true;
			}
			if ( ! empty( $entry ) ) {
				$visibility[ sanitize_key( $field_key ) ] = $entry;
			}
		}

		if ( $plugin->post_data ) {
			$plugin->post_data->set_field_visibility( $post_id, $visibility, 'admin' );
		}
	}
}
