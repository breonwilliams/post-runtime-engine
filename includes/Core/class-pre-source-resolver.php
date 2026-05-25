<?php
/**
 * Source resolver for grouping items.
 *
 * Each grouping's items can come from one of three sources: `manual`
 * (items entered per post), `child_posts` (auto from hierarchical
 * children), or `taxonomy_match` (auto from posts sharing a taxonomy
 * term). This class takes a grouping entry plus its definition and
 * returns the resolved items array.
 *
 * Caching is intentionally minimal in v1.0 — WordPress's object cache
 * memoizes WP_Query results within a single request, which is what
 * actually matters for render performance. Cross-request caching can be
 * added in Phase 6 once production usage shows whether it pays.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
 * phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
 *
 * Justification: This class implements the three documented source modes
 * (`manual`, `child_posts`, `taxonomy_match`, `meta_match`). The auto
 * modes legitimately require tax_query / meta_query (to match the
 * configured taxonomy term or meta key/value) and post__not_in (to
 * exclude the current post from "related items" results). These are
 * exactly the query patterns PRE was designed around — Plugin Check
 * flags them as performance-advisory but they're load-bearing here.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves grouping sources to item arrays.
 */
class PRE_Source_Resolver {

	/**
	 * Maximum items returned by an auto source if the definition didn't
	 * specify a limit. Keeps runaway queries from rendering 10,000-item
	 * grids if someone misconfigures a taxonomy match.
	 */
	const DEFAULT_LIMIT = 6;

	/**
	 * Hard upper bound across all sources. Mirrors PRE_Validator.
	 */
	const MAX_LIMIT = 100;

	/**
	 * Resolve the items array for a grouping on a given post.
	 *
	 * @param array   $entry Per-post grouping entry from `_pre_groupings`.
	 * @param array   $def   Grouping definition (from PRE_Grouping_Registry).
	 * @param WP_Post $post  Current post being rendered.
	 * @return array<int,array>
	 */
	public function resolve( array $entry, array $def, WP_Post $post ) {
		$source = isset( $entry['source'] ) && $entry['source'] !== ''
			? $entry['source']
			: ( $def['default_source'] ?? 'manual' );

		// String source: 'manual' or 'child_posts'.
		if ( is_string( $source ) ) {
			if ( $source === 'manual' ) {
				return is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
			}
			if ( $source === 'child_posts' ) {
				return $this->resolve_child_posts( $post, $def );
			}
			return array();
		}

		// Array source: { type, ...params }. Two object-form modes:
		// taxonomy_match (terms) and meta_match (post meta).
		if ( is_array( $source ) ) {
			$type = $source['type'] ?? '';
			if ( $type === 'taxonomy_match' ) {
				return $this->resolve_taxonomy_match( $post, $source, $def );
			}
			if ( $type === 'meta_match' ) {
				return $this->resolve_meta_match( $post, $source, $def );
			}
		}

		return array();
	}

	/**
	 * Resolve child_posts source. Returns items mapped from posts whose
	 * post_parent equals the current post's ID, in the same post type.
	 *
	 * @param WP_Post $post Current post.
	 * @param array   $def  Grouping definition.
	 * @return array<int,array>
	 */
	private function resolve_child_posts( WP_Post $post, array $def ) {
		$limit = $this->effective_limit( $def );

		$children = get_posts(
			array(
				'post_type'        => $post->post_type,
				'post_parent'      => $post->ID,
				'post_status'      => 'publish',
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'numberposts'      => $limit,
				'suppress_filters' => false,
			)
		);

		return array_map( array( $this, 'post_to_item' ), $children );
	}

