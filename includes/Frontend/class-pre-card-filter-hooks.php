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
		$plugin = function_exists( 'pre' ) ? pcptpages() : null;
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
		$plugin = function_exists( 'pre' ) ? pcptpages() : null;
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
