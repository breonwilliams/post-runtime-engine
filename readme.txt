=== Promptless CPT Pages ===
Contributors: promptlesswp
Tags: custom post types, post template, structured content, custom fields, single page
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Render CPT single pages with structured data display, layout variants, and admin or AI population. Works standalone or with Promptless WP.

== Description ==

Promptless CPT Pages provides a constrained, opinionated primitive for repeatable structured content on custom-post-type single pages:

* Register custom post types from an admin UI — no ACF / MetaBox / Pods dependency.
* Define "groupings" per CPT — named clusters of items sharing a layout variant and a position (above main / below main / sidebar).
* Items follow one shape — `{ image-or-icon, heading, supporting_text, optional link }`.
* Five layout variants per grouping — compact-grid, card-grid, featured-card, horizontal-row, gallery.
* Four source modes — `manual`, `child_posts`, `taxonomy_match`, `meta_match` (including reverse lookups across types).
* Curated icon library of 53 icons across 13 categories, extensible via the `pre_icon_library` filter.
* Connector REST API + MCP tools (under `/wp-json/post-runtime/v1/connector/`) so AI assistants like Claude Cowork can register CPTs, define groupings, populate per-post values, and preview rendered output.
* Design-token inheritance from Promptless WP — colors, spacing, typography, radii. Graceful fallback when Promptless is not installed.

Promptless CPT Pages is positioned as a free companion plugin to Promptless WP (the page builder) and Promptless Forms (the form renderer). It owns dynamic CPT single-page rendering; it does not replace Promptless for landing-page composition or Promptless Forms for forms.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WP Admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Visit **Post Runtime → Post Types** in the admin to register your first custom post type.
4. To enable the connector for Claude, visit **Post Runtime → Connector** and follow the setup steps.

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
3. The per-post meta box for filling in grouping items, with the layout-variant preview cards
4. Frontend render of a CPT single page with the compact-grid variant for an "amenities" grouping
5. Claude Cowork connector setup — opt-in App Password generation, default-disabled kill switch

== Changelog ==

= 0.10.1 =
* Changed: deleting the plugin keeps post types, fields, groupings and record values unless PCPTPAGES_REMOVE_ALL_DATA is set in wp-config.php.
* Fixed: the connector's delete removed records permanently while reporting a trash; connector writes could take up to an hour to show on the page.
* Fixed: all-day events moved to Past on their last day, records with no end date appeared in no list, and a long event dropped out of the calendar feed.
* Fixed: saving a type or grouping in the admin erased settings made through the connector (custom address, REST base, Iconify icon, reverse lookups).

= 0.10.0 =
* Added: records accept a photo URL (`featured_image_url`) on the connector and in FlowMint imports. The image is downloaded into the media library once per URL, reused on every later sync, set as the featured image with the title as alt text, and a URL that fails is a warning rather than an error.
* Changed: post-type archive pages no longer load the single-page stylesheet they never used, so they load faster. Single pages are unchanged.

= 0.9.1 =
* Fixed: three Plugin Check errors in the 0.9.0 package (two prohibited `suppress_filters` declarations, one missing translators comment). No behaviour change.

= 0.9.0 =
* Added: a calendar for event-shaped record types. Each record page has "Add to calendar" (a standard .ics download) and "Google Calendar" links, and each type has a subscribable feed of its upcoming records that Apple Calendar, Google Calendar and Outlook keep up to date. Built on WordPress core's feed mechanism; the connector reports the feed address.
* Added: records that mirror another system are identified by source and external id. Creating one twice updates it instead of duplicating it, unchanged records are recognised, and the connector has an upsert route and tool — the primitive behind scheduled ingests from vendor systems.
* Added: right-to-left languages load right-to-left stylesheets, so an Arabic or Hebrew site gets mirrored cards, heroes, maps and admin screens.
* Fixed: permanently deleting a record of a deleted type left its category and tag rows behind; the earlier "purge data" ran as one unbounded request and skipped trashed records — it is batched now and covers them.
* Fixed: "Skip to content" moves keyboard focus on record pages.

= 0.8.1 =
* Fixed: "Skip to content" did nothing on custom post type single pages. The template mirrors the theme's `<main>` but had not been updated with it, so activating the link scrolled the page without moving keyboard focus — the next Tab went back to the top of the navigation (WCAG 2.4.1).

= 0.8.0 =
* Added: the connector can now list and delete the posts it creates. Previously it could build a set of content and then had no way to review or undo it without going into wp-admin by hand.
* Added: re-registering a post type you deleted earlier now tells you what came back — existing groupings, fields and posts — instead of looking like a fresh start and then failing confusingly.
* Fixed: deleting a post type destroyed its grouping definitions even though it reported that your data was preserved. Re-registering brought back content that could no longer be displayed. Nothing is destroyed now unless you explicitly ask to purge.
* Fixed: "purge data" left most of the data behind — field definitions, saved values and backup rows. It now removes all of it.
* Fixed: every grouping save created around 20 junk database options that were loaded on every page request, growing with each save. Rows already on your site are removed automatically the first time you visit wp-admin after updating.
* Fixed: the connector used up its hourly request allowance about twice as fast as it should have.
* Fixed: the connector could not reach an HTTPS local development site.


== Upgrade Notice ==

= 0.10.1 =
Fixes a connector delete that removed records permanently, event lists that dropped all-day and open-ended events, and admin saves that erased settings made through the connector. Deleting the plugin now keeps your data unless you opt in.

= 0.10.0 =
Records can take a photo URL from the connector or a FlowMint import; each image is downloaded once and reused. Archive pages load less CSS. Additive; no settings change.

= 0.9.1 =
Plugin Check clean-up of the 0.9.0 package; no behaviour change. Safe for all users.

= 0.9.0 =
Adds Add to calendar links and a subscribable calendar feed for event-shaped record types, external-identity upsert for ingesting records from other systems, and right-to-left stylesheets. Fixes orphaned category rows on delete and a purge that skipped trashed records. No settings change.

= 0.8.1 =
Accessibility fix: the "Skip to content" link now moves keyboard focus on custom post type single pages instead of only scrolling. No content or settings change.

= 0.8.0 =
Fixes two data-handling bugs: deleting a post type destroyed grouping definitions it claimed to preserve, and "purge data" left most behind. Also stops a bug that created ~20 junk autoloaded options per grouping save, and clears existing ones automatically.

