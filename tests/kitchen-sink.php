<?php
/**
 * Kitchen-sink visual fixture for Post Runtime Engine.
 *
 * Sets up a "pre_demo" CPT plus a curated set of grouping definitions and
 * posts that exercise every layout variant, every position, and every
 * source mode (manual / child_posts / taxonomy_match). Run before doing
 * a visual design review to ensure all combinations render side-by-side
 * against realistic content.
 *
 * Idempotent: re-running the script updates existing fixtures rather
 * than duplicating them. Safe to run repeatedly.
 *
 * USAGE: run via tests/run-kitchen-sink-via-browser.php (browser),
 *        or `wp eval-file tests/kitchen-sink.php` if WP-CLI is set up.
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "ERROR: WordPress is not loaded.\n";
	exit( 2 );
}

if ( ! function_exists( 'pre' ) ) {
	echo "FAIL: pcptpages() global accessor not defined. Activate the plugin first.\n";
	exit( 1 );
}

const PRE_KS_CPT       = 'pre_demo';
const PRE_KS_TAX       = 'pre_demo_area';
const PRE_KS_PARENT    = 'Modern Family Home in Lake View';
const PRE_KS_AREA_TERM = 'lake-view';

$ks_log = array();

function ks_log( $msg ) {
	global $ks_log;
	$ks_log[] = $msg;
	echo $msg . "\n";
}

// ---------------------------------------------------------------------------
// 1. Register the demo CPT (hierarchical, public, full supports).
// ---------------------------------------------------------------------------

$plugin = pcptpages();

$cpt_def = array(
	'slug'           => PRE_KS_CPT,
	'label_singular' => 'Demo Listing',
	'label_plural'   => 'Demo Listings',
	'description'    => 'Kitchen-sink visual fixture covering every variant and source mode.',
	'public'         => true,
	'has_archive'    => true,
	'hierarchical'   => true,
	'show_in_rest'   => true,
	'show_in_menu'   => true,
	'menu_position'  => 26,
	'menu_icon'      => 'dashicons-admin-home',
	'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
	'taxonomies'     => array( PRE_KS_TAX ),
	'capability_type' => 'post',
);

$result = $plugin->cpts->register( PRE_KS_CPT, $cpt_def );
if ( is_wp_error( $result ) ) {
	ks_log( 'FAIL: CPT registration failed — ' . $result->get_error_message() );
	exit( 1 );
}
ks_log( 'OK: registered CPT ' . PRE_KS_CPT );

// Persist the taxonomy registration so it survives across requests. Our
// plugin's `pre_auto_taxonomies` option is replayed on every init by the
// main plugin class. Idempotent: existing entry is overwritten with the
// same shape.
$auto_taxonomies = get_option( 'pre_auto_taxonomies', array() );
if ( ! is_array( $auto_taxonomies ) ) {
	$auto_taxonomies = array();
}
$auto_taxonomies[ PRE_KS_TAX ] = array(
	'object_type' => PRE_KS_CPT,
	'args'        => array(
		'labels'       => array(
			'name'          => 'Demo Areas',
			'singular_name' => 'Demo Area',
		),
		'public'       => true,
		'hierarchical' => false,
		'show_in_rest' => true,
		'show_ui'      => true,
	),
);
update_option( 'pre_auto_taxonomies', $auto_taxonomies );

// Also register it in this request so the term assignment below succeeds.
register_taxonomy(
	PRE_KS_TAX,
	PRE_KS_CPT,
	$auto_taxonomies[ PRE_KS_TAX ]['args']
);
ks_log( 'OK: registered + persisted taxonomy ' . PRE_KS_TAX );

// Re-register all stored CPTs with WordPress so the demo CPT is queryable
// in this same request (otherwise it'd take effect on the next page load).
$plugin->register_post_types();

// Force a rewrite flush right now so pretty permalinks for the new CPT work
// on the very next request. The registry sets a transient flag for this on
// every register()/unregister() call; force-running it here makes the
// fixture self-contained.
flush_rewrite_rules( false );
delete_transient( 'pre_needs_rewrite_flush' );

// ---------------------------------------------------------------------------
// 2. Define groupings — covers all variant × position × source combinations.
// ---------------------------------------------------------------------------

$groupings = array(
	// ABOVE MAIN
	array(
		'key'              => 'at_a_glance',
		'label'            => 'At a glance',
		'description'      => 'Quick spec chips inline at the top of the page.',
		'default_variant'  => 'horizontal-row',
		'default_position' => 'above_main',
	),
	array(
		'key'              => 'highlights',
		'label'            => 'Highlights',
		'description'      => 'What makes this property special.',
		'default_variant'  => 'card-grid',
		'default_position' => 'above_main',
		'supporting_text_required' => true,
	),

	// BELOW MAIN
	array(
		'key'              => 'amenities',
		'label'            => 'Amenities',
		'description'      => 'Icon-and-label list of features.',
		'default_variant'  => 'compact-grid',
		'default_position' => 'below_main',
	),
	array(
		'key'              => 'awards',
		'label'            => 'Awards & recognition',
		'description'      => 'Featured awards and press mentions.',
		'default_variant'  => 'card-grid',
		'default_position' => 'below_main',
	),
	array(
		'key'              => 'highlighted_areas',
		'label'            => 'Featured areas',
		'description'      => 'Auto-populated from child posts.',
		'default_variant'  => 'card-grid',
		'default_position' => 'below_main',
		'default_source'   => 'child_posts',
	),

	// SIDEBAR
	array(
		'key'              => 'agent_card',
		'label'            => 'Listing agent',
		'default_variant'  => 'featured-card',
		'default_position' => 'sidebar',
		'max_items'        => 1,
		'supporting_text_required' => true,
	),
	array(
		'key'              => 'quick_specs',
		'label'            => 'Listing details',
		'default_variant'  => 'compact-grid',
		'default_position' => 'sidebar',
	),
	array(
		'key'              => 'cta_card',
		'label'            => 'Schedule a tour',
		'default_variant'  => 'featured-card',
		'default_position' => 'sidebar',
		'max_items'        => 1,
	),
	array(
		'key'              => 'related_listings',
		'label'            => 'Other Lake View homes',
		'description'      => 'Auto-populated from posts sharing the demo area taxonomy.',
		'default_variant'  => 'compact-grid',
		'default_position' => 'sidebar',
		'default_source'   => array(
			'type'         => 'taxonomy_match',
			'taxonomy'     => PRE_KS_TAX,
			'limit'        => 4,
			'exclude_self' => true,
		),
	),
);

foreach ( $groupings as $g ) {
	$r = $plugin->groupings->define( PRE_KS_CPT, $g );
	if ( is_wp_error( $r ) ) {
		ks_log( 'FAIL: define grouping ' . $g['key'] . ' — ' . $r->get_error_message() );
		exit( 1 );
	}
	ks_log( 'OK: defined grouping ' . $g['key'] . ' (' . $g['default_variant'] . '/' . $g['default_position'] . ')' );
}

// ---------------------------------------------------------------------------
// 3. Create or update the parent demo post.
// ---------------------------------------------------------------------------

function ks_find_or_create_post( $title, $args ) {
	$existing = get_posts(
		array(
			'post_type'      => $args['post_type'],
			'title'          => $title,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	$args['post_title']  = $title;
	$args['post_status'] = $args['post_status'] ?? 'publish';

	if ( ! empty( $existing ) ) {
		$args['ID'] = $existing[0];
		wp_update_post( $args );
		return $existing[0];
	}

	return wp_insert_post( $args );
}

$parent_content = <<<HTML
<h2>About the property</h2>
<p>This recently renovated four-bedroom home sits at the foot of the Cascades, with floor-to-ceiling windows framing mountain views from the great room. Originally built in 2018 and updated in 2024, the property combines modern finishes with the warmth of a family home.</p>

<h2>Recent renovations</h2>
<p>The kitchen was opened up to the living area in spring 2024, with quartz counters, a six-burner gas range, and a custom walnut island built by a local craftsman. New hardwood floors run continuously through the main level. The primary bath was rebuilt around a freestanding soaker tub and a glass-enclosed walk-in shower with rain head.</p>

<h2>Outdoor space</h2>
<p>The backyard is fully fenced and landscaped, with a covered patio, fire pit, and heated saltwater pool. Mature evergreens along the back property line provide privacy. The two-car garage at street level includes an EV charger and overhead storage.</p>
HTML;

$parent_id = ks_find_or_create_post(
	PRE_KS_PARENT,
	array(
		'post_type'    => PRE_KS_CPT,
		'post_excerpt' => 'Recently renovated 4-bedroom home with mountain views, a heated pool, and a two-car garage in the Lake View neighborhood.',
		'post_content' => $parent_content,
		'post_status'  => 'publish',
	)
);

if ( ! $parent_id || is_wp_error( $parent_id ) ) {
	ks_log( 'FAIL: parent post creation failed' );
	exit( 1 );
}

ks_log( 'OK: parent post id=' . $parent_id );

// Assign the demo area term so taxonomy_match has something to find.
$term_result = wp_set_post_terms( $parent_id, array( PRE_KS_AREA_TERM ), PRE_KS_TAX, false );
if ( ! is_wp_error( $term_result ) ) {
	ks_log( 'OK: parent post tagged with taxonomy term' );
}

// ---------------------------------------------------------------------------
// 4. Create child posts so child_posts source has something to render.
// ---------------------------------------------------------------------------

$children = array(
	array(
		'title'   => 'Gourmet kitchen',
		'excerpt' => 'Six-burner gas range, walnut island, quartz counters, and a butler\'s pantry.',
		'icon'    => 'star',
	),
	array(
		'title'   => 'Master suite',
		'excerpt' => 'Vaulted ceilings, walk-in closet, and a spa bath with a freestanding soaker tub.',
		'icon'    => 'home',
	),
	array(
		'title'   => 'Backyard retreat',
		'excerpt' => 'Heated saltwater pool, covered patio, fire pit, and a fully fenced yard.',
		'icon'    => 'pool',
	),
);

foreach ( $children as $child ) {
	$child_id = ks_find_or_create_post(
		$child['title'],
		array(
			'post_type'    => PRE_KS_CPT,
			'post_parent'  => $parent_id,
			'post_excerpt' => $child['excerpt'],
			'post_status'  => 'publish',
		)
	);
	if ( ! is_wp_error( $child_id ) ) {
		update_post_meta( $child_id, '_pre_icon', $child['icon'] );
		ks_log( 'OK: child post id=' . $child_id . ' (' . $child['title'] . ')' );
	}
}

// ---------------------------------------------------------------------------
// 5. Create sibling posts in the same area so taxonomy_match has results.
// ---------------------------------------------------------------------------

$siblings = array(
	array(
		'title'   => 'Cozy bungalow on Maple Lane',
		'excerpt' => 'Charming three-bedroom bungalow with original hardwoods and a wraparound porch.',
		'icon'    => 'home',
	),
	array(
		'title'   => 'Lakefront contemporary',
		'excerpt' => 'Five-bedroom contemporary with private dock and panoramic lake views.',
		'icon'    => 'star',
	),
	array(
		'title'   => 'Hillside craftsman',
		'excerpt' => 'Restored craftsman with mountain views, garden cottage, and a finished basement.',
		'icon'    => 'home',
	),
);

foreach ( $siblings as $sib ) {
	$sib_id = ks_find_or_create_post(
		$sib['title'],
		array(
			'post_type'    => PRE_KS_CPT,
			'post_excerpt' => $sib['excerpt'],
			'post_status'  => 'publish',
		)
	);
	if ( ! is_wp_error( $sib_id ) ) {
		wp_set_post_terms( $sib_id, array( PRE_KS_AREA_TERM ), PRE_KS_TAX, false );
		update_post_meta( $sib_id, '_pre_icon', $sib['icon'] );
		ks_log( 'OK: sibling post id=' . $sib_id . ' (' . $sib['title'] . ')' );
	}
}

// ---------------------------------------------------------------------------
// 6. Populate per-post grouping items on the parent post.
// ---------------------------------------------------------------------------

$post_groupings = array(
	// HORIZONTAL ROW — at-a-glance specs (above_main).
	array(
		'grouping_key' => 'at_a_glance',
		'source'       => 'manual',
		'items'        => array(
			array( 'icon_id' => 'bed',   'heading' => '4 Bedrooms' ),
			array( 'icon_id' => 'bath',  'heading' => '2 Bathrooms' ),
			array( 'icon_id' => 'ruler', 'heading' => '1,800 sqft' ),
			array( 'icon_id' => 'home',  'heading' => 'Single Family' ),
			array( 'icon_id' => 'calendar', 'heading' => 'Built 2018' ),
		),
	),

	// CARD GRID — highlights (above_main).
	array(
		'grouping_key' => 'highlights',
		'source'       => 'manual',
		'items'        => array(
			array(
				'icon_id'         => 'pool',
				'heading'         => 'Outdoor entertainment',
				'supporting_text' => 'Heated saltwater pool, covered patio, and a built-in fire pit make summer evenings effortless.',
			),
			array(
				'icon_id'         => 'star',
				'heading'         => 'Mountain views',
				'supporting_text' => 'Floor-to-ceiling windows in the great room frame the Cascade range from sunrise to sunset.',
			),
			array(
				'icon_id'         => 'car',
				'heading'         => 'EV-ready garage',
				'supporting_text' => 'Two-car attached garage with a Level 2 EV charger and overhead storage.',
			),
		),
	),

	// COMPACT GRID — amenities (below_main).
	array(
		'grouping_key' => 'amenities',
		'source'       => 'manual',
		'items'        => array(
			array( 'icon_id' => 'pool',     'heading' => 'Heated pool' ),
			array( 'icon_id' => 'bath',     'heading' => 'Spa bath' ),
			array( 'icon_id' => 'car',      'heading' => 'EV charger' ),
			array( 'icon_id' => 'home',     'heading' => 'Smart thermostat' ),
			array( 'icon_id' => 'star',     'heading' => 'Skylights' ),
			array( 'icon_id' => 'globe',    'heading' => 'Solar panels' ),
			array( 'icon_id' => 'check',    'heading' => 'Hardwood floors' ),
			array( 'icon_id' => 'heart',    'heading' => 'Pet friendly' ),
			array( 'icon_id' => 'briefcase','heading' => 'Home office' ),
			array( 'icon_id' => 'graduation','heading' => 'Top schools' ),
			array( 'icon_id' => 'phone',    'heading' => 'Smart locks' ),
			array( 'icon_id' => 'mail',     'heading' => 'Package locker' ),
		),
	),

	// CARD GRID — awards (below_main).
	array(
		'grouping_key' => 'awards',
		'source'       => 'manual',
		'items'        => array(
			array(
				'icon_id'         => 'award',
				'heading'         => 'Best Renovation 2024',
				'supporting_text' => 'Cascade Real Estate Awards — recognized for the open-concept kitchen renovation.',
			),
			array(
				'icon_id'         => 'star',
				'heading'         => 'Featured Property',
				'supporting_text' => 'Pacific Northwest Living Magazine, March 2024 issue, page 42.',
			),
			array(
				'icon_id'         => 'check',
				'heading'         => 'Energy Star Certified',
				'supporting_text' => 'Solar panels and high-efficiency appliances earned the home its Energy Star rating in late 2023.',
			),
		),
	),

	// CHILD POSTS auto-source (below_main).
	array(
		'grouping_key' => 'highlighted_areas',
		'source'       => 'child_posts',
		'items'        => array(),
	),

	// FEATURED CARD — agent (sidebar).
	array(
		'grouping_key' => 'agent_card',
		'source'       => 'manual',
		'items'        => array(
			array(
				'icon_id'         => 'user',
				'heading'         => 'Sarah Chen, Senior Realtor',
				'supporting_text' => '12 years specializing in Lake View and Cascade waterfront properties. Sarah will walk you through the listing in person and answer every question.',
				'link'            => '#contact-sarah',
			),
		),
	),

	// COMPACT GRID — listing details (sidebar).
	array(
		'grouping_key' => 'quick_specs',
		'source'       => 'manual',
		'items'        => array(
			array( 'icon_id' => 'dollar',   'heading' => '$875,000' ),
			array( 'icon_id' => 'calendar', 'heading' => 'Listed 3 days ago' ),
			array( 'icon_id' => 'tag',      'heading' => 'Available' ),
			array( 'icon_id' => 'map-pin',  'heading' => 'Lake View, OR' ),
		),
	),

	// FEATURED CARD — CTA (sidebar).
	array(
		'grouping_key' => 'cta_card',
		'source'       => 'manual',
		'items'        => array(
			array(
				'icon_id'         => 'calendar',
				'heading'         => 'Schedule a tour',
				'supporting_text' => 'No pressure, no obligation. We\'ll work around your schedule — evenings and weekends welcome.',
				'link'            => '#schedule-tour',
			),
		),
	),

	// TAXONOMY MATCH auto-source (sidebar).
	array(
		'grouping_key' => 'related_listings',
		'source'       => array(
			'type'         => 'taxonomy_match',
			'taxonomy'     => PRE_KS_TAX,
			'limit'        => 4,
			'exclude_self' => true,
		),
		'items'        => array(),
	),
);

$result = $plugin->post_data->set_groupings( $parent_id, $post_groupings, 'kitchen-sink' );
if ( is_wp_error( $result ) ) {
	ks_log( 'FAIL: set_groupings on parent post — ' . $result->get_error_message() );
	exit( 1 );
}
ks_log( 'OK: populated ' . count( $post_groupings ) . ' groupings on parent post' );

// ---------------------------------------------------------------------------
// 7. Done — print summary with links.
// ---------------------------------------------------------------------------

$permalink   = get_permalink( $parent_id );
$edit_url    = admin_url( 'post.php?action=edit&post=' . $parent_id );
$archive_url = get_post_type_archive_link( PRE_KS_CPT );

echo "\n----------------------------------------\n";
echo "Kitchen sink ready.\n";
echo "  View on frontend: " . $permalink . "\n";
echo "  Edit in admin:    " . $edit_url . "\n";
if ( $archive_url ) {
	echo "  CPT archive:      " . $archive_url . "\n";
}
echo "\n";
