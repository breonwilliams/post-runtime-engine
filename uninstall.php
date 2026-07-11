<?php
/**
 * Uninstall handler for Promptless CPT Pages.
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
 *     `pcptpages_settings.delete_data_on_uninstall` flag, post meta is also
 *     removed. This is the explicit-consent escape hatch.
 *
 * This mirrors the data-protection pattern Promptless WP and Form Runtime
 * Engine follow. The default is conservative: never destroy user content
 * silently.
 *
 * All cleanup work is wrapped in pcptpages_run_uninstall_cleanup() so the
 * intermediate locals ($settings, $cpts, $rows, etc.) stay function-scoped
 * — uninstall.php runs in global scope, and PHPCS treats every top-level
 * `$var` as an unprefixed global otherwise. Same pattern Form Runtime
 * Engine adopted to clear WP.org Plugin Check warnings.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
 *
 * Justification: uninstall.php runs once during plugin deletion to scrub
 * plugin-owned options and (optionally) plugin post meta across all
 * posts. Direct queries are required (the meta_key scans affect rows
 * across the whole site, not a single post); caching is irrelevant
 * since the plugin is being removed.
 */

// Bail if not invoked by WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Duplicate-install guard (2026-07-11).
 *
 * When a second copy of this plugin exists under a DIFFERENT folder name
 * (e.g. one installed from the release ZIP into `promptless-cpt-pages/`
 * alongside an older copy in `post-runtime-engine/` from a GitHub source
 * ZIP or a copied dev folder), deleting the stale copy through the Plugins
 * screen runs THIS file — which would wipe the shared, database-stored
 * configuration (CPT definitions, groupings, settings) out from under the
 * copy that is still installed. Found live on a test environment during
 * the v0.6.5 release verification.
 *
 * If any other installed copy of the plugin remains (identified by its
 * `post-runtime-engine.php` main file in a different plugin folder), skip
 * cleanup entirely: the surviving copy owns the data. Full cleanup runs
 * only when the LAST copy is deleted.
 */
$pcptpages_own_dir = dirname( WP_UNINSTALL_PLUGIN );
$pcptpages_mains   = glob( WP_PLUGIN_DIR . '/*/post-runtime-engine.php' );
if ( is_array( $pcptpages_mains ) && '' !== $pcptpages_own_dir && '.' !== $pcptpages_own_dir ) {
	foreach ( $pcptpages_mains as $pcptpages_main ) {
		if ( basename( dirname( $pcptpages_main ) ) !== $pcptpages_own_dir ) {
			return; // Another copy is still installed — preserve shared data.
		}
	}
}
unset( $pcptpages_own_dir, $pcptpages_mains, $pcptpages_main );

/**
 * Run the full uninstall cleanup. Wrapped in a function so intermediate
 * variables are function-scoped rather than globals — uninstall.php runs
 * in global scope, and PHPCS otherwise flags every local `$var` here as
 * a non-prefixed global.
 *
 * @return void
 */
function pcptpages_run_uninstall_cleanup() {
	global $wpdb;

	// Determine whether the user opted into full deletion.
	$settings        = get_option( 'pcptpages_settings', array() );
	$delete_all_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	// ---------------------------------------------------------------------------
	// Always-removed: plugin-owned site configuration.
	// ---------------------------------------------------------------------------

	// Read CPT slugs first so we can clean up the per-CPT grouping options.
	$cpts = get_option( 'pcptpages_cpts', array() );
	if ( ! is_array( $cpts ) ) {
		$cpts = array();
	}

	// Remove top-level options.
	delete_option( 'pcptpages_cpts' );
	delete_option( 'pcptpages_settings' );
	delete_option( 'pcptpages_data_version' );

	// Revoke the scoped `pcptpages_manage_cpts` capability from every role. Mirrors the
	// FRE / FlowMint pattern: capability lifecycle tracks plugin lifecycle so the
	// site doesn't carry orphan capability grants after uninstall. Manually
	// requires the class file because the plugin's autoloader does not run during
	// uninstall.
	require_once __DIR__ . '/includes/Core/class-pre-capabilities.php';
	PCPTPages_Capabilities::revoke_all_capabilities();

	// Remove per-CPT grouping definition options.
	foreach ( array_keys( $cpts ) as $cpt_slug ) {
		$cpt_slug = sanitize_key( $cpt_slug );
		if ( '' !== $cpt_slug ) {
			delete_option( 'pcptpages_groupings_' . $cpt_slug );
		}
	}

	// Belt-and-suspenders cleanup: any pcptpages_groupings_* options that survived a
	// CPT slug rename or partial cleanup should also go. Done with a direct
	// query because the option count is small and the alternative
	// (wp_load_alloptions + filter) is more expensive.
	$option_prefix = 'pcptpages_groupings_';
	$option_rows   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $option_prefix ) . '%'
		)
	);
	if ( is_array( $option_rows ) ) {
		foreach ( $option_rows as $option_row ) {
			delete_option( $option_row );
		}
	}

	// Connector rate-limit transients (added in Phase 3+; cleaning here is
	// future-proof and harmless if no transients exist).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_pcptpages_connector_rate_%',
			'_transient_timeout_pcptpages_connector_rate_%'
		)
	);

	// Source-resolver caches (added in Phase 2+).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_pcptpages_source_%',
			'_transient_timeout_pcptpages_source_%'
		)
	);

	// ---------------------------------------------------------------------------
	// Conditional removal: per-post groupings.
	// ---------------------------------------------------------------------------

	if ( $delete_all_data ) {
		// User opted into full deletion. Remove every PRE-owned post meta key
		// across the entire site, in one query per key. This catches posts in
		// any post status (published, draft, trash) and any post type.
		$pcptpages_meta_keys = array(
			'_pcptpages_groupings',
			'_pcptpages_groupings_backup',
			'_pcptpages_groupings_backup_time',
			'_pcptpages_groupings_backup_user',
			'_pcptpages_groupings_backup_source',
			'_pcptpages_position_overrides',
			'_pcptpages_icon',
		);

		foreach ( $pcptpages_meta_keys as $pcptpages_meta_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $pcptpages_meta_key ) );
		}
	}

	// Note: We deliberately do NOT delete the posts that belonged to registered
	// CPTs. Those posts contain user content (titles, main-editor body, etc.)
	// independent of this plugin. If the user wants to delete the posts, they
	// can do so through the WordPress admin — destroying user content during
	// uninstall is never the right default.
}

pcptpages_run_uninstall_cleanup();
