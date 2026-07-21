# Gallery Grouping Variant — Design Contract (v1, FINAL)

**Status: AGREED — decisions delegated to assistant by founder 2026-07-18,
resolved on performance/accessibility data and 2026 industry standards
(§8 records each decision + grounding). This contract is the source of
truth for the build phase. Disagreements get resolved by editing this
document, not by writing different code.**

Per the post-launch maintenance constraints, extending
`PCPTPages_Validator::VARIANTS` requires an explicit architectural
conversation. This document is that conversation's artifact.

**Date:** 2026-07-18
**Origin:** Directory/listing-site exploration (car listing case study). The
gap: a listing needs 6+ supporting photos with a lightbox; post fields are
deliberately scalar and no grouping variant renders a photo grid.

---

## 1. Decision: a fifth layout variant, not a new field type

Three approaches were assessed:

| Approach | Verdict |
|---|---|
| New post-field display type `gallery` | ❌ Violates the locked scalar contract (POST_FIELDS_V1_1_DESIGN.md). Post fields are single values by design. |
| Third field type (media collection) | ❌ Heaviest option: new registry, meta box, REST/MCP surface, and the constraints gate third field types on real client demand. |
| **Fifth grouping layout variant `gallery`** | ✅ Architectural decision #6 says variants are "CSS treatments of the same data." Grouping items already carry `image_id`. The repeater UI, per-post values, source modes, and connector CRUD all exist and are reused unchanged. |

The gallery variant renders a grouping's items as a responsive photo grid
with a lightbox — visually aligned with Promptless WP's Gallery section so
the two plugins read as one design system on the same site.

## 2. Item-shape mapping (the constrained primitive, unchanged)

The item shape stays `{image-or-icon, heading, supporting_text, link}`.
The variant reinterprets it, mirroring the existing icon-only precedent
(compact-grid/horizontal-row drop `image_id` at render; gallery is the
exact inverse):

| Item field | Gallery treatment |
|---|---|
| `image_id` | **Required at render** — items without one are skipped (connector emits a warning, mirroring existing non-fatal term warnings) |
| `icon_id` | Dropped at render (inverse of the icon-only rule; documented in the same `critical_rules` style as `compact_grid_strips_image`) |
| `heading` | Optional caption (rendered as `<figcaption>` under the tile and in the lightbox; empty = image-only tile, no empty caption element emitted) |
| `supporting_text` | Not rendered (connector allowlist omits it) |
| `link` / `link_post_id` | **Not rendered** — the tile click opens the lightbox. One click target per tile; the variant's intent (viewing photos) overrides per-item links, exactly as variant intent already overrides media shape elsewhere. |

Alt text comes from the WordPress attachment's alt field (the correct
canonical source) — items do not grow an alt field. The connector's
`critical_rules` entry instructs agents to set attachment alt text when
uploading gallery images (WCAG 1.1.1).

## 3. Grid + visual treatment

- Mirrors the Promptless WP Gallery section's stylization via `--aisb-*`
  tokens with documented fallbacks (radius from `--aisb-section-radius-image`,
  spacing from the space scale, 16:9 tiles per the AISB gallery default).
  Every new token consumed gets added to `docs/AISB_TOKEN_CONTRACT.md`
  FIRST, per the token-contract constraint.
- **Tile aspect ratio — definition-level `gallery_image_aspect`**
  (amended 2026-07-21, see §8-D2a): `16:9` default | `4:3` | `1:1` | `4:5`,
  reusing the validator's `ARCHIVE_IMAGE_ASPECTS` vocabulary so the plugin
  speaks ONE aspect language across archive cards, AISB sections, and
  gallery tiles. Tiles only — the lightbox always shows the full
  uncropped image. Rendered as a `pre-gallery--aspect-{a}-{b}` modifier
  class on the grid; conditional select in the grouping definition form
  (visible only when the default variant is Gallery).
- **Fixed responsive columns — 3 desktop / 2 tablet / 2 mobile** (§8-D2).
- All three positions allowed (`above_main`, `below_main`, `sidebar`) — the
  sidebar renders single-column thumbnails via CSS; no enum change and no
  position restrictions to teach.
- Tiles are `<figure>` elements; captions are `<figcaption>` —
  programmatic association for assistive tech, not visual-only labels.

