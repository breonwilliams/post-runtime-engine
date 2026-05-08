# AISB Token Contract — Post Runtime Engine (consumer side)

**Status:** Ratified for v0.2.0 (after Phase 4 audit, 2026-05-08)
**Consumer:** Post Runtime Engine
**Producer:** Promptless WP plugin (AI Section Builder Modern)
**Minimum compatible producer version:** Promptless WP `1.3.0+`
**Sister contract:** [`form-runtime-engine/docs/AISB_TOKEN_CONTRACT.md`](../../form-runtime-engine/docs/AISB_TOKEN_CONTRACT.md) — Form Runtime Engine consumes the same producer with overlapping token set

---

## Purpose

Post Runtime Engine **inherits brand styling from Promptless WP when active** and **degrades gracefully to sensible defaults when not**. It does this by reading a small set of CSS custom properties (design tokens) emitted by Promptless WP's Global Settings.

This document is the **public contract** between the two plugins. It is the authoritative list of `--aisb-*` tokens that Post Runtime Engine reads. No other tokens are consumed without first updating this file.

---

## Architectural pattern: intermediate variables

Inside Post Runtime Engine's frontend CSS, references to `--aisb-*` tokens flow through a small set of intermediate variables (`--pre-color-*`, `--pre-card-*`) declared on the `.pre-single` element. The intermediates make mode switching cheap — when a parent applies `.aisb-section--dark` or the body gets `aisb-neo-brutalist-cards`, only the intermediates change; the rest of the file is mode-blind.

Example:

```css
/* Light mode (default) */
.pre-single {
    --pre-color-text: var(--aisb-color-text, #1f2937);
    --pre-color-surface: var(--aisb-color-surface, #f9fafb);
    /* … */
}

/* Dark mode override — flips the intermediates only */
.aisb-section--dark .pre-single {
    --pre-color-text: var(--aisb-color-dark-text, #fafafa);
    --pre-color-surface: var(--aisb-color-dark-surface, #2a2a2a);
    /* … */
}

/* Neo-brutalist override — flips the card chrome only */
body.aisb-neo-brutalist-cards .pre-single {
    --pre-card-border-width: var(--aisb-neo-border-width, 4px);
    --pre-card-shadow: var(--aisb-neo-shadow-offset, 8px) var(--aisb-neo-shadow-offset, 8px) 0 var(--aisb-neo-brutalist-primary-border, #000000);
}

/* Every other rule reads the intermediates — mode-blind */
.pre-grouping__heading { color: var(--pre-color-text); }
.pre-grouping__item    { background: var(--pre-color-surface); border: var(--pre-card-border-width) solid var(--pre-color-border); box-shadow: var(--pre-card-shadow); }
```

This mirrors how Form Runtime Engine consumes the same tokens.

---

## Contract Terms

**Promptless WP (producer) promises:**

1. Every token listed in the Consumed Tokens table will continue to be emitted on any page where Promptless WP is active, for as long as the contract version is supported.
2. A token's semantic meaning will not change between minor versions.
3. Removal or rename is preceded by at least one minor version of deprecation notice; the old token is emitted as an alias during the deprecation window.

**Post Runtime Engine (consumer) promises:**

1. Every `--aisb-*` reference in `assets/css/frontend.css` provides a fallback that produces a usable rendering when the token is absent. Direct references that don't hit the fallback (because they read through `--pre-*` intermediates) keep the fallback at the intermediate's declaration site.
2. No `--aisb-*` token is read anywhere else in the plugin (no JS, no PHP, no other CSS files) without updating this document first.
3. New token consumption requires: (a) adding it to the table below, (b) pinning the minimum producer version, (c) supplying a fallback.
4. The plugin functions at minimum-viable visual quality with all tokens absent (vanilla WordPress, no Promptless).

---

## Consumed Tokens

These are the tokens actually read by `assets/css/frontend.css` as of v0.2.0. The **Fallback** column is the value used when Promptless WP is inactive or the token is otherwise not defined; changing a fallback is a breaking change for standalone styling.

### Core Colors (`--aisb-color-*`)

