=== Promptless CPT Pages ===
Contributors: promptlesswp
Tags: custom post types, post template, structured content, custom fields, single page
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Render CPT single pages with structured data display, layout variants, and admin or AI population. Works standalone or with Promptless WP.

== Description ==

Promptless CPT Pages provides a constrained, opinionated primitive for repeatable structured content on custom-post-type single pages:

* Register custom post types from an admin UI — no ACF / MetaBox / Pods dependency.
* Define "groupings" per CPT — named clusters of items sharing a layout variant and a position (above main / below main / sidebar).
* Items follow one shape — `{ image-or-icon, heading, supporting_text, optional link }`.
* Four layout variants per grouping — compact-grid, card-grid, featured-card, horizontal-row.
* Three source modes — `manual`, `child_posts`, `taxonomy_match`.
* Curated icon library of 53 icons across 13 categories, extensible via the `pre_icon_library` filter.
* Connector REST API + MCP tools (18 endpoints under `/wp-json/post-runtime/v1/connector/`) so AI assistants like Claude Cowork can register CPTs, define groupings, populate per-post values, and preview rendered output.
* Design-token inheritance from Promptless WP — colors, spacing, typography, radii. Graceful fallback when Promptless is not installed.

Promptless CPT Pages is positioned as a free companion plugin to Promptless WP (the page builder) and Promptless Forms (the form renderer). It owns dynamic CPT single-page rendering; it does not replace Promptless for landing-page composition or Promptless Forms for forms.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WP Admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Visit **Post Runtime → CPTs** in the admin to register your first custom post type.
4. To enable the Claude Cowork connector, visit **Post Runtime → Claude Connection** and follow the setup steps.

For full documentation see `CLAUDE.md` and `docs/` inside the plugin folder.

== Frequently Asked Questions ==

= Does this plugin require Promptless WP? =

No. PRE renders standalone with sensible default styling. When Promptless WP is active, PRE automatically inherits its `--aisb-*` design tokens for visual consistency.

= Can I use existing ACF / MetaBox fields with groupings? =

Not in this version. Promptless CPT Pages owns its own field model end-to-end via grouping items. ACF interop is out of scope for v0.x.

= Where is the data stored? =

Per-post grouping values live in WordPress post meta. CPT and grouping definitions live in `wp_options`. No custom database tables.

== External Services ==

This plugin connects to one third-party service: the Iconify API. It is used solely to display icons that you choose to use, and only when you opt into using them. If you do not use Iconify icons, no external service is ever contacted.

= Iconify API =

What it is and what it is for: the plugin bundles the open-source `iconify-icon` web component locally (`assets/js/iconify-icon.min.js` — it is shipped inside the plugin and is NOT loaded from any CDN). When a CPT single page, archive card, grouping item, or post field uses an Iconify-format icon identifier written in `collection:name` form (for example `mdi:home` or `material-symbols:business-outline`), the web component requests that single icon's SVG path data from the Iconify API at render time so the icon can be displayed. This is what makes 200,000+ open-source icons available without bundling them all into the plugin.

What data is sent, and when: only the icon identifier you chose to use (for example `mdi:home`) is sent, as part of the request URL, at the moment a page containing that icon is viewed in a visitor's browser. The request is made by the visitor's browser — not by your server. No personal data, no user content, no site URL, and no identifiers of any kind are transmitted.

When it is NOT contacted: the plugin also ships a built-in library of 53 icons that render inline as SVG with zero network requests. If you use only those built-in icons — or no icons at all — the Iconify API is never contacted.

Service provider: Iconify (Iconify OÜ).

Endpoints contacted: https://api.iconify.design (primary), with https://api.simplesvg.com and https://api.unisvg.com as automatic fallbacks used only if the primary endpoint is unreachable.

Terms of use: https://iconify.design/docs/api/ — the Iconify API is a free, open-source public service released under the Apache 2.0 License; the "Public API" section documents the terms of use.

Privacy policy: https://iconify.design/privacy/

== Screenshots ==

1. Admin UI for registering a custom post type — name, slug, labels, icon picker, archive on/off
2. Grouping definitions per CPT — define a named cluster of items sharing a layout variant and a position (above main / below main / sidebar)
3. The per-post meta box for filling in grouping items, with the four layout-variant preview cards
4. Frontend render of a CPT single page with the compact-grid variant for an "amenities" grouping
5. Claude Cowork connector setup — opt-in App Password generation, default-disabled kill switch

