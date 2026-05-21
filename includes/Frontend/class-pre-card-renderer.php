<?php
/**
 * Post field renderer for Post Runtime Engine (v1.1).
 *
 * Single entry point `render( $post_id, $context )` where $context is
 * `card` or `single_hero`. Consumed by:
 *   - PRE_Renderer (single-post template, $context='single_hero')
 *   - Promptless WP's PostGrid section via aisb_postgrid_card_content
 *     filter (Phase 12, $context='card')
 *   - Promptless theme's archive card template via
 *     promptless_archive_card_content filter (Phase 12, $context='card')
 *   - Any third-party caller that wants design-coherent card field rendering
 *
 * The renderer does NOT render the post title, featured image, or excerpt
 * — those are the caller's responsibility. PRE_Card_Renderer is a content
 * augmenter that emits HTML for the five field positions (image_overlay,
 * headline, subtitle, meta_strip, footer_meta). The caller wraps that
 * output in its own card / hero markup.
 *
 * Design contract: docs/POST_FIELDS_V1_1_DESIGN.md § 6.
 *
 * @package PostRuntimeEngine
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders post field values for a post in either card or single-hero context.
 */
class PRE_Card_Renderer {

	/**
	 * Ordered list of positions for rendering. Output renders positions in
	 * this order; multiple fields in the same position render in their
	 * field-definition order (set by `define()` and `reorder()` on
	 * PRE_Post_Field_Registry).
	 */
	const POSITION_RENDER_ORDER = array(
		'image_overlay',
		'headline',
		'subtitle',
		'meta_strip',
		'footer_meta',
	);

	/**
	 * Stars used for rating display. Filled and outline glyphs combine to
	 * produce N out of M visual ratings.
	 */
	const STAR_FILLED = '★';
	const STAR_EMPTY  = '☆';

	/**
	 * Post field registry. Lazy-resolved if null.
	 *
	 * @var PRE_Post_Field_Registry|null
	 */
	private $post_fields;

	/**
	 * Post data accessor. Lazy-resolved if null.
	 *
	 * @var PRE_Post_Data|null
	 */
	private $post_data;

	/**
	 * Constructor.
	 *
	 * @param PRE_Post_Field_Registry|null $post_fields Optional injection.
	 * @param PRE_Post_Data|null           $post_data   Optional injection.
	 */
	public function __construct( $post_fields = null, $post_data = null ) {
		$this->post_fields = $post_fields;
		$this->post_data   = $post_data;
	}

	/**
	 * Render all visible post field values for a post in the given context.
	 *
	 * Output structure (when $context = 'card'):
	 *
	 * <div class="pre-card-fields pre-card-fields--card">
	 *   <div class="pre-card-fields__position pre-card-fields__position--image-overlay">
	 *     <span class="pre-field pre-field--badge pre-field--badge-success">For sale</span>
	 *   </div>
	 *   <div class="pre-card-fields__position pre-card-fields__position--headline">
	 *     <span class="pre-field pre-field--currency">$1,250,000</span>
	 *   </div>
	 *   ...
	 * </div>
	 *
	 * Position containers are only emitted when they have at least one
	 * visible field — empty positions produce zero output rather than
	 * empty wrappers, so the caller's CSS doesn't have to handle them.
	 *
	 * Returns empty string when the post's CPT has no post fields
	 * registered, or when no fields have values, or when all fields are
	 * hidden via per-post visibility overrides.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $context 'card' or 'single_hero'.
	 * @return string HTML output (already escaped).
	 */
	public function render( $post_id, $context = 'card' ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return '';
		}

		$context = in_array( $context, array( 'card', 'single_hero' ), true ) ? $context : 'card';

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$registry   = $this->get_post_fields();
		$field_defs = $registry->get_all( $post->post_type );
		if ( empty( $field_defs ) ) {
			return '';
		}

		$buckets = $this->bucket_fields_by_position( $post_id, $post->post_type, $context, $field_defs );
		if ( empty( $buckets ) ) {
			return '';
		}

		$wrapper_modifier = ( $context === 'card' ) ? 'card' : 'single-hero';
		$html             = sprintf(
			'<div class="pre-card-fields pre-card-fields--%s">',
			esc_attr( $wrapper_modifier )
		);

