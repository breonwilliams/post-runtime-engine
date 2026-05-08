# Post Runtime Engine — Architecture

This is the technical design contract for the plugin. Phase 1+ code MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

## High-level shape

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Promptless WP (separate plugin) — emits --aisb-* CSS custom properties    │
└────────────────────────────────────────┬──────────────────────────────────┘
                                         │ CSS-only contract (no PHP coupling)
                                         ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                    Post Runtime Engine (this plugin)                      │
│                                                                           │
│  ┌─────────────────────────┐    ┌─────────────────────────────────────┐  │
│  │ CPT Registry            │    │ Grouping Definitions                 │  │
│  │  wp_options:            │    │  wp_options:                         │  │
│  │  pre_cpts               │    │  pre_groupings_{cpt_slug}            │  │
│  │                         │    │                                       │  │
│  │  - slug                 │    │  - key                                │  │
│  │  - label_singular       │    │  - label                              │  │
│  │  - label_plural         │    │  - default_variant                    │  │
│  │  - supports             │    │  - default_position                   │  │
│  │  - public               │    │  - icon_or_image_required             │  │
│  │  - has_archive          │    │  - heading_required                   │  │
│  │  - rest_base            │    │  - supporting_text_required           │  │
│  │  - capability_type      │    │  - link_required                      │  │
│  │  - registered_at        │    │  - max_items                          │  │
│  └─────────────────────────┘    └─────────────────────────────────────┘  │
│                │                                  │                       │
│                │ Phase 1 reads                    │ Phase 1 reads          │
│                ▼                                  ▼                       │
│  ┌─────────────────────────────────────────────────────────────────────┐ │
│  │ register_post_type() called on init for each CPT in the registry     │ │
│  └─────────────────────────────────────────────────────────────────────┘ │
│                                  │                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐ │
│  │ Per-Post Values (post meta)                                          │ │
│  │   _pre_groupings: array of { grouping_key, items[], position }       │ │
│  │   each item: { image_id|icon_id, heading, supporting_text, link }    │ │
│  └─────────────────────────────────────────────────────────────────────┘ │
│                                  │                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐ │
│  │ Template Router (template_include filter)                            │ │
│  │   if ( is_singular && cpt is registered ) → use plugin's template    │ │
│  │     1. hero (post_title + featured_image, automatic)                 │ │
│  │     2. groupings with position=above_main                            │ │
│  │     3. main content (the_content() — default WP editor)              │ │
│  │     4. groupings with position=below_main                            │ │
│  │     5. sidebar groupings (column on desktop, stacked on mobile)      │ │
│  │     6. related-posts footer (taxonomy match)                         │ │
│  └─────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

## Plugin file structure (final, post-Phase-1)

