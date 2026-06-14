<?php
/**
 * Card filter hook listener (v1.1 Phase 12).
 *
 * Subscribes to two action hooks exposed by upstream consumers:
 *   - `aisb_postgrid_card_section`         — fired by Promptless WP's PostGrid
 *     section (includes/Core/Sections/Renderers/PostGridRenderer.php) at 5
 *     semantic positions per card.
 *   - `promptless_archive_card_section`    — fired by Promptless theme's
 *     archive card template (template-parts/archive/card.php) at the same 5
 *     positions per card.
 *
 * For each fire, the listener checks if the post belongs to a PRE-managed
 * CPT, and if so, echoes the position-specific HTML produced by
 * PCPTPages_Card_Renderer::render_position_html().
 *
 * Architecture note: PRE has zero PHP-level dependency on Promptless WP or
 * the Promptless theme. Both consumers are simply exposing a do_action()
 * call; PRE listens. When either consumer is absent (or when their version
 * predates Phase 12), the action never fires and PRE simply renders nothing
 * in those contexts — the single-post hero (which PRE owns) continues to
 * render unchanged.
 *
 * Also handles the conditional CSS enqueue: cards.css is loaded on any page
 * that fires either action for the first time with a PRE-managed post,
 * which covers archive pages and PostGrid sections without an a-priori
 * detection pass.
 *
 * @package PostRuntimeEngine
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to the AISB PostGrid + Promptless theme card section actions.
 */
class PCPTPages_Card_Filter_Hooks {

	/**
	 * Memoized card renderer for the request. Lazily instantiated on
	 * first need.
	 *
	 * @var PCPTPages_Card_Renderer|null
	 */
	private $renderer = null;

	/**
	 * Tracks whether the card assets (cards.css + iconify-icon JS) have
	 * been enqueued / late-injected during this request. Set when the
	 * first relevant action fires. Avoids redundant injections per card.
	 *
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Wire the hooks. Called once during plugin init.
	 */
	public function init() {
		// AISB PostGrid section. 4 args: position, post_id, content array.
		// (We register at 4 args so listeners with shorter signatures still
		// work; WP passes only what they ask for.)
		add_action( 'aisb_postgrid_card_section', array( $this, 'on_postgrid_card_section' ), 10, 3 );

		// Promptless theme archive card. 2 args: position, post_id.
		add_action( 'promptless_archive_card_section', array( $this, 'on_archive_card_section' ), 10, 2 );

		// Per-CPT toggles for the theme's archive-card date + author byline.
		// Default true. When a CPT is registered with PRE and its definition
		// includes `archive_show_post_date: false`, that filter returns
		// false for that CPT's posts, suppressing the theme-rendered date.
		// Non-PRE CPTs are unaffected (filter returns the incoming value).
		add_filter( 'promptless_archive_card_show_date', array( $this, 'filter_archive_show_date' ), 10, 2 );
		add_filter( 'promptless_archive_card_show_author', array( $this, 'filter_archive_show_author' ), 10, 2 );

		// Events vertical (v1.2): decoupled PostGrid query shaping. Promptless
		// WP's PostGrid exposes its assembled WP_Query args via this generic
		// filter; PRE injects an event date-status meta_query + ordering when
		// the queried CPT is event-shaped. AISB stays zero-knowledge of PRE.
		add_filter( 'aisb_postgrid_query_args', array( $this, 'filter_postgrid_query_args' ), 10, 3 );

		// Schema-driven filters (v1.2): the visitor-facing event date_toggle
		// facet is provider-handled — Promptless can't build the end-anchored
		// upcoming/happening/past meta_query, so its filter engine delegates it
		// here via aisb_postfilter_query_args. PRE owns the event logic, reused
		// verbatim from the events vertical. Generic facets (range, checkbox,
		// text, taxonomy) Promptless builds itself and never reach this hook.
		add_filter( 'aisb_postfilter_query_args', array( $this, 'filter_postfilter_query_args' ), 10, 2 );
	}

