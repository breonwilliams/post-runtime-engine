# Post Runtime Engine — AI Reference

**Status (as of 2026-05-07, version 0.1.0):** Phase 1 — data layer in place. The plugin's core classes (autoloader, validator, icon library, CPT registry, grouping registry, post-data accessor, capabilities helper) are written and unit-test-ready. Activating the plugin now seeds default options, registers the action hooks, and registers any stored CPTs with WordPress on init — but the admin UI for creating CPTs and groupings does NOT exist yet (next pass). No frontend rendering, no connector, no MCP tools. Activate-and-deactivate is safe; the plugin is functional for programmatic use via `pre()->cpts`, `pre()->groupings`, and `pre()->post_data` from PHP.

A WordPress plugin that renders custom-post-type single pages with structured data display through Promptless WP's design system. Companion plugin alongside Promptless WP (page builder) and Form Runtime Engine (form renderer); does not replace either.

## What this plugin IS

- A renderer for custom-post-type single pages, owned end-to-end by this plugin (CPT registration, custom fields, frontend rendering, admin meta box)
- A constrained-primitive system: one repeatable field type ("grouping") whose items conform to a fixed shape — `image-or-icon, heading, supporting text, optional link` — composed into 3-4 visual layout variants
- A consumer of the `--aisb-*` design tokens emitted by Promptless WP, mirroring how Form Runtime Engine consumes them today
- A connector + MCP surface so Claude Cowork can register CPTs, define groupings, populate per-post values, and choose layout variants
- Designed for greenfield CPT-driven sites first (real estate, jobs, events, courses, professional services, agency-built directories)

## What this plugin IS NOT

- A page builder (Promptless WP does that)
- A form renderer (Form Runtime Engine does that)
- An archive / filter / search engine (deferred to v1.1+; the default theme archive plus Promptless's existing PostGrid section cover archive needs in v1.0)
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
| **Technical design contract** | `docs/ARCHITECTURE.md` |
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

2. **One repeatable field type ("grouping"), one item shape.** No dates, numbers, taxonomy fields, nested groups, conditional logic, or relationship fields in v1.0. Just `{image-or-icon, heading, supporting_text, optional link}`. The constraint is the feature. Each grouping supports per-post variant override (4 variants: compact-grid, card-grid, featured-card, horizontal-row) and three source modes (manual, child_posts, taxonomy_match) for auto-population.

3. **Promptless owns CPT registration and field model.** No ACF / MetaBox / Pods integration in v1.0. Greenfield CPT use cases only. Migration from existing ACF sites is a v1.1 problem.

4. **Default WP editor handles main content.** No new editing surface for prose. Users type in Gutenberg or the classic editor; theme styles the output via existing Promptless content tokens. Groupings flank that main content area.

5. **Three layout positions, not free-form layouts.** `above_main`, `below_main`, `sidebar`. Not full-width-only, not multi-column main, not magazine-style. Three positions cover the use cases this plugin is built for; users wanting more flexibility use Promptless WP for hand-authored pages instead.

6. **3–4 layout variants per grouping, fixed.** Compact grid (icon + label, 2–4 cols), card grid (icon + heading + supporting text, 2–3 cols), featured card (image + heading + supporting text + link, full width or sidebar), horizontal row (icon + value/label inline). Variants are CSS treatments of the same data, not separate components.

7. **Connector + MCP from day one.** Claude Cowork can register CPTs, define groupings, populate values, and choose variants without an admin UI. The admin meta box exists for human-driven editing; the connector exists for AI-driven setup. Both write to the same data model.

8. **No custom DB tables.** All data in `wp_options` (CPT registry + grouping definitions) and post meta (per-post values + layout positions). Same constraint Promptless WP honors — keeps the plugin portable across managed hosts.

9. **Premium-tier feature.** Uses Freemius like Promptless does. Free tier (if any) is TBD during Phase 1; the connector is premium-only following Promptless's pattern.

10. **One-way CSS dependency on Promptless WP.** This plugin reads tokens documented in `docs/AISB_TOKEN_CONTRACT.md`. Promptless WP has zero knowledge of this plugin's existence. Same pattern as FRE.

## Naming conventions

- **Class prefix:** `PRE_*` (Post Runtime Engine, parallels `FRE_*` for Form Runtime Engine)
- **Plugin slug / text domain:** `post-runtime-engine`
- **REST namespace:** `post-runtime/v1/connector/`
- **Action prefix:** `pre_*` (e.g., `pre_cpt_registered`, `pre_grouping_saved`)
- **Filter prefix:** `pre_*`
- **Option prefix:** `pre_*`
- **Post meta prefix:** `_pre_*`
- **MCP tool prefix:** TBD during Phase 4 (`postengine_*`, `cptengine_*`, or whatever fits the connector ecosystem)

The plugin folder name `post-runtime-engine` is provisional and may be renamed before any implementation work begins. Renaming the folder, slug, class prefix, REST namespace, and text domain together is the agreed convention.

## Phased build status

| Phase | Description | Hours est | Status |
|---|---|---|---|
| 0 | Planning + design docs | 6 | **Complete (this folder)** |
| 1 | CPT registry + grouping field type + admin meta box (with variant override) | ~16 | Not started |
| 2 | Frontend rendering + layout variants + source modes (manual / child_posts / taxonomy_match) | ~14 | Not started |
| 3 | Connector REST + MCP tools | ~12 | Not started |
| 4 | Promptless theme integration + design-token consumer CSS | ~6 | Not started |
| 5 | Reference CPTs + documentation | ~4 | Not started |
| 6 | Production polish + first client acceptance | ~8 | Not started |

See `docs/ROADMAP.md` for full phase detail. Total estimate: **~66 hours** for v1.0.

## Critical guardrails for AI sessions working on this plugin

- **Plugin is in PLANNING PHASE.** Do not write Phase 1+ code without explicit confirmation from Breon. The docs in `docs/` are the contract; Phase 1 build implements them.
- **Resist scope creep on field types.** v1 has ONE field type. The temptation to add a date picker, number field, taxonomy selector, or nested repeater will be intense. Don't. Adding a second field type is a v1.1 conversation triggered by real client demand, not by AI suggestion.
- **Do not depend on Promptless WP at the PHP level.** Reading `--aisb-*` CSS tokens with documented fallbacks is the only allowed coupling. No `class_exists('AISB_Plugin')` checks gating functionality. No calls into Promptless's `SectionRenderer` or any other Promptless class.
- **Do not integrate with ACF, MetaBox, or Pods in v1.0.** This plugin owns the field model end-to-end. Migration tooling is a separate v1.1 concern.
- **Honor the design-token contract.** Every `--aisb-*` reference in this plugin's CSS must have a documented fallback in `docs/AISB_TOKEN_CONTRACT.md`. New token consumption requires updating that doc first.
- **The default WP editor handles main content.** Do not build a new editing surface for prose. The plugin's editing UI is just the meta box for groupings.
- **Three layout positions only.** `above_main`, `below_main`, `sidebar`. Resist adding a fourth without an architectural conversation.
- **No custom DB tables in v1.0.** Use `wp_options` and post meta. If a feature seems to require a custom table, that feature is probably out of scope.
- **Test coverage is required for v1 ship.** Each new field-type behavior, layout variant, and renderer pass ships with unit tests. Coverage target: >80%.

---

**Plugin status:** scaffolding complete, design docs in progress, no runtime code yet. See `docs/ROADMAP.md` for what comes next.
