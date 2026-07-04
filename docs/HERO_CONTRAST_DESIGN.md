# Hero Contrast & Width — Design Contract

**Status:** Phase A locked 2026-07-04 and SHIPPED (validator, renderer, CSS, admin,
connector, MCP — smoke-tested end-to-end). Phase B locked 2026-07-04, not yet
implemented. Implementation must match this contract; changes to the contract
require editing this document first (same discipline as
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

## Implementation lessons from Phase A (binding on Phase B)

Three bugs found in Phase A smoke testing that Phase B must not re-introduce:

1. **Theme-interop variables.** The Promptless theme styles headings/links inside
   `.promptless-content` via its OWN `--section-*` intermediates at higher
   specificity than `.pre-hero__*` element classes. Any hero treatment that forces
   a mode must re-declare the theme's `--section-*` set alongside `--pre-*`
   (see the `.pre-hero--theme-dark` block in `frontend.css`).
2. **Overflow clipping placement.** `overflow-x: clip` on `.pre-single` clips the
   full-bleed breakout itself (clipping affects painting, not layout — rects
   measure fine while the paint is wrong). Viewport-sliver containment lives on
   `body:has(.pre-single--hero-full)`.
3. **Cascade order vs. the container shorthand.** `.pre-single { padding: ... }`
   lives late in the file; any earlier rule zeroing part of that padding needs
   compound-selector specificity (`.pre-single.pre-single--hero-full`), not
   source-order luck.

---

# Phase B — Overlay hero layout (`hero_layout: overlay`)

**Status:** Locked 2026-07-04. Not yet implemented.

## Problem

Phase A's band puts the featured image *beside or above* the text on a colored
surface. For image-rich CPTs (real estate listings, events, venues, portfolios) the
premium agency treatment puts text *on* the image: the featured image fills the hero
band and the title/price/badges/meta sit on top, kept readable by a gradient scrim.

## Scope

ONE new enum value: `hero_layout` gains `overlay` (`stacked | split | overlay`), plus
ONE new optional CPT key:

| Key | Enum | Default | Meaning |
|---|---|---|---|
| `hero_overlay_focus` | `top` \| `center` \| `bottom` | `center` | Which part of the featured image stays visible when the band crops it (`object-position`). `top` keeps rooflines/skies, `bottom` keeps foregrounds. Only meaningful when `hero_layout=overlay`. |

Explicit non-goals (future contracts if demanded): scrim-strength knob, text-position
knob (bottom-left is fixed), per-post overlay opt-out, video backgrounds, parallax.

## Rendering contract

`render_hero()` gains an `overlay` branch emitting:

```html
<header class="pre-hero pre-hero--overlay pre-hero--focus-{focus} [pre-hero--full] pre-hero--has-image">
  <div class="pre-hero__inner">
    <div class="pre-hero__backdrop">          <!-- absolute, fills band -->
      <img class="pre-hero__image" ...>       <!-- size 'full', object-fit: cover -->
    </div>
    <div class="pre-hero__scrim" aria-hidden="true"></div>
    <!-- image_overlay post-field position: top-left of the band (unchanged semantics) -->
    <div class="pre-hero__text pre-hero__text--overlay">  <!-- bottom-left, above scrim -->
      headline → h1 → subtitle → excerpt → meta_strip → footer_meta  (same order as today)
    </div>
  </div>
</header>
```

- **Text is ALWAYS dark-mode-tokened** in the with-image case: the overlay branch
  applies the same `--pre-*` + `--section-*` re-declarations as
  `.pre-hero--theme-dark`, because text sits on a darkened photograph regardless of
  page mode. `hero_theme` is IGNORED when overlay has an image (documented
  precedence, enforced in CSS not PHP — the theme class may still be emitted but the
  overlay rules win).
- **No-featured-image fallback:** the hero renders exactly as `stacked` does today
  (text-only, honoring `hero_theme`/`hero_width`). Never an empty dark box. This is
  a renderer-level branch: `overlay && ! has_post_thumbnail()` → render the stacked
  path. Markup for imageless posts is therefore identical to Phase A output.
- **Band height:** `clamp(320px, 52vh, 560px)` desktop; `clamp(260px, 45vh, 420px)`
  under 768px. Values may be tuned during implementation within acceptance
  criterion B3 (text never clipped, band never exceeds first viewport).
- **Composes with `hero_width`:** `contained` renders the overlay as a rounded card
  (`--aisb-section-radius-card`) inside the grid; `full` is the full-bleed cinematic
  version using Phase A's breakout mechanics unchanged.

## Scrim contract (the WCAG guarantee)

- Fixed bottom-weighted gradient on `.pre-hero__scrim`:
  `linear-gradient(180deg, rgba(10,14,18,0.25) 0%, rgba(10,14,18,0.55) 30%, rgba(10,14,18,0.80) 55%, rgba(10,14,18,0.92) 100%)`.
  (Amended during implementation: the originally-drafted 0.18/0.30/0.86 stops
  failed acceptance B2 in smoke testing — a tall text block puts the title at
  ~35% band height, where 0.28 opacity measured ~2:1 over a white test image.
  The amended stops guarantee ≥3:1 (AA large text) anywhere the title/headline
  can reach (~25% down) and ≥4.5:1 (AA normal text) from the title line down,
  where excerpt/meta always sit. `.pre-hero__text--overlay` additionally
  carries `text-shadow: 0 1px 3px rgba(10,14,18,0.55)` as belt-and-suspenders
  for pathological images.)
- Scrim constants are **deliberately palette-independent** (literal near-black rgba,
  NOT derived from `--aisb-color-dark-background`): the contrast guarantee must hold
  for any uploaded photo and any user palette. A site palette with a light "dark
  background" must not be able to break hero text contrast.
- Text tokens over the scrim use the standard dark set (`--aisb-color-dark-text`
  etc.) — the bottom scrim stop (0.86 near-black) guarantees ≥ WCAG AA for
  `#fafafa`-class text over ANY image content.
- The gradient is exempt from the plugin's no-gradient aesthetic conventions: it is
  a functional contrast device, not decoration.

## Data-layer contract

- `PCPTPages_Validator`: `'overlay'` added to the `hero_layout` inline enum; new
  `HERO_OVERLAY_FOCUS` const + validation block for `hero_overlay_focus`.
- `merge_defaults()`: `hero_overlay_focus => 'center'`. No migration, no data-version
  bump (additive, behavior-preserving default).
- `hero_image_position` / `hero_image_aspect` remain split-only; overlay ignores them
  (validator continues to accept them regardless of layout, per existing policy).

## Surface wiring checklist (same nine points as Phase A — silent-drop gates ⚠️)

- [ ] Validator: enum extension + `HERO_OVERLAY_FOCUS` + validation block
- [ ] Registry `merge_defaults()`
- [ ] Renderer: overlay branch, no-image fallback branch, focus class,
      `loading="eager"` + `fetchpriority="high"` on the overlay image (it is the LCP
      element by construction; mirrors Promptless WP's hero LCP handling)
- [ ] `frontend.css`: `.pre-hero--overlay` block (backdrop/scrim/text positioning,
      heights, focus variants, contained-radius vs full-bleed) with the Phase A
      lessons applied
- [ ] Admin CPTs screen: `overlay` option in the Hero layout select + focus select
      (shown for all layouts, described as overlay-only, matching how
      image_position/aspect describe themselves as split-only)
- [ ] ⚠️ Connector `field_name_hints()['cpt_definition']`: add `hero_overlay_focus`
- [ ] Connector `critical_rules`: extend `hero_contrast_band` with overlay guidance
      (when to pick overlay vs dark band; no-image fallback; hero_theme precedence)
- [ ] ⚠️ MCP JS: `hero_layout` enum in BOTH tool schemas, `hero_overlay_focus` in
      schemas AND both body-allowlist arrays
- [ ] Cache invalidation: already covered by the Phase A `pcptpages_cpt_registered`
      hook — no new work

## Acceptance criteria (Phase B)

B1. Existing CPTs (`stacked`/`split`) render byte-identical markup — `overlay` is
    pure opt-in.
B2. Overlay with an image: title/meta text passes WCAG AA over a worst-case bright
    image (verify with a white test image); text sits bottom-left, aligned with the
    page grid in both `contained` and `full` widths.
B3. Band height respects the clamp on desktop and mobile; text never clips; the band
    never pushes all content below the first viewport.
B4. Overlay without a featured image renders the stacked fallback honoring
    `hero_theme` — no empty band, no layout shift artifacts.
B5. `hero_overlay_focus` visibly shifts crop (top/center/bottom) on a tall test image.
B6. Overlay image carries `loading="eager"`/`fetchpriority="high"`; no layout shift
    (band height is fixed by CSS, not by image intrinsic size).
B7. All three write surfaces (admin, REST, MCP) accept the new values; invalid values
    rejected with typed errors.
