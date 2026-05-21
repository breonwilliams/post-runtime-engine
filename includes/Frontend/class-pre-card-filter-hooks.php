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
 * PRE_Card_Renderer::render_position_html().
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
class PRE_Card_Filter_Hooks {

	/**
	 * Memoized card renderer for the request. Lazily instantiated on
	 * first need.
	 *
	 * @var PRE_Card_Renderer|null
	 */
	private $renderer = null;

	/**
	 * Tracks whether cards.css has been enqueued during this request.
	 * Set when the first relevant action fires. Avoids redundant enqueue
	 * checks per card.
	 *
	 * @var bool
	 */
	private $css_enqueued = false;

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
		$plugin = function_exists( 'pre' ) ? pre() : null;
		if ( ! $plugin || ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Defer CSS enqueue until we know we'll actually render something
		// — saves the stylesheet load on cards that have no fields. We
		// still need this BEFORE the render call so the styles are queued
		// before any inline output, but only conditional on us being about
		// to render fields for a managed CPT.
		$this->maybe_enqueue_css();

		$renderer = $this->get_renderer();
		$html     = $renderer->render_position_html( $post_id, $position, 'card' );

		if ( $html === '' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_position_html returns pre-escaped HTML.
		echo $html;
	}

	/**
	 * Enqueue cards.css if it hasn't been queued for this request yet.
	 * Idempotent — the per-request flag plus wp_style_is() inside the
	 * actual enqueue call mean this is safe to invoke from any hook firing.
	 */
	private function maybe_enqueue_css() {
		if ( $this->css_enqueued ) {
			return;
		}
		$this->css_enqueued = true;

		// The action fires inside the body of the response — too late for
		// wp_enqueue_style() to add a <link> in <head>. Print the
		// stylesheet inline at the point of first use. This is a known WP
		// pattern for late-discovered CSS dependencies.
		if ( wp_style_is( 'pre-cards', 'registered' ) || wp_style_is( 'pre-cards', 'enqueued' ) ) {
			// Already loaded via PRE_Frontend_Assets (e.g. on a registered
			// CPT single that contains a PostGrid section). Nothing to do.
			return;
		}

		// Print directly. The stylesheet is small (~17KB) so inlining late
		// is acceptable; the alternative (a header pre-scan) is more
		// complex and only saves bytes when no fields render.
		printf(
			'<link rel="stylesheet" id="pre-cards-late-css" href="%s?ver=%s" media="all">',
			esc_url( PRE_PLUGIN_URL . 'assets/css/cards.css' ),
			esc_attr( PRE_VERSION )
		);
	}

	/**
	 * Lazy-instantiate the card renderer. One instance per request is
	 * enough; render calls don't carry state between invocations.
	 *
	 * @return PRE_Card_Renderer
	 */
	private function get_renderer() {
		if ( $this->renderer === null ) {
			$this->renderer = new PRE_Card_Renderer();
		}
		return $this->renderer;
	}
}