## 4. Lightbox

PRE ships its own small vanilla lightbox (`assets/js/pre-lightbox.js`),
modeled on the AISB gallery lightbox's behavior contract but PRE-owned:
the CSS-token-only coupling rule forbids depending on AISB's asset, and
PRE must work when Promptless WP is inactive (same pattern FRE follows).

Conditionally enqueued (deferred, no dependencies) only when a rendered
page contains at least one gallery-variant grouping — the existing
asset-gating philosophy: a page never ships assets it can't use.

**Always-on** — no per-grouping toggle (§8-D5).

## 5. Sources, caps, admin UX

- **Source modes:** all four work unchanged. `manual` is the primary path.
  Auto-sources (`child_posts`, `taxonomy_match`, `meta_match`) resolve each
  matched post's featured image as the item image — "photos of every unit
  in this building" falls out for free; matched posts without a featured
  image are skipped at render.
- **Item cap: soft advisory of 24 in connector guidance; no hard cap**
  (§8-D3). Performance is protected structurally (§9), not by rationing.
- **Meta box: the one UX investment.** When the effective variant is
  `gallery`, the meta box gains an "Add Images" button opening `wp.media`
  in multi-select mode, creating one item per selected image in one step
  (additive — items remain individually sortable/removable/captionable
  afterward). The existing variant-aware meta box logic (it already hides
  image controls for icon-only variants) is the hook point.

## 6. Connector + validation surface

- `PCPTPages_Validator::VARIANTS` += `'gallery'`; per-variant item
  validation: `icon_id` rejected (mirror of image-rejection messaging),
  missing `image_id` accepted-with-warning (render skips).
- `variant_item_fields['gallery'] = ['heading', 'image_id']` in the
  connector guidance map.
- New `critical_rules` entry (`gallery_variant`) documenting: image
  required, links/supporting_text not rendered, caption behavior, the
  attachment-alt-text requirement, the auto-source featured-image
  behavior, and the soft ~24 item guidance.
- Preflight variant catalog gains the `gallery` entry (same shape as the
  existing four).
- Data-version bump; `docs/CONNECTOR_SPEC.md` + `docs/CAPABILITIES.md`
  updated in the same commit (the doc-sync rule).

## 7. Out of scope (explicitly)

- WooCommerce anything (assessed 2026-07-18: directories are lead-gen;
  sellable items use Promptless WP's product_grid + real Woo products —
  no PRE integration, no exceptions without real client demand).
- Masonry/justified layouts, video items, zoom/pan — the constraint is the
  feature; 16:9 grid + lightbox covers the verticals PRE targets.
- ImageObject schema markup for gallery images — noted as a possible
  v-next addition to `PCPTPages_Meta_Tags`, not in this scope.
- A third field type. This variant existing is the argument that one
  isn't needed yet.

## 8. Resolved decisions (delegated 2026-07-18, grounding recorded)

- **D1 — Name: `gallery`.** Matches the WordPress core Gallery block and
  the Promptless WP Gallery section — the term users and AI agents already
  hold. A novel name (`photo-grid`) would add vocabulary for zero gain.
- **D2 — Columns: fixed responsive 3/2/2, no setting.** The
  constrained-primitive discipline ("the constraint is the feature")
  says no new knobs without demonstrated need. 3-up desktop is the
  dominant listing-gallery pattern (real-estate/auto directories);
  2-up on phones keeps 16:9 tiles above the WCAG 2.2 §2.5.8 24px minimum
  target size with comfortable margin and halves page height vs 1-up.
  A columns knob can be added later via a filter without breaking this
  contract; the reverse (removing a shipped setting) breaks users.
- **D2a — Aspect ratio: definition-level setting; columns stay fixed**
  (amendment, 2026-07-21). Follow-up review against the AISB Gallery
  section's controls (columns / aspect / styling / row alignment) split
  the four: `gallery_image_aspect` was ADDED because aspect is a
  content-shape decision (property photos are 4:3, portraits 4:5,
  products 1:1 — cropping everything to 16:9 damages real inventories)
  and the vocabulary already existed (`ARCHIVE_IMAGE_ASPECTS`), so it's
  vocabulary reuse rather than a new knob. Columns remain fixed per D2
  (a pure layout preference with a good universal answer), and styling /
  row-alignment controls were declined (token inheritance already
  handles styling; row alignment is meaningless in a uniform grid).
