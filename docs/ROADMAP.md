# Post Runtime Engine — Roadmap

**Document status:** Phases 0–6 shipped in v0.1.0. v0.2.0 layered on hero layouts + smart-token routing + variant intent + default_icon. v0.3.0 hardens the connector authoring contract from real hosted-pressure-test findings: critical_rules + field_name_hints in preflight, CDATA sanitization, link-aware cross-CPT icon resolution, update_post tool, _site envelope. v0.3.1 ships renderer global-$post fix and icon validator hints from a 2026-05-10 pressure test.

> **💼 Licensing note (clarified 2026-05-10):** This plugin is **FREE**. References below to "premium gating," "premium-license gating," or "Freemius wiring" are stale planning language from when the business model was undecided. Only Promptless WP is sold via Freemius. PRE, FRE, and FlowMint are all free plugins. Connector endpoints are gated by WP user capability + the per-site connector enable toggle — never by license state. See `CLAUDE.md` for the canonical statement.
**Author:** Breon Williams + Claude (planning + build sessions)
**Last updated:** 2026-05-08
**Plugin name:** Post Runtime Engine (PRE) — confirmed
**Initial commit:** 2026-05-08 (v0.1.0)
**Latest release:** 2026-05-08 (v0.3.0)

## Completion log

| Phase | Status | Notes |
|---|---|---|
| 0 — Planning + design docs | ✅ Complete | All architectural decisions locked; folder scaffold, CLAUDE.md, ARCHITECTURE.md, AISB_TOKEN_CONTRACT.md, INTEGRATION_PROMPTLESS.md shipped |
| 1 — Data layer + admin UI | ✅ Complete | All Phase 1 deliverables shipped + meta box visual polish, hover-revealed handle/remove, expanded 53-icon library across 13 categories, post-search autocomplete with site-portable post_id storage |
| 2 — Frontend rendering | ✅ Complete | All four variants + three positions + three source modes working; kitchen-sink fixture with realistic real-estate data; responsive iframe harness; 59/59 smoke tests passing |
| 3 — Connector + MCP | ✅ Complete | 18 REST endpoints + admin page + 4 connector classes; spec at `docs/CONNECTOR_SPEC.md`; setup guide at `docs/MCP_CONNECTOR_SETUP.md`; 87/87 smoke tests passing |
| 4 — Design-token CSS audit | ✅ Complete | All `--aisb-*` references flow through `--pre-color-*` / `--pre-card-*` intermediates; dark mode (`.aisb-section--dark` ancestor) and neo-brutalist mode (`body.aisb-neo-brutalist-cards`) both wired; ratified contract at `docs/AISB_TOKEN_CONTRACT.md` |
| 5 — Reference patterns + docs | ✅ Complete (light) | README rewritten with shipped state; `docs/SETUP.md` walks human users through first-CPT setup; common patterns for real-estate / attorney / course / event / restaurant documented inline. Full per-industry reference patterns deferred to v1.1 — pending real client demand |
| 6 — Production polish | ✅ Complete (critical path) | Edge-case audit (graceful degradation for trashed images, removed icons, deleted attachments); render-time transient cache with timestamp-based auto-invalidation; accessibility audit (heading hierarchy, alt-text fallbacks, focus indicators, ARIA on icons + link overlays). Multilingual / caching-plugin compat deferred until first client surfaces a need. |

## What shipped beyond original Phase 1+2 scope

These weren't in the original phase plan but proved valuable to ship inline:

- **53-icon curated library across 13 categories.** Original plan called for a small starter set; expanded to cover real estate, legal, education, communication, location, commerce, people, food/hospitality, medical, creative, fitness, travel, and general primitives. Dropdown uses `<optgroup>` for browsability. Extensible via the `pre_icon_library` filter for site-specific additions.
- **Modern meta box card layout.** Drag handle and remove × are hover-revealed; preview tile + icon picker + image controls combined into a single 96px column; fields use border-light styling with indigo focus ring; placeholder-driven labels keep the card scannable when scrolling many items.
- **Post-search autocomplete on the link field.** Inline jQuery UI Autocomplete backed by `/wp/v2/search`. Suppresses for URL-shaped input (anchors, tel:, mailto:, external URLs). Lazy-initialized on first focus.
- **Site-portable internal links.** Items now store `link_post_id` alongside the URL string. Renderer prefers `get_permalink(link_post_id)` at render time, making links survive domain migrations and permalink-structure changes. The autocomplete captures post_id on selection; manual edits clear it. Defense-in-depth check on save: post_id is dropped if the URL no longer matches `get_permalink()`.
- **Browser-based test harnesses.** `tests/run-via-browser.php` and `tests/run-kitchen-sink-via-browser.php` work around Local's wp-cli MySQL socket setup; `tests/responsive-preview.php` renders a post at 375/768/1280px in iframes for visual regression review.

