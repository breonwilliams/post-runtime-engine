<?php
/**
 * Capability mapping for Promptless CPT Pages.
 *
 * Centralizes the plugin's custom capability so callers never hard-code the
 * string. All site-config checkpoints (CPT registration, grouping definitions,
 * connector site-config endpoints, plugin admin UI) go through the
 * MANAGE_CAP constant.
 *
 * Capability model:
 *   - `pre_manage_cpts`: Controls access to CPT registration, grouping
 *     definition CRUD, plugin admin UI, and the Cowork connector's site-config
 *     endpoints. Per-post grouping editing falls back to the standard
 *     CPT-derived capabilities (`edit_posts` / `edit_post`) which administrators
 *     and editors already have.
 *
 * Granted to the `administrator` role by default on install and upgrade.
 * Admins can delegate to additional roles via the
 * `pre_default_manage_cpts_roles` filter (fires at activation/upgrade) or by
 * calling `add_cap()` from a small mu-plugin. The `pre_manage_capability`
 * filter remains available for runtime overrides (e.g., mapping the check to
 * `manage_options` on a site that explicitly wants to keep the legacy
 * behavior).
 *
 * Family alignment: mirrors FRE_Capabilities (`fre_manage_forms`) and
 * FMW_Capabilities (`flowmint_manage_workflows`). Each plugin in the
 * Promptless ecosystem owns a scoped capability so multi-user sites can
 * delegate per-plugin access without granting site-wide `manage_options`.
 *
 * @package PostRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability resolver and permission helpers.
 */
class PRE_Capabilities {

	/**
	 * Capability required to manage CPT registrations, grouping definitions,
	 * plugin settings, and connector site-config endpoints.
	 *
	 * Use `current_user_can( PRE_Capabilities::MANAGE_CAP )` everywhere a
	 * check is needed. Do not hard-code the string elsewhere.
	 *
	 * Default-granted to `administrator` on activation and on every plugin
	 * version upgrade. Override the filter `pre_default_manage_cpts_roles` to
	 * grant additional roles at install time, or `pre_manage_capability` for
	 * runtime overrides.
	 *
	 * @var string
	 */
	const MANAGE_CAP = 'pre_manage_cpts';

	/**
	 * Roles that receive MANAGE_CAP by default on install and upgrade.
	 *
	 * Extendable via the `pre_default_manage_cpts_roles` filter so site owners
	 * can opt additional roles in at activation time rather than granting the
	 * capability manually after the fact.
	 *
	 * @return array Array of role slugs.
	 */
	public static function default_roles() {
		/**
		 * Filters the roles that receive the MANAGE_CAP capability by default.
		 *
		 * Fires during activation and plugin-version upgrades. Does not fire on
		 * every page load.
		 *
		 * @param array $roles Default roles (administrator only by default).
		 */
		return apply_filters(
			'pre_default_manage_cpts_roles',
			array( 'administrator' )
		);
	}

	/**
	 * Grant the MANAGE_CAP capability to the default roles.
	 *
	 * Idempotent: WordPress's `add_cap()` is safe to call repeatedly. Only
	 * persists to the database when the capability is not already present
	 * on the role.
	 *
	 * Called from:
	 *   - Plugin activation hook (fresh installs and re-activations)
	 *   - Plugin version upgrade handler (so existing installs pick up the
	 *     capability the first time they boot a version that grants it)
	 */
	public static function grant_default_capabilities() {
		foreach ( self::default_roles() as $role_slug ) {
			$role = get_role( $role_slug );

			if ( null === $role ) {
				continue;
			}

			if ( ! $role->has_cap( self::MANAGE_CAP ) ) {
				$role->add_cap( self::MANAGE_CAP );
			}
		}
	}

