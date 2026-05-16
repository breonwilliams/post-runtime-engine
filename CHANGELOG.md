# Changelog

All notable changes to Post Runtime Engine are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the plugin is pre-1.0, the public surface (CPT shape, grouping shape, REST connector, MCP tools) is treated as semi-stable — additive changes are minor releases; backward-incompatible changes are noted in their own section even at this stage.

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
