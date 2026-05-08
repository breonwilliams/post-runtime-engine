# Post Runtime Engine

**Status:** v0.3.0 — hosted pressure-test hardening: connector now ships a `critical_rules` rulebook + `field_name_hints` in preflight, defensive CDATA sanitization on post_content, link-aware cross-CPT default_icon resolution, `postruntime_update_post` tool, and a `_site` envelope on every connector response. Smoke suite extended from 99 to 138 assertions covering all of the above.

Renders WordPress custom-post-type single pages with structured data display, inheriting brand styling from Promptless WP's Global Settings. Companion plugin to Promptless WP (page builder) and Form Runtime Engine (form renderer).

---

## What it does

Provides a constrained primitive for repeatable structured content on CPT single pages:

- **Register custom post types** through an admin UI — slug, labels, supports, taxonomies, capability mapping. No dependency on ACF, MetaBox, or other field plugins.
- **Define "groupings"** per CPT — named clusters of items that share a layout variant and position. A real-estate listing might have a "Quick specs" grouping (horizontal-row variant, above main) and a "Highlights" grouping (card-grid variant, below main).
- **Items use a single shape** — `{image-or-icon, heading, supporting_text, optional link}` — chosen because it covers the patterns that show up most often: amenities, attorney specialties, course modules, event details, restaurant menu sections, photo portfolios, attorney practice areas, instructor bios.
- **Four layout variants** for groupings — compact-grid, card-grid, featured-card, horizontal-row. All CSS treatments of the same data shape; per-post override picks one.
- **Three positions** — above main content, below main content, or in a sidebar.
- **Three source modes** — `manual` (items stored explicitly), `child_posts` (auto-populated from hierarchical children), `taxonomy_match` (auto-populated from posts sharing a taxonomy term).
- **Site-portable internal links** — picking a post via the meta box's autocomplete stores the post ID alongside the URL string. The renderer resolves through `get_permalink()` at render time, so internal links survive domain migrations and permalink changes without database rewrites.
- **Curated icon library** — 53 icons across 13 categories (Property, Business & Legal, Education, Communication, Location & Time, Commerce, People, Food & Hospitality, Medical, Creative, Fitness, Travel, plus general primitives). Extensible via the `pre_icon_library` filter.
- **Connector REST API + MCP tools** — 18 endpoints under `/wp-json/post-runtime/v1/connector/` so Claude Cowork can register CPTs, define groupings, populate per-post values, preview rendered output, and run an end-to-end agency workflow without touching the admin UI.
- **Render caching** — per-post transient cache, automatic invalidation on save_post / before_delete_post / set_object_terms / grouping definition changes. Logged-in editors bypass cache for fresh previews. Filterable via `pre_render_cache_enabled` and `pre_render_cache_lifetime`.
- **Design-token inheritance** — reads `--aisb-*` tokens from Promptless WP for colors, spacing, typography, radii. Dark mode triggered automatically by the Promptless theme's customizer setting (Appearance → Customize → Content Theme). Neo-brutalist mode triggered by the body class `aisb-neo-brutalist-cards`. Documented contract at [`docs/AISB_TOKEN_CONTRACT.md`](docs/AISB_TOKEN_CONTRACT.md).
- **Graceful no-Promptless fallback** — every token reference has a documented fallback. Plugin renders cleanly on a vanilla WordPress install.

---

## What it does NOT do

- **Replace Promptless WP** for landing-page authoring. Promptless owns static page composition; this plugin owns dynamic CPT single-page rendering.
- **Render archive pages or filter UIs.** Default WP archive plus Promptless WP's existing PostGrid section cover archives in v1. Filter / search UIs deferred to v1.1+.
- **Multiple field types.** v1 has one field type (the grouping repeater). Date pickers, number fields, value-with-unit fields, relationship fields are deferred.
- **General-purpose custom fields.** Not a competitor to ACF or MetaBox; the constraint is the feature.
- **Block editor / Gutenberg integration for groupings.** Groupings are edited via the meta box, not as Gutenberg blocks.
- **Block-themes / Full Site Editing integration.** Classic-style template overrides only in v1.

---

## Quick start

1. **Activate** the plugin alongside Promptless WP (recommended for design-token inheritance).
2. **Register a CPT**: Admin → Post Runtime → Post Types → Add new. Pick a slug, labels, supports, taxonomies.
3. **Define groupings** for that CPT: pick a key, default layout variant, default position, source mode.
4. **Create a post** of that type. The post-edit screen shows a Post Runtime Groupings meta box. Populate items per grouping.
5. **Preview** — the post's frontend URL renders through this plugin's template, with hero (title + featured image + excerpt), main content (the WP editor body), and groupings positioned according to your definitions.

For the human-facing setup walkthrough with screenshots, see [`docs/SETUP.md`](docs/SETUP.md).

For the AI-agent / Cowork workflow, see [`docs/MCP_CONNECTOR_SETUP.md`](docs/MCP_CONNECTOR_SETUP.md) and [`docs/CONNECTOR_SPEC.md`](docs/CONNECTOR_SPEC.md).

---

## Requirements

| Requirement | Version | Notes |
|---|---|---|
| WordPress | 5.6+ | Application Passwords are core 5.6+; the connector relies on them. |
| PHP | 7.4+ | Type hints, null coalescing, arrow functions. |
| MySQL | 5.6+ / MariaDB 10.0+ | InnoDB recommended (transactional integrity for post-meta backups). |
| Promptless WP | 1.3.0+ | Optional but recommended — without it, the plugin uses documented fallback values for all design tokens. |

---

## Documentation

- **[CLAUDE.md](CLAUDE.md)** — engineering / AI front door
- **[docs/ROADMAP.md](docs/ROADMAP.md)** — phase plan + completion status
- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — technical design contract
- **[docs/SETUP.md](docs/SETUP.md)** — first-run setup walkthrough
- **[docs/AISB_TOKEN_CONTRACT.md](docs/AISB_TOKEN_CONTRACT.md)** — design-token contract with Promptless WP
- **[docs/INTEGRATION_PROMPTLESS.md](docs/INTEGRATION_PROMPTLESS.md)** — boundary doc with Promptless WP plugin
- **[docs/CONNECTOR_SPEC.md](docs/CONNECTOR_SPEC.md)** — REST + MCP API contract
- **[docs/MCP_CONNECTOR_SETUP.md](docs/MCP_CONNECTOR_SETUP.md)** — Cowork connector setup guide

---

## Companion plugins

This plugin is part of a coordinated stack:

- **Promptless WP** — landing-page builder (page composer with global brand settings)
- **Form Runtime Engine (FRE)** — form renderer (intake forms, lead capture)
- **FlowMint Workflows** — automation runtime (post-submission workflows)
- **Post Runtime Engine (this plugin)** — single-post renderer for custom post types

All four share the same design-token contract and connector pattern. They can be used independently — each works without the others — but together they cover the full agency workflow: static pages (Promptless), dynamic CPT pages (this), forms (FRE), and post-form automation (FlowMint).

---

## License

GPL-2.0-or-later — same as WordPress core.
