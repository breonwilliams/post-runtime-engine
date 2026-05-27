<?php
/**
 * Cowork connector settings storage.
 *
 * Central accessor for the connector's persisted state:
 *   - Enabled toggle (default OFF — opt-in, matches FRE pattern)
 *   - Per-user "configured" hint (presence of a generated App Password
 *     for this connector — informational only; the actual credential
 *     lives in WordPress core's Application Password storage which this
 *     plugin never reads or caches)
 *
 * Deliberately a pure data-access class: no UI, no REST, no auth
 * decisions. PRE_Connector_Auth reads the toggle, PRE_Connector_Admin
 * writes it.
 *
 * @package PostRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
 *
 * Justification: delete_all() runs once during uninstall to clean up
 * user-meta markers across all users. Direct query is required (the
 * meta_key scan affects multiple users, not a single user); caching
 * is irrelevant since the site is being torn down.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connector settings accessor.
 */
class PRE_Connector_Settings {

	/**
	 * Option key storing the master toggle.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'pre_connector_enabled';

	/**
	 * User-meta key marking that a user has generated a connector App
	 * Password at some point. Presence is an informational hint for the
	 * admin UI; the actual credential lives in core's Application
	 * Password storage which this plugin never reads or caches.
	 *
	 * @var string
	 */
	const USER_META_CONFIGURED = '_pre_connector_configured_at';

	/**
	 * App Password "name" used when creating the connector credential
	 * via WP_Application_Passwords::create_new_application_password().
	 * Used as the matching key when revoking the connector's prior
	 * credential before issuing a new one.
	 *
	 * @var string
	 */
	const APP_PASSWORD_NAME = 'Promptless CPT Pages — Claude Cowork';

	/**
	 * Is the connector enabled site-wide?
	 *
	 * Default: false. The connector is opt-in per the §2 design — a site
	 * administrator must explicitly enable it before any external agent
	 * can use the REST endpoints.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Enable or disable the connector.
	 *
	 * @param bool $enabled Desired state.
	 * @return bool True on success.
	 */
	public static function set_enabled( $enabled ) {
		return update_option( self::OPTION_ENABLED, (bool) $enabled );
	}

	/**
	 * Mark the current user as having configured the connector (i.e.
	 * generated an App Password). Stamps user meta with the time so the
	 * admin UI can show "Last configured: …".
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool True on success.
	 */
	public static function mark_user_configured( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::USER_META_CONFIGURED, time() );
	}

	/**
	 * When did the user last configure the connector?
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return int Unix timestamp, or 0 if never configured.
	 */
	public static function get_user_configured_at( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}
		$ts = get_user_meta( $user_id, self::USER_META_CONFIGURED, true );
		return (int) $ts;
	}

	/**
	 * Clear the per-user configured marker.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool True on success.
	 */
	public static function clear_user_configured( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) delete_user_meta( $user_id, self::USER_META_CONFIGURED );
	}
}
