# PostGrid Editor-Preview Parity for Post Fields — Design Contract

**Document status:** Draft contract. Locks the architecture before code. Implementations MUST follow this design; disagreements are resolved by editing this doc, not by writing different code.

**Author:** Breon Williams + Claude (planning session)
**Initiated:** 2026-06-13
**Target ship version:** PRE v1.2.x + a coordinated Promptless WP release
**Related:** `POST_FIELDS_V1_1_DESIGN.md` (the field model), `EVENTS_VERTICAL_DESIGN.md` (a consumer), `AISB_TOKEN_CONTRACT.md` (the CSS-only coupling this extends with a JS-only coupling).

---

## 1. Problem

PRE post-field metadata (price, beds/baths, event date/location, status badges) renders on every **server-rendered** surface — the front end, native archives, search — because Promptless WP's PHP `PostGridRenderer` fires `do_action( 'aisb_postgrid_card_section', $position, $post_id, $content )` at five positions per card and PRE's `PCPTPages_Card_Filter_Hooks` echoes its field HTML there.

The Promptless **editor canvas preview** is React (`src/components/Sections/PostGrid/PostGridPreview.js`). It builds cards client-side from the WP REST API and — by the deliberate one-way decoupling — has zero knowledge of PRE. It cannot run a PHP action, so it shows only the base card (title, excerpt, image, button). The result: cards look **complete but wrong** in the editor (the defining metadata is missing), which users read as "my data didn't save" / "it's broken."

This affects **all** post-field verticals (real estate, events, courses, directories), not just events.

> The server-rendered **Preview** (eye icon) already shows the correct result. This contract is about the live editing **canvas**, where the WYSIWYG expectation lives.

## 2. Goal

The PostGrid canvas preview renders the same PRE post-field metadata, at the same five positions, that the front end shows — **without Promptless gaining any knowledge of PRE.** Promptless exposes a generic JS extension point (mirroring its PHP action); PRE consumes it. A greppable check (`PCPTPages_` / `pcptpages` in Promptless `src`) must stay empty.

## 3. Architectural decisions (locked)

1. **Mirror the PHP action on the JS side.** Promptless already exposes a server hook (`aisb_postgrid_card_section`). We add the editor equivalent: a `wp.hooks` extension point Promptless's preview calls. Same decoupling shape — Promptless fires, PRE listens.

2. **A registered async *provider*, not a sync filter.** PRE's metadata requires a REST fetch, which is async; `applyFilters` is synchronous. So Promptless uses `applyFilters` only to **retrieve a provider function** (sync), then calls it (async). One provider per site.

3. **Batched, one fetch per preview render.** The provider receives **all** visible post IDs at once and returns a position-keyed HTML map for each. One REST round-trip per query, not one per card.

4. **PRE owns the rendering.** The provider calls a PRE REST endpoint that renders field HTML via the *same* `PCPTPages_Card_Renderer` the front end uses (`'card'` context), so canvas and front end are byte-identical by construction.

5. **Progressive, non-blocking.** The base card paints immediately; metadata fills in when the provider resolves. A failed/absent provider leaves the base card unchanged — never an error state, never a blocked canvas.

6. **CSS already loaded.** PRE's `cards.css` and the `--aisb-*` tokens already style the injected markup on the front end; the editor canvas already loads the section tokens. The injected HTML reuses the same classes, so it styles correctly in-canvas with no new CSS contract.

7. **No new AISB→PRE coupling.** Promptless ships the generic hook + provider call only. It works identically (no metadata) when PRE is inactive or no provider is registered.

## 4. The contract

### 4.1 Promptless WP side (producer)

`PostGridPreview.js`, after it has fetched the posts to display:

```js
import { applyFilters } from '@wordpress/hooks';

// Retrieve a registered metadata provider (or null).
const provider = applyFilters( 'aisb.postgrid.cardMetadataProvider', null );

// provider signature (async):
//   (postIds: number[], content: object) =>
//     Promise<{ [postId: number]: { [position: string]: string /* HTML */ } }>
// positions: 'image_overlay' | 'headline' | 'subtitle' | 'meta_strip' | 'footer_meta'
```

- Promptless calls the provider once per render with the visible post IDs + the section `content`, stores the resolved map in component state, and injects the HTML at each of the five positions via `dangerouslySetInnerHTML` (the markup is first-party, sanitized by the renderer).
- Promptless renders the five positions in the **same semantic order** as the PHP template so the canvas matches the front end.
- If `provider` is `null`, the resolve fails, or a post has no entry, Promptless renders the base card unchanged.
- Promptless caches per `(postId, contentHash)` for the editing session and re-requests only when the query changes (post type, filters, status). Debounce rapid edits.

This is the **only** Promptless change: expose the hook, call the provider, inject the result. Promptless names no plugin.

### 4.2 PRE side (consumer)

1. **Editor script** (enqueued on the Promptless editor screen) registers the provider:

```js
import { addFilter } from '@wordpress/hooks';

addFilter( 'aisb.postgrid.cardMetadataProvider', 'pcptpages', () => async ( postIds, content ) => {
    // POST postIds to the PRE endpoint; return the position-keyed HTML map.
} );
```

2. **REST endpoint** (PRE), editor-auth (`edit_posts` + nonce), batched:

```
POST  post-runtime/v1/connector/postgrid-card-preview
body  { post_ids: number[] }
=>    { "<postId>": { "headline": "<html>", "meta_strip": "<html>", ... }, ... }
```