== Changelog ==

= 0.6.7 =
* New: Gallery grouping variant — display a grouping's items as a responsive photo grid (3-up desktop / 2-up mobile) with a fully accessible lightbox (keyboard, touch swipe, reduced-motion support). Ideal for property photo tours, vehicle galleries, and portfolios; captions come from item headings and imageless items simply wait for their photo.
* New: Gallery tile aspect control per grouping definition — 16:9 (default), 4:3, 1:1, or 4:5 tile crops; the lightbox always shows the full image.
* Fix: Admin styles now load on all plugin admin screens (missing page hooks in the enqueue guard).
* Internal: bundled MCP connector synced with the gallery vocabulary; unit suite extended (117 tests).

= 0.6.6 =
* Fixed: grouping thumbnails rendered as thin vertical slivers in sidebar and horizontal card layouts. The image was the only item in the row the browser was allowed to shrink, so long text squeezed a 60px thumbnail down to ~15px. Only affected image-bearing groupings; icons were never large enough to trigger it.
* Added: per-CPT archive image crop (archive_image_aspect) - 16:9 (default), 4:3, 1:1, or 4:5 - answered to the theme's archive grid. People-centric CPTs like agents or team members typically want 1:1 or 4:5. Existing CPTs are unchanged.
* Changed: newly registered CPTs now default to hiding the post date and author byline on archive cards. These describe WordPress bookkeeping (when the record was created, and which account created it) rather than the record itself - an agent card bylined with the site admin's name is misleading. Existing CPTs keep their current setting; toggle either per CPT at any time.
* Fixed: neo-brutalist and lifted card styles no longer leak onto post-field chips in PostGrid.

