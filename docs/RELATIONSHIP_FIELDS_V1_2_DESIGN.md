# Relationship Fields — v1.2 Design Contract

**Document status:** Draft — formal scope-expansion conversation, the third field type the v1.0/v1.1 guardrails explicitly anticipated (`POST_FIELDS_V1_1_DESIGN.md` §3 "Explicitly out of scope: Cross-CPT relationships … Deferred to v1.2+"). Locks the architectural decisions before any code is written. Implementations MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

**Author:** Breon Williams + Claude (planning session)
**Initiated:** 2026-08-19
**Target ship version:** PRE v1.2.0
**Scope-expansion trigger:** Real agency demand for editor-driven correlation between custom post types — conference sites (speaker ↔ session), real estate (agent ↔ listing, neighborhood ↔ listing), directories (professional ↔ chapter), courses (instructor ↔ course), medical (provider ↔ location). The correlation *engine* already exists (grouping source modes); what is missing is a first-class **input** primitive an editor recognizes and trusts, and optional **two-way** binding.

---

## 1. Why this is being built

PRE can already correlate post types. A grouping on any CPT can auto-populate from related posts via four source modes — `manual` (curated items, each linkable to a specific post via `link_post_id`), `child_posts`, `taxonomy_match`, and `meta_match` (including the reverse-lookup shape that pulls a *different* CPT whose field names the current post). The connector uses exactly this machinery. The display side is solved and it is solid.

The gap is on the **authoring** side, and it surfaced in two concrete ways during review of the live conference demo (`association.flowmintdemos.com`):

1. **There is no first-class relationship input.** To attach a speaker to a session today, an editor opens the Session, scrolls to the "Speakers" grouping (a meta box in the **normal/content column** — `class-pre-meta-box.php` registers it with context `'normal'`), adds an item, and uses the autocomplete link picker to point that item at a Speaker post. That works and is rename-proof (it stores `link_post_id`), but it presents as "build a card that links somewhere," not "declare that this session has these speakers." Every senior developer arriving from ACF, Pods, or MetaBox reaches for a **sidebar relationship panel** first and is briefly disoriented when it is not there. The capability exists; the affordance does not match the mental model.

2. **There is no two-way binding.** Attaching a speaker on the Session does not make that session appear on the Speaker. The two directions are independent lists. On the demo, the connector populated *both* directions, which is why it looks reciprocal — but structurally it is two hand-maintained lists, and a human editor would have to maintain both.

The v1.0 grouping primitive and the v1.1 post-field primitive both deliberately declined to solve this (grouping items are *content cards*; post fields are *scalar display metadata*). A relationship is neither: it is a **typed structural link** between two posts. Stretching either existing primitive to carry it would dilute both — the same reasoning that produced two distinct primitives in v1.0 → v1.1. A third, tightly-scoped primitive is the clean architectural answer, and it is the piece that turns "correlation is possible and correct" into "correlation is effortless for an editor and obviously trustworthy to a developer."

## 1.5 Current-state editor UX audit (live findings, 2026-08-19)

*Recorded after a live walkthrough of the association demo, driven the way a non-technical client would experience it — not inferred from the code. This is the evidence base for the contract: what the editor actually does today, versus what the code is capable of.*

**The handoff problem this must solve.** An agency builds one of these sites fast using the connector (Claude wires the CPTs, groupings, and correlations). It then hands the site to a non-technical client who must maintain it by hand — add speakers, add sessions, connect them, reorder, remove — for the life of the site. The bar is **manual parity**: a hand-editing client must be able to produce the *same quality* of correlation the AI produced, with *less* effort, not more. Today that bar is not met, and the shortfall is invisible until someone opens the editor.

**What actually works today.**
- The front end is correct. A speaker page renders a "Sessions" card linking to the session (via `link_post_id: 69`), and the session renders a "Speakers" card linking back (`link_post_id: 32`/`14`). The *output* is right and rename-proof.
- The picker exists and works. Typing a name in an item's link field returns a live typeahead of matching posts with a post-type badge and URL; selecting one stores the stable `link_post_id`. The machinery is real — it is just buried.

**Where a non-technical client gets lost — seven concrete gaps, observed live.**

1. **Wrong place to look (discoverability).** The correlation UI is a meta box in the *content column* ("Post Runtime Groupings"), not the sidebar. An editor trained on ACF/Pods looks in the sidebar first and concludes the feature isn't there. The grouping's *definition* (its source mode) lives on a separate screen (Post Runtime → Groupings), disconnected from the post — and that screen's help text is developer language (`child_posts`, `taxonomy_match`, "the post-meta key that identifies related posts, e.g. `_agent_id`").