For each post ID whose post type is a PRE CPT, PRE renders each position with the existing `PCPTPages_Card_Renderer` in `'card'` context — the same call path `on_postgrid_card_section` uses — so the HTML is identical to the front end and already sanitized. Non-PRE post IDs return an empty object (the base card stays).

3. The provider resolves the map and hands it back to Promptless. Done.

## 5. Performance

- **One** REST call per preview render (all visible IDs batched). Editor previews typically show page 1, so the working set is small (≤ posts_per_page).
- Session cache keyed by `(postId, contentHash)`; invalidate on query change.
- Endpoint loads field defs once per CPT per request and `get_post_meta($id)` per post (hits the meta cache). No N+1 beyond the unavoidable per-post meta read.
- Hard-cap the batch (e.g. the section's `posts_per_page`, ≤ 50) to bound the response.

## 6. Security / trust

- The endpoint is editor-authenticated (`edit_posts` + REST nonce); it returns HTML only for posts the user can edit.
- The HTML is produced by `PCPTPages_Card_Renderer` (the front-end renderer) with its existing escaping; Promptless injects first-party, plugin-generated markup. Document that `aisb.postgrid.cardMetadataProvider` consumers MUST return sanitized HTML (Promptless trusts the registered provider, exactly as it trusts the PHP action's echo today).

## 7. Backward compatibility / decoupling gate

- Promptless with no provider registered → identical to today (base card). PRE inactive → no provider, no change.
- Non-PRE post types → empty map → base card.
- **Gate:** `grep -r 'PCPTPages_\|pcptpages' <promptless-wp>/src` stays empty. Promptless ships only the generic hook + provider call.

## 8. Phased build plan

| Phase | Title | Output |
|---|---|---|
| 1 | Promptless producer hook | `PostGridPreview.js` retrieves + calls `aisb.postgrid.cardMetadataProvider`, injects the 5 positions, caches, progressive render. Ships as a no-op until a provider exists. |
| 2 | PRE REST endpoint | `postgrid-card-preview` batched endpoint via `PCPTPages_Card_Renderer` ('card'); editor auth; smoke test of the position-keyed shape for PRE vs non-PRE IDs. |
| 3 | PRE editor script | Enqueue on the Promptless editor screen; register the provider; fetch + map + cache. |
| 4 | Verify | Browser pass: canvas card matches front-end card field-for-field (events + a real-estate fixture); decoupling grep gate; perf check with a full page of cards. |

## 9. Success criteria

1. A PostGrid of a PRE CPT renders the same field metadata in the **canvas** as on the **front end**, at the same five positions, on a real fixture (events + real estate).
2. Progressive: base card paints immediately; metadata fills in; no flicker-to-error.
3. One batched REST call per preview render; cached across unrelated edits.
4. Promptless source has zero PRE references (greppable gate).
5. PRE inactive / non-PRE post types / no provider → byte-identical to today.

## 10. Interim (optional, until Phase 1–4 ship)

The server **Preview** already shows the correct result. If zero in-canvas confusion is wanted sooner, the same hook can carry a lightweight notice (a synchronous `applyFilters( 'aisb.postgrid.cardNotice', '', postType )` that PRE fills with "Post-field metadata renders on the front end / Preview"). Cheap, but unnecessary once true parity ships — recommend going straight to parity.

## 11. Follow-on parity gaps (resolved)

True WYSIWYG requires the canvas to match the front end on three axes, not one. The render-parity work above (Phases 1–4) closed the first; two more surfaced during verification and were closed:

### 11.1 Styling parity (resolved)

The injected metadata uses PRE's `pre-field` / `pre-card-fields__position` classes, styled by `cards.css` (scoped via `:is(.pre-card-fields--card, .aisb-features__item) .pre-field`). The React PostGrid card already carries `.aisb-features__item`, so the only missing piece was the stylesheet itself — `cards.css` is enqueued during front-end card render but not in the editor. Fix: `PCPTPages_Editor_Preview_API::enqueue_provider()` now also enqueues `pcptpages-cards` (+ the iconify web component for `meta_pair`) on the editor screen. The metadata now renders with identical font size/weight/spacing.

### 11.2 Query/filter parity (resolved)

The React preview historically queried `wp/v2` directly, which ignores the section's taxonomy filter, any `meta_query`, and ordering applied by query-shaping plugins — so the canvas showed a different (often larger, differently-ordered) post set than the live page. Fix:

- Promptless WP `PostGridRenderer::build_query_args( $content, $paged, $host_post_id )` — the query assembly (post type, ordering, pagination, tax_query, and the `aisb_postgrid_query_args` filter) is now a single shared method, used by both the front-end renderer and a new editor endpoint. One source of truth; no drift.
- New endpoint `ai-section-builder/v1/postgrid/preview-query` (POST, editor-authed) runs that exact query and returns the post set.
- `PostGridPreview.js` calls this endpoint instead of `wp/v2`, so the canvas shows the same filtered + sorted posts as the front end — including PRE's event date filter, taxonomy filters, and ordering, for any query-shaping plugin.

Verified live (events demo): canvas shows exactly the 3 upcoming workshops, soonest-first, past excluded — matching the front end.

### 11.3 Decoupling note

The query-parity endpoint lives in Promptless WP and calls Promptless's own query builder + filter — Promptless gains no knowledge of PRE. PRE's event filter applies automatically because it hooks the same `aisb_postgrid_query_args` filter. The greppable gate (no `PCPTPages_` in Promptless source) still holds.

---

**End of design contract.** Phases 1–6 implemented and verified.
