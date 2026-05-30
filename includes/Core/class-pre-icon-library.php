<?php
/**
 * Icon library for Promptless CPT Pages.
 *
 * Curated icon registry. Each icon is referenced by a stable string ID and
 * grouped under a human-readable category (rendered as <optgroup> labels in
 * the admin dropdown). The actual SVG markup is rendered at output time.
 *
 * The library is extensible via the `pcptpages_icon_library` filter — a theme
 * developer or third-party plugin can add their own icons without forking
 * this plugin. Adding icons via filter is the supported extension path.
 *
 * SVG markup is intentionally minimal and uses currentColor so icons
 * inherit the surrounding text color through the design tokens — no
 * per-icon color logic needed at render time.
 *
 * ----------------------------------------------------------------------
 * Curation philosophy: when to add a built-in icon
 * ----------------------------------------------------------------------
 *
 * The built-in set is deliberately bounded. We add an icon if it meets one
 * of the following bars:
 *
 *   1. **Cross-industry universal.** Star, check, calendar, phone — every
 *      industry needs these. ~100% utility for ~100% of installs.
 *
 *   2. **Single-industry specific but high-frequency within that industry.**
 *      `bed` / `bath` / `ruler` for real estate. `gavel` / `scale` for law.
 *      `stethoscope` / `pill` for medical. Adding these once means
 *      every install in those industries gets the right icon out of the box.
 *
 *   3. **Common service-business primitive.** Briefcase, award, dollar,
 *      shield — useful across many industries that share a "professional
 *      services" presentation pattern.
 *
 * We do NOT add icons for:
 *
 *   - Logos or brand-specific marks (Twitter, Facebook, Slack icons live in
 *     a separate "social" extension if added later).
 *   - Niche industry verticals with <3 expected installs in foreseeable
 *     pipeline (e.g. embroidery-specific tooling icons).
 *   - Decorative variations of icons we already have (single "heart" is
 *     enough; we don't ship `heart-filled`, `heart-broken`, etc.).
 *
 * If a client needs a niche icon, the right path is the `pcptpages_icon_library`
 * filter at the theme/site level. Site-specific extensions don't belong in
 * the plugin's built-in set.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static icon registry.
 *
 * ----------------------------------------------------------------------
 * Iconify support (added 2026-05-15)
 * ----------------------------------------------------------------------
 *
 * The plugin now accepts ANY Iconify icon code (≈200,000 icons across 100+
 * sets) anywhere the curated set was previously accepted. Both representations
 * are valid in storage:
 *
 *   - **Legacy curated ID** — e.g. `home`, `users`, `briefcase`. Bound to
 *     the 53 inline SVGs declared in default_icons() below. Renders as a
 *     `<span aria-hidden><svg>...</svg></span>` blob.
 *
 *   - **Iconify code** — e.g. `mdi:home`, `logos:wordpress`, `material-symbols:business`.
 *     `collection:name` format (sanitize_key-safe characters on both sides
 *     of a single colon). Renders as a `<iconify-icon icon="..."></iconify-icon>`
 *     web component. The web component fetches the SVG from api.iconify.design
 *     on first paint (cached aggressively).
 *
 * Why both? The 53 curated icons stay because (a) they ship inline so there's
 * no network request, (b) existing post data referencing them must continue
 * to render after the upgrade, and (c) hand-authoring an "agency-built
 * directory site" with the picker UI is faster when the most common picks are
 * one click away. Iconify joins because connector-driven workflows (Claude
 * building pages from a brief) need access to any icon the content calls for
 * — a WordPress site, a stripe-payment icon, an industry-specific glyph that
 * isn't in the curated 53.
 *
 * The legacy IDs are TRANSPARENTLY MAPPED to Iconify equivalents via
 * `legacy_to_iconify()` so the public surface stays consistent: callers
 * pass `home` and get either the inline curated SVG OR (via
 * `as_iconify_code('home')` → `mdi:home`) the Iconify web component. The
 * inline curated SVG is preferred when both are available — same render
 * cost, no network.
 */