## Architectural decisions resolved during the build

- **Icon UX:** Curated dropdown with optgroup categories, not Iconify slug input. Reasoning: 53 icons cover ≥90% of expected use, dropdown is faster than typing, extensible via filter for niche cases.
- **Link affordance for clickable items:** Unified stretched-link pattern across all variants — full card is the click target, subtle arrow indicator on card-grid / featured-card. Per-item CTA buttons with custom labels deferred to v1.1 (would require a `link_label` field).
- **Featured-card image:** Edge-bleed (1:1 aspect ratio when stacked, auto-fill when side-by-side). No padding around the image.
- **Sidebar groupings:** Force single-column layout regardless of variant — sidebar context overrides the variant's grid even at desktop viewport.
- **Internal link storage:** `link_post_id` (canonical, hidden) + `link` (display string, fallback). Renderer prefers post_id; falls back to URL when post_id is empty or post is trashed.
- **Validator strictness on link_post_id:** Validates structure only (positive int when set), not post existence. Drafts and scheduled posts are valid link targets; trashed posts are rendered with the URL fallback.
- **Plugin name:** "Post Runtime Engine" confirmed (was provisional).

---

## 1. Vision

Post Runtime Engine renders custom-post-type single pages with structured data display, inheriting brand styling from Promptless WP's design system without depending on Promptless at the PHP level. It closes the dynamic-content gap in the FlowMint stack: Promptless WP handles static landing pages, Form Runtime Engine handles intake, FlowMint Workflows handles automation, and this plugin handles single-post display for any post type the user wants to build their site around.

The plugin is positioned as a **standalone premium feature** packaged the same way Form Runtime Engine is — independently versioned, independently shippable, with its own connector and MCP server, integrated with Promptless WP only via the documented `--aisb-*` CSS design-token contract.

## 2. Why this is being built

The agency stack is currently strong at static-page composition and form intake but weak at dynamic-data display. A real estate site with 30 listings, a law firm site with 12 attorney bios, an event site with a calendar of upcoming workshops, a course platform with structured course detail pages — none of these are well-served by hand-authoring each post in Promptless. The repetition cost defeats the value proposition.

Existing alternatives (specialized themes, Elementor templates + ACF, Bricks, FSE block templates) all involve abandoning Promptless's design system, learning a new editor, or accepting brittle vendor lock-in. None of them participate in the Cowork-driven setup pattern the rest of the stack supports.

The constraint that makes this buildable is the same one that made Form Runtime Engine work: pick a small, focused primitive that handles the patterns that show up most often, and accept that more complex use cases stay with specialized tools.

## 3. Architectural premise

A constrained "grouping repeater" primitive — items shaped as `{image-or-icon, heading, supporting_text, optional link}` — covers most of what custom-post-type single pages display. Real estate listings show this pattern as amenities and quick specs. Law firm bios show it as practice areas, education, and contact methods. Event listings show it as event details, speakers, and ticket info. Course pages show it as course info, curriculum modules, and enrollment CTAs.

Pair this primitive with:

- A **simple template wrapper**: hero (auto-rendered from post title + featured image), main content (default WP editor output), groupings positioned `above_main`, `below_main`, or in a `sidebar`, plus a related-posts footer
- A **fixed set of layout variants** for groupings (compact grid, card grid, featured card, horizontal row) — all CSS treatments of the same underlying data
- The **`--aisb-*` design tokens** consumed at the CSS level, with documented fallbacks for standalone use
- A **connector REST + MCP surface** so Claude Cowork can register CPTs, define groupings, populate per-post values, and choose layout variants

Promptless WP owns CPT registration in this plugin's model. No ACF, no MetaBox, no third-party field plugins in v1. The constraint is the feature.

## 4. Scope for v1.0

### In scope