2. **The connection is invisible on reload — bug, now fixed.** An item linked by `link_post_id` showed a **blank** search box on reopen, because that box is bound to the URL string, not the post ID. A client literally could not see or verify that anything was connected. *Fixed in this change set* (§6.3): the item now shows "Linked to {Title} ({Type})" on load and flags a reference whose target no longer exists.

3. **"Build a card," not "connect a post" (mental-model mismatch).** The flow is: add a blank card, type a heading, pick an icon, and *separately* search-and-link. Non-technical users think in connections ("this speaker is at this session"), not in display cards. The current UI foregrounds the card and hides the connection.

4. **Label and link are decoupled (data-quality trap).** The visible label is free text, independent of the linked post. It is easy to produce a card with a label and no link, a link with a mismatched label, or — the common case at handoff — a label with *no link at all*, because linking is a hidden second step. The connector reliably sets the link; a hand-editing client frequently won't. That asymmetry is the core handoff risk.

5. **The picker searches everything (no scoping).** The link search returns *all* post types. A client connecting a session could accidentally link a Page or a News article with a similar title.

6. **No auto-fill, and rename drift.** Picking the post does not fill the heading — the client types it too. And because the label is stored text, renaming the target later leaves the *label* stale on every card that references it: the link still resolves, but the displayed name does not update.

7. **No two-way, and no cleanup.** Connecting a speaker on a session does nothing to the speaker page; the client must maintain both ends by hand and will forget one, producing an asymmetric site. When a session is cancelled/trashed, the speaker's card still references it (now visibly flagged as broken, but with no assisted cleanup).

**Conclusion.** The correlation is *stored* correctly and *renders* correctly, but the *editing experience* neither reveals it nor makes it safe to reproduce by hand. The connector papers over this because the AI performs the hidden steps perfectly; a non-technical client cannot. Closing this — visible connections, one obvious place, one gesture, scoped picking, live labels, optional two-way, safe deletion — is exactly what the relationship field must deliver, and it is why manual parity is a locked requirement (§4.11).

## 2. Architectural premise

**The relationship field is an INPUT primitive that FEEDS the existing display engine. It does not render lists itself.**

This is the load-bearing decision. PRE already knows how to *render* a related-post list — that is what groupings do. What it lacks is a clean, typed way to *declare* the relationship and (optionally) keep both ends in sync. So:

- A **relationship field** stores stable post-ID references from the current post to a target CPT. It is defined per-CPT, edited in a sidebar picker, and validated strictly.
- A **grouping** continues to own rendering. A new source mode — `relationship` — lets a grouping say "render the posts referenced by relationship field X," reusing the auto-item rendering path already built for `taxonomy_match` / `meta_match` (auto-pull title, featured image, permalink; link-aware icon fallback).
- Optional **reciprocal binding** keeps the inverse relationship field on the target CPT in sync, so one editor action populates both pages.

The result closes the loop with no new rendering surface: **picker (input) → relationship meta (storage) → `relationship` source mode (query) → existing grouping renderer (output)**, with reciprocal sync as an opt-in write-back.

The constraint discipline from v1.0/v1.1 carries forward unchanged: small closed enums (cardinality, on-missing behavior, reciprocal on/off), stable ID references only, no custom DB tables, strict save-time validation, connector parity. The combinatorial surface stays bounded and AI-legible; coverage scales across verticals because every "X has many Y" pattern reduces to one relationship field plus one `relationship`-sourced grouping.

## 3. Scope for v1.2

### In scope

