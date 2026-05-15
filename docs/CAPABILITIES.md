# Post Runtime Engine Capabilities

Post Runtime Engine registers one custom WordPress capability for site-config
operations and uses the standard CPT-derived capabilities for per-post editing.
The two are separate by design: site-shaping actions (registering a CPT,
defining groupings, enabling the connector) are gated by the scoped capability;
content actions (creating a Listing, editing a Job posting) are gated by the
CPT's own `edit_posts` / `edit_post` derivation.

## The site-config capability

**`pre_manage_cpts`** — Controls access to:

- The Post Runtime admin pages (Post Types, Connector)
- The CPT registry (registering, editing, removing CPTs)
- The grouping definition CRUD
- The connector REST endpoints that read or modify site-level configuration
- The connector admin page (Application Password generate/revoke)

Constant in code: `PRE_Capabilities::MANAGE_CAP`.

Filter for runtime override: `pre_manage_capability` (returning a different
capability slug remaps every check). Use sparingly — the default is the right
answer for nearly all deployments.

## Per-post editing capabilities (unchanged)

Per-post grouping editing falls back to the standard CPT capability set
WordPress derives from `capability_type`. With the default
`capability_type=post`, those resolve to `edit_posts` / `edit_post` /
`publish_posts` / `delete_post`, which administrators and editors already
have. PRE provides resolver helpers so callers don't hard-code the strings:

| Helper | Use case |
|---|---|
| `PRE_Capabilities::edit_cap_for($cpt_slug)` | Resolve the per-CPT edit capability (`edit_posts` or a custom set) |
| `PRE_Capabilities::publish_cap_for($cpt_slug)` | Resolve the per-CPT publish capability |
| `PRE_Capabilities::delete_cap_for($cpt_slug)` | Resolve the per-CPT delete capability |
| `PRE_Capabilities::current_user_can_edit_post($cpt_slug, $post_id)` | Per-post check (uses `edit_post` with map_meta_cap) |

These helpers DO NOT require `pre_manage_cpts` — they intentionally reuse
WordPress's CPT capability map so existing roles and per-post ownership
checks continue to work.

## Default role grants

On plugin activation and on every plugin data-version upgrade
(`PRE_DATA_VERSION` bump), `pre_manage_cpts` is granted to:

- `administrator`

The grant is idempotent — WordPress's `add_cap()` is a no-op when the role
already has the capability, so multiple calls are safe.

## Granting to other roles

### Option A — Hook the filter (preferred)

Add this snippet to a site-specific plugin or your theme's `functions.php`.
Fires once at activation / data-version bump time:

```php
add_filter( 'pre_default_manage_cpts_roles', function ( $roles ) {
    $roles[] = 'editor';      // also grant to editors
    $roles[] = 'shop_manager'; // also grant to WooCommerce shop managers
    return $roles;
} );
```

After adding the filter, deactivate and reactivate Post Runtime Engine (or
trigger a data-version bump) for the grant to apply to the new roles.

### Option B — Grant manually via WP-CLI

For one-off grants without changing code:

```bash
wp cap add editor pre_manage_cpts
wp cap add shop_manager pre_manage_cpts
```

### Option C — Grant programmatically

For dynamic role-management plugins (Members, User Role Editor, etc.), use
the standard `WP_Role::add_cap()` API:

```php
$role = get_role( 'editor' );
if ( $role ) {
    $role->add_cap( 'pre_manage_cpts' );
}
```

## Overriding the required capability (enterprise)

For enterprise installs that want admin / connector access gated on a
different capability — e.g. a custom role from an SSO mapping — use the
`pre_manage_capability` filter:

```php
add_filter( 'pre_manage_capability', function () {
    return 'my_custom_pre_admin_cap';
} );
```

This swaps the capability that REST endpoints, admin pages, and the
connector check, but does NOT change which capability is granted on
activation. Use sparingly.

## Migrating from pre-v0.4.0

Before v0.4.0 (data-version 0.2.0), Post Runtime Engine checked
`manage_options` everywhere. The v0.4.0 upgrade introduces `pre_manage_cpts`
and:

1. **Existing administrators:** unaffected. The new capability is granted
   to administrator on the first page load after the upgrade runs (via the
   data-version bump handler), so admin access continues uninterrupted.
2. **Non-admin roles previously locked out:** can now be granted access
   cleanly via any of the three methods above.
3. **Filter overrides:** if a site previously used `user_has_cap` filters
   to grant `manage_options` solely for PRE access, those can now be
   removed in favor of the scoped capability.
4. **Custom call sites that hard-coded `manage_options`:** swap the string
   to `PRE_Capabilities::MANAGE_CAP`. Any code path that calls
   `current_user_can( 'manage_options' )` for PRE-related gating will
   continue to work for super-admins but stops working correctly for
   delegated PRE-only roles — the scoped check handles both cases.

## Cleanup on uninstall

When the plugin is deleted (not just deactivated) via the WP Plugins admin
page, PRE's `uninstall.php` calls `PRE_Capabilities::revoke_all_capabilities()`,
which iterates every WordPress role and removes the capability. This catches
custom roles that admins may have granted the capability to via `add_cap`
directly.

## Pattern parity across the Promptless plugin family

This pattern aligns with:

- **FRE (Form Runtime Engine):** `fre_manage_forms` via `FRE_Capabilities::MANAGE_FORMS`
- **FlowMint Workflows:** `flowmint_manage_workflows` via `FMW_Capabilities::MANAGE_WORKFLOWS` (see [FlowMint CAPABILITIES.md](../../flowmint-workflows/docs/CAPABILITIES.md))
- **Promptless WP:** `promptless_manage_settings` via `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` (see [Promptless CAPABILITIES.md](../../ai-section-builder-modern/docs/development/CAPABILITIES.md))

Each plugin owns its own scoped capability. Multi-user sites (agencies with
client editors, e-commerce teams with marketing roles, nonprofit volunteer
setups) can grant per-plugin access without giving up site-wide super-admin.

### Capability summary across the family

| Plugin | Capability | Constant | Granted to (default) |
|---|---|---|---|
| Form Runtime Engine | `fre_manage_forms` | `FRE_Capabilities::MANAGE_FORMS` | `administrator` |
| FlowMint Workflows | `flowmint_manage_workflows` | `FMW_Capabilities::MANAGE_WORKFLOWS` | `administrator` |
| Post Runtime Engine | `pre_manage_cpts` | `PRE_Capabilities::MANAGE_CAP` | `administrator` |
| Promptless WP | `promptless_manage_settings` | `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` | `administrator` |

Each plugin's `default_*_roles()` (or equivalent) is filterable so the same
site-wide grant pattern works on any role model. Each plugin's
`revoke_all_capabilities()` runs on uninstall so role tables stay clean.
