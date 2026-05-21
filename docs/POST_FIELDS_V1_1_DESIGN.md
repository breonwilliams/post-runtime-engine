# Post Fields — v1.1 Design Contract

**Document status:** Draft — formal scope-expansion conversation that the v1 guardrails in `CLAUDE.md` and `ARCHITECTURE.md` anticipated. Locks the architectural decisions for v1.1 before any code is written. Phase 7+ implementations MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

**Author:** Breon Williams + Claude (planning sessions)
**Initiated:** 2026-05-20
**Target ship version:** PRE v1.1.0
**Scope-expansion trigger:** Real client demand across multiple verticals (real estate, law firms, medical providers, courses, events, restaurants, automotive, travel, non-profits, contractors, pet shelters, wedding vendors, government directories, financial advisors) for displaying structured scalar metadata both in the single-post hero and in card / archive contexts.

---

## 1. Why this is being built

The v1.0 guardrails in `CLAUDE.md` explicitly anticipated this conversation:

> *Resist scope creep on field types. v1 has ONE field type ("grouping"). The temptation to add a second field type will be intense. Don't. Adding a second field type is a v1.1 conversation triggered by real client demand, not by AI suggestion.*

The trigger condition is now met. The real-estate kitchen-sink demo validates that PRE's single-post template handles structured display beautifully, but card / archive / PostGrid contexts have no symmetric mechanism. A real-estate listing's single page can show price, beds, baths, sqft in the hero — but the same data is invisible when the listing appears in an archive grid or an AISB PostGrid section on the homepage. The same gap blocks every directory-style use case the agency builds for.

The v1.0 grouping primitive intentionally does not solve this. Groupings are repeatable, with a fixed `{image-or-icon, heading, supporting_text, link}` item shape. Most card metadata is scalar (one value per field), with a different visual treatment per field (currency formatting, badges with color semantics, icon+value meta pairs). Stretching groupings to cover this would dilute both the single-post layout discipline and the card-rendering pattern. A second field type — focused, constrained, scalar — is the cleaner architectural answer.

## 2. Architectural premise

The mental model is symmetric with the existing single-post layout: small enum × small enum × one field type. v1.0 gave PRE:

- 1 field type (grouping, repeatable)
- 3 single-post positions (above_main, below_main, sidebar)
- 4 layout variants (compact-grid, card-grid, featured-card, horizontal-row)

v1.1 adds:

- 1 field type (post field, scalar)
- 5 positions usable in BOTH single-post hero AND card contexts (image_overlay, headline, subtitle, meta_strip, footer_meta)
- 9 display types (currency, number_with_label, badge, meta_pair, date, text, rating, progress, multi_badge)

The position enum is intentionally symmetric across the two render contexts. A field declared at `card_position=headline` and `single_position=headline` renders as a prominent value in BOTH the card and the single-post hero — same semantic role, different visual treatment driven by CSS. This is design coherence by construction: a CPT's data renders consistently regardless of where it appears on the site.

The constraint is what makes this AI-legible. Cowork sees a small enumerated set of choices for every dimension. The combinatorial space stays bounded; the practical coverage scales across verticals because every common card pattern reduces to "pick one display type per field, pick one position per context."

## 3. Scope for v1.1

### In scope

- A new field type — **post field** — distinct from groupings. Scalar, one value per post per field, with a typed display.
- Per-CPT post field definitions stored in `wp_options` under `pre_post_fields_{cpt_slug}`. Parallel to the existing `pre_groupings_{cpt_slug}` pattern.
- Per-post values stored as individual post meta entries (`_pre_field_{field_key}`) — one meta per field, queryable via WP_Query meta_query.
- Per-post visibility overrides stored in a single `_pre_field_visibility` JSON object — a post can hide a specific field on its card or its single-post hero, but cannot override the field's position or display type.
- 9 display types (see § 5.3).
- 5 positions (see § 5.4), symmetric across single-post hero and card contexts.
- Single-post hero rendering integration: post fields render inside the existing hero block, alongside the post title and featured image.
- Card rendering integration via a new `PRE_Card_Renderer` class consumed by:
  - AISB's PostGrid section, via a new `aisb_postgrid_card_content` filter exposed by AISB.
  - The Promptless theme's archive card template, via a parallel `promptless_archive_card_content` filter.
  - Any third-party theme or plugin that exposes the same filter shape.