- A new admin UI to register custom post types, with the standard WP options (slug, label, supports, public, has_archive, REST exposure, capability mapping)
- For each registered CPT, an admin UI to define which "groupings" it accepts (key, label, default layout variant, position default)
- A meta box on the post edit screen for each registered CPT, allowing per-post grouping items to be added, edited, reordered, position-overridden, and variant-overridden (a `<select>` per grouping for choosing among the four variants)
- The single-post template renderer (template_include filter) that produces hero + groupings + main content + sidebar + related-posts footer for registered CPTs
- 4 grouping layout variants: compact grid, card grid, featured card, horizontal row
- 3 grouping source modes: `manual` (items stored explicitly), `child_posts` (auto-populated from hierarchical children), `taxonomy_match` (auto-populated from posts sharing a taxonomy term)
- The default WP editor for main content (Gutenberg or classic — both work)
- Featured image and post title rendered automatically as the hero
- Related-posts footer using a taxonomy match (configurable per CPT) — reuses the existing PostGrid pattern from Promptless
- Per-CPT opt-in: this plugin only takes over template rendering for CPTs explicitly registered through it
- Theme override: a theme can supply `post-runtime-engine-{cpt}-single.php` and it wins via `locate_template()`
- Connector REST endpoints under `/post-runtime/v1/connector/*` with WP App Password auth, premium gating, and rate limiting
- MCP tools (named TBD) for: registering CPTs, defining groupings, populating per-post values, previewing the rendered output, deploying changes, listing all CPTs and their templates
- Strict-mode validation: grouping items must conform to the fixed shape; layout variants must come from the fixed list; positions must be one of the three allowed values
- Reference CPTs documentation: example setups for real estate listing, law firm attorney bio, event listing, course detail
- Inherits `--aisb-*` design tokens when Promptless WP is active; degrades to documented fallbacks when not

### Explicitly out of scope for v1.0

- **Multiple field types.** v1 has ONE field type (the grouping repeater). Date pickers, number fields, value-with-unit fields (for nutrition / lab specs), relationship fields, nested repeaters — all deferred.
- **Archive templates.** The default WP archive plus Promptless WP's existing PostGrid section cover archive needs in v1.
- **Search / filter / sort UI.** Deferred. Adding filter UIs is a substantial separate concern that benefits from FRE composition (see `docs/CLAUDE.md` notes on "filter as form" pattern for v1.1+).
- **ACF / MetaBox / Pods integration.** v1.0 owns the field model end-to-end. Migration tooling for existing field-plugin sites is a v1.1 concern.
- **Custom block / Gutenberg block editor integration for groupings.** Groupings are edited via the meta box, not as Gutenberg blocks.
- **Per-instance overrides beyond position and variant.** A post can override position and variant per grouping but cannot override the grouping shape (e.g., adding a fifth field on one post). Shape overrides are deferred.
- **Custom CSS per post / per grouping.** Theme-level customization is supported via the design tokens; per-instance custom CSS is deferred.
- **Block themes / FSE integration.** Classic-style template overrides only.
- **WooCommerce product templates.** The `product` post type is excluded from takeover.
- **User accounts, favorites, saved searches.** Deferred.
- **Custom HTML / arbitrary code components.** Anti-drift requirement.

These deferred features can be added in v1.1+ as need arises.

## 5. Success criteria for v1.0

A v1.0 ship is gated on ALL of the following:

1. **One real client CPT goes live.** A real estate, law firm, events, or courses site uses this plugin in production for its CPT singles. The visual coverage matches client expectations.
2. **Cowork can build a CPT setup end-to-end.** From `register CPT` through `define groupings` and `populate first post` to `preview the rendered output`, no manual admin-UI step required.
3. **The `--aisb-*` token contract works in both directions.** With Promptless WP active, the plugin inherits brand styling. Without Promptless WP, the documented fallbacks produce a clean, usable default look.
4. **Strict validation catches the common error classes.** Items that don't conform to the fixed shape, invalid layout variants, invalid positions — all rejected at save with clear error messages.
5. **No regressions to existing surfaces.** Promptless WP page authoring, FRE form rendering, and FlowMint Workflows all work unchanged. Activating this plugin alongside them produces no conflicts.
6. **Performance verified.** A single-post template with 4 groupings renders in under 100ms on a representative dev box, with post meta cache pre-warming preventing N+1 queries.
7. **Test coverage > 80%** for the renderer, validator, CPT registry, and grouping data layer.
8. **Documentation complete.** This roadmap, the architecture doc, the design-token contract, the connector spec, the integration boundary doc, and reference patterns for at least 3 example CPTs.