	/**
	 * Filter callback for `aisb_postfilter_query_args` (provider-handled facets).
	 *
	 * Scans the descriptors for an active `event_status` (date_toggle) facet and,
	 * when its `{key}_when` param holds a valid status, merges the end-anchored
	 * status meta_query into the filter fragment. Everything else passes through
	 * — including every non-event facet and non-PRE post type.
	 *
	 * @param array $fragment The filter query fragment from PostFilterQuery.
	 * @param array $context  { post_type, params, descriptors }.
	 * @return array
	 */
	public function filter_postfilter_query_args( $fragment, $context = array() ) {
		if ( ! is_array( $fragment ) ) {
			return $fragment;
		}

		$context     = is_array( $context ) ? $context : array();
		$params      = ( isset( $context['params'] ) && is_array( $context['params'] ) ) ? $context['params'] : array();
		$descriptors = ( isset( $context['descriptors'] ) && is_array( $context['descriptors'] ) ) ? $context['descriptors'] : array();

		foreach ( $descriptors as $desc ) {
			if ( ! is_array( $desc ) ) {
				continue;
			}
			$query = ( isset( $desc['query'] ) && is_array( $desc['query'] ) ) ? $desc['query'] : array();
			if ( ( $query['handler'] ?? '' ) !== 'provider' || ( $query['mode'] ?? '' ) !== 'event_status' ) {
				continue;
			}

			$when_param = $desc['params']['when'] ?? '';
			if ( $when_param === '' || ! isset( $params[ $when_param ] ) ) {
				continue;
			}

			$raw    = is_array( $params[ $when_param ] ) ? reset( $params[ $when_param ] ) : $params[ $when_param ];
			$status = sanitize_key( (string) $raw );
			if ( ! in_array( $status, PCPTPages_Event_Query::STATUSES, true ) ) {
				// 'all' or anything unrecognized — no date constraint.
				continue;
			}

			$cpt = $query['cpt'] ?? '';
			if ( $cpt === '' || ! PCPTPages_Event_Query::is_event_cpt( $cpt ) ) {
				continue;
			}

			$status_group = PCPTPages_Event_Query::status_meta_query( $cpt, $status );
			if ( empty( $status_group ) ) {
				continue;
			}

			if ( empty( $fragment['meta_query'] ) || ! is_array( $fragment['meta_query'] ) ) {
				$fragment['meta_query'] = array( 'relation' => 'AND', $status_group );
			} else {
				if ( empty( $fragment['meta_query']['relation'] ) ) {
					$fragment['meta_query']['relation'] = 'AND';
				}
				$fragment['meta_query'][] = $status_group;
			}
		}

		return $fragment;
	}

	/**
	 * Filter callback for `aisb_postgrid_query_args`.
	 *
	 * When the PostGrid section requests an event date status
	 * (`$content['event_status']` = upcoming|happening|past) and the queried
	 * post type is a PRE event-shaped CPT, merge in the date-status
	 * meta_query and (unless disabled) order by event date. Otherwise the
	 * args pass through untouched — including for every non-PRE post type.
	 *
	 * @param array $query_args      Assembled WP_Query args from PostGrid.
	 * @param array $content         The PostGrid section content/settings.
	 * @param int   $section_post_id The post the section is rendered on (unused).
	 * @return array
	 */
	public function filter_postgrid_query_args( $query_args, $content = array(), $section_post_id = 0 ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}

		$content = is_array( $content ) ? $content : array();
		$status  = isset( $content['event_status'] ) ? sanitize_key( $content['event_status'] ) : '';
		if ( ! in_array( $status, PCPTPages_Event_Query::STATUSES, true ) ) {
			// '', 'none', or anything unrecognized — no event filtering.
			return $query_args;
		}