```
post-runtime-engine/
  post-runtime-engine.php             # Main plugin file (bootstrap, autoloader, deps check)
  CLAUDE.md                           # AI / engineer reference
  README.md
  CHANGELOG.md
  uninstall.php                       # On plugin delete: clean options (preserve post meta by default)
  composer.json                       # If vendor deps emerge; v1 may not need any
  phpunit.xml                         # Test config
  docs/                               # All design / reference docs
  includes/
    class-pre-autoloader.php          # PSR-like autoloader for PRE_* classes
    Core/
      class-pre-cpt-registry.php      # Stores and reads CPT definitions; calls register_post_type()
      class-pre-grouping-registry.php # Stores and reads grouping definitions per CPT
      class-pre-post-data.php         # Read/write per-post grouping values + position overrides
      class-pre-validator.php         # Strict validation: shape, variants, positions
      class-pre-capabilities.php      # Capability mapping for registered CPTs
      class-pre-icon-library.php      # Available icons (Dashicons + curated set)
    Frontend/
      class-pre-template-router.php   # template_include filter
      class-pre-renderer.php          # Single-post template orchestrator
      class-pre-grouping-renderer.php # Per-variant grouping HTML output
      class-pre-related-posts.php     # Related-posts footer logic
    Admin/
      class-pre-admin.php             # Admin menu + page registration
      class-pre-admin-cpts.php        # CPT list + registration form
      class-pre-admin-groupings.php   # Per-CPT grouping definition UI
      class-pre-meta-box.php          # Post-edit-screen meta box for grouping values
      class-pre-settings.php          # Plugin-level settings page
    Connector/
      class-pre-connector-api.php     # REST route registration
      class-pre-connector-auth.php    # WP App Password + capability + premium gating + rate limiting
      class-pre-connector-cpts.php    # /cpts CRUD endpoints
      class-pre-connector-groupings.php
      class-pre-connector-posts.php
      class-pre-connector-preview.php
      class-pre-connector-preflight.php
    Mcp/
      class-pre-mcp-tools.php         # PHP-side MCP tool definitions
    Updates/
      class-pre-github-updater.php    # If self-hosted; otherwise Freemius handles it
  templates/
    single-base.php                   # Base template (wraps hero + body grid + footer)
  assets/
    css/
      frontend.css                    # Base + token consumption
      variants/
        compact-grid.css
        card-grid.css
        featured-card.css
        horizontal-row.css
      admin.css                       # Admin UI styles
    js/
      meta-box.js                     # Meta box behavior (drag-reorder, image picker, icon picker)
      admin.js                        # Admin UI behavior
  languages/
    post-runtime-engine.pot
  tests/
    Unit/
      ValidatorTest.php
      CPTRegistryTest.php
      GroupingRegistryTest.php
      PostDataTest.php
      RendererTest.php
      ...
    Integration/
      TemplateRoutingTest.php
      ConnectorAPITest.php
      ...
  bin/
    build-release.sh                  # Mirrors form-runtime-engine — produces clean zip
    install-wp-tests.sh               # Sets up WP test harness for integration tests
```

## Database / storage

**Zero custom DB tables in v1.0.** All state lives in `wp_options` and `wp_postmeta`. Same constraint Promptless WP honors — keeps the plugin portable and managed-host-friendly.

### `wp_options`

| Key | Holds |
|-----|-------|
| `pre_cpts` | Array of all registered CPT definitions |
| `pre_groupings_{cpt_slug}` | Array of grouping definitions for one CPT |
| `pre_settings` | Plugin-level settings (default related-posts taxonomy, icon library choice, etc.) |
| `pre_db_version` | For migration tracking |
| `pre_connector_rate_{user}_{endpoint}` | Per-user per-endpoint rate-limit window (transient) |
| `pre_auth_key_hash` | Connector auth bookkeeping |

### `wp_postmeta`

| Key | Holds |
|-----|-------|
| `_pre_groupings` | Per-post grouping values (full structure: `[{grouping_key, items[], position}, ...]`) |
| `_pre_groupings_backup`, `_pre_groupings_backup_time`, `_pre_groupings_backup_user` | Rollback copy created before connector-driven updates |
| `_pre_position_overrides` | Optional: per-grouping position overrides for this specific post |

The `_pre_groupings` shape:

```json
[
  {
    "grouping_key": "quick_specs",
    "position": "above_main",
    "variant_override": null,
    "source": "manual",
    "items": [
      {
        "image_id": null,
        "icon_id": "bed",
        "heading": "4 Bedrooms",
        "supporting_text": null,
        "link": null
      },
      {
        "image_id": null,
        "icon_id": "bathroom",
        "heading": "2 Bathrooms",
        "supporting_text": null,
        "link": null
      },
      {
        "image_id": null,
        "icon_id": "ruler",
        "heading": "1,800 sqft",
        "supporting_text": null,
        "link": null
      }
    ]
  },
  {
    "grouping_key": "amenities",
    "position": "below_main",
    "variant_override": "card-grid",
    "source": "manual",
    "items": [
      {
        "image_id": null,
        "icon_id": "pool",
        "heading": "Private Pool",
        "supporting_text": "Heated, in-ground, with covered patio",
        "link": null
      },
      {
        "image_id": null,
        "icon_id": "garage",
        "heading": "2-car Garage",
        "supporting_text": "Climate-controlled with EV charger",
        "link": null
      }
    ]
  },
  {
    "grouping_key": "sub_practices",
    "position": "above_main",
    "variant_override": null,
    "source": "child_posts",
    "items": []
  },
  {
    "grouping_key": "related_practices",
    "position": "sidebar",
    "variant_override": null,
    "source": {
      "type": "taxonomy_match",
      "taxonomy": "practice_category",
      "limit": 4,
      "exclude_self": true
    },
    "items": []
  }
]
```

