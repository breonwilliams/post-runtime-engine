# Changelog

All notable changes to Post Runtime Engine are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the plugin is pre-1.0, the public surface (CPT shape, grouping shape, REST connector, MCP tools) is treated as semi-stable — additive changes are minor releases; backward-incompatible changes are noted in their own section even at this stage.

## [Unreleased]

### Added
- **Gallery grouping variant** (design contract: `docs/GALLERY_VARIANT_DESIGN.md` — the fifth layout variant, agreed 2026-07-18 for the directory/listing vertical: property photos, vehicle galleries, portfolio shots). A grouping set to `gallery` renders its items as a responsive photo grid (3-up desktop / 2-up mobile, 16:9 tiles, `<figure>`/`<figcaption>` markup) with a new self-contained lightbox (`assets/js/pre-lightbox.js` — WAI-ARIA APG dialog: focus trap, Escape, arrow keys, Home/End, visible ≥44px prev/next buttons per WCAG 2.2 §2.5.7, touch swipe as enhancement, `aria-live` image announcements, reduced-motion support, scroll lock with gutter preservation). Item-shape reinterpretation is the inverse of the icon-only variants: `image_id` required to render (imageless items are valid but skipped — stage now, photo later), `icon_id` REJECTED by the validator (`pcptpages_gallery_icon_rejected`), `heading` becomes the optional caption (heading requirement auto-relaxed under gallery), `supporting_text`/`link` accepted-but-not-rendered (tile click opens the lightbox). Item validation is now effective-variant-aware (validates against `variant_override` when set, not just the definition default). Performance per contract §9: `aspect-ratio` reserves tile space (zero CLS), `loading="lazy"` + `decoding="async"` on all tiles, grid-matched `sizes` attribute so browsers fetch tile-sized files, full-size images load only on lightbox open with adjacent prefetch; the lightbox script is registered on managed pages but enqueued ONLY when a gallery renders (footer queue). Admin: variant appears in the definition + per-post override dropdowns; gallery mode hides icon/supporting-text/link controls per item, shows an explanatory note, and adds an "Add images" bulk button (wp.media multi-select — one item per photo, cap-aware). Connector: `variant_item_fields.gallery`, new `critical_rules.gallery_variant` (incl. attachment-alt-text requirement and auto-source featured-image behavior), `GET /variants` catalog entry; auto-sources work unchanged (matched posts' featured images become tiles). All styling flows from documented `--aisb-*` tokens (no new token consumption — `docs/AISB_TOKEN_CONTRACT.md` unchanged). Data-version 0.6.0 (additive vocabulary marker, no migration). `docs/CONNECTOR_SPEC.md` synced.
- **Gallery tile aspect control (`gallery_image_aspect`)** — definition-level enum on grouping definitions (`16:9` default / `4:3` / `1:1` / `4:5`), reusing the validator's `ARCHIVE_IMAGE_ASPECTS` vocabulary so archive cards, AISB sections, and gallery tiles all speak one aspect language (contract amendment §8-D2a). Controls the tile crop only — the lightbox always shows the full uncropped image. Surfaces: validator enum check (`pcptpages_invalid_gallery_image_aspect`), registry default (`16:9`), renderer modifier class (`pre-gallery--aspect-{a}-{b}`) with CSS overrides for the three non-default ratios, a conditional "Gallery tile aspect" select in the grouping definition form (shown only when the default variant is Gallery), connector `field_name_hints.grouping_definition` + `critical_rules.gallery_variant` guidance (4:3 property/vehicle, 1:1 product, 4:5 portrait). Grid columns deliberately remain fixed 3/2/2 per contract §8-D2.

### Fixed
- **Bundled MCP connector (`includes/Connector/assets/post-runtime-connector.js`) taught the gallery vocabulary.** This file is the canonical source served to users via the `pcptpages_download_connector` admin-ajax action — the copy that ends up at `~/post-runtime-mcp/` on their machine. It predated the gallery variant entirely: the `default_variant` enum in `define_grouping` lacked `gallery` (an AI client following the schema could not select it), `gallery_image_aspect` was absent from the define/update schemas AND from both handler payload allowlists (values were silently stripped before reaching the REST API — caught in hosted E2E when a deliberate invalid `21:9` came back as a clean save at the default `16:9` instead of the expected 422), `list_variants` said "the 4 variants", and the define/set_post_groupings descriptions carried no gallery item-shape guidance. All synced; `docs/CONNECTOR_SPEC.md` error table gained `gallery` in the variant enum plus the `pre_invalid_gallery_image_aspect` / `pre_gallery_icon_rejected` rows.
- **Admin assets never loaded on the Groupings / Post Fields screens** (rebrand fallout, present since the `pre-*` → `pcptpages-*` page-slug rename). Those pages are hidden (null-parent) submenus, so their admin hooks are `admin_page_pcptpages-groupings` / `admin_page_pcptpages-post-fields` — which contain neither `promptless-cpt-pages` nor the planning-era `pre-` prefix the `PCPTPages_Admin::enqueue_assets()` guard matched on. Result: `admin.css`, `admin-groupings.js` (source-row + gallery-row visibility toggles), and `post-fields-editor.js` (conditional field display + drag reorder) were silently skipped there. The guard now also matches `pcptpages` in the hook. Discovered while live-verifying the gallery aspect select's conditional visibility.

### Added
- **Per-CPT archive card image aspect ratio** (`archive_image_aspect`, demo-site pressure testing 2026-07-12). New CPT definition key controlling the featured-image crop on the theme's archive cards: `16:9` (default — the theme's historical hardcoded crop, existing sites unchanged), `4:3`, `1:1`, `4:5`. Square/portrait suit people-centric CPTs (agents, team directories); 4:3 suits property/product photography. Answered to the theme through the new `promptless_archive_image_aspect` filter (theme 1.3.0+) — the same handshake as `archive_show_post_date/author`. Vocabulary matches the PostGrid section's `card_image_aspect_ratio` enum so the ecosystem speaks one aspect language. Wired end-to-end: validator enum (`PCPTPages_Validator::ARCHIVE_IMAGE_ASPECTS`), registry default, admin CPT form, connector REST field hints, MCP `register_cpt`/`update_cpt` schemas + payload allowlists, unit tests.

### Fixed
- **Post-field chips no longer inherit the card border emphasis** (demo-site testing, 2026-07-12). When Promptless WP's Card & Button Style was set to Outline or Lifted (body class `aisb-neo-brutalist-cards[-outline]`), `cards.css` gave badge chips and multi-badge pills a `currentColor` border and — worse — overrode their color-intent background with the plain surface color, visually destroying tier/status badges in PostGrid cards, theme archive cards, and single-post field positions. Progress bars were also forced square with thickened borders. Field chips are data display, not card surfaces: the neo-mode rules are removed entirely and the exclusion is documented in-file so they don't come back. Card containers (`.aisb-postgrid__item`, `.pre-grouping__item`, theme archive cards) keep the border treatment — that's where the mode belongs. Ecosystem-wide sweep confirmed no other chip-level element in PRE, Promptless WP, Promptless Forms, or the theme is targeted by any `aisb-neo-*` rule.

## [0.6.5] — 2026-07-11

### Fixed
- **Duplicate-install guard in `uninstall.php`** (v0.6.5 release verification, 2026-07-11). When two copies of the plugin exist under different folder names (a release-ZIP install in `promptless-cpt-pages/` alongside an older copy from a GitHub source ZIP or copied dev folder), deleting the stale copy through the Plugins screen ran `uninstall.php` and wiped the shared database-stored configuration (CPT definitions, groupings, settings) out from under the surviving copy. Cleanup now runs only when the LAST installed copy is deleted. `RELEASE.md` gains a "Test-install policy" section documenting sanctioned install sources and the safe duplicate-cleanup playbook. Sibling guards shipped in Promptless WP and Promptless Forms.
- **Filter URL params colliding with WordPress query variables hijacked the archive** (Harbor & Oak `/listings/?neighborhood=downtown`, 2026-07-10). Filter param names are derived from field keys, and selects/checkbox groups used the bare key — so the listing CPT's `neighborhood` select emitted `?neighborhood=…`, which WordPress core claimed first (`neighborhood` is also a registered CPT and therefore a public query var) and 301-redirected the archive to the nearest neighborhood post before the filter ever ran. The user-visible symptom masqueraded as "sort doesn't work" (they landed on the neighborhood page's own unsorted grid); sorting itself was verified correct in isolation and in combination with non-colliding filters. Same hijack class applies to any field key or public-taxonomy facet named after a post type slug, taxonomy query var, or core public var (`name`, `author`, `s`, `order`, …). Fixed at the single choke point where param names are generated (`collision_safe_param()` in the descriptor builder): colliding names get an `f_` prefix (`f_neighborhood`), checked against WordPress's live public-query-var registry so every current and future CPT/taxonomy/core var is covered with zero maintenance. Non-colliding keys keep their clean bare names, and no working URLs break (colliding URLs never worked — they redirected). All downstream consumers (form input names, query parsing, chips, SEO noindex) read the descriptor's params map, so the rename is complete by construction. Connector preflight's `filterable_archive_setup` rule now documents the prefixing and points URL-constructing agents at the authoritative param names. (`includes/Frontend/class-pre-filter-descriptors.php`, `includes/Connector/class-pre-connector-api.php`.)
- **Render cache dropped asset enqueues from shortcodes inside cached content — anonymous visitors got unstyled embeds** (Harbor & Oak request-a-showing forms, 2026-07-10). The render cache stores each single-post page's full HTML as a transient; on a hit the HTML is replayed but shortcodes inside it (e.g. Promptless Forms `[pforms_form]` in post_content) never re-execute — and enqueueing their stylesheets/scripts is a side effect of execution. Result: the first anonymous visitor after invalidation got a styled page (MISS = real render), every subsequent one got the form markup with **no stylesheet link at all**, while editors — who always bypass the cache — saw a styled page, making the bug nearly invisible. Fixed by snapshotting the style/script queues around the MISS render, storing the delta handle lists in the transient, and re-enqueueing them on every hit (handles are registered globally on `wp_enqueue_scripts` by their owning plugins — the standard register-early/enqueue-at-render pattern — so enqueue-by-handle restores the exact assets). Entries cached by earlier builds lack the handle lists and are treated as misses, so every page heals on first view after deploy. Verified: MISS and HIT renders serve identical stylesheet sets. (`includes/Frontend/class-pre-renderer.php`.)
- **Image-optimizer `<picture>` wrappers broke every %-sized PRE image — grouping cards AND heroes** (Harbor & Oak agent + listing pages, 2026-07-10). EWWW's picture-WebP rewriting (and any similar optimizer/CDN) inserts a `<picture>` between the media box and the `<img>`; `<picture>` defaults to `display:inline` and shrink-wraps, so the img's `width:100%`/`height:100%` resolved against the wrapper instead of the media box. Symptoms found live: card-grid images rendered at aspect-driven partial widths (agent page), and the split hero left a bottom gap whenever the photo's aspect was shorter than the media frame — aspect-dependent, so it looked intermittent (listing page; square photos coincidentally filled). Invisible on hosts without an optimizer. Fixed with `display:contents` on `picture` inside all three PRE image containers (`.pre-grouping__media`, `.pre-hero__media`, `.pre-hero__backdrop` — covering every grouping variant and all three hero layouts incl. overlay): the wrapper vanishes from layout and the img participates exactly as the direct child every image rule was written against, with or without an optimizer. (An initial `display:block; width:100%` fix repaired widths but not fixed-height/aspect frames — superseded.) Also: explicit `width:100%; height:160px` box on card-grid images (fixes ragged heights from panoramic photos) and an honest `sizes` attribute for card-grid images (`(max-width: 640px) 100vw, 340px` — WP's default claimed 300px for a ~330px cell, so browsers never picked sharp candidates on high-DPR screens). The same neutralizer rule ships in Promptless WP (`.aisb-section picture`) and the Promptless theme (archive cards + mini-cart) so the whole stack is optimizer-proof in one sweep. (`assets/css/frontend.css`, `includes/Frontend/class-pre-renderer.php`.)
- **Bare per-post grouping entries now inherit the definition's `default_source`** (2026-07-10 smoke-test finding). A connector `set_post_groupings` entry of just `{grouping_key}` was normalized to `source:'manual'` + `items:[]` — rendering nothing and making `define_grouping`'s `default_source` unreachable through the connector (the admin meta box snapshots the definition source on save, so only the connector path was affected). `PCPTPages_Post_Data::set_groupings()` now mirrors the admin path: entries omitting `source` with no items inherit the definition's `default_source`; entries omitting `source` but carrying items keep the implicit-manual behavior. (`includes/Core/class-pre-post-data.php`.)

