# Changelog

All notable changes to Post Runtime Engine are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the plugin is pre-1.0, the public surface (CPT shape, grouping shape, REST connector, MCP tools) is treated as semi-stable — additive changes are minor releases; backward-incompatible changes are noted in their own section even at this stage.

## [0.4.0] — 2026-05-22

First release of the v1.1 post-fields feature surface (data layer + frontend rendering + admin UI + connector REST + MCP bridge), plus the post-staging-deploy fix cluster, the per-CPT archive-card meta toggles, and the modern 2026 design refinement pass. Backward-compatible — existing v0.3.x CPTs continue to render identically until they opt in to post fields.

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
