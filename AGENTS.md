# Post Runtime Engine (Promptless CPT Pages) — AI Reference

> **⚠️ Naming migration (doc corrected 2026-07-04 — trust THIS doc, not older planning docs):**
> The plugin was rebranded **"Promptless CPT Pages"** and the entire PHP surface renamed from the planning-era `PRE_*`/`pre_*` names. There are no `PRE_*` classes and no `pre()` accessor in the shipped code. Actual names:
>
> | Surface | Actual name |
> |---|---|
> | Plugin header / text domain | `Promptless CPT Pages` / `promptless-cpt-pages` |
> | Class prefix | `PCPTPages_*` (files remain `class-pre-*.php`) |
> | Main class / accessor | `Promptless_CPT_Pages`, `pcptpages()` (`class_alias` keeps `Post_Runtime_Engine` working) |
> | Constants | `PCPTPages_VERSION` (0.6.6), `PCPTPages_DATA_VERSION` (0.6.0) |
> | Actions/filters | `pcptpages_*` (e.g. `pcptpages_cpt_registered`, `pcptpages_grouping_defined`, `pcptpages_render_cache_enabled`) |
> | Options | `pcptpages_*` (`pcptpages_cpts`, `pcptpages_groupings_{cpt}`, `pcptpages_post_fields_{cpt}`, `pcptpages_connector_enabled`) |
> | Post meta | `_pcptpages_*` (`_pcptpages_groupings`, `_pcptpages_field_{key}`, `_pcptpages_field_visibility`) |
> | Capability | `pcptpages_manage_cpts` (site config); per-post writes use `edit_post` |
> | REST namespace | `post-runtime/v1/connector/` (unchanged) |
> | MCP tool prefix | `postruntime_*` (29 tools in `includes/Connector/assets/post-runtime-connector.js`) |
> | Release ZIP | `build/promptless-cpt-pages.zip` (root folder `promptless-cpt-pages/`) |
>
> The **plugin root** (`post-runtime-engine/includes/…`) is the live source tree. `build/` contains only build output — never edit there.

**Status (as of 2026-07-22, version 0.6.6):** Phases 1–6 (v1.0 scope) shipped, **plus v1.1 post fields and the v1.2 events/filters layer — all implemented, not planned**. Feature surface: data layer, admin UI for CPT + grouping + post-field management, frontend rendering with all five layout variants (the fifth, `gallery`, added at data-version 0.6.0 — photo grid + accessible lightbox with a definition-level `gallery_image_aspect`; contract: `docs/GALLERY_VARIANT_DESIGN.md`) and **four** source modes (manual, child_posts, taxonomy_match, meta_match — the 4th added at data-version 0.3.0), template router for registered CPT singles, connector REST API (18 route registrations / 24+ method-level endpoints), and a 29-tool MCP surface for Cowork. Programmatic access via `pcptpages()->cpts`, `->groupings`, `->post_fields`, `->post_data`. v1.0 ship readiness still pending unit-test coverage (target >80%, currently smoke-level — see `POST_RUNTIME_AUDIT.md`).

**v1.1 post fields (SHIPPED):** second field type — scalar **post fields** with a closed enum of 9 display types (currency, number_with_label, badge, meta_pair, date, text, rating, progress, multi_badge) and 5 positions symmetric across single-post hero and card contexts (image_overlay, headline, subtitle, meta_strip, footer_meta, plus `hidden`). Implemented in `PCPTPages_Post_Field_Registry`, `PCPTPages_Card_Renderer`, `PCPTPages_Meta_Box_Post_Fields`, with full REST/MCP CRUD + reorder + per-post values/visibility. Design contract: **[`docs/POST_FIELDS_V1_1_DESIGN.md`](docs/POST_FIELDS_V1_1_DESIGN.md)**.