- A new field type — **relationship field** — distinct from groupings and post fields. Stores one or many stable post-ID references to a single target CPT.
- Per-CPT relationship definitions stored in `wp_options` under `pcptpages_relationships_{cpt_slug}`, parallel to `pcptpages_groupings_{cpt_slug}` and `pcptpages_post_fields_{cpt_slug}`.
- Per-post values stored in post meta (see §5.2): an ordered source-of-truth entry plus flat companion rows for `WP_Query` correlation.
- Cardinality enum: `one` (single reference) or `many` (ordered multiple).
- **Sidebar** editor UI (`context: 'side'`) — a searchable post picker scoped to the target CPT, reusing the existing core `/wp/v2/search` autocomplete infrastructure. Directly answers the "I expected this in the sidebar" expectation.
- A "Relationships" tab on the CPT edit screen (parallel to "Groupings" / "Post Fields") for *defining* relationship fields.
- New grouping source mode `relationship` that renders the posts a named relationship field references, via the existing auto-item render path.
- Optional **reciprocal (two-way) binding**, opt-in per field, maintained by a single idempotent, loop-guarded sync service.
- Referential-integrity handling for trash / delete / restore of target posts (soft, non-destructive — see §8).
- Validator extensions: cardinality enum, on-missing enum, `relationship` source mode, definition/value/reciprocal validation, guard tests pinning the new enums.
- Connector REST endpoints under `/post-runtime/v1/connector/cpts/{slug}/relationships/*` and per-post relationship endpoints, with full MCP tool parity and preflight surfacing.
- Backward compatibility: existing installs render identically; relationships are opt-in per CPT; existing `manual` and `meta_match` groupings keep working untouched.

### Explicitly out of scope for v1.2

- **Polymorphic targets.** A single relationship field pointing at more than one target CPT ("attach a Speaker *or* a Sponsor"). Substantial query and UI complexity. Deferred to v1.3+.
- **Edge metadata.** Data stored *on* the relationship itself ("this speaker's role for this session = keynote"). Requires a join-record model that breaks the "no custom tables" constraint. Deferred; if demand is real it is its own contract.
- **Computed / derived relationships.** "Sessions in the same track as this one" — that is already expressible via `taxonomy_match`; no relationship field needed. Relationship fields are for *asserted* links only.
- **Cascading delete.** Deleting a Speaker never deletes their Sessions. On-delete is soft only (§8). A destructive cascade is explicitly forbidden.
- **ACF / Pods / MetaBox relationship migration tooling.** PRE owns its model; an importer is a separate optional deliverable, not part of this contract.
- **Cross-site / multisite-network relationships.** References are within a single site. Network-level correlation is out of scope.
- **Weighting / scoring / ordering beyond manual order.** `many` relationships preserve the editor's drag order; no relevance ranking.

## 4. Architectural decisions (locked)

Settled during planning. Disagreements resolved by editing this doc.

1. **Third field type, not stretched groupings or post fields.** A relationship is a typed reference, not a content card and not a scalar display value. Conflating it with either dilutes both primitives — the same reasoning that produced the grouping/post-field split.

2. **Store stable post IDs, never titles or slugs.** References are rename-proof and slug-change-proof by construction. This is the specific fragility that `meta_match` with `match_against: current_title` carries; relationship fields exist partly to eliminate it. (`meta_match` remains valid for cases where the match value is genuinely a shared attribute rather than an asserted link.)

3. **The relationship field feeds display; it does not render.** Rendering stays with groupings via the new `relationship` source mode. No new list-rendering code path is introduced. This keeps one renderer, one set of variants, one CSS surface.

4. **Reciprocal binding is opt-in and is the ONLY sanctioned path by which PRE writes to a second post's meta.** All such writes go through one auditable service (`PRE_Relationship_Sync`), are idempotent, and are loop-guarded. Default is one-way (no write-back) because two-way writes have blast radius.

5. **On-missing / on-delete is soft, never destructive.** Deleting or trashing a target does not delete the referencing post or the reference eagerly. The renderer skips targets that are absent or not published (matching the existing link-aware renderer, which already gates on the linked post being published). A lazy/CLI reconciliation prunes dangling IDs. No cascade, ever.

6. **Closed enums.** `cardinality` ∈ {`one`, `many`}; `on_missing` ∈ {`skip`} (single value in v1.2, enum-shaped for forward growth); reciprocal is a boolean plus a target field key. No filter-based custom relationship behaviors in v1.2 — extension is a later conversation with its own contract.

7. **No custom DB tables.** Definitions in `wp_options`, values in post meta, reciprocal state derived from the same meta on both ends. Same portability guarantee as v1.0/v1.1 across managed hosts.

8. **Reuse the existing post-picker infrastructure.** The sidebar picker uses the same core `/wp/v2/search` + `jquery-ui-autocomplete` path the grouping link field already uses (`class-pre-meta-box.php`), scoped to the target CPT. No new search backend.

