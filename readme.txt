=== Post Runtime Engine ===
Contributors: flowmint
Tags: custom post types, cpt, page builder, post template, structured content
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Renders WordPress custom-post-type single pages with structured data display through Promptless WP's design system. Companion plugin to Form Runtime Engine and Promptless WP.

== Description ==

Post Runtime Engine (PRE) provides a constrained, opinionated primitive for repeatable structured content on custom-post-type single pages:

* Register custom post types from an admin UI — no ACF / MetaBox / Pods dependency.
* Define "groupings" per CPT — named clusters of items sharing a layout variant and a position (above main / below main / sidebar).
* Items follow one shape — `{ image-or-icon, heading, supporting_text, optional link }`.
* Four layout variants per grouping — compact-grid, card-grid, featured-card, horizontal-row.
* Three source modes — `manual`, `child_posts`, `taxonomy_match`.
* Curated icon library of 53 icons across 13 categories, extensible via the `pre_icon_library` filter.
* Connector REST API + MCP tools (18 endpoints under `/wp-json/post-runtime/v1/connector/`) so AI assistants like Claude Cowork can register CPTs, define groupings, populate per-post values, and preview rendered output.
* Design-token inheritance from Promptless WP — colors, spacing, typography, radii. Graceful fallback when Promptless is not installed.

PRE is positioned as a free companion plugin to Promptless WP (the page builder) and Form Runtime Engine (the form renderer). It owns dynamic CPT single-page rendering; it does not replace Promptless for landing-page composition or FRE for forms.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WP Admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Visit **Post Runtime → CPTs** in the admin to register your first custom post type.
4. To enable the Claude Cowork connector, visit **Post Runtime → Claude Connection** and follow the setup steps.

For full documentation see `CLAUDE.md` and `docs/` inside the plugin folder.

== Frequently Asked Questions ==

= Does this plugin require Promptless WP? =

No. PRE renders standalone with sensible default styling. When Promptless WP is active, PRE automatically inherits its `--aisb-*` design tokens for visual consistency.

= Can I use existing ACF / MetaBox fields with PRE groupings? =

Not in this version. PRE owns its own field model end-to-end via grouping items. ACF interop is out of scope for v0.x.

= Where is the data stored? =

Per-post grouping values live in WordPress post meta. CPT and grouping definitions live in `wp_options`. No custom database tables.

== Changelog ==

= 0.3.1 =
* Plugin-checker compliance pass: relocated translator comments to satisfy `WordPress.WP.I18n.MissingTranslatorsComment`, swapped `parse_url()` for `wp_parse_url()`, added `phpcs:ignore` reasons to trusted-internal output sites where the Icon Library's SVG is surfaced.

= 0.3.0 =
* Hosted pressure-test hardening: connector now ships a `critical_rules` rulebook and `field_name_hints` in preflight, defensive CDATA sanitization on `post_content`, link-aware cross-CPT `default_icon` resolution, `postruntime_update_post` tool, and a `_site` envelope on every connector response. Smoke suite extended from 99 to 138 assertions.

= 0.2.0 =
* Frontend rendering: all four layout variants (compact-grid, card-grid, featured-card, horizontal-row) and three source modes (manual, child_posts, taxonomy_match).

= 0.1.0 =
* Initial release: CPT registry, grouping definitions, admin meta box with variant override, three layout positions, single-position rendering.

== Upgrade Notice ==

= 0.3.1 =
Compatibility update — passes WordPress.org Plugin Check cleanly. No feature or behavior changes.
