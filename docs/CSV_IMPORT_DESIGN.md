# CSV Import & Export — Design Contract

**Document status:** Draft — scope-expansion contract for a bulk content on-ramp. Locks the architectural decisions before any code is written. Implementations MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

**Author:** Breon Williams + Claude (planning session)
**Initiated:** 2026-08-19
**Target ship version:** a future minor, indicatively PRE v1.3.0 — sequenced **after** Relationship Fields (v1.2), which this builds on for importing connections. Demand-gated: build when an agency workflow needs to seed or migrate content at scale.
**Scope-expansion trigger:** The agency handoff and migration workflow. The connector makes AI-driven bulk creation easy, but a client seeding a directory (hundreds of listings, sessions, professionals) or migrating off ACF/Pods/Toolset has no non-AI on-ramp. CSV import fills that gap; CSV export completes the round-trip and doubles as the authoring template.

---

## 1. Why this is being built

PRE can create content one post at a time (admin) or in bulk via the connector (AI). What it lacks is a **deterministic, non-AI bulk path** — the thing an agency reaches for when it needs to load 300 real-estate listings from a spreadsheet, migrate a client's existing directory, or hand a non-technical client a way to update dozens of records in Excel and push them back.

Every mature structured-content tool ships this (WP All Import, WooCommerce's product CSV importer, Toolset's import). The recurring failure mode across all of them is the same: **the user doesn't know how to structure the CSV**, so rows import into the wrong fields, dates land as text, multi-value fields split wrong, and relationships silently fail to resolve. The design premise here is that the *template is the product* — if the structure is authoritative and the import is validated and previewed, bulk import stops being scary.

## 2. Architectural premise

**The CSV schema is DERIVED from the CPT definition, never hand-authored, and every cell is validated against that same definition before anything is written.**

This is the load-bearing decision and the answer to "how does a user know the structure." A CPT already declares its shape — post fields (with display types, allowed enum values, formats), taxonomies, and (v1.2) relationships. The importer reads that shape to:

1. **Generate a self-describing template** (§5) — exact columns, per-column format hints, allowed enum values inline, and a filled example row.
2. **Auto-map** an uploaded CSV's columns to the CPT's fields (the template's headers match 1:1; foreign CSVs get a mapping step).
3. **Validate every cell** through the existing `PCPTPages_Validator` in a **dry-run preview** that reports errors per row/cell *before* a single post is created.
4. **Write** via the same code paths the admin and connector use — no bypass, no new storage.

Everything inherits the constrained-primitive discipline: no custom tables (posts, post meta, taxonomies, and the existing option-stored definitions only), closed enums enforced on import exactly as in the editor, idempotent re-import, and a hard bias toward "fail loudly in preview, never write bad data."

## 3. Scope

### In scope

- **Schema-derived template download** per CPT: headers + instruction row + example row, generated from the CPT's live field/taxonomy/relationship definitions (§5).
- **CSV export** of existing posts of a CPT, using the identical column schema — the round-trip half and the most trustworthy template.
- **CSV import** with: upload → auto-map (with manual override) → **dry-run validation preview** → commit → per-row result report.
- **Idempotent re-import** keyed on a designated unique column (slug or an external-ID column stored as `_pcptpages_import_key`), so a spreadsheet can be re-imported to *update* rather than duplicate.
- Column support for: core post fields (title, content, excerpt, status, slug, date, author), **featured image** (by attachment ID or URL sideload), **post fields** (every display type, with type-correct formatting — §5.2), **taxonomies** (by name or slug), and **relationships** (v1.2 — referenced by title/slug/ID, resolved to stable IDs; §5.3).
- Validation-backed preview reusing `PCPTPages_Validator`; nothing is written unless the row passes.
- Batch/chunked processing for large files so imports don't time out (§9).
- Backward compatibility: additive; existing sites and content are untouched.

### Explicitly out of scope for this phase

- **Authored manual grouping items (repeater cards) as first-class columns.** Repeatable authored cards (icon + heading + supporting text + link per item) do not map cleanly to a flat CSV. The common case — *connections* — is handled by importing **relationships** (one cell of titles/slugs) that auto-render via a relationship-sourced grouping. For sites that genuinely need to bulk-load authored cards, a documented **JSON-in-a-cell** escape hatch is offered (§5.4), clearly marked advanced; a richer repeater-import format is deferred until a real client needs it.
- **Scheduled / recurring imports** (watch a feed URL, cron-sync). Deferred; this is one-shot, user-initiated import.
- **Arbitrary transformation / formulas on import** (concatenate columns, compute values). The CSV is authored to match the schema; transformation belongs upstream.
- **Non-CSV formats** (XML, JSON, direct DB, WXR). CSV only. (Export may add JSON later if demand exists.)
- **Cross-CPT import in one file.** One import targets one CPT. Relationships *reference* other CPTs but a single file does not create multiple post types.
- **Rollback/undo of a committed import.** Mitigated by the dry-run preview and idempotent update-by-key (re-import a corrected file). A true transactional rollback is deferred.

## 4. Architectural decisions (locked)

1. **The template is generated from the CPT definition, always.** No hand-maintained sample files that drift. Add a field, re-download, the column is there — with its format hint and (for enums) its allowed values.
2. **Validate in a dry run before writing anything.** The importer parses the whole file, validates every cell through `PCPTPages_Validator`, and shows a per-row/cell preview. Commit is a second, explicit step. No partial garbage from a half-run import.
3. **Write through existing code paths only.** Import creates/updates posts, post-field meta, taxonomy terms, and relationship meta using the same functions the admin meta box and connector use. No direct SQL, no bypass of validation, no new storage.
4. **Idempotent, keyed re-import.** Every import designates a key column (default: `slug`; or an `external_id` column persisted as `_pcptpages_import_key`). Re-importing updates the matched post instead of duplicating. This is what makes CSV a *migration* tool, not just a one-time loader.
5. **Relationships are how connections are imported.** A relationship column holds a delimited list of target titles/slugs/IDs; the importer resolves them to stable IDs and (if reciprocal) syncs the inverse via `PRE_Relationship_Sync`. Targets that don't exist yet are reported, not silently dropped — with an optional two-pass mode (import all rows, then resolve relationships) for files that reference rows within the same import.
6. **Closed enums enforced identically to the editor.** A `badge`/`track`/`level` value not in the field's allowed set is a preview error with the allowed values listed — never a silently-created bad value.
7. **No custom tables.** Posts, post meta, taxonomy terms, existing option-stored definitions. Same portability guarantee as the rest of PRE.
8. **Export uses the exact same column schema as import.** Round-trip safe: export → edit → re-import updates in place.
9. **Large files process in batches, with progress.** No "increase your PHP timeout" advice; the importer chunks (Action Scheduler or batched AJAX — §9) so a 5,000-row file completes reliably on shared hosting.
10. **Backward compatibility is mandatory.** Additive feature; opt-in per import; existing content and workflows unchanged.

## 5. The self-describing template (the centerpiece)

This section is the direct answer to "how does a user know how to structure their CSV." The importer ships **two** schema-derived generators, both driven off the CPT's live definition so they can never fall out of sync with the fields.

### 5.1 "Download CSV template" — blank, but self-documenting

A button on the CPT's Import screen produces a CSV with three rows:

- **Row 1 — machine headers (the real column keys).** Namespaced so nothing collides:
  - Core: `title`, `content`, `excerpt`, `status`, `slug`, `date`, `author`, `featured_image`
  - Post fields: one per field, `field:{key}` — e.g. `field:price`, `field:track`, `field:starts`
  - Paired sub-values where a display type needs them: `field:rating`, `field:rating__count`; `field:progress`, `field:progress__goal`
  - Taxonomies: `tax:{taxonomy}` — e.g. `tax:category`
  - Relationships (v1.2): `rel:{key}` — e.g. `rel:speakers`
  - The designated key column is marked (e.g. `slug` or `external_id`).
- **Row 2 — the instruction row.** For each column, a plain-language label + the exact required format. This row is prefixed with a skip marker (e.g. a leading `#` in the first cell, or delivered as a separate "How to fill this in" sheet) so the importer ignores it. Examples the generator emits per display type:
  - `field:price` (currency) → `Number, no symbol or commas — e.g. 1250000`
  - `field:starts` (date) → `YYYY-MM-DD HH:MM (or the field's configured format)`
  - `field:track` (badge with options) → `One of: regulatory_policy | field_science | practice_management | climate_resilience`
  - `field:level` (badge) → `One of: intro | intermediate | advanced`
  - `field:rating` / `field:rating__count` → `1–5` / `Whole number of reviews`
  - `field:amenities` (multi_badge) → `Comma-separated values`
  - `field:duration` (number_with_label) → `Number — unit label "min" is fixed by the field`
  - `featured_image` → `Image URL (will be sideloaded) or an existing attachment ID`
  - `tax:category` → `One or more term names or slugs, separated by |`
  - `rel:speakers` → `One or more Speaker titles or slugs, separated by | (resolved to the linked posts)`
- **Row 3 — a filled example row.** Realistic sample values in every column, so the user sees one concrete, correct record to pattern-match against.

Because Row 1 and Row 2 are emitted from the field definitions, a closed-enum field automatically lists its current allowed values, a date field states its configured format, and a newly-added field appears the next time the template is downloaded. The structure is authoritative because it *is* the schema.

### 5.2 Column ↔ post-field format map

The generator (and the validator, on import) share one table of per-display-type formatting:

| Display type | Column(s) | CSV value format |
|---|---|---|
| `currency` | `field:{key}` | Bare number (no symbol/commas); symbol/format applied at render |
| `number_with_label` | `field:{key}` | Bare number; `number_grouping` and unit label come from the definition |
| `badge` | `field:{key}` | One of the field's option keys (listed inline); or free text if no options defined |
| `multi_badge` | `field:{key}` | Comma-separated values |
| `meta_pair` | `field:{key}` | Text value (icon comes from the definition) |
| `date` | `field:{key}` | `YYYY-MM-DD` or `YYYY-MM-DD HH:MM`; normalized to the field's stored + sortable companion meta |
| `text` | `field:{key}` | Plain text |
| `rating` | `field:{key}`, `field:{key}__count` | `1–5`; review count as whole number |
| `progress` | `field:{key}`, `field:{key}__goal` | Current value; goal value |

Empty cell = field left unset for that post (identical to leaving it blank in the editor).

### 5.3 Relationships (v1.2 dependency)

A `rel:{key}` cell holds a delimited (`|`) list of target references — titles, slugs, or numeric IDs. On import the resolver:

1. Looks each token up within the relationship's `target_cpt` (slug match first, then exact title), collecting stable IDs.
2. Writes the relationship value via the v1.2 storage, and if the field is reciprocal, syncs the inverse through `PRE_Relationship_Sync` (idempotent, loop-guarded).
3. Reports unresolved tokens in the preview (e.g. `rel:speakers → "Dr. Nobody" not found`) rather than silently dropping them.
4. Supports a **two-pass** mode: import every row first (so intra-file references exist), then resolve relationships — for files where rows reference each other.

This is why CSV import is sequenced after relationships: importing a listing *and* its agent, or a session *and* its speakers, is one cell, rename-proof, and optionally two-way.

### 5.4 Manual grouping items (advanced escape hatch)

Authored repeater cards don't fit flat CSV, and the common "connection" case is covered by relationships (§5.3). For the rare bulk-authored-card need, a `grouping:{key}` column may contain a JSON array of item objects (`[{"heading":"…","supporting_text":"…","link_post_id":123}, …]`), validated by `PCPTPages_Validator` exactly as through the editor. This is documented as advanced, kept out of the default template unless the CPT has a manual grouping the user opts to include, and deliberately not "solved" further until a client needs it.

## 6. Import flow

1. **Upload** a CSV on the CPT's Import screen (or paste/drag). BOM and common encodings handled; delimiter auto-detected with an override.
2. **Auto-map** columns. Template headers match 1:1; a foreign CSV surfaces a mapping UI (CSV column → CPT field), with unmapped columns clearly shown and skippable.
3. **Choose the key column** (default `slug`; or `external_id` → `_pcptpages_import_key`) and the create/update policy (create-only, update-only, or upsert).
4. **Dry-run preview.** Every row parsed and validated through `PCPTPages_Validator`; the preview shows a table of rows with per-cell errors/warnings, a count of "will create / will update / will skip," and blocks commit if any row in an all-or-nothing mode fails. Row-level mode lets valid rows through and lists the rejected ones.
5. **Commit** (batched — §9), writing through existing code paths.
6. **Report.** Per-row outcome (created/updated/skipped/failed + reason), downloadable as a results CSV. Failures are addressable by fixing the file and re-importing (idempotent).

## 7. Export

"Export to CSV" on the CPT screen produces the **same column schema** as the template, populated from selected/all posts of the CPT (respecting current filters). It is the round-trip half (edit in Excel → re-import updates via the key column) and, in practice, the template users trust most because it's a real, valid example. Export includes post fields, taxonomies, and relationships (as `|`-delimited titles or slugs — human-readable and re-importable).

## 8. Validation & error reporting

- Every cell runs through the **existing** validator methods (post-field value validation, enum checks, relationship resolution, taxonomy existence). No parallel validation logic — one source of truth, identical to the editor and connector.
- Errors are **located** (row N, column `field:track`) and **actionable** (`"advanced" is not allowed; expected one of: intro | intermediate | advanced`).
- Preview distinguishes **errors** (block the row) from **warnings** (e.g. a featured-image URL that will be sideloaded; a relationship target resolved by title rather than slug).
- Strict discipline: bad data is rejected at preview, never written and never silently coerced.

## 9. Large files & performance

- Parse and validate stream/chunked; never load a huge file wholly into memory.
- Commit in **batches via Action Scheduler** (already a dependency pattern in the stack — FlowMint uses it) or batched AJAX with a progress bar, so multi-thousand-row imports complete on shared hosting without timeouts.
- Media sideloading (featured images by URL) is rate-aware and resumable; a failed image sideload is a row warning, not a hard failure of the record.
- Idempotency makes a resumed/re-run import safe.

## 10. Admin UI

`includes/Admin/class-pre-import-export.php` (new). On each CPT's management area, an **Import / Export** tab (alongside Groupings / Post Fields / Relationships):

- **Template:** "Download CSV template" (§5.1) and a one-line explainer.
- **Export:** "Export to CSV" (all / filtered / selected).
- **Import:** upload → map → key column + policy → **Preview** (the validation table) → **Commit** → results, with a progress bar for large files.

The UI's job is to make the safe path obvious: download the template, fill it, preview, commit — with the preview as the guardrail that nothing bad gets in.

## 11. Connector / MCP (optional, thin)

Primary surface is the admin UI. A minimal connector affordance is worth exposing so AI-assisted migrations can drive it: `postruntime_import_csv` (accepts a CSV payload or URL + the same map/key/policy options, runs the same validated dry-run/commit) and `postruntime_export_csv`. These reuse the import/export engine exactly; no separate logic. Preflight surfaces the per-CPT column schema so an AI session can generate a conformant CSV directly.

## 12. Edge cases (decisions)

- **Encoding / BOM / Excel quirks:** detect and normalize UTF-8 (incl. BOM); handle Excel's CRLF and quoted commas/newlines; configurable delimiter.
- **Dates & timezones:** normalized to the field's stored format + the existing sortable/UTC companion meta; ambiguous formats are a preview warning.
- **Missing relationship targets:** reported per cell; optional two-pass; never silently dropped.
- **Duplicate key values within a file:** flagged in preview (which row wins is explicit, not accidental).
- **Featured image by URL:** sideloaded into the media library once and reused by URL hash to avoid re-downloading on re-import; ID passthrough when the value is numeric.
- **Status column:** `publish` / `draft` / `pending` etc. validated; unknown status is an error.
- **Author column:** by login/email/ID; unknown author is a warning that falls back to the importing user.
- **Very wide files / unknown columns:** unmapped columns are listed and skipped, never guessed.
- **Partial failure:** row-level mode commits valid rows and returns a failures CSV; all-or-nothing mode blocks on any error. User chooses.

## 13. Backward compatibility

Purely additive. No change to storage, rendering, existing groupings/post fields/relationships, the connector, or the front end. Import/export are opt-in per CPT and per action. A site that never opens the Import tab behaves exactly as today.

## 14. Success criteria

1. **A non-technical user loads a directory from a spreadsheet without help** — downloads the template, fills it, previews, commits, and every record lands in the right fields.
2. **Re-import updates, never duplicates** — the same file re-run against the key column updates in place; verified.
3. **Bad data cannot get in** — invalid enum, malformed date, unresolved relationship, over-cap value are all caught in the dry-run preview with located, actionable messages; nothing is written.
4. **Round-trip is lossless** — export → re-import reproduces the same content (post fields, taxonomies, relationships) with no drift.
5. **Relationships import correctly** — a `rel:` column of titles/slugs resolves to stable IDs and (reciprocal) syncs the inverse.
6. **Scale holds** — a multi-thousand-row file imports on shared hosting via batching without timeouts, with progress.
7. **One validation source** — a greppable check shows import validates through `PCPTPages_Validator`, not a parallel implementation.
8. **Template can't drift** — adding a field and re-downloading the template yields the new column with its format hint automatically.
9. **Docs complete** — this contract, ROADMAP/ARCHITECTURE updates, CONNECTOR_SPEC (if the MCP tools ship), and a short user-facing "Importing content" guide.

## 15. Phased build plan

Mirrors the house structure. Detail lands in `docs/ROADMAP.md`.

| Phase | Title | Est. hours | Output |
|---|---|---|---|
| A | Planning + this design contract | 6 | This doc + ROADMAP/ARCHITECTURE updates |
| B | Schema → template + export | 12 | Schema-derived template generator (headers/instructions/example), CSV export using the identical column schema, per-display-type format map |
| C | Parser + validated dry-run preview | 14 | Streaming CSV parse, column auto-map + mapping UI, full validation through `PCPTPages_Validator`, located per-row/cell preview |
| D | Commit engine (batched) + idempotent keying | 12 | Batched writes via existing code paths, key-column upsert (`_pcptpages_import_key`), media sideload, results report |
| E | Relationship import + two-pass resolve | 8 | `rel:` resolution to stable IDs, reciprocal sync, two-pass mode, unresolved-target reporting |
| F | Admin UI + optional connector tools | 10 | Import/Export tab, progress UI; `postruntime_import_csv` / `postruntime_export_csv` reusing the engine; preflight column schema |
| G | Scale + edge-case hardening + docs | 8 | Large-file/timeout hardening, encoding/date/dup edge cases, user "Importing content" guide, pressure test on a real migration |
| **Total** | | **~70 hours** | |

---

**End of design contract.** Phase A deliverable. Phase B begins after founder approval of this doc and the linked ROADMAP updates. Sequenced after Relationship Fields (v1.2), on which §5.3 depends.
