<?php
/**
 * Uninstall handler for Promptless CPT Pages.
 *
 * Runs ONCE when an administrator deletes the plugin via the WordPress
 * Plugins screen (NOT on deactivation). At this point the plugin's PHP
 * is no longer loaded — only this file runs.
 *
 * Behavior:
 *   - KEEPS the site's data by default: record-type, grouping and post-field
 *     definitions, settings, and every record's values. Definitions are the
 *     only copy of what makes the records render — re-installing picks up
 *     where the site left off. Up to 0.10.0 this file always deleted the
 *     type and grouping definitions (the same loss the connector's
 *     delete_cpt stopped causing in 0.8.0) while keeping field definitions,
 *     and left the connector switch and render markers behind.
 *   - ALWAYS removes housekeeping: transients (render cache, source cache,
 *     connector rate limits), the calendar-feed rewrite bookkeeping, render
 *     markers, the connector switch and the capability grants.
 *   - With consent — `pcptpages_settings.delete_data_on_uninstall`, or
 *     `define( 'PCPTPAGES_REMOVE_ALL_DATA', true );` in wp-config.php (the
 *     plugin has no settings screen; WooCommerce's pattern) — removes every
 *     definition and option and all `_pcptpages_*` post meta: grouping and
 *     field values, visibility, external identity, backups.
 *   - NEVER deletes posts: records are ordinary WordPress content.
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

	// --- Always: housekeeping, no user data. -------------------------------

	// Transients: the render cache, source-resolver caches, connector rate
	// limits.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_pcptpages_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pcptpages_' ) . '%'
		)
	);

	// Render-cache markers (pcptpages_gchanged_*) and the calendar-feed
	// rewrite bookkeeping: derived state, rebuilt on demand.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'pcptpages_gchanged_' ) . '%'
		)
	);
	delete_option( 'pcptpages_ics_feed_rules' );

	// Access, not content.
	delete_option( 'pcptpages_connector_enabled' );

	// Revoke the scoped capability from every role; activation grants it
	// again. The autoloader does not run during uninstall.
	require_once __DIR__ . '/includes/Core/class-pre-capabilities.php';
	PCPTPages_Capabilities::revoke_all_capabilities();

	// --- Only with consent: the site's data. ------------------------------

	$settings = get_option( 'pcptpages_settings', array() );
	$consent  = ( defined( 'PCPTPAGES_REMOVE_ALL_DATA' ) && PCPTPAGES_REMOVE_ALL_DATA )
		|| ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) );
	if ( ! $consent ) {
		return;
	}

	// Every definition and option: types, groupings, post fields, settings,
	// data version, the removed-types tombstone.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'pcptpages_' ) . '%'
		)
	);

	// Every record's values: groupings, field values and their companions,
	// visibility, external identity, backups, icons, position overrides.
	// Up to 0.10.0 the opt-in removed only the grouping keys, leaving every
	// field value behind.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_pcptpages_' ) . '%'
		)
	);

	// Posts are deliberately NOT deleted: titles, content and featured
	// images are ordinary WordPress content the site owner can remove.
}

pcptpages_run_uninstall_cleanup();