- Admin UI: a new "Post Fields" tab on the CPT edit screen, plus an extended per-post meta box with appropriate inputs for each registered field.
- Live preview pane on the CPT edit screen showing both a card preview AND a single-post hero preview, updating as fields are configured.
- Connector REST endpoints under `/post-runtime/v1/connector/cpts/{slug}/post-fields/*` parallel to the existing grouping endpoints, with full MCP tool surface.
- Closed enums for color_intent (success / warning / danger / info / neutral) on the badge display type.
- Validator extensions: field-shape validation, display-type enum check, position enum check, color_intent enum check.
- Backward compatibility: existing v0.3.x installs continue to work unchanged. CPTs without post fields render identically to today.

### Explicitly out of scope for v1.1

- **Cross-CPT relationships.** "This listing has an agent (another CPT)." Tempting but a substantial separate architectural conversation — relationship fields, two-way binding, query implications. Deferred to v1.2+.
- **Computed / virtual fields.** "Days on market" derived from `listed_date`. Requires a formula engine. Deferred.
- **Facet filtering on archives.** Once cards expose structured data, archive pages naturally want filter UIs ("3+ beds, $500K-$1M, For Sale only"). This is a substantial v1.2+ deliverable; it would benefit from FRE composition (the "filter as form" pattern noted in the v1.0 roadmap).
- **Automatic schema.org emission per field config.** Real-estate listings should emit `RealEstateListing` schema, jobs `JobPosting`, events `Event`. AISB has a `SchemaRegistry`. The right integration is a per-field mapping (e.g., `price` field → `price` in JobPosting schema). Worth doing — but as its own dedicated v1.2 phase, not bolted onto v1.1.
- **Currency / number / date range types.** A range value like "$500K – $700K" can be entered as text in v1.1. A dedicated `range` display type may ship in v1.2.
- **Multi-language / localization of field values.** Field values are stored as raw strings; the rendering layer formats currency / dates per site locale. Per-string translation deferred.
- **Per-instance overrides beyond visibility.** A post can hide a field but cannot change its position or display type. Full per-post layout overrides are deferred indefinitely — the design coherence principle is the feature.
- **ACF / MetaBox / Pods migration tooling.** PRE still owns the field model end-to-end. v1.1 does not change that.
- **Custom display types via filter.** v1.1 ships a closed enum. Extension via filter is a v1.2 conversation that requires a thoughtful contract for what a third-party display type may and may not do.

## 4. Architectural decisions (locked)

These were settled during planning. Disagreements resolved by editing this doc.

1. **Second field type, not stretched groupings.** Groupings remain repeatable with the v1.0 item shape. Post fields are scalar. Two distinct primitives serve two distinct purposes; conflating them dilutes both.

2. **Per-CPT configuration, not per-grid-instance.** Card field configuration lives on the CPT definition. Every PostGrid, archive, related-posts surface, and search result that lists posts from that CPT renders with the same field set. Per-instance configuration was rejected as AI-illegible and consistency-corrosive.

3. **Single definition for single + card contexts.** A field declared once on the CPT picks two positions: one for the single-post hero, one for the card. Same field, two surfaces. Avoids doubled registries and forces consistency.

4. **Symmetric position enum.** The same 5 positions (image_overlay, headline, subtitle, meta_strip, footer_meta) apply to both contexts. CSS handles visual differences. "Hidden" is the sixth option meaning "don't render in this context."

5. **Hide-only per-post overrides.** A post can hide a field in either context via `_pre_field_visibility` post meta. Positions and display types are locked at the CPT level. Full per-post overrides were rejected as design-drift inducing.

6. **Closed display-type enum.** 9 display types ship in v1.1. No filter-based extension in v1.1. Extension is a v1.2 conversation.

7. **One meta key per field value.** Per-post field values stored as individual post meta entries (`_pre_field_{field_key}`), not a single serialized blob. Queryable via WP_Query meta_query, compatible with WP's native meta REST exposure, and discoverable via WP_CLI / wp_postmeta inspection.

8. **AISB PostGrid integration via filter, not direct call.** PRE's CSS-only coupling to AISB stays intact. AISB exposes `aisb_postgrid_card_content`; PRE hooks it. AISB has zero knowledge of PRE's existence.

9. **No custom DB tables.** Field definitions in `wp_options`, field values in post meta, visibility overrides in post meta. Same constraint as v1.0; same portability benefit across managed hosts.

10. **Backward compatibility is mandatory.** Existing v0.3.x sites continue to work unchanged. Adding post fields is opt-in per CPT.

## 5. Data model

### 5.1 Field definition shape

A post field definition stored under `pre_post_fields_{cpt_slug}` looks like:

```php
array(
    'key'              => 'price',                    // sanitize_key; unique within CPT
    'label'            => 'Price',                    // display label (admin + a11y)
    'display_type'     => 'currency',                 // one of § 5.3
    'card_position'    => 'headline',                 // one of § 5.4 (or 'hidden')
    'single_position'  => 'headline',                 // one of § 5.4 (or 'hidden')
    'icon'             => '',                         // optional, for meta_pair type
    'color_intent'     => 'neutral',                  // optional, for badge type
    'options'          => array(),                    // optional, for badge type with predefined choices
    'description'      => '',                         // admin help text (optional)
    'required'         => false,                      // affects admin UI only (not validation)
)
```

The full per-CPT option value is an associative array keyed by field key:

```php
get_option( 'pre_post_fields_listings' );
// =>
array(
    'price'        => array( /* ... */ ),
    'status'       => array( /* ... */ ),
    'beds'         => array( /* ... */ ),
    'baths'        => array( /* ... */ ),
    'sqft'         => array( /* ... */ ),
    'listed_date'  => array( /* ... */ ),
);
```

### 5.2 Per-post storage

For each registered field, the value lives in its own post meta entry:

```
_pre_field_price        => '1250000'
_pre_field_status       => 'for_sale'
_pre_field_beds         => '3'
_pre_field_baths        => '2'
_pre_field_sqft         => '1800'
_pre_field_listed_date  => '2026-05-15'
```

Visibility overrides live in a single JSON object:

```
_pre_field_visibility   => '{"price":{"card_hidden":false,"single_hidden":false},"agent_phone":{"card_hidden":true,"single_hidden":false}}'
```

Absence of an entry = field uses default visibility (rendered in both contexts).

Why per-field meta keys rather than a single serialized array: native WP queryability, REST visibility, WP-CLI inspectability, and compatibility with caching plugins that respect the `update_meta_cache()` call pattern.

### 5.3 Display types (closed enum, 9 types)

| Type | Renders as | Typical position | Notes |
|------|-----------|------------------|-------|
| `currency` | Formatted currency value, e.g. `$1,250,000` | headline | Locale-aware formatting via `number_format_i18n()`; currency symbol from CPT-level or sitewide setting |
| `number_with_label` | Number + unit, e.g. `1,800 sqft` or `3 BR` | meta_strip | Label is part of the field definition; value comes from post meta |
| `badge` | Pill / chip with color_intent | image_overlay or subtitle | color_intent: `success` / `warning` / `danger` / `info` / `neutral`. Optional `options` array of predefined choices |
| `meta_pair` | Icon + value, inline | meta_strip | Icon from `PRE_Icon_Library`; value from post meta |
| `date` | Formatted date or relative time | footer_meta or subtitle | Format selectable: `absolute` (`May 20, 2026`) or `relative` (`2 days ago`) |
| `text` | Plain text value | subtitle or any | Catch-all for values that don't fit other types |
| `rating` | Composite rating: stars + count | meta_strip | Two underlying values: `_pre_field_{key}` (rating value) + `_pre_field_{key}_count` (review count) |
| `progress` | Progress bar with percentage label | meta_strip or subtitle | Two underlying values: `_pre_field_{key}` (current) + `_pre_field_{key}_goal` (target) |
| `multi_badge` | Multiple pills in one slot | meta_strip | Value is comma-separated; renderer splits and pills each. Useful for "Vegan, GF, Quick" |

### 5.4 Positions (closed enum, 5 + hidden)

The same 5 positions apply to both the single-post hero and the card. CSS handles visual differences between contexts.

| Position | Card semantics | Single-post semantics |
|----------|---------------|----------------------|
| `image_overlay` | Pill / chip overlaid on the featured image (top-left by default) | Pill / chip overlaid on the featured image in the hero |
| `headline` | Large prominent value above the title | Large prominent value above the title in the hero |
| `subtitle` | Small line directly under the title | Small line directly under the title in the hero |
| `meta_strip` | Inline horizontal strip of small items (icon+value pairs, etc.) | Inline horizontal strip of items in the hero, below the title |
| `footer_meta` | Small muted line at the bottom of the card | Small muted line below the hero block, before main content |
| `hidden` | Field is registered but not rendered in this context | Field is registered but not rendered in this context |

Multiple fields can occupy the same position. They render in field-definition order (the order they appear in `pre_post_fields_{cpt_slug}`). Drag-to-reorder in admin UI rearranges the option array.

### 5.5 Color intent enum (for badge type, 5 values)

