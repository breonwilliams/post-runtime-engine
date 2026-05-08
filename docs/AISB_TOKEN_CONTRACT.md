# AISB Token Contract — Post Runtime Engine (consumer side)

**Status:** Draft (planning phase). Final token list will be confirmed during Phase 4 once actual CSS is written.
**Consumer:** Post Runtime Engine
**Producer:** Promptless WP plugin (AI Section Builder Modern)
**Minimum compatible producer version:** Promptless WP `1.3.0+`
**Sister contract:** `form-runtime-engine/docs/AISB_TOKEN_CONTRACT.md` — Form Runtime Engine consumes the same producer with overlapping token set

---

## Purpose

Post Runtime Engine is designed to **inherit brand styling from Promptless WP when it is active** and to **degrade gracefully to sensible defaults when it is not**. It does this by reading a small set of CSS custom properties (design tokens) emitted by Promptless WP's Global Settings.

This document is the **public contract** between the two plugins. It is the authoritative list of `--aisb-*` tokens that Post Runtime Engine reads. No other tokens are consumed, and every token listed here has a documented fallback so the plugin never breaks when Promptless is absent, deactivated, or running an older version that does not yet emit a given token.

This is the second contract Promptless WP has with consumer plugins (Form Runtime Engine has its own contract). The producer-side commitment is the same; the consumer-side commitment lives in this document.

---

## Contract Terms

**Promptless WP (producer) promises:**

1. Every token listed in the Consumed Tokens table below will continue to be emitted on any page where Promptless WP is active, for as long as the contract version in this document is supported.
2. A token's semantic meaning (what it represents visually) will not change between minor versions. Values may be re-calculated; meanings will not be repurposed.
3. Removal or rename of a listed token will be preceded by at least one minor version's worth of deprecation notice, and the old token will continue to be emitted (as an alias) for the full deprecation window.

**Post Runtime Engine (consumer) promises:**

1. Every `var(--aisb-*, <fallback>)` in `assets/css/frontend.css` and `assets/css/variants/*.css` provides a fallback that produces a usable rendering when the token is absent.
2. No `--aisb-*` token will be read anywhere else in the plugin (no JS reads, no PHP reads, no additional CSS files) without updating this document first.
3. New token consumption requires: (a) adding the token to the table below, (b) pinning the minimum producer version, (c) supplying a fallback.
4. The plugin must function at minimum-viable visual quality with all tokens absent (i.e., on a vanilla WordPress install with no Promptless WP).

---

## Consumed Tokens

> **Note:** This list is **provisional** during planning. Phase 4 will audit the actual CSS and finalize the token list. Tokens in this draft reflect what the plugin is *expected* to consume based on the four layout variants (compact grid, card grid, featured card, horizontal row) and the base template structure (hero, body, sidebar, footer).

Tokens are grouped by functional area. The **Fallback** column is the value used when Promptless WP is inactive or the token is otherwise not defined; changing a fallback is a breaking change for standalone plugin styling.

### Core Colors (`--aisb-color-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-primary` | Primary brand color; CTA buttons, link colors, focus rings | `#6366f1` |
| `--aisb-color-secondary` | Secondary brand color; accent highlights | `#8b5cf6` |
| `--aisb-color-text` | Body text color (light mode) | `#1f2937` |
| `--aisb-color-text-muted` | Secondary text (supporting text in groupings, dates, captions) | `#6b7280` |
| `--aisb-color-text-inverse` | Text on dark backgrounds (button labels, badges) | `#ffffff` |
| `--aisb-color-background` | Page / template background (light mode) | `#ffffff` |
| `--aisb-color-surface` | Card backgrounds, sidebar surface (light mode) | `#f9fafb` |
| `--aisb-color-border` | Card borders, dividers, separators (light mode) | `#e5e7eb` |

### Dark Mode Colors (`--aisb-color-dark-*`)

Used when the page is inside a dark-themed wrapper or the user-level theme variant is dark.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-dark-text` | Body text (dark mode) | `#fafafa` |
| `--aisb-color-dark-text-muted` | Secondary text (dark mode) | `#9ca3af` |
| `--aisb-color-dark-background` | Page background (dark mode) | `#1a1a1a` |
| `--aisb-color-dark-surface` | Card backgrounds (dark mode) | `#2a2a2a` |
| `--aisb-color-dark-border` | Card borders (dark mode) | `#4b5563` |

### Smart-Color Chain (`--aisb-smart-*`)

