# Changelog

All notable changes to Post Runtime Engine are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the plugin is pre-1.0, the public surface (CPT shape, grouping shape, REST connector, MCP tools) is treated as semi-stable — additive changes are minor releases; backward-incompatible changes are noted in their own section even at this stage.

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