**v1.2 layer (SHIPPED, post-dates the phase table below):** events vertical (`PCPTPages_Event_Query`, `PCPTPages_Event_Schema` Schema.org JSON-LD, semantic date roles with `__sort`/`__utc` companion meta), schema-driven filters (`PCPTPages_Filter_Descriptors`, `filterable`/`sortable`/`filter_widget` field attributes, `aisb_postfilter_query_args` integration), AISB PostGrid / theme-archive card hooks (`PCPTPages_Card_Filter_Hooks` listening to `promptless_archive_card_section` + `aisb_postgrid_card_section`), and SEO meta tags (`PCPTPages_Meta_Tags`). Design docs: `docs/EVENTS_VERTICAL_DESIGN.md`, `docs/POSTGRID_PREVIEW_PARITY.md`.

> **💼 Licensing model (clarified 2026-05-10):** This plugin is **FREE**. No Freemius, no premium tier, no license gates anywhere in the codebase. Only Promptless WP uses Freemius. PRE, FRE, and FlowMint are all free and exist to add value to Promptless rather than compete as standalone commercial products. Earlier planning docs (`docs/ARCHITECTURE.md`, `docs/ROADMAP.md`) contain stale "Premium-tier feature" / "Freemius-ready" language from when the model was undecided — **ignore those references in favor of this statement**. Connector endpoints are gated only by WP user capability (`manage_options` or finer-grained PRE caps) plus the per-site connector enable toggle. Do NOT add license checks when extending features.

A WordPress plugin that renders custom-post-type single pages with structured data display through Promptless WP's design system. Companion plugin alongside Promptless WP (page builder) and Form Runtime Engine (form renderer); does not replace either.

## What this plugin IS

- A renderer for custom-post-type single pages, owned end-to-end by this plugin (CPT registration, custom fields, frontend rendering, admin meta box)
- A constrained-primitive system: one repeatable field type ("grouping") whose items conform to a fixed shape — `image-or-icon, heading, supporting text, optional link` — composed into a small closed set of visual layout variants (see `PCPTPages_Validator::VARIANTS` — five as of 0.6.6, incl. `gallery`)
- A consumer of the `--aisb-*` design tokens emitted by Promptless WP, mirroring how Form Runtime Engine consumes them today
- A connector + MCP surface so Codex Cowork can register CPTs, define groupings, populate per-post values, and choose layout variants
- Designed for greenfield CPT-driven sites first (real estate, jobs, events, courses, professional services, agency-built directories)

## What this plugin IS NOT

