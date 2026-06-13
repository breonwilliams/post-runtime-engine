# Events Vertical — Design Contract (PRE v1.2, events slice)

**Document status:** Draft contract. Locks the architectural decisions for the events vertical before code is written. Phase implementations MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

**Author:** Breon Williams + Claude (planning session)
**Initiated:** 2026-06-13
**Target ship version:** PRE v1.2.0 (events slice); coordinated Promptless WP PostGrid release
**Supersedes/extends:** `POST_FIELDS_V1_1_DESIGN.md` § 3 deferred items — "Facet filtering on archives" (narrow events-only slice) and "Automatic schema.org emission per field config" (`Event` only). This is the dedicated v1.2 phase that doc anticipated.

---

## 0. Naming note (read first)

The folder/slug was renamed from the provisional `post-runtime-engine` / `pre_*` to the shipped `promptless-cpt-pages` / `pcptpages_*`. `POST_FIELDS_V1_1_DESIGN.md` predates that rename and still shows `_pre_field_*` / `pre_post_fields_*` in places. **This document and all code use the shipped conventions:**

- Class prefix: `PCPTPages_*` (e.g. `PCPTPages_Validator`, `PCPTPages_Post_Data`, `PCPTPages_Post_Field_Registry`)
- Field-value meta: `_pcptpages_field_{key}` (+ `_count` / `_goal` secondaries)
- Field-definition option: `pcptpages_post_fields_{cpt_slug}`
- Visibility meta: `_pcptpages_field_visibility`
- Action/filter prefix: `pcptpages_*`
- Text domain: `promptless-cpt-pages`

## 1. Why this is being built

Events is the simplest, most natural validation of structured-data archives. ~80% of an event landing page already reduces to PRE primitives shipped in v1.1: auto hero (title + featured image), default WP editor for the agenda, `child_posts` groupings for speakers/sponsors, taxonomy categories, and post fields for date/price/status/capacity. The remaining ~20% — **archive filtering by date status, Schema.org `Event` markup, and date/timezone correctness** — is what this contract specifies.

Positioning is deliberately narrow: branded event *landing pages* that inherit the Promptless design system and compose with Forms/FlowMint, NOT a calendar/ticketing/recurring-events plugin. Recurring events, calendar-grid widgets, and ticketing are explicitly out of scope and customers needing them are pointed at dedicated plugins. The focus constraint is the feature.

This slice is also the deliberate first plank of the broader schema-driven filter system (`SCHEMA_DRIVEN_FILTERS.md`). The declarative attributes introduced here (`semantic_role`, and later `filterable` / `sortable`) and the decoupled PostGrid query-args seam are reused by that system. Events ships a complete vertical *and* de-risks the general filter contract before the larger PostFilter component investment.

## 2. Architectural premise

The events vertical adds **zero new display types and zero new positions.** It is built entirely from:

1. Two existing `date` post fields (event start, event end), extended with additive attributes.
2. A small set of additive, forward-compatible **field-definition attributes** (`all_day`, `semantic_role`) — attributes, not enum values, so the v1.1 closed enums (display types, positions, color intents) are untouched. (The post-fields guardrail requires documenting attribute additions in a contract first; this is that document.)
3. A **normalized, lexically-sortable stored value** for date fields, written on save, enabling efficient range/sort queries.
4. A **decoupled query-args seam** between Promptless WP's PostGrid and PRE, mirroring the existing `aisb_postgrid_card_content` decoupling.
5. A **self-contained `Event` JSON-LD emitter** owned by PRE.

## 3. Scope

### In scope (events v1)

- `all_day` boolean attribute on the `date` display type.
- `semantic_role` attribute on post-field definitions: `event_start`, `event_end`, `event_status`, `event_location`, `event_offers`, `event_attendance_mode`. (Closed enum, events-scoped; extensible later for other verticals.)
- Normalized sortable date storage: on save, PRE writes a companion `_pcptpages_field_{key}__sort` meta containing a numeric `YYYYMMDDHHMMSS` value (and a UTC-normalized value) derived from the entered date. Querying/sorting uses this companion, never the human display value.
- Three-state date-status query helper (`upcoming` / `happening` / `past`), computed against the **end date** so multi-day and in-progress events resolve correctly.
- Promptless WP PostGrid: a generic, reusable "event date status" control + sort-by-date in the Query tab, wired through a new generic `aisb_postgrid_query_args` filter. PRE hooks that filter to inject the date `meta_query`. AISB stays zero-knowledge of PRE.
- Schema.org `Event` JSON-LD emitted by PRE via `wp_head` on registered-CPT singles, mapped from `semantic_role` fields. Date-only for all-day, ISO-8601-with-offset for timed.
- One documented reference pattern: "Build a workshop series."
- Unit + integration smoke tests for date normalization, three-state boundaries, all-day handling, and schema output.