Note: every item has all five fields (`image_id`, `icon_id`, `heading`, `supporting_text`, `link`), with unused ones set to `null`. This makes validation and rendering straightforward — no missing-field checks at render time.

The `variant_override` field is optional; when null, the grouping uses the variant declared in its definition. When set to a valid variant key (`compact-grid`, `card-grid`, `featured-card`, `horizontal-row`), the override applies for this specific post. Validators reject unknown variant keys at save time.

The `source` field controls where items come from. Three modes — see "Grouping source modes" below. For non-manual sources, the stored `items` array is empty; the renderer populates items at render time by querying the source.

## The grouping primitive

This is the load-bearing data shape for the entire plugin. Every grouping item conforms to:

```php
[
    'image_id'        => int|null,    // attachment ID; null if using icon or neither
    'icon_id'         => string|null, // icon key from the curated library; null if using image or neither
    'heading'         => string,       // required; plain text
    'supporting_text' => string|null,  // optional; plain text
    'link'            => string|null,  // optional; URL or anchor
]
```

**Constraints enforced by the validator:**

- `image_id` and `icon_id` are mutually exclusive — at most one is set
- `heading` is required and non-empty (per-grouping-definition; some groupings may make it optional in v1.1)
- `link` if set must be a valid URL or an anchor reference (`#anchor-name`)
- Plain-text fields use `sanitize_text_field()` on save and `esc_html()` on output
- Links use `esc_url()` on output and `esc_url_raw()` on save

**No HTML in any field.** This is intentional — keeps validation simple and keeps the rendering predictable. If users need rich text, that goes in the main content area (default WP editor), not in groupings.

## Grouping source modes

A grouping's `items` array can come from one of three sources. The source is declared in the per-post grouping data (`_pre_groupings`) and resolved at render time.

### `manual` (default)

Items are stored explicitly in the post's `_pre_groupings` post meta. The user (or Cowork) populated them through the meta box or connector.

```json
{
  "grouping_key": "quick_specs",
  "source": "manual",
  "items": [ /* explicit array of items */ ]
}
```

This is the default and covers the majority of groupings — anything where the content is unique to the post.

### `child_posts`

Items are auto-populated at render time from the post's child posts (posts whose `post_parent` equals this post's ID). The CPT must be registered with `hierarchical: true` for this mode to work.

```json
{
  "grouping_key": "sub_practices",
  "source": "child_posts",
  "items": []
}
```

The renderer maps each child post into a grouping item:
- `image_id` ← child post's featured image (if no per-post icon override)
- `icon_id` ← child post's `_pre_icon` post meta (if set; falls back to a per-CPT default icon)
- `heading` ← child post's `post_title`
- `supporting_text` ← child post's `post_excerpt` (truncated to ~120 chars)
- `link` ← child post's permalink

Use case: a parent Practice Area post auto-shows its child sub-practices without manually maintaining links. Updating a child's title or adding a new child auto-updates the parent's grouping.

### `taxonomy_match`

Items are auto-populated from posts sharing one or more taxonomy terms with the current post.

```json
{
  "grouping_key": "related_practices",
  "source": {
    "type": "taxonomy_match",
    "taxonomy": "practice_category",
    "limit": 4,
    "exclude_self": true
  },
  "items": []
}
```

The source object accepts:
- `type`: must be `"taxonomy_match"`
- `taxonomy`: the taxonomy slug to match on
- `limit`: max items to return (default 6)
- `exclude_self`: whether to exclude the current post (default true)
- `orderby` / `order`: standard WP_Query args (defaults: `menu_order`, `ASC`)