9. **Definition lives on the CPT, not the post or the grid.** Every post of the CPT gets the same relationship fields; every surface that lists related posts renders consistently. Per-instance relationship config is rejected as AI-illegible and consistency-corrosive (mirrors v1.1 decision #2).

10. **Backward compatibility is mandatory.** Existing v1.0/v1.1 sites render identically. Relationships are opt-in per CPT. Existing groupings (`manual`, `child_posts`, `taxonomy_match`, `meta_match`) are untouched.

11. **Manual parity with the connector is a first-class requirement, not an afterthought.** Every correlation the connector can create, a non-technical client must be able to create by hand with equal or better ease and *identical data quality* — a stable link, a correct label, and (when enabled) both directions. The agency-to-client handoff is the primary lifecycle event this feature serves: the site must be as maintainable by the client after handoff as it was buildable by the AI before it. This decision is what elevates the relationship field from "possible via groupings" to "required" — see the live audit in §1.5. Acceptance for every UX decision below is judged against a non-technical editor, not a developer.

## 5. Data model

### 5.1 Relationship definition shape

Stored under `pcptpages_relationships_{cpt_slug}` (associative array keyed by field key):

```php
array(
    'key'          => 'speakers',        // sanitize_key; unique within CPT; MAX_FIELD_KEY_LEN (64)
    'label'        => 'Speakers',        // admin + a11y label
    'target_cpt'   => 'speaker',         // must be a CPT registered through PRE
    'cardinality'  => 'many',            // 'one' | 'many'   (see §5.3)
    'on_missing'   => 'skip',            // closed enum; skip unpublished/absent targets at render
    'reciprocal'   => array(             // optional two-way binding; omit or enabled:false for one-way
        'enabled'    => true,
        'target_key' => 'sessions',      // the relationship field key on target_cpt that mirrors this one
    ),
    'max_items'    => null,              // optional cap for cardinality 'many' (null = uncapped)
    'description'  => '',                // admin help text (optional)
)
```

Example per-CPT value:

```php
get_option( 'pcptpages_relationships_session' );
// =>
array(
    'speakers' => array( /* target_cpt: speaker, cardinality: many, reciprocal → speaker.sessions */ ),
);
get_option( 'pcptpages_relationships_speaker' );
// =>
array(
    'sessions' => array( /* target_cpt: session, cardinality: many, reciprocal → session.speakers */ ),
);
```

### 5.2 Per-post storage

Two representations, one source of truth:

- **Ordered source of truth** — `_pcptpages_rel_{key}`: a JSON array of post IDs preserving editor order. This is what the renderer reads.
  ```
  _pcptpages_rel_speakers  =>  '[412,388,401]'
  ```
- **Flat companion rows** — `_pcptpages_rel_{key}__id`: the same IDs written as repeated single-value meta rows, so `WP_Query` `meta_query` and `post__in` correlation work natively.
  ```
  _pcptpages_rel_speakers__id  =>  '412'
  _pcptpages_rel_speakers__id  =>  '388'
  _pcptpages_rel_speakers__id  =>  '401'
  ```

For `cardinality: one`, the JSON array holds exactly zero or one ID; the companion row holds zero or one value. This keeps read/write code uniform across cardinalities.

Rationale mirrors the v1.1 "one meta key per field value" decision (§5.2 of that contract): the flat companion rows give native queryability, REST visibility, and WP-CLI inspectability; the ordered JSON gives deterministic render order that repeated-row storage cannot guarantee. The companion rows are always derived from the JSON on write — the JSON is authoritative.

Absence of `_pcptpages_rel_{key}` = the field is unset for that post (renders nothing; on a `relationship`-sourced grouping, the grouping is empty).

### 5.3 Cardinality (closed enum, 2 values)

| Value | Meaning | Editor UI | Storage |
|-------|---------|-----------|---------|
| `one` | At most one target post | Single searchable select | JSON array of 0–1 IDs + 0–1 companion rows |
| `many` | Ordered set of target posts | Searchable multi-add list with drag-reorder | JSON array of N IDs + N companion rows |

### 5.4 On-missing (closed enum, 1 value in v1.2)

| Value | Behavior |
|-------|----------|
| `skip` | A referenced target that is trashed, deleted, private, or draft is silently omitted at render. The reference is retained in storage until reconciliation (§8) prunes truly-deleted IDs. |

The enum is single-valued now but enum-shaped so a future `placeholder` (render a "no longer available" stub) or `strict` (surface a validation warning in admin) can be added without a data-model change.

## 6. Editor UX

This section is the direct answer to the authoring gap.

### 6.1 Sidebar relationship panel (per-post)

Relationship fields render in a **sidebar meta box** (`add_meta_box( …, 'side', 'default' )`) titled from the CPT's relationship set — deliberately in the sidebar, matching the ACF/Pods/MetaBox convention editors expect. (The existing groupings meta box stays in the normal column; relationships are structurally different and belong where editors look for connections.)

- **`cardinality: one`** — a single searchable select. Type to search posts of `target_cpt` (core `/wp/v2/search`, scoped), pick one; a removable chip shows the selection linking to the target's edit screen.
- **`cardinality: many`** — a searchable add box plus an ordered list of chips. Each chip: target title, edit link, drag handle, remove button. Order is the stored order. Optional `max_items` disables the add box at the cap with a clear message.
- When `reciprocal.enabled`, the panel shows an inline note: *"Adding a session here also lists this speaker on that session."* So the two-way effect is never a surprise.

### 6.2 Defining relationship fields (CPT edit screen)

`includes/Admin/class-pre-admin-relationships.php` (new). A third tab, "Relationships", alongside "Groupings" and "Post Fields", same horizontal-tab pattern. Each definition row shows key, label, target CPT, cardinality, and reciprocal target (if any). Inline editor (not modal, consistent with the post-fields editor):

- Field key (`sanitize_key`), Label
- Target CPT (dropdown of PRE-registered CPTs)
- Cardinality (`one` / `many`)
- Reciprocal: checkbox → when on, a dropdown of relationship fields already defined on the target CPT whose `target_cpt` points back to this CPT (prevents mismatched pairings at definition time)
- `max_items` (shown only for `many`), Description

A "Wire the inverse for me" affordance: when defining `session.speakers` with reciprocal on, if `speaker.sessions` does not yet exist, offer to create the mirrored definition on the target CPT in the same action, pre-filled and consistent. This makes correct two-way setup a single gesture rather than two coordinated edits.

### 6.3 Shipped interim fix — visible linked state on manual groupings (2026-08-19)

Independent of the v1.2 field, one audit finding (§1.5 gap 2) was fixed immediately in `class-pre-meta-box.php`, `meta-box.js`, and `admin.css`, because it silently undermined trust in *every existing* manual grouping: an item linked by `link_post_id` rendered a blank link box on reload, so an editor could not see or verify the connection. The item now renders a "Linked to {Title} ({Type})" indicator on load, kept in sync by the picker on select/clear, and flags a reference whose target no longer exists ("Linked to a post that no longer exists (ID N)"). It is save-safe — it reads the existing `link_post_id` and never writes the submittable link field. This is a stopgap that makes the *current* model honest; it does not replace the relationship field, which removes the decoupled label/link model entirely.

### 6.4 How the sidebar picker closes each audited gap

| Audit gap (§1.5) | How the relationship field resolves it |
|---|---|
| 1 · Wrong place to look | Lives in the **sidebar**, where editors look for connections; definition sits alongside Groupings/Post Fields, described in plain terms, not `meta_match` jargon |
| 2 · Invisible connection | The field *is* the visible connection — chips showing the linked posts, always; nothing hidden in a URL string (interim fix already lands this for legacy groupings) |
| 3 · "Build a card" mismatch | The gesture is "pick a Speaker," not "compose a card." No heading, no icon, no link field to reconcile |
| 4 · Decoupled label/link | There is no separate label to mismatch — the display is derived from the linked post; you cannot create a label-with-no-link or link-with-wrong-label |
| 5 · Unscoped search | The picker is scoped to `target_cpt`; a Speaker field only finds speakers |
| 6 · No auto-fill / rename drift | The label is pulled live from the target at render, so renames propagate automatically; nothing to re-type |
| 7 · No two-way / no cleanup | Optional reciprocal binding maintains the inverse; trash/delete is handled softly and surfaced, with a reconcile tool for cleanup (§8) |

The test for success is not "a developer can wire this" — it already is possible for a developer via groupings. It is "the association's part-time administrator adds next year's speakers and sessions, connects them, and the site stays correct and symmetric, without ever seeing the word `meta_match`."

## 7. Feeding the display engine

New grouping source mode: **`relationship`**.

```php
// Grouping definition on the 'session' CPT
array(
    'key'    => 'speakers',
    'label'  => 'Speakers',
    'source' => array(
        'type'  => 'relationship',
        'field' => 'speakers',   // the relationship field key on this CPT
    ),
    // default_variant, default_position, etc. as today
)
```

At render, the `relationship` source reads `_pcptpages_rel_speakers`, loads those posts in one `get_posts( array( 'post__in' => $ids, 'orderby' => 'post__in' ) )`, and emits auto-items through the **existing** auto-item path (title, featured image, permalink, link-aware `default_icon` fallback) — the same path `taxonomy_match` and `meta_match` already use. No new renderer, no new variant, no new CSS.

Consequently the full loop is:

1. Editor sets **Speakers** on a Session in the sidebar picker.
2. The Session's **Speakers** grouping (`source: relationship, field: speakers`) renders those speakers.
3. If `reciprocal` is on, `PRE_Relationship_Sync` writes the inverse, so the Speaker's **Sessions** grouping (`source: relationship, field: sessions`) renders that session automatically.

One editor action, both pages correct, all IDs stable.

`relationship` and `meta_match` coexist: `relationship` is for *asserted* links (rename-proof IDs, optional two-way); `meta_match` remains for *attribute* matches ("same agent id", "same neighborhood name"). The connector `source_modes` descriptor and preflight will state this chooser explicitly.

## 8. Reciprocal binding & referential integrity (the trust-critical section)

This is where a senior developer's confidence is won or lost, so the guarantees are explicit.

**Service.** One class, `PRE_Relationship_Sync`, is the sole writer of reciprocal meta. It hooks the relationship save path (connector write and admin save), never scattered `update_post_meta` calls.

**Idempotence & loop-guard.** Every sync operation is idempotent (adding an existing ID is a no-op; removing an absent ID is a no-op). Re-entrancy is blocked by a process-static guard set keyed on `(post_id, field_key)` around any write-back, so A→B writing B→A cannot trigger B→A writing A→B in a loop. Self-referential relationships (e.g., "related products" on the same CPT/field) are allowed and covered by the same guard.

**Operations.**
- *Add A→B* (B is added to A's `field`): ensure A ∈ B's `target_key` list (append preserving order if absent).
- *Remove A→B*: remove A from B's `target_key` list.
- *Replace A's whole list*: diff old vs new; apply the minimal add/remove set to each affected target. No full rewrite of untouched targets.
- *Trash / delete A* (`wp_trash_post`, `before_delete_post`): prune A from every post that references A. Trash is reversible — on `untrash_post`, references are **not** auto-restored (they were pruned); reconciliation or re-selection restores them. This is documented so it is not surprising.
- *Rename / slug change A*: no-op (IDs, not titles).

**Failure handling.** A reciprocal write that fails (e.g., target locked) is logged and left for reconciliation; the primary write still succeeds. The system never persists a half-write that renders as a corrupted relationship — the ordered JSON on each end is written atomically per post, and the companion rows are rebuilt from it, so a given post's relationship state is always internally consistent even if a *counterpart* is temporarily behind.

**Reconciliation.** A `postruntime_reconcile_relationships` connector tool (and a `wp pcptpages reconcile-relationships` WP-CLI command) walks all relationship meta, prunes IDs whose targets no longer exist, and repairs one-sided reciprocals (A→B present but B→A missing) for fields where `reciprocal.enabled`. Safe to run repeatedly; reports what it changed. This is the escape hatch that lets an operator trust the two-way system: drift is always detectable and repairable, never silent.

**Capabilities.** Setting a relationship requires the actor can edit the current post. A reciprocal write-back to the target is a system-initiated write gated by an explicit capability check (`current_user_can( 'edit_post', $target_id )` for admin-initiated writes; the connector's authenticated identity for connector writes). A user who cannot edit the target cannot cause a write to it — the reciprocal is skipped and surfaced, never forced.

## 9. Validator extensions

`includes/Core/class-pre-validator.php` gains:

```php
const RELATIONSHIP_CARDINALITY = array( 'one', 'many' );
const RELATIONSHIP_ON_MISSING  = array( 'skip' );

const SOURCE_MODES = array(
    'manual',
    'child_posts',
    'taxonomy_match',
    'meta_match',
    'relationship',   // NEW
);
```

New methods:

- `validate_relationship_definition( $def )` — key/label/target_cpt (must be PRE-registered), cardinality enum, on_missing enum, reciprocal shape, `max_items` sanity.
- `validate_reciprocal_config( $def, $target_cpt_relationships )` — `target_key` exists on `target_cpt`, its `target_cpt` points back to this CPT, cardinalities are compatible, and the pairing is not accidentally self-inconsistent.
- `validate_relationship_value( $def, $ids )` — every ID is a real post of `target_cpt`; count ≤ `max_items`; cardinality `one` allows ≤ 1.
- Extend `relationship` in the grouping source validator: `source.field` names a defined relationship field on the CPT; auto-source grouping carries an empty `items` array (reusing the existing "auto sources must use an empty items array" rule).

**Guard tests.** New PHPUnit guards pin `RELATIONSHIP_CARDINALITY`, `RELATIONSHIP_ON_MISSING`, and the extended `SOURCE_MODES` — the intentional-fail pattern: growing the vocabulary fails the guard until this contract is updated to cite the addition. Strict-mode discipline is unchanged: invalid input is rejected at save, never papered over at render.

## 10. Connector REST + MCP

New endpoints under `/post-runtime/v1/connector/cpts/{slug}/relationships/*`, parallel to groupings and post fields:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/relationships` | GET | List relationship definitions for the CPT |
| `/relationships` | POST | Add a relationship definition |
| `/relationships/{key}` | GET / PUT / DELETE | Read / update / remove a definition |
| `/posts/{post_id}/relationships` | GET | Read all relationship values for a post |
| `/posts/{post_id}/relationships` | PUT | Set relationship values for a post (triggers reciprocal sync) |
| `/relationships/reconcile` | POST | Repair dangling IDs and one-sided reciprocals |

MCP tools (mirror REST, `postruntime_*` prefix, consumed by the PRE relay):

- `postruntime_define_relationship`
- `postruntime_update_relationship`
- `postruntime_delete_relationship`
- `postruntime_list_relationships`
- `postruntime_set_post_relationships`
- `postruntime_get_post_relationships`
- `postruntime_reconcile_relationships`

Preflight extensions: surface `RELATIONSHIP_CARDINALITY`, `RELATIONSHIP_ON_MISSING`, the new `relationship` source mode, and a chooser note distinguishing `relationship` (asserted, ID-stable, optional two-way) from `meta_match` (attribute match).

**Vocabulary-sync invariant (do this in ONE change set).** Per the stack-wide "connector vocabulary is owner-synced" rule, the new enums and tools must be added, in the same change, to: the validator (owner) → the REST service field allowlists → the relay tool schemas *and* body-field allowlists (an unknown field is silently dropped and reads as "saved with defaults") → `docs/CONNECTOR_SPEC.md` → the preflight descriptor. Then sync the bundled relay copy and note the required Claude Desktop restart.

## 11. Edge-case decisions

- **Self-referential relationships** (same CPT, e.g. "related resources"): allowed. Reciprocal on a self-relationship is allowed and covered by the loop-guard.
- **Draft / private / trashed targets:** stored, skipped at render (`on_missing: skip`), retained until reconciliation confirms deletion.
- **`many` ordering:** editor drag order is authoritative (`orderby: post__in`). No implicit sort.
- **Duplicate selection:** the picker prevents adding the same target twice; the validator dedupes defensively on write.
- **Deleting a definition** (not a post): removes the definition and, on request, its stored values; a `relationship`-sourced grouping referencing a removed field validates to empty rather than erroring.
- **Performance:** one `get_posts( post__in )` per relationship grouping per post render; cache with the existing render-cache transient. N is editor-bounded and small.
- **Cardinality change `many` → `one`:** requires an explicit reduction step (keep first, or clear) surfaced in admin/connector; never silent data loss.

### 11.1 Non-technical client lifecycle matrix

The edge cases that matter most are the ones a client hits *after handoff*, doing routine maintenance. Each must behave predictably with no developer intervention:

| Client action | Required behavior |
|---|---|
| Add a new speaker, connect to an existing session | One sidebar pick on either post; if reciprocal is on, both pages update. No second screen, no typing the name twice |
| Speaker drops out of a session | Remove the chip; the inverse is removed too (reciprocal). No orphaned card left on the other page |
| Session is cancelled (trashed) | The speaker's list silently omits it at render (`on_missing: skip`); the editor shows it as removed/broken rather than a dead link; reconcile prunes it |
| Trashed session is restored | It reappears where still referenced; if it had been pruned, the client re-picks it (documented — restore does not silently resurrect removed links) |
| Speaker is renamed | Every page referencing them shows the new name automatically (label pulled live) — the rename-drift trap is gone |
| Client tries to add the same speaker twice | Prevented in the picker; deduped defensively on save |
| Reorder speakers on a session | Drag the chips; order is preserved and authoritative |
| Lower-privileged editor (Author/Editor role) edits connections | Can set connections on posts they may edit; a reciprocal write to a post they may *not* edit is skipped and surfaced, never forced (§8) |
| Client opens a relationship the **connector** built | Round-trips cleanly — same storage, same UI; the client cannot tell whether a human or the AI created it, and editing it is identical |
| Large set (e.g. 40 sessions on a track chair) | Picker paginates/searches; render is one `post__in` query; no per-item hand-entry |

The through-line: the client should never need to know that a "grouping," a "source mode," or a "post-meta key" exists. They pick people and things from lists, in the sidebar, and the site stays correct.

## 12. Backward compatibility

- Existing v1.0/v1.1 installs render identically. Relationship fields are opt-in per CPT; a CPT with none behaves exactly as today.
- Existing groupings — `manual`, `child_posts`, `taxonomy_match`, `meta_match` — are untouched and remain first-class. `relationship` is additive.
- An optional, documented migration path converts a `manual` grouping whose items all carry `link_post_id` into a `relationship` field + `relationship`-sourced grouping (read the `link_post_id`s, write them as relationship meta). Opt-in, reversible, never automatic.
- The demo's current `manual` speaker↔session wiring keeps working after upgrade; converting it to relationship fields is a choice, not a requirement.

## 13. Success criteria for v1.2

A v1.2 ship is gated on ALL of the following:

1. **One-gesture correlation.** An editor sets speakers on a session in the sidebar picker; the session page lists the speakers and (reciprocal on) each speaker page lists that session — with no second edit.
2. **Rename-proof.** Renaming or reslugging a target does not break any relationship (verified by test).
3. **Reciprocal integrity proven.** Add / remove / replace / trash / delete / untrash each leave both ends in a documented, consistent state; the loop-guard is proven by test (no infinite write recursion, self-relationships included).
4. **Reconciliation repairs drift.** A deliberately corrupted set (dangling IDs, one-sided reciprocals) is fully repaired by the reconcile tool, idempotently.
5. **Display via existing engine only.** `relationship`-sourced groupings render through the current auto-item path — greppable check: zero new list-rendering code paths, zero new variants.
6. **Cowork parity.** From `define_relationship` through `set_post_relationships` to verified rendered output, no manual admin step required.
7. **No regression.** v1.0/v1.1 CPTs, groupings, and post fields behave identically; the connector's existing pressure tests pass unchanged.
8. **Strict validation.** Invalid target CPT, bad cardinality, mismatched reciprocal target, over-cap counts, non-existent IDs — all rejected at save with clear messages.
9. **Capability safety.** A user who cannot edit a target cannot cause a write to it; verified by test.
10. **Coverage > 80%** on `PRE_Relationship_Sync`, the relationship registry, and the validator extensions, with explicit integrity/fuzz coverage on the sync service.
11. **Documentation complete.** This contract, `ROADMAP.md`, `ARCHITECTURE.md` (third field type + `relationship` source mode), `CONNECTOR_SPEC.md`, the knowledge-map/preflight descriptors, and `CLAUDE.md`/`AGENTS.md` posture notes.

## 14. Phased build plan

Mirrors the v1.0/v1.1 phase structure. Detail lands in `docs/ROADMAP.md`.

| Phase | Title | Est. hours | Output |
|---|---|---|---|
| A | v1.2 planning + this design contract | 6 | This doc + ROADMAP/ARCHITECTURE/CLAUDE updates |
| B | Relationship data layer + validator | 12 | Registry (`pcptpages_relationships_{slug}`), ordered-JSON + companion-row storage, cardinality/on_missing enums, validator methods + guard tests |
| C | Reciprocal sync service + integrity | 14 | `PRE_Relationship_Sync` (idempotent, loop-guarded), trash/delete/untrash handling, reconciliation (connector tool + WP-CLI), integrity/fuzz tests |
| D | Editor UX: sidebar picker + Relationships tab | 12 | Sidebar meta box (`context: 'side'`), single/many pickers reusing `/wp/v2/search`, CPT-edit "Relationships" tab, "wire the inverse" affordance |
| E | `relationship` grouping source integration | 8 | New source mode wired into the existing auto-item render path; empty-items rule; render-cache integration; side-by-side verification |
| F | Connector REST + MCP + preflight | 10 | Endpoints under `/cpts/{slug}/relationships/*`, `postruntime_*` tools + body-field allowlists, preflight/knowledge-map sync, relay bundle sync |
| G | Pressure test + docs | 8 | Speaker↔session and agent↔listing end-to-end on the pressure-test site; reciprocal + reconciliation proven live; docs finalized |
| **Total** | | **~70 hours** | |

---

**End of design contract.** Phase A deliverable. Phase B begins after founder approval of this doc and the linked ROADMAP updates.