### Explicitly out of scope (events v1)

- RSVP / registration (Promptless Forms), capacity tracking, FlowMint reminder/follow-up workflows. These are a **later events slice**; v1 core stays decoupled from Forms.
- iCal / "Add to calendar" `.ics` export. Follow-up.
- Multi-locale / viewer-local time rendering. Storage is designed to support it (see § 5), but v1 renders in the **site timezone** with a documented constraint.
- Recurring events, calendar-grid widgets, ticketing. Permanently out of scope — point at dedicated plugins.
- The general schema-driven facet filter system (`filterable`/`sortable` attributes, the PostFilter section, URL-state, chips, mobile drawer). That is the **next** project after events ships; this slice only builds the narrow date filter.
- A `date_range` display type. Two separate `date` fields cover start/end; no new display type.

## 4. Architectural decisions (locked)

1. **No new display types, no new positions.** Events = two `date` fields + additive attributes. The v1.1 enums are frozen; this slice only adds attributes.
2. **End-date-based status logic.** `upcoming` = `end >= now`; `happening` = `start <= now <= end`; `past` = `end < now`. Comparing on the end date is what makes multi-day and "currently happening" correct. (Proven pattern: mature event plugins filter on end date.)
3. **Normalized sortable storage, written on save.** `wp_postmeta.meta_value` is `LONGTEXT` and cannot be range-indexed efficiently; storing a numeric `YYYYMMDDHHMMSS` companion lets `orderby => meta_value_num` and range `meta_query` work correctly and as fast as postmeta allows. Documented `~2,000`-event ceiling; the denormalized-index escape hatch (per `SCHEMA_DRIVEN_FILTERS.md`) is the scale path, not built now.
4. **Storage designed right, rendering shipped simple.** Persist the entered value + an `all_day` flag + an optional event timezone + a UTC-normalized companion now. Render in site timezone for v1; viewer-local is a later additive change with no storage migration.
5. **PRE owns `Event` JSON-LD, standalone.** PRE renders the CPT single, so PRE owns its schema. Emitted via `wp_head`, gated on a configured `event_start` role. No dependency on Promptless WP's `SchemaRegistry`; works when Promptless is inactive. PRE's `Event` node and Promptless's `WebPage` node coexist (distinct `@type`).
6. **Decoupled PostGrid filter via query-args hook.** Promptless WP exposes a generic `aisb_postgrid_query_args` filter; PRE injects the date `meta_query` when the queried CPT has an `event_start`/`event_end` role mapped. AISB has zero `PCPTPages_*` references (greppable acceptance gate, same as the existing card-content filter).
7. **`semantic_role` is a generic seam, not events-only plumbing.** It is the declarative mechanism schema mapping (now) and richer filtering (later) both read. Events defines the first closed set of roles; other verticals extend it under their own contracts.
8. **No custom DB tables in v1.** Normalized date companion is post meta. Consistent with PRE's portability constraint. A `wp_pcptpages_index` table is only considered if the documented ceiling is hit.
9. **Backward compatibility mandatory.** CPTs without an `event_start` role render and query exactly as today. No migration. The attributes are opt-in per field.

## 5. Data model (additive)

### 5.1 New field-definition attributes

On the `date` display type:

```php
'event_start' => array(
    'key'            => 'event_start',
    'label'          => 'Starts',
    'display_type'   => 'date',
    'single_position'=> 'meta_strip',
    'card_position'  => 'headline',
    'date_format'    => 'absolute',     // existing attribute (DATE_FORMATS)
    'all_day'        => false,          // NEW: bool. true => date-only, no time, no tz math
    'event_timezone' => '',             // NEW: optional IANA tz (e.g. 'America/New_York'); '' => site tz
    'semantic_role'  => 'event_start',  // NEW: see § 5.2
)
```

`all_day` and `event_timezone` are only meaningful on `date` fields. `semantic_role` is valid on any field but the events role enum constrains which roles pair with which display types (validated — see § 7).

### 5.2 `semantic_role` enum (events-scoped, closed)

| Role | Expected display_type | Maps to (schema / query) |
|------|----------------------|--------------------------|
| `event_start` | `date` | `Event.startDate`; query/sort anchor |
| `event_end` | `date` | `Event.endDate`; status comparison anchor |
| `event_status` | `badge` | `Event.eventStatus` (Scheduled/Cancelled/Postponed/MovedOnline) |
| `event_location` | `text` | `Event.location` (Place + PostalAddress) |
| `event_offers` | `currency` | `Event.offers` (price + priceCurrency) |
| `event_attendance_mode` | `badge` | `Event.eventAttendanceMode` (Offline/Online/Mixed) |