	/**
	 * Remove the MANAGE_CAP capability from every role.
	 *
	 * Called only during plugin uninstall. Iterates all roles (not just the
	 * default-granted ones) because admins may have delegated the capability
	 * to custom roles; uninstall must clean up all traces.
	 */
	public static function revoke_all_capabilities() {
		$roles = wp_roles();

		if ( ! $roles instanceof WP_Roles ) {
			return;
		}

		foreach ( array_keys( $roles->role_objects ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( null === $role ) {
				continue;
			}

			if ( $role->has_cap( self::MANAGE_CAP ) ) {
				$role->remove_cap( self::MANAGE_CAP );
			}
		}
	}

	/**
	 * Resolve the `edit_post` capability for a given CPT slug.
	 *
	 * Used by admin permission callbacks and connector endpoints that act
	 * on a specific post.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return string Capability name (e.g., 'edit_posts', 'edit_listings').
	 */
	public static function edit_cap_for( $cpt_slug ) {
		$cpt = get_post_type_object( $cpt_slug );
		if ( $cpt && isset( $cpt->cap->edit_posts ) ) {
			return $cpt->cap->edit_posts;
		}
		return 'edit_posts';
	}

	/**
	 * Resolve the `publish_posts` equivalent capability for a given CPT.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return string Capability name.
	 */
	public static function publish_cap_for( $cpt_slug ) {
		$cpt = get_post_type_object( $cpt_slug );
		if ( $cpt && isset( $cpt->cap->publish_posts ) ) {
			return $cpt->cap->publish_posts;
		}
		return 'publish_posts';
	}

	/**
	 * Resolve the `delete_post` equivalent capability for a given CPT.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return string Capability name.
	 */
	public static function delete_cap_for( $cpt_slug ) {
		$cpt = get_post_type_object( $cpt_slug );
		if ( $cpt && isset( $cpt->cap->delete_posts ) ) {
			return $cpt->cap->delete_posts;
		}
		return 'delete_posts';
	}

	/**
	 * Whether the current user can manage Promptless CPT Pages site config
	 * (CPT registrations, grouping definitions, plugin settings, connector
	 * site-config endpoints).
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::required_capability() );
	}

	/**
	 * Resolve the runtime capability required to manage the plugin.
	 *
	 * Defensively wraps the `pre_manage_capability` filter so call sites get
	 * a single source of truth. Returns the constant directly when no filter
	 * is registered.
	 *
	 * @return string Capability slug.
	 */
	public static function required_capability() {
		/**
		 * Filter the capability required to manage Promptless CPT Pages
		 * configuration. Default is `pre_manage_cpts`. Override to map the
		 * check to `manage_options` (legacy behavior) or to a custom
		 * capability your role model already uses.
		 *
		 * @param string $cap Capability name.
		 */
		$cap = apply_filters( 'pre_manage_capability', self::MANAGE_CAP );

		if ( ! is_string( $cap ) || $cap === '' ) {
			return self::MANAGE_CAP;
		}

		return $cap;
	}

	/**
	 * Whether the current user can edit posts of the given CPT.
	 *
	 * @param string   $cpt_slug CPT slug.
	 * @param int|null $post_id  Optional specific post ID for per-post checks.
	 * @return bool
	 */
	public static function current_user_can_edit_post( $cpt_slug, $post_id = null ) {
		$cap = self::edit_cap_for( $cpt_slug );

		if ( $post_id !== null ) {
			// Per-post check uses map_meta_cap to resolve `edit_post`
			// against the specific object — handles author ownership.
			return current_user_can( 'edit_post', (int) $post_id );
		}

		return current_user_can( $cap );
	}

	/**
	 * Whether the current user can publish posts of the given CPT.
	 *
	 * @param string $cpt_slug CPT slug.
	 * @return bool
	 */
	public static function current_user_can_publish( $cpt_slug ) {
		return current_user_can( self::publish_cap_for( $cpt_slug ) );
	}

	/**
	 * Whether the current user can delete a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function current_user_can_delete_post( $post_id ) {
		return current_user_can( 'delete_post', (int) $post_id );
	}
}
