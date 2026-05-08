<?php
/**
 * Capability mapping for Post Runtime Engine.
 *
 * Helpers for resolving CPT capability requirements at admin permission
 * checkpoints. CPTs registered through this plugin set `map_meta_cap => true`
 * and a `capability_type` (default `post`), which causes WordPress to derive
 * the standard `edit_*`, `publish_*`, `delete_*` capabilities automatically.
 *
 * In v1.0 this class is a thin set of static helpers — it does NOT add or
 * remove role capabilities at activation/deactivation time. The default
 * `capability_type=post` reuses the existing `edit_posts` / `publish_posts`
 * capabilities, which administrators and editors already have. v1.1 may
 * introduce a per-CPT custom capability set with role-grant logic; until
 * then, capability checks resolve against WP core's built-in role grants.
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
	 * Capability required to manage CPT registrations and grouping
	 * definitions through the admin UI or the connector.
	 *
	 * Site-level configuration (CPT registry, grouping definitions, plugin
	 * settings) maps to `manage_options` because adding or removing a CPT
	 * is a site-shaping action, not a content action.
	 */
	const MANAGE_CAP = 'manage_options';

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
	 * Whether the current user can manage Post Runtime Engine site config
	 * (CPT registrations, grouping definitions, plugin settings).
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		/**
		 * Filter the capability required to manage Post Runtime Engine
		 * configuration. Default is `manage_options`. Tighten or loosen
		 * according to your site's role model.
		 *
		 * @param string $cap Capability name.
		 */
		$cap = apply_filters( 'pre_manage_capability', self::MANAGE_CAP );
		return current_user_can( $cap );
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
