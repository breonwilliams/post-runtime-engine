# Reference Pattern — Build a Workshop Series (Events Vertical)

A worked, verified example of the events vertical (PRE v1.2). It shows how a
CPT plus six role-tagged post fields produces: branded event single pages, an
"Upcoming" filtered + sorted archive via Promptless WP's PostGrid, and valid
Schema.org `Event` markup — with no event-specific plugin.

The runnable version of everything below is `tests/seed-events-demo.php`
(`wp eval-file tests/seed-events-demo.php`). This doc explains the pattern;
the seed is the executable proof.

## 1. The field schema (the whole setup)

Register a CPT (e.g. `workshop`), then define post fields and tag the
event-relevant ones with a `semantic_role`. The role is what the query +
schema layers read; everything else is ordinary v1.1 post-field config.

| Field key | display_type | semantic_role | Notes |
|-----------|-------------|---------------|-------|
| `event_start` | `date` | `event_start` | Required. `all_day:false` for timed; `true` for all-day/multi-day. Optional `event_timezone`. |
| `event_end` | `date` | `event_end` | Strongly recommended — drives upcoming/past + "happening now". |
| `event_status` | `badge` | `event_status` | Values map to schema: `scheduled` / `cancelled` / `postponed` / `moved_online`. |
| `event_attendance_mode` | `badge` | `event_attendance_mode` | `in_person` / `online` / `mixed`. |
| `event_location` | `text` | `event_location` | Emitted as a schema `Place`. |
| `event_price` | `currency` | `event_offers` | Emitted as a schema `Offer` (with `currency_code`). |

Rules enforced at save (`PCPTPages_Validator`):

- A role pairs with exactly one `display_type` (e.g. `event_start` requires
  `date`). Mismatches are rejected.
- Each role may be mapped **once per CPT** (no two start dates).
- `all_day` must be boolean; `event_timezone` must be a valid IANA id.

These are **additive attributes** — the closed v1.1 display-type / position /
color-intent enums are unchanged. See `EVENTS_VERTICAL_DESIGN.md`.

### Set it up via the connector (AI) or admin (human)

Both paths write the same data model:

- **Connector / Cowork:** define the CPT + fields (including `semantic_role`,
  `all_day`, `event_timezone`) and populate per-post values through the
  existing post-field connector endpoints. This is the spreadsheet→CPT path.
- **Admin:** the CPT's **Post Fields** tab → field editor now shows an
  **Event timing** section (all-day + timezone, for date fields) and an
  **Event role** selector. Per-post date values use a `datetime-local` input
  for timed event dates.

## 2. How dates are stored (and why it scales)

When a `date` field has a `semantic_role`, saving a value writes two companion
meta entries next to the display value:

```
_pcptpages_field_event_start            2026-09-05 19:00:00   (display value)
_pcptpages_field_event_start__sort      20260905190000        (numeric, site-local wall clock)
_pcptpages_field_event_start__utc       1757106000            (unix UTC, tz-aware)
```

`__sort` is what range/sort queries hit — a numeric column comparison instead
of parsing `LONGTEXT`. `__utc` is stored now for future viewer-local rendering
(v1 renders in the site timezone). DST is handled correctly because conversion
always goes through `DateTimeZone`.

**Scale note:** straight `meta_query` on `__sort` is fine to ~2,000 events. Past
that, denormalize the hot fields into a flat indexed table (see
`SCHEMA_DRIVEN_FILTERS.md`). Not built in v1.

## 3. The filtered archive (Promptless WP PostGrid)

On a Promptless page, add a **PostGrid** section, set **Post Type** to your CPT,
then in the **Query** tab set:

- **Event Date Filter:** `Upcoming` (or `Happening now` / `Past`)
- **Event Date Sort:** `Soonest` (or `Latest` / `Auto`)

Status semantics are **end-date anchored**, which is what makes multi-day
events correct:

- `upcoming` = `event_end >= now` → includes events already in progress
- `happening` = `event_start <= now <= event_end`
- `past` = `event_end < now`

Decoupling: PostGrid only passes these settings through the generic
`aisb_postgrid_query_args` filter. PRE hooks that filter and injects the
date `meta_query` + ordering **only** for event-shaped CPTs. Promptless has
zero knowledge of PRE (greppable: no `PCPTPages_` references in AISB source).

**Verified behavior** (from the demo seed): with four workshops — one past, one
in-progress multi-day, two upcoming — an `Upcoming / Soonest` PostGrid renders
exactly three cards (in-progress retreat → nearest upcoming → furthest
upcoming), excluding the past one.

## 4. Schema.org Event markup (automatic)

On a registered-CPT single that maps an `event_start` role, PRE emits an
`Event` JSON-LD block in `<head>` via `wp_head` — self-contained, no dependency
on Promptless's schema engine, and it defers (emits nothing) on pages flagged
`_aisb_enabled` so a hand-built Promptless page owns its own head.

Verified output (online, free workshop):

```json
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "SEO Masterclass",
  "startDate": "2026-07-13T15:28:45+00:00",
  "endDate": "2026-07-13T18:28:45+00:00",
  "description": "...",
  "location": { "@type": "Place", "name": "Online", "address": "Online" },
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OnlineEventAttendanceMode",
  "offers": { "@type": "Offer", "price": "0", "priceCurrency": "USD", "url": "..." }
}
```

- All-day events emit **date-only** (`2026-03-15`); timed events emit
  **ISO-8601 with offset**, per schema.org guidance.
- Required `name` / `startDate` / `location` plus recommended `endDate`,
  `eventStatus`, `eventAttendanceMode`, `image`, `offers`, `description`.
- Customize the assembled array with the `pcptpages_event_schema` filter.

## 5. Timezone handling (v1)

Dates render in the **site timezone**, and `__sort` is computed in the site
timezone to match. Set a site that's single-locale (a studio, a retreat
center) and this is exactly right. The `event_timezone` field attribute and
the stored `__utc` companion are the seam for viewer-local rendering later —
no data migration required.

## 6. Scope boundaries (point complex needs elsewhere)

Deliberately **not** part of this pattern — recommend a dedicated plugin when a
client needs them:

- **Recurring events** (weekly classes) — out of scope.
- **Calendar-grid widgets / ticketing** — out of scope (use The Events
  Calendar, WooCommerce + tickets, etc.).
- **RSVP / capacity / reminders** — a *later* events slice composing Promptless
  Forms + FlowMint; not part of v1 core.
- **iCal export, viewer-local time** — deferred follow-ups.

The focus constraint is the feature: a beautiful, branded, schema-rich event
series landing page that inherits the Promptless design system and ships in
hours.

## 7. Verify it yourself

```
wp eval-file wp-content/plugins/post-runtime-engine/tests/seed-events-demo.php
```

Then open the printed PostGrid page (3 upcoming, soonest first) and any
workshop single (View Source → `application/ld+json` Event block). Unit-level
logic is covered by `tests/smoke-events-phase{1,2,4}.php`.
