# Post Runtime Engine — Setup Guide

This walkthrough takes you from a fresh plugin activation to your first CPT single page rendering on the frontend. About 15 minutes for the first CPT; subsequent CPTs are faster once the patterns click.

If you'd rather have an AI agent (Claude Cowork) drive the entire setup, see [MCP_CONNECTOR_SETUP.md](MCP_CONNECTOR_SETUP.md) instead.

---

## What you'll build

We'll set up a "Listings" CPT for a real-estate site, with three groupings:

- **Quick specs** (above main content, horizontal-row variant) — bedrooms, bathrooms, sqft, etc.
- **Highlights** (below main content, card-grid variant) — selling-point cards with icons + descriptions
- **Schedule a tour** (sidebar, featured-card variant) — single CTA card

You'll create one example listing post with populated groupings and verify it renders.

---

## Step 1 — Activate the plugin

Upload and activate Post Runtime Engine through **Plugins → Add New** in wp-admin. You should see a new top-level menu item: **Post Runtime**.

If you also have Promptless WP active, your CPT pages will inherit the brand styling you've already configured in Promptless's Global Settings. If not, the plugin uses its documented fallbacks (clean defaults) and your pages still render correctly.

> **Tip — low-contrast brand colors:** if you've picked a brand primary that's intentionally close to your background (a light cream on white, a dark navy on charcoal, etc.), turn on **Promptless WP → Settings → Smart Accessibility Colors**. PRE consumes Promptless's smart link / icon tokens, so once that toggle is on the runtime contrast adjuster keeps icons, link hovers, and focus outlines WCAG-compliant on PRE templates without you having to pick a separate "accessible" version of your brand color.

---

## Step 2 — Register a CPT

1. Navigate to **Post Runtime → Post Types**.
2. Click **Add new**.
3. Fill in the fields:
   - **Slug**: `listing` (snake_case, max 20 chars). This becomes the URL segment, e.g. `/listing/modern-family-home/`.
   - **Singular label**: `Listing`
   - **Plural label**: `Listings`
   - **Supports**: title, editor, thumbnail, excerpt (default selection works for most CPTs)
   - **Public**: yes
   - **Has archive**: yes (lets you have a `/listings/` index page)
   - **Show in REST**: yes (required for the connector and for Promptless WP to discover the CPT)
   - **Menu icon**: `dashicons-admin-home` (optional)
4. Click **Save**.

You should see "Listings" appear as a new top-level menu item in wp-admin sidebar — that's the standard WordPress CPT admin surface. The plugin manages registration; everything else (creating posts, setting categories, etc.) uses WordPress's built-in flows.

---

## Step 3 — Define your first grouping

A grouping is a named cluster of items with a shared layout. We'll start with "Quick specs" — the row of property facts.