Promptless WP calculates these WCAG-compliant values from the primary color and surface context. Post Runtime Engine uses them so contrast remains correct against any brand color choice.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-smart-light-section-link` | Link color in groupings (light surfaces) | `var(--aisb-color-primary, #6366f1)` |
| `--aisb-smart-light-surface-border` | Card border on light surfaces (3.0:1 contrast) | `var(--aisb-color-border, #e5e7eb)` |
| `--aisb-smart-light-surface-muted` | Muted text on light surfaces (4.5:1 contrast) | `var(--aisb-color-text-muted, #6b7280)` |
| `--aisb-smart-dark-section-link` | Link color in groupings (dark surfaces) | `var(--aisb-color-primary, #6366f1)` |
| `--aisb-smart-dark-surface-border` | Card border on dark surfaces (3.0:1 contrast) | `var(--aisb-color-dark-border, #4b5563)` |
| `--aisb-smart-dark-surface-muted` | Muted text on dark surfaces (4.5:1 contrast) | `var(--aisb-color-dark-text-muted, #9ca3af)` |

### Button Tokens (`--aisb-button-*`)

For CTA buttons in featured-card variants and link-style groupings.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-button-primary-bg` | Primary CTA button background | `var(--aisb-color-primary, #6366f1)` |
| `--aisb-button-primary-text` | Primary CTA button label | `#ffffff` |
| `--aisb-button-primary-hover-bg` | Primary CTA hover background | `#4f46e5` |
| `--aisb-button-primary-hover-text` | Primary CTA hover label | `#ffffff` |

### Typography (`--aisb-section-font-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-font-body` | Body font for grouping text, supporting text, descriptions | System font stack: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif` |
| `--aisb-section-font-heading` | Heading font for grouping headings, post titles, section labels | `var(--aisb-section-font-body)` |
| `--aisb-section-font-button` | Button font family | `var(--aisb-section-font-body)` |
| `--aisb-section-font-button-weight` | Button font weight | `600` |

### Layout & Shape (`--aisb-section-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-radius-card` | Card border-radius (groupings, sidebar surfaces) | `8px` |
| `--aisb-section-radius-button` | Button border-radius | `8px` |
| `--aisb-section-radius-image` | Image border-radius (featured card images, hero featured image) | `8px` |
| `--aisb-section-space-xs` | Extra-small spacing scale | `0.5rem` |
| `--aisb-section-space-sm` | Small spacing scale | `1rem` |
| `--aisb-section-space-md` | Medium (default) spacing scale | `1.5rem` |
| `--aisb-section-space-lg` | Large spacing scale | `2rem` |
| `--aisb-section-space-xl` | Extra-large spacing scale (between major regions: hero / body / footer) | `3rem` |
| `--aisb-section-max-width` | Maximum content width (constrains template wrapper) | `1280px` |

### Neo-Brutalist Mode (`--aisb-neo-*`)

Promptless WP emits these only when Neo-Brutalist mode is enabled in Global Settings. When present, the plugin's groupings render with bold borders and box shadows matching the site's brutalist treatment.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-neo-border-width` | Bold border thickness for cards | `2px` |
| `--aisb-neo-border-color` | Bold border color | `var(--aisb-color-text, #1f2937)` |
| `--aisb-neo-box-shadow` | Brutalist box shadow on cards | `4px 4px 0px var(--aisb-color-text, #1f2937)` |

When Neo-Brutalist mode is OFF, the plugin's CSS uses `var(--aisb-neo-border-width, 1px)` etc., so cards render with subtle 1px borders and no harsh shadows.

---

## Where tokens are read

| File | Tokens used |
|------|-------------|
| `assets/css/frontend.css` | All Core Colors, Smart-Color Chain, Typography, Layout & Shape, Neo-Brutalist |
| `assets/css/variants/compact-grid.css` | Layout & Shape (spacing, radius), Core Colors (text, surface, border) |
| `assets/css/variants/card-grid.css` | Same as compact-grid plus surface backgrounds, neo tokens |
| `assets/css/variants/featured-card.css` | Same as card-grid plus button tokens, image radius |
| `assets/css/variants/horizontal-row.css` | Layout & Shape (spacing only), Core Colors (text, muted) |
| `assets/css/admin.css` | NONE — admin UI uses fixed editor styling, parallel to Promptless's `--aisb-editor-*` separation |

The admin CSS deliberately does NOT consume `--aisb-*` tokens. The admin UI is internal tooling, not user-facing brand surface, and using the editor-fixed styling pattern (parallel to Promptless's `--aisb-editor-*` system) keeps the meta box and admin pages visually consistent regardless of brand customization.

---

## Verification at build time

A CI test (added during Phase 4) greps all CSS files in `assets/css/` for `--aisb-*` references and verifies that:

1. Every reference has a fallback (`var(--aisb-foo, <fallback>)` form, never bare `var(--aisb-foo)`)
2. Every consumed token appears in this contract
3. No tokens listed in this contract are absent from the actual CSS (catches drift)

This test runs on every PR. Failure blocks merge.

---

## Visual quality bar without Promptless WP

The plugin must render at minimum-viable quality when Promptless WP is not active. "Minimum-viable" means:

- Layout structure is preserved (hero / body / sidebar / footer)
- All four grouping variants render correctly with the fallback values
- Text is readable (sufficient contrast against background)
- Cards have visible borders (1px subtle border via fallback)
- Buttons have hover states
- Dark mode works if triggered by the user-level theme variant
- No JavaScript errors, no broken images, no layout collapse

The plugin does NOT need to look polished or branded without Promptless. The fallbacks are for graceful degradation, not for replacing the brand styling.

---

## When this contract changes

Adding a new token to consume:

1. Add the token to this document (table entry + fallback)
2. Pin the minimum producer version in the version row at the top of this doc
3. Open a coordination note with Promptless WP's maintainers (i.e., update `ai-section-builder-modern/docs/development/CONNECTOR_KNOWLEDGE_MAP.md` to note the new consumer dependency)
4. Add the CSS reference with the fallback
5. Update the CI test

Removing a consumed token:

1. Stop using the token in CSS
2. Remove the row from this document
3. Update the CI test
4. (No producer-side action needed — Promptless can keep emitting it for other consumers)

Renaming a token (producer-side change):

1. Promptless WP emits both old and new tokens during the deprecation window
2. This plugin updates its CSS to use the new token name
3. After all known consumers have migrated, Promptless removes the old token

This is the same versioning protocol Form Runtime Engine's contract follows.
