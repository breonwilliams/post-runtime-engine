<?php
/**
 * SEO meta tags for PRE-rendered CPT single pages.
 *
 * PRE owns the CPT single pages it renders, so it is responsible for their
 * document-level SEO tags — the standard `<meta name="description">` plus a
 * compact OpenGraph/Twitter set for link previews. Promptless WP's
 * SocialMetaTags only fires on pages that carry `_aisb_sections`; a PRE CPT
 * single (a real-estate listing, a practice area, an attorney bio, an event)
 * has none, so without this emitter those pages ship with no meta description
 * at all and Lighthouse/PageSpeed flags "Document does not have a meta
 * description."
 *
 * Mirrors PCPTPages_Event_Schema's `wp_head` pattern to keep the one-way
 * decoupling intact: PRE works standalone, has no PHP dependency on Promptless,
 * and defers cleanly to whoever else owns the head.
 *
 * Emits only when:
 *   - the request is a singular view of a PRE-registered CPT,
 *   - `_aisb_enabled` is NOT set on the post (Promptless precedence — a
 *     flagship page hand-built in Promptless owns its own head and already
 *     emits these tags), and
 *   - no dedicated SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress, The SEO
 *     Framework, Slim SEO) is active — those own the document head wholesale
 *     and emit their own description, so we must not double-emit.
 *
 * The description is derived from the post excerpt when present, else from the
 * post content, trimmed to a search-friendly length. Title and featured image
 * feed the OG/Twitter tags. Everything is filterable for site-level overrides.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and prints the document meta tags for PRE CPT singles.
 */
class PCPTPages_Meta_Tags {

	/**
	 * Soft character cap for the meta description. Descriptions are trimmed to
	 * a whole word at or before this length. ~155 chars is the commonly-cited
	 * safe length before search engines truncate.
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX_CHARS = 155;

	/**
	 * Register the wp_head emitter.
	 *
	 * Priority 1 so the description sits high in <head>, before Promptless's
	 * theme-level tags and PRE's own Event JSON-LD (priority 20).
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'emit' ), 1 );
	}

	/**
	 * wp_head callback. Gate, build, filter, print.
	 *
	 * @return void
	 */
	public function emit() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$plugin = function_exists( 'pcptpages' ) ? pcptpages() : null;
		if ( ! $plugin || ! $plugin->cpts || ! $plugin->cpts->exists( $post->post_type ) ) {
			return;
		}

		// Promptless precedence: a page hand-built in Promptless owns its head
		// and already emits description + OG/Twitter via SocialMetaTags.
		if ( get_post_meta( $post->ID, '_aisb_enabled', true ) ) {
			return;
		}

		// A dedicated SEO plugin owns the head wholesale — never double-emit.
		if ( self::is_seo_plugin_active() ) {
			return;
		}

		$description = self::get_description( $post->ID );
		$title       = wp_strip_all_tags( get_the_title( $post->ID ) );
		$url         = get_permalink( $post->ID );
		$image       = get_the_post_thumbnail_url( $post->ID, 'large' );
		$site_name   = get_bloginfo( 'name' );

		/**
		 * Filter the assembled meta-tag values before they are printed.
		 *
		 * Return an empty description to suppress the `<meta name="description">`
		 * tag while still emitting the OG/Twitter set; return an entirely empty
		 * array of values to suppress everything.
		 *
		 * @param array   $values  { description, title, url, image, site_name }.
		 * @param int     $post_id The CPT post ID.
		 * @param WP_Post $post    The post object.
		 */
		$values = apply_filters(
			'pcptpages_meta_tags',
			array(
				'description' => $description,
				'title'       => $title,
				'url'         => $url,
				'image'       => $image,
				'site_name'   => $site_name,
			),
			$post->ID,
			$post
		);

		if ( empty( $values ) || ! is_array( $values ) ) {
			return;
		}