class PCPTPages_Icon_Library {

	/**
	 * Memoized icon set. Built lazily on first access; rebuilt only if the
	 * filter is re-applied (it is not, but caching here keeps repeated
	 * lookups O(1) without forcing every consumer to memoize separately).
	 *
	 * @var array<string,array>|null
	 */
	private static $cache = null;

	/**
	 * Legacy curated-ID → Iconify-code mapping. Lets connector consumers
	 * write either `home` or `mdi:home` and both render identically (the
	 * legacy path wins because the SVG ships inline). The mapping target
	 * is also what the admin meta box's quick-pick row inserts when the
	 * user clicks one of the curated icons.
	 *
	 * Picked from Material Design Icons (`mdi:*`) for cross-set visual
	 * consistency — the curated SVGs were originally drawn in a
	 * Lucide / Heroicons-inspired aesthetic; mdi shares that 24px line
	 * style closer than the official Lucide Iconify set's rounded corners.
	 *
	 * @var array<string,string>
	 */
	private const LEGACY_TO_ICONIFY = array(
		// General-purpose primitives.
		'check'       => 'mdi:check',
		'star'        => 'mdi:star',
		'heart'       => 'mdi:heart',
		'info'        => 'mdi:information-outline',
		'arrow-right' => 'mdi:arrow-right',
		'shield'      => 'mdi:shield',
		// Property & real estate.
		'bed'         => 'mdi:bed',
		'bath'        => 'mdi:bathtub-outline',
		'ruler'       => 'mdi:ruler',
		'home'        => 'mdi:home',
		'car'         => 'mdi:car',
		'pool'        => 'mdi:pool',
		'building'    => 'mdi:office-building',
		'key'         => 'mdi:key',
		// Business & legal.
		'scale'       => 'mdi:scale-balance',
		'gavel'       => 'mdi:gavel',
		'briefcase'   => 'mdi:briefcase',
		'award'       => 'mdi:trophy-award',
		// Education.
		'graduation'  => 'mdi:school',
		'book'        => 'mdi:book-open-variant',
		'lightbulb'   => 'mdi:lightbulb-on-outline',
		// Communication.
		'phone'       => 'mdi:phone',
		'mail'        => 'mdi:email',
		'message'     => 'mdi:message-text',
		// Location & time.
		'map-pin'     => 'mdi:map-marker',
		'globe'       => 'mdi:earth',
		'compass'     => 'mdi:compass',
		'calendar'    => 'mdi:calendar',
		'clock'       => 'mdi:clock-outline',
		// Commerce.
		'dollar'      => 'mdi:currency-usd',
		'tag'         => 'mdi:tag',
		// People.
		'user'        => 'mdi:account',
		'users'       => 'mdi:account-group',
		// Food & hospitality.
		'utensils'    => 'mdi:silverware-fork-knife',
		'coffee'      => 'mdi:coffee',
		'wine'        => 'mdi:glass-wine',
		'chef-hat'    => 'mdi:chef-hat',
		// Medical & health.
		'stethoscope' => 'mdi:stethoscope',
		'heart-pulse' => 'mdi:heart-pulse',
		'pill'        => 'mdi:pill',
		'tooth'       => 'mdi:tooth-outline',
		'hospital'    => 'mdi:hospital-building',
		// Creative & media.
		'camera'      => 'mdi:camera',
		'palette'     => 'mdi:palette',
		'code'        => 'mdi:code-tags',
		'image'       => 'mdi:image',
		'pen-tool'    => 'mdi:fountain-pen',
		// Fitness & wellness.
		'dumbbell'    => 'mdi:dumbbell',
		'leaf'        => 'mdi:leaf',
		'water-drop'  => 'mdi:water',
		// Travel.
		'airplane'    => 'mdi:airplane',
		'suitcase'    => 'mdi:bag-suitcase',
		'mountain'    => 'mdi:terrain',
	);