- A page builder (Promptless WP does that)
- A form renderer (Form Runtime Engine does that)
- An archive / search engine (the default theme archive plus Promptless's PostGrid section cover archive rendering; v1.2 added schema-driven *filter descriptors* that feed PostGrid filtering, but PRE still does not render archives itself)
- A general-purpose custom-fields plugin (does not compete with ACF, MetaBox, Pods, etc.)
- A binding layer over Promptless's existing section catalog (an earlier proposal that was set aside in favor of this constrained-primitive approach)
- A migration target for existing ACF / MetaBox sites in v1.0 (deferred — v1.0 owns the field model end-to-end)
- Required for Promptless WP to function. Promptless WP works fully without this plugin; this plugin works at minimum-viable level without Promptless WP

## System requirements

| Requirement | Version | Notes |
|-------------|---------|-------|
| WordPress | 5.0+ | Block editor recommended for main content authoring |
| PHP | 7.4+ | Type hints, arrow functions |
| MySQL | 5.6+ / MariaDB 10.0+ | InnoDB recommended (no custom tables in v1.0; only post meta and `wp_options`) |
| Promptless WP | 1.3.0+ (recommended, not required) | When active, design tokens are inherited automatically. Without it, documented fallbacks apply. |

## Documentation map

The full docs live in `docs/`. Planning-phase doc set:

| Topic | File |
|-------|------|
| **Phased build plan, scope, success criteria** | `docs/ROADMAP.md` |
| **Technical design contract (v1.0)** | `docs/ARCHITECTURE.md` |
| **Post fields design contract (v1.1)** | `docs/POST_FIELDS_V1_1_DESIGN.md` |
| **Design-token contract (consumer side)** | `docs/AISB_TOKEN_CONTRACT.md` |
| **Boundary with Promptless WP** | `docs/INTEGRATION_PROMPTLESS.md` |

Phase 1 will add: `docs/CONNECTOR_API.md`, `docs/REFERENCE_PATTERNS.md` (example CPT setups for real estate / law firm / events / courses), `docs/SETUP_*.md` for any per-feature configuration, `docs/TROUBLESHOOTING.md`.

## Quick architectural summary

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Promptless WP (separate plugin)                     │
│                                                                           │
│  Emits --aisb-* CSS custom properties via Global Settings                 │
└────────────────────────────────────────┬──────────────────────────────────┘
                                         │ CSS-only contract
                                         ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                      Post Runtime Engine (this plugin)                    │
│                                                                           │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ CPT Registry                                                         │  │
│  │   Promptless-owned CPT registration (post type slug, labels,         │  │
│  │   capability mapping, archive on/off, REST exposure)                 │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Grouping Definitions                                                 │  │
│  │   Per-CPT list of accepted "groupings": each grouping has a key,     │  │
│  │   a label, a default layout variant, and an items-array shape:       │  │
│  │   { image-or-icon, heading, supporting_text, link }                  │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Per-post Values                                                      │  │
│  │   For each post in a registered CPT: filled-in grouping items,       │  │
│  │   plus a layout-position assignment (above_main / below_main /       │  │
│  │   sidebar) for each grouping                                         │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│                                  ▼                                        │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Template Router (template_include)                                   │  │
│  │   On registered CPT singles, renders:                                │  │
│  │     hero (post title + featured image, automatic)                    │  │
│  │     groupings positioned above_main                                  │  │
│  │     main content (default WP editor output via the_content())        │  │
│  │     groupings positioned below_main                                  │  │
│  │     sidebar groupings (if any) — pinned on desktop                   │  │
│  │     footer (related posts via taxonomy match)                        │  │
│  └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

## Key architectural decisions (locked during planning)

These were settled in conversation with the founder before any code is written. Disagreements are resolved by editing the doc that captures the decision (`docs/ARCHITECTURE.md` is canonical), not by writing different code.

1. **Standalone plugin, not bundled into Promptless WP.** Same packaging shape as Form Runtime Engine. The coupling to Promptless is at the CSS-token level only, no PHP-level dependency. This plugin works at minimum-viable level on any theme; when Promptless WP is active, the design tokens flow through automatically.

2. **One repeatable field type ("grouping"), one item shape.** No dates, numbers, taxonomy fields, nested groups, conditional logic, or relationship fields in v1.0. Just `{image-or-icon, heading, supporting_text, optional link}`. The constraint is the feature. Each grouping supports per-post variant override (5 variants: compact-grid, card-grid, featured-card, horizontal-row, gallery — gallery reinterprets items as image+optional-caption, rejects icon_id, and validation is effective-variant-aware) and four source modes (manual, child_posts, taxonomy_match, meta_match) for auto-population. (v1.1 later added the second, deliberately-scoped scalar "post fields" type — see status header.)

3. **Promptless owns CPT registration and field model.** No ACF / MetaBox / Pods integration in v1.0. Greenfield CPT use cases only. Migration from existing ACF sites is a v1.1 problem.

4. **Default WP editor handles main content.** No new editing surface for prose. Users type in Gutenberg or the classic editor; theme styles the output via existing Promptless content tokens. Groupings flank that main content area.

5. **Three layout positions, not free-form layouts.** `above_main`, `below_main`, `sidebar`. Not full-width-only, not multi-column main, not magazine-style. Three positions cover the use cases this plugin is built for; users wanting more flexibility use Promptless WP for hand-authored pages instead.

6. **3–4 layout variants per grouping, fixed.** Compact grid (icon + label, 2–4 cols), card grid (icon + heading + supporting text, 2–3 cols), featured card (image + heading + supporting text + link, full width or sidebar), horizontal row (icon + value/label inline). Variants are CSS treatments of the same data, not separate components.

7. **Connector + MCP from day one.** Codex Cowork can register CPTs, define groupings, populate values, and choose variants without an admin UI. The admin meta box exists for human-driven editing; the connector exists for AI-driven setup. Both write to the same data model.

8. **No custom DB tables.** All data in `wp_options` (CPT registry + grouping definitions) and post meta (per-post values + layout positions). Same constraint Promptless WP honors — keeps the plugin portable across managed hosts.

9. **Free plugin.** No Freemius, no premium tier, no license gates. Only Promptless WP is sold; PRE, FRE, and FlowMint are all free and exist to add value to the Promptless ecosystem. The connector and every endpoint are gated by WP user capability + the per-site connector enable toggle — never by license state. (Clarified 2026-05-10. Earlier planning docs treated this as TBD or premium; that's superseded.)

10. **One-way CSS dependency on Promptless WP.** This plugin reads tokens documented in `docs/AISB_TOKEN_CONTRACT.md`. Promptless WP has zero knowledge of this plugin's existence. Same pattern as FRE.

## Naming conventions (as shipped — supersedes the planning-era `PRE_*`/`pre_*` scheme)

- **Class prefix:** `PCPTPages_*` (PHP file names keep the historical `class-pre-*.php` pattern; the classes inside are `PCPTPages_*`)
- **Plugin header / text domain:** `Promptless CPT Pages` / `promptless-cpt-pages` (repo/folder name remains `post-runtime-engine`)
- **REST namespace:** `post-runtime/v1/connector/`
- **Action prefix:** `pcptpages_*` (e.g., `pcptpages_cpt_registered`, `pcptpages_grouping_defined`, `pcptpages_post_field_defined`)
- **Filter prefix:** `pcptpages_*` (e.g., `pcptpages_cpt_register_args`, `pcptpages_render_cache_enabled`)
- **Option prefix:** `pcptpages_*`
- **Post meta prefix:** `_pcptpages_*`
- **MCP tool prefix:** `postruntime_*` (resolved; 29 tools mapping to the connector REST routes)
- **Capability:** `pcptpages_manage_cpts` for site configuration; per-post value writes gate on `edit_post`

The folder name `post-runtime-engine` was retained for repo continuity even though the shipped brand is Promptless CPT Pages. Do not "fix" the folder/file names to match the class prefix — the mismatch is deliberate and renaming would break update paths.

## Phased build status

| Phase | Description | Hours est | Status |
|---|---|---|---|
| 0 | Planning + design docs | 6 | ✅ Complete |
| 1 | CPT registry + grouping field type + admin meta box (with variant override) | ~16 | ✅ Complete (v0.1) |
| 2 | Frontend rendering + layout variants + source modes (manual / child_posts / taxonomy_match) | ~14 | ✅ Complete (v0.2) |
| 3 | Connector REST + MCP tools | ~12 | ✅ Complete (v0.3) |
| 4 | Promptless theme integration + design-token consumer CSS | ~6 | ✅ Complete |
| 5 | Reference CPTs + documentation | ~4 | ✅ Complete |
| 6 | Production polish + first client acceptance | ~8 | ✅ Complete |
| 7–12 | v1.1 post fields (registry, meta box, card renderer, REST/MCP CRUD) | — | ✅ Complete |
| v1.2 | Events vertical + schema-driven filters + PostGrid/theme card hooks + SEO meta tags | — | ✅ Complete (v0.6.x) |

See `docs/ROADMAP.md` for full phase detail and per-phase changelog. Current shipped version: **v0.6.6** (`PCPTPages_VERSION`), data-schema version 0.6.0 (`PCPTPages_DATA_VERSION`). **Outstanding work before v1.0 ship** is documented in `POST_RUNTIME_AUDIT.md` (test coverage scaffold, doc-spec drift on connector preflight fields, optional production-polish items).

## Releasing New Versions

**Canonical release procedure: [`RELEASE.md`](RELEASE.md)** at the plugin root. That document is the single source of truth — version-stamp locations, pre-release checklist, build commands, tag pattern, `gh release create` invocation, post-release verification.

**One-line summary:** update version stamps in `post-runtime-engine.php` (header + `PCPTPages_VERSION` constant), `readme.txt` (Stable tag + Changelog + Upgrade Notice), and `CHANGELOG.md` → commit "Release X.Y.Z" → push → `./bin/build-release.sh` → `wp plugin check build/promptless-cpt-pages` → `git tag vX.Y.Z && git push --tags` → `gh release create vX.Y.Z --title "vX.Y.Z" --generate-notes` → publish `build/promptless-cpt-pages/` to WordPress.org SVN trunk + `tags/X.Y.Z` (see RELEASE.md for exact rsync/svn commands). **Distribution is WordPress.org-exclusive — do NOT attach ZIP assets to GitHub releases** (the GitHub updater was retired per WP.org guideline #8; GitHub releases are fine for marking versions, just don't upload the ZIP). The build script verifies the ZIP's internal structure and aborts on a flattened/hand-assembled archive — never package a release by hand.

## Post-launch maintenance constraints (formerly "guardrails")

These were originally pre-launch guardrails to prevent design drift during the build phases. They remain in force as **post-launch constraints** — any v1.1+ change that would violate one of these requires an explicit architectural conversation, not a casual commit. The constraints are what make this plugin a focused tool rather than a kitchen-sink CPT framework.

- **Resist scope creep on field types.** v1.0 had ONE field type ("grouping"). v1.1 adds a SECOND, deliberately-scoped field type ("post fields" — scalar, with a closed enum of 9 display types and 5 positions). v1.1's design contract is locked in `docs/POST_FIELDS_V1_1_DESIGN.md`; do not extend the post-field enums (display types, positions, color intents) without updating that contract first. Any THIRD field type (relationship fields, nested repeaters, computed/virtual fields, filter-based custom display types) remains a v1.2+ conversation triggered by real client demand — not by AI suggestion. The discipline applies as the plugin matures: each new field-type expansion gets its own design contract and explicit architectural decision, not a casual addition under "while we're in here."
- **Three single-post grouping positions; five post-field positions.** Groupings hardcoded in `PCPTPages_Validator::POSITIONS` (`above_main`, `below_main`, `sidebar`). Post fields hardcoded in `PCPTPages_Validator::FIELD_POSITIONS` (`image_overlay`, `headline`, `subtitle`, `meta_strip`, `footer_meta` plus `hidden` opt-out — symmetric across single-post hero and card contexts). Resist adding a sixth position to either enum without an architectural conversation about whether the existing cap was the right one. (`PCPTPages_Validator` is the single source of truth for every closed enum: `VARIANTS`, `POSITIONS`, `SOURCE_MODES` — now four incl. `meta_match` — `DISPLAY_TYPES`, `FIELD_POSITIONS`, `COLOR_INTENTS`, `SEMANTIC_ROLES`, `FILTER_WIDGETS`.)
- **Do not depend on Promptless WP at the PHP level.** Reading `--aisb-*` CSS tokens with documented fallbacks is the only allowed coupling. No `class_exists('AISB_Plugin')` checks gating functionality. No calls into Promptless's `SectionRenderer` or any other Promptless class. The plugin must continue to work at minimum-viable level when Promptless is inactive.
- **Do not integrate with ACF, MetaBox, or Pods in v1.0 or v1.x.** This plugin owns the field model end-to-end. Migration tooling for existing ACF sites is a separate v1.2+ concern when there's real demand.
- **Honor the design-token contract.** Every `--aisb-*` reference in this plugin's CSS must have a documented fallback in `docs/AISB_TOKEN_CONTRACT.md`. New token consumption requires updating that doc first. (See FRE's `bin/build-release.sh` AISB-fallback check for an automated guard pattern worth copying.)
- **The default WP editor handles main content.** Do not build a new editing surface for prose. The plugin's editing UI is the meta box for groupings (v1.0) and the Post Fields tab + per-post field inputs (v1.1).
- **No custom DB tables.** Use `wp_options` and post meta. If a feature seems to require a custom table, that feature is probably out of scope. Keeps the plugin portable across managed hosts.
- **Test coverage is required for v1 ship.** Each new field-type behavior, layout variant, and renderer pass ships with unit tests. Coverage target: >80%. **Currently: ~0% (smoke tests only).** This is the primary blocker before v1.0 — see `POST_RUNTIME_AUDIT.md` Critical #1 for the recommended scaffold.

---

**Plugin status:** v0.6.4 shipped (brand: Promptless CPT Pages); v1.0 phases 1–6, v1.1 post fields, and the v1.2 events/filters layer all complete; production-ready for customer use; outstanding work documented in `POST_RUNTIME_AUDIT.md` and `docs/ROADMAP.md`. Primary remaining gap: automated test coverage.