		$description = isset( $values['description'] ) ? (string) $values['description'] : '';
		$title       = isset( $values['title'] ) ? (string) $values['title'] : '';
		$url         = isset( $values['url'] ) ? (string) $values['url'] : '';
		$image       = ! empty( $values['image'] ) ? (string) $values['image'] : '';
		$site_name   = isset( $values['site_name'] ) ? (string) $values['site_name'] : '';

		$out = '';

		// Standard meta description (the audit target).
		if ( $description !== '' ) {
			$out .= '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		// OpenGraph — link previews on Facebook, LinkedIn, Slack, iMessage, etc.
		if ( $title !== '' ) {
			$out .= '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description !== '' ) {
			$out .= '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		$out .= '<meta property="og:type" content="article" />' . "\n";
		if ( $url !== '' ) {
			$out .= '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( $site_name !== '' ) {
			$out .= '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		}
		if ( $image !== '' ) {
			$out .= '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		// Twitter card.
		$out .= '<meta name="twitter:card" content="' . ( $image !== '' ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
		if ( $title !== '' ) {
			$out .= '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description !== '' ) {
			$out .= '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $image !== '' ) {
			$out .= '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		echo "\n" . $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each value escaped above.
	}

	/**
	 * Resolve a post's meta description.
	 *
	 * Prefers a hand-written excerpt; otherwise derives one from the post
	 * content. Strips tags/shortcodes, collapses whitespace, and trims to a
	 * whole word at or before DESCRIPTION_MAX_CHARS.
	 *
	 * @param int $post_id Post ID.
	 * @return string Description, or '' when nothing usable exists.
	 */
	public static function get_description( $post_id ) {
		if ( has_excerpt( $post_id ) ) {
			$raw = get_the_excerpt( $post_id );
		} else {
			$content = (string) get_post_field( 'post_content', $post_id );
			$content = strip_shortcodes( $content );
			$content = wp_strip_all_tags( $content );
			$raw     = wp_trim_words( $content, 40, '' );
		}

		$raw = trim( wp_strip_all_tags( (string) $raw ) );
		$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );

		$description = self::truncate_on_word( $raw, self::DESCRIPTION_MAX_CHARS );

		/**
		 * Filter the resolved meta description string.
		 *
		 * @param string $description The resolved description.
		 * @param int    $post_id     The post ID.
		 */
		return (string) apply_filters( 'pcptpages_meta_description', $description, $post_id );
	}

	/**
	 * Truncate a string to a whole word at or before $max chars, appending an
	 * ellipsis when the string was actually shortened.
	 *
	 * @param string $text Text to truncate.
	 * @param int    $max  Max characters (before the ellipsis).
	 * @return string
	 */
	private static function truncate_on_word( $text, $max ) {
		$text = (string) $text;
		if ( $text === '' || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$slice = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $slice, ' ' );
		if ( $space !== false && $space > 0 ) {
			$slice = mb_substr( $slice, 0, $space );
		}
		return rtrim( $slice, " \t\n\r\0\x0B.,;:" ) . '…';
	}

	/**
	 * Detect whether a dedicated SEO plugin is managing the document head.
	 *
	 * When one is active it emits its own description/OG/Twitter tags for every
	 * page including our CPT singles, so PRE must stay silent to avoid duplicate
	 * tags. Covers the plugins that own the head wholesale; extend via the
	 * `pcptpages_seo_plugin_active` filter for anything not listed.
	 *
	 * @return bool
	 */
	public static function is_seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )                 // Yoast SEO.
			|| defined( 'RANK_MATH_VERSION' )                 // Rank Math.
			|| defined( 'AIOSEO_VERSION' )                    // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )                  // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' )         // The SEO Framework.
			|| defined( 'SLIM_SEO_VER' );                     // Slim SEO.

		/**
		 * Filter whether a dedicated SEO plugin is considered active.
		 *
		 * @param bool $active Whether an SEO plugin owns the head.
		 */
		return (bool) apply_filters( 'pcptpages_seo_plugin_active', $active );
	}
}