### Added
- **meta_match cross-CPT reverse lookup** (real-estate demo pressure test, 2026-07-10). The `meta_match` grouping source gains three optional, backward-compatible params: `post_type` (query a different CPT than the host post's), `match_against` (`same_key` default = original mirror behavior; `current_id` / `current_slug` / `current_title` = compare the target posts' meta to the current post itself — the parent-pulls-children shape), and `field_key` (reference a PRE post-field by its key, resolved to `_pcptpages_field_{key}` storage — the connector-writable form, closing the "no raw-meta write path" gap). An Agent page can now auto-pull its Listings via `{type:'meta_match', post_type:'listing', field_key:'agent', match_against:'current_title'}`. New closed enum `PCPTPages_Validator::META_MATCH_AGAINST`; exactly one of `meta_key`/`field_key` enforced (`pcptpages_meta_match_key_conflict`). Meta-key auto-registration now targets the QUERIED post type (where the values live) and skips `field_key` sources (post-field meta is already PRE-managed). Unit tests + connector preflight descriptor + MCP `define_grouping` schema + `docs/CONNECTOR_SPEC.md` updated. Data-version marker 0.5.0 reserved for the release that ships this. (`includes/Core/class-pre-validator.php`, `includes/Core/class-pre-source-resolver.php`, `post-runtime-engine.php`, `includes/Connector/*`, `tests/Unit/ValidatorTest.php`.)

## [0.6.4] — 2026-07-04

### Added
- **Hero contrast band: per-CPT `hero_theme` (`inherit`/`light`/`dark`) and `hero_width` (`contained`/`full`).** (Phase A of `docs/HERO_CONTRAST_DESIGN.md`.) A forced theme turns the single-post hero into a contrasting band with its own background — the standard "dark hero band on a light page" detail-page treatment — with all colors flowing from the existing `--aisb-*` token set including the smart WCAG-corrected link/icon tokens, plus re-declared theme `--section-*` interop variables so theme-level heading/link rules resolve to the forced mode. `hero_width: full` bleeds the band's background to the viewport edges (margin-inline breakout + `overflow-x: clip` on `body` via `:has()`) while hero content re-caps at `--aisb-section-max-width`, staying pixel-aligned with the page grid. Defaults (`inherit`/`contained`) emit no classes — markup is byte-identical to 0.6.3 for CPTs that have not opted in. Wired across validator (`HERO_THEMES`/`HERO_WIDTHS` closed enums), registry defaults, renderer, admin CPT form, connector `field_name_hints` + `critical_rules`, and MCP tool schemas + body allowlists. (`includes/Core/class-pre-validator.php`, `includes/Core/class-pre-cpt-registry.php`, `includes/Frontend/class-pre-renderer.php`, `includes/Admin/class-pre-admin-cpts.php`, `includes/Connector/*`, `assets/css/frontend.css`.)
- **Overlay hero layout: `hero_layout` gains `overlay`, plus `hero_overlay_focus` (`top`/`center`/`bottom`).** (Phase B of `docs/HERO_CONTRAST_DESIGN.md`.) The featured image fills the hero band and the title/headline/badges/excerpt/meta render on top of it over a fixed bottom-weighted scrim whose stops are palette-independent constants — audited ≥3:1 (AA large text) anywhere the title can reach and 5:1 measured at the title line over a pure-white worst-case image. Posts without a featured image fall back to the stacked treatment honoring `hero_theme` (never an empty band), so overlay is safe to enable before every post has imagery. `hero_theme` is ignored while an image is present (text always uses the dark token set over the scrim, enforced by CSS specificity). The overlay image ships `loading="eager"` + `fetchpriority="high"` (it is the LCP element by construction); band height is fixed by CSS clamp so there is no layout shift. Composes with `hero_width` — contained renders as a card, full as the full-bleed cinematic band. `hero_overlay_focus` maps to `object-position` so tall photos crop predictably.

### Fixed
- **CPT definition changes now invalidate the render cache.** The invalidation hook only watched `pcptpages_groupings_*` options, while CPT definitions live in the single `pcptpages_cpts` option — so editing `hero_layout` (or any definition field) left stale cached single-page renders for up to the cache TTL. New listeners on `pcptpages_cpt_registered`/`pcptpages_cpt_unregistered` bump the same per-CPT change timestamp the cache key already incorporates. (`includes/Frontend/class-pre-renderer.php`.)
- **Intent badge/chip colors (success/warning/danger/info) now meet WCAG AA in every context.** A full-matrix contrast audit (3 hero layouts × 3 hero themes × 2 page modes × 2 widths, with synthetic fields of every display type and badge intent injected into the live hero) found two failures: badge/pill TEXT preferred the site's `--aisb-color-success/warning/error/info` brand tokens, which are mid-tone FILL colors on real sites (audited 1.9–3.2:1 on the tint backgrounds even in plain light mode; image-overlay badges put white text on the same mid-tones at ~2.6:1); and NO dark-context intent remap existed for page-dark cards (pre-existing), forced-dark hero bands, or the overlay hero (pale tints + dark text audited 1.9–4.4:1). Status colors are semantic, not brand: text/tint constants are now palette-independent AA-safe shades, and a dark-context block covers every dark surface a badge can sit on with light text shades on stronger tints (audited ≥7:1 over the darkest band). Post-fix audit: 10 configurations, zero failures. (`assets/css/cards.css`.)

## [0.6.3] — 2026-07-03

### Added
- **SEO meta tags on CPT single pages: `<meta name="description">` plus a compact OpenGraph/Twitter set.** PRE renders its own CPT single pages (listings, practice areas, attorney bios, events), but only emitted structured data (Event JSON-LD) for them — never a meta description. Promptless WP's `SocialMetaTags` only fires on pages carrying `_aisb_sections`, which PRE singles don't have, so those pages shipped with no meta description at all and Lighthouse/PageSpeed flagged "Document does not have a meta description." A new self-contained `PCPTPages_Meta_Tags` emitter (mirroring the `PCPTPages_Event_Schema` `wp_head` pattern) now outputs `<meta name="description">` derived from the post excerpt (or trimmed content, capped ~155 chars on a word boundary), plus `og:title/description/type/url/site_name/image` and the Twitter card, using the post title and featured image. It emits only on singular views of PRE-registered CPTs, **defers to Promptless** on `_aisb_enabled` pages (which own their own head), and **defers to any active SEO plugin** (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework, Slim SEO — detected by version constant, filterable via `pcptpages_seo_plugin_active`) so it never double-emits. Description and the full tag set are filterable (`pcptpages_meta_description`, `pcptpages_meta_tags`). No PHP dependency on Promptless — the one-way decoupling is preserved. (`includes/Frontend/class-pre-meta-tags.php`, autoloader, `post-runtime-engine.php`.)

## [0.6.2] — 2026-06-25

### Fixed
- **Sidebar card-grid grouping items were vertically misaligned (icon · heading · arrow).** When a card-grid grouping renders in the sidebar, the CSS pivots each card into a horizontal row. The row was top-aligned (`align-items: flex-start`) while the CTA arrow still carried the vertical-card rules (`align-self: flex-end; margin-top: auto`), so the icon and heading pinned to the top of the row and the arrow dropped to the bottom — measured at icon 29px / heading 35px / arrow 45px on a 70px row, and the arrow as far down as 99px on a 124px row with supporting text. The arrow also lacked `margin-left: auto`, so on short headings it floated mid-row instead of pinning right. The sidebar row now centers its items (`align-items: center`), re-centers the arrow on the cross axis, and lets the heading column flex-grow so the arrow always pins to the right edge regardless of heading length. Icon, heading, and arrow now share one vertical center for single-line, short, and thumbnail items (heading-plus-supporting items center the icon against the block by design). Verified against a real CPT single page on staging and a multi-viewport kitchen-sink harness that loads the real plugin CSS. (`assets/css/frontend.css`.)
- **Neutral `multi_badge` pills (chips) were illegible (dark-on-dark) under the theme's dark Content Theme / dark sections.** The chip fill follows the section surface and darkened correctly, but the chip TEXT used `--pre-color-neutral-text`, which was hard-wired to the raw light `--aisb-color-text-muted` and never re-pointed for dark — yielding dark-brown text (~1.5:1) on the dark fill while every other field flipped fine. Re-pointed `--pre-color-neutral-text` to the theme-aware `--pre-color-text-muted` token (which already flips light↔dark) in BOTH the card and single-hero token blocks — semantically a neutral pill's text IS muted text — so chips stay legible in every dark context. Measured: chip text now resolves to the dark-muted token (`rgb(192,168,152)`, ~6:1) instead of `rgb(96,72,64)`. Surfaced by the Promptless theme Customizer "Content Theme: Dark" on a PRE single-post page; the same fix also covers PRE chips injected into dark PostGrid / theme archive cards. (`assets/css/cards.css`.)