A CPT becomes "event-shaped" when at least an `event_start` role is mapped. `event_end` is strongly recommended (status logic falls back to start when absent — see § 6.3).

### 5.3 Per-post storage

Unchanged primary value, plus a normalized companion written on save:

```
_pcptpages_field_event_start          => '2026-09-05 19:00'      (human/entered value)
_pcptpages_field_event_start__sort     => 20260905190000          (NEW: numeric YYYYMMDDHHMMSS, site-tz)
_pcptpages_field_event_start__utc      => 1757106000              (NEW: unix UTC, for future viewer-local)
```

- Companion keys use a `__sort` / `__utc` suffix (double underscore avoids collision with the existing `_count`/`_goal` secondaries).
- For `all_day` fields, `__sort` is `YYYYMMDD000000` and the schema emits date-only.
- Written in `PCPTPages_Post_Data::write_field_value()` whenever a `date` field with a `semantic_role` is saved. Non-event date fields skip the companion (no behavior change).
- A one-time backfill is unnecessary for new CPTs; an idempotent "recompute on save" plus an optional admin/connector backfill helper covers pre-existing posts.

### 5.4 Timezone handling (v1 = site tz, storage future-proof)

- `__sort` is computed in the site timezone (`wp_timezone()`), matching how the value is rendered. This is the "cheap version": correct for single-locale sites (studios, retreats, local meetups).
- `__utc` is computed using `event_timezone` if set, else the site timezone, via `DateTimeImmutable` + `DateTimeZone`. It is stored now but not used for rendering in v1; it is the seam for viewer-local rendering later with no migration.
- DST is handled by always going through `DateTimeZone`, never naive string math.

## 6. Query model

### 6.1 Helper

`PCPTPages_Post_Data` (or a small `PCPTPages_Event_Query` helper) exposes:

```php
PCPTPages_Event_Query::status_meta_query( $cpt_slug, $status /* upcoming|happening|past */, $now = null ): array
PCPTPages_Event_Query::sort_args( $cpt_slug, $direction /* soonest|latest */ ): array
```

These resolve the `event_start` / `event_end` field keys for the CPT, then return `meta_query` + `orderby` fragments keyed on the `__sort` companion.

### 6.2 meta_query shape

```php
// upcoming: events whose end is now or later, soonest first
'meta_query' => array(
    array(
        'key'     => '_pcptpages_field_event_end__sort',
        'value'   => (int) gmdate('YmdHis', current_datetime_in_site_tz),
        'compare' => '>=',
        'type'    => 'NUMERIC',
    ),
),
'orderby'  => array( '_pcptpages_field_event_start__sort' => 'ASC' ),
```

### 6.3 Status definitions

- `upcoming`: `event_end__sort >= now` (includes in-progress). If no `event_end` mapped, falls back to `event_start__sort >= now`.
- `happening`: `event_start__sort <= now AND event_end__sort >= now`.
- `past`: `event_end__sort < now` (fallback `event_start__sort < now`).
- `now` is computed in site tz via `wp_date('YmdHis')` semantics at query time — never cached at publish time (freshness requirement from the events exploration).

## 7. Validator extensions

`PCPTPages_Validator` gains (additive, no enum changes to DISPLAY_TYPES/FIELD_POSITIONS/COLOR_INTENTS):

```php
const SEMANTIC_ROLES = array(
    'event_start', 'event_end', 'event_status',
    'event_location', 'event_offers', 'event_attendance_mode',
);
```

In `validate_post_field_definition()`:

- `all_day` — optional; must be boolean; only meaningful when `display_type === 'date'` (warn/ignore otherwise).
- `event_timezone` — optional string; if non-empty must be a valid `DateTimeZone` id (`timezone_identifiers_list()`).
- `semantic_role` — optional; must be in `SEMANTIC_ROLES`; the role↔display_type pairing in § 5.2 is enforced (e.g. `event_start` requires `date`); a given role may be mapped at most once per CPT (reject duplicates with a clear error).

Strict-mode discipline as elsewhere: reject at save, never paper over at render.

## 8. Schema.org Event emitter

`includes/Frontend/class-pre-event-schema.php` (new). Hooks `wp_head`.

