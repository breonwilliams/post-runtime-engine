# Location & Map Display — Design Contract

**Document status:** IMPLEMENTED, with a **post-implementation architecture reversal (2026-08-21)** recorded below. The original contract had PRE *reuse* Promptless WP's Map section through a filter; pressure testing showed that made a core PRE field depend on Promptless WP being installed AND on the right (map-capable) build — inconsistent with PRE's independence principle, and a silent-failure trap. The map was reworked so **PRE owns it end to end**. Where the body of this contract still says "reuse AISB", "shared filter", "delegates to `MapRenderer`", or "degrades to text without AISB" (notably §2, §4 decision 1, §7 cross-repo, and Phase B of §11), read it as **superseded by the note below.** Disagreements are still resolved by editing this doc, not by writing different code.

> **ARCHITECTURE (final, 2026-08-21) — PRE owns the map; zero Promptless WP dependency.** A `location` field is core PRE metadata, and PRE's gallery lightbox already sets the precedent that PRE ships its own frontend assets and renders standalone. Delegating the map to Promptless WP via the `aisb_render_map_embed` filter made the feature silently non-functional wherever Promptless WP wasn't the map-capable build — a coordinated-release trap that surfaced the first time PRE was deployed to a site running stock Promptless WP. So **PRE now builds the address→embed itself**: `PCPTPages_Renderer::render_map_embed()` emits the `pre-map__*` markup (click facade / auto iframe + a "Get directions" link, no API key / coordinates / geocoding), styled by PRE's own **`assets/css/map.css`** and driven by **`assets/js/pre-map.js`**. Both consume `--aisb-*` design tokens **with literal fallbacks**, so the map is visually native when Promptless WP supplies the palette and still renders correctly without it — **parity via shared tokens, not shared code** (identical to the gallery lightbox). The empty-address business-identity fallback reads the shared `aisb_business_settings` option **directly** (plain option data via `get_option`, not a PHP dependency; absent → the fallback is simply skipped). **Promptless WP is untouched** — the earlier `MapRenderer` / `Plugin.php` companion changes were reverted; there is no cross-plugin hook, and no coordinated release is required. The feature ships entirely inside PRE.
>
> **Placement (unchanged):** the map is block-level, placed with the grouping `POSITIONS` vocabulary via a per-CPT `map_position` default (`MAP_POSITIONS` = `above_main` | `below_main` | `sidebar` | `hidden`, default `below_main`) plus a per-post override in `_pcptpages_field_visibility`. `single_position` doesn't apply to a block-level map; `card_position` still shows the address **as text** on cards/archives. Admin UX (a "Location / map" field type with placement/zoom/load/directions controls, plus an address box + per-post placement dropdown on the post editor) lets a non-technical client create, fill, and reposition maps entirely by hand.
>
> **Verified live (2026-08-21):** the map renders via PRE's own `pre-map` renderer on a site whose Promptless WP carries NO map code — facade, address, named zoom (neighborhood → z=14), directions, and sidebar placement all correct; brand palette intact; **`.aisb-map` absent from the DOM** (proving no AISB path). Independence confirmed.

**Author:** Breon Williams + Claude (planning session)
**Initiated:** 2026-08-19
**Target ship version:** a future minor (indicatively PRE v1.3.x) — independent of relationships/CSV; low risk because it ports proven Promptless WP logic. Demand-gated to locator-style verticals (real estate, professional/office directories, event venues).
**Scope-expansion trigger:** Directory and listing sites routinely need "where is this?" on a record's page — a property, an office, a venue. Promptless WP already ships a Map section that solves the rendering cleanly; the gap is that a PRE-registered CPT can't surface a per-post location without hand-placing that section. This ports the same logic into PRE as a per-post location field, exactly as the gallery lightbox was mirrored from AISB into PRE.

---

## 1. Why this is being built

The single-post template PRE renders for a CPT can show a hero, groupings, post fields, and content — but not "here's where this is." For locator verticals that's a first-class need: a real-estate listing wants a map to the property, a professional directory wants each office plotted, a venue wants a map to the door.

Promptless WP already solves the hard part. Its **Map section** (`MapRenderer`) renders a **click-to-load Google Maps embed built from a plain address string** — no API key, no lat/lng, no geocoding service, with a privacy-friendly facade mode and design-token styling. Mirroring that into PRE (the way the gallery lightbox was mirrored) gives every registered CPT a per-post map with zero new infrastructure and perfect visual consistency with the rest of the Promptless design system.

## 2. Architectural premise

**Port, don't reinvent. A location is an address string on a post; the map renders through Promptless WP's existing address→embed logic.**

This is the load-bearing decision and the reason this is low-risk. AISB's `MapRenderer` already:

- Takes a **sanitized address string** and builds a `https://www.google.com/maps/...` **embed URL server-side** — no API key, no coordinates, no geocoding API.
- Offers two load modes: **`auto`** (native lazy iframe) and **`click`** (a token-styled facade `<button>` carrying address + zoom in data attributes; `map.js` swaps in the iframe on click — no Google request until the user interacts).
- Uses **named zoom levels** (`ZOOM_LEVELS`, e.g. `neighborhood`), a `show_directions` affordance, and design-token/palette styling.
- Falls back **at render time** to the business-identity address (`aisb_business_settings → business_address`) when no address is set.

PRE adds one thing: a **`location` post-field display type** whose value is an address string, whose single-post render **delegates to that same embed/facade logic** (reusing `MapRenderer`'s embed-URL builder, `ZOOM_LEVELS`, `map.js`, and tokens via a shared filter/helper), and whose per-CPT definition carries the map's default zoom, load mode, and directions toggle. No new rendering, no keys, no coordinates, no custom tables — the same constrained-primitive discipline as every other field.

## 3. Scope

### In scope

- A new **`location`** post-field display type (additive to the closed `DISPLAY_TYPES` enum, via the contract-amendment pattern): one **address string** per post, stored as an ordinary post-field value (`_pcptpages_field_{key}`).
- **Single-post render:** a map block on the CPT's single-post page, produced by reusing Promptless WP's address→embed logic (auto/click load modes, named zoom, directions, tokens, business-identity fallback).
- **Per-CPT definition attributes** for the location field: default **zoom** (named level), default **load mode** (`auto` | `click`), **show directions** (bool) — closed enums, mirroring AISB's Map section options.
- **Card / archive render:** the address as text (existing text treatment) or hidden — a per-field position choice. No embedded map per card (deliberately — §4.5).
- **Business-identity fallback** identical to AISB: empty address falls back to `aisb_business_settings` at render time.
- Connector/MCP parity: `location` is just another post-field display type, so `define_post_field` / `set_post_field_values` already cover it; only the new attributes + enum need surfacing.
- Backward compatible, opt-in per CPT, no new storage.

### Explicitly out of scope for this phase

- **Multi-marker archive / PostGrid "map view"** (all posts of a CPT plotted on one interactive map). This is the directory dream, but it cannot be done with the no-API-key single-address embed — it requires the Google Maps **JavaScript API + an API key** (or a tile provider like Leaflet/OpenStreetMap), which breaks the zero-config, no-key simplicity that makes the single-post map trivial. Deferred; revisit only if a client vertical demands a live multi-pin map, and treat it as its own contract because it introduces a key, a JS map library, and per-marker data plumbing.
- **Latitude/longitude storage and geocoding.** The address-string embed needs neither. If a future multi-marker view ships, *that* contract owns coordinates and geocoding — not this one.
- **Multiple locations per post** (a chain with many offices). One `location` field = one address. A second office is a second field, or (later) the multi-marker view. No repeater-of-addresses here.
- **Non-Google providers / self-hosted tiles** for the single-post map. AISB standardized on the Google embed; PRE mirrors it for consistency. A provider abstraction is out of scope.
- **Routing / distance / "near me" search.** That's discovery, and it belongs with the (deferred) multi-marker view + the existing filter system, not the per-post field.

## 4. Architectural decisions (locked)

1. **Reuse Promptless WP's map logic; do not fork it.** PRE's `location` render calls the same embed-URL builder, `ZOOM_LEVELS`, `map.js`, and token styling as `MapRenderer`, exposed through a shared helper/filter so there is one map implementation across the stack (the gallery-port precedent). CSS-token coupling only — PRE still never hard-depends on AISB PHP; when AISB is inactive, the field degrades to the address as text.
2. **Address string only — no keys, no coordinates.** The whole value proposition is zero-config. The stored value is an address; the embed is built from it server-side. No API key, no geocoding, no lat/lng.
3. **A new closed display type, `location`.** Additive to `DISPLAY_TYPES` via the documented contract-amendment pattern (design doc first, then the validator enum + guard test cite it). Its map-block render is heavier than the inline types, but conceptually it is still "one typed value, one render."
4. **Per-CPT map options are closed enums**, mirroring AISB's Map section: named zoom levels, `auto|click` load mode, directions bool. No free-form map config.
5. **No embedded map on cards.** A map iframe per card in a grid is heavy and visually noisy; cards show the address as text (or hide it). The map is a single-post feature. (A single static-map thumbnail on cards could be a later addition, but it reintroduces keys/coordinates, so it's deferred with the multi-marker view.)
6. **Business-identity fallback preserved.** Empty address → `aisb_business_settings` address at render time, identical to AISB, so a single-office site "just works" without per-post entry.
7. **No custom tables.** The address is a post-field value; map options live on the field definition in the existing option store.
8. **Backward compatibility mandatory.** Additive, opt-in per CPT; existing content and rendering unchanged; degrades to text without AISB.

## 5. Data model

### 5.1 Field definition (additive attributes on a `location` field)

```php
array(
    'key'            => 'office',          // sanitize_key
    'label'          => 'Office',
    'display_type'   => 'location',        // NEW closed display type
    'single_position'=> 'below_main',      // where the map block renders on the single-post page
    'card_position'  => 'footer_meta',     // address-as-text on cards, or 'hidden'
    'map_zoom'       => 'neighborhood',    // closed enum, mirrors AISB ZOOM_LEVELS
    'map_load'       => 'click',           // 'auto' | 'click' (privacy-friendly default: click)
    'show_directions'=> true,
    'description'    => '',
)
```

### 5.2 Per-post value

```
_pcptpages_field_office  =>  "123 Cascade Ave, Missoula, MT 59801"
```

A plain address string — the same input AISB's Map section takes. Empty → business-identity fallback at render.

## 6. Rendering

- **Single-post:** at the field's `single_position`, PRE emits the map via the shared helper that reuses AISB's embed/facade logic — `auto` (lazy iframe) or `click` (token-styled facade + `map.js`), named zoom, optional "Directions" link, palette/token styling. Identical look to a Promptless WP Map section by construction.
- **Card / PostGrid / archive:** the address as text at `card_position` (or hidden). PostGrid already renders PRE post-field metadata via the existing card filter, so the address flows through with no new integration.
- **AISB inactive:** the field renders the address as plain text (graceful degradation; the map is an AISB-provided enhancement, consistent with the CSS-token-only coupling rule).

## 7. Validator, connector, and stack coordination

- **Validator:** add `location` to `DISPLAY_TYPES` (guard-test-pinned via the amendment pattern), a named-zoom enum, and `auto|click` load-mode enum; validate the address as sanitized text.
- **Connector/MCP:** `location` is a post-field display type, so `define_post_field` / `update_post_field` / `set_post_field_values` already carry it — only the new attributes (`map_zoom`, `map_load`, `show_directions`) and the two new enums need adding to the REST allow-lists, relay body-field lists, preflight descriptor, and knowledge map, in one change set (the owner-synced-vocabulary invariant).
- **Cross-repo:** the shared map helper/filter is the one coordination point — Promptless WP exposes its embed-URL builder + `map.js` behavior through a documented filter/helper that PRE consumes (parallel to the `aisb_postgrid_card_content` pattern). AISB never learns PRE exists.

## 8. Edge cases (decisions)

- **Empty address:** business-identity fallback; if that is also empty, render nothing map-like (no broken iframe) — exactly AISB's behavior.
- **`click` as the default load mode:** privacy-friendly (no Google request until interaction) and lighter on card-heavy pages; `auto` available when an always-visible map is wanted.
- **Bad/ambiguous address:** the Google embed shows its own "location approximate" behavior; no PRE-side geocoding to fail. The field stores exactly what the author typed.
- **Multiple maps on one page** (several location fields, or a map in a grouping context): each is an independent facade/iframe; `click` mode keeps them cheap.
- **RTL / long addresses / a11y:** inherits AISB's accessible facade (button with label, keyboard-activatable) — no new a11y surface.

## 9. Backward compatibility

Additive. New display type, opt-in per CPT. No change to existing fields, groupings, relationships, rendering, storage, or the connector. Sites that never add a `location` field are unaffected; sites without AISB active get the address as text.

## 10. Success criteria

1. **A CPT gains a per-post map with no keys or coordinates** — define a `location` field, type an address, and the single-post page shows a Promptless-styled click-to-load map identical to an AISB Map section.
2. **One map implementation across the stack** — a greppable check shows PRE reuses AISB's embed/facade logic (no forked map code).
3. **Degrades cleanly** — with AISB inactive, the field renders the address as text; with an empty address, business-identity fallback, then nothing (no broken embed).
4. **Connector parity** — an AI or manual author sets a location via the existing post-field tools; the new attributes/enums are surfaced in preflight.
5. **No regression / no new storage / no custom tables.**
6. **Docs complete** — this contract, ROADMAP/ARCHITECTURE updates, the shared-map-helper filter documented in the integration doc, and the validator amendment cited by the guard test.

## 11. Phased build plan

| Phase | Title | Est. hours | Output |
|---|---|---|---|
| A | Planning + this contract | 4 | This doc + ROADMAP/ARCHITECTURE updates |
| B | Shared map helper in Promptless WP | 8 | Expose `MapRenderer`'s embed-URL builder + `map.js` behavior via a documented filter/helper for cross-plugin reuse (no visual change to AISB) |
| C | `location` display type in PRE | 12 | Validator amendment + guard test, field definition attributes (zoom/load/directions), single-post map render delegating to the shared helper, card address-as-text, business-identity fallback |
| D | Connector + preflight + docs | 6 | New attributes/enums across REST allow-lists, relay schema, preflight, knowledge map; integration-doc entry; user note |
| **Total** | | **~30 hours** | |

The multi-marker archive "map view" (API key + JS map library) is intentionally **not** in this plan; it is a separate, later contract gated on a real locator-directory client need.

---

**End of design contract.** Phase A deliverable. Phase B begins after founder approval. Ports Promptless WP's Map section logic; introduces no keys, coordinates, or custom tables.