The renderer maps each matched post into a grouping item using the same field-mapping rules as `child_posts`.

Use case: "Related Practices" sidebar grouping that auto-populates as new practice areas are added that share a category. The author / Cowork doesn't have to manually update related-practice links every time the catalog grows.

### Validation

The validator rejects:
- `source` values other than `"manual"` or a recognized auto-source object
- `child_posts` source on non-hierarchical CPTs
- `taxonomy_match` source referencing an unregistered taxonomy
- Auto-source groupings with non-empty `items` arrays (auto modes ignore stored items; raise an error to catch confused state)
- Manual source with a malformed items array

### Caching

Auto-source groupings cache their resolved items in a transient keyed on `(post_id, grouping_key, source_signature)`. Invalidates on:
- Any post in the queried set being saved or trashed
- The current post being saved
- The grouping definition changing
- Plugin or CPT-definition version bumping

This keeps render performance comparable to manual mode under typical load.

## Layout variants

Four variants in v1.0. Each is a CSS treatment of the same data shape.

### Compact grid

- Used for: amenities lists, quick-spec rows
- Visual: 2–4 columns of `[icon · heading]`, no supporting text shown
- Configurable column count: 2, 3, or 4 (default: 3 desktop / 2 mobile)
- If items have `supporting_text`, it's hidden in this variant

### Card grid

- Used for: highlight cards, feature lists, "what's included"
- Visual: 2–3 columns of cards with `[icon, heading, supporting_text]`
- Configurable column count: 2 or 3 (default: 3 desktop / 1 mobile)
- Cards have surface-color backgrounds and rounded corners (from design tokens)

### Featured card

- Used for: hosted-by / agent / instructor / speaker cards (sidebar or full-width)
- Visual: single card with `[image (left or top), heading, supporting_text, link CTA (right or bottom)]`
- Renders well at sidebar widths AND full-content widths
- Single item per featured-card grouping (validator enforces `max_items: 1` for this variant)

### Horizontal row

- Used for: at-a-glance specs ("12 guests · 4 bedrooms · 8 beds · 2 baths")
- Visual: inline horizontal layout, `[icon · heading]` separated by middots or dividers
- Renders compact even at narrow widths
- No supporting text shown in this variant

**Variant selection:** Per-grouping-definition default sets the variant used by every post in the CPT. Per-post override is supported in v1.0 — the meta box exposes a variant `<select>` for each grouping, and the value is stored in `_pre_groupings[].variant_override`. When `variant_override` is null, the definition default applies. The validator rejects unknown variant keys.

This per-post flexibility is small in code cost (a select element + a renderer fallback) but meaningful in practice: it lets one Practice Area page show "Recent Results" as a card-grid while another shows it as a single featured-card to highlight a marquee win.

## Position model

Three positions in v1.0. Each post can override its grouping definitions' default positions, but cannot invent new positions.

- **`above_main`** — renders between hero and main content. Top of body area.
- **`below_main`** — renders between main content and footer. Bottom of body area.
- **`sidebar`** — renders in the right sidebar column on desktop (above 768px). Stacks below `below_main` on mobile. Pinned to top of viewport on scroll on desktop.

The hero, main content, and footer (related posts) are always full-width and always present.

The sidebar column appears only if at least one grouping is positioned in it. Otherwise the body is single-column full-width.

## Rendering pipeline

For a single post in a registered CPT:

1. `template_include` filter checks: is this CPT registered? If yes, take over rendering.
2. The base template (`templates/single-base.php`) wraps the page structure.
3. Hero block: `post_title` + `the_post_thumbnail()`. Heading level is `<h1>`.
4. Read `_pre_groupings` post meta. For each grouping:
   - Resolve the grouping definition (from `pre_groupings_{cpt_slug}` option) to get the default variant and required field shape
   - Determine the effective variant: `variant_override` from post meta if set, otherwise the definition's default
   - Resolve the items array based on `source`:
     - `manual` — use the stored items directly
     - `child_posts` — query `WP_Query` for child posts and map to items
     - `taxonomy_match` — query `WP_Query` for posts sharing taxonomy terms and map to items
   - Validate the resolved items (catches data drift even for auto-sourced items)
   - Group by position (`above_main`, `below_main`, `sidebar`)
