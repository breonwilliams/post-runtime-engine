=== Post Runtime Engine ===
Contributors: flowmint
Tags: custom post types, post template, structured content, custom fields, single page
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Render CPT single pages with structured data display, layout variants, and admin or AI population. Works standalone or with Promptless WP.

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

= 0.3.3 =
* New: Admin meta-box icon picker rebuilt as a compact `<select>` dropdown grouped by icon category — replaces the 53-button visual quickpick grid that was burning ~330px of vertical space per item. The Iconify text input above the dropdown is unchanged, so any of the 200,000+ Iconify codes can still be typed directly when the curated 53 don't have what you need.
* New: Per-grouping variant gating in the meta box. When a grouping's effective layout variant is icon-only (compact-grid or horizontal-row), the "Add image" button hides per-item and an amber notice appears at the top of the grouping explaining that uploaded images are dropped at render time. Mirrors PRE_Renderer's variant-aware media resolution so the meta box never silently accepts data the renderer will discard.
* New: `--pre-icon-size` CSS custom property drives icon dimensions on the frontend. Every per-variant override sets one value and width / height / font-size all pick it up, keeping legacy SVG icons and Iconify web components rendered at matching sizes. Adding a new variant in the future automatically gets correct sizing for both render paths.
* New: Server-side HTML→Gutenberg blocks converter in the connector. `POST /posts` and `PUT /posts/{id}` now auto-wrap raw HTML in proper Gutenberg block delimiters when the input has no `<!-- wp: -->` markers. Idempotent — block-format input passes through unchanged with zero parsing cost. Handles h1-h6 (with level attr for non-h2), p, ul / ol (with ordered:true attr), blockquote, pre, hr, and figure-with-img; unrecognized top-level elements fall through to core/html, matching what Gutenberg's "Convert to blocks" does.
* New: `critical_rules.post_content_is_gutenberg_blocks` replaces `post_content_is_html` in the connector preflight, documenting the canonical block-format contract with examples for every supported block type. The server converter is described as a defense-in-depth safety net, not a feature — AI agents that send block format directly get faster writes and full control over block attributes (heading levels, list types).
* Improved: Item layout in the admin meta box. Item height shrunk 360px → 229px (36% smaller), media column width reduced 220px → 160px (frees horizontal space for the fields column), preview tile 96×96 → 64×64, icon helper text compressed to a single line.
* Fixed: Image upload silently failed on profile cards (card-grid + featured-card variants). Root cause: `setItemImage` referenced an undefined `$iconSelect` variable carried over from when the icon picker was a single `<select>` element. The `ReferenceError` threw after image_id was stored in the hidden input but before the thumbnail preview rendered, so the image was persisted on save with no visual confirmation. `$iconSelect` is now properly scoped from `$item.find('.pre-item__icon-select')` and the function runs to completion.
* Fixed: Iconify icon sizing mismatch on the frontend. `<iconify-icon>` web components were rendering their inner shadow-DOM SVG at 1em (16px default font-size) instead of matching the element's CSS width/height, so an Iconify code like `fluent:chat-20-regular` painted at 16×16 inside a 32×32 wrapper — visibly smaller than the curated SVG icons next to it. The single-source-of-truth `--pre-icon-size` variable now drives font-size alongside width/height, so both render paths land at the same size for every variant.

= 0.3.2 =
* New: Dual-format icon system. `icon_id` and `default_icon` now accept BOTH the existing curated 53-icon library AND any Iconify code in `collection:name` form (e.g. `mdi:home`, `logos:wordpress`, `material-symbols:business-outline`, `fa6-solid:tooth`). Curated IDs render as inline SVG (zero network), Iconify codes render via the `<iconify-icon>` web component. ~200,000 additional icons across 100+ Iconify sets, browseable at icon-sets.iconify.design. Restores icon vocabulary parity with Promptless WP for connector-driven page-building workflows.
* New: Admin meta-box icon control rewritten as a monospace text input + a 28-px-thumb quick-pick row of the curated 53. Type any Iconify code; the preview updates live. Click a quick-pick to insert a curated ID. Single picker covers both formats.
* New: Connector `GET /icons` response gains an `iconify` block (format pattern, browse URL, full legacy → Iconify map, render-pattern hint) and a per-icon `iconify_code` field so AI consumers learn the dual-format contract in one round trip. The MCP `postruntime_list_icons` description rewritten accordingly.
* New: `PRE_Icon_Library::is_valid_id()`, `is_iconify_format()`, `legacy_to_iconify()`, `get_legacy_iconify_map()`, plus `MAX_ICONIFY_LENGTH` constant. Public surface so themes / third-party plugins can validate icon IDs the same way the plugin does.
* Changed: Validator switches from `PRE_Icon_Library::has()` to `is_valid_id()` at both call sites (CPT default_icon, grouping item icon_id). Error messages now point at both discovery paths.
* Changed: Frontend asset enqueue adds the iconify-icon web-component module (~20kb gzipped, jsdelivr CDN) on registered CPT singles so Iconify codes paint correctly without per-page detection. Same module Promptless WP enqueues — browser caches one copy across pages.
* Internal: 15 new smoke assertions in `tests/smoke-phase1.php` + two new unit-test methods in `tests/Unit/ValidatorTest.php` covering accept (5 Iconify formats) and reject (6 malformed shapes including over-length and uppercase) paths.

= 0.3.1 =
* Plugin-checker compliance pass: relocated translator comments to satisfy `WordPress.WP.I18n.MissingTranslatorsComment`, swapped `parse_url()` for `wp_parse_url()`, added `phpcs:ignore` reasons to trusted-internal output sites where the Icon Library's SVG is surfaced.

= 0.3.0 =
* Hosted pressure-test hardening: connector now ships a `critical_rules` rulebook and `field_name_hints` in preflight, defensive CDATA sanitization on `post_content`, link-aware cross-CPT `default_icon` resolution, `postruntime_update_post` tool, and a `_site` envelope on every connector response. Smoke suite extended from 99 to 138 assertions.

= 0.2.0 =
* Frontend rendering: all four layout variants (compact-grid, card-grid, featured-card, horizontal-row) and three source modes (manual, child_posts, taxonomy_match).

= 0.1.0 =
* Initial release: CPT registry, grouping definitions, admin meta box with variant override, three layout positions, single-position rendering.

== Upgrade Notice ==

= 0.3.4 =
Connector admin page UI cleanup. Status card now shows connection state at a glance with the kill-switch toggle inline. Setup steps are cleaner. App password check works correctly on local dev environments. No data changes.

= 0.3.3 =
Admin meta-box UX rebuild plus icon and content-authoring bug fixes. Icon picker is now a category-grouped dropdown; Iconify text input still accepts 200,000+ codes. Variant gating prevents image-upload buttons from showing on icon-only layouts. Recommended for all users.

= 0.3.2 =
Icon system is now dual-format: curated IDs keep working alongside any Iconify code (200,000+ icons). Admin dropdown becomes a text input + quick-pick row. No data migration required. Recommended for all users.

= 0.3.1 =
Compatibility update — passes WordPress.org Plugin Check cleanly. No feature or behavior changes.