	/**
	 * Resolve taxonomy_match source. Returns items from posts sharing one
	 * of the current post's terms in the configured taxonomy.
	 *
	 * @param WP_Post $post   Current post.
	 * @param array   $source Source config ({type, taxonomy, limit, exclude_self, ...}).
	 * @param array   $def    Grouping definition.
	 * @return array<int,array>
	 */
	private function resolve_taxonomy_match( WP_Post $post, array $source, array $def ) {
		$taxonomy = isset( $source['taxonomy'] ) ? sanitize_key( $source['taxonomy'] ) : '';
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$current_terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $current_terms ) || empty( $current_terms ) ) {
			return array();
		}

		$limit         = isset( $source['limit'] ) ? (int) $source['limit'] : null;
		$limit         = $limit !== null && $limit > 0 ? min( $limit, self::MAX_LIMIT ) : $this->effective_limit( $def );
		$exclude_self  = ! isset( $source['exclude_self'] ) || $source['exclude_self'];

		$args = array(
			'post_type'        => $post->post_type,
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
			'tax_query'        => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $current_terms,
				),
			),
		);

		if ( $exclude_self ) {
			$args['exclude'] = array( $post->ID );
		}

		$matched = get_posts( $args );
		return array_map( array( $this, 'post_to_item' ), $matched );
	}

	/**
	 * Resolve meta_match source. Returns items from posts whose configured
	 * meta_key value equals the current post's value for the same key.
	 *
	 * Mirrors how taxonomy_match works: reads a parameter from the current
	 * post and finds related posts in the same CPT. Use cases:
	 *   - "More listings from this agent"  (meta_key: '_agent_id')
	 *   - "Other openings from this employer"  (meta_key: '_employer_id')
	 *   - "Other locations of this business"  (meta_key: '_business_id')
	 *
	 * If the current post has no value for the meta_key (or the value is
	 * an empty string), the resolver returns an empty array — same fail-safe
	 * behavior as taxonomy_match when the post has no terms.
	 *
	 * @param WP_Post $post   Current post.
	 * @param array   $source Source config ({type, meta_key, limit, exclude_self}).
	 * @param array   $def    Grouping definition.
	 * @return array<int,array>
	 */
	private function resolve_meta_match( WP_Post $post, array $source, array $def ) {
		$meta_key = isset( $source['meta_key'] ) ? (string) $source['meta_key'] : '';
		if ( $meta_key === '' ) {
			return array();
		}

		$current_value = get_post_meta( $post->ID, $meta_key, true );

		// Empty / unset values short-circuit. Matching against "" would return
		// every post that ALSO has no value for the key — which is virtually
		// every post in the CPT. That's never what the user wants.
		if ( $current_value === '' || $current_value === null || $current_value === false ) {
			return array();
		}

		// Arrays / objects are out of scope for the v1 mirror-current-post
		// shape. Real-world uses (agent_id, employer_id, business_id) are
		// scalar identifiers. Returning empty here prevents accidentally
		// serializing an array into the meta_query value.
		if ( is_array( $current_value ) || is_object( $current_value ) ) {
			return array();
		}

		$limit        = isset( $source['limit'] ) ? (int) $source['limit'] : null;
		$limit        = $limit !== null && $limit > 0 ? min( $limit, self::MAX_LIMIT ) : $this->effective_limit( $def );
		$exclude_self = ! isset( $source['exclude_self'] ) || $source['exclude_self'];

		$args = array(
			'post_type'        => $post->post_type,
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'     => $meta_key,
					'value'   => $current_value,
					'compare' => '=',
				),
			),
		);

		if ( $exclude_self ) {
			$args['exclude'] = array( $post->ID );
		}

		$matched = get_posts( $args );
		return array_map( array( $this, 'post_to_item' ), $matched );
	}

	/**
	 * Map a WP_Post into the standard grouping-item shape.
	 *
	 * Field mapping:
	 *   image_id        → featured image ID (if set)
	 *   icon_id         → _pre_icon post meta (if set; takes precedence over image when no thumbnail)
	 *   heading         → post_title
	 *   supporting_text → post_excerpt (or trimmed post_content if no excerpt)
	 *   link            → permalink
	 *
	 * @param WP_Post $post Source post.
	 * @return array
	 */
	private function post_to_item( WP_Post $post ) {
		$image_id = (int) get_post_thumbnail_id( $post->ID );
		$icon_id  = (string) get_post_meta( $post->ID, '_pre_icon', true );

		// If both an icon and an image exist, the image wins by default —
		// cards with photos are richer than cards with icons. The validator
		// blocks both being set on a manual item; for auto items we resolve
		// to one or the other here.
		if ( $image_id > 0 ) {
			$icon_id = '';
		}

		// Excerpt fallback: post_excerpt if set, otherwise auto-generate from
		// content. Mirrors WP's get_the_excerpt() behavior but bypasses the
		// global $post setting required by the_excerpt().
		$excerpt = $post->post_excerpt;
		if ( $excerpt === '' ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 24, '…' );
		}

		return array(
			'image_id'        => $image_id > 0 ? $image_id : null,
			'icon_id'         => $icon_id !== '' ? $icon_id : null,
			'heading'         => $post->post_title,
			'supporting_text' => $excerpt !== '' ? $excerpt : null,
			'link'            => get_permalink( $post ),
		);
	}

	/**
	 * Effective item limit for a grouping. Caps at MAX_LIMIT.
	 *
	 * @param array $def Grouping definition.
	 * @return int
	 */
	private function effective_limit( array $def ) {
		$limit = isset( $def['max_items'] ) && $def['max_items'] !== null
			? (int) $def['max_items']
			: self::DEFAULT_LIMIT;
		return max( 1, min( $limit, self::MAX_LIMIT ) );
	}
}