5. Render `above_main` groupings (in the order they appear in post meta).
6. Render `the_content()` — default WP editor output. WordPress's existing filters (autop, blocks, shortcodes) apply.
7. Render `below_main` groupings.
8. If any grouping is in `sidebar`, render the sidebar column with those groupings.
9. Render the related-posts footer using a taxonomy match (configurable per CPT; default: pick the first registered taxonomy).
10. Each grouping is rendered by `class-pre-grouping-renderer.php` based on its effective variant.

## Hero customization (or lack thereof) in v1.0

The hero is intentionally simple in v1.0:

- Always renders `post_title` as `<h1>`
- Renders `the_post_thumbnail('large')` if a featured image is set
- Renders `the_excerpt()` if the post has an excerpt set (optional, theme-styled)

No subtitle field, no inline grouping in the hero, no custom CTAs in the hero. Users wanting more flexibility can build a hand-authored Promptless page for that specific post (Promptless is still activatable per-post on registered CPTs if Promptless WP is installed).

This may change in v1.1 based on what real-estate / law-firm / events patterns demand.

## Theme override

A theme can supply `post-runtime-engine-{cpt_slug}-single.php` and it wins via `locate_template()`. The plugin's template is always the fallback.

The theme override receives access to the same data via documented helper functions:

- `pre_get_groupings( $post_id, $position = null )` — array of resolved groupings
- `pre_render_grouping( $grouping )` — full HTML for one grouping
- `pre_get_hero_data( $post_id )` — hero data structure
- `pre_get_related_posts( $post_id, $args = [] )` — array of related WP_Post

This lets advanced theme developers customize the wrapper without giving up grouping rendering.

## Connector + MCP boundary

See `docs/CONNECTOR_API.md` (TBD during Phase 3) for the full REST + MCP reference. Key principles:

1. **Same auth pattern as Promptless WP and FRE.** WordPress Application Passwords. `manage_options` for CPT registration; `edit_posts` (or stricter per-CPT) for per-post grouping editing.
2. **Premium-only.** Following Promptless's pattern. Free tier (if any) is the renderer + admin UI; the connector is premium.
3. **Per-user per-endpoint rate limiting.** Transients keyed `pre_connector_rate_{user_id}_{endpoint}`.
4. **Strict validation at every write.** Every connector-driven write goes through the same validator the meta box uses. No bypass.
5. **Per-update versioning + rollback.** Mirroring FRE's `connector_version` field and the FlowMint Workflows audit trail. Every connector-driven update creates a backup of the previous state.

## Naming conventions