| Intent | Visual mapping |
|--------|----------------|
| `success` | Green ramp (uses `--aisb-color-success` token with documented fallback) |
| `warning` | Amber ramp (uses `--aisb-color-warning`) |
| `danger` | Red ramp (uses `--aisb-color-error`) |
| `info` | Blue ramp (uses `--aisb-color-info`) |
| `neutral` | Muted gray (uses `--aisb-color-text-muted`) |

The badge display type may optionally define an `options` array — a curated list of allowed values, each with its own label and color_intent override. This is the "open now / closed / coming soon" pattern: the field's base color_intent is `neutral`, but specific option values map to specific intents.

```php
'status' => array(
    'display_type'  => 'badge',
    'color_intent'  => 'neutral',  // fallback
    'options'       => array(
        'open'        => array( 'label' => 'Open now',    'color_intent' => 'success' ),
        'closing'     => array( 'label' => 'Closing soon', 'color_intent' => 'warning' ),
        'closed'      => array( 'label' => 'Closed',      'color_intent' => 'danger' ),
        'coming_soon' => array( 'label' => 'Coming soon', 'color_intent' => 'info' ),
    ),
)
```

## 6. Renderer architecture

### 6.1 New class: `PRE_Card_Renderer`

`includes/Frontend/class-pre-card-renderer.php`

Single entry point: `PRE_Card_Renderer::render( $post_id, $context )` where `$context` is `'card'` or `'single_hero'`. Returns rendered HTML.

The renderer:

1. Loads the post field definitions for the post's CPT via `PRE_Post_Field_Registry::get_all($cpt_slug)`.
2. Loads all field values for the post in one `get_post_meta($post_id)` call (single query, hits the meta cache).
3. Loads visibility overrides from `_pre_field_visibility`.
4. For each field, skips if the position for the current context is `hidden` or if visibility overrides hide it.
5. Buckets remaining fields by position.
6. Renders each position in semantic order: image_overlay → headline → (title — supplied by caller, not by PRE) → subtitle → meta_strip → footer_meta.
7. Each field within a position renders through a per-display-type method (`render_currency`, `render_badge`, `render_meta_pair`, etc.).

The renderer does NOT render the post title or featured image — those are the caller's responsibility (the AISB section, the theme template, or the PRE single-post template). PRE_Card_Renderer is a content augmenter, not a card builder.

### 6.2 Per-display-type rendering

Each display type is handled by a method on `PRE_Card_Renderer`. Output is HTML strings with escaped values.

Display types share a common BEM-style class structure:

```html
<span class="pre-field pre-field--currency pre-field--position-headline">
    $1,250,000
</span>
```

```html
<span class="pre-field pre-field--badge pre-field--badge-success pre-field--position-overlay">
    Open now
</span>
```

Class composition: `pre-field` (base) + `pre-field--{display_type}` (type) + `pre-field--position-{position}` (position) + optional modifiers (`pre-field--badge-{color_intent}`).

### 6.3 CSS pattern

`assets/css/cards.css` (new file).

Two-layer CSS:

1. **Base layer**: structural rules for each position. Defines layout, spacing, and typographic scale. Read `--aisb-*` tokens with documented fallbacks (per the existing AISB token contract).
2. **Context layer**: per-context overrides. `.pre-card .pre-field--position-headline` vs `.pre-single-hero .pre-field--position-headline` may apply different font sizes (cards are smaller than hero blocks).

The context selector is supplied by the wrapper element the caller provides:

```html
<article class="pre-card">
    <!-- AISB PostGrid wraps each card with this class -->
    [PRE-rendered fields and other card content]
</article>
```

vs in the single-post hero:

```html
<header class="pre-single-hero">
    [PRE-rendered fields and post title and featured image]
</header>
```

### 6.4 Integration into the single-post template

`PRE_Renderer` (the existing single-post template renderer) calls `PRE_Card_Renderer::render($post_id, 'single_hero')` inside its hero block. The rendered fields are interleaved with the post title and featured image per the position enum.

Existing CPTs without post fields render unchanged: `PRE_Post_Field_Registry::get_all($cpt_slug)` returns an empty array, and the renderer adds zero output to the hero. No visual regression, no migration step.

### 6.5 AISB PostGrid integration

AISB exposes a new filter (Phase 12 of the build):

```php
$card_html = apply_filters( 'aisb_postgrid_card_content', $default_card_html, $post_id, $section_settings );
```

