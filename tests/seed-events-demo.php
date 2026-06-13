<?php
/**
 * Events vertical — integration seed (v1.2).
 *
 * Stands up a realistic events data set so the full vertical can be verified
 * end-to-end in the browser:
 *
 *   1. Registers a `workshop` CPT.
 *   2. Defines 6 role-tagged post fields:
 *        event_start (date, role=event_start)   — timed
 *        event_end   (date, role=event_end)     — timed
 *        event_status (badge, role=event_status)
 *        event_attendance_mode (badge, role=event_attendance_mode)
 *        event_location (text, role=event_location)
 *        event_price (currency, role=event_offers)
 *   3. Creates 4 workshops with dates RELATIVE TO NOW so the set always
 *      spans the today boundary regardless of when it's run:
 *        - a PAST workshop (ended ~10 days ago)
 *        - an IN-PROGRESS multi-day retreat (started yesterday, ends in 2 days)
 *        - an UPCOMING bootcamp (~7 days out)
 *        - an UPCOMING masterclass (~30 days out, online, free)
 *   4. Creates a Promptless WP page with a PostGrid set to
 *      event_status = 'upcoming', event_sort = 'soonest'.
 *
 * Usage from Local's Site Shell:
 *
 *   wp eval-file wp-content/plugins/post-runtime-engine/tests/seed-events-demo.php
 *
 * Idempotent: re-running replaces the CPT def, overwrites fields by key,
 * updates posts/page by slug. Build-excluded via the tests dir + seed- prefix.
 *
 * VERIFY AFTER RUNNING (URLs are printed at the end):
 *   A. PostGrid page → shows exactly 3 cards (retreat, bootcamp, masterclass)
 *      in that order (soonest first). The PAST workshop is absent. The
 *      in-progress retreat IS present (end date is in the future).
 *   B. Any workshop single page → view source → contains a
 *      <script type="application/ld+json"> Event block with startDate,
 *      endDate, location, eventStatus, eventAttendanceMode, offers.
 *
 * @package PostRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via: wp eval-file wp-content/plugins/post-runtime-engine/tests/seed-events-demo.php\n" );
}

if ( ! function_exists( 'pcptpages' ) || ! pcptpages() ) {
	exit( "Error: PCPTPages plugin not loaded. Activate Promptless CPT Pages first.\n" );
}

$plugin = pcptpages();

echo "=== Seeding events demo data (PCPTPages v1.2) ===\n\n";

// ---------------------------------------------------------------------------
// 1. Register the `workshop` CPT.
// ---------------------------------------------------------------------------
$registered = $plugin->cpts->register(
	'workshop',
	array(
		'slug'                     => 'workshop',
		'label_singular'           => 'Workshop',
		'label_plural'             => 'Workshops',
		'public'                   => true,
		'has_archive'              => true,
		'show_in_rest'             => true,
		'supports'                 => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		'hero_layout'              => 'stacked',
		'default_icon'             => 'mdi:calendar-star',
		'archive_show_post_date'   => false,
		'archive_show_post_author' => false,
	)
);
if ( is_wp_error( $registered ) ) {
	exit( 'Failed to register CPT: ' . $registered->get_error_message() . "\n" );
}
echo "  \xE2\x9C\x93 Registered CPT: workshop\n";

// ---------------------------------------------------------------------------
// 2. Define the role-tagged post fields.
// ---------------------------------------------------------------------------
$fields = array(
	array(
		'key'                => 'event_start',
		'label'              => 'Starts',
		'display_type'       => 'date',
		'card_position'      => 'headline',
		'single_position'    => 'meta_strip',
		'date_format'        => 'custom',
		'date_format_string' => 'M j, Y · g:i A',
		'all_day'            => false,
		'semantic_role'      => 'event_start',
		'required'           => true,
	),
	array(
		'key'                => 'event_end',
		'label'              => 'Ends',
		'display_type'       => 'date',
		'card_position'      => 'hidden',
		'single_position'    => 'meta_strip',
		'date_format'        => 'custom',
		'date_format_string' => 'M j, Y · g:i A',
		'all_day'            => false,
		'semantic_role'      => 'event_end',
	),
	array(
		'key'             => 'event_status',
		'label'           => 'Status',
		'display_type'    => 'badge',
		'card_position'   => 'image_overlay',
		'single_position' => 'subtitle',
		'color_intent'    => 'neutral',
		'semantic_role'   => 'event_status',
		'options'         => array(
			'scheduled' => array( 'label' => 'Scheduled' ),
			'cancelled' => array( 'label' => 'Cancelled' ),
			'postponed' => array( 'label' => 'Postponed' ),
		),
	),
	array(
		'key'             => 'event_attendance_mode',
		'label'           => 'Attendance',
		'display_type'    => 'badge',
		'card_position'   => 'hidden',
		'single_position' => 'subtitle',
		'color_intent'    => 'neutral',
		'semantic_role'   => 'event_attendance_mode',
		'options'         => array(
			'in_person' => array( 'label' => 'In person' ),
			'online'    => array( 'label' => 'Online' ),
			'mixed'     => array( 'label' => 'Hybrid' ),
		),
	),
	array(
		'key'             => 'event_location',
		'label'           => 'Location',
		'display_type'    => 'text',
		'card_position'   => 'footer_meta',
		'single_position' => 'meta_strip',
		'semantic_role'   => 'event_location',
	),
	array(
		'key'             => 'event_price',
		'label'           => 'Price',
		'display_type'    => 'currency',
		'card_position'   => 'meta_strip',
		'single_position' => 'headline',
		'currency_code'   => 'USD',
		'semantic_role'   => 'event_offers',
	),
);

foreach ( $fields as $field ) {
	$defined = $plugin->post_fields->define( 'workshop', $field );
	if ( is_wp_error( $defined ) ) {
		exit( "Failed to define field {$field['key']}: " . $defined->get_error_message() . "\n" );
	}
	echo "  \xE2\x9C\x93 Defined post field: {$field['key']} (role={$field['semantic_role']})\n";
}

// ---------------------------------------------------------------------------
// 3. Flush rewrite rules so /workshop/<slug>/ resolves.
// ---------------------------------------------------------------------------
$plugin->cpts->register_all_with_wp();
flush_rewrite_rules( false );

// ---------------------------------------------------------------------------
// 4. Create / update workshops with dates RELATIVE TO NOW.
//    wp_date() renders the timestamp in the site timezone, so the stored
//    'Y-m-d H:i:s' is correct site-local wall clock (matching the renderer).
// ---------------------------------------------------------------------------
$now = time();
$fmt = static function ( $ts ) {
	return wp_date( 'Y-m-d H:i:s', $ts );
};

$workshops = array(
	array(
		'slug'    => 'past-intro-to-react',
		'title'   => 'Intro to React (past)',
		'excerpt' => 'A beginner-friendly introduction to React fundamentals — components, props, and state.',
		'start'   => $now - ( 10 * DAY_IN_SECONDS ),
		'end'     => $now - ( 10 * DAY_IN_SECONDS ) + ( 3 * HOUR_IN_SECONDS ),
		'status'  => 'scheduled',
		'mode'    => 'in_person',
		'loc'     => 'Brooklyn, NY',
		'price'   => '49',
	),
	array(
		'slug'    => 'design-systems-retreat',
		'title'   => 'Design Systems Retreat (in progress)',
		'excerpt' => 'A three-day immersive retreat building a production design system end to end.',
		'start'   => $now - ( 1 * DAY_IN_SECONDS ),
		'end'     => $now + ( 2 * DAY_IN_SECONDS ),
		'status'  => 'scheduled',
		'mode'    => 'in_person',
		'loc'     => 'Asheville, NC',
		'price'   => '299',
	),
	array(
		'slug'    => 'wordpress-bootcamp',
		'title'   => 'WordPress Bootcamp (upcoming, ~7 days)',
		'excerpt' => 'A full-day bootcamp covering modern WordPress development from blocks to deploys.',
		'start'   => $now + ( 7 * DAY_IN_SECONDS ),
		'end'     => $now + ( 7 * DAY_IN_SECONDS ) + ( 6 * HOUR_IN_SECONDS ),
		'status'  => 'scheduled',
		'mode'    => 'in_person',
		'loc'     => 'Austin, TX',
		'price'   => '149',
	),
	array(
		'slug'    => 'seo-masterclass',
		'title'   => 'SEO Masterclass (upcoming, ~30 days, online)',
		'excerpt' => 'A live online masterclass on technical SEO, structured data, and content strategy.',
		'start'   => $now + ( 30 * DAY_IN_SECONDS ),
		'end'     => $now + ( 30 * DAY_IN_SECONDS ) + ( 3 * HOUR_IN_SECONDS ),
		'status'  => 'scheduled',
		'mode'    => 'online',
		'loc'     => 'Online',
		'price'   => '0',
	),
);

$workshop_ids = array();

foreach ( $workshops as $w ) {
	$existing = get_page_by_path( $w['slug'], OBJECT, 'workshop' );

	$post_args = array(
		'post_type'    => 'workshop',
		'post_status'  => 'publish',
		'post_title'   => $w['title'],
		'post_name'    => $w['slug'],
		'post_excerpt' => $w['excerpt'],
		'post_content' => '<!-- wp:paragraph --><p>' . esc_html( $w['excerpt'] ) . '</p><!-- /wp:paragraph -->',
	);

	if ( $existing instanceof WP_Post ) {
		$post_args['ID'] = $existing->ID;
		$post_id         = wp_update_post( $post_args, true );
		$action          = 'Updated';
	} else {
		$post_id = wp_insert_post( $post_args, true );
		$action  = 'Created';
	}

	if ( is_wp_error( $post_id ) ) {
		exit( "Failed to create workshop '{$w['title']}': " . $post_id->get_error_message() . "\n" );
	}

	$workshop_ids[] = $post_id;

	$result = $plugin->post_data->set_field_values(
		$post_id,
		array(
			'event_start'           => $fmt( $w['start'] ),
			'event_end'             => $fmt( $w['end'] ),
			'event_status'          => $w['status'],
			'event_attendance_mode' => $w['mode'],
			'event_location'        => $w['loc'],
			'event_price'           => $w['price'],
		),
		'seed-script'
	);
	if ( is_wp_error( $result ) ) {
		exit( "Failed to set field values on post {$post_id}: " . $result->get_error_message() . "\n" );
	}

	echo "  \xE2\x9C\x93 {$action} workshop #{$post_id}: {$w['title']}\n";
}

// ---------------------------------------------------------------------------
// 5. Create / update the Promptless page with an "Upcoming" PostGrid.
// ---------------------------------------------------------------------------
$page_slug     = 'upcoming-workshops-demo';
$existing_page = get_page_by_path( $page_slug, OBJECT, 'page' );

$sections = array(
	array(
		'type'    => 'hero',
		'id'      => 'hero-' . wp_generate_uuid4(),
		'content' => array(
			'heading'    => 'Upcoming Workshops',
			'content'    => '<p>This PostGrid is set to <strong>Event Date Filter = Upcoming</strong>, <strong>Sort = Soonest</strong>. It should show the in-progress retreat plus the two upcoming workshops (soonest first), and exclude the past one.</p>',
			'media_type' => 'none',
		),
	),
	array(
		'type'    => 'postgrid',
		'id'      => 'postgrid-' . wp_generate_uuid4(),
		'content' => array(
			'heading'        => 'What\'s coming up',
			'content'        => '<p>Seeded by tests/seed-events-demo.php.</p>',
			'post_type'      => 'workshop',
			'posts_per_page' => 6,
			'grid_columns'   => '2',
			'show_date'      => false,
			'show_author'    => false,
			'show_excerpt'   => true,
			'excerpt_length' => 22,
			// Events vertical controls:
			'event_status'   => 'upcoming',
			'event_sort'     => 'soonest',
		),
	),
);

$page_args = array(
	'post_type'   => 'page',
	'post_status' => 'publish',
	'post_title'  => 'Upcoming Workshops (events demo)',
	'post_name'   => $page_slug,
	'post_content' => '',
);

if ( $existing_page instanceof WP_Post ) {
	$page_args['ID'] = $existing_page->ID;
	$page_id         = wp_update_post( $page_args, true );
	$action          = 'Updated';
} else {
	$page_id = wp_insert_post( $page_args, true );
	$action  = 'Created';
}

if ( is_wp_error( $page_id ) ) {
	exit( 'Failed to create demo page: ' . $page_id->get_error_message() . "\n" );
}

update_post_meta( $page_id, '_aisb_enabled', '1' );
// Store the sections as a PHP array (WP serializes it), matching what the
// Promptless editor itself persists. Storing a JSON *string* here renders
// fine on the front end (the template json_decodes) but crashes the React
// editor, whose REST loader feeds the value straight to sections.map().
update_post_meta( $page_id, '_aisb_sections', $sections );

echo "  \xE2\x9C\x93 {$action} demo page #{$page_id}\n";

// ---------------------------------------------------------------------------
// 6. Report + expectations.
// ---------------------------------------------------------------------------
$page_url = get_permalink( $page_id );
echo "\n=== Done ===\n\n";
echo "PostGrid 'Upcoming' page:\n  {$page_url}\n\n";
echo "Workshop singles (for JSON-LD check):\n";
foreach ( $workshop_ids as $id ) {
	echo '  ' . get_permalink( $id ) . "\n";
}
echo "\nExpected on the PostGrid page (event_status=upcoming, sort=soonest):\n";
echo "  - 3 cards, in order: Design Systems Retreat, WordPress Bootcamp, SEO Masterclass\n";
echo "  - The PAST 'Intro to React' workshop is ABSENT\n";
echo "  - The in-progress retreat IS present (its end date is in the future)\n";
echo "\nExpected on any workshop single page (View Source):\n";
echo "  - <script type=\"application/ld+json\"> Event block with startDate, endDate,\n";
echo "    location, eventStatus, eventAttendanceMode (online for SEO Masterclass), offers\n";
echo "\nTo reset: re-run this script (idempotent), or delete the 'workshop' posts + demo page.\n";