| Resource | Pattern |
|---|---|
| Class prefix | `PRE_*` |
| Plugin slug / text domain | `post-runtime-engine` |
| REST namespace | `post-runtime/v1/connector/` |
| Action prefix | `pre_*` |
| Filter prefix | `pre_*` |
| Option prefix | `pre_*` |
| Post meta prefix | `_pre_*` |
| MCP tool prefix | TBD during Phase 3 |
| CSS class prefix | `.pre-*` for plugin-internal classes |
| CSS variable prefix | Inherits `--aisb-*` (no plugin-specific tokens; uses `--pre-*` only for internal computed values that don't need to be public) |

## Reusing visual patterns from Promptless WP

When Promptless WP ships a visual treatment (button styles, card hover effects, accordion behavior, tab styling, modal presentation, etc.) that this plugin's groupings would benefit from, the rule is:

1. **Match the design exactly using the same `--aisb-*` tokens.** The token contract handles colors, spacing, typography, border radius, shadow values, transitions automatically. A button rendered in a PRE grouping references the same `--aisb-button-primary-bg` and `--aisb-section-radius-button` that Promptless's buttons reference, so they look identical without any cross-plugin coupling.

2. **Duplicate the structural CSS rules; do not reference Promptless's CSS files at runtime.** If accordion behavior is added in v1.1, copy Promptless's accordion selectors and rules into this plugin's stylesheet (using the same class names so theme-level CSS overrides target both consistently). The duplication is small because both stylesheets resolve to the same token values; only the structural rules duplicate, not the styling decisions.

3. **JavaScript behavior, when needed, is also duplicated, not shared.** If a v1.1 accordion needs JS for collapse/expand, ship a minimal local JS file. Don't depend on Promptless's frontend JS being loaded.

This rule preserves the no-PHP-coupling, no-runtime-dependency architecture while still delivering pixel-level visual consistency with Promptless surfaces. The cost is a few hundred bytes of duplicated CSS per shared pattern; the benefit is independence and robustness.

For v1.0, no shared visual patterns from Promptless need replication. The four layout variants for groupings are simple grids and cards using token-driven styling. Accordion / tabs / modal patterns from Promptless become candidates for v1.1+ if specific groupings need them.

## Coexistence with Promptless WP and FRE

This plugin coexists cleanly with both:

- **Promptless WP** registers `_aisb_sections` post meta and adds a meta box to `post` and `page` types. This plugin uses different post meta keys (`_pre_*`) and registers its own CPTs. Zero conflict on data. Both plugins read the same `--aisb-*` design tokens.
- **Form Runtime Engine** registers form-specific post meta and renders forms via shortcode. This plugin's groupings can include FRE form shortcodes in their `link` field if needed (e.g., a CTA grouping that links to `#contact-form` where an FRE form is embedded). Zero conflict.

If Promptless WP is also activated on a registered CPT (via Promptless's "enable on this post type" setting), Promptless's display takes precedence on a per-post basis (the post's `_aisb_enabled` flag wins). This means a single CPT can mix: most posts use Post Runtime Engine's template, while a flagship listing uses a hand-built Promptless page. Both are valid and both render correctly.

## Hard architectural decisions (locked)

These are documented in `CLAUDE.md` and worth restating here:

1. Async / synchronous: rendering is synchronous (no Action Scheduler).
2. Workflow-as-data: groupings live in DB as structured arrays, not as code.
3. Per-client WP install: no multi-tenancy.
4. MCP-first interface: connector + MCP from day one; admin UI is a parallel surface, not the only one.
5. Field types as code, grouping definitions as data: adding a new field type requires a code change. Defining a new grouping with the existing field type is data-only.
6. Standalone plugin, not bundled into Promptless WP: same packaging shape as FRE.
7. CSS-token coupling only: no PHP-level dependency on Promptless WP.
8. Greenfield-first: no ACF / MetaBox integration in v1.0.
9. WP editor for main content: no new prose-editing surface.
10. Three positions, fixed: not free-form layouts.

## Critical guardrails for AI sessions working on this plugin

- **Plugin is in PLANNING PHASE.** Do not write Phase 1+ code without explicit confirmation from Breon.
- **Resist scope creep on field types.** v1 has ONE field type. The temptation to add a date picker, number field, taxonomy selector, or nested repeater will be intense. Don't. Adding a second field type is a v1.1 conversation triggered by real client demand.
- **Do not depend on Promptless WP at the PHP level.** Reading `--aisb-*` CSS tokens with documented fallbacks is the only allowed coupling.
- **Do not integrate with ACF, MetaBox, or Pods in v1.0.** This plugin owns the field model end-to-end.
- **Honor the design-token contract.** Every `--aisb-*` reference in this plugin's CSS must have a documented fallback in `docs/AISB_TOKEN_CONTRACT.md`.
- **The default WP editor handles main content.** Do not build a new editing surface for prose.
- **Three layout positions only.** Resist adding a fourth without an architectural conversation.
- **No custom DB tables in v1.0.** Use `wp_options` and post meta.
- **Test coverage is required for v1 ship.** Each new field-type behavior, layout variant, and renderer pass ships with unit tests. Coverage target: >80%.