= 0.6.5 =
* Fixed: deleting a stale duplicate copy of the plugin (two installs under different folder names) no longer wipes the shared configuration - cleanup now runs only when the last installed copy is removed.
* Added: meta_match groupings can now pull posts from a DIFFERENT post type - "this agent's listings" on an agent page, "area listings" on a neighborhood page. New optional source parameters: post_type (which CPT to query), match_against (compare against the current post's title, slug, or ID), and field_key (reference a post field instead of a raw meta key). Fully backward compatible - existing meta_match groupings behave identically.
* Added: activating an auto-populated grouping on a post now inherits the grouping's configured source automatically - a bare grouping entry just works instead of silently rendering nothing.
* Fixed: archive filters whose field key matches a WordPress query variable (like a post type named "neighborhood") no longer get hijacked by WordPress core - their URL parameters are automatically namespaced so the filter reaches the archive instead of redirecting away.
* Fixed: cached single-post pages now reload the stylesheets and scripts of shortcodes embedded in their content (e.g. Promptless Forms) - anonymous visitors previously could receive an unstyled form when the page was served from the render cache.
* Fixed: grouping card and hero images no longer collapse or leave gaps when an image optimizer (e.g. EWWW WebP delivery) wraps images in <picture> tags; card images also render at a uniform height regardless of photo aspect ratio, with sharper candidates on high-DPI screens.

= 0.6.4 =
* Added: Hero theme option (Inherit / Light band / Dark band) per CPT - force the single-post hero into a contrasting band independent of the page's light/dark mode. Colors flow from the site's design tokens with WCAG-corrected link/icon colors.
* Added: Hero width option (Contained / Full width) per CPT - full width bleeds the hero band edge-to-edge while the title, image, and metadata stay aligned with the page grid.
* Added: Overlay hero layout - the featured image fills the hero band and the title, badges, and metadata render on top of it over a darkening gradient that guarantees readable text over any photo. Posts without a featured image automatically fall back to the stacked treatment. New "Overlay image focus" option (Top / Center / Bottom) controls how the photo is cropped.
* Fixed: changing a post type's settings (hero layout, archive flags, labels) now refreshes cached single-page renders immediately instead of waiting out the cache lifetime.
* Fixed: status badge and chip colors (success / warning / danger / info) now meet WCAG AA contrast in every context - light pages, dark pages, dark hero bands, overlay heroes, and badges over images - regardless of the site's brand palette.

= 0.6.3 =
* Added: CPT single pages now emit a <meta name="description"> tag plus a compact OpenGraph/Twitter set, derived from the post excerpt/content, title, and featured image. Emits only when no dedicated SEO plugin is active and defers to Promptless-built pages. Clears the "Document does not have a meta description" SEO flag on CPT singles.

= 0.6.2 =
* Fixed: sidebar card-grid grouping items (icon, heading, link arrow) are now vertically centered, with the arrow pinned to the right edge — single-line items no longer look misaligned.
* Fixed: neutral chips (multi_badge) are now legible on a dark Content Theme and on dark sections; they could render dark-on-dark before.
* Changed: meta-strip field separators now use clean gap spacing instead of a middot character, so a separator never dangles when the strip wraps to multiple lines.

= 0.6.1 =
* Fixed: related-posts / grouping cards in the card layout now bleed their image to the card edges and follow the site's global Card Image Style, matching Promptless's own cards; the card's "clickable" arrow no longer overlaps the supporting text.
* Fixed: post-field meta (such as a date) inside a dark Post Grid card is now readable — it follows the dark section colors instead of staying light-on-light.
* Fixed: grouping data attached with `key` instead of `grouping_key` now saves correctly, and delete actions return a proper success response to the connector.

= 0.6.0 =
* New: the "Number with label" display type gains a Thousands separator option. Turn it off for years, model years, unit/lot numbers, and IDs so they render ungrouped (2019, not 2,019) while keeping the label.
* New: the connector can assign taxonomy terms (categories, tags, or any taxonomy registered for the CPT) when creating or updating a post. Terms can be names, slugs, or IDs and are created if they don't exist. Powers taxonomy-based archive facets and taxonomy_match groupings.
* Changed: minimum WordPress version is now 5.6 — required by Application Passwords (connector) and wp_date()/wp_timezone() (events).
* Changed: taxonomy filter facets now hide empty terms, so the filter never offers an option that returns zero results.
* Removed: the legacy GitHub auto-updater. Updates are delivered through the WordPress.org plugin directory.
* Fixed: feature / multi-badge pills are now clearly visible on cards (they previously matched the card background); the meta-strip separator no longer shows a "Â·" encoding artifact.

= 0.5.3 =
* WordPress.org review round 2: rewrote the Iconify External Services disclosure with a direct privacy-policy link (https://iconify.design/privacy/), a clearer terms-of-use link, an explicit note that the icon web component is bundled locally (not loaded from a CDN), and a precise data / when / endpoints breakdown. Also synced this changelog with the full version history. No code or behavior change.

= 0.5.2 =
* Fixed: post fields (image_overlay, headline, subtitle, meta_strip, footer_meta) now render correctly on Promptless WP PostGrid cards. A stale `function_exists( 'pre' )` guard left over from the v0.5.0 accessor rename was silently no-op'ing the entire card hook handler. No data migration.

= 0.5.1 =
* WordPress.org review round 1 fixes: nonce sanitization before wp_verify_nonce(), options_json and field_values sanitized at the admin boundary, a publish_posts capability check on the update_post REST handler, Iconify documented as an external service, and the GitHub auto-updater fully stripped from the WordPress.org build. No end-user behavior change.

= 0.5.0 =
* WordPress.org prefix-compliance rename: the 3-character `pre_` / `PRE_` prefix (below WP.org's 4-character minimum) was re-prefixed to `pcptpages_` / `PCPTPages_` across classes, hooks, options, the `pcptpages()` accessor, script handles and admin page slugs. `class_alias` keeps the old main-class name working. No data migration; no end-user behavior change.

= 0.4.1 =
* WordPress.org compliance pass. Class `Post_Runtime_Engine` renamed to `Promptless_CPT_Pages` (`class_alias` preserves backward compatibility for the old name). GitHub auto-updater instantiation gated by `class_exists` so the WP.org build (which excludes the updater) doesn't fatal. All inline `<script>` / `<style>` blocks moved to enqueued asset files (`assets/css/connector-admin.css`, `assets/js/connector-admin.js`, `assets/js/admin-groupings.js`), gated to their own admin pages. `esc_url_raw` → `esc_url` in one JS output context. Plugin URI, contributors username, and dev-internal markdown file exclusions corrected for the WP.org build. No data migration. No behavior change for end users.

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

= 0.6.7 =
Adds the Gallery grouping variant: responsive photo grids with an accessible lightbox and per-definition tile aspect (16:9/4:3/1:1/4:5) — ideal for property photo tours, vehicle galleries, and portfolios. Additive release; existing groupings are unchanged. Recommended for all users.

= 0.6.6 =
Fixes grouping thumbnails rendering as thin slivers in sidebar and horizontal card layouts. Adds a per-CPT archive image crop (square, 4:3, 4:5, 16:9) — useful for people-centric CPTs like agents or team members. New CPTs now default to hiding the post date and author byline on archive cards, since those describe WordPress bookkeeping rather than the record itself; existing CPTs are unchanged. Recommended for all users.

= 0.6.5 =
Cross-CPT relationships arrive: meta_match groupings can now auto-pull posts from another post type (an agent page listing its properties). Important fixes: archive filters named after post types no longer redirect away, cached pages no longer serve unstyled embedded forms to anonymous visitors, and image-optimizer <picture> wrapping no longer breaks card/hero image layout. Recommended for all users.

= 0.6.4 =
New hero design options per CPT: contrasting light/dark hero bands, full-width hero, and an overlay layout with text over the featured image. Fully opt-in - existing CPTs render identically until you change their Hero settings. Also fixes status badge contrast on dark backgrounds.

= 0.6.3 =
CPT single pages now emit a meta description plus OpenGraph/Twitter tags (when no SEO plugin is active), clearing the "Document does not have a meta description" SEO flag. Additive — no data or behavior changes.

= 0.6.2 =
Visual polish: sidebar card grouping alignment, legible neutral chips in dark mode, and cleaner meta-strip spacing. CSS-only — no data or behavior changes.

= 0.6.1 =
Bug-fix release: card-layout grouping images now bleed to the card edges and honor the global Card Image Style, the card arrow no longer overlaps the supporting text, dark Post Grid card meta is readable (WCAG AA), grouping data keyed by `key` now saves, and connector delete actions return a proper success response. Recommended for all users.

= 0.6.0 =
Adds an ungrouped-number option (for years, IDs, unit numbers) and connector taxonomy-term assignment, hides empty terms in taxonomy filters, raises the minimum WordPress version to 5.6, and removes the legacy self-updater (updates now come from the WordPress.org directory). Recommended for all users.

= 0.5.3 =
Documentation-only update: the External Services disclosure for the Iconify icon API now meets WordPress.org guidelines — a direct privacy-policy link, a clearer terms-of-use link, and an explicit note that the icon component is bundled locally rather than loaded from a CDN. No functional change.

= 0.5.2 =
Bug fix: post fields (image_overlay, headline, meta_strip, footer_meta) now render correctly on Promptless WP PostGrid cards. Stale function_exists guard from the v0.5.0 rename was silently breaking the entire card hook handler. Recommended for any site using PostGrid with a PCPTPages CPT.

= 0.5.1 =
WP.org review round 1 fixes: nonce sanitization, options_json + field_values sanitized at admin boundary, publish_posts cap check on update_post REST, Iconify documented as external service, GitHub auto-updater stripped from WP.org build. No end-user behavior change.

= 0.5.0 =
WP.org prefix compliance: pre_/PRE_ renamed to pcptpages_/PCPTPages_ (3-char prefix didn't meet WP.org's 4-char minimum). Classes, hooks, options, accessor pcptpages() all renamed. class_alias keeps Post_Runtime_Engine working. No end-user behavior change.

= 0.4.1 =
WP.org compliance pass. Main class renamed (back-compat alias preserved). Inline scripts/styles moved to enqueued assets. No data migration. No behavior change for end users.

= 0.3.4 =
Connector admin page UI cleanup. Status card now shows connection state at a glance with the kill-switch toggle inline. Setup steps are cleaner. App password check works correctly on local dev environments. No data changes.

= 0.3.3 =
Admin meta-box UX rebuild plus icon and content-authoring bug fixes. Icon picker is now a category-grouped dropdown; Iconify text input still accepts 200,000+ codes. Variant gating prevents image-upload buttons from showing on icon-only layouts. Recommended for all users.

= 0.3.2 =
Icon system is now dual-format: curated IDs keep working alongside any Iconify code (200,000+ icons). Admin dropdown becomes a text input + quick-pick row. No data migration required. Recommended for all users.

= 0.3.1 =
Compatibility update — passes WordPress.org Plugin Check cleanly. No feature or behavior changes.