	/**
	 * Iconify-code format pattern. Strict on purpose — we don't accept
	 * arbitrary strings (which would let typos / garbage through), only
	 * the canonical `collection:name` shape where each side is a
	 * sanitize_key-safe slug (lowercase letters, digits, hyphens,
	 * underscores). One colon separator. No whitespace.
	 *
	 * Hyphen support is REQUIRED — Iconify icon names routinely use hyphens
	 * (e.g. `mdi:account-group`, `material-symbols:business-outline`,
	 * `heroicons:arrow-right-circle`). sanitize_key would strip those, so
	 * we validate via regex instead.
	 *
	 * @var string
	 */
	private const ICONIFY_FORMAT_PATTERN = '/^[a-z0-9][a-z0-9_-]*:[a-z0-9][a-z0-9_-]*$/';

	/**
	 * Max accepted length of an icon identifier (combined collection:name).
	 * Iconify codes in the wild top out around ~50 chars; 100 is a
	 * comfortable ceiling that blocks obvious abuse (someone pasting a JWT
	 * into the field) without rejecting legitimate codes.
	 */
	const MAX_ICONIFY_LENGTH = 100;

	/**
	 * Get the full icon registry, keyed by icon ID.
	 *
	 * Each icon entry is an associative array with:
	 * - `id`       (string) Stable ID used in grouping items.
	 * - `label`    (string) Human-readable label for admin UI.
	 * - `svg`      (string) Inline SVG markup. Uses currentColor; no fill/stroke
	 *                       hard-coded. Render-ready.
	 * - `tags`     (array)  Free-text search tags for the admin icon picker.
	 * - `category` (string) Category label for <optgroup> grouping in the
	 *                       admin dropdown.
	 *
	 * @return array<string,array>
	 */
	public static function get_all() {
		if ( self::$cache !== null ) {
			return self::$cache;
		}

		$icons = self::default_icons();

		/**
		 * Filter the icon library.
		 *
		 * Theme developers and third-party plugins can add or remove icons
		 * here. Each icon must have id, label, svg, tags, and category fields.
		 * Removing a built-in icon is allowed but discouraged (it may break
		 * existing post data referencing the removed ID — the validator will
		 * reject future writes but existing posts will fall back to no-icon
		 * rendering).
		 *
		 * @param array<string,array> $icons Default icon set keyed by ID.
		 */
		$filtered = apply_filters( 'pcptpages_icon_library', $icons );

		// Defensive normalization: skip malformed entries rather than crash.
		$result = array();
		foreach ( $filtered as $id => $icon ) {
			if ( ! is_string( $id ) || sanitize_key( $id ) !== $id ) {
				continue;
			}
			if ( ! is_array( $icon ) || empty( $icon['svg'] ) || ! is_string( $icon['svg'] ) ) {
				continue;
			}

			$result[ $id ] = array(
				'id'       => $id,
				'label'    => isset( $icon['label'] ) && is_string( $icon['label'] ) ? $icon['label'] : $id,
				'svg'      => $icon['svg'],
				'tags'     => isset( $icon['tags'] ) && is_array( $icon['tags'] ) ? array_values( array_filter( $icon['tags'], 'is_string' ) ) : array(),
				'category' => isset( $icon['category'] ) && is_string( $icon['category'] ) && $icon['category'] !== '' ? $icon['category'] : __( 'General', 'promptless-cpt-pages' ),
			);
		}

		self::$cache = $result;
		return $result;
	}

	/**
	 * Get icons grouped by category, preserving the source order so categories
	 * appear in the order their first icon was declared. Useful for rendering
	 * <optgroup>-grouped dropdowns.
	 *
	 * @return array<string,array<string,array>> Map of category => (id => icon).
	 */
	public static function get_grouped_by_category() {
		$icons   = self::get_all();
		$grouped = array();

		foreach ( $icons as $id => $icon ) {
			$cat = $icon['category'];
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = array();
			}
			$grouped[ $cat ][ $id ] = $icon;
		}