		foreach ( self::POSITION_RENDER_ORDER as $position ) {
			if ( empty( $buckets[ $position ] ) ) {
				continue;
			}
			$html .= $this->render_position( $position, $buckets[ $position ], $context, $post_id );
		}

		$html .= '</div>';

		/**
		 * Filter the complete rendered card-fields HTML for a post.
		 *
		 * Last-chance hook for themes / extensions that need to alter
		 * the final output. Most consumers should use the per-field /
		 * per-display-type filter points instead; this is the catch-all.
		 *
		 * @param string $html    Rendered HTML.
		 * @param int    $post_id Post ID.
		 * @param string $context 'card' or 'single_hero'.
		 */
		return apply_filters( 'pre_card_fields_html', $html, $post_id, $context );
	}

	/**
	 * Render the markup for ONE position only. Used by surfaces that
	 * inject post fields at semantically-meaningful points in their own
	 * card markup (AISB PostGrid via aisb_postgrid_card_section action,
	 * Promptless theme archive via promptless_archive_card_section action,
	 * PRE's own single-post hero via the extract helpers in PRE_Renderer).
	 *
	 * Returns empty string when:
	 *   - The post's CPT has no post fields registered
	 *   - The position has no visible fields for this post
	 *   - The position string doesn't match the closed enum
	 *
	 * This is the building block surrogate for the whole-wrapper render()
	 * — callers that need per-position output use this; callers that need
	 * the whole wrapper use render().
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $position One of POSITION_RENDER_ORDER.
	 * @param string $context  'card' or 'single_hero'.
	 * @return string HTML for the position's container + fields, or empty string.
	 */
	public function render_position_html( $post_id, $position, $context = 'card' ) {
		$post_id  = absint( $post_id );
		$position = sanitize_key( $position );
		$context  = in_array( $context, array( 'card', 'single_hero' ), true ) ? $context : 'card';

		if ( $post_id === 0 || ! in_array( $position, self::POSITION_RENDER_ORDER, true ) ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$registry   = $this->get_post_fields();
		$field_defs = $registry->get_all( $post->post_type );
		if ( empty( $field_defs ) ) {
			return '';
		}

		$buckets = $this->bucket_fields_by_position( $post_id, $post->post_type, $context, $field_defs );
		if ( empty( $buckets[ $position ] ) ) {
			return '';
		}

		return $this->render_position( $position, $buckets[ $position ], $context, $post_id );
	}

	/**
	 * Bucket visible fields by position, in definition order.
	 *
	 * Resolves visibility via PRE_Post_Data::is_field_visible() so per-post
	 * overrides are honored. Fields with no stored value are silently
	 * dropped (the renderer never emits empty markers).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $cpt_slug   CPT slug.
	 * @param string $context    'card' or 'single_hero'.
	 * @param array  $field_defs Field definitions keyed by field_key.
	 * @return array<string,array<int,array{def:array,value:mixed}>>
	 */
	private function bucket_fields_by_position( $post_id, $cpt_slug, $context, array $field_defs ) {
		$post_data = $this->get_post_data();
		$buckets   = array();

		$position_attr = ( $context === 'card' ) ? 'card_position' : 'single_position';
		$is_visible_context = ( $context === 'card' ) ? 'card' : 'single';

		foreach ( $field_defs as $field_key => $field_def ) {
			$position = $field_def[ $position_attr ] ?? 'meta_strip';
			if ( $position === 'hidden' ) {
				continue;
			}

			if ( ! $post_data->is_field_visible( $post_id, $field_key, $is_visible_context ) ) {
				continue;
			}

			$value = $post_data->get_field_value( $post_id, $field_key );
			// Drop fields without values entirely. The renderer never
			// emits placeholder markers — empty data == no output.
			if ( $value === null || $value === '' ) {
				continue;
			}

			// For composite types, also drop when the inner value is
			// empty (rating with no value, progress with no current).
			if ( is_array( $value ) ) {
				$inner = $value['value'] ?? null;
				if ( $inner === null || $inner === '' ) {
					continue;
				}
			}

			if ( ! isset( $buckets[ $position ] ) ) {
				$buckets[ $position ] = array();
			}
			$buckets[ $position ][] = array(
				'def'   => $field_def,
				'value' => $value,
			);
		}

		return $buckets;
	}

	/**
	 * Render all fields within a single position.
	 *
	 * @param string $position  Position key.
	 * @param array  $fields    Array of { def, value } entries.
	 * @param string $context   'card' or 'single_hero'.
	 * @param int    $post_id   Post ID.
	 * @return string HTML.
	 */
	private function render_position( $position, array $fields, $context, $post_id ) {
		$position_modifier = str_replace( '_', '-', sanitize_html_class( $position ) );

		$inner = '';
		foreach ( $fields as $entry ) {
			$inner .= $this->render_field( $entry['def'], $entry['value'], $position, $context, $post_id );
		}

		return sprintf(
			'<div class="pre-card-fields__position pre-card-fields__position--%s">%s</div>',
			esc_attr( $position_modifier ),
			$inner
		);
	}

	/**
	 * Dispatch to the per-display-type renderer.
	 *
	 * @param array  $field_def Field definition.
	 * @param mixed  $value     Stored value.
	 * @param string $position  Position the field occupies.
	 * @param string $context   'card' or 'single_hero'.
	 * @param int    $post_id   Post ID (for filter args).
	 * @return string HTML.
	 */
	private function render_field( array $field_def, $value, $position, $context, $post_id ) {
		$display_type = $field_def['display_type'] ?? 'text';

		$html = '';

		switch ( $display_type ) {
			case 'currency':
				$html = $this->render_currency( $field_def, $value, $position, $context );
				break;
			case 'number_with_label':
				$html = $this->render_number_with_label( $field_def, $value, $position, $context );
				break;
			case 'badge':
				$html = $this->render_badge( $field_def, $value, $position, $context );
				break;
			case 'meta_pair':
				$html = $this->render_meta_pair( $field_def, $value, $position, $context );
				break;
			case 'date':
				$html = $this->render_date( $field_def, $value, $position, $context );
				break;
			case 'text':
				$html = $this->render_text( $field_def, $value, $position, $context );
				break;
			case 'rating':
				$html = $this->render_rating( $field_def, $value, $position, $context );
				break;
			case 'progress':
				$html = $this->render_progress( $field_def, $value, $position, $context );
				break;
			case 'multi_badge':
				$html = $this->render_multi_badge( $field_def, $value, $position, $context );
				break;
		}

		/**
		 * Filter the rendered HTML for a single post field.
		 *
		 * Use to customize a single field's output. The display type and
		 * field key are passed for fine-grained filtering. Returning an
		 * empty string suppresses the field entirely.
		 *
		 * @param string $html      Default-rendered HTML.
		 * @param array  $field_def Field definition.
		 * @param mixed  $value     Stored value.
		 * @param string $position  Position the field occupies.
		 * @param string $context   'card' or 'single_hero'.
		 * @param int    $post_id   Post ID.
		 */
		return apply_filters( 'pre_card_field_html', $html, $field_def, $value, $position, $context, $post_id );
	}

	// =====================================================================
	// Per-display-type renderers
	// =====================================================================

	/**
	 * Render currency value (e.g. "$1,250,000").
	 */
	private function render_currency( array $field_def, $value, $position, $context ) {
		$formatted = $this->format_currency( $value, $field_def );
		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			esc_html( $formatted )
		);
	}

	/**
	 * Render number with a unit label (e.g. "1,800 sqft", "3 BR").
	 *
	 * The label comes from the field definition's `label` attribute when
	 * `unit_label` isn't set; otherwise `unit_label` wins. Empty labels
	 * render just the number.
	 */
	private function render_number_with_label( array $field_def, $value, $position, $context ) {
		$number = is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value;
		$label  = $field_def['unit_label'] ?? $field_def['label'] ?? '';
		$display = $label !== '' ? sprintf( '%s %s', $number, $label ) : $number;

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			esc_html( $display )
		);
	}

	/**
	 * Render a single badge with color intent.
	 *
	 * If the field defines `options`, the value is looked up in that map
	 * to resolve the human label AND the per-value color intent override.
	 */
	private function render_badge( array $field_def, $value, $position, $context ) {
		$intent = $field_def['color_intent'] ?? 'neutral';
		$label  = (string) $value;

		// Per-value option lookup: resolves label + optional intent override.
		if ( ! empty( $field_def['options'] ) && is_array( $field_def['options'] ) ) {
			if ( isset( $field_def['options'][ $value ] ) ) {
				$opt    = $field_def['options'][ $value ];
				$label  = $opt['label'] ?? $value;
				$intent = $opt['color_intent'] ?? $intent;
			}
		}

		$classes = $this->classes_for_field( $field_def, $position );
		$classes .= ' pre-field--badge-' . sanitize_html_class( $intent );

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $classes ),
			esc_html( $label )
		);
	}

	/**
	 * Render an icon + value pair (designed for meta_strip).
	 *
	 * Pulls the icon SVG from PRE_Icon_Library when an icon slug is set;
	 * otherwise just renders the value.
	 */
	private function render_meta_pair( array $field_def, $value, $position, $context ) {
		$icon_slug = $field_def['icon'] ?? '';
		$icon_html = '';

		if ( $icon_slug !== '' && class_exists( 'PRE_Icon_Library' ) ) {
			$icon_html = PRE_Icon_Library::render( $icon_slug, 'pre-field__icon' );
		}

		$value_html = sprintf(
			'<span class="pre-field__value">%s</span>',
			esc_html( (string) $value )
		);

		return sprintf(
			'<span class="%s">%s%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			$icon_html,
			$value_html
		);
	}

	/**
	 * Render a date value with the configured format mode.
	 */
	private function render_date( array $field_def, $value, $position, $context ) {
		$formatted = $this->format_date_value( $value, $field_def );

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			esc_html( $formatted )
		);
	}

	/**
	 * Render plain text.
	 */
	private function render_text( array $field_def, $value, $position, $context ) {
		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			esc_html( (string) $value )
		);
	}

	/**
	 * Render a composite rating: stars + numeric value + optional count.
	 *
	 * Expects composite-shape input: `{ value: number, count: int|null }`.
	 */
	private function render_rating( array $field_def, $value, $position, $context ) {
		$rating = is_array( $value ) ? (float) ( $value['value'] ?? 0 ) : (float) $value;
		$count  = is_array( $value ) ? ( $value['count'] ?? null ) : null;
		$max    = isset( $field_def['max'] ) && (int) $field_def['max'] > 0 ? (int) $field_def['max'] : 5;

		$stars_html = $this->render_stars( $rating, $max );

		$count_html = '';
		if ( $count !== null && $count !== '' ) {
			$count_html = sprintf(
				' <span class="pre-field__rating-count">(%s)</span>',
				esc_html( number_format_i18n( (int) $count ) )
			);
		}

		// Format rating to a single decimal when it has one, otherwise integer.
		$rounded         = round( $rating, 1 );
		$formatted_value = ( floor( $rounded ) === $rounded )
			? number_format_i18n( $rounded, 0 )
			: number_format_i18n( $rounded, 1 );

		$sr_label = sprintf(
			/* translators: %1$s: rating value, %2$d: max */
			__( 'Rated %1$s out of %2$d', 'post-runtime-engine' ),
			$formatted_value,
			$max
		);

		return sprintf(
			'<span class="%s" role="img" aria-label="%s">%s <span class="pre-field__rating-value">%s</span>%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			esc_attr( $sr_label ),
			$stars_html,
			esc_html( $formatted_value ),
			$count_html
		);
	}

	/**
	 * Render a progress bar with optional goal label.
	 *
	 * Expects composite-shape input: `{ value: number, goal: number|null }`.
	 * When no goal is set, renders as a 0-100 percentage if the value is
	 * already in that range, otherwise just the raw value.
	 */
	private function render_progress( array $field_def, $value, $position, $context ) {
		$current = is_array( $value ) ? (float) ( $value['value'] ?? 0 ) : (float) $value;
		$goal    = is_array( $value ) ? ( $value['goal']  ?? null ) : null;

		// Compute percentage for the bar width. Clamp to [0, 100].
		$pct = 0.0;
		if ( $goal !== null && (float) $goal > 0 ) {
			$pct = ( $current / (float) $goal ) * 100.0;
		} elseif ( $current >= 0 && $current <= 100 ) {
			// No goal but value reads as a percentage already.
			$pct = $current;
		}
		$pct = max( 0.0, min( 100.0, $pct ) );

		// Label: "65%" if no goal, "65% funded" if a label is defined, or
		// "$320,000 of $500,000" if currency_code is set on the field.
		$label = '';
		if ( ! empty( $field_def['currency_code'] ) && $goal !== null ) {
			$label = sprintf(
				/* translators: %1$s: current amount, %2$s: goal amount */
				__( '%1$s of %2$s', 'post-runtime-engine' ),
				$this->format_currency( $current, $field_def ),
				$this->format_currency( $goal, $field_def )
			);
		} elseif ( ! empty( $field_def['unit_label'] ) ) {
			$label = sprintf( '%s%% %s', number_format_i18n( $pct, 0 ), $field_def['unit_label'] );
		} else {
			$label = sprintf( '%s%%', number_format_i18n( $pct, 0 ) );
		}

		$sr_label = sprintf(
			/* translators: %d: percentage */
			__( '%d percent complete', 'post-runtime-engine' ),
			(int) round( $pct )
		);

		return sprintf(
			'<div class="%s" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100" aria-label="%s">'
			. '<div class="pre-field__progress-track"><div class="pre-field__progress-bar" style="width: %s%%;"></div></div>'
			. '<span class="pre-field__progress-label">%s</span>'
			. '</div>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			(int) round( $pct ),
			esc_attr( $sr_label ),
			esc_attr( number_format( $pct, 1, '.', '' ) ),
			esc_html( $label )
		);
	}

	/**
	 * Render multiple pills from a comma-separated value (or array).
	 */
	private function render_multi_badge( array $field_def, $value, $position, $context ) {
		if ( is_array( $value ) ) {
			$segments = $value;
		} else {
			$segments = array_map( 'trim', explode( ',', (string) $value ) );
		}
		$segments = array_filter( $segments, 'strlen' );

		$default_intent = $field_def['color_intent'] ?? 'neutral';
		$options        = is_array( $field_def['options'] ?? null ) ? $field_def['options'] : array();

		$pills = '';
		foreach ( $segments as $segment ) {
			$label  = $segment;
			$intent = $default_intent;

			// Per-segment override via options map. Lookup tolerates both
			// the raw segment string and sanitize_key form (so editors can
			// store "GF" and look up against an option key "gf").
			$lookup_key = sanitize_key( $segment );
			if ( isset( $options[ $segment ] ) ) {
				$opt    = $options[ $segment ];
				$label  = $opt['label'] ?? $segment;
				$intent = $opt['color_intent'] ?? $intent;
			} elseif ( isset( $options[ $lookup_key ] ) ) {
				$opt    = $options[ $lookup_key ];
				$label  = $opt['label'] ?? $segment;
				$intent = $opt['color_intent'] ?? $intent;
			}

			$pills .= sprintf(
				'<span class="pre-field__multi-badge-pill pre-field__multi-badge-pill--%s">%s</span>',
				esc_attr( sanitize_html_class( $intent ) ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $this->classes_for_field( $field_def, $position ) ),
			$pills
		);
	}

	// =====================================================================
	// Formatting helpers
	// =====================================================================

	/**
	 * Format a numeric value as locale-aware currency.
	 *
	 * Resolution chain (per design doc § 12.1):
	 *   1. Field-level currency_code attribute
	 *   2. AISB Business Identity (`aisb_business_settings` option) when active
	 *   3. `pre_currency` option fallback
	 *   4. 'USD' final default
	 *
	 * @param mixed $value     Numeric value.
	 * @param array $field_def Field definition.
	 * @return string Formatted currency string.
	 */
	private function format_currency( $value, array $field_def ) {
		$amount = is_numeric( $value ) ? (float) $value : 0.0;
		$code   = $this->get_currency_code( $field_def );
		$symbol = $this->get_currency_symbol( $code );

		// Determine decimal precision per currency. JPY, KRW, and a few
		// others have no minor units; default is 2.
		$zero_decimal = array( 'JPY', 'KRW', 'IDR', 'CLP', 'PYG', 'VND', 'XAF', 'XOF' );
		$decimals     = in_array( $code, $zero_decimal, true ) ? 0 : 2;

		// Strip cents when the value is a whole number, even for 2-decimal
		// currencies. "$1,250,000" reads cleaner than "$1,250,000.00" in
		// card headlines. Sites that want fixed precision can hook the
		// pre_format_currency filter below.
		if ( $decimals === 2 && floor( $amount ) === $amount ) {
			$decimals = 0;
		}

		$formatted = $symbol . number_format_i18n( $amount, $decimals );

		// v1.1 — append the field's optional value_suffix. Common patterns:
		// "+" (starting-at), "/mo" (subscription), "/night" (hotels),
		// "+ tax" (taxes excluded), " AUD" (currency code postfix style).
		// Empty suffix (default) is a no-op so existing fields render
		// unchanged. Capped at 16 chars in the validator.
		$suffix = isset( $field_def['value_suffix'] ) ? (string) $field_def['value_suffix'] : '';
		if ( $suffix !== '' ) {
			$formatted .= $suffix;
		}

		/**
		 * Filter the formatted currency string before output.
		 *
		 * @param string $formatted Default formatted string.
		 * @param float  $amount    Raw amount.
		 * @param string $code      ISO 4217 currency code.
		 * @param array  $field_def Field definition.
		 */
		return (string) apply_filters( 'pre_format_currency', $formatted, $amount, $code, $field_def );
	}

	/**
	 * Resolve the effective currency code for a field via the three-tier
	 * chain. Always returns a non-empty uppercase code (final fallback USD).
	 *
	 * @param array $field_def Field definition.
	 * @return string ISO 4217 code.
	 */
	private function get_currency_code( array $field_def ) {
		// 1. Field-level override.
		if ( ! empty( $field_def['currency_code'] ) ) {
			return strtoupper( $field_def['currency_code'] );
		}

		// 2. AISB Business Identity when active. The option may not exist
		// or may not include a currency value — both fall through.
		$aisb_settings = get_option( 'aisb_business_settings', array() );
		if ( is_array( $aisb_settings ) && ! empty( $aisb_settings['currency'] ) ) {
			return strtoupper( $aisb_settings['currency'] );
		}

		// 3. PRE fallback option.
		$pre_currency = get_option( 'pre_currency', '' );
		if ( $pre_currency !== '' ) {
			return strtoupper( $pre_currency );
		}

		// 4. Final default.
		return 'USD';
	}

	/**
	 * Resolve a currency symbol from an ISO 4217 code.
	 *
	 * @param string $code ISO 4217 code.
	 * @return string Symbol or the code itself if no mapping exists.
	 */
	private function get_currency_symbol( $code ) {
		$default_symbols = array(
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'JPY' => '¥',
			'CNY' => '¥',
			'CAD' => 'CA$',
			'AUD' => 'A$',
			'NZD' => 'NZ$',
			'CHF' => 'CHF ',
			'SEK' => 'kr ',
			'NOK' => 'kr ',
			'DKK' => 'kr ',
			'SGD' => 'S$',
			'HKD' => 'HK$',
			'KRW' => '₩',
			'INR' => '₹',
			'BRL' => 'R$',
			'MXN' => 'MX$',
			'ZAR' => 'R',
			'AED' => 'AED ',
			'SAR' => 'SAR ',
			'TRY' => '₺',
			'PLN' => 'zł ',
			'CZK' => 'Kč ',
			'HUF' => 'Ft ',
			'RUB' => '₽',
			'ILS' => '₪',
			'THB' => '฿',
			'PHP' => '₱',
			'IDR' => 'Rp ',
		);

		/**
		 * Filter the currency-code → symbol map. Add custom currencies
		 * here when extending PRE_Validator::SUPPORTED_CURRENCIES.
		 *
		 * @param array $symbols Default map.
		 */
		$symbols = apply_filters( 'pre_currency_symbols', $default_symbols );

		return isset( $symbols[ $code ] ) ? $symbols[ $code ] : ( $code . ' ' );
	}

	/**
	 * Format a date value per the field's `date_format` mode.
	 *
	 * @param mixed $value     Stored date value (string, typically YYYY-MM-DD).
	 * @param array $field_def Field definition.
	 * @return string Formatted date string.
	 */
	private function format_date_value( $value, array $field_def ) {
		$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		if ( $timestamp === false ) {
			return (string) $value;
		}

		$mode = $field_def['date_format'] ?? 'absolute';

		switch ( $mode ) {
			case 'relative':
				$now  = current_time( 'timestamp' );
				$diff = $now - $timestamp;
				if ( $diff >= 0 ) {
					return sprintf(
						/* translators: %s: human-readable time difference */
						__( '%s ago', 'post-runtime-engine' ),
						human_time_diff( $timestamp, $now )
					);
				}
				return sprintf(
					/* translators: %s: human-readable time difference */
					__( 'in %s', 'post-runtime-engine' ),
					human_time_diff( $now, $timestamp )
				);

			case 'custom':
				$format = $field_def['date_format_string'] ?? get_option( 'date_format' );
				return date_i18n( $format, $timestamp );

			case 'absolute':
			default:
				$format = get_option( 'date_format' );
				return date_i18n( $format, $timestamp );
		}
	}

	/**
	 * Render the star glyphs for a rating value.
	 *
	 * Uses ★ and ☆ Unicode characters. Half-stars are approximated by
	 * rounding to the nearest whole star (acceptable for v1.1; SVG-based
	 * half-star rendering can land later if real client demand surfaces).
	 *
	 * Decorative — aria-hidden — the parent element provides the real
	 * accessibility label.
	 *
	 * @param float $rating Rating value.
	 * @param int   $max    Max rating (default 5).
	 * @return string HTML span containing the stars.
	 */
	private function render_stars( $rating, $max ) {
		$rounded = (int) round( max( 0.0, min( (float) $max, (float) $rating ) ) );
		$filled  = str_repeat( self::STAR_FILLED, $rounded );
		$empty   = str_repeat( self::STAR_EMPTY, $max - $rounded );

		return sprintf(
			'<span class="pre-field__stars" aria-hidden="true">%s%s</span>',
			$filled,
			$empty
		);
	}

	/**
	 * Compose the BEM-style class string for a field.
	 *
	 * Format:
	 *   pre-field pre-field--{display_type} pre-field--position-{position}
	 *
	 * @param array  $field_def Field definition.
	 * @param string $position  Position the field occupies.
	 * @return string Space-separated class list.
	 */
	private function classes_for_field( array $field_def, $position ) {
		$display = sanitize_html_class( $field_def['display_type'] ?? 'text' );
		$pos     = sanitize_html_class( str_replace( '_', '-', $position ) );
		$field_key = sanitize_html_class( $field_def['key'] ?? '' );

		$classes = sprintf(
			'pre-field pre-field--%s pre-field--position-%s',
			$display,
			$pos
		);

		if ( $field_key !== '' ) {
			$classes .= ' pre-field--key-' . $field_key;
		}

		return $classes;
	}

	// =====================================================================
	// Dependency resolution
	// =====================================================================

	/**
	 * Resolve the post field registry, preferring constructor injection,
	 * then the global plugin instance, then a fresh fallback.
	 *
	 * @return PRE_Post_Field_Registry
	 */
	private function get_post_fields() {
		if ( $this->post_fields instanceof PRE_Post_Field_Registry ) {
			return $this->post_fields;
		}
		if ( function_exists( 'pre' ) ) {
			$plugin = pre();
			if ( $plugin && isset( $plugin->post_fields ) && $plugin->post_fields instanceof PRE_Post_Field_Registry ) {
				$this->post_fields = $plugin->post_fields;
				return $this->post_fields;
			}
		}
		$this->post_fields = new PRE_Post_Field_Registry();
		return $this->post_fields;
	}

	/**
	 * Resolve the post data accessor, preferring constructor injection.
	 *
	 * @return PRE_Post_Data
	 */
	private function get_post_data() {
		if ( $this->post_data instanceof PRE_Post_Data ) {
			return $this->post_data;
		}
		if ( function_exists( 'pre' ) ) {
			$plugin = pre();
			if ( $plugin && isset( $plugin->post_data ) && $plugin->post_data instanceof PRE_Post_Data ) {
				$this->post_data = $plugin->post_data;
				return $this->post_data;
			}
		}
		// Last-resort fallback. Builds dependencies fresh — should not
		// normally be hit because PRE_Post_Data needs a CPT registry and
		// grouping registry to construct cleanly.
		$cpts      = new PRE_CPT_Registry();
		$groupings = new PRE_Grouping_Registry();
		$this->post_data = new PRE_Post_Data( $cpts, $groupings, null, $this->get_post_fields() );
		return $this->post_data;
	}
}