1. Navigate to **Post Runtime → Post Types** → **Listing** → **Define groupings**.
2. Click **Add grouping**.
3. Fill in:
   - **Key**: `quick_specs` (snake_case)
   - **Label**: `Quick specs` (shown above the items)
   - **Description**: `Property specs row` (optional, only shown in admin)
   - **Default layout variant**: **Horizontal row** (inline chips for at-a-glance facts)
   - **Default position**: **Above main**
   - **Default source**: **Manual** (we'll add items per post)
   - **Heading required**: yes (each item must have a heading)
4. Click **Save**.

Repeat for the other two groupings:

| Key | Label | Variant | Position | Source | Max items |
|---|---|---|---|---|---|
| `highlights` | Highlights | Card grid | Below main | Manual | (no cap) |
| `cta_card` | Schedule a tour | Featured card | Sidebar | Manual | 1 |

The "featured-card" variant requires `max_items: 1` (the validator enforces this). One card, image-prominent, gets the full sidebar slot.

---

## Step 4 — Create your first listing

1. Navigate to **Listings** → **Add new**.
2. Set the title: `Modern Family Home in Lake View`
3. Set the excerpt: `Recently renovated 4-bedroom home with mountain views, a heated pool, and a two-car garage.`
4. Upload a featured image (the hero photo).
5. Write some content in the main editor — this becomes the body text below the "Quick specs" row and above the "Highlights" cards.
6. Below the editor you'll see the **Post Runtime Groupings** meta box, with a section for each grouping you defined.

For each grouping, click **Add item** and populate:

**Quick specs** (each item gets one icon + one heading):

| Icon | Heading |
|---|---|
| Bed | 4 Bedrooms |
| Bath | 2 Bathrooms |
| Ruler | 1,800 sqft |
| Home | Single Family |
| Calendar | Built 2018 |

**Highlights** (each item gets icon, heading, supporting text — no link in this example):

| Icon | Heading | Supporting text |
|---|---|---|
| Pool | Outdoor entertainment | Heated saltwater pool, covered patio, and a built-in fire pit make summer evenings effortless. |
| Star | Mountain views | Floor-to-ceiling windows in the great room frame the Cascade range from sunrise to sunset. |
| Car | EV-ready garage | Two-car garage with 240V Level-2 charger pre-installed. |

**Schedule a tour** (one item only — featured-card cap):

| Icon | Heading | Supporting text | Link |
|---|---|---|---|
| Calendar | Schedule a tour | No pressure, no obligation. We'll work around your schedule. | `#schedule-tour` |

For the link field, you can either:

- **Type a URL or anchor** like `#schedule-tour` for in-page jumps, `tel:+15555550100`, `mailto:hello@example.com`, or any external URL.
- **Type a post name** (like "About us") and pick from the autocomplete dropdown — the URL fills in automatically and the underlying post ID gets stored alongside, so internal links survive domain migrations.

7. Click **Publish**.

---

## Step 5 — Preview

Click **View Listing** at the top of the editor (or visit `/listing/modern-family-home-in-lake-view/` directly).

You should see:

- Hero with the title, excerpt, and featured image
- "Quick specs" row above the main content (icons + labels in a horizontal row)
- Your editor body content (formatted as the WordPress editor handles it)
- "Highlights" cards below the main content (3-column grid on desktop)
- "Schedule a tour" featured card in the right sidebar

If something doesn't render, check **Post Runtime → Post Types** to confirm the CPT is registered, and check the post-edit screen's meta box for any validation errors.

---

## Step 6 — Tweak and iterate

A few common adjustments:

**Per-post variant override.** On the post-edit screen, the Post Runtime Groupings meta box shows a position dropdown and a variant dropdown for each grouping. You can override the defaults for that one post — useful when one listing benefits from a different visual treatment without changing the grouping definition.

**Reorder items.** Drag the handle (≡) on the left of each item card to reorder. The drag handle appears on hover.

**Remove an item.** Click the × in the top-right of each item card (also hover-revealed).

**Switch between icon and image.** Each item can have an icon OR an image, not both. Picking one clears the other automatically.

---

## Step 7 — Configure the connector (optional)

If you'll be using Claude Cowork or another MCP client to drive setup for future CPTs, enable the connector:

1. Navigate to **Post Runtime → Connector**.
2. Check **Enable connector** and click **Save settings**.
3. Click **Generate Application Password** and copy the credentials immediately (WordPress only displays the password once).
4. If you're on Apache, follow the `.htaccess` fix in [MCP_CONNECTOR_SETUP.md](MCP_CONNECTOR_SETUP.md) — Apache strips the Authorization header by default, breaking Application Password auth.

After that, an MCP client can register CPTs, define groupings, populate posts, and preview rendered output through 18 REST endpoints — no admin clicks required for setup. The full agency workflow becomes a Cowork session instead of an afternoon of manual configuration.

---

## Common patterns

A few CPT layouts that map cleanly to the primitive:

**Real-estate listing.** Quick specs row (horizontal-row), highlights cards (card-grid), agent contact in sidebar (featured-card), neighborhood profile in sidebar (compact-grid).

**Attorney bio.** Practice areas (compact-grid, source: child_posts if you've structured practice areas as a hierarchy), education + bar admissions (compact-grid), notable cases (card-grid), contact in sidebar (featured-card).

**Course detail.** Instructor card (featured-card sidebar), course modules (card-grid), prerequisites (compact-grid), enrollment CTA (featured-card sidebar).

**Event listing.** Event details strip (horizontal-row), featured speakers (card-grid), schedule (compact-grid), ticket info in sidebar (featured-card).

**Restaurant menu.** Menu sections (card-grid with one grouping per category — appetizers, mains, desserts), dietary info (horizontal-row), reservations CTA (featured-card sidebar).

The constraint is the feature. If your content fits the primitive, the plugin saves you hours of layout-building per CPT. If it doesn't, that's a signal that you might want a different tool (Elementor, Bricks, custom theme template) for that specific case — and that's fine.

---

## When something looks wrong

**The page renders without my groupings.** Confirm the post type is the registered one (URL slug should be `/listing/...`, not `/listings/...` or `/post/...`). Confirm the meta box has data — empty groupings are silently skipped.

**The page renders with the theme's default styling, not Promptless's brand.** Confirm Promptless WP is active. The plugin's CSS uses fallback values when Promptless isn't installed, so the page still renders cleanly but without your brand customizations.

**A featured image is missing alt text.** Edit the attachment in the WordPress media library and add alt text. The plugin falls back to the post or item title when no alt is saved, but the saved alt is always preferred for screen readers.

**Cards don't separate visibly in dark mode.** Promptless's brand dark colors intentionally have low surface-vs-border contrast. The plugin adds a soft drop-shadow in dark mode to compensate. If you want stronger separation, override `--pre-color-border` in your theme's CSS or use the neo-brutalist mode toggle.

**The customizer's "Content theme" toggle (light/dark) doesn't change the CPT page.** Confirm the Promptless theme is the active theme (not Twenty Twenty-Five or another theme). The integration relies on the theme's `<main id="main-content" class="aisb-section--{theme}">` wrapper.

For deeper diagnostics, see the dedicated troubleshooting sections in [MCP_CONNECTOR_SETUP.md](MCP_CONNECTOR_SETUP.md) and [CONNECTOR_SPEC.md](CONNECTOR_SPEC.md).