		// Resolve the queried post type (string or first of an array).
		$post_type = isset( $query_args['post_type'] ) ? $query_args['post_type'] : '';
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}
		if ( ! is_string( $post_type ) || $post_type === '' ) {
			return $query_args;
		}

		// Only act on PRE event-shaped CPTs.
		if ( ! PCPTPages_Event_Query::is_event_cpt( $post_type ) ) {
			return $query_args;
		}

		$status_group = PCPTPages_Event_Query::status_meta_query( $post_type, $status );
		if ( empty( $status_group ) ) {
			return $query_args;
		}

		$query_args = self::merge_meta_query( $query_args, $status_group );

		// Order by event date unless explicitly disabled.
		$requested_sort = isset( $content['event_sort'] ) ? sanitize_key( $content['event_sort'] ) : 'auto';
		if ( $requested_sort !== 'none' ) {
			$direction = PCPTPages_Event_Query::resolve_sort_direction( $status, $requested_sort );
			$sort_args = PCPTPages_Event_Query::sort_args( $post_type, $direction );
			if ( ! empty( $sort_args ) ) {
				$query_args['meta_key'] = $sort_args['meta_key'];
				$query_args['orderby']  = $sort_args['orderby'];
				$query_args['order']    = $sort_args['order'];
			}
		}

		return $query_args;
	}

	/**
	 * Merge an additional meta_query group into existing WP_Query args
	 * without clobbering any meta_query the caller already set. When one is
	 * present, the two groups are ANDed; otherwise the new group is used
	 * directly.
	 *
	 * @param array $query_args  WP_Query args.
	 * @param array $new_group   A meta_query group to add.
	 * @return array
	 */
	private static function merge_meta_query( $query_args, $new_group ) {
		if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
			$query_args['meta_query'] = $new_group;
			return $query_args;
		}

		$query_args['meta_query'] = array(
			'relation' => 'AND',
			$query_args['meta_query'],
			$new_group,
		);

		return $query_args;
	}

	/**
	 * Filter callback: return the CPT's `archive_show_post_date` flag
	 * when the post belongs to a PRE-registered CPT. Falls through to
	 * the default (the incoming $show value, typically true) for any
	 * post type PRE doesn't manage.
	 *
	 * @param bool $show    Current decision from the theme.
	 * @param int  $post_id Post being rendered.
	 * @return bool
	 */
	public function filter_archive_show_date( $show, $post_id ) {
		return $this->cpt_toggle( $show, $post_id, 'archive_show_post_date' );
	}

	/**
	 * Filter callback: return the CPT's `archive_show_post_author` flag.
	 *
	 * @param bool $show    Current decision from the theme.
	 * @param int  $post_id Post being rendered.
	 * @return bool
	 */
	public function filter_archive_show_author( $show, $post_id ) {
		return $this->cpt_toggle( $show, $post_id, 'archive_show_post_author' );
	}

	/**
	 * Shared CPT-lookup helper for the archive-meta filters above.
	 *
	 * @param bool   $show     Theme's incoming value.
	 * @param int    $post_id  Post being filtered.
	 * @param string $cpt_key  Definition key on the CPT registry to read.
	 * @return bool
	 */
	private function cpt_toggle( $show, $post_id, $cpt_key ) {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return $show;
		}
		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			// Not a PRE-managed CPT — defer to the theme's decision.
			return $show;
		}
		$def = $plugin->cpts->get( $post->post_type );
		if ( ! is_array( $def ) || ! array_key_exists( $cpt_key, $def ) ) {
			return $show;
		}
		return (bool) $def[ $cpt_key ];
	}

	/**
	 * Handle a PostGrid card section action.
	 *
	 * @param string $position Position key.
	 * @param int    $post_id  Post ID.
	 * @param array  $content  PostGrid section content (unused here, available
	 *                          for future logic that wants the section context).
	 */
	public function on_postgrid_card_section( $position, $post_id, $content = array() ) {
		$this->emit_position( $position, $post_id );
	}

	/**
	 * Handle a theme archive card section action.
	 *
	 * @param string $position Position key.
	 * @param int    $post_id  Post ID.
	 */
	public function on_archive_card_section( $position, $post_id ) {
		$this->emit_position( $position, $post_id );
	}

	/**
	 * Internal: emit the HTML for one position of one card.
	 *
	 * @param string $position Position key.
	 * @param int    $post_id  Post ID.
	 */
	private function emit_position( $position, $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id === 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		// Silently no-op when the post type isn't one we manage. Avoids
		// any output on non-PRE cards even though the action fired.
		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Defer CSS enqueue until we know we'll actually render something
		// — saves the stylesheet load on cards that have no fields. We
		// still need this BEFORE the render call so the styles are queued
		// before any inline output, but only conditional on us being about
		// to render fields for a managed CPT.
		$this->maybe_enqueue_card_assets();

		$renderer = $this->get_renderer();
		$html     = $renderer->render_position_html( $post_id, $position, 'card' );

		if ( $html === '' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_position_html returns pre-escaped HTML.
		echo $html;
	}

	/**
	 * Inject cards.css AND the iconify-icon web component JS into the
	 * response if neither has been queued yet for this request.
	 *
	 * Two cases land here:
	 *   1. PostGrid section inside a Promptless page (a non-CPT URL like
	 *      `/home/`). PCPTPages_Frontend_Assets::enqueue() never fires because
	 *      this isn't a registered CPT page; we late-inject both assets.
	 *   2. Theme archive of a registered CPT where PCPTPages_Frontend_Assets
	 *      DOES fire (it now covers archives, not just singles). In that
	 *      case wp_style_is() / wp_script_is() returns true and we skip
	 *      the duplicate injection.
	 *
	 * Idempotent at three levels: the per-request flag, the WP enqueue
	 * registry, and the browser cache.
	 */
	private function maybe_enqueue_card_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;

		// CSS — register/enqueue/print through the standard wp_*_style
		// API rather than emitting a raw <link> tag. We're firing mid-
		// response (the action that triggers this enqueue happens during
		// card rendering, after wp_head has closed) so we have to call
		// wp_print_styles() explicitly to force WordPress to emit the
		// <link> tag now. Using the API instead of raw printf means the
		// dependency / version / cache-busting machinery all works.
		if ( ! wp_style_is( 'pcptpages-cards', 'enqueued' ) ) {
			if ( ! wp_style_is( 'pcptpages-cards', 'registered' ) ) {
				wp_register_style(
					'pcptpages-cards',
					PCPTPages_PLUGIN_URL . 'assets/css/cards.css',
					array(),
					PCPTPages_VERSION
				);
			}
			wp_enqueue_style( 'pcptpages-cards' );
			// Force immediate emission. wp_head has already passed.
			wp_print_styles( array( 'pcptpages-cards' ) );
		}

		// Iconify web component — same pattern. Required for the
		// <iconify-icon icon="mdi:..."> elements the meta_pair display
		// type emits to actually render their SVG. Without the JS that
		// registers the custom element, those tags stay as empty 14×14
		// placeholders (the user sees no glyph). Bundled locally at
		// assets/js/iconify-icon.min.js.
		if ( ! wp_script_is( 'pcptpages-iconify-icon', 'enqueued' ) ) {
			if ( ! wp_script_is( 'pcptpages-iconify-icon', 'registered' ) ) {
				wp_register_script(
					'pcptpages-iconify-icon',
					PCPTPages_PLUGIN_URL . 'assets/js/iconify-icon.min.js',
					array(),
					'2.1.0',
					true
				);
				wp_script_add_data( 'pcptpages-iconify-icon', 'type', 'module' );
			}
			wp_enqueue_script( 'pcptpages-iconify-icon' );
			// Force immediate emission. wp_footer hasn't fired yet but
			// we want the script available the moment the first
			// <iconify-icon> element appears in the DOM so the custom
			// element registers before the browser tries to render it.
			wp_print_scripts( array( 'pcptpages-iconify-icon' ) );
		}
	}

	/**
	 * Lazy-instantiate the card renderer. One instance per request is
	 * enough; render calls don't carry state between invocations.
	 *
	 * @return PCPTPages_Card_Renderer
	 */
	private function get_renderer() {
		if ( $this->renderer === null ) {
			$this->renderer = new PCPTPages_Card_Renderer();
		}
		return $this->renderer;
	}
}
