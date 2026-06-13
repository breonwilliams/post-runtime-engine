<?php
/**
 * Editor-preview REST endpoint (PostGrid card parity, v1.2.x).
 *
 * Promptless WP's React PostGrid canvas preview can't run PHP, so it can't
 * show PRE post-field metadata the way the server-rendered front end does.
 * This endpoint lets the editor fetch, in ONE batched call, the same
 * position-keyed field HTML that the `aisb_postgrid_card_section` action
 * echoes on the front end — rendered through the very same
 * PCPTPages_Card_Renderer, so the canvas matches the front end by construction.
 *
 * Promptless stays unaware of PRE: it exposes the generic
 * `aisb.postgrid.cardMetadataProvider` JS hook; PRE's editor script registers
 * a provider that calls this endpoint. See docs/POSTGRID_PREVIEW_PARITY.md.
 *
 * Editor-authenticated (`edit_posts` + REST cookie nonce). NOT the Cowork
 * connector surface — distinct `/editor/` route, no connector enable toggle.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched card-field-HTML endpoint for the editor preview.
 */
class PCPTPages_Editor_Preview_API {

	/**
	 * Card positions, in the front-end semantic order.
	 *
	 * @var string[]
	 */
	const POSITIONS = array(
		'image_overlay',
		'headline',
		'subtitle',
		'meta_strip',
		'footer_meta',
	);

	/**
	 * Hard cap on post IDs per request — bounds the response and matches the
	 * realistic working set of a single editor preview (one page of cards).
	 */
	const MAX_IDS = 50;

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_provider' ) );
	}

	/**
	 * Enqueue the provider script on the Promptless editor screen.
	 *
	 * Promptless's isolated editor template fires admin_enqueue_scripts with
	 * the 'admin_page_aisb-editor' hook and prints the script queue
	 * (wp_print_scripts), specifically to preserve third-party enqueueing — so
	 * a standard enqueue here reaches the editor. When the Promptless editor
	 * isn't the screen in use, the hook never matches and nothing loads.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_provider( $hook ) {
		if ( 'admin_page_aisb-editor' !== $hook ) {
			return;
		}

		// Styling parity: the injected card metadata uses PRE's `pre-field` /
		// `pre-card-fields__position` classes, which cards.css styles (scoped
		// via `:is(.pre-card-fields--card, .aisb-features__item) .pre-field`).
		// On the front end this is enqueued during card render; the editor
		// canvas needs it too or the metadata inherits default fonts/weights
		// and won't match the front end. Same handle/path as the front end.
		if ( ! wp_style_is( 'pcptpages-cards', 'registered' ) ) {
			wp_register_style(
				'pcptpages-cards',
				PCPTPages_PLUGIN_URL . 'assets/css/cards.css',
				array(),
				PCPTPages_VERSION
			);
		}
		wp_enqueue_style( 'pcptpages-cards' );

		// Iconify web component — required for meta_pair display types whose
		// <iconify-icon> tags otherwise stay empty placeholders.
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

		$handle = 'pcptpages-postgrid-preview';
		wp_enqueue_script(
			$handle,
			PCPTPages_PLUGIN_URL . 'assets/js/postgrid-preview-provider.js',
			array( 'wp-hooks' ),
			PCPTPages_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'pcptpagesPreview',
			array(
				'endpoint' => esc_url_raw( rest_url( 'post-runtime/v1/editor/postgrid-card-preview' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'post-runtime/v1',
			'/editor/postgrid-card-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'post_ids' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * Editor permission: any user who can edit posts. The REST cookie nonce
	 * (X-WP-Nonce) is validated by core before this runs.
	 *
	 * @return bool
	 */
	public function permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Handle the batched request.
	 *
	 * Returns a map: { "<post_id>": { "<position>": "<html>", ... }, ... }.
	 * Only PRE-managed post types with rendered fields appear; everything
	 * else is omitted (the editor renders the base card for those).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$ids = $request->get_param( 'post_ids' );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		$ids = array_filter( array_map( 'absint', $ids ) );
		$ids = array_values( array_unique( $ids ) );
		$ids = array_slice( $ids, 0, self::MAX_IDS );

		$out    = array();
		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->cpts ) {
			return rest_ensure_response( $out );
		}

		$renderer = new PCPTPages_Card_Renderer( $plugin->post_fields, $plugin->post_data );

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			// Non-PRE post types contribute nothing — the editor keeps the
			// base card for them.
			if ( ! $plugin->cpts->exists( $post->post_type ) ) {
				continue;
			}

			$positions = array();
			foreach ( self::POSITIONS as $position ) {
				$html = $renderer->render_position_html( $id, $position, 'card' );
				if ( is_string( $html ) && $html !== '' ) {
					$positions[ $position ] = $html;
				}
			}

			if ( ! empty( $positions ) ) {
				$out[ (string) $id ] = $positions;
			}
		}

		return rest_ensure_response( $out );
	}
}
