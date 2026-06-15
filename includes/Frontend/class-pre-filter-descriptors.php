<?php
/**
 * Filter-descriptor provider (schema-driven filters, v1.2 — Phase 2).
 *
 * Answers the question "what filters does this post type want?" by mapping
 * each filterable post field (and each public taxonomy) onto Promptless WP's
 * CLOSED generic filter-widget vocabulary. Promptless renders + queries purely
 * from these descriptors and never learns a PRE display_type name — the same
 * one-way, hook-based decoupling the events vertical established.
 *
 * Two channels read the SAME builder:
 *   - PHP filter hook `aisb_postgrid_available_filters` — front-end render.
 *   - REST `GET post-runtime/v1/editor/post-type-filters/{slug}` — editor JS
 *     (edit_posts auth + REST cookie nonce; NOT the Cowork connector surface).
 *
 * The descriptor `query` block carries everything the query engine (Phase 3)
 * needs to build the clause WITHOUT calling back into PRE — except the
 * `event_status` mode, which is end-anchored and provider-handled (PRE hooks
 * the query). `handler` distinguishes the two.
 *
 * Full contract: ai-section-builder-modern/docs/development/SCHEMA_DRIVEN_FILTERS_DESIGN.md § 4, § 6.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds + exposes filter descriptors for PRE-managed post types.
 */
class PCPTPages_Filter_Descriptors {

	/**
	 * Hard cap on taxonomy term options surfaced per facet — bounds the
	 * payload and keeps a runaway taxonomy from bloating every render.
	 */
	const MAX_TERM_OPTIONS = 100;

	/**
	 * Wire the provider hook + the editor REST route.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'aisb_postgrid_available_filters', array( $this, 'provide_filters' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * `aisb_postgrid_available_filters` provider. Promptless calls this during
	 * render with an empty list; PRE appends its descriptors for the queried
	 * post type. Non-PRE post types are passed through untouched.
	 *
	 * @param array  $descriptors Descriptors accumulated by earlier providers.
	 * @param string $post_type   The post type PostGrid is querying.
	 * @return array
	 */
	public function provide_filters( $descriptors, $post_type ) {
		if ( ! is_array( $descriptors ) ) {
			$descriptors = array();
		}
		$mine = self::build_for_cpt( (string) $post_type );
		if ( empty( $mine ) ) {
			return $descriptors;
		}
		return array_merge( $descriptors, $mine );
	}