- **D3 — Cap: soft advisory 24, no hard cap.** Real listing inventories
  legitimately run 20–40 photos (MLS norms); a hard cap would be an
  arbitrary ceiling users hit angrily. Performance is protected by
  structure instead: lazy-loading, grid-sized srcset, and on-demand
  full-size loading (§9) make item count nearly cost-free at page load.
- **D4 — Captions: visible `<figcaption>` under tiles when heading is
  present.** Matches the AISB gallery treatment (design-system
  consistency), gives context that alt text alone doesn't surface
  visually, and costs nothing when headings are empty (no element
  emitted). Lightbox shows the same caption.
- **D5 — Lightbox: always-on, no toggle.** Users universally expect photo
  grids in listings to expand; a toggle is a knob whose "off" state is a
  worse product. AISB's gallery keeps its toggle for general-purpose page
  building; PRE's variant is purpose-built for listing media where the
  expectation is unambiguous. Zero-config wins.

## 9. Performance requirements (Core Web Vitals, 2026)

Binding on the implementation — these are what make D3's "no hard cap"
safe:

1. **CLS = 0 by construction:** tiles reserve space via CSS
   `aspect-ratio: 16 / 9` — no layout shift as images arrive.
2. **Lazy tiles:** `loading="lazy"` + `decoding="async"` on all tile
   images. Gallery groupings render below the CPT hero, so no tile is an
   LCP candidate; lazy-loading everything is correct (never lazy-load the
   LCP element — the hero already handles that rule).
3. **Grid-aware responsive sizing:** tiles render via
   `wp_get_attachment_image()` with a `sizes` attribute matched to the
   3/2/2 grid math, so browsers download tile-sized files, not
   full-resolution originals (the same grid-aware sizing discipline
   Promptless WP shipped in its 1.4.7 performance release).
4. **Full-size on demand:** the lightbox loads the full-size image only
   when opened, and prefetches only the adjacent (next/prev) images.
   24 tiles ≈ 24 thumbnails at page load, never 24 originals.
5. **JS cost:** one dependency-free deferred script, enqueued only on
   pages that render a gallery grouping; zero JS when the variant is
   unused anywhere.

## 10. Accessibility requirements (WCAG 2.2 AA, 2026 baseline)

Binding on the implementation. WCAG 2.2 AA is the current legal/commercial
baseline (EU EAA enforcement since 2025-06; ADA title II rulemaking):

1. **Tiles are buttons:** keyboard-operable (`Enter`/`Space`), visible
   focus indicator from the design system's focus tokens, accessible name
   from the image alt/caption. Minimum 24×24px target (§2.5.8) — 2-up
   16:9 tiles on a 360px viewport are ~160×90px, comfortably above.
2. **Lightbox follows the WAI-ARIA APG dialog contract** (the same
   contract AISB's modal.js implements in-house): `role="dialog"`,
   `aria-modal="true"`, labelled by the image caption/counter, focus
   moves in on open, Tab is trapped, `Escape` closes, focus returns to
   the triggering tile on close.
3. **Drag alternatives (§2.5.7):** touch swipe is an enhancement, never
   the only path — visible previous/next buttons (≥24px targets) and
   arrow-key navigation are the baseline controls.
4. **`prefers-reduced-motion`:** transitions collapse to instant
   show/hide; no parallax/zoom effects.
5. **Alt text:** sourced from the attachment; connector guidance requires
   agents to populate it at upload. Empty alt renders `alt=""`
   (decorative) rather than filename junk.
6. **Captions:** `<figure>`/`<figcaption>` association; the lightbox
   announces "Image X of Y" plus the caption via an `aria-live="polite"`
   region on navigation.
7. **Scroll lock without layout shift:** body scroll locked while open,
   preserving scrollbar gutter (the modal.js technique).

## 11. Estimated shape

One focused phase (~8–12h by the historical phase table): validator +
renderer branch + CSS + lightbox JS + meta box multi-add + connector
guidance/preflight + unit tests for the new render branch. No data
migrations (existing groupings are untouched; the variant is opt-in).
Acceptance: the §9/§10 requirement lists double as the test checklist.