### Changed
- **Meta-strip field separators are now gap-only — the `·` middot was removed.** The middot rendered as an `::after` glyph on each `meta_strip` field; `:not(:last-child)` kept it off the final item, but at a WRAP point the last field on a line still painted its `·`, leaving an orphaned middot at the end of the wrapped line (seen when a narrow column — split-hero or card meta-strip — wrapped a heavy strip with several fields incl. a progress bar). No rendered separator (middot, `|`, or a left border) can avoid this, because CSS cannot select "the last item of a wrapped line." The strip now separates items with flex `gap` only (column gap widened 0.85em → 1.25em to compensate for the removed glyph), which is bulletproof — it never dangles at any width or item count, wrapped lines get their own row-gap, and the meta-pair leading icons still delimit items visually. (`assets/css/cards.css`.)

## [0.6.1] — 2026-06-23

### Fixed
- **Card-grid CTA arrow overlapped the supporting text.** The clickability arrow was absolutely positioned in the card's bottom-right corner, so a supporting line that reached the bottom-right collided with it (verified: a 16×16px overlap on cards with full-width last lines; whether it overlapped depended on text length). The arrow now flows as a bottom-aligned flex footer (`margin-top:auto; align-self:flex-end`) — it always sits on its own row below the text and, because grid cards stretch to equal height, bottom-aligns consistently across a row. `featured-card` keeps its own placement (its padded content wrapper isolates the text). (`assets/css/frontend.css`.)
- **Card-grid grouping images were inset instead of bleeding to the card edges.** Related-posts / grouping cards rendered in the `card-grid` variant kept their image inset inside the card's padding (with its own radius), out of step with Promptless's Features / PostGrid default cards (which bleed the image flush to the top + side edges) and ignoring the site's global Card Image Style setting. `assets/css/frontend.css` now bleeds the card-grid image to the card edges by default (item clips it via `overflow:hidden`), and insets it with a halved radius only under Promptless's `body.aisb-card-image-inset` class — so PRE honors the same global toggle as Promptless's own cards. `featured-card` already bled by design (image-prominent) and is unchanged; the sidebar card-grid (horizontal list with a small thumbnail) is explicitly excluded. (Surfaced in the CAEP demo visual pass.)
- **PostGrid card post-field meta was unreadable on dark sections.** Post-field meta rendered inside an AISB PostGrid / theme archive card (e.g. a `footer_meta` date) kept the light-mode muted color on dark-theme sections (~1.7:1 contrast), while the rest of the card flipped correctly. Root cause: the light-mode card token block declares the `--pre-color-*` tokens on BOTH `.pre-card-fields--card` and `.aisb-features__item`, but the dark-mode override only covered `.pre-card-fields--card` — and PostGrid cards inject fields directly into `.aisb-features__item` with no `.pre-card-fields--card` wrapper. Extended the dark override in `assets/css/cards.css` to mirror the light selector, so card meta flips to the dark muted token with the section theme. (Surfaced in the CAEP demo accessibility pass.)
- **Inline groupings silently failed when keyed by `key` instead of `grouping_key`.** `define_grouping` uses `key`, so authors naturally reached for `key` when attaching grouping DATA to a post via `create_post` / `update_post` / `set_post_groupings` — but those entries require `grouping_key`, so the items didn't attach (and the validator returned "missing grouping_key"). `PCPTPages_Post_Data::set_groupings()` now accepts `key` as an alias and canonicalizes it to `grouping_key` before validation and storage, covering all three entry points.
- **Delete endpoints returned a bare `204 No Content`, which the MCP bridge misread as an error.** `DELETE /cpts/{slug}`, `DELETE /cpts/{slug}/groupings/{key}`, and `DELETE /cpts/{slug}/post-fields/{key}` now return `200 OK` with a JSON envelope (`{ "deleted": true, "slug": "...", ... }`), matching every other connector endpoint. HTTP 204 cannot legally carry a body, so the bridge's `JSON.parse("")` threw and surfaced a spurious `{ error: true, status: 204 }` on what was actually a successful delete. Updated the three handlers in `class-pre-connector-api.php` and the `DELETE` contracts in `docs/CONNECTOR_SPEC.md`.
- **Hardened the MCP bridge (`post-runtime-connector.js`) against empty success bodies.** An empty body on any 2xx response (a 204, or a body stripped by an upstream proxy) now resolves as `{ success: true, status }` instead of falling into the JSON-parse `catch` and reporting an error. Defense-in-depth so this class of bug can't resurface on other endpoints. (Takes effect after the connector is reinstalled; the server-side fix above resolves the reported case for existing bridge installs.)

## [0.6.0] — 2026-06-15

### Added
- **`number_grouping` option for the `number_with_label` display type** (default `true`). Set `false` to render identifier-like numbers without thousands separators — year built `2019`, not `2,019`; model years, unit/lot numbers, IDs — while keeping the field label. Wired through the card renderer, registry defaults, validator, the admin "Thousands separator" toggle, the connector schema + hints, and `docs/POST_FIELDS_V1_1_DESIGN.md`.
- **Taxonomy-term assignment via the connector.** `create_post` / `update_post` accept a `taxonomies` map (e.g. `{"category": ["Downtown"]}`); terms may be names, slugs, or IDs and missing terms are created. Validated against the CPT's registered taxonomies; non-fatal issues surface in the response `warnings`. Powers taxonomy-based archive facets and `taxonomy_match` groupings.

### Changed
- **Minimum WordPress version raised to 5.6** (header + `readme.txt`). The connector requires Application Passwords (WP 5.6) and the events layer uses `wp_date()` / `wp_timezone()` (WP 5.3), so 5.6 is the true floor. Resolves Plugin Check `wp_function_not_compatible_with_requires_wp` errors.
- **Taxonomy filter facets hide empty terms** so the UI never offers a dead-end option that returns zero results.

### Removed
- **GitHub auto-updater retired.** Now that the plugin is hosted in the WordPress.org directory, updates are delivered by WordPress core — bundling a self-updater violates guideline #8. Removed the updater class, its autoloader entry, and its bootstrap call, and collapsed the dual GitHub/WP.org build in `bin/build-release.sh` to a single WP.org-compliant build (with a safety net that fails the build if an updater reference reappears). Resolves Plugin Check `plugin_updater_detected`. `RELEASE.md` rewritten for the WordPress.org SVN release workflow.

### Fixed
- **`multi_badge` neutral pills were invisible on cards.** The neutral fill resolved to the card surface token, so feature tags showed only their padding (reading as indented text). They now render as outline chips — surface fill plus an ink-mixed hairline border — and every color token carries a literal fallback so a pill still renders correctly if placed outside a card.
- **Meta-strip separator mojibake.** The `·` separator rendered as `Â·` when the stylesheet was served without an explicit charset; replaced the literal byte with the encoding-proof CSS escape `\00B7`.

## [0.5.3] — 2026-06-08

### Documentation
- **External Services disclosure rewritten for WordPress.org review round 2.** The round-1 disclosure listed the Iconify API but linked the project homepage ("see footer links") as the privacy policy and the API docs as terms; the review team flagged the links as insufficient. The section now links Iconify's dedicated privacy policy (https://iconify.design/privacy/ — verified live) and the public-API terms-of-use section (https://iconify.design/docs/api/), states explicitly that the `iconify-icon` web component is **bundled locally** at `assets/js/iconify-icon.min.js` (NOT loaded from any CDN — older changelog references to a jsDelivr CDN describe behavior since removed, confirmed by the current `wp_register_script` call using `PCPTPages_PLUGIN_URL`), and gives a precise breakdown: what is sent (only the `prefix:name` icon code, in the request URL), when (at render time, by the visitor's browser, only when an Iconify-format icon is present), the three endpoints (`api.iconify.design` primary + `api.simplesvg.com` / `api.unisvg.com` fallbacks), and the no-contact path (the built-in 53-icon inline SVG library).
- **Synced `readme.txt` changelog with full version history.** The readme Changelog section was missing the 0.5.0–0.5.2 entries (present in CHANGELOG.md and the Upgrade Notice but not the readme Changelog). Added so the stable tag always has a matching changelog entry.

### Notes
- Documentation only. No code changes, no data migration. `pcptpages_data_version` unchanged at 0.4.0.

## [0.5.2] — 2026-06-02

### Fixed
- **PostGrid card rendering**: post fields (image_overlay badges, headline values, subtitle, meta_strip, footer_meta) now render on Promptless WP's PostGrid section cards as designed. The bug: five `function_exists( 'pre' )` guards in production code still checked for the legacy plugin accessor name that was renamed to `pcptpages()` during the v0.5.0 rename. Every guarded code path returned `null` for the plugin instance and silently no-op'd, including the entire `aisb_postgrid_card_section` hook handler. The handler was correctly subscribed and Promptless WP was correctly firing the action — the stale check inside the handler killed it. Fixed in `class-pre-card-filter-hooks.php` (2 instances), `class-pre-card-renderer.php` (2 instances), and `class-pre-post-data.php` (1 instance). Also affects the theme's archive card hook (`promptless_archive_card_section`) and post-data registry resolution. Test files still reference the old guard pattern — they're excluded from the build and will be cleaned up in a future pass.

### Notes
- No data migration required. `pcptpages_data_version` unchanged at 0.4.0.
- No new capabilities granted; no schema changes.
- This is a behavior fix on the existing v0.5.1 surface — anyone running 0.5.1 with PostGrid + a registered CPT was affected and should upgrade.

## [0.5.1] — 2026-06-01

WordPress.org plugin directory review — round 1 fixes. The 0.5.0 build
was flagged on a small number of specific items by the review team; this
release addresses every one without changing end-user behavior.

### Security
- **Nonce sanitization**: `class-pre-meta-box.php` and
  `class-pre-meta-box-post-fields.php` now wrap nonces in
  `sanitize_text_field( wp_unslash( … ) )` before `wp_verify_nonce()`.
  WordPress's `wp_verify_nonce()` is pluggable, so per the WP.org
  guideline the value must be sanitized at the call site even though
  the core implementation accepts arbitrary input.
- **Field-input sanitization at admin boundary**: post-fields admin
  (`options_json` now goes through `sanitize_textarea_field`) and the
  post-fields meta-box (`pcptpages_field_values` array now sanitized
  per-display-type before normalization). The downstream registry
  validators still re-validate shape and format — this pass ensures the
  scalar values are WordPress-safe at the input boundary, satisfying
  Plugin Check's input-sanitization checks.
- **Authorization on REST `update_post`**: when the caller sends a
  `post_status` that transitions the post to a publicly-visible state
  (`publish`, `future`, `private`), the handler now requires the
  post-type's `publish_posts` capability in addition to the `edit_post`
  check the per-post permission_callback already performed. Reviewer
  flagged this as a real authorization gap. Returns 403
  `pcptpages_publish_forbidden` if the calling user lacks the cap.

### Changed
- **GitHub auto-updater fully stripped from the WP.org build.** Round 1
  reviewer specifically flagged the autoloader entry and bootstrap call
  for the updater even though they were `class_exists`-gated. The build
  script now removes any code block wrapped in
  `BUILD:STRIP-FOR-WPORG-START` / `…-END` markers from `--wporg` builds,
  and the autoloader class_map entry + the admin bootstrap call are
  both wrapped. After this change the WP.org distribution contains zero
  references to `PCPTPages_GitHub_Updater`. The GitHub distribution build
  leaves the markers in source and ships the wrapped code untouched,
  so self-hosted sites continue to auto-update from GitHub Releases.