		return $grouped;
	}

	/**
	 * Check whether an icon ID is registered.
	 *
	 * @param string $id Icon ID.
	 * @return bool
	 */
	public static function has( $id ) {
		if ( ! is_string( $id ) || $id === '' ) {
			return false;
		}
		$icons = self::get_all();
		return isset( $icons[ $id ] );
	}

	/**
	 * Get a single icon entry by ID.
	 *
	 * @param string $id Icon ID.
	 * @return array|null Icon entry or null if not registered.
	 */
	public static function get( $id ) {
		$icons = self::get_all();
		return isset( $icons[ $id ] ) ? $icons[ $id ] : null;
	}

	/**
	 * Render an icon. Returns an empty string for unrecognized IDs so
	 * callers can safely echo without conditional checks.
	 *
	 * Resolution order:
	 *   1. If the ID matches a legacy curated icon → return inline SVG span.
	 *   2. If the ID parses as an Iconify code (`collection:name`) →
	 *      return `<iconify-icon>` web component.
	 *   3. Otherwise → return empty string.
	 *
	 * Legacy IDs win when both representations are available — the inline
	 * SVG ships with the plugin (no network), while Iconify codes fetch
	 * the SVG from api.iconify.design on first paint (cached after that).
	 *
	 * @param string $id    Icon ID (legacy curated name OR Iconify code).
	 * @param string $class Optional CSS class to add to the wrapper.
	 * @return string Render-ready HTML or empty string.
	 */
	public static function render( $id, $class = '' ) {
		if ( ! is_string( $id ) || $id === '' ) {
			return '';
		}

		// Legacy curated icon — inline SVG path.
		$icon = self::get( $id );
		if ( $icon ) {
			$class_attr = '';
			if ( $class !== '' ) {
				$class_attr = ' class="' . esc_attr( $class ) . '"';
			}
			// Wrap in a span with aria-hidden=true; the heading carries semantic meaning.
			return '<span' . $class_attr . ' aria-hidden="true">' . $icon['svg'] . '</span>';
		}

		// Iconify code — web component path. The PCPTPages_Frontend_Assets enqueue
		// loads the iconify-icon JS module from the jsdelivr CDN when any
		// grouping item has a non-empty icon_id, so the component is
		// available by the time the page paints.
		if ( self::is_iconify_format( $id ) ) {
			$class_attr = '';
			if ( $class !== '' ) {
				$class_attr = ' class="' . esc_attr( $class ) . '"';
			}
			return '<iconify-icon icon="' . esc_attr( $id ) . '"' . $class_attr . ' aria-hidden="true"></iconify-icon>';
		}

		return '';
	}

	/**
	 * Check whether a string parses as an Iconify icon code.
	 *
	 * @param string $value Candidate string.
	 * @return bool True when the value has the canonical `collection:name`
	 *              shape AND fits within MAX_ICONIFY_LENGTH.
	 */
	public static function is_iconify_format( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return false;
		}
		if ( strlen( $value ) > self::MAX_ICONIFY_LENGTH ) {
			return false;
		}
		return (bool) preg_match( self::ICONIFY_FORMAT_PATTERN, $value );
	}

	/**
	 * Translate a legacy curated ID to its Iconify equivalent.
	 *
	 * Returns the legacy ID's mapped Iconify code if known; returns the
	 * input untouched if it's already an Iconify code; returns an empty
	 * string for unknown values.
	 *
	 * Used by the admin meta-box quick-pick row (which stores Iconify
	 * codes going forward) and by code paths that want to normalize
	 * legacy IDs without forcing a write.
	 *
	 * @param string $id Legacy curated ID OR Iconify code.
	 * @return string Iconify code OR empty string.
	 */
	public static function legacy_to_iconify( $id ) {
		if ( ! is_string( $id ) || $id === '' ) {
			return '';
		}
		if ( isset( self::LEGACY_TO_ICONIFY[ $id ] ) ) {
			return self::LEGACY_TO_ICONIFY[ $id ];
		}
		if ( self::is_iconify_format( $id ) ) {
			return $id;
		}
		return '';
	}

	/**
	 * Check whether an icon identifier is recognized by ANY supported path.
	 *
	 * Returns true for both legacy curated IDs and well-formed Iconify
	 * codes. Iconify codes are not verified against the Iconify registry
	 * because (a) that would require a network call on every validate
	 * (the registry is fetched from api.iconify.design at render time
	 * with graceful fallback for typos), and (b) Iconify ships missing-
	 * icon fallback SVGs so a typo renders a placeholder rather than
	 * crashing the page.
	 *
	 * Replaces the original `has()` check at validator + renderer call
	 * sites; the old `has()` is kept for narrow "is this in the curated
	 * library" checks.
	 *
	 * @param string $id Icon ID.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		if ( ! is_string( $id ) || $id === '' ) {
			return false;
		}
		if ( self::has( $id ) ) {
			return true;
		}
		return self::is_iconify_format( $id );
	}

	/**
	 * Return the full legacy → Iconify mapping. Consumed by the admin
	 * quick-pick row, the connector's `postruntime_list_icons` response
	 * (so AI consumers know the curated palette in the Iconify dialect),
	 * and the docs auto-generator.
	 *
	 * @return array<string,string>
	 */
	public static function get_legacy_iconify_map() {
		return self::LEGACY_TO_ICONIFY;
	}

	/**
	 * Reset the memo cache. Useful in tests when filters are mutated.
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Built-in icon set. Authored fresh in a Lucide / Heroicons-inspired
	 * aesthetic — minimal 24x24 line icons, currentColor, 2px stroke. Source
	 * order matters: it determines the order categories and items appear in
	 * the admin dropdown.
	 *
	 * @return array<string,array>
	 */
	private static function default_icons() {
		// SVG attribute helper for consistency.
		$attrs = 'width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

		$general    = __( 'General', 'promptless-cpt-pages' );
		$property   = __( 'Property & Real Estate', 'promptless-cpt-pages' );
		$prof       = __( 'Business & Legal', 'promptless-cpt-pages' );
		$education  = __( 'Education', 'promptless-cpt-pages' );
		$comm       = __( 'Communication', 'promptless-cpt-pages' );
		$location   = __( 'Location & Time', 'promptless-cpt-pages' );
		$commerce   = __( 'Commerce', 'promptless-cpt-pages' );
		$people     = __( 'People', 'promptless-cpt-pages' );
		$food       = __( 'Food & Hospitality', 'promptless-cpt-pages' );
		$medical    = __( 'Medical & Health', 'promptless-cpt-pages' );
		$creative   = __( 'Creative & Media', 'promptless-cpt-pages' );
		$wellness   = __( 'Fitness & Wellness', 'promptless-cpt-pages' );
		$travel     = __( 'Travel', 'promptless-cpt-pages' );

		return array(
			// ============================================================
			// General-purpose primitives.
			// ============================================================
			'check'       => array(
				'category' => $general,
				'label'    => __( 'Check', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><polyline points="20 6 9 17 4 12"/></svg>',
				'tags'     => array( 'tick', 'success', 'done', 'complete' ),
			),
			'star'        => array(
				'category' => $general,
				'label'    => __( 'Star', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
				'tags'     => array( 'rating', 'favorite', 'highlight' ),
			),
			'heart'       => array(
				'category' => $general,
				'label'    => __( 'Heart', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
				'tags'     => array( 'love', 'like', 'favorite' ),
			),
			'info'        => array(
				'category' => $general,
				'label'    => __( 'Info', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
				'tags'     => array( 'information', 'detail' ),
			),
			'arrow-right' => array(
				'category' => $general,
				'label'    => __( 'Arrow Right', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
				'tags'     => array( 'next', 'forward', 'continue' ),
			),
			'shield'      => array(
				'category' => $general,
				'label'    => __( 'Shield', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
				'tags'     => array( 'security', 'trust', 'protection', 'guarantee' ),
			),

			// ============================================================
			// Property & real estate.
			// ============================================================
			'bed'         => array(
				'category' => $property,
				'label'    => __( 'Bed', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M2 4v16"/><path d="M22 4v16"/><path d="M2 8h20"/><path d="M2 12h20"/><path d="M6 8V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"/></svg>',
				'tags'     => array( 'bedroom', 'sleep', 'real-estate', 'hotel' ),
			),
			'bath'        => array(
				'category' => $property,
				'label'    => __( 'Bath', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-2.12 0l-.79.79a1.5 1.5 0 0 0 0 2.12L6 9"/><line x1="22" y1="12" x2="2" y2="12"/><path d="M5 12v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>',
				'tags'     => array( 'bathroom', 'shower', 'real-estate' ),
			),
			'ruler'       => array(
				'category' => $property,
				'label'    => __( 'Ruler', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m21 3-3 3-9-9"/><path d="m12 6 3 3"/><path d="m9 9 3 3"/><path d="m6 12 3 3"/><path d="m3 15 3 3"/><path d="m21 3-18 18"/></svg>',
				'tags'     => array( 'measure', 'size', 'sqft', 'real-estate' ),
			),
			'home'        => array(
				'category' => $property,
				'label'    => __( 'Home', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
				'tags'     => array( 'house', 'property', 'real-estate' ),
			),
			'car'         => array(
				'category' => $property,
				'label'    => __( 'Car', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>',
				'tags'     => array( 'garage', 'vehicle', 'parking', 'real-estate' ),
			),
			'pool'        => array(
				'category' => $property,
				'label'    => __( 'Pool', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M2 12h20"/><path d="M5 8c1.7 0 1.7-2 3.5-2S10.2 8 12 8s1.7-2 3.5-2 1.8 2 3.5 2"/><path d="M5 16c1.7 0 1.7-2 3.5-2s1.7 2 3.5 2 1.7-2 3.5-2 1.8 2 3.5 2"/></svg>',
				'tags'     => array( 'swimming', 'water', 'amenity' ),
			),
			'building'    => array(
				'category' => $property,
				'label'    => __( 'Building', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
				'tags'     => array( 'office', 'commercial', 'real-estate', 'apartment' ),
			),
			'key'         => array(
				'category' => $property,
				'label'    => __( 'Key', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>',
				'tags'     => array( 'access', 'unlock', 'rental', 'real-estate' ),
			),

			// ============================================================
			// Business & legal.
			// ============================================================
			'scale'       => array(
				'category' => $prof,
				'label'    => __( 'Scales of Justice', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>',
				'tags'     => array( 'law', 'justice', 'attorney' ),
			),
			'gavel'       => array(
				'category' => $prof,
				'label'    => __( 'Gavel', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m14 13-7.5 7.5c-.83.83-2.17.83-3 0 0 0 0 0 0 0a2.12 2.12 0 0 1 0-3L11 10"/><path d="m16 16 6-6"/><path d="m8 8 6-6"/><path d="m9 7 8 8"/><path d="m21 11-8-8"/></svg>',
				'tags'     => array( 'court', 'judge', 'verdict', 'law' ),
			),
			'briefcase'   => array(
				'category' => $prof,
				'label'    => __( 'Briefcase', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
				'tags'     => array( 'business', 'work', 'job', 'professional' ),
			),
			'award'       => array(
				'category' => $prof,
				'label'    => __( 'Award', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
				'tags'     => array( 'achievement', 'medal', 'recognition', 'trophy' ),
			),

			// ============================================================
			// Education.
			// ============================================================
			'graduation'  => array(
				'category' => $education,
				'label'    => __( 'Graduation Cap', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>',
				'tags'     => array( 'education', 'degree', 'school', 'university' ),
			),
			'book'        => array(
				'category' => $education,
				'label'    => __( 'Book', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>',
				'tags'     => array( 'reading', 'library', 'course', 'lesson' ),
			),
			'lightbulb'   => array(
				'category' => $education,
				'label'    => __( 'Lightbulb', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>',
				'tags'     => array( 'idea', 'insight', 'innovation', 'tip' ),
			),

			// ============================================================
			// Communication.
			// ============================================================
			'phone'       => array(
				'category' => $comm,
				'label'    => __( 'Phone', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
				'tags'     => array( 'call', 'contact', 'telephone' ),
			),
			'mail'        => array(
				'category' => $comm,
				'label'    => __( 'Email', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
				'tags'     => array( 'email', 'message', 'contact' ),
			),
			'message'     => array(
				'category' => $comm,
				'label'    => __( 'Message', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
				'tags'     => array( 'chat', 'comment', 'speech' ),
			),

			// ============================================================
			// Location & time.
			// ============================================================
			'map-pin'     => array(
				'category' => $location,
				'label'    => __( 'Map Pin', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
				'tags'     => array( 'location', 'address', 'place' ),
			),
			'globe'       => array(
				'category' => $location,
				'label'    => __( 'Globe', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
				'tags'     => array( 'world', 'web', 'international' ),
			),
			'compass'     => array(
				'category' => $location,
				'label'    => __( 'Compass', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>',
				'tags'     => array( 'navigation', 'direction', 'explore' ),
			),
			'calendar'    => array(
				'category' => $location,
				'label'    => __( 'Calendar', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
				'tags'     => array( 'date', 'schedule', 'event' ),
			),
			'clock'       => array(
				'category' => $location,
				'label'    => __( 'Clock', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
				'tags'     => array( 'time', 'duration', 'hour' ),
			),

			// ============================================================
			// Commerce.
			// ============================================================
			'dollar'      => array(
				'category' => $commerce,
				'label'    => __( 'Dollar', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
				'tags'     => array( 'money', 'price', 'cost' ),
			),
			'tag'         => array(
				'category' => $commerce,
				'label'    => __( 'Tag', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/></svg>',
				'tags'     => array( 'label', 'category', 'sale' ),
			),

			// ============================================================
			// People.
			// ============================================================
			'user'        => array(
				'category' => $people,
				'label'    => __( 'User', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
				'tags'     => array( 'person', 'profile', 'account' ),
			),
			'users'       => array(
				'category' => $people,
				'label'    => __( 'Users', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
				'tags'     => array( 'team', 'group', 'people' ),
			),

			// ============================================================
			// Food & hospitality.
			// ============================================================
			'utensils'    => array(
				'category' => $food,
				'label'    => __( 'Utensils', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2v0a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>',
				'tags'     => array( 'food', 'restaurant', 'dining', 'menu' ),
			),
			'coffee'      => array(
				'category' => $food,
				'label'    => __( 'Coffee', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg>',
				'tags'     => array( 'cafe', 'beverage', 'breakfast', 'tea' ),
			),
			'wine'        => array(
				'category' => $food,
				'label'    => __( 'Wine Glass', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/></svg>',
				'tags'     => array( 'bar', 'drink', 'alcohol', 'dining' ),
			),
			'chef-hat'    => array(
				'category' => $food,
				'label'    => __( 'Chef Hat', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/><line x1="6" x2="18" y1="17" y2="17"/></svg>',
				'tags'     => array( 'cooking', 'restaurant', 'chef', 'cuisine' ),
			),

			// ============================================================
			// Medical & health.
			// ============================================================
			'stethoscope' => array(
				'category' => $medical,
				'label'    => __( 'Stethoscope', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/></svg>',
				'tags'     => array( 'doctor', 'medical', 'health', 'physician' ),
			),
			'heart-pulse' => array(
				'category' => $medical,
				'label'    => __( 'Heart Pulse', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/></svg>',
				'tags'     => array( 'cardio', 'medical', 'health', 'vitals' ),
			),
			'pill'        => array(
				'category' => $medical,
				'label'    => __( 'Pill', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>',
				'tags'     => array( 'medicine', 'pharmacy', 'prescription', 'drug' ),
			),
			'tooth'       => array(
				'category' => $medical,
				'label'    => __( 'Tooth', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M12 5.5c-1.074-.586-2.583-1.5-4-1.5-3 0-5 2.5-5 6 0 4.5 2 11 4 11 2.5 0 1.5-5 5-5s2.5 5 5 5c2 0 4-6.5 4-11 0-3.5-2-6-5-6-1.417 0-2.926.914-4 1.5Z"/></svg>',
				'tags'     => array( 'dental', 'dentist', 'oral', 'medical' ),
			),
			'hospital'    => array(
				'category' => $medical,
				'label'    => __( 'Hospital', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M12 6v4"/><path d="M14 14h-4"/><path d="M14 18h-4"/><path d="M14 8h-4"/><path d="M18 12h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2h2"/><path d="M18 22V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v18"/></svg>',
				'tags'     => array( 'clinic', 'medical', 'health', 'emergency' ),
			),

			// ============================================================
			// Creative & media.
			// ============================================================
			'camera'      => array(
				'category' => $creative,
				'label'    => __( 'Camera', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>',
				'tags'     => array( 'photo', 'photography', 'photographer', 'studio' ),
			),
			'palette'     => array(
				'category' => $creative,
				'label'    => __( 'Palette', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
				'tags'     => array( 'art', 'design', 'painter', 'colors' ),
			),
			'code'        => array(
				'category' => $creative,
				'label'    => __( 'Code', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
				'tags'     => array( 'developer', 'programming', 'web', 'software' ),
			),
			'image'       => array(
				'category' => $creative,
				'label'    => __( 'Image', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
				'tags'     => array( 'photo', 'picture', 'gallery', 'portfolio' ),
			),
			'pen-tool'    => array(
				'category' => $creative,
				'label'    => __( 'Pen Tool', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="m2 2 7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>',
				'tags'     => array( 'design', 'illustration', 'vector', 'creative' ),
			),

			// ============================================================
			// Fitness & wellness.
			// ============================================================
			'dumbbell'    => array(
				'category' => $wellness,
				'label'    => __( 'Dumbbell', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M14.4 14.4 9.6 9.6"/><path d="M18.657 21.485a2 2 0 1 1-2.829-2.828l-1.767 1.768a2 2 0 1 1-2.829-2.829l6.364-6.364a2 2 0 1 1 2.829 2.829l-1.768 1.767a2 2 0 1 1 2.828 2.829z"/><path d="m21.5 21.5-1.4-1.4"/><path d="M3.9 3.9 2.5 2.5"/><path d="M6.404 12.768a2 2 0 1 1-2.829-2.829l1.768-1.767a2 2 0 1 1-2.828-2.829l2.828-2.828a2 2 0 1 1 2.829 2.828l1.767-1.768a2 2 0 1 1 2.829 2.829z"/></svg>',
				'tags'     => array( 'gym', 'fitness', 'exercise', 'workout' ),
			),
			'leaf'        => array(
				'category' => $wellness,
				'label'    => __( 'Leaf', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19.2 2.96a1 1 0 0 1 1.6.8c0 3-1.2 6.13-2.5 8.43A8 8 0 0 1 11 20Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>',
				'tags'     => array( 'nature', 'organic', 'eco', 'wellness' ),
			),
			'water-drop'  => array(
				'category' => $wellness,
				'label'    => __( 'Water Drop', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
				'tags'     => array( 'water', 'hydration', 'spa', 'liquid' ),
			),

			// ============================================================
			// Travel.
			// ============================================================
			'airplane'    => array(
				'category' => $travel,
				'label'    => __( 'Airplane', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>',
				'tags'     => array( 'flight', 'travel', 'plane', 'tourism' ),
			),
			'suitcase'    => array(
				'category' => $travel,
				'label'    => __( 'Suitcase', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="M16 20V6a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v14"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>',
				'tags'     => array( 'luggage', 'travel', 'business-trip', 'tourism' ),
			),
			'mountain'    => array(
				'category' => $travel,
				'label'    => __( 'Mountain', 'promptless-cpt-pages' ),
				'svg'      => '<svg ' . $attrs . '><path d="m8 3 4 8 5-5 5 15H2L8 3z"/></svg>',
				'tags'     => array( 'outdoor', 'adventure', 'nature', 'hiking' ),
			),
		);
	}
}