## 6. Phased build plan

| Phase | Title | Hours est | Cumulative | Output |
|---|---|---|---|---|
| 0 | Planning + design docs | 6 | 6 | This doc set; folder scaffold; CLAUDE.md and architecture spec |
| 1 | CPT registry + grouping data layer + meta box | 16 | 22 | Admin UI for CPT registration, grouping definitions, per-post meta box (with variant-override select); data persists correctly |
| 2 | Frontend rendering + layout variants + source modes | 14 | 36 | Template router; hero / groupings / main / sidebar / related-posts pipeline; 4 layout variants; manual/child_posts/taxonomy_match source resolution with caching |
| 3 | Connector REST + MCP tools | 12 | 48 | All endpoints; full Cowork session can build a CPT setup end-to-end |
| 4 | Design-token consumer CSS | 6 | 54 | Inherits Promptless tokens; documented fallbacks; per-variant CSS |
| 5 | Reference CPTs + docs | 4 | 58 | Example setups for real estate, law firm, events, courses; user-facing docs |
| 6 | Production polish + first client acceptance | 8 | 66 | Cache layer, accessibility audit, multilingual + caching-plugin compat, first client cutover |

The estimate ticked up from ~54 to ~66 hours after planning conversations surfaced two refinements worth promoting from "v1.1 deferred" to v1.0 scope: per-post variant override (~3 hours added across Phases 1 and 2) and the three grouping source modes including auto-population from child posts and taxonomy matches (~6 hours added in Phase 2). Both deliver real day-one value for the law-firm hierarchy and cross-CPT linking patterns.

Even at ~66 hours, the estimate is meaningfully smaller than the earlier on-hold roadmap that lived in Promptless's docs folder. The scope reduction holds: (a) one field type, not many; (b) no field-source abstraction (Native / ACF / MetaBox); (c) no binding-layer architecture over Promptless's section catalog; (d) default WP editor handling main content; (e) three positions, not free-form layouts.

Each phase ends with an acceptance gate. No phase begins until the previous phase's gate is met.

---

### Phase 0 — Planning + design docs (6 hours, complete)

**Goal.** Lay down the architectural decisions and design contracts before any code is written.

**Deliverables.**
- This roadmap
- `docs/ARCHITECTURE.md` — technical design contract
- `docs/AISB_TOKEN_CONTRACT.md` — design-token contract (consumer side)
- `docs/INTEGRATION_PROMPTLESS.md` — boundary doc with Promptless WP
- `CLAUDE.md` — AI / engineer front door
- `README.md` — public-facing overview
- `post-runtime-engine.php` — plugin header stub (no runtime code)

**Acceptance gate.** Founder approves architectural decisions; final plugin name confirmed; ready to begin Phase 1.

---

### Phase 1 — CPT registry + grouping data layer + meta box (14 hours, ✅ complete)

**Goal.** Promptless owns CPT registration end-to-end. Users can register a CPT through the admin UI, define which groupings it accepts, and fill in per-post grouping items.

**Deliverables.**

```
includes/
  class-pre-autoloader.php           ← PSR-like autoloader for PRE_* classes
  Core/
    class-pre-cpt-registry.php       ← Stores and reads CPT definitions; calls register_post_type()
    class-pre-grouping-registry.php  ← Stores and reads grouping definitions per CPT
    class-pre-post-data.php          ← Read/write per-post grouping values + position/variant/source overrides
    class-pre-validator.php          ← Strict validation: shape, variants, positions, sources
    class-pre-capabilities.php       ← Capability mapping for registered CPTs
  Admin/
    class-pre-admin.php              ← Admin menu + page registration
    class-pre-admin-cpts.php         ← CPT list + registration form
    class-pre-admin-groupings.php    ← Per-CPT grouping definition UI
    class-pre-meta-box.php           ← Post-edit-screen meta box for grouping values
                                       (includes per-grouping variant <select> and source mode <select>)
post-runtime-engine.php              ← Real bootstrap (replaces planning stub)
uninstall.php                        ← On plugin delete: clean options (preserve post meta by default)
```

