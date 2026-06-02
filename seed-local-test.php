<?php
/**
 * Local pressure-test seed for the PostGrid card-render fix (v0.5.2).
 *
 * Sets up the minimum data shape required to exercise the
 * `aisb_postgrid_card_section` action handler that was broken by stale
 * `function_exists( 'pre' )` guards in 5 production-code locations.
 *
 *   1. Registers a `session` CPT
 *   2. Defines 3 post fields exercising the headline / meta_strip /
 *      footer_meta positions (image_overlay is intentionally skipped —
 *      it needs a featured image to render and we don't want to wrestle
 *      with uploads in a smoke test)
 *   3. Creates 2 published sessions with the field values populated
 *   4. Creates a Promptless WP page with a hero + PostGrid section
 *      pointing at `session`
 *
 * Then echos the page URL. Visit it and confirm:
 *   - Each card shows the session_date headline above its title
 *   - "45 min" / "90 min" appears in the meta_strip
 *   - "Development" / "Design" badges in the footer_meta
 *
 * If those render, the v0.5.2 fix is verified end-to-end on the local
 * environment. If they don't, the fix is incomplete and the bug lives
 * somewhere we haven't touched yet — escalate before packaging.
 *
 * Usage from Local's Site Shell:
 *
 *   wp eval-file wp-content/plugins/post-runtime-engine/seed-local-test.php
 *
 * Idempotent: safe to re-run. CPT register call replaces the existing
 * definition; post field defines overwrite by key; posts are matched by
 * unique slugs and updated in place; the page is matched by slug.
 *
 * Build exclusion: filename matches `seed-*.php` in the build script's
 * BASE_EXCLUDES list, so this script never ships in any release ZIP.
 *
 * @package PostRuntimeEngine
 */

// Bail if not invoked through WP-CLI's wp eval-file (which loads WP fully).
if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via: wp eval-file wp-content/plugins/post-runtime-engine/seed-local-test.php\n" );
}

if ( ! function_exists( 'pcptpages' ) || ! pcptpages() ) {
	exit( "Error: PCPTPages plugin not loaded. Activate Promptless CPT Pages first.\n" );
}

$plugin = pcptpages();

echo "=== Seeding pressure-test data for PCPTPages v0.5.2 ===\n\n";

// ---------------------------------------------------------------------------
// 1. Register the `session` CPT.
// ---------------------------------------------------------------------------
$cpt_def = array(
	'slug'                     => 'session',
	'label_singular'           => 'Session',
	'label_plural'             => 'Sessions',
	'public'                   => true,
	'has_archive'              => true,
	'show_in_rest'             => true,
	'supports'                 => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
	'hero_layout'              => 'stacked',
	'default_icon'             => 'mdi:microphone',
	'archive_show_post_date'   => false,
	'archive_show_post_author' => false,
);

$registered = $plugin->cpts->register( 'session', $cpt_def );
if ( is_wp_error( $registered ) ) {
	exit( "Failed to register CPT: " . $registered->get_error_message() . "\n" );
}
echo "  ✓ Registered CPT: session\n";

// ---------------------------------------------------------------------------
// 2. Define post fields. These are the ones whose rendering on PostGrid
//    cards was broken by the stale `function_exists( 'pre' )` guards.
// ---------------------------------------------------------------------------
$fields = array(
	array(
		'key'                => 'session_date',
		'label'              => 'Session date',
		'display_type'       => 'date',
		'card_position'      => 'headline',
		'single_position'    => 'headline',
		'date_format'        => 'custom',
		'date_format_string' => 'M j · g:i A',
		'required'           => true,
	),
	array(
		'key'             => 'duration',
		'label'           => 'Duration',
		'display_type'    => 'number_with_label',
		'card_position'   => 'meta_strip',
		'single_position' => 'meta_strip',
		'unit_label'      => 'min',
	),
	array(
		'key'             => 'track',
		'label'           => 'Track',
		'display_type'    => 'badge',
		'card_position'   => 'footer_meta',
		'single_position' => 'footer_meta',
		'color_intent'    => 'neutral',
		'options'         => array(
			'development' => array( 'label' => 'Development' ),
			'design'      => array( 'label' => 'Design' ),
			'business'    => array( 'label' => 'Business' ),
		),
	),
);

foreach ( $fields as $field ) {
	$defined = $plugin->post_fields->define( 'session', $field );
	if ( is_wp_error( $defined ) ) {
		exit( "Failed to define field {$field['key']}: " . $defined->get_error_message() . "\n" );
	}
	echo "  ✓ Defined post field: {$field['key']}\n";
}

// ---------------------------------------------------------------------------
// 3. Flush rewrite rules so /session/<slug>/ URLs resolve before we insert
//    posts. Posts inserted before WP knows the CPT exists end up with
//    404'd permalinks even if the CPT registers later in the request.
// ---------------------------------------------------------------------------
$plugin->cpts->register_all_with_wp();
flush_rewrite_rules( false );

