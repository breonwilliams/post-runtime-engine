# Hero Contrast & Width — Design Contract (Phase A)

**Status:** Locked 2026-07-04. Implementation must match this contract; changes to the
contract require editing this document first (same discipline as
`POST_FIELDS_V1_1_DESIGN.md`).

## Problem

Single-post pages render the hero in whatever mode the sitewide
`promptless_content_theme` Customizer setting applies to `<main>`. The hero has no
independent contrast hook, so a light page produces a visually flat detail page: hero,
body, and sidebar all share one background. Modern detail-page design (real estate
listings, events, jobs — PRE's core use cases) leans on a contrasting hero band: dark
band on a light page, often bleeding full viewport width while the content stays
grid-aligned.

## Scope (Phase A only)

Two new **CPT-level** definition keys. Nothing per-post ships in this phase.

| Key | Enum | Default | Meaning |
|---|---|---|---|
| `hero_theme` | `inherit` \| `light` \| `dark` | `inherit` | `inherit` = today's behavior (hero follows the page mode). `light`/`dark` force that mode on the hero band only, independent of the page. |
| `hero_width` | `contained` \| `full` | `contained` | `contained` = today's behavior. `full` = the hero band's background bleeds to the viewport edges; hero *content* stays capped at `--aisb-section-max-width` and aligned with the page grid. |

Explicit non-goals for Phase A (future contracts required):
- `overlay` as a third `hero_layout` (text over featured image with scrim) — Phase B.
- Per-post hero theme override (visibility-model pattern) — Phase C.
- Any new `--aisb-*` token. Phase A consumes only tokens already listed in
  `docs/AISB_TOKEN_CONTRACT.md`.

## Rendering contract

`PCPTPages_Renderer::render_hero()` adds classes to the existing `.pre-hero` root:

- `hero_theme=dark`  → `pre-hero--theme-dark`
- `hero_theme=light` → `pre-hero--theme-light`
- `hero_theme=inherit` → no theme class (byte-identical markup to v0.6.3)
- `hero_width=full`  → `pre-hero--full`
- `hero_width=contained` → no class (byte-identical markup)

`render_internal()` additionally emits `pre-single--hero-full` on the `<article>` when
`hero_width=full` (the breakout needs `overflow-x: clip` and padding coordination on
the container; a child class alone cannot provide that).

## CSS contract (`assets/css/frontend.css`)

1. **Hero-scoped theming.** `.pre-hero--theme-dark` / `.pre-hero--theme-light` re-declare
   the same `--pre-color-*` intermediate set used by the existing
   `.aisb-section--dark .pre-single` block, scoped to the hero element, reading the
   identical `--aisb-*` token chains (including smart WCAG link/icon tokens and hover
   brightness). The hero also gets `background: var(--pre-color-background)` and
   `color: var(--pre-color-text)` plus internal padding when themed (a forced-theme
   hero is a *band*; an inherit hero remains backgroundless as today).
   Both forced-theme blocks must win regardless of page mode — a `pre-hero--theme-light`
   inside `.aisb-section--dark` renders light, and vice versa. CSS cascade handles this
   because the hero-scoped declarations are more specific and later in the file; add an
   explicit comment marking the ordering requirement.
2. **Full-bleed breakout.** `.pre-single--hero-full { overflow-x: clip; }` on the
   article; `.pre-hero--full { margin-inline: calc(50% - 50vw); padding-inline:
   max(var(--aisb-section-space-md, 1.5rem), calc(50vw - var(--aisb-section-max-width,
   1280px) / 2 + var(--aisb-section-space-md, 1.5rem))); }` so the band bleeds while
   content re-aligns to the page grid. `50vw` includes the scrollbar gutter on classic
   scrollbars; `overflow-x: clip` (not `hidden`) prevents the horizontal scroll sliver
   without creating a new scroll container.
3. **No self-referencing intermediates** (documented v0.1.0 bug class): every
   re-declaration reads `--aisb-*` sources, never another `--pre-*`.
4. Neo-brutalist mode requires no hero rules (it styles grouping/footer cards only).

## Data-layer contract

- `PCPTPages_Validator::validate_cpt_definition()` — two new closed-enum checks
  following the `hero_layout` pattern. New class constants `HERO_THEMES` and
  `HERO_WIDTHS` (validator is the single source of truth for all closed enums).
- `PCPTPages_CPT_Registry::merge_defaults()` — `hero_theme => 'inherit'`,
  `hero_width => 'contained'`. Existing stored CPTs pick the defaults up transparently
  on read; **no data migration**, no `PCPTPages_DATA_VERSION` bump (additive keys with
  behavior-preserving defaults).
- `build_register_args()` untouched (render-only fields).

## Cache-invalidation prerequisite (pre-existing bug, fixed in this phase)

CPT definition changes currently do NOT invalidate the render cache: the invalidation
hook only watches options prefixed `pcptpages_groupings_`, while CPT definitions live
in the single `pcptpages_cpts` option. Toggling `hero_layout` today (and `hero_theme`
tomorrow) leaves stale cached HTML for up to `DEFAULT_CACHE_LIFETIME`.

Fix shipping with Phase A: `PCPTPages_Renderer` listens to `pcptpages_cpt_registered`
and `pcptpages_cpt_unregistered` and bumps the same per-CPT
`pcptpages_groupings_changed_{slug}` timestamp the cache key already incorporates.
Reuses the existing timestamp rather than adding a parallel key so cache-key
composition stays single-sourced.

## Surface wiring checklist (all mandatory — silent-drop gates marked ⚠️)

- [ ] `PCPTPages_Validator`: `HERO_THEMES`, `HERO_WIDTHS`, two validation blocks
- [ ] `PCPTPages_CPT_Registry::merge_defaults()`
- [ ] `PCPTPages_Renderer`: read defs, class emission, cache-invalidation hooks
- [ ] `assets/css/frontend.css`: theme blocks + breakout
- [ ] Admin CPTs screen: two selects in the Hero fieldset, save handling, form defaults
- [ ] ⚠️ Connector `get_field_name_hints()['cpt_definition']` — keys absent from this
      list are silently dropped on write
- [ ] Connector preflight: mention new enums in the CPT rules text
- [ ] ⚠️ MCP `post-runtime-connector.js`: `postruntime_register_cpt` +
      `postruntime_update_cpt` `inputSchema.properties` AND the JS body-allowlist
      arrays (keys absent there are silently dropped before the HTTP call)
- [ ] `docs/AISB_TOKEN_CONTRACT.md`: no new tokens; add the hero band to the
      consumption notes for the dark token set

## Acceptance criteria

1. A CPT with defaults renders byte-identical hero markup to v0.6.3 (no theme/width
   classes emitted).
2. `hero_theme=dark` on a light page renders a dark band with WCAG-passing text/links
   (smart dark tokens), and `hero_theme=light` inside a dark page renders light.
3. `hero_width=full` bleeds the band edge-to-edge with no horizontal scrollbar at any
   viewport width; hero text stays aligned with `.pre-body` content edges.
4. Saving a CPT definition change is visible on the next front-end load (cache
   invalidation works) without waiting out the TTL.
5. Options are writable via admin UI, REST connector, and MCP tools; invalid enum
   values are rejected by the validator with a clear error.
6. Non-Promptless themes: forced hero themes still work (they depend only on `--aisb-*`
   fallbacks); `inherit` remains light as today.
