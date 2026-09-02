=== Promptless CPT Pages ===
Contributors: promptlesswp
Tags: custom post types, post template, structured content, custom fields, single page
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.0
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

= 0.8.0 =
* Added: the connector can now list and delete the posts it creates. Previously it could build a set of content and then had no way to review or undo it without going into wp-admin by hand.
* Added: re-registering a post type you deleted earlier now tells you what came back — existing groupings, fields and posts — instead of looking like a fresh start and then failing confusingly.
* Fixed: deleting a post type destroyed its grouping definitions even though it reported that your data was preserved. Re-registering brought back content that could no longer be displayed. Nothing is destroyed now unless you explicitly ask to purge.
* Fixed: "purge data" left most of the data behind — field definitions, saved values and backup rows. It now removes all of it.
* Fixed: every grouping save created around 20 junk database options that were loaded on every page request, growing with each save. See the upgrade notice for cleaning up existing rows.
* Fixed: the connector used up its hourly request allowance about twice as fast as it should have.
* Fixed: the connector could not reach an HTTPS local development site.

= 0.7.2 =
* Added: a new "Location / map" field type. Enter a street address on a post and its single page shows a click-to-load map — no Google Maps API key, no coordinates, no setup. Cards and archive listings show the address as text. You choose the zoom level (street / neighborhood / city), whether the map loads on click (privacy-friendly, the default) or automatically, and whether to show a "Get directions" link. You also choose where the map sits on the page — above the content, below it, or in the sidebar — the same placement control your grouping sections use, and each post can override it. If a post has no address, the map uses your Business Identity address (when Promptless WP is set up). The map is self-contained — it does NOT require Promptless WP and works on any theme; when Promptless WP is active the map simply picks up your brand colours automatically. Works from the editor by hand or through the AI connector.
* Updated: tested up to WordPress 7.1.

= 0.7.1 =
* Fixed: single-post pages (such as a speaker or session profile) now align their content and hero image to the same layout width as the floating navigation and page sections, instead of extending slightly past it on the left and right.
* Fixed: in the post editor, a grouping item linked to another post now clearly shows which post it is linked to (and the post type), instead of leaving the link field looking empty. A link whose target has been deleted is flagged. Makes existing connections visible and verifiable at a glance. Editor-only; no content or data changes.

= 0.7.0 =
* Fixed: on phones and tablets, the gallery lightbox prev/next controls now sit in a bottom nav bar with the image counter between them (matching Promptless WP), instead of side arrows overlapping the photo. The counter shows the compact "2 / 4" for consistency, with the full "Image 2 of 4" kept for screen readers.

= 0.6.9 =
* Improved: the "Copy Command" button on the Connector setup screen now sits below the command block instead of overlaying it, fixing a tap-target overlap and a color-contrast issue.

= 0.6.8 =
* Improved: gallery lightbox caption and counter now share one design language with Promptless WP galleries
* Fixed: badge collision on overlay-hero cards; CSS now cache-busts by file modification time
* Improved: image-overlay fields flow into the content area on compact Post Grid rows

WordPress truncates this section at 5,000 characters, so it keeps a rolling window of the
six most recent releases. The complete history lives in CHANGELOG.md in the plugin folder,
and on the GitHub releases page.

== Upgrade Notice ==

= 0.8.0 =
Fixes two data-handling bugs: deleting a post type destroyed grouping definitions it claimed to preserve, and "purge data" left most data behind. Also stops a bug that added ~20 junk autoloaded options per grouping save. Existing junk rows are harmless but can be removed — see the changelog.

= 0.7.2 =
Adds a Location / map field: enter an address and the single page shows a click-to-load map — no Google Maps API key or setup. Place it above, below, or in the sidebar, overridable per post. Works with or without Promptless WP. Additive; existing fields unchanged.

= 0.7.1 =
Grouping items linked to another post now show the linked post (and its type) in the editor instead of a blank field, and flag a link whose target no longer exists. Editor-only clarity fix; no content, schema, or front-end changes. Recommended for all users.

= 0.7.0 =
Gallery lightbox mobile navigation now matches Promptless WP: on phones and tablets the prev/next controls sit in a bottom nav bar with the image counter between them, instead of side arrows overlapping wide photos. Recommended for all users.

= 0.6.7 =
Adds the Gallery grouping variant: responsive photo grids with an accessible lightbox and per-definition tile aspect (16:9/4:3/1:1/4:5) — ideal for property photo tours, vehicle galleries, and portfolios. Additive release; existing groupings are unchanged. Recommended for all users.

= 0.6.6 =
Fixes grouping thumbnails rendering as thin slivers in sidebar and horizontal layouts. Adds a per-CPT archive image crop (square, 4:3, 4:5, 16:9). New CPTs now hide the post date and author byline on archive cards by default; existing CPTs are unchanged.

= 0.6.5 =
Cross-CPT relationships: meta_match groupings can auto-pull posts from another post type (an agent page listing its properties). Also fixes archive filters named after post types redirecting away, unstyled embedded forms on cached pages, and <picture> wrapping breaking image layout.