**Acceptance gate.**
- A CPT can be registered through the admin UI, persists across requests, and is queryable via standard WP functions
- Groupings can be defined for a CPT and persist
- The post edit screen for a registered CPT shows the meta box with all defined groupings
- Adding/editing/reordering grouping items works correctly; data persists in post meta
- The variant override `<select>` works per grouping; null means "use definition default"
- The source mode `<select>` works per grouping (manual / child_posts / taxonomy_match); auto modes hide the items editor and show a preview of what will resolve
- Strict validation rejects: malformed items, invalid layout variants, invalid positions, invalid source modes, child_posts source on non-hierarchical CPTs, taxonomy_match referencing an unregistered taxonomy
- Capability mapping is correct (admin-level for CPT registration; configurable per-CPT for per-post editing)
- Uninstall preserves post data by default (matches Promptless's data-protection pattern)

**Iterative test.** Set up a "Listing" CPT with two groupings ("Quick Specs" and "Amenities"). Create three listing posts, populate groupings, verify data round-trips correctly. Delete the CPT registration; verify post meta is preserved (not destroyed) by default.

---

### Phase 2 — Frontend rendering + layout variants + source modes (14 hours, ✅ complete)

**Goal.** Single-post pages for registered CPTs render through this plugin's template, with all four layout variants and all three source modes (manual / child_posts / taxonomy_match) working correctly. Per-post variant overrides applied at render time.

**Deliverables.**
- `includes/Core/class-pre-template-router.php` — `template_include` filter; per-CPT opt-in lookup; respects Promptless `_aisb_enabled` flag (Promptless wins per-post if both are active on a CPT)
- `includes/Core/class-pre-source-resolver.php` — resolves `manual` / `child_posts` / `taxonomy_match` sources to items at render time; per-source caching via transients
- `includes/Frontend/class-pre-renderer.php` — orchestrates hero + groupings + main content + sidebar + related-posts; resolves variant overrides
- `includes/Frontend/class-pre-grouping-renderer.php` — per-variant grouping HTML output
- `templates/single-base.php` — base template structure (hero, body grid, sidebar, footer)
- `assets/css/frontend.css` — base styling using `--aisb-*` tokens with documented fallbacks
- `assets/css/variants/compact-grid.css`
- `assets/css/variants/card-grid.css`
- `assets/css/variants/featured-card.css`
- `assets/css/variants/horizontal-row.css`
- Theme override support: `locate_template()` lookup for `post-runtime-engine-{cpt}-single.php`
- Conditional asset loading: variant CSS only loads if the page uses that variant (mirrors Promptless's per-section CSS pattern)
- Cache invalidation hooks: `save_post`, `before_delete_post`, taxonomy term changes invalidate affected source-resolver transients

**Acceptance gate.**
- A registered CPT's single-post pages render through this plugin's template, not the theme's default `single.php`
- Groupings positioned `above_main` appear above the main content; `below_main` appears below; `sidebar` appears in the sidebar column on desktop and stacks below main on mobile
- All four layout variants render correctly with realistic data
- Per-post `variant_override` correctly overrides the grouping definition's default variant
- `manual` source uses stored items; `child_posts` source pulls live from hierarchical children; `taxonomy_match` source pulls from posts sharing the configured taxonomy term
- Auto-source caching works: first render queries the DB, second render hits the transient, post-save invalidates correctly
- Featured image and post title render automatically as the hero
- Default WP editor content (`the_content()`) renders in the main content area, styled by the design tokens
- Related-posts footer renders using a taxonomy match
- Theme override works: if the theme provides `post-runtime-engine-listing-single.php`, it wins
- Pages render in under 100ms on a representative dev box (manual source); under 150ms cold cache for auto-source pages

**Iterative test.** Build a 3-level hierarchical practice-area structure (Personal Injury parent with 4 children) plus 5 attorney posts cross-linked to practice areas via taxonomy. Render the parent practice page: verify "Areas We Handle" auto-populates from `child_posts` source, "Related Practices" auto-populates from `taxonomy_match` source, "Practicing Attorneys" populated manually with explicit links. Side-by-side comparison: with Promptless WP active vs. without; with variant overrides set vs. not. All combinations look correct.

---

### Phase 3 — Connector REST + MCP tools (12 hours, 🚧 starting)

**Goal.** Claude Cowork can register CPTs, define groupings, populate per-post values, choose layout variants, and preview output entirely through MCP tools.

**REST endpoints** under `/wp-json/post-runtime/v1/connector/`:

| Endpoint | Method | Purpose |
|---|---|---|
| `/preflight` | GET | Site readiness; lists registered CPTs with capability flags |
| `/cpts` | GET, POST | List all registered CPTs / register a new CPT |
| `/cpts/{slug}` | GET, PUT, DELETE | CPT CRUD |
| `/cpts/{slug}/groupings` | GET, POST | List / define groupings for a CPT |
| `/cpts/{slug}/groupings/{key}` | GET, PUT, DELETE | Grouping CRUD |
| `/posts/{id}/groupings` | GET, PUT | Per-post grouping values |
| `/posts/{id}/preview` | GET | Render a post and return the HTML for visual verification |
| `/posts` | POST | Create a post in a registered CPT (with optional grouping values) |

**MCP tools** (names TBD during Phase 3 — provisional list):
- `pre_preflight`
- `pre_list_cpts`
- `pre_register_cpt`
- `pre_get_cpt`
- `pre_update_cpt`
- `pre_delete_cpt`
- `pre_list_groupings`
- `pre_define_grouping`
- `pre_update_grouping`
- `pre_delete_grouping`
- `pre_get_post_groupings`
- `pre_set_post_groupings`
- `pre_create_post`
- `pre_preview_post`

Each follows the pattern established by Promptless WP's connector and FRE's connector: WP App Password auth, `manage_options` (or stricter per-endpoint) capability checks, premium-license gating, per-user per-endpoint rate limiting.

**Acceptance gate.**
- Full Cowork session can register a CPT, define 3 groupings, create 5 posts with populated groupings, and preview each rendered output — all via MCP tools, no admin UI step
- All endpoints return well-shaped error responses on validation failure
- Rate limiting fires correctly under stress test
- Premium gating returns 403 cleanly for free-tier sites (if free tier exists)
- Strict validation catches at least 3 common error classes (malformed items, invalid variant, invalid position)

**Iterative test.** Have Cowork build a real-estate setup against a fixtures dataset. Verify the deploy validation catches deliberately broken inputs.

---

### Phase 4 — Design-token consumer CSS (6 hours)

**Goal.** This plugin's frontend CSS reads the documented `--aisb-*` tokens with proper fallbacks, mirroring how Form Runtime Engine consumes them.

**Deliverables.**
- Update `docs/AISB_TOKEN_CONTRACT.md` with the final token list this plugin consumes (after Phase 2 reveals the actual usage)
- All `--aisb-*` references in `assets/css/*.css` audited to confirm: every reference has a fallback; every consumed token is listed in the contract
- Dark-mode support: when the page is inside `.aisb-section--dark` or theme variant is `dark`, dark tokens apply
- Neo-brutalist mode support: when Promptless's neo-brutalist toggle is on, brutal tokens apply
- Visual regression tests: side-by-side renders with Promptless active vs. inactive, dark vs. light, neo-brutalist on vs. off
- CI test (where feasible): grep all CSS files for `--aisb-*`; verify every match is in the contract

**Acceptance gate.**
- All token references documented in the contract
- All token references have fallbacks
- Visual quality acceptable in all four mode combinations (Promptless on/off × dark/light)
- No `!important` overrides anywhere in the plugin's CSS
- Renders well on the Promptless theme; renders acceptably on Twenty Twenty-Five and one other popular theme

**Iterative test.** Activate this plugin on a vanilla WordPress install with the default Twenty Twenty-Five theme and no Promptless. Set up a small CPT and render a post. Visual review: clean and usable. Then activate Promptless and the Promptless theme; visual review: brand styling inherited automatically.

---

### Phase 5 — Reference CPTs + documentation (4 hours)

**Goal.** Documented example setups for the most common CPT use cases, plus complete user-facing docs.

**Deliverables.**
- `docs/REFERENCE_PATTERNS.md` — example setups for: real estate listing, law firm attorney bio, event listing, course detail. Each example includes: CPT registration JSON, grouping definitions JSON, sample post data, expected visual output description.
- `docs/CONNECTOR_API.md` — full REST + MCP reference for external consumers
- `docs/SETUP.md` — first-run setup walkthrough for human users
- `docs/TROUBLESHOOTING.md` — common issues, diagnostics, fixes
- Updates to `CLAUDE.md` and `README.md` reflecting actual shipped state

**Acceptance gate.**
- Each reference pattern is reproducible: a fresh Cowork session can replicate it from the documented JSON
- Connector API doc covers every endpoint with example requests and responses
- Setup doc walks a non-AI human user from "fresh install" to "first CPT live"
- Troubleshooting doc captures issues found during phases 1-4

---

### Phase 6 — Production polish + first client acceptance (8 hours)

**Goal.** Production-grade stability, performance, accessibility. First real client CPT goes live.

**Deliverables.**
- Caching layer: rendered template HTML cached as transient keyed on `(post_id, post_modified, plugin_version, cpt_definition_version)`. Invalidates correctly on data changes.
- N+1 query prevention: `update_post_meta_cache()` for the post being rendered; bulk warm for the related-posts footer
- Accessibility audit: axe / Lighthouse passes; correct heading levels; alt text on grouping images; keyboard navigation on the meta box
- Multilingual compatibility: WPML and Polylang both work correctly with registered CPTs
- Caching plugin compatibility: tested against W3 Total Cache, WP Rocket, LiteSpeed Cache
- Edge cases: trash/draft/private status handled correctly; missing data falls back gracefully; very long titles / descriptions don't break layout
- Performance verification: 4-grouping single-post template renders in under 100ms cold cache, under 5ms warm
- First client cutover: one real CPT (real estate, law firm, events, or courses) deployed to production. Documented rollback plan.

**Acceptance gate.**
- All Phase 0–5 acceptance criteria still hold
- Performance, accessibility, multilingual, and caching-plugin targets met
- First production client successfully cutover; no critical bugs in 7 days
- All documentation merged

---

## 7. Decision gates between phases

| Gate | What's being decided | What "no-go" looks like |
|---|---|---|
| End of Phase 0 | Are the architectural decisions correct and the scope tight? | Founder pushback on scope; replan |
| End of Phase 1 | Does the data model work end-to-end? | Storage or validation patterns need rework before adding rendering |
| End of Phase 2 | Does the frontend look right with realistic data? | Layout variants or template structure need rework |
| End of Phase 3 | Can Cowork drive the full lifecycle without the admin UI? | Connector surface needs more endpoints; expand before Phase 4 |
| End of Phase 4 | Does the design-token contract work in both standalone and Promptless-active modes? | Visual regressions in one mode; fix before Phase 5 |
| End of Phase 5 | Are the docs sufficient for self-service onboarding? | Add examples; expand troubleshooting |
| End of Phase 6 | Ready to ship? | Performance, accessibility, or first-client cutover criteria not met |

## 8. Open questions to resolve before / during build

1. **Final plugin name.** "Post Runtime Engine" is provisional. Should be settled before Phase 1.
2. **MCP tool naming convention.** `pre_*`, `postengine_*`, `cptengine_*`, or other? Decide during Phase 3.
3. **Free vs premium tier.** Is there a free tier? If so, what's gated? Resolve during Phase 1 alongside Freemius wiring.
4. **Hero customization beyond the automatic post title + featured image.** Should the hero be customizable per-CPT (e.g., add a subtitle, add a specific grouping inline)? Decide during Phase 2 based on what real-estate / law-firm patterns need.
5. **Related-posts footer configurability.** Per-CPT taxonomy match is the v1 default. Should users be able to disable it? Customize the variant? Decide during Phase 2.
6. **Block editor compatibility for the meta box.** Gutenberg-friendly meta box vs. classic post-edit-screen meta box? Affects Phase 1 scope.
7. **Image-or-icon field UX.** Both are supported, but should the user pick one per item, or can items have both? What's the icon library — Dashicons? Iconify? Custom upload? Decide during Phase 1.
8. **Taxonomy registration.** This plugin's `pre_register_cpt` should accept taxonomy registrations alongside CPT definitions, since `taxonomy_match` source mode depends on taxonomies existing. Decide the API shape during Phase 1.

**Resolved during planning (no longer open):**
- ~~Per-post variant override.~~ Promoted to v1.0 scope.
- ~~Source modes (auto-population from child posts and taxonomy matches).~~ Promoted to v1.0 scope.

**Resolved during Phases 1+2 (no longer open):**
- ~~Final plugin name.~~ "Post Runtime Engine" confirmed.
- ~~Image-or-icon field UX.~~ Mutually exclusive per item (validator enforces). Curated 53-icon library across 13 categories with `<optgroup>` dropdown; extensible via `pre_icon_library` filter for site-specific additions. Iconify slug input deferred — curated set covers expected use.
- ~~Block editor compatibility for the meta box.~~ Classic-style PHP-rendered meta box; works alongside Gutenberg post-edit screen via the standard meta-box compat shim. No native Gutenberg-block port in v1.

**Still open (resolve during Phase 3):**
- MCP tool naming convention. Provisional: `postruntime_*` (matches plugin slug, distinct from FRE's `formengine_*` and Promptless WP's `wordpress_*`). Final lock during spec authoring.
- Free vs premium tier. Decision: defer Freemius wiring in v1; ship as a single tier behind WP App Password auth + capability check, matching FRE's connector pattern. Revisit before public release.
- Hero customization beyond automatic post title + featured image. Defer to v1.1 unless a real client surfaces a need.
- Related-posts footer configurability. Currently hardcoded to first registered taxonomy; defer per-CPT configurability to v1.1.

## 9. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Scope creep on field types — pressure to add a date picker, number field, etc. | High | High | Discipline. The constraint is the feature. Adding a second field type is a v1.1 conversation, never v1. |
| Three layout positions are too restrictive for real-world layouts | Low | Medium | Three positions cover the use cases this plugin targets. If a client demands a fourth, that's a v1.1 conversation triggered by real demand. |
| The grouping primitive doesn't fit one major industry use case we haven't tested yet | Medium | Medium | Phase 5 documents reference patterns across 4 industries. If a 5th industry surfaces a misfit during pre-launch, replan during Phase 5. |
| Cowork drift in CPT setup (registering wrong fields, picking wrong variants) | Medium | Medium | Strict validation; rich introspection responses; require preview before deploy. |
| Conflict with existing CPTs on a site (e.g., user already has a `listing` CPT) | Low | Medium | Per-CPT opt-in; the plugin only takes over CPTs it registered. Existing third-party CPTs are untouched. |
| Theme template conflicts | Medium | Low | Theme override always wins via `locate_template()`. Admin UI shows clear warnings when conflicts are detected. |
| Caching plugin incompatibility | Medium | Medium | Test against top 3 plugins in Phase 6; document any required tweaks. |
| Performance regression on sites with many registered CPTs | Low | Medium | Phase 6 caching layer; benchmark targets; lazy registration where possible. |

## 10. What's deferred to v1.1+

In rough priority order:

1. **Migration tooling for existing ACF / MetaBox sites.** "Import these ACF field groups into Post Runtime Engine groupings" — driven by first migration request from a real client.
2. **Additional field types.** Date picker, number, value-with-unit (for nutrition / lab specs), relationship field — driven by concrete client need.
3. **Archive templates.** Custom archive layouts for registered CPTs.
4. **Filter / search / sort UI for archives.** Likely composed by reusing FRE field types as filter controls.
5. **Custom CSS per post / per grouping.** For one-off design adjustments.
6. **Block editor / Gutenberg integration for groupings.** Edit groupings as blocks instead of via the meta box.
7. **Hero customization.** Beyond automatic post title + featured image: per-CPT hero variants (add subtitle, add inline grouping, add CTA).
8. **Related-posts footer customization.** Choose taxonomy, choose variant, disable entirely.
9. **Shared visual patterns from Promptless.** Accordion, tabs, modal — only added if specific groupings need them and the use case is concrete.
10. **Block themes / FSE integration.**
11. **Additional source modes.** `featured_query` (admin-curated list), `recent_posts` (most recent N in a CPT), `manual_post_list` (specific post IDs) — if real demand surfaces.

Each deferred feature can be scoped against the v1.0 architecture without major rework. The phases above leave clean extension points.

---

## 11. How this document evolves

This is a living planning document. Edit it during the build:

- Cross out scope items that prove unnecessary; add scope items that prove essential
- Move items between phases as dependencies clarify
- Update hour estimates as actual time accrues
- Mark phase completions with date + commit references
- Resolve open questions inline; cross-reference the resolution

When the v1.0 build ships, freeze this document as a historical artifact and start v1.1 in a new phase plan that references this one.
