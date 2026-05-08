<?php
/**
 * Uninstall handler for Post Runtime Engine.
 *
 * Runs ONCE when an administrator deletes the plugin via the WordPress
 * Plugins screen (NOT on deactivation). At this point the plugin's PHP
 * is no longer loaded — only this file runs.
 *
 * Behavior:
 *   - Site-level configuration owned by this plugin (CPT definitions,
 *     grouping definitions, plugin-level settings) is REMOVED. These were
 *     created by the plugin and have no use without it active.
 *   - Per-post content (the actual filled-in groupings stored as post meta)
 *     is PRESERVED by default. Users can re-activate the plugin later and
 *     resume from where they left off.
 *   - If the user has explicitly opted into full deletion via the
 *     `pre_settings.delete_data_on_uninstall` flag, post meta is also
 *     removed. This is the explicit-consent escape hatch.
 *
 * This mirrors the data-protection pattern Promptless WP and Form Runtime
 * Engine follow. The default is conservative: never destroy user content
 * silently.
 *
 * @package PostRuntimeEngine
 */

// Bail if not invoked by WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Determine whether the user opted into full deletion.
$settings        = get_option( 'pre_settings', array() );
$delete_all_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

// ---------------------------------------------------------------------------
// Always-removed: plugin-owned site configuration.
// ---------------------------------------------------------------------------

// Read CPT slugs first so we can clean up the per-CPT grouping options.
$cpts = get_option( 'pre_cpts', array() );
if ( ! is_array( $cpts ) ) {
	$cpts = array();
}

// Remove top-level options.
delete_option( 'pre_cpts' );
delete_option( 'pre_settings' );
delete_option( 'pre_data_version' );

// Remove per-CPT grouping definition options.
foreach ( array_keys( $cpts ) as $cpt_slug ) {
	$cpt_slug = sanitize_key( $cpt_slug );
	if ( $cpt_slug !== '' ) {
		delete_option( 'pre_groupings_' . $cpt_slug );
	}
}

// Belt-and-suspenders cleanup: any pre_groupings_* options that survived a
// CPT slug rename or partial cleanup should also go. Done with a direct
// query because the option count is small and the alternative
// (wp_load_alloptions + filter) is more expensive.
$prefix = 'pre_groupings_';
$rows   = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $prefix ) . '%'
	)
);
if ( is_array( $rows ) ) {
	foreach ( $rows as $row ) {
		delete_option( $row );
	}
}

// Connector rate-limit transients (added in Phase 3+; cleaning here is
// future-proof and harmless if no transients exist).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_pre_connector_rate_%',
		'_transient_timeout_pre_connector_rate_%'
	)
);

// Source-resolver caches (added in Phase 2+).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_pre_source_%',
		'_transient_timeout_pre_source_%'
	)
);

// ---------------------------------------------------------------------------
// Conditional removal: per-post groupings.
// ---------------------------------------------------------------------------

if ( $delete_all_data ) {
	// User opted into full deletion. Remove every PRE-owned post meta key
	// across the entire site, in one query per key. This catches posts in
	// any post status (published, draft, trash) and any post type.
	$pre_meta_keys = array(
		'_pre_groupings',
		'_pre_groupings_backup',
		'_pre_groupings_backup_time',
		'_pre_groupings_backup_user',
		'_pre_groupings_backup_source',
		'_pre_position_overrides',
		'_pre_icon',
	);

	foreach ( $pre_meta_keys as $meta_key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
	}
}

// Note: We deliberately do NOT delete the posts that belonged to registered
// CPTs. Those posts contain user content (titles, main-editor body, etc.)
// independent of this plugin. If the user wants to delete the posts, they
// can do so through the WordPress admin — destroying user content during
// uninstall is never the right default.