### Documentation
- **External Services section added to readme**. Documents the Iconify
  Web Component bundled at `assets/js/iconify-icon.min.js`. When a
  grouping item, post field, or card references an Iconify-format icon
  code (e.g. `mdi:home`), the component fetches the SVG markup from
  `api.iconify.design` (with `api.simplesvg.com` and `api.unisvg.com`
  as automatic fallbacks). Reviewer flagged the lack of an External
  Services disclosure for these endpoints. The plugin's built-in
  53-icon library is rendered inline and does NOT contact Iconify.

### Compliance status
- Plugin Check passes with zero errors after these changes.
- The `permission_callback` flags on `build_callback` /
  `build_per_post_callback` REST routes are the same closure-from-method
  false positives that Promptless Forms cleared at round 3 — the static
  analyzer can't trace the 5-step permission stack through a
  closure-returning method.

## [0.5.0] — 2026-05-30

WordPress.org plugin directory compliance release — final round. The
3-character `pre` / `PRE_` prefix fell below WP.org's required 4-character
minimum (the same rule that bit FRE during its review), so the entire
symbol surface was re-prefixed to `pcptpages` / `PCPTPages_`.

Unlike FRE's equivalent rename, PRE ships without an option-key
migration routine because there are no production installs of this
plugin yet. The plugin is positioned for first-time WordPress.org
distribution.

### Changed (breaking for any third-party code referencing the old names)
- **Re-prefixed all `PRE_` / `pre_` symbols** to `PCPTPages_` /
  `pcptpages_`: class names, the plugin's global constants
  (`PCPTPages_VERSION`, `PCPTPages_PLUGIN_DIR`, etc.), public API
  functions (`pcptpages_register_cpt()`, …), the `pcptpages()` accessor,
  all action/filter hook names, option keys, AJAX action names,
  transient keys, the capability constant, and user-meta keys.
- **Script/style handles, menu slugs, `page=` URLs, and
  `wp_localize_script` object names** also renamed from `pre-*`/`pre*`
  to `pcptpages-*`/`pcptpages*`. Element IDs and CSS class names in
  HTML markup are preserved (`.pre-body__main`, `#pre-setup-command`,
  etc.) — these are not WordPress-flagged symbols.
- **Build script (`bin/build-release.sh`) version detection** updated to
  read the renamed `PCPTPages_VERSION` constant.

### Compatibility
- `class_alias( 'Promptless_CPT_Pages', 'Post_Runtime_Engine' )` is
  retained for any external code referencing the old main-class name
  (smoke tests, integration test scaffolding, third-party extensions).
- Autoloader file paths (`includes/class-pre-*.php`) intentionally
  preserved — those are actual on-disk filenames, not symbols.

### Compliance status
- Plugin Check passes with zero errors after this rename.
- Header set is clean: Plugin Name, Plugin URI (no duplicate Author URI),
  Text Domain, Stable tag, Tested up to 7.0 all aligned with WP.org
  guidelines as proven on FRE's successful submission.

## [0.4.1] — 2026-05-27

Preemptive WordPress.org plugin directory compliance pass, applying the
patterns FRE 1.7.x went through during its review. No new features, no
breaking changes — the plugin works identically for end users. Backward
compatibility for any third-party code that referenced the old class
name is preserved via `class_alias`.

### Changed

- **Main class renamed.** `Post_Runtime_Engine` → `Promptless_CPT_Pages`.
  WordPress.org plugin guideline 11 (avoid common-word prefixes) treats
  "post" as too generic. A `class_alias( 'Promptless_CPT_Pages', 'Post_Runtime_Engine' )`
  preserves backward compatibility for any external code that referenced
  the old name — the global `pre()` accessor, smoke tests, integration
  test scaffolding, and other plugins all continue to work.
- **GitHub auto-updater instantiation is `class_exists`-gated.** Mirrors
  the FRE 1.7.0 fix — the WP.org distribution build excludes
  `includes/Updates/` per guideline 8 (plugins must use WordPress's
  update mechanism). The class_exists guard means the same bootstrap
  code runs cleanly in both distributions without a fatal in the WP.org
  build.
- **All inline `<script>` and `<style>` blocks moved to enqueued asset
  files.** The connector admin page's styles + JS were extracted to
  `assets/css/connector-admin.css` and `assets/js/connector-admin.js`,
  loaded via a page-hook-gated `wp_enqueue_*` call with
  `wp_localize_script` for dynamic data (ajax URL, nonce, connector
  script URL, site URL, translated strings). The Groupings edit form's
  source-type row toggle was extracted to `assets/js/admin-groupings.js`
  and enqueued conditionally on the groupings page.

### Fixed