PRE hooks the filter at default priority. If `$post_id`'s post type is registered with PRE, PRE replaces `$default_card_html` with its own card markup (calling `PRE_Card_Renderer::render($post_id, 'card')` for the fields, plus the post title, featured image, and excerpt). Otherwise PRE returns `$default_card_html` unchanged.

AISB stays unaware of PRE. PRE listens and decorates. Same pattern as the Promptless theme listens for AISB tokens.

### 6.6 Promptless theme archive integration

The Promptless theme's `template-parts/archive/card.php` exposes a parallel filter:

```php
$card_html = apply_filters( 'promptless_archive_card_content', $default_card_html, $post_id );
```

PRE hooks this filter using the same `PRE_Card_Renderer` call. Net result: a CPT registered with PRE renders consistently across:

- AISB PostGrid sections (anywhere on a Promptless-managed page)
- Native WP archive pages (default route for a CPT)
- WP search results
- Related-posts widgets that follow the same filter convention
- Any third-party theme that exposes a similarly-named filter

Four-plus surfaces, one renderer, zero per-surface duplication.

## 7. Validator extensions

`includes/Core/class-pre-validator.php` gains new constants and methods.

```php
const DISPLAY_TYPES = array(
    'currency',
    'number_with_label',
    'badge',
    'meta_pair',
    'date',
    'text',
    'rating',
    'progress',
    'multi_badge',
);

const FIELD_POSITIONS = array(
    'image_overlay',
    'headline',
    'subtitle',
    'meta_strip',
    'footer_meta',
    'hidden',
);

const COLOR_INTENTS = array(
    'success',
    'warning',
    'danger',
    'info',
    'neutral',
);
```

New methods:

- `validate_post_field_definition( $field )` — full field-shape validation
- `validate_post_field_value( $field_def, $value )` — value-shape validation per display type
- `validate_post_field_visibility( $visibility )` — visibility-overrides JSON validation

Strict-mode discipline: invalid input rejected at save time, never glossed over at render time. Mirrors v1.0's validation discipline.

## 8. Admin UI

### 8.1 New tab on CPT edit screen

`includes/Admin/class-pre-admin-post-fields.php` (new file).

The CPT edit screen gains a second tab: "Post Fields" alongside the existing "Groupings". Same horizontal-tab pattern as today. Default tab on a fresh CPT remains "Groupings" for continuity.

### 8.2 Post Fields tab layout

Two-pane layout:

- **Left pane (60%):** field list. Each field is a row showing key, label, display type, single position, card position. Drag-to-reorder handle on hover. Edit / delete buttons. "Add Field" button below the list.
- **Right pane (40%):** live preview. Two stacked previews — a card mockup and a single-post hero mockup — both rendering the current field configuration with placeholder data. Updates in real time as fields are added / edited / reordered.

### 8.3 Field editor (inline, not modal)

Clicking "Add Field" or "Edit" expands an inline editor in the left pane (the row becomes a form). Fields:

- Field key (sanitize_key)
- Label
- Display type (dropdown with per-option mini-preview)
- Single-post position (dropdown including "Hidden")
- Card position (dropdown including "Hidden")
- Conditional fields shown based on display type:
  - `badge` → color_intent, optional predefined options
  - `meta_pair` → icon picker
  - `date` → format (absolute / relative)
  - `progress` → goal value field key
  - `rating` → review-count field key
- Description (optional help text)

Save / Cancel buttons. No modal — modals fight the live-preview pattern.

### 8.4 Per-post meta box updates

`includes/Admin/class-pre-meta-box.php` (existing file) gains a "Post Fields" section above or below the existing "Groupings" section. For each registered post field, the right input type:

- `currency` → numeric input with currency prefix
- `number_with_label` → numeric input
- `badge` → select dropdown (if `options` defined) or text input
- `meta_pair` → text input
- `date` → date picker
- `text` → text input
- `rating` → numeric input (1-5) + review count numeric input
- `progress` → numeric input + goal numeric input
- `multi_badge` → comma-separated text input or tag-style input

Plus per-field visibility toggles: "Hide on this post's card" / "Hide on this post's single-page hero" checkboxes.

## 9. Connector REST + MCP