	/**
	 * Register the editor REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'post-runtime/v1',
			'/editor/post-type-filters/(?P<slug>[a-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'slug' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Editor permission: any user who can edit posts. The REST cookie nonce is
	 * validated by core before this runs. Mirrors PCPTPages_Editor_Preview_API.
	 *
	 * @return bool
	 */
	public function permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * REST handler: return the descriptor list for a CPT slug.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		return rest_ensure_response(
			array(
				'post_type' => $slug,
				'filters'   => self::build_for_cpt( $slug ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Builder
	// ---------------------------------------------------------------------

	/**
	 * Build the full descriptor list for a CPT: filterable post fields first
	 * (declaration order), then public taxonomies registered to the CPT.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return array List of descriptor arrays. Empty when the CPT is unknown
	 *               or has no filterable surface.
	 */
	public static function build_for_cpt( $cpt_slug ) {
		$cpt_slug = sanitize_key( (string) $cpt_slug );
		if ( $cpt_slug === '' ) {
			return array();
		}

		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || empty( $plugin->post_fields ) ) {
			return array();
		}

		$defs = $plugin->post_fields->get_all( $cpt_slug );
		if ( ! is_array( $defs ) ) {
			$defs = array();
		}

		$out = array();

		foreach ( $defs as $key => $def ) {
			if ( ! is_array( $def ) || empty( $def['filterable'] ) ) {
				continue;
			}
			$descriptor = self::build_field_descriptor( $cpt_slug, (string) $key, $def );
			if ( $descriptor !== null ) {
				$out[] = $descriptor;
			}
		}

		foreach ( self::filterable_taxonomies( $cpt_slug ) as $taxonomy ) {
			$descriptor = self::build_taxonomy_descriptor( $taxonomy );
			if ( $descriptor !== null ) {
				$out[] = $descriptor;
			}
		}

		/**
		 * Filter the descriptor list a CPT exposes, after PRE builds it.
		 * Lets a site add, remove, or reshape facets without touching PRE.
		 *
		 * @param array  $out      Descriptor list.
		 * @param string $cpt_slug CPT slug.
		 */
		return apply_filters( 'pcptpages_filter_descriptors', $out, $cpt_slug );
	}

	/**
	 * Build a descriptor for one filterable post field.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @param string $key      Field key.
	 * @param array  $def      Field definition.
	 * @return array|null Null when the field's display type isn't filterable.
	 */
	private static function build_field_descriptor( $cpt_slug, $key, array $def ) {
		$display_type = $def['display_type'] ?? '';
		$widget       = self::resolve_widget( $key, $def, $cpt_slug );
		if ( $widget === '' ) {
			return null; // e.g. meta_pair, or an unmapped type.
		}

		$meta_key = PCPTPages_Post_Data::FIELD_VALUE_META_PREFIX . $key;

		$descriptor = array(
			'key'        => $key,
			'label'      => isset( $def['label'] ) ? (string) $def['label'] : $key,
			'widget'     => $widget,
			'params'     => self::params_for_widget( $key, $widget ),
			'query'      => self::query_for( $cpt_slug, $key, $def, $widget, $meta_key ),
			'options'    => null,
			'range'      => null,
			'sortable'   => ! empty( $def['sortable'] ),
			'sort_param' => ! empty( $def['sortable'] ) ? $key : null,
		);

		if ( in_array( $widget, array( 'pill_select', 'checkbox_group' ), true ) ) {
			$descriptor['options'] = self::options_for_field( $def );
		}

		if ( in_array( $widget, array( 'range', 'stepper' ), true ) ) {
			$descriptor['range'] = self::range_for_field( $def );
		}

		return $descriptor;
	}

	/**
	 * Resolve the generic widget for a field: honor a valid `filter_widget`
	 * override, else the display_type default. Date fields are role-aware —
	 * a date_toggle only makes sense for an event-role date (it needs a
	 * start/end to anchor); a role-less date defaults to a date_range.
	 *
	 * @param string $key      Field key.
	 * @param array  $def      Field definition.
	 * @param string $cpt_slug CPT slug.
	 * @return string Widget id, or '' when the type isn't filterable.
	 */
	private static function resolve_widget( $key, array $def, $cpt_slug ) {
		$display_type = $def['display_type'] ?? '';
		$allowed      = PCPTPages_Validator::DISPLAY_TYPE_FILTER_WIDGETS[ $display_type ] ?? array();
		if ( empty( $allowed ) ) {
			return '';
		}

		$override = isset( $def['filter_widget'] ) ? (string) $def['filter_widget'] : '';

		if ( $display_type === 'date' ) {
			$role        = $def['semantic_role'] ?? '';
			$is_event    = in_array( $role, array( 'event_start', 'event_end' ), true );
			// A role-less date can't drive an upcoming/past toggle.
			if ( ! $is_event ) {
				return 'date_range';
			}
			// Event date: honor a date_range override, else toggle.
			if ( $override === 'date_range' ) {
				return 'date_range';
			}
			return 'date_toggle';
		}

		if ( $override !== '' && in_array( $override, $allowed, true ) ) {
			return $override;
		}
		return $allowed[0];
	}

	/**
	 * URL param-name map for a widget, all derived from the field key.
	 *
	 * @param string $key    Field key (or taxonomy slug).
	 * @param string $widget Widget id.
	 * @return array
	 */
	private static function params_for_widget( $key, $widget ) {
		switch ( $widget ) {
			case 'range':
				return array( 'min' => $key . '_min', 'max' => $key . '_max' );
			case 'stepper':
				return array( 'min' => $key . '_min' );
			case 'date_toggle':
				return array( 'when' => $key . '_when' );
			case 'date_range':
				return array( 'after' => $key . '_after', 'before' => $key . '_before' );
			case 'text_search':
				return array( 'q' => $key . '_q' );
			case 'pill_select':
				return array( 'value' => $key );
			case 'checkbox_group':
				return array( 'values' => $key );
			default:
				return array();
		}
	}

	/**
	 * Build the `query` block: a self-describing instruction the Phase 3 query
	 * engine executes. `handler:generic` clauses Promptless builds itself;
	 * `handler:provider` (event_status) is translated by PRE's query hook.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @param string $key      Field key.
	 * @param array  $def      Field definition.
	 * @param string $widget   Resolved widget.
	 * @param string $meta_key Primary value meta key for the field.
	 * @return array
	 */
	private static function query_for( $cpt_slug, $key, array $def, $widget, $meta_key ) {
		switch ( $widget ) {
			case 'range':
				return array( 'handler' => 'generic', 'mode' => 'meta_range', 'meta_key' => $meta_key, 'type' => 'NUMERIC' );

			case 'stepper':
				return array( 'handler' => 'generic', 'mode' => 'meta_min', 'meta_key' => $meta_key, 'type' => 'NUMERIC' );

			case 'pill_select':
				return array( 'handler' => 'generic', 'mode' => 'meta_equals', 'meta_key' => $meta_key );

			case 'checkbox_group':
				// multi_badge is stored comma-separated; matched per-value.
				return array( 'handler' => 'generic', 'mode' => 'meta_in_csv', 'meta_key' => $meta_key );

			case 'text_search':
				return array( 'handler' => 'generic', 'mode' => 'meta_like', 'meta_key' => $meta_key );

			case 'date_range':
				// The stored date string ('Y-m-d' or 'Y-m-d H:i:s') is directly
				// comparable; no numeric companion needed. DATETIME covers both
				// shapes in WP's meta_query date casting.
				return array( 'handler' => 'generic', 'mode' => 'date_range', 'meta_key' => $meta_key, 'type' => 'DATETIME' );

			case 'date_toggle':
				// End-anchored upcoming/happening/past — PRE owns this logic
				// (PCPTPages_Event_Query). Provider-handled in Phase 3.
				return array( 'handler' => 'provider', 'mode' => 'event_status', 'cpt' => $cpt_slug );

			default:
				return array( 'handler' => 'generic', 'mode' => 'noop' );
		}
	}

	/**
	 * Option list for a badge / multi_badge field, from its `options` map.
	 *
	 * @param array $def Field definition.
	 * @return array List of { value, label }.
	 */
	private static function options_for_field( array $def ) {
		$options = ( isset( $def['options'] ) && is_array( $def['options'] ) ) ? $def['options'] : array();
		$out     = array();
		foreach ( $options as $value => $opt ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => ( is_array( $opt ) && isset( $opt['label'] ) ) ? (string) $opt['label'] : (string) $value,
			);
		}
		return $out;
	}

	/**
	 * Declarative range for a numeric field. True data-driven min/max is a
	 * Phase 4+ refinement (and a per-result-set concern); here we surface what
	 * the definition already knows so the UI can render sane bounds.
	 *
	 * @param array $def Field definition.
	 * @return array { min, max|null, step }
	 */
	private static function range_for_field( array $def ) {
		$display_type = $def['display_type'] ?? '';
		$declared_max = isset( $def['max'] ) && is_numeric( $def['max'] ) ? (float) $def['max'] : 0.0;

		switch ( $display_type ) {
			case 'rating':
				return array( 'min' => 0, 'max' => $declared_max > 0 ? $declared_max : 5, 'step' => 1 );
			case 'progress':
				return array( 'min' => 0, 'max' => $declared_max > 0 ? $declared_max : 100, 'step' => 1 );
			case 'number_with_label':
				return array( 'min' => 0, 'max' => $declared_max > 0 ? $declared_max : null, 'step' => 1 );
			case 'currency':
			default:
				// Open-ended; the UI may refine the ceiling from loaded results.
				return array( 'min' => 0, 'max' => $declared_max > 0 ? $declared_max : null, 'step' => null );
		}
	}

	// ---------------------------------------------------------------------
	// Taxonomies
	// ---------------------------------------------------------------------

	/**
	 * Public, UI-visible taxonomies registered to the CPT. These become
	 * checkbox_group facets (the universal "filter by category" pattern).
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return \WP_Taxonomy[]
	 */
	private static function filterable_taxonomies( $cpt_slug ) {
		if ( ! post_type_exists( $cpt_slug ) ) {
			return array();
		}
		$objects = get_object_taxonomies( $cpt_slug, 'objects' );
		if ( ! is_array( $objects ) ) {
			return array();
		}
		$out = array();
		foreach ( $objects as $tax ) {
			if ( ! empty( $tax->public ) && ! empty( $tax->show_ui ) ) {
				$out[] = $tax;
			}
		}
		return $out;
	}

	/**
	 * Build a checkbox_group descriptor for a taxonomy, with its terms as
	 * options (hierarchy carried via `parent` for indented rendering).
	 *
	 * @param \WP_Taxonomy $tax Taxonomy object.
	 * @return array|null
	 */
	private static function build_taxonomy_descriptor( $tax ) {
		$slug  = $tax->name;
		// hide_empty: a facet should never offer a dead-end option. A term
		// with zero published posts returns nothing when selected, so it is
		// excluded. With the intended greenfield model — a dedicated taxonomy
		// owned by the CPT (e.g. a `neighborhood` taxonomy used only by
		// `property`) — this is exactly the CPT-scoped behavior the facet
		// wants. (Shared built-in taxonomies like `category` count posts across
		// all post types; a category used only by blog posts could still
		// surface here. CPT-scoped term counting is a deliberate future
		// enhancement — the architecture steers toward per-CPT taxonomies.)
		$terms = get_terms(
			array(
				'taxonomy'   => $slug,
				'hide_empty' => true,
				'number'     => self::MAX_TERM_OPTIONS,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$options = array();
		foreach ( $terms as $term ) {
			$options[] = array(
				'value'  => $term->slug,
				'label'  => $term->name,
				'parent' => (int) $term->parent,
			);
		}

		return array(
			'key'        => $slug,
			'label'      => isset( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : $slug,
			'widget'     => 'checkbox_group',
			'params'     => array( 'values' => $slug ),
			'query'      => array( 'handler' => 'generic', 'mode' => 'tax_in', 'taxonomy' => $slug, 'field' => 'slug' ),
			'options'    => $options,
			'range'      => null,
			'sortable'   => false,
			'sort_param' => null,
		);
	}
}