Read directly into the `--pre-color-*` intermediates on `.pre-single`.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-primary` | Brand primary; link color, focus ring, icon tint, arrow indicator | `#6366f1` |
| `--aisb-color-text` | Body text (light mode) | `#1f2937` |
| `--aisb-color-text-muted` | Secondary text — supporting text in groupings, hero excerpt, related-posts captions | `#6b7280` |
| `--aisb-color-background` | Container background (light mode) | `#ffffff` |
| `--aisb-color-surface` | Card backgrounds (light mode) | `#f9fafb` |
| `--aisb-color-border` | Card borders, dividers (light mode) | `#e5e7eb` |

### Dark Mode Colors (`--aisb-color-dark-*`)

Activated when the post is wrapped in `.aisb-section--dark` (Promptless's standard dark-mode marker). Smart tokens preferred for muted/border (WCAG-correct contrast against the dark surface), with dark-color tokens as the next fallback.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-dark-text` | Body text (dark mode) | `#fafafa` |
| `--aisb-color-dark-text-muted` | Secondary text (dark mode) — used as the inner fallback for the smart variant | `#9ca3af` |
| `--aisb-color-dark-background` | Container background (dark mode) | `#1a1a1a` |
| `--aisb-color-dark-surface` | Card backgrounds (dark mode) | `#2a2a2a` |
| `--aisb-color-dark-border` | Card borders (dark mode) — used as the inner fallback for the smart variant | `#4b5563` |
| `--aisb-smart-dark-surface-muted` | WCAG-corrected muted text against dark surface; falls back to `--aisb-color-dark-text-muted` | `#9ca3af` |
| `--aisb-smart-dark-surface-border` | WCAG-corrected border against dark surface; falls back to `--aisb-color-dark-border` | `#4b5563` |

### Smart Link Tokens (`--aisb-smart-*-link*`)

PRE consumes Promptless's smart-link system to keep link colors and hover treatments contrast-correct in both light and dark mode without requiring per-deployment tuning. The system defines separate tokens for "section-level" links (sitting on the page background) and "surface-level" links (sitting on card surfaces), and for each it pairs a base color with a hover-brightness multiplier applied via CSS `filter: brightness()`.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-smart-light-section-link` | Link color on light page backgrounds — body content, compact-grid/horizontal-row item hover destinations; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-light-surface-link` | Link color on light card surfaces — card-grid/featured-card item hover destinations, footer link hover, cta-arrow, focus outline; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-light-link-hover-brightness` | Brightness multiplier for hover on section-level light links; values <1 darken | `0.75` |
| `--aisb-smart-light-surface-link-hover-brightness` | Brightness multiplier for hover on surface-level light links; values <1 darken | `0.75` |
| `--aisb-smart-light-icon` | Icon color on light page backgrounds — compact-grid/horizontal-row decorative icons; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-light-surface-icon` | Icon color on light card surfaces — card-grid/featured-card decorative icons; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-dark-section-link` | Link color on dark page backgrounds; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-dark-surface-link` | Link color on dark card surfaces; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-dark-link-hover-brightness` | Brightness multiplier for hover on section-level dark links; values >1 brighten | `1.2` |
| `--aisb-smart-dark-surface-link-hover-brightness` | Brightness multiplier for hover on surface-level dark links; values >1 brighten | `1.2` |
| `--aisb-smart-dark-icon` | Icon color on dark page backgrounds; falls back to `--aisb-color-primary` | `#6366f1` |
| `--aisb-smart-dark-surface-icon` | Icon color on dark card surfaces; falls back to `--aisb-color-primary` | `#6366f1` |

PRE wraps each smart token in an intermediate `--pre-color-link` / `--pre-color-surface-link` / `--pre-color-icon` / `--pre-color-surface-icon` / `--pre-link-hover-brightness` / `--pre-surface-link-hover-brightness` so the dark-mode block under `.aisb-section--dark .pre-single` flips the entire chain in one place. Promptless WP overrides these tokens at runtime — its `SmartColorManager` (when `smart_accessibility_colors` is enabled in global settings) computes contrast-corrected variants based on the user's brand primary, the actual surface color, and the relevant WCAG target (4.5:1 for links/text, 3:1 for icons via 1.4.11 non-text contrast). The output goes to `:root` via `wp_head` priority 11 globally on every page, so it reaches PRE-rendered post pages even though they aren't AISB sections.

> **Note on Promptless's smart-colors toggle:** the runtime contrast correction is opt-in via Promptless WP → Settings → Smart Accessibility Colors. When the toggle is off, `SmartColorManager` does not emit overrides and PRE's smart-* references fall through the chain to the raw `--aisb-color-primary`. That's the correct behavior — a user with a sufficiently-contrasting brand primary may not need correction. The setup docs flag the toggle for deployers who pick an intentionally low-contrast brand color and want PRE to compensate.

### Layout & Spacing (`--aisb-section-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-max-width` | Max content width of `.pre-single` container | `1280px` |
| `--aisb-section-space-sm` | Small spacing — inline gap, card-internal gap | `1rem` |
| `--aisb-section-space-md` | Medium spacing — default card padding, primary content gap | `1.5rem` |
| `--aisb-section-space-lg` | Large spacing — between hero and body, between groupings | `2rem` |
| `--aisb-section-space-xl` | Extra-large spacing — page padding, footer top margin | `3rem` |

### Typography (`--aisb-section-font-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-font-body` | Body font family — `.pre-single` base | System font stack |
| `--aisb-section-font-heading` | Heading font family — hero title, grouping labels, group headings, footer heading | `inherit` |

### Radii (`--aisb-section-radius-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-radius-card` | Card border-radius — all three card variants | `8px` |
| `--aisb-section-radius-image` | Image border-radius — hero image, related-posts thumbnails (cards use overflow:hidden so the inner image inherits the card radius) | `8px` |

### Motion (`--aisb-section-transition-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-transition-base` | Default transition — link hover color, related-posts title hover | `200ms ease` |

### Neo-Brutalist Mode (`--aisb-neo-*`, `--aisb-neo-brutalist-*`)

Activated when the body has the `aisb-neo-brutalist-cards` class (Promptless's master toggle). Switches card chrome to bold borders + offset shadows.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-neo-border-width` | Card border thickness in brutalist mode | `4px` |
| `--aisb-neo-shadow-offset` | Card box-shadow offset (both x and y) | `8px` |
| `--aisb-neo-brutalist-primary-border` | Card border + box-shadow color in brutalist mode | `#000000` |

---

## Where tokens are read

| File | Tokens used |
|------|-------------|
| `assets/css/frontend.css` | All tokens listed above |
| `assets/css/admin.css` | **None.** The admin UI uses fixed editor styling, parallel to Promptless's `--aisb-editor-*` separation. Admin chrome is internal tooling, not user-facing brand surface. |

---

## Visual quality bar without Promptless WP

The plugin must render at minimum-viable quality when Promptless is not active. "Minimum-viable" means:

- Layout structure preserved (hero / body / sidebar / footer)
- All four grouping variants render correctly with fallback values
- Text legible (sufficient contrast against the white fallback background)
- Cards have visible 1px borders and subtle gray surface
- Dark mode works if triggered by an `.aisb-section--dark` ancestor (using fallback dark colors)
- No JS errors, no broken images, no layout collapse

The plugin does NOT need to look polished or branded without Promptless. The fallbacks are for graceful degradation, not for replacing the brand styling.

---

## `!important` exceptions

The plugin's CSS is `!important`-free with one documented exception in `assets/css/admin.css`:

```css
.pre-item--placeholder {
    background: #eef2ff !important;
    border: 1px dashed #a5b4fc !important;
    box-shadow: none !important;
}
```

These three rules style the jQuery UI Sortable placeholder (the visual ghost shown while dragging an item). jQuery UI sortable applies inline styles via JavaScript at drag-start that override class-based rules; `!important` is the only mechanism that wins against runtime-injected inline styles. This exception is contained to drag-state visuals only — no production rendering uses `!important`.

The `frontend.css` file (everything user-facing) has zero `!important` and zero hardcoded brand colors.

---

## When this contract changes

**Adding a new token to consume:**

1. Add it to the table above with purpose + fallback
2. Pin the minimum producer version at the top of this doc
3. Add a coordination note for Promptless WP's maintainers (update `ai-section-builder-modern/docs/development/CONNECTOR_KNOWLEDGE_MAP.md` if such tracking exists)
4. Add the CSS reference with the fallback (or wire through an existing intermediate)

**Removing a consumed token:**

1. Stop using it in CSS
2. Remove the row from this document
3. (No producer-side action — Promptless can keep emitting it for other consumers)

**Renaming (producer-side):**

1. Promptless emits both old and new names during the deprecation window
2. PRE updates its CSS to use the new token name
3. After all known consumers have migrated, Promptless removes the old token

This matches Form Runtime Engine's contract versioning protocol.