// ---------------------------------------------------------------------------
// 4. Create / update 2 sample sessions with post field values populated.
// ---------------------------------------------------------------------------
$session_posts = array(
	array(
		'slug'    => 'building-your-first-block',
		'title'   => 'Building Your First WordPress Block with React',
		'excerpt' => 'A from-scratch introduction to building custom WordPress blocks — attributes, inspector controls, and a save function you can actually read.',
		'content' => "<!-- wp:paragraph --><p>The WordPress block editor reshapes how we author content. In this hands-on session you'll go from \"I've never touched the block API\" to a working custom block with attributes, inspector controls, and a save function you can read.</p><!-- /wp:paragraph -->",
		'fields'  => array(
			'session_date' => '2026-09-15 09:30:00',
			'duration'     => '45',
			'track'        => 'development',
		),
	),
	array(
		'slug'    => 'design-systems-for-wordpress',
		'title'   => 'Design Systems for WordPress: From Tokens to Theme.json',
		'excerpt' => 'Move your design system from a Figma file into a governed theme.json — token taxonomies, component patterns, drift auditing.',
		'content' => "<!-- wp:paragraph --><p>A design system is only valuable if it survives contact with real production work. In this 90-minute workshop we'll move from \"a Figma file full of colors\" to a fully governed theme.json that constrains AND empowers the editors who use it.</p><!-- /wp:paragraph -->",
		'fields'  => array(
			'session_date' => '2026-09-15 14:00:00',
			'duration'     => '90',
			'track'        => 'design',
		),
	),
);

$session_ids = array();

foreach ( $session_posts as $session ) {
	$existing = get_page_by_path( $session['slug'], OBJECT, 'session' );

	$post_args = array(
		'post_type'    => 'session',
		'post_status'  => 'publish',
		'post_title'   => $session['title'],
		'post_name'    => $session['slug'],
		'post_excerpt' => $session['excerpt'],
		'post_content' => $session['content'],
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
		exit( "Failed to create session '{$session['title']}': " . $post_id->get_error_message() . "\n" );
	}

	$session_ids[] = $post_id;

	$result = $plugin->post_data->set_field_values( $post_id, $session['fields'], 'seed-script' );
	if ( is_wp_error( $result ) ) {
		exit( "Failed to set field values on post {$post_id}: " . $result->get_error_message() . "\n" );
	}

	echo "  ✓ {$action} session #{$post_id}: {$session['title']}\n";
}

// ---------------------------------------------------------------------------
// 5. Create / update the Promptless WP page with Hero + PostGrid.
// ---------------------------------------------------------------------------
$page_slug = 'sessions-local-test';

$existing_page = get_page_by_path( $page_slug, OBJECT, 'page' );

$sections = array(
	array(
		'type'    => 'hero',
		'id'      => 'hero-' . wp_generate_uuid4(),
		'content' => array(
			'heading'    => 'PostGrid card-render {smoke test}',
			'content'    => '<p>Each card below should show: session date in the headline, duration in the meta strip, and track as a footer badge. If you see only titles and excerpts, the v0.5.2 fix did not land.</p>',
			'media_type' => 'none',
		),
	),
	array(
		'type'    => 'postgrid',
		'id'      => 'postgrid-' . wp_generate_uuid4(),
		'content' => array(
			'heading'        => 'All sessions',
			'content'        => '<p>Test data seeded by seed-local-test.php.</p>',
			'post_type'      => 'session',
			'posts_per_page' => 3,
			'grid_columns'   => '2',
			'show_date'      => false,
			'show_author'    => false,
			'show_excerpt'   => true,
			'excerpt_length' => 25,
			'image_styling'  => 'rounded',
		),
	),
);

$page_args = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'PostGrid Smoke Test (local)',
	'post_name'    => $page_slug,
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
	exit( "Failed to create test page: " . $page_id->get_error_message() . "\n" );
}

// Persist the sections array and the Promptless-managed flag.
update_post_meta( $page_id, '_aisb_enabled', '1' );
update_post_meta( $page_id, '_aisb_sections', wp_json_encode( $sections ) );

echo "  ✓ {$action} test page #{$page_id}\n";

// ---------------------------------------------------------------------------
// 6. Report.
// ---------------------------------------------------------------------------
$page_url     = get_permalink( $page_id );
$session_urls = array_map( 'get_permalink', $session_ids );

echo "\n=== Done. Visit the test page: ===\n\n";
echo "  PostGrid test page:    {$page_url}\n";
foreach ( $session_urls as $i => $url ) {
	$n = $i + 1;
	echo "  Session {$n} single:      {$url}\n";
}
echo "\nExpected on the PostGrid card for each session:\n";
echo "  - Headline above title:  date in 'Sep 15 · 9:30 AM' format\n";
echo "  - Meta strip:            '45 min' / '90 min'\n";
echo "  - Footer badge:          'Development' / 'Design'\n";
echo "\nIf any are missing, the v0.5.2 fix is incomplete.\n";