- Runs only on `is_singular()` for a CPT registered with PRE that has an `event_start` role mapped, and only when `_aisb_enabled` is NOT set (mirrors the template router's Promptless precedence).
- Builds a JSON-LD `Event` object:
  - Required: `name` (post title), `startDate`, `location`.
  - Recommended: `endDate`, `eventStatus`, `eventAttendanceMode`, `image` (featured image), `description` (excerpt/meta description), `offers`, `performer` (derived from a `child_posts` speakers grouping if present).
  - `startDate`/`endDate`: date-only `Y-m-d` when `all_day`, else ISO-8601 `c` with the resolved offset.
  - `eventStatus` / `eventAttendanceMode`: mapped from the badge field's value through a small internal map to the schema.org URIs (e.g. `cancelled` → `https://schema.org/EventCancelled`).
- Escaped with `wp_json_encode()`; emitted in a `<script type="application/ld+json">` block.
- Filterable: `pcptpages_event_schema` (the assembled array) for site-level customization before encode.

Spec anchors: Google requires `name` + `startDate` + `location`; recommends `endDate`, `eventStatus`, `eventAttendanceMode`, `image`, `offers`. All-day uses date-only; timed uses ISO-8601 with offset.

## 9. Promptless WP PostGrid integration (decoupled)

### 9.1 New generic filter (AISB side)

`PostGridRenderer` exposes its assembled `WP_Query` args through a new filter **before** instantiating the query:

```php
$query_args = apply_filters( 'aisb_postgrid_query_args', $query_args, $content, $post_id );
```

This is generic (any plugin can shape PostGrid queries) and reusable by the future filter system. AISB ships a small "Event date" control in the Query tab that sets `content['event_status']` (`none|upcoming|happening|past`) and a date sort option; AISB itself only passes these through in `$content` — it does NOT implement date logic.

### 9.2 PRE handler

`PCPTPages_Card_Filter_Hooks` (existing) gains a handler on `aisb_postgrid_query_args`: when `content['event_status']` is set and the queried `post_type` is a PRE CPT with an `event_start` role, it merges in the § 6.2 `meta_query` + `orderby`. Otherwise returns args unchanged.

Acceptance gate: `grep -r 'PCPTPages_\|pcptpages_' <promptless-wp>/src <promptless-wp>/includes` shows only the generic filter name string, never a PRE class reference.

## 10. Backward compatibility

- CPTs with no `event_start` role: zero behavior change. No companion meta written, no schema emitted, PostGrid filter is a no-op.
- Existing `date` fields without `all_day`/`semantic_role`: render exactly as today.
- No `pcptpages_data_version` bump required for query/schema (additive runtime behavior). If `register_post_meta()` is added for the `__sort` companion (for REST/WP-CLI visibility), bump to mark it — additive only, no migration.

## 11. Phased build plan

| Phase | Title | Output |
|---|---|---|
| 0 | This contract + post-fields contract note | This doc; forward-note in `POST_FIELDS_V1_1_DESIGN.md` |
| 1 | PRE date-model extension | `all_day` + `event_timezone` + `semantic_role` validation; `__sort`/`__utc` companions written in `write_field_value`; admin inputs; smoke test |
| 2 | PRE query helper + PostGrid query-args handler | `PCPTPages_Event_Query`; `aisb_postgrid_query_args` handler in card-filter-hooks; smoke test of three-state boundaries + multi-day |
| 3 | Promptless WP PostGrid control | Event date-status + sort control (React Query tab + PHP renderer + `aisb_postgrid_query_args` filter); decoupling gate |
| 4 | PRE `Event` JSON-LD emitter | `class-pre-event-schema.php` on `wp_head`; smoke test of all-day vs timed, status/attendance mapping |
| 5 | Reference pattern + test consolidation | "Workshop series" pattern doc; unit/integration smoke tests; manual-test checklist |

## 12. Success criteria

1. A CPT with `event_start`/`event_end` fields renders a branded event single page, shows correct upcoming/happening/past state, and emits valid `Event` JSON-LD (passes Google Rich Results structural checks).
2. A Promptless WP PostGrid set to "Upcoming events, soonest first" lists only future/in-progress events in correct order, on a real-content fixture spanning the today boundary (multi-day in-progress event included).
3. All-day and timed events both emit spec-correct schema (date-only vs ISO-8601-with-offset).
4. Zero `PCPTPages_*` references in Promptless WP source (decoupling gate).
5. CPTs without event roles are byte-identical to pre-change behavior (regression).
6. Smoke tests green for: date normalization (incl. DST boundary), three-state boundary math, all-day, schema assembly. Manual side-by-side verification on a real install.

## 13. Open items deferred (tracked, not built)

- RSVP/Forms + capacity + FlowMint reminders (later events slice).
- iCal export.
- Viewer-local time rendering (storage already supports via `__utc`).
- General schema-driven facet filter system (`SCHEMA_DRIVEN_FILTERS.md`) — the next project.

---

**End of events design contract.** Phase 1 begins after this doc is in place.