New endpoints under `/post-runtime/v1/connector/cpts/{slug}/post-fields/*`, parallel to the existing grouping endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/post-fields` | GET | List all post field definitions for the CPT |
| `/post-fields` | POST | Add a new post field definition |
| `/post-fields/{key}` | GET | Read a single field definition |
| `/post-fields/{key}` | PUT | Update a field definition |
| `/post-fields/{key}` | DELETE | Remove a field definition |
| `/post-fields/reorder` | POST | Bulk reorder field definitions |
| `/posts/{post_id}/field-values` | GET | Read all field values for a post |
| `/posts/{post_id}/field-values` | PUT | Bulk update field values for a post |
| `/posts/{post_id}/field-visibility` | GET | Read visibility overrides for a post |
| `/posts/{post_id}/field-visibility` | PUT | Update visibility overrides for a post |

All endpoints follow the existing PRE connector auth pattern: WP App Password + per-site connector enable toggle. Never license-gated.

MCP tools (mirror the REST endpoints):

- `register_post_field`
- `update_post_field`
- `delete_post_field`
- `list_post_fields`
- `reorder_post_fields`
- `set_post_field_values`
- `get_post_field_values`
- `set_post_field_visibility`

Preflight extensions: the existing `/preflight` endpoint surfaces the new display-type enum, position enum, and color-intent enum so Cowork sessions see the closed set of valid values without trial-and-error.

## 10. Edge case decisions (all 4 ship in v1.1)

### 10.1 Progress bar — `progress` display type

Renders as a horizontal bar with a percentage label. Storage: two post meta entries — `_pre_field_{key}` (current) and `_pre_field_{key}_goal` (target). Visible width = `current / goal * 100`, clamped to 0-100%.

Use case primary: non-profits showing fundraising progress ("$320,000 raised of $500,000 goal"). Secondary: sales targets, signups against a cap.

Renders well in meta_strip (compact horizontal bar) and subtitle (full-width bar). Not appropriate for image_overlay or headline.

### 10.2 Multi-badge — `multi_badge` display type

Value is comma-separated. Renderer splits and pills each segment. Each pill uses the field's base color_intent unless the field declares an `options` map that assigns intent per value.

Use case primary: recipes ("Vegan, GF, Quick"). Secondary: jobs ("Remote, Senior, Engineering"), events ("Free, In-person, English").

Most natural position: meta_strip. Image_overlay accepts at most one pill structurally; multi_badge there would overflow.

### 10.3 Color-semantic badge — `color_intent` attribute on `badge` type

Badge gains a `color_intent` field on its definition: `success` / `warning` / `danger` / `info` / `neutral`. Optional `options` map can override intent per predefined value (the "open now / closed / coming soon" pattern in § 5.5).

Not a separate display type; an attribute of the existing `badge` type. This keeps the type enum at 9 and lets one type cover the breadth of badge use cases.

### 10.4 Composite rating — `rating` display type

Renders as star icons + numeric value + optional review count, e.g. "★★★★☆ 4.8 (1,243)". Storage: two post meta entries — `_pre_field_{key}` (rating value, e.g. 4.8) and `_pre_field_{key}_count` (review count, e.g. 1243).

Field definition optionally specifies `max` (default 5) and whether to show the count (default true if count meta exists).

Use case primary: courses, restaurants, services. Secondary: medical providers, contractors.

## 11. Backward compatibility

- Existing v0.3.x installs continue working unchanged. Pre-v1.1 CPTs have no `pre_post_fields_{slug}` option; the registry returns an empty array; the renderer adds zero output to the hero.
- No data migration on upgrade. Adding post fields is opt-in per CPT.
- The version-upgrade handler bumps `pre_data_version` to `0.4.0` to mark v1.1 schema availability, but the only upgrade action is granting any new capabilities (none currently — `pre_manage_cpts` already covers post field management). The handler is idempotent.
- v0.3.x connector clients (Cowork sessions built against the older preflight contract) continue working against the post field endpoints because the endpoints are additive — no existing endpoint shape changes. Cowork picks up the new endpoints by re-running `/preflight`.

## 12. Open question resolutions (Phase 7 final pass, 2026-05-20)

Resolved during the Phase 7 design pass. Phase 8 implementation follows these decisions; deviations require updating this doc first.

### 12.1 Sitewide currency setting — RESOLVED

**Decision.** Three-tier resolution chain:

1. **Field-level override.** A `currency` display type may declare a `currency_code` attribute on its definition (e.g. `'currency_code' => 'EUR'`). Wins if set.
2. **AISB Business Identity** (when AISB is active). The existing `AISB\Modern\Admin\BusinessIdentity` settings page already collects currency. Read via `get_option('aisb_business_settings')['currency']`. Used as the site default when no field-level override.
3. **PRE fallback option** (`pre_currency`, defaults to `'USD'`). Used when AISB is inactive or its currency setting is empty.

Locale-aware formatting via `number_format_i18n()`. Currency symbol resolved through a small internal map (USD → `$`, EUR → `€`, GBP → `£`, CAD → `CA$`, AUD → `A$`, JPY → `¥`, plus a `pre_currency_symbols` filter for extension). Validator checks the resolved code against ISO 4217 (closed enum, ~30 commonly-used currencies; filter-extensible).

**Rationale.** Reusing AISB's existing Business Identity setting avoids duplicate config surfaces when both plugins are active. The PRE fallback ensures standalone operation. Field-level override accommodates multi-currency directory sites (e.g., international job boards showing salaries in the listing's posted currency).

### 12.2 Date format default — RESOLVED

**Decision.** Absolute format by default (e.g., `May 20, 2026`). Per-field `date_format` attribute supports three modes:

- `absolute` (default) — rendered via WP's `date_i18n()` using the field-defined or sitewide format string.
- `relative` — rendered as human-readable elapsed time (e.g., `2 days ago`, `3 weeks ago`) via WP's `human_time_diff()`.
- `custom` — caller supplies a strftime-style format string in a `date_format_string` attribute (e.g., `'F j'` for `May 20`).

Sitewide format default falls back to WP core's `date_format` option (`get_option('date_format')`), matching how WP renders dates everywhere else.

**Rationale.** Absolute is the safer default because relative dates can be misleading in card contexts (a 2-year-old listing should not say "2 years ago" prominently — the user needs to see the actual date). Relative is appropriate for "Posted X days ago" footers but bad for "Listed on: May 20, 2026" headlines. Per-field override lets the CPT designer pick the right one per semantic role.

### 12.3 Icon library reuse — CONFIRMED

**Decision.** Reuse `PRE_Icon_Library` unchanged. The existing 53-icon library across 13 categories already covers card use cases:

- Real estate: bed, bath, ruler, ruler-2, home, key, building
- Calendar / time: calendar, calendar-event, clock, hourglass
- Location / contact: map-pin, phone, mail, world, link
- Commerce: tag, currency-dollar, shopping-cart, package
- People: user, users, briefcase, school, certificate
- Food: chef-hat, cup, salt, leaf
- Medical: stethoscope, pill, first-aid-kit, heart-rate-monitor
- Etc.

Niche per-vertical additions go through the existing `pre_icon_library` filter (used by site-specific themes or extension plugins). No changes to `PRE_Icon_Library` for v1.1.

**Rationale.** The icon set was deliberately expanded during v1.0 Phase 1 specifically to handle the diverse vertical patterns this v1.1 scope serves. Field testing during v0.3 confirmed coverage. Adding the same library to a second field type costs nothing.

### 12.4 Live preview placeholder data — RESOLVED

**Decision.** Hybrid approach: smart defaults per display type, with an optional dropdown to preview against a real post.

**Smart defaults (used when no post is selected):**

| Display type | Placeholder value |
|--------------|-------------------|
| `currency` | `$1,250,000` |
| `number_with_label` | `1,800 sqft` (label from field definition appended) |
| `badge` | First option value if `options` defined; otherwise `Sample badge` |
| `meta_pair` | Icon (from field def) + `3` |
| `date` | `May 20, 2026` |
| `text` | `Sample text` |
| `rating` | `★★★★☆ 4.8 (1,243)` |
| `progress` | `65% of $500,000` |
| `multi_badge` | `Vegan · GF · Quick` |

**Dropdown:** A "Preview with: [Smart defaults | existing post titles…]" select in the live preview pane lets the user switch to any existing post of the CPT being edited. When selected, the preview re-renders with that post's actual field values (rendering through `PRE_Card_Renderer` against the post_id, same path the frontend uses). When set back to "Smart defaults," the smart values return.

**Rationale.** Smart defaults serve the empty-CPT case (first-time setup, before any posts exist). Real-post preview serves the iteration case (an existing CPT with posts, where the user wants to see exactly how their content lands). Both modes share the same renderer code path, so the preview is faithful to what the frontend will produce.

### 12.5 Per-CPT field count cap — RESOLVED

**Decision.** Soft warning at 8 fields, hard error at 12. Filterable via `pre_max_post_fields_per_cpt` (default 12). Validator enforces the hard cap; admin UI shows the soft warning.

```php
const SOFT_FIELD_COUNT_WARNING = 8;
const HARD_FIELD_COUNT_LIMIT   = 12;
```

Admin UI behavior:

- ≤8 fields: no warning. Add button visible.
- 9-12 fields: yellow warning banner above the field list: "You have N post fields. Cards display best with 8 or fewer; beyond that, the meta strip may wrap or truncate."
- 12 fields: Add button disabled. Hovering shows tooltip: "Maximum 12 post fields per CPT. Remove an unused field to add a new one."

Connector REST returns HTTP 400 with a clear error code (`max_field_count_exceeded`) when attempting to create the 13th field.

**Rationale.** Eight fields fits comfortably in cards across the five positions (image_overlay, headline, subtitle, meta_strip with up to 3-4 items, footer_meta). Beyond 8, the meta_strip starts wrapping or being truncated on mobile, and visual hierarchy degrades. Twelve is the absolute maximum a card layout can support without overflow on any reasonable viewport. The soft/hard split lets advanced users push past the comfort zone without making the bad-UX state easy to stumble into accidentally. Filter-extensible for sites with genuinely unusual needs.

---

**All five open questions resolved. Phase 7 documentation complete. Phase 8 may begin upon founder approval of this resolution pass.**

## 13. Success criteria for v1.1

A v1.1 ship is gated on ALL of the following:

1. **One real client CPT uses post fields end-to-end.** A real-estate, law-firm, medical, course, or event CPT runs in production with post fields driving card and single-post rendering.
2. **Same field config, two surfaces.** The same field definitions power both the single-post hero AND the AISB PostGrid card on a single client install. Verified visually side-by-side.
3. **Cowork can configure post fields end-to-end.** From `register_post_field` through `set_post_field_values` to verifying rendered output via the connector preview endpoint, no manual admin step required.
4. **No regression to v1.0 behavior.** Existing CPTs without post fields render identically to v0.3.x. The render-cache transient continues working. The connector's existing endpoints continue passing the v0.3 pressure test.
5. **All 9 display types render correctly.** Each display type produces design-coherent output in both context (card + single-post hero) on a representative real-content fixture.
6. **All 5 positions render correctly.** Multiple fields can occupy the same position; ordering is deterministic; CSS layouts hold up under 1-4 fields per position.
7. **Per-post visibility overrides work.** Hiding a field on a single post's card hides it on cards site-wide but leaves the single-post hero unchanged (and vice versa).
8. **Strict validation catches the common error classes.** Invalid display types, invalid positions, invalid color intents, malformed visibility JSON — all rejected at save with clear error messages.
9. **Test coverage > 80%** for the new classes (`PRE_Post_Field_Registry`, `PRE_Card_Renderer`) and the validator extensions.
10. **AISB integration is clean.** AISB's PostGrid section exposes `aisb_postgrid_card_content`; PRE hooks it; no other AISB code path needs to know PRE exists. Verified by greppable check (zero PRE class references in AISB source).
11. **Documentation complete.** This design doc, ROADMAP updates, ARCHITECTURE.md updates noting v1.1 scope expansion, CLAUDE.md updates removing the "one field type" guardrail from v1+ posture, INTEGRATION_PROMPTLESS.md updates documenting the new filter contracts.

## 14. Phased build plan

Six phases in v1.1, mirroring the v1.0 phase structure. Detailed in `docs/ROADMAP.md`:

| Phase | Title | Hours est | Output |
|---|---|---|---|
| 7 | v1.1 planning + design contract | 6 | This doc + ROADMAP updates + CLAUDE.md updates |
| 8 | Post field data layer | 12 | `PRE_Post_Field_Registry`, validator extensions, post-data read/write for field values + visibility |
| 9 | Card renderer + position × display-type CSS | 14 | `PRE_Card_Renderer`, 9 display types × 5 positions × 2 contexts CSS, integration into existing single-post template |
| 10 | Admin UI: Post Fields tab + meta box | 12 | New CPT edit-screen tab, field editor, live preview, per-post meta box updates |
| 11 | Connector REST + MCP tools | 10 | All endpoints under `/cpts/{slug}/post-fields/*`, MCP tool surface, preflight extensions |
| 12 | AISB PostGrid + theme archive integration | 8 | `aisb_postgrid_card_content` filter (added to AISB), parallel `promptless_archive_card_content` filter (added to theme), PRE hooks both, side-by-side verification |
| **Total** | | **~62 hours** | |

Phase 13 (full schema.org integration per display-type) is deferred to v1.2 — substantial enough to deserve its own dedicated phase rather than being half-built in v1.1.

---

**End of design contract.** Phase 7 deliverable. Phase 8 begins after founder approval of this doc and the linked ROADMAP updates.