- **Plugin header `Plugin URI` set to a working URL.** The previous
  value pointed at a 404 (`/cpt-pages` subpath that doesn't exist).
  Replaced with the bare `https://promptlesswp.com`.
- **Plugin header `Author URI` removed.** Was identical to `Plugin URI`
  after the fix above; WP.org's upload-form validator rejects header
  sets where Plugin URI and Author URI carry the same value.
- **`Contributors` updated** from `flowmint` to `promptlesswp` to match
  the WP.org username that owns the submission.
- **`esc_url_raw` → `esc_url`** in one JS-output context in the
  connector admin page (output escaping vs database sanitization).

### Internal

- Build script (`bin/build-release.sh`) excludes additional engineering
  planning documents from the WP.org build, per WP.org reviewers
  flagging similar files as "AI-generated output" on the FRE
  submission: `docs/ARCHITECTURE.md`, `docs/HOSTED_VALIDATION.md`,
  `docs/INTEGRATION_PROMPTLESS.md`, `docs/POST_FIELDS_V1_1_DESIGN.md`,
  `docs/PRESSURE_TESTS.md`, `docs/ROADMAP.md`. The GitHub build keeps
  all docs.

## [0.4.0] — 2026-05-22

First release of the v1.1 post-fields feature surface (data layer + frontend rendering + admin UI + connector REST + MCP bridge), plus the post-staging-deploy fix cluster, the per-CPT archive-card meta toggles, the modern 2026 design refinement pass, AND the GitHub auto-updater so sites that install this plugin can pick up future releases automatically. Backward-compatible — existing v0.3.x CPTs continue to render identically until they opt in to post fields.

### Fixed (Plugin Check / compliance pass, pre-tag)

A WordPress Plugin Check (PCP) scan on the v0.4.0 build surfaced a small cluster of issues — most real, one expected-and-dismissed. Cleaned up in this pass so the v0.4.0 tag ships with a clean PCP report (modulo the auto-updater detection, which is documented as intentional for self-hosted plugins):

- **External-resource enqueue (3 sites).** The Iconify web-component JS was loaded from jsDelivr's CDN in `PRE_Frontend_Assets::enqueue()`, `PRE_Meta_Box::enqueue_assets()`, and `PRE_Card_Filter_Hooks::maybe_enqueue_card_assets()`. PCP flags any `wp_enqueue_script` call pointing at an external URL because it creates a third-party runtime dependency. Fix: bundled `iconify-icon@2.1.0` locally at `assets/js/iconify-icon.min.js` and updated all three enqueue paths to use `PRE_PLUGIN_URL . 'assets/js/iconify-icon.min.js'`. Eliminates the CDN dependency, removes GDPR exposure, and makes the plugin work offline. The Iconify component itself still fetches individual icon SVGs from `api.iconify.design` at paint time — that part is inherent to the component's design — but the component bundle is now self-contained.
- **Non-enqueued stylesheet / script in late-inject path.** `PRE_Card_Filter_Hooks::maybe_enqueue_card_assets()` previously emitted raw `<link>` and `<script>` tags via `printf()` because the action it subscribes to fires mid-response (after `wp_head` has already passed), and `wp_enqueue_style()` alone doesn't emit late. Fix: switched to `wp_register_style/script` + `wp_enqueue_style/script` + `wp_print_styles/scripts()` — the same APIs WordPress core uses for late-discovered assets (Customizer, block library). PCP now sees proper enqueue calls, and the runtime behavior is identical (the tags still emit at the point of first card render).
- **Translator comment misplaced.** `class-pre-meta-box.php` had a `/* translators: %s: Iconify icon-sets URL */` comment one level too high — sitting above `wp_kses()` rather than directly above the `__()` call. PCP requires the comment to be on the line immediately preceding the translation function. Fix: moved the comment between `wp_kses(` and `__(`.
- **`Tested up to` outdated.** Bumped from 6.9 to 7.0 in both `readme.txt`.

### Notes (intentional, not fixed)

- **`plugin_updater_detected` in `class-pre-github-updater.php`.** PCP flags this as severity 9 because plugin-provided updaters are forbidden in WordPress.org-hosted plugins. This plugin is self-hosted via GitHub Releases — the rule doesn't apply. The file's docblock already documents this explicitly, and the `phpcs:ignoreFile` directive at the top silences the PHPCS counterpart of the same rule. PCP's WordPress.org-targeted scanner doesn't honor `phpcs:ignoreFile` (PCP has its own scanner), so the warning will continue to appear in PCP reports — that's expected.

### Added (release infrastructure)

- **`PRE_GitHub_Updater` class** (`includes/Updates/class-pre-github-updater.php`). Self-contained GitHub auto-updater — no external library — modeled after the same pattern used by Form Runtime Engine. Polls the GitHub Releases API for the latest tag on a 12-hour cache, compares against the installed `PRE_VERSION`, surfaces newer releases through WordPress's standard `pre_set_site_transient_update_plugins` filter so the update appears in WP admin → Updates and in the plugins list with a one-click "Update now" button. Loaded only inside `is_admin()` so frontend requests carry no overhead. Targets `breonwilliams/post-runtime-engine` by default — edit the `$github_repo` property in the class if your fork is hosted elsewhere. Private repos are supported by defining the `PRE_GITHUB_TOKEN` constant in `wp-config.php` (a GitHub Personal Access Token with `repo` scope); the updater adds the auth header to both the API check and the zip download. Includes the "fix-source-dir" filter so GitHub's auto-generated `username-repo-hash` zipball directory name gets renamed back to `post-runtime-engine/` during extraction — WordPress recognizes it as the same plugin and updates in place rather than creating a duplicate.

v1.1 introduces the second field type — **post fields** — for scalar metadata display in both the single-post hero and card / archive / PostGrid contexts. Design contract: `docs/POST_FIELDS_V1_1_DESIGN.md`. Roadmap: `docs/ROADMAP.md` § 11.

### Added (Phase 8 — data layer)

- **`PRE_Post_Field_Registry` class** (`includes/Core/class-pre-post-field-registry.php`). CRUD over the `pre_post_fields_{cpt_slug}` option family. Mirrors the structure of `PRE_Grouping_Registry`: lazy per-CPT memoization, validator injection, `define()` / `get_all()` / `get()` / `remove()` / `reorder()` / `remove_all_for_cpt()`. Enforces the hard field-count cap (12 by default, filterable via `pre_max_post_fields_per_cpt`). Fires `pre_post_field_defined`, `pre_post_field_removed`, `pre_post_fields_reordered` actions.
- **`PRE_Validator` extensions.** New constants: `DISPLAY_TYPES` (9 closed types: currency, number_with_label, badge, meta_pair, date, text, rating, progress, multi_badge), `FIELD_POSITIONS` (5 + hidden, symmetric across card and single-hero contexts), `COLOR_INTENTS` (success / warning / danger / info / neutral), `DATE_FORMATS` (absolute / relative / custom), `SUPPORTED_CURRENCIES` (closed ISO 4217 set with `pre_supported_currencies` filter), plus length caps and the field-count thresholds. New methods: `validate_post_field_definition()`, `validate_post_field_value()` with per-display-type checks, `validate_post_field_visibility()` for per-post override shape.
- **`PRE_Post_Data` extensions.** Optional 4th constructor parameter (`$post_fields`) with lazy fallback to the global plugin instance — preserves backward compatibility with v0.3.x 3-arg callers. New methods: `get_field_values()`, `get_field_value()`, `set_field_values()`, `set_field_value()`, `get_field_visibility()`, `set_field_visibility()`, `is_field_visible()`. Composite display types (rating, progress) handle array-shape input transparently. Per-field meta keys (`_pre_field_{key}`) keep values queryable via WP_Query meta_query. Fires `pre_post_field_values_saved`, `pre_post_field_visibility_saved` actions.
- **`PRE_DATA_VERSION` bumped to 0.4.0** as a marker for v1.1 storage availability. No data migration required — post fields are purely additive opt-in.

### Added (Phase 9 — card renderer + CSS)

- **`PRE_Card_Renderer` class** (`includes/Frontend/class-pre-card-renderer.php`). Single entry point: `render( $post_id, $context )` where context is `card` or `single_hero`. Buckets visible fields by position, renders each of the 5 positions in semantic order with BEM-style classes (`.pre-card-fields__position--{position}`, `.pre-field--{display_type}`, `.pre-field--badge-{intent}`). Nine per-display-type render methods. Three-tier currency resolution chain: field-level `currency_code` → AISB Business Identity → `pre_currency` option → USD fallback. Locale-aware formatting via `number_format_i18n()` and `date_i18n()`. Filter hooks for per-field (`pre_card_field_html`) and whole-payload (`pre_card_fields_html`) customization.
- **`assets/css/cards.css` stylesheet.** Two-layer pattern: structural rules per position + per-display-type styling, context overrides (`.pre-card-fields--card` vs `.pre-card-fields--single-hero`). Color-intent variants for badge type. Mobile-responsive meta_strip layout. Neo-brutalist mode integration via existing `body.aisb-neo-brutalist-cards` class. Reads `--aisb-*` tokens via existing `--pre-color-*` intermediates with documented fallbacks per `docs/AISB_TOKEN_CONTRACT.md`.
- **Single-post hero integration.** `PRE_Renderer::render_hero()` now interleaves post field positions with the existing post title, featured image, and excerpt. `image_overlay` renders inside the media wrapper; `headline` renders above the title; `subtitle` renders directly below the title; `meta_strip` and `footer_meta` render below the excerpt. Existing CPTs without post fields render identically to v0.3.x (zero additional markup).
- **Conditional `cards.css` enqueue.** `PRE_Frontend_Assets::enqueue()` loads `cards.css` alongside `frontend.css` on registered CPT singles. PostGrid + archive integrations land in Phase 12 with their own enqueue paths.

### Added (Phase 10 — admin UI)

- **`PRE_Admin_Post_Fields` class** (`includes/Admin/class-pre-admin-post-fields.php`). New admin page at `admin.php?page=pre-post-fields&cpt={slug}`, mirroring the existing Groupings admin page structure (list view + new/edit form, server-rendered, server-side validation, POST-redirect-GET notices). Reachable from the CPT list row actions ("Post Fields" link added between "Groupings" and "Remove"). Form view exposes the closed display-type / position / color-intent enums via `<select>` controls. Conditional editor sections (badge options, meta_pair icon picker, date format, currency code, rating/progress max + unit label) toggle visibility based on the selected display type. Field-count cap surfaces as a soft warning banner at 8 fields and a hard error at 12. Drag-to-reorder via jQuery UI Sortable; "Save order" persists via a dedicated `reorder` POST.
- **`PRE_Meta_Box_Post_Fields` class** (`includes/Admin/class-pre-meta-box-post-fields.php`). New post-edit-screen meta box rendered alongside the existing `PRE_Meta_Box` (groupings). Only registers on CPTs that have at least one post field defined, so unused boxes don't clutter the edit screen. Renders the right input control per display type: numeric with currency-symbol prefix for `currency`, native `<input type="date">` for `date`, select dropdown for `badge` when `options` are defined, paired value + count inputs for `rating`, paired value + goal inputs for `progress`, comma-separated text for `multi_badge`. Per-field visibility toggles ("Hide on cards" / "Hide in single-page hero") render below each input. Own nonce (`pre_post_fields_nonce`) so the two meta boxes' save handlers don't collide. Composite display-type values normalize into the array shape `PRE_Post_Data::set_field_values()` expects.
- **`assets/js/post-fields-editor.js`** — plain jQuery, enqueued only on the Post Fields admin page. Drives two interactions: (1) toggling the conditional form sections based on the selected display type via `data-shown-when` attributes, and (2) jQuery UI Sortable for the list-view drag-to-reorder.
- **Admin CSS additions** (`assets/css/admin.css`). Drag-handle column, sortable placeholder styling, field-row layout in the post meta box (label + display-type chip + input + visibility toggles), per-input-type sub-components (currency symbol prefix, number unit suffix, paired rating/progress inputs).

### Added (Phase 11 — connector REST + preflight)

- **10 new REST endpoints** added to `class-pre-connector-api.php`, parallel to the existing groupings surface and gated by the same auth stack (`PRE_Connector_Auth::build_callback()` / `build_per_post_callback()`):
  - `GET /cpts/{slug}/post-fields` — list field definitions
  - `POST /cpts/{slug}/post-fields` — create a field
  - `GET /cpts/{slug}/post-fields/{key}` — read one
  - `PUT /cpts/{slug}/post-fields/{key}` — update (with `connector_version` concurrency)
  - `DELETE /cpts/{slug}/post-fields/{key}` — remove (preserves per-post values)
  - `POST /cpts/{slug}/post-fields/reorder` — bulk reorder, registered before the `{key}` route so "reorder" doesn't get captured as a field key
  - `GET /posts/{id}/field-values` — read all per-post field values
  - `PUT /posts/{id}/field-values` — partial bulk write (composite types accept `{ value, count }` / `{ value, goal }` shape)
  - `GET /posts/{id}/field-visibility` — read visibility overrides
  - `PUT /posts/{id}/field-visibility` — full-replace visibility overrides
- **Preflight extension.** The `/preflight` response now surfaces a `post_field_enums` block exposing the 9 display types, 5 positions, 5 color intents, 3 date formats, 30 supported currencies, and field count thresholds — each with human labels and descriptions so Cowork agents can pick valid values without trial-and-error against the validator. The `critical_rules` block gains six new entries documenting post-field authoring patterns (post fields vs groupings, position semantics, display-type chooser, value shape per type, count cap, visibility model). The `field_name_hints` block gains a `post_field_definition` entry listing the valid keys on a field definition payload.
- **CONNECTOR_SPEC.md updated** with full documentation for the new endpoints (section 5.7 — request bodies, response shapes, error codes, examples) and the new MCP tools (section 6 — `postruntime_*_post_field*` tool inventory).

### Deviations from the locked design contract

- **Live preview pane deferred.** The design contract (§ 8) called for a two-pane editor with a live-updating card and single-hero preview on the right. Phase 10 ships without it because the existing PRE admin (Groupings, CPTs) doesn't include any live preview, and adding one for the new page alone would be UX inconsistent. The deferral is captured here so the choice is auditable; if real client demand surfaces for the live preview, it ships as Phase 10.5 with a unified design that updates the Groupings admin in the same pass.

### Added (per-CPT archive-card meta toggles)

Two new optional CPT-definition fields let authors suppress the theme-rendered post create-date and author byline on archive cards, on a per-CPT basis. Backward-compatible: both default to `true`, so existing CPTs without these keys behave exactly as before.

- **`archive_show_post_date`** — boolean, default `true`. When `false`, the theme's archive card omits the post's create-date row. Use when a CPT already exposes a meaningful date through a post-field (e.g. an event CPT's `event_date` IS the date that matters; showing both is duplicative).
- **`archive_show_post_author`** — boolean, default `true`. When `false`, the theme's archive card omits the author byline. Use for CPTs where author identity is irrelevant (directories, single-admin publications).

The plumbing spans both repos:

- **Promptless theme** (`inc/template-functions.php`) — `promptless_post_meta_with_categories()` gained two filter hooks, `promptless_archive_card_show_date` and `promptless_archive_card_show_author`. Both default to `true` so existing theme behavior is unchanged. When all three meta items (date, author, categories) are suppressed, the wrapper div is also skipped.
- **PRE validator** (`PRE_Validator::validate_cpt_definition`) — accepts the two new boolean fields and rejects non-boolean values with `pre_invalid_archive_meta_flag`.
- **PRE registry** (`PRE_CPT_Registry::merge_defaults`) — defaults both to `true` so older stored definitions keep their existing behavior.
- **PRE filter callbacks** (`PRE_Card_Filter_Hooks::filter_archive_show_date` / `filter_archive_show_author`) — subscribe to the theme filters and return the per-CPT flag for PRE-managed posts. Non-PRE-managed posts fall through to the theme default unchanged.
- **PRE admin form** (`PRE_Admin_CPTs::render_form`) — adds an "Archive card meta" section with two checkboxes under the existing form. Both default to checked.
- **Connector REST** (`PRE_Connector_API::handle_register_cpt` / `handle_update_cpt`) — pass-through (no explicit handler change needed; the validator + registry accept the new keys). Surfaced in the preflight `cpt_definition` field-name-hints list.
- **MCP bridge JS** (`post-runtime-connector.js`) — `postruntime_register_cpt` and `postruntime_update_cpt` tool schemas declare both fields as optional booleans with descriptive guidance; the case handlers pass them through.

Also in this pass: **`handle_update_cpt` partial-update fix**. The previous code passed the body straight to `register()`, which always re-runs the full validator (including the `label_singular` / `label_plural` required-field checks). A caller doing a partial update like `update_cpt(slug, has_archive: true)` would be rejected with `pre_missing_label_singular`. Fixed by merging the body INTO the existing definition before passing to `register()` — preserves the upsert semantics while letting partial updates flow through cleanly. Verified by setting `has_archive: true` on staging session CPT (Test 2 in the post-staging-deploy verification round).

### Fixed (post-staging-deploy verification round)

A full production-readiness sweep on the staging environment surfaced two more issues that the local-only kitchen-sink test couldn't catch:

- **F8 — `<iconify-icon>` web component never registered on archives and PostGrid contexts.** The meta_pair display type emits `<iconify-icon icon="mdi:clock-outline">` markup, which requires the iconify-icon custom-element JS to register and render. `PRE_Frontend_Assets::enqueue()` was gated on `is_singular()` only, so on theme archives + AISB PostGrid sections the JS was never loaded — the elements stayed as empty 14×14 placeholders with no glyph visible. Two-part fix: (1) `PRE_Frontend_Assets::enqueue()` now also fires on `is_post_type_archive()` for registered PRE CPTs via a new `is_pre_managed_page()` helper, covering the theme-archive case; (2) `PRE_Card_Filter_Hooks::maybe_enqueue_card_assets()` (renamed from `maybe_enqueue_css`) now late-injects the iconify-icon module alongside cards.css, covering the PostGrid-section-on-a-Promptless-page case. Idempotent at three levels (per-request flag, WP enqueue registry, browser cache).
- **Archive enqueue gating bug** (related to F8). `is_singular()`-only gating meant that on `has_archive: true` CPTs, the theme archive page rendered post-field markup but with NO `cards.css` and NO iconify-icon JS — the page looked unstyled until `PRE_Card_Filter_Hooks` happened to be wired through `promptless_archive_card_section`. Fixed in the same pass by extending the enqueue gate to cover archives.

### Changed (design refinement pass — modern 2026 sizing & accessibility)

Following the post-pressure-test fix cluster, a comprehensive design audit pass refines pill / badge / meta sizing to match modern design-system standards (Linear, Stripe Primer, Vercel Geist) and the WCAG-comfortable lower bound for non-body text (≥12px for pills, ≥14px for muted body-adjacent). Colors stayed token-driven (already wired through `--aisb-*` via `--pre-color-*` intermediates); the audit revealed `font-size` was the off-axis problem.

The unifying change: **switch absolute typographic sizes from `em` (cascade-compounding) to `rem` (html-rooted, predictable)** for badge/pill/chip text. Padding stays in `em` so it scales with the pill's own font, but the pill's font no longer compound-shrinks when it lands inside two layers of font-size-relative parents (the recurring "pill ends up at 9px in footer_meta on cards" bug). Specific changes:

- **`.pre-field--badge`**: `font-size 0.75em → 0.75rem` (12px absolute), `padding 0.2em 0.7em → 0.3em 0.85em`, `line-height 1.3 → 1.4`. Image_overlay variant bumped to `font-size 0.8125rem` (13px) with `padding 0.45em 0.95em` for photo-background presence.
- **`.pre-field__multi-badge-pill`**: `font-size 0.7em → 0.75rem` (12px absolute — was compounding to ~9px in footer_meta), `padding 0.15em 0.55em → 0.35em 0.8em`, `line-height 1.3 → 1.4`. Matches `.pre-field--badge` geometry so single + multi badges share the same visual rhythm.
- **`.pre-field--meta-pair`**: `font-size 0.875em → 0.875rem` (14px absolute), `gap 0.3em → 0.4em` for clearer icon-text breathing room.
- **`.pre-field--rating`**: `font-size 0.9em → 0.875rem` (14px floor), `stars letter-spacing 0.02em → 0.08em` — real spacing between star characters that matches how Apple Maps / Yelp / Linear render rating widgets. Stars at 0.02em looked crammed.
- **`.pre-field--text.pre-field--position-footer-meta`**: `0.8em → 0.8125rem` (13px) — accessibility floor for muted body-adjacent text.
- **Currency at headline position (single-hero)**: `line-height 1.15 → 1.1` and added `letter-spacing: -0.01em` — standard display-type typography conventions for headings ≥24px to keep large numerals visually cohesive.
- **Single-hero subtitle rhythm**: `margin-top 0.25em → 0.5rem`, `margin-bottom 0.75em → 1rem` for modern hero vertical rhythm.
- **Single-hero meta_strip top divider**: `padding-top 0.75rem → 1rem`, `margin-top 1rem → 1.25rem` for airier section break.
- **Single-hero footer_meta**: `font-size 0.85em → 0.875rem` (14px), `margin-top 0.5rem → 0.75rem`.
- **Card-context meta_strip**: `font-size 0.85em → 0.875rem` (14px) to match the single-hero equivalent.
- **Card-context footer_meta**: `font-size 0.75em → 0.8125rem` (13px) — was 12px on small cards; now meets the touch-friendly floor.
- **Mobile breakpoint `@media (max-width: 600px)`**: pills get `min-height: 24px` for tap comfort (still below the WCAG 44px button minimum because badges aren't tap targets, but matches native iOS/Android chip rhythm). Image-overlay badges bump to `font-size: 0.875rem` + `padding: 0.5em 1em` on mobile because shrunken photos need bigger badges to stay readable. Footer-meta gets tighter `gap: 0.4em 0.65em` for compact wrapped rows.

Net effect on the user's specific concern: multi_badge pills go from ~9px (nested inside a 0.85em parent inside an 0.7em pill) to a stable 12px regardless of context. Visually substantial without dominating.

### Fixed (post-pressure-test pass, pre-ship)

A pressure test on a fresh conference-sessions setup (10 fields covering all 9 display types × all 6 positions × 3 color intents) surfaced a cluster of issues fixed here together:

- **Position extraction was clobbering content when a position contained nested-div fields.** `PRE_Renderer::extract_position_field_html()` used a non-greedy regex `.*?</div>` to slice positions out of the card renderer's serialized output. When meta_strip contained a `progress` field (which has nested `<div class="progress-track"><div class="progress-bar">…</div></div>` markup), the regex stopped at the FIRST `</div>` — closing only the innermost progress-bar — truncating the rest of progress, swallowing the rating field that came after it, and leaving meta_strip's wrapper unclosed so the browser auto-recovered by nesting footer_meta inside progress-track. Fix: stop extracting from a serialized string entirely. `PRE_Renderer::render_hero()` now calls `PRE_Card_Renderer::render_position_html( $post_id, $position, 'single_hero' )` once per slot — that method already existed for the AISB PostGrid + theme archive integrations and returns self-contained per-position HTML with no string parsing. The `extract_position_field_html` and `extract_overlay_field_html` helpers are removed.
- **CSS class names didn't match for three display types.** `PRE_Card_Renderer::classes_for_field()` normalized underscores to hyphens for `position` but NOT for `display_type`, so the renderer emitted `.pre-field--meta_pair`, `.pre-field--multi_badge`, and `.pre-field--number_with_label` while the CSS targeted the hyphenated forms. None of the styling for those three display types was applied (meta_pair icon alignment, multi_badge pill styling, number_with_label rules). Fix: normalize underscores in display_type the same way position does.
- **Subtitle and footer_meta positions had no flex layout.** Sibling fields in the same position rendered adjacent with no gap (e.g. `Workshop Studio 3$2,950 early-bird` running together). Fix: `display: flex; flex-wrap: wrap; gap: …` added to `.pre-card-fields__position--subtitle` and `.pre-card-fields__position--footer-meta` in `cards.css`, matching the existing treatment for headline + meta_strip.
- **meta_pair icon was misaligned with its value.** The `.pre-field--meta-pair` base inherited `align-items: baseline` from `.pre-field`, dropping the icon glyph to the text baseline. Fix: override to `align-items: center` on the meta_pair container plus `line-height: 1` on the icon span and `display: block` on the inner SVG / iconify-icon. Note this fix only took effect because the underscore-class bug above is fixed in the same pass — the rule existed before but wasn't matching anything.
- **Date storage truncated time component.** `PRE_Post_Data` always stored dates as `Y-m-d`, so a session with `session_date: "2026-09-12 14:30"` rendered as `September 12 · 12:00 AM` via a custom format string that included time tokens. Fix: detect a time-like substring (`/\d{1,2}:\d{2}/`) in the input and store as `Y-m-d H:i:s` when present; otherwise keep the existing date-only normalization. The renderer's `date_i18n()` handles both shapes transparently. Backward-compatible: existing date-only values stay unchanged.
- **`number_with_label` rendered with a double space.** When `unit_label` was authored with a leading space (e.g. `" sessions in track"` — a common "natural separator" instinct), the renderer's own value/label separator concatenated to it producing `12  sessions in track`. Fix: `trim()` the unit_label at render time in both `render_number_with_label()` and the progress field's label fallback.
- **`define_post_field` rate limit too tight for legitimate bulk-define.** AI agents setting up a new industry CPT routinely define 8–12 fields in one session; the 10/min default tripped after field #5. Fix: explicit per-endpoint entries in `RATE_LIMITS` for all 10 v1.1 endpoints. Reads at 60/min, value writes at 30/min, `define_post_field` and `update_post_field` at 30/min (raised from the default), destructive at 5/min. The grouping endpoints stay at 10/min because they're less likely to be bulk-defined.
- **Image-overlay fields silently dropped when post has no featured image.** Authors who defined a `track` or `level` badge on `image_overlay` couldn't see why it didn't render. Fix: the Post Fields meta box now emits a `notice notice-warning inline` block when the post has no featured image AND one or more fields are configured for `image_overlay`, listing the affected field labels. The render-time behavior is unchanged (silent drop); only the authoring affordance is added.

### Notes

- Phases 8 + 9 + 10 + 11 are merged in this entry because they ship together as the v1.1 connector-ready feature surface (data layer + frontend rendering + human-facing admin + AI / connector authoring). Phase 12 (AISB PostGrid filter + theme archive integration) follows in a subsequent release with cross-repo coordination.
- The MCP tool layer itself (the 10 `postruntime_*_post_field*` tools) is implemented in the upstream Promptless MCP server, not in this plugin. This plugin exposes the REST endpoints; the MCP server wraps them 1:1 per the existing pattern. Spec is in CONNECTOR_SPEC.md § 6.
- Greppable AISB-independence check still passes: zero new PHP-level references to AISB classes in Phase 11. The only AISB read is the soft `get_option('aisb_business_settings')` inherited from Phase 9's currency resolution chain.
- No automated tests added in this pass. The v1.0 test-coverage gap (`POST_RUNTIME_AUDIT.md` Critical #1) is now also a v1.1 gap; building unit-test scaffolding for both field types together remains the recommended path before v1.1 ships publicly.
- Open questions in design doc § 12 (sitewide currency setting, date format default, icon library reuse, live preview placeholder data, per-CPT field count cap) all resolved with concrete decisions during Phase 7.

## [0.3.4] — 2026-05-17

### Changed

- **Connector admin page UI refactored.** The setup page now opens with a "Connection Status" card showing the current state (Configured / Not Connected) and the kill-switch toggle inline. The three setup steps below are cleaner and more focused. App password availability check uses WordPress's canonical `wp_is_application_passwords_available()` which correctly allows local dev environments (Local by Flywheel, wp-env) even without HTTPS.

## [0.3.3] — 2026-05-16

Admin meta-box UX rebuild driven by demo-pressure-test feedback, plus a class of bug fixes spanning the meta box, frontend icon rendering, and connector content storage. Item height in the meta box drops 36% (360px → 229px). Iconify icons render at matching sizes regardless of variant. Connector-driven post_content lands as proper Gutenberg blocks instead of a single Classic-Editor wrapper. Critical demo bug (image upload silently failing on profile cards) fixed.

### Added

- **`<select>` icon picker in the admin meta box.** The 53-button visual quickpick grid is replaced with a compact category-grouped dropdown. The Iconify text input above is unchanged — Claude and power users can still type any of the 200,000+ Iconify codes. Saves ~330px of vertical space per item and removes the visual noise of 53 SVG renders per row.
- **Per-grouping variant gating in the meta box.** When the effective layout variant (override or default) is icon-only (compact-grid or horizontal-row), the "Add image" button hides per-item and a grouping-level amber notice appears explaining that uploaded images are dropped at render time. The note renders once per grouping, not per item — previously the duplicate amber notes added ~2,580px of repeated text on the demo page with 29 items. Mirrors `PRE_Renderer::render_item`'s variant-aware media resolution so the meta box never silently accepts data the renderer will discard.
- **`--pre-icon-size` CSS custom property** as the single source of truth for icon dimensions on the frontend. Every per-variant rule sets one value and `width` / `height` / `font-size` all pick it up. Adding a new variant in the future automatically gets correct sizing for both legacy SVG and Iconify-web-component render paths — no opportunity for the sizing bug below to recur.
- **Server-side HTML→Gutenberg blocks converter in the connector** (`ensure_gutenberg_blocks` in `PRE_Connector_API`). `POST /posts` and `PUT /posts/{id}` now wrap raw HTML in proper `<!-- wp:* -->` block delimiters when the input has no such markers. Handles h1-h6 (with `level` attr for non-h2), p, ul, ol (with `ordered:true` attr), blockquote, pre, hr, and figure-with-img. Unrecognized top-level elements fall through to `core/html` — matches what Gutenberg's own "Convert to blocks" command does. Idempotent: block-format input passes through unchanged with zero parsing cost.
- **`critical_rules.post_content_is_gutenberg_blocks`** replaces the older `post_content_is_html` rule. The new rule documents the canonical block-format contract with examples for every supported block type and describes the server-side converter as a defense-in-depth safety net (not a feature) — AI agents that send block format directly get faster writes and full control over block attributes.

### Changed

- **Item layout in the admin meta box.** Item height: 360px → 229px (36% smaller). Media column: 220px wide → 160px wide and 96×96 preview → 64×64 preview. Icon helper text: 2 lines → 1 line ("Any [Iconify code] or pick one below"). Grid template column updated to match the new media width so the fields column absorbs the freed horizontal space.

### Fixed

- **Image upload silently failed on profile cards** (card-grid + featured-card variants). Root cause: `setItemImage` in `meta-box.js` referenced an undefined `$iconSelect` variable carried over from a prior version when the icon picker was a single `<select>`. The `ReferenceError` threw *after* `image_id` was stored in the hidden input but *before* the thumbnail preview rendered, so the image persisted on save with zero visual confirmation. `$iconSelect` is now properly scoped via `$item.find('.pre-item__icon-select')` and the function runs to completion — thumbnail renders, "Add image" button label flips to "Replace image".
- **Iconify icon sizing mismatch on the frontend.** `<iconify-icon>` web components render their inner shadow-DOM SVG at `1em` (the host element's font-size), NOT at the host element's CSS width/height. The previous rule set `width: 2rem; height: 2rem;` on the host but left `font-size` at the default 16px, so an Iconify code like `fluent:chat-20-regular` painted a 16×16 SVG inside a 32×32 wrapper — visibly smaller than the curated SVG icons rendered next to it in the same card-grid. The `--pre-icon-size` variable now drives `font-size` alongside `width`/`height`, so both render paths land at the same size.

### Notes

- No data migration required. Existing CPTs, groupings, and per-post values continue to work unchanged.
- The smoke test suite (`tests/smoke-phase1.php`) and unit tests (`tests/Unit/`) all pass.

## [0.3.2] — 2026-05-15

Adds **dual-format icon system** alongside the existing curated 53-icon library. `icon_id` and `default_icon` now accept ANY Iconify code in `collection:name` form (e.g. `mdi:home`, `logos:wordpress`, `material-symbols:business-outline`, `fa6-solid:tooth`) in addition to the legacy curated IDs. ~200,000 additional icons across 100+ Iconify sets, browseable at icon-sets.iconify.design. Restores icon vocabulary parity with Promptless WP for connector-driven page-building workflows.

### Added

- **`PRE_Icon_Library::is_valid_id()`** — public accessor returning true for both legacy curated IDs and well-formed Iconify codes. Replaces direct `has()` checks at validator + renderer call sites.
- **`PRE_Icon_Library::is_iconify_format()`** — shape check (`collection:name` regex, sanitize-key-safe slugs on both sides of a single colon, MAX_ICONIFY_LENGTH = 100). Mirrors the JS-side check in `meta-box.js` for symmetric server/client validation.
- **`PRE_Icon_Library::legacy_to_iconify()`** — translates a curated ID to its Iconify equivalent (used by the quick-pick row and the `/icons` response's `legacy_map`). Passes Iconify codes through unchanged; returns empty for unknown input.
- **`PRE_Icon_Library::get_legacy_iconify_map()`** — full curated → Iconify map (53 entries targeting `mdi:*` for visual consistency with the curated SVGs).
- **`<iconify-icon>` web-component enqueue** in both admin (`PRE_Meta_Box::enqueue_assets`) and frontend (`PRE_Frontend_Assets::enqueue`). Sourced from the jsdelivr CDN as an ES module; cached site-wide. Same module Promptless WP enqueues, so when both plugins are active the browser shares one cached copy.
- **Iconify support metadata in `GET /icons`** — response now carries an `iconify` block (`supported`, `format`, `pattern`, `max_length`, `browse_url`, `render_pattern`, `legacy_map`, `note`) so connector consumers learn the contract in one round trip. Each curated icon entry also carries its `iconify_code` equivalent for cross-format awareness.
- **Smoke + unit test coverage** — `tests/smoke-phase1.php` gains 15 new Iconify assertions. `tests/Unit/ValidatorTest.php` gains `test_validate_grouping_item_accepts_iconify_code_in_icon_id` (5 different sets) and `test_validate_grouping_item_rejects_invalid_iconify_format` (6 malformed shapes including over-length, trailing colon, whitespace, uppercase).

### Changed

- **`icon_id` and `default_icon` validation accepts Iconify codes alongside legacy IDs.** Validator switches from `PRE_Icon_Library::has()` to `is_valid_id()`. Save-time errors (`pre_invalid_default_icon`, `pre_unknown_icon`) now point at BOTH discovery paths.
- **Renderer dispatches on format.** `PRE_Icon_Library::render()` returns an inline `<span><svg/></span>` for legacy IDs and `<iconify-icon icon="…"></iconify-icon>` for Iconify codes (component fetches from api.iconify.design at paint time with graceful fallback for missing icons).
- **Admin meta-box icon control: `<select>` → text input + quick-pick row.** The 53-icon dropdown was a hard ceiling on the user's icon vocabulary. Replaced with a monospace text input that accepts any Iconify code (matches Promptless WP's existing UX). A 28-px-thumb quick-pick grid below the input renders the curated 53 as one-click shortcuts. Preview tile updates live. Save handler swaps `sanitize_key()` (which would strip colons) for `sanitize_text_field()` + the validator's strict pattern check.
- **`critical_rules.icon_ids_must_be_registered`** rewritten to describe the dual-format contract.
- **`postruntime_list_icons` MCP description** rewritten to surface the iconify block.

### Migration notes

- **No data migration required.** Existing post meta keeps storing legacy curated IDs verbatim; renderer dual-dispatch handles them.
- **Frontend cost.** `<iconify-icon>` script (~20kb gzipped) now enqueued on every registered CPT single. Browser caches site-wide after first request.
- **`PRE_Icon_Library::has()` remains supported** for narrow "is this in the curated library specifically" checks. New code should prefer `is_valid_id()`.

## [0.3.1] — 2026-05-10

Connector pressure-test cycle on the Northcraft staging fixture surfaced three issues — two real bugs, one ergonomics gap. All three are addressed here. A separate finding about the PRE MCP wrapper input schemas being out of sync with the WordPress connector contract is documented in `POST_RUNTIME_AUDIT.md` as I-NEW; that fix lives in the Node.js MCP wrapper repo, not this plugin.

### Fixed

- **Renderer's main content body was empty in REST preview contexts.** `PRE_Renderer::render_internal()` called `setup_postdata( $post )` before invoking `the_content()`, which is sufficient inside a normal theme loop but NOT in REST contexts where the global `$post` is unset. WordPress's `setup_postdata()` only updates the *derived* globals (`$id`, `$authordata`, `$page`, etc.) — it does not set the global `$post` itself. Without the global, `the_content()` → `get_post()` returned empty and the rendered HTML had a present-but-empty `<div class="pre-content"></div>`. The renderer now explicitly assigns `$GLOBALS['post']` (with backup-and-restore) around the `the_content()` call. Frontend rendering inside a theme template was unaffected; only the connector's `preview_post` endpoint and any direct programmatic call from outside a loop were affected. Discovered during the connector pressure test on 2026-05-10. Regression test: `RendererTest::test_render_includes_post_content_when_no_global_post_is_set`.

### Changed

- **Validator error messages for unknown icons now point at the discovery endpoint.** Both `pre_invalid_default_icon` (CPT-level) and `pre_unknown_icon` (per-item) errors now include "call the connector's GET /icons endpoint (or postruntime_list_icons via MCP) to discover the 53 available icon IDs" plus a few category examples. Previously the error said "See PRE_Icon_Library for the available set" which is correct but unhelpful for AI agents that don't have direct file access. Pairs with the new `icon_ids_must_be_registered` critical rule below so the contract is visible in two places: at preflight time (proactive) and on rejection (corrective).
- **`critical_rules.icon_ids_must_be_registered` added to preflight.** Documents that the icon library is curated 53-icon (no Iconify, no Font Awesome, no SVG passthrough), instructs agents to call `GET /icons` on first connection, and lists per-category examples. Any MCP wrapper that auto-derives tool descriptions from preflight will pick this up; hand-coded MCP wrappers should add it manually to their `register_cpt` and `define_grouping` tool descriptions.

### Added (test coverage)

- **`CPTRegistryTest::test_register_persists_description_field`** — round-trip test for the CPT `description` field through `register()` → `get()`. Pins the WP-side handling. (The pressure test discovered the field appeared to drop, but root cause was traced to the MCP wrapper schema, not the WP plugin. This test guarantees the WP side stays correct so the MCP fix flows through end-to-end.)
- **`CPTRegistryTest::test_register_defaults_description_to_empty_when_omitted`** — pins the merge_defaults contract for `description`.
- **`RendererTest::test_render_includes_post_content_when_no_global_post_is_set`** — regression test for the REST-context content rendering bug. Defensively unsets `$GLOBALS['post']` before render, asserts a unique content marker appears in the rendered HTML.

## [0.3.0] — 2026-05-08

Driven by findings from a real hosted-environment pressure test (Northcraft Architects fixture on Bluehost staging). Two real bugs and one architectural gap surfaced during agentic content authoring of two cross-linked CPTs with multiple groupings. This release encodes the rules-of-the-road into the connector itself so the same mistakes can't reproduce, ships defensive sanitization for the most common authoring slip, fixes a renderer semantic, and extends the smoke test suite to lock the new behavior in.

### Added

- **`critical_rules` block in `postruntime_preflight`.** Mirrors Promptless WP's preflight rulebook pattern. Seven distilled rules covering the most frequent authoring failures: `post_content_is_html` (no CDATA wrappers), `groupings_creation_pattern`, `cross_cpt_item_icons`, `compact_grid_strips_image`, `link_post_id_canonical`, `postgrid_grid_balance`, `featured_card_max_one`. Each rule has a stable key and a clear instruction. AI agents reading preflight on first connection now see the contract before issuing their first write — without it, agents extrapolate from generic context and reach for plausible-but-wrong patterns (e.g. wrapping JSON parameters with XML CDATA notation).
- **`field_name_hints` block in `postruntime_preflight`.** Per-variant grouping item shape (compact-grid / horizontal-row / card-grid / featured-card) plus full CPT and grouping definition field lists. Helps agents avoid invented field names like `title` (use `heading`) or `subtitle` (use `supporting_text`).
- **`postruntime_update_post` connector tool.** Partial update of any post created through the connector — accepts subsets of `post_title`, `post_content`, `post_excerpt`, `post_status`, `featured_image_id`, `groupings`. Omitted fields are not changed. Same defensive CDATA sanitization as `create_post`. Closes the gap that previously forced `delete + recreate` (which broke cross-CPT references via `link_post_id`).
- **`_site` envelope on every PRE connector response.** Each response now includes `_site: {site_url, site_name, env_hint}` so AI agents can verify the target host before destructive operations. This addresses a near-miss during the hosted pressure test where a stale connector configuration was silently still pointed at a production site (725 Print Lab) while the operator believed it was disconnected. `env_hint` heuristically classifies the host as `production` / `staging` / `development` based on URL patterns; tuneable via the `pre_site_envelope` filter for sites with non-standard subdomain conventions. Hooked to `rest_request_after_callbacks` rather than `rest_post_dispatch` so the envelope is also applied on internal `rest_do_request()` calls (smoke tests + PHP-side integrations) — not just HTTP-served REST.

### Changed

- **CDATA sanitization on incoming `post_content`.** Both `create_post` and `update_post` strip a leading `<![CDATA[` and trailing `]]>` from `post_content` if it bookends the entire content. Surfaces a `post_content_cdata_stripped` warning in the response when it fires (or a stronger `post_content_cdata_opener_stripped` when only the opener is present). Conservative — mid-content CDATA tokens (e.g. an article body that documents XML syntax) are not touched. The connector-side strip is defense-in-depth; `critical_rules.post_content_is_html` documents the contract authors should follow.
- **Cross-CPT `default_icon` resolution in the renderer.** When a grouping item has `link_post_id` and no per-item `icon_id`, the renderer now first tries the LINKED post's CPT `default_icon`, then falls back to the host post's `default_icon`. Previously the host's default fired unconditionally — producing semantically wrong icons (e.g. a "Lead Architect" featured-card on a Project page showing the Project CPT's `home` icon instead of the Architect CPT's `user` icon). Items with explicit per-item `icon_id` are unaffected.

### Tests

Smoke suite extended from 99 to **138 assertions**, all passing. New coverage includes: every `critical_rules` key present, every variant's `field_name_hints` entry, `_site` envelope present on multiple route classes (preflight + introspection + CRUD), CDATA sanitization in three configurations (bookend / opener-only / mid-content), `update_post` round-trip through title-only / content-with-CDATA / invalid-status / empty-title-rejection, and rendered-HTML assertion that cross-CPT icon resolution returns the linked CPT's icon (not the host's). The Phase B test additions caught two real regressions during local validation before the release was built — the `rest_post_dispatch` hook didn't fire on internal dispatch (switched to `rest_request_after_callbacks`) and a wrong assumption about `wp_kses_post`'s interaction with mid-content CDATA tokens.

### Storage compatibility

`PRE_DATA_VERSION` stays at `0.1.0`. Every change in this release is purely runtime — preflight enrichment, request-time sanitization, render-time icon resolution, an additive REST endpoint. No storage schema changes. v0.3.0 installs cleanly over v0.2.0 by replacing files; existing CPT data, grouping data, and post meta are unaffected.

## [0.2.0] — 2026-05-08

Layered four orthogonal feature areas on top of the v0.1.0 foundation. Every change is additive — existing v0.1.0 CPT and grouping data renders identically without touching admin or connector calls; new behavior opts in via new fields with safe defaults.

### Added

- **Hero layout system.** Three new optional CPT-level fields control how the hero block above the post body is composed:
  - `hero_layout` (`stacked` | `split`) — `stacked` (default) renders the featured image as a 16:9 banner above the title block; `split` renders the image side-by-side with the title at desktop breakpoints, collapsing to a single column on mobile.
  - `hero_image_position` (`left` | `right`) — only meaningful for `split`. Stacked layouts always place the image above the text.
  - `hero_image_aspect` (`square` | `landscape` | `wide`) — only meaningful for `split`. Picks the natural shape of the image slot so featured photos crop cleanly: 1:1 for headshots, 4:3 for property/product photography, 16:9 for cinematic banners.
  - Wired through validator (with typed error codes), registry defaults, admin form (three dropdowns under a "Hero" panel), connector REST, MCP server JSON schemas, and smoke tests.
- **CPT `default_icon` field.** Optional icon ID from `PRE_Icon_Library` used as a fallback visual cue when a grouping item resolves to no media (no per-item icon, no image). Validated against the registered icon set; empty string allowed and means "no fallback". Especially relevant for icon-only variants (see below) — auto-source items from posts without `_pre_icon` meta now pick up the CPT-level default automatically. Surfaced in admin UI as a category-grouped `<optgroup>` dropdown.
- **Smart link / icon token consumption.** `frontend.css` now routes link colors, link-hover treatments, icon colors, and focus outlines through Promptless WP's `SmartColorManager` tokens (`--aisb-smart-{light|dark}-{section|surface}-{link|icon}` plus the `*-link-hover-brightness` pair). When the user enables Promptless's "Smart Accessibility Colors" toggle, contrast against the actual surface is computed at runtime per WCAG AA and AA-non-text targets — so a low-contrast brand primary (e.g. light cream on white) still renders icons, link hovers, and focus indicators with adequate contrast. PRE wraps the upstream tokens in intermediate `--pre-color-link` / `--pre-color-surface-link` / `--pre-color-icon` / `--pre-color-surface-icon` / `--pre-link-hover-brightness` / `--pre-surface-link-hover-brightness` so the dark-mode block flips the entire chain in one place. Documented as a token contract addition in `docs/AISB_TOKEN_CONTRACT.md`.
- **`.pre-content a:hover` state.** Body-content links now have an explicit hover treatment (`filter: brightness()` plus a thicker underline) and a `:focus-visible` outline using `currentColor`. Previously links had no hover state at all, which failed WCAG 2.4.7 / 1.4.11 against any brand primary.

### Changed

- **`compact-grid` and `horizontal-row` are now icon-only variants by design.** The renderer drops `image_id` for these variants so the variant's visual rhythm wins over per-item content shape. Auto-source items (`taxonomy_match`, `child_posts`) that previously sprawled featured-image thumbnails inside compact rows now resolve to icons (per-item `_pre_icon` meta first, falling back to the CPT's `default_icon`). If neither is set, items render iconless — graceful degradation, never breakage. Featured images route through `card-grid` and `featured-card` variants where the layout was built around them.
- **WP attachment size selection per variant.** `featured-card` keeps `large`, `card-grid` keeps `medium`, and `compact-grid` / `horizontal-row` (still emitted in legacy paths) request `thumbnail` — closer to the actual rendered slot size and avoids pulling 300px sources just to display 32px of them.
- **`pre-grouping__media--image` modifier.** The grouping renderer now emits this class on image-bearing media wrappers (parallel to the existing `--icon` modifier), so per-variant CSS can size images and icons independently without leaning on attribute selectors or `:has()`.
- **`.pre-grouping__cta-arrow` and `.pre-grouping__link-overlay:focus-visible`** now route through `--pre-color-surface-link` instead of raw `--pre-color-primary`, so the cta-arrow and focus rings inherit smart contrast correction.

### Documentation

- `docs/CONNECTOR_SPEC.md` — added the four new CPT shape fields (`hero_layout`, `hero_image_position`, `hero_image_aspect`, `default_icon`), their semantics, and the four new validator error codes.
- `docs/AISB_TOKEN_CONTRACT.md` — declared the eight new smart-link / smart-icon tokens consumed, plus a note explaining Promptless's `smart_accessibility_colors` toggle behavior so users with low-contrast brand primaries know how to enable runtime contrast adjustment.
- `docs/SETUP.md` — flagged the `Smart Accessibility Colors` toggle for users picking intentionally low-contrast brand colors.

### Tests

Smoke suite: 96 → 99 assertions, all passing. New coverage for hero-aspect round-trip, invalid-aspect rejection, default-icon round-trip, default-icon rejection (unknown ID), and default-icon empty-default behavior.

### Storage compatibility

`PRE_DATA_VERSION` stays at `0.1.0`. All new fields are additive — `PRE_CPT_Registry::merge_defaults()` fills missing keys for v0.1.0-era stored CPTs on read, so no migration step is needed. v0.2.0 can be installed over v0.1.0 by replacing files; the next page render picks up the new defaults transparently.

## [0.1.0] — 2026-05-08

Initial release. Phases 0–6 of the build plan: foundation (autoloader, validator, registry, capabilities), admin UI (CPT manager, grouping definitions, item meta box with icon picker + post-search autocomplete), frontend renderer (four grouping variants with site-portable internal links), REST connector + MCP tool layer (18 tools, three-check permission stack, idempotent updates via `connector_version`), Promptless WP design-token integration with light/dark mode and neo-brutalist support, render-time transient caching, accessibility audit, and the build/release pipeline.
