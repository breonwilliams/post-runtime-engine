# Post Runtime Engine — Connector Specification

**Document status:** Phase 3 design contract — locks the REST + MCP surface before implementation
**Author:** Breon Williams + Claude (Phase 3 planning)
**Last updated:** 2026-05-08
**Plugin version targeted:** 0.2.0 (introduces connector)
**REST namespace:** `post-runtime/v1`
**REST base:** `/wp-json/post-runtime/v1/connector/`

This document is the contract between the connector implementation, the MCP tool layer, and any AI agent (Claude Cowork, custom integrations, third-party tooling) that drives the plugin without using its admin UI. Anything not in this spec is not part of the connector's public surface.

The contract is deliberately conservative — every primitive needed to build a CPT-backed site end-to-end is here, nothing extra. Internal data-layer functions (e.g. `PRE_Source_Resolver::resolve()`) are deliberately not exposed; agents go through the post-data endpoint and let the renderer handle resolution.

---

## 1. Design principles

The connector is shaped by five rules. Implementation choices that conflict with any of these get pushed back, not relaxed.

1. **Shapes mirror the data layer exactly.** `PRE_Validator::validate_cpt`, `validate_grouping`, and `validate_grouping_item` define the canonical shapes for CPTs, groupings, and items. The connector returns and accepts those shapes verbatim — no field renaming, no flattening, no shape massage. If a shape change is needed, it lands in the validator first and propagates outward.
2. **Strict validation at every write.** The connector calls into the validator for every `POST` and `PUT`. Validation failures return `422 Unprocessable Entity` with the validator's `WP_Error` code as the response body's `code` field. AI agents read these codes to self-correct.
3. **Idempotency through `connector_version`.** CPT and grouping definitions carry a `connector_version` integer that auto-increments on every update. `PUT` requests must include the version they're updating; mismatched versions return `409 Conflict`. This makes concurrent agent activity safe and prevents lost updates.
4. **No silent migrations.** The connector never rewrites stored data to fit a new shape. Old data either passes the current validator or returns a typed error explaining what's wrong; the agent decides how to fix it.
5. **Introspection endpoints are first-class.** AI agents need to know what icons are available, what variants exist, what positions are valid — before writing data. Introspection endpoints (`/icons`, `/variants`, `/positions`) are part of the v1 spec, not afterthoughts.

---

## 2. Authentication and authorization

### Auth method: WordPress Application Passwords

All connector endpoints require an authenticated user. Authentication uses WordPress's built-in Application Passwords feature (core since WP 5.6) — agents send a per-user, per-app revocable token as HTTP Basic auth.

**Header format:**

```
Authorization: Basic base64(username:application_password)
```

This matches the pattern Form Runtime Engine and Promptless WP use; agents already integrated with those plugins reuse the same credentials.

**Why not connector tokens?** Earlier drafts considered a `pre_connector_token` option (similar to a session token). Rejected because (a) Application Passwords are the WordPress-native pattern, (b) revocation is built in (user can revoke any individual app password without affecting others), (c) the auth maps to a real WP user with real capabilities, which makes capability checks meaningful instead of "this token can do anything."

### Capability matrix

Each endpoint requires a capability. The connector's auth handler verifies the authenticated user has the capability for the resource being accessed, otherwise returns `403 Forbidden`.

| Resource | Read | Write |
|---|---|---|
| CPT definitions | `manage_options` | `manage_options` |
| Grouping definitions | `manage_options` | `manage_options` |
| Post groupings | `read` (subject to post visibility) | `edit_post` (per-post) |
| Preview render | `read` (subject to post visibility) | n/a |
| Introspection (`/icons`, `/variants`, `/positions`, `/preflight`) | authenticated | n/a |

### Failure modes

| Status | When | Response body |
|---|---|---|
| `401 Unauthorized` | No auth header, malformed, or invalid credentials | `{"code":"pre_unauthenticated","message":"..."}` |
| `403 Forbidden` | Authenticated but lacks capability | `{"code":"pre_forbidden","message":"...","required_capability":"..."}` |

---

## 3. Rate limiting

Per-user, per-route, transient-backed. Defaults match the FRE connector: **60 requests per minute** per user per route. Bursts are allowed up to the limit; sustained traffic above the limit returns `429 Too Many Requests` with a `Retry-After` header (seconds until window reset).

Rate-limit responses include:

```json
{
  "code": "pre_rate_limited",
  "message": "Rate limit exceeded. Retry after 23 seconds.",
  "retry_after": 23
}
```

The limit is intentionally generous for AI agents (Cowork builds typically issue 10–50 requests per resource setup, well below 60/min). It exists to protect the site against runaway scripts, not to throttle legitimate use. The limit is filterable via `pre_connector_rate_limit` (per-route override) for site operators with unusual needs.

---

## 4. Common patterns

### Error response shape

Every `4xx` and `5xx` response has the same shape:

```json
{
  "code": "pre_invalid_variant",
  "message": "Invalid variant 'card-fancy'. Allowed: compact-grid, card-grid, featured-card, horizontal-row, gallery.",
  "data": {
    "status": 422,
    "details": { ... optional, error-specific context ... }
  }
}
```

The `code` field is stable across versions; the `message` is human-readable and may change. AI agents should branch on `code`, not message text.

### Idempotency: `connector_version`

CPT and grouping definitions carry an integer `connector_version` field. The validator increments it on every successful update. `PUT` requests must include the expected version:

**Request:**

```http
PUT /post-runtime/v1/connector/cpts/listing HTTP/1.1
Content-Type: application/json
If-Match: 3

{ ... CPT shape ... }
```

Or, equivalently, in the request body:

```json
{
  "connector_version": 3,
  "label_singular": "Listing",
  ...
}
```

If the stored version is greater than the submitted version, the response is `409 Conflict`:

```json
{
  "code": "pre_version_conflict",
  "message": "CPT 'listing' has been updated since you read it (server version 5, you sent 3).",
  "data": {
    "status": 409,
    "current_version": 5,
    "submitted_version": 3
  }
}
```

The agent's recovery path: `GET` the resource to fetch the current version, merge or rebase its changes, retry the `PUT`.

### Pagination

List endpoints (`GET /cpts`, `GET /cpts/{slug}/groupings`) return all resources in a single response — there is no pagination in v1. Reasoning: the expected scale is small (≤20 CPTs per site, ≤10 groupings per CPT). If a site grows beyond that, paginate-by-default lands in v1.1.

`GET /icons` returns all icons (currently 53) in one response for the same reason.

### Content type

All requests and responses use `application/json` with UTF-8 encoding. The connector rejects non-JSON request bodies with `415 Unsupported Media Type`.

### Timestamps

All timestamps are ISO 8601 in UTC: `2026-05-08T14:23:00Z`. The connector reads from WordPress's stored timestamps (which are UTC if `gmt_offset` is configured correctly) and never converts to local time. Agents that need local time convert client-side.

---

## 5. Resources

### 5.1 Preflight

`GET /post-runtime/v1/connector/preflight`

Site-readiness check. Returns version info, registered CPTs, capability flags, **and the authoring rulebook** (`critical_rules` + `field_name_hints`) — the latter two distill real authoring failures into machine-readable guidance so agents can author correctly without trial-and-error.

Agents should call preflight before any content-creation work and SHOULD pass `critical_rules` to their planner before generating posts. Skipping preflight means the agent re-discovers each rule the hard way.

**Response:**

```json
{
  "plugin_version": "0.3.0",
  "data_version": "0.1.0",
  "wp_version": "6.5.2",
  "rest_namespace": "post-runtime/v1",
  "rest_base": "/wp-json/post-runtime/v1/connector",
  "user": {
    "id": 1,
    "login": "admin",
    "can_manage_cpts": true,
    "can_manage_groupings": true
  },
  "registered_cpts": ["listing", "attorney"],
  "promptless_active": true,
  "promptless_version": "1.3.2",
  "critical_rules": {
    "post_content_is_html": "post_content is plain HTML for the WP editor area, with <p> tags for paragraphs. Do NOT wrap content in <![CDATA[...]]> — that XML idiom belongs to SOAP and WordPress importer XML; in JSON-typed connector parameters it is stored verbatim and leaks into the rendered body. The connector strips a leading <![CDATA[ and trailing ]]> from incoming post_content as a defensive net and adds a \"post_content_cdata_stripped\" warning when it fires — but treat that as a safety net, not a feature.",
    "groupings_creation_pattern": "Two ways to populate groupings on a post: (1) pass `groupings` inline with create_post for atomic creation in a single call; (2) call set_post_groupings after a bare create_post. Both work. set_post_groupings fully replaces all groupings — to update one grouping without touching others, use the read-modify-write pattern (get_post_groupings → modify → set_post_groupings). update_post also accepts a groupings field for atomic edits.",
    "cross_cpt_item_icons": "When a grouping item links to another CPT via link_post_id, set per-item icon_id explicitly to reflect what the item IS (e.g. icon_id:\"user\" on a Lead Architect featured-card item that links to an architect post). The renderer's default_icon fallback is link-aware: when link_post_id is set, it tries the LINKED post's CPT default_icon first, then falls back to the host post's default_icon. Setting icon_id explicitly bypasses both fallbacks.",
    "compact_grid_strips_image": "compact-grid and horizontal-row are icon-only variants by design. Any image_id on an item in these variants is dropped at render time — set icon_id on each item, or rely on the linked-CPT / host-CPT default_icon fallback chain. card-grid and featured-card variants accept either icon_id OR image_id (mutually exclusive; validator rejects both being set on the same item).",
    "link_post_id_canonical": "For internal links to same-site posts, prefer link_post_id over a literal URL. The renderer resolves it via get_permalink() at render time, which makes stored data domain-portable across staging → production migrations and after permalink-structure changes. The literal `link` field is preserved as a fallback when the referenced post has been trashed/deleted.",
    "postgrid_grid_balance": "Postgrid sections render posts_per_page items in a grid_columns grid. Promptless's design optimizer auto-balances these when only one is explicit (defer-to-explicit on the unset field). Send only posts_per_page=4 → optimizer picks grid_columns='4'. Send only grid_columns='4' → optimizer picks posts_per_page=8. Both explicit → leave alone (your choice). Both omitted → renderer defaults align (6+3). Awkward post counts (5, 7, 11) minimize orphan slots automatically.",
    "featured_card_max_one": "featured-card variant has max_items=1 enforced by the validator. featured-card is for ONE prominent item per grouping (a Lead Architect, a Schedule a Tour CTA, a Currently Featured project). For multi-item collections of cards-with-images use card-grid."
  },
  "field_name_hints": {
    "groupings_item_shape": {
      "compact-grid":   ["heading", "icon_id", "link", "link_post_id", "link_text", "link_target"],
      "horizontal-row": ["heading", "icon_id"],
      "gallery": ["image_id", "heading"],
      "card-grid":      ["heading", "supporting_text", "icon_id", "image_id", "link", "link_post_id", "link_text", "link_target"],
      "featured-card":  ["heading", "supporting_text", "icon_id", "image_id", "link", "link_post_id", "link_text", "link_target"]
    },
    "cpt_definition":      ["slug", "label_singular", "label_plural", "supports", "public", "has_archive", "show_in_rest", "show_in_menu", "menu_position", "menu_icon", "taxonomies", "capability_type", "description", "rewrite", "hero_layout", "hero_image_position", "hero_image_aspect", "default_icon"],
    "grouping_definition": ["key", "label", "description", "default_variant", "default_position", "default_source", "max_items", "heading_required", "supporting_text_required", "link_required", "icon_or_image_required", "gallery_image_aspect"],
    "notes": "icon_id and image_id are mutually exclusive on a single item. featured-card has max_items=1 enforced. Compact-grid and horizontal-row are icon-only — image_id is dropped at render time. link_post_id is preferred over literal `link` URLs for internal references; both can be set (link is the fallback when link_post_id resolution fails)."
  }
}
```

**Field reference:**

| Field | Type | Purpose |
|---|---|---|
| `plugin_version` | string | PRE plugin version. |
| `data_version` | string | Schema version of the stored CPT/grouping data shape. Bumps when validator-enforced shape changes. |
| `wp_version` | string | WordPress core version on the host. |
| `rest_namespace` | string | The REST namespace this connector lives under. |
| `rest_base` | string | Absolute URL prefix for connector routes. |
| `user.{id,login,can_manage_cpts,can_manage_groupings}` | object | Authenticated user context + per-action capability flags. |
| `registered_cpts` | string[] | Slugs of CPTs currently registered through PRE on this site. |
| `promptless_active` | bool | Whether the Promptless WP plugin is loaded. When false, agents may want to warn the operator that styling will use documented fallbacks. |
| `promptless_version` | string\|null | Promptless WP version, or `null` when inactive. |
| `critical_rules` | object | **Authoring rulebook.** Keyed map of `rule_id → human-readable instruction`. Distilled from real authoring failures. Every key is referenced by smoke tests so the suite stays in sync with the rulebook. The connector enforces SOME rules via validation (e.g. `featured_card_max_one`) but several describe AI-side patterns the connector cannot detect at write time (e.g. `cross_cpt_item_icons`, `postgrid_grid_balance`). Agents should ingest these into their planning prompt before generating posts. |
| `field_name_hints.groupings_item_shape` | object | Per-variant list of accepted field names for grouping items. Field names not in the list are silently dropped by `PRE_Validator`. Use this to avoid invented field names like `title` (the canonical name is `heading`) or `subtitle` (the canonical name is `supporting_text`). |
| `field_name_hints.cpt_definition` | string[] | Accepted top-level fields on a CPT registration payload. |
| `field_name_hints.grouping_definition` | string[] | Accepted top-level fields on a grouping definition payload. |
| `field_name_hints.notes` | string | Cross-cutting notes that don't fit a per-field row. |

**Stability contract for `critical_rules`:** the set of keys is part of the connector contract. A key is removed only after the corresponding smoke-test assertion is removed AND `CHANGELOG.md` documents the removal. New keys are added freely as new authoring failure modes are discovered. Agents should treat unknown keys as informational (read-them-but-don't-crash), not as schema violations.

**Stability contract for `field_name_hints`:** the per-variant arrays mirror `PRE_Validator`'s allow-list. When the validator gains support for a new field name, this list updates in lockstep. When the validator drops a field name, this list updates in lockstep. The `groupings_item_shape` keys (variant names: `compact-grid`, `horizontal-row`, `card-grid`, `featured-card`, `gallery`) match `PRE_Validator::VARIANTS` exactly. Gallery items take `image_id` + optional `heading` (caption) only — `icon_id` is rejected by the validator, and `supporting_text`/`link` are accepted-but-not-rendered (the tile opens the lightbox). Items without `image_id` are valid but skipped at render. See `critical_rules.gallery_variant` and docs/GALLERY_VARIANT_DESIGN.md.

---

### 5.2 CPTs

CPT shape (matches `PRE_Validator::validate_cpt`):

```json
{
  "slug": "listing",
  "label_singular": "Listing",
  "label_plural": "Listings",
  "supports": ["title", "editor", "thumbnail", "excerpt"],
  "public": true,
  "has_archive": true,
  "show_in_rest": true,
  "menu_icon": "dashicons-admin-home",
  "capability_type": "post",
  "rewrite": { "slug": "listings", "with_front": false },
  "taxonomies": ["category"],
  "hero_layout": "split",
  "hero_image_position": "left",
  "hero_image_aspect": "landscape",
  "default_icon": "home",
  "connector_version": 3,
  "created_at": "2026-05-08T12:00:00Z",
  "updated_at": "2026-05-08T14:30:00Z"
}
```

Hero fields control the single-page layout above the post body. All three default to safe values (`stacked` / `left` / `square`) when omitted at registration:

- `hero_layout` (`stacked` | `split`) — `stacked` renders the featured image as a 16:9 banner above the title (best for editorial CPTs: events, courses, articles). `split` renders the image side-by-side with the title at desktop breakpoints (best for profile-shaped CPTs: real estate listings, attorney bios, team pages).
- `hero_image_position` (`left` | `right`) — only meaningful for `split`. Stacked layouts always place the image above the text.
- `hero_image_aspect` (`square` | `landscape` | `wide`) — only meaningful for `split`. Pick the aspect that matches the natural shape of the post's photos so they crop cleanly: `square` (1:1) for headshots and team pages, `landscape` (4:3) for property photos and product shots, `wide` (16:9) for cinematic banner imagery. Stacked layouts always use a 16:9 banner regardless.

The `default_icon` field is an optional icon ID from `PRE_Icon_Library` (use `postruntime_list_icons` to discover the available set). It serves as a fallback visual cue when a grouping item has no per-item icon and no image — particularly relevant for `compact-grid` and `horizontal-row` variants, which are icon-only by design and strip any `image_id` on the way to render. Auto-source items (`taxonomy_match`, `child_posts`) that pull from posts without `_pre_icon` meta also pick up this default. Empty string means "no fallback"; matching items render iconless. Pick a generic shape that fits the CPT (e.g. `home` for listings, `user` for team members, `calendar` for events).

`connector_version`, `created_at`, `updated_at` are managed by the registry and ignored on writes (the registry assigns them). `slug` is the primary key and cannot be changed via `PUT`.

#### List CPTs

`GET /cpts` → `200 OK`

```json
{ "cpts": [ { ...CPT shape... }, { ...CPT shape... } ] }
```

#### Register a CPT

`POST /cpts`

**Request body:** CPT shape (without `connector_version`, `created_at`, `updated_at`).

**Success:** `201 Created`, response body is the registered CPT shape with all server-assigned fields populated.

**Failure:** `422` with one of:
- `pre_invalid_slug` — slug reserved (`post`, `page`, `attachment`, etc.) or malformed
- `pre_slug_too_long` — slug > 20 characters (WP CPT limit)
- `pre_missing_label_singular`
- `pre_missing_label_plural`
- `pre_invalid_supports` — supports list contains unknown value
- `pre_invalid_hero_layout` — `hero_layout` not one of `stacked`, `split`
- `pre_invalid_hero_image_position` — `hero_image_position` not one of `left`, `right`
- `pre_invalid_hero_image_aspect` — `hero_image_aspect` not one of `square`, `landscape`, `wide`
- `pre_invalid_default_icon` — `default_icon` is non-string or not a registered icon in `PRE_Icon_Library`

#### Get a CPT

`GET /cpts/{slug}` → `200 OK` with CPT shape, or `404` (`pre_cpt_not_found`).

#### Update a CPT

`PUT /cpts/{slug}`

Requires `connector_version` in body or `If-Match` header. Slug cannot be changed (returns `400 Bad Request` `pre_immutable_field` if attempted).

**Success:** `200 OK` with the updated CPT shape (new `connector_version`).

**Failure:** any validator code; `409 Conflict` on version mismatch.

#### Delete a CPT

`DELETE /cpts/{slug}` → `200 OK` on success, body `{ "deleted": true, "slug": "...", "purged": <bool> }`.

The deletion unregisters the CPT from `register_post_type()` on the next request and removes its grouping definitions. **Post data is preserved by default** (matches the data-protection pattern) — the user can restore it by re-registering the CPT with the same slug. To purge post data too, send `?purge_data=1`. Returns `404` if the CPT doesn't exist.

---

### 5.3 Groupings

Grouping shape (matches `PRE_Validator::validate_grouping`):

```json
{
  "key": "quick_specs",
  "label": "Quick Specs",
  "description": "Property specs row.",
  "default_position": "above_main",
  "default_variant": "horizontal-row",
  "default_source": "manual",
  "max_items": 0,
  "heading_required": true,
  "supporting_text_required": false,
  "link_required": false,
  "icon_or_image_required": false,
  "connector_version": 1
}
```

Source can be a string (`"manual"` or `"child_posts"`) or an object form for the auto-populating modes:

```json
// Match by shared taxonomy term — "related Articles in same topic"
"default_source": {
  "type": "taxonomy_match",
  "taxonomy": "practice_area",
  "limit": 6,             // optional, defaults to 6, max 100
  "exclude_self": true    // optional, defaults to true
}

// Match by shared post-meta value — "more from this agent / employer / business"
// (MIRROR shape: same-CPT siblings sharing the current post's value)
"default_source": {
  "type": "meta_match",
  "meta_key": "_agent_id", // EXACTLY ONE of meta_key/field_key; max 64 chars; may start with _ for private meta
  "limit": 6,              // optional
  "exclude_self": true     // optional
}

// Cross-CPT reverse lookup (data-version 0.5.0) — parent pulls children:
// an Agent page pulling the Listings whose "agent" post-field names it.
"default_source": {
  "type": "meta_match",
  "field_key": "agent",            // EXACTLY ONE of meta_key/field_key — a PRE post-field key,
                                   // resolved to _pcptpages_field_agent storage. Prefer this form:
                                   // post-field values are the meta this connector writes.
  "post_type": "listing",          // optional; CPT to query (defaults to the host CPT)
  "match_against": "current_title",// optional; same_key (default) | current_id | current_slug | current_title
  "limit": 12,
  "exclude_self": true
}
```

**Choosing between modes** (also surfaced in `preflight.critical_rules.choosing_a_source_mode`):

- `manual` — items curated per post (e.g. a Listing's hand-picked Features)
- `child_posts` — natural WP hierarchy (a Course post with Lesson child posts)
- `taxonomy_match` — relationship is "shares a category / tag / term"
- `meta_match` — relationship is a stored value. Two shapes controlled by `match_against`:
  - **Mirror** (`same_key`, default): the resolver reads the current post's own value for the configured key and finds other posts *in the same CPT* with the same value — "more from this agent" on a listing page.
  - **Reverse lookup** (`current_id` / `current_slug` / `current_title`, data-version 0.5.0): the resolver finds posts whose value for the key equals the current post's ID, slug, or title — the parent-pulls-children shape, usually combined with `post_type` to query a *different* CPT. Use `current_title` when the child's post-field stores the human-readable name shown on cards (e.g. a Listing's visible "Agent" field); use `current_id` with a `hidden`-position post-field when you want a rename-proof link.

`meta_match` short-circuits to an empty array when the derived match value is empty (and when a configured `post_type` isn't registered), so it is safe to define before every post has its fields populated.

**`field_key` vs `meta_key`:** `field_key` references a PRE post-field by its definition key and is resolved to the `_pcptpages_field_{key}` storage key at render time — prefer it, because post-field values are exactly the meta this connector can write (`set_post_field_values` / `values` on create). `meta_key` remains the raw escape hatch for meta written by other plugins or code. Exactly one of the two is required (`pcptpages_meta_match_key_conflict` if both).

**Auto-registration of meta keys (v0.4.0+):** When a grouping is defined with a `meta_match` source using a raw `meta_key`, PRE auto-calls `register_post_meta()` for that key with `show_in_rest: true` and an `edit_post` auth callback — on the post type being *queried* (the host CPT for the mirror shape; the configured `post_type` for cross-CPT reverse lookups, since that's where the values live). This makes the meta key writable through the standard WP REST API (`POST /wp/v2/{cpt}/{id}` with `meta: {your_key: value}`) on sites that don't have a field plugin (ACF, MetaBox, Pods) installed. Sites that do use a field plugin can opt out via the `pre_auto_register_meta_match_keys` filter. Underscore-prefixed keys are accepted (the standard WordPress private-meta convention). `field_key` sources skip auto-registration entirely — post-field meta is already PRE-managed.

`max_items: 0` means no cap. `featured-card` variant requires `max_items: 1` (validator enforces).

#### List groupings for a CPT

`GET /cpts/{slug}/groupings` → `200 OK`

```json
{ "groupings": [ { ...grouping shape... }, ... ] }
```

#### Define a grouping

`POST /cpts/{slug}/groupings`

**Request body:** grouping shape without `connector_version`.

**Success:** `201 Created`.

**Failure:** `422` with codes including:
- `pre_invalid_grouping_key`
- `pre_duplicate_grouping_key`
- `pre_invalid_variant` — not in {compact-grid, card-grid, featured-card, horizontal-row, gallery}
- `pre_invalid_position` — not in {above_main, below_main, sidebar}
- `pre_invalid_source` — source not one of {manual, child_posts, taxonomy_match, meta_match}
- `pre_featured_card_max_items` — featured-card requires max_items: 1
- `pre_taxonomy_match_needs_object` — taxonomy_match given as bare string
- `pre_invalid_source_taxonomy` — taxonomy doesn't exist or is missing
- `pre_meta_match_needs_object` — meta_match given as bare string
- `pre_invalid_source_meta_key` — neither meta_key nor field_key given, or meta_key fails canonical-form check
- `pre_invalid_source_meta_key_length` — meta_key longer than 64 characters
- `pre_meta_match_key_conflict` — both meta_key AND field_key given (exactly one required)
- `pre_invalid_source_field_key` — field_key fails canonical form (lowercase alphanumeric + underscores, no leading underscore)
- `pre_invalid_source_field_key_length` — field_key longer than 64 characters
- `pre_invalid_source_post_type` — post_type not a valid post-type key (form check only; existence is checked at render, failing soft to empty)
- `pre_invalid_source_match_against` — match_against not one of {same_key, current_id, current_slug, current_title}
- `pre_invalid_source_limit` — limit out of [1, 100]
- `pre_invalid_source_exclude_self` — exclude_self not a boolean

#### Get / Update / Delete a grouping

`GET /cpts/{slug}/groupings/{key}` → `200 OK` or `404`

`PUT /cpts/{slug}/groupings/{key}` — same versioning rules as CPT update

`DELETE /cpts/{slug}/groupings/{key}` → `200 OK`, body `{ "deleted": true, "slug": "...", "key": "..." }`. Post data referencing this grouping_key is preserved unless `?purge_data=1` is set.

---

### 5.4 Post groupings

The per-post payload mirrors `PRE_Post_Data::get_groupings` exactly:

```json
{
  "post_id": 1088,
  "post_type": "listing",
  "groupings": [
    {
      "grouping_key": "quick_specs",
      "position": null,
      "variant_override": null,
      "source": "manual",
      "items": [
        {
          "image_id": null,
          "icon_id": "bed",
          "heading": "4 Bedrooms",
          "supporting_text": null,
          "link": null,
          "link_post_id": null
        },
        ...
      ]
    },
    ...
  ]
}
```

`position`, `variant_override` are nullable (null = use grouping definition's default). `items` is empty for auto sources (validator enforces).

#### Get post groupings

`GET /posts/{id}/groupings` → `200 OK` with the shape above.

`404` if post doesn't exist or post type isn't a registered PRE CPT.

#### Set post groupings

`PUT /posts/{id}/groupings`

**Request body:**

```json
{ "groupings": [ ... full groupings array ... ] }
```

This is a **full replacement** — the entire `groupings` array is replaced atomically. The data layer creates a backup of the previous state before the write (recoverable via `PRE_Post_Data::has_backup`).

To update one grouping without touching others, the agent should `GET`, modify the relevant entry in the response, and `PUT` the full array back. This matches the data layer's `set_groupings` semantics; per-grouping patch endpoints were considered and rejected as adding implementation complexity for an edge case (groupings are typically small and full replacement is fine).

**Success:** `200 OK` with the persisted shape (post-validation, normalized).

**Failure:** any item-level validator code (`pre_image_icon_conflict`, `pre_unknown_icon`, `pre_invalid_link`, `pre_invalid_link_post_id`, `pre_unknown_grouping_key`, `pre_duplicate_post_grouping`, `pre_auto_source_has_items`, etc.).

---

### 5.5 Posts (create / update)

The connector exposes a **thin wrapper** around `wp_insert_post` for creating posts in registered CPTs, plus a unified "create with groupings" endpoint that lets an agent register a post and its groupings in one round trip.

#### Create a post

`POST /posts`

**Request body:**

```json
{
  "post_type": "listing",
  "post_status": "publish",
  "post_title": "Modern Family Home in Lake View",
  "post_excerpt": "Recently renovated 4-bedroom home...",
  "post_content": "<p>Full HTML body content...</p>",
  "featured_image_id": 42,
  "groupings": [
    { ...grouping shape... }
  ]
}
```

Only `post_type` and `post_title` are required. `post_status` defaults to `draft`. `groupings` is optional — if present, it's applied via the same path as `PUT /posts/{id}/groupings` after the post is created.

If `featured_image_id` is set, the connector calls `set_post_thumbnail($post_id, $id)`. If that fails (attachment doesn't exist, isn't an image), the post is still created and the response includes a `warnings` array.

**Success:** `201 Created`

```json
{
  "post_id": 1234,
  "permalink": "https://example.com/listings/modern-family-home-in-lake-view/",
  "edit_url": "https://example.com/wp-admin/post.php?post=1234&action=edit",
  "warnings": []
}
```

**Failure:** `422` with codes including:
- `pre_unregistered_post_type` — `post_type` isn't a CPT registered through PRE
- `pre_missing_post_title`
- any item-level validator code if `groupings` was provided and validation failed

If groupings validation fails after the post was created, the post is still rolled back (the connector wraps the create + grouping write in a transaction-equivalent: catch the error, delete the post, return the error). This is a deliberate departure from a "partial success" model — the agent should not have to clean up half-created posts.

#### Update a post (groupings only)

For groupings updates, use `PUT /posts/{id}/groupings` (described above). For `post_title`, `post_content`, etc., the agent uses WordPress's standard REST API (`PUT /wp/v2/{post_type}/{id}`) which is already authenticated and capability-checked. No reason to duplicate that surface here.

---

### 5.6 Preview

`GET /posts/{id}/preview`

Renders the post through `PRE_Renderer::render` and returns the resulting HTML. Useful for visual verification — an agent can post a render to a screenshot service or compare against expected output without navigating the site frontend.

**Response:** `200 OK`

```json
{
  "post_id": 1088,
  "html": "<article id=\"post-1088\" ...>...</article>",
  "css_url": "https://example.com/wp-content/plugins/post-runtime-engine/assets/css/frontend.css?ver=0.2.0",
  "permalink": "https://example.com/pre_demo/modern-family-home-in-lake-view/"
}
```

The HTML is rendered with the **current** stored grouping data — the renderer doesn't accept inline overrides. To preview unsaved changes, the agent must `PUT` the data first (using a draft post if it doesn't want the change to be live).

`html` is the article body only (no `<head>`, no `<header>`, no `<footer>`). Agents that need a complete page render fetch `permalink` directly.

---

### 5.7 Post fields (v1.1)

> **Status:** v1.1 (data-version 0.4.0). Available alongside the v1.0 groupings surface; both field types coexist on every CPT. See `docs/POST_FIELDS_V1_1_DESIGN.md` for the locked design contract.

Post fields are scalar (single-value-per-post) data points with a closed enum of display types and positions. Distinct from groupings (which are repeatable). All field definitions and per-post values pass through `PRE_Validator` with strict-mode shape checks; invalid input rejected at write time with stable error codes.

#### GET `/cpts/{slug}/post-fields`

List all post field definitions for a CPT, in render order.

**Response 200:**

```json
{
  "post_fields": [
    {
      "key": "price",
      "label": "Price",
      "display_type": "currency",
      "card_position": "headline",
      "single_position": "headline",
      "currency_code": "USD",
      "description": "",
      "required": false,
      "options": {},
      "connector_version": 1,
      "created_at": "2026-05-21 14:00:00",
      "updated_at": "2026-05-21 14:00:00"
    }
  ]
}
```

Empty array when the CPT has no post fields. `{ post_fields: [] }` not `{ post_fields: null }` — consumers can iterate without null-guards.

**Errors:** `404 pre_cpt_not_found`.

#### POST `/cpts/{slug}/post-fields`

Create a new post field definition. Returns the created definition.

**Body:**

```json
{
  "key": "price",
  "label": "Price",
  "display_type": "currency",
  "card_position": "headline",
  "single_position": "headline",
  "currency_code": "USD"
}
```

**Required:** `key`, `label`, `display_type`, `card_position`, `single_position`.

**Optional (conditional on display_type):**
- `description` (string ≤ 500 chars)
- `color_intent` (one of `success` / `warning` / `danger` / `info` / `neutral`) — for `badge` and `multi_badge`
- `options` (object mapping option key → `{ label, color_intent? }`) — for `badge` and `multi_badge`
- `icon` (PRE_Icon_Library slug or Iconify code) — for `meta_pair`
- `date_format` (`absolute` / `relative` / `custom`) — for `date`
- `date_format_string` (PHP date format string) — for `date` with format=custom
- `currency_code` (ISO 4217) — for `currency` and `progress`
- `max` (number) — for `rating` (default 5) and `progress` (default 100)
- `unit_label` (string) — for `number_with_label` and `progress`
- `required` (bool, admin-UX hint only)

**Response 201:** the created field definition with `connector_version: 1`, timestamps.

**Errors:** `404 pre_cpt_not_found`, `422 pre_invalid_*` (validation), `422 pre_max_field_count_exceeded` (hard cap of 12).

#### GET `/cpts/{slug}/post-fields/{key}`

Read a single field definition.

**Response 200:** the field shape from the list endpoint.

**Errors:** `404 pre_cpt_not_found`, `404 pre_post_field_not_found`.

#### PUT `/cpts/{slug}/post-fields/{key}`

Update a definition. URL `key` is authoritative (body `key` is forced to match). Optimistic concurrency via `connector_version` — pass the value from the previous read; if it doesn't match the stored version, returns 409.

**Body:** same shape as POST, plus `connector_version` (the value you just read).

**Response 200:** the updated definition.

**Errors:** `404`, `422`, `409 pre_connector_version_mismatch`.

#### DELETE `/cpts/{slug}/post-fields/{key}`

Remove a field definition.

**Response 200:** `{ "deleted": true, "slug": "...", "key": "..." }`.

Per-post values stored at `_pre_field_{key}` are intentionally preserved — orphaned until the post is next saved (the meta box save sweeps them) or until a future explicit cleanup endpoint. Matches the grouping delete behavior; protects against accidental data loss.

**Errors:** `404`.

#### POST `/cpts/{slug}/post-fields/reorder`

Bulk-rewrite the field render order. The submitted list MUST contain exactly the set of currently-defined field keys — no additions, no removals, no duplicates. Order determines render order within each position (especially important for `meta_strip`).

**Body:**

```json
{ "ordered_keys": ["status", "price", "location", "beds", "baths", "sqft", "listed_date"] }
```

**Response 200:** the full post fields list in the new order.

**Errors:** `404`, `422 pre_reorder_keys_mismatch`, `422 pre_reorder_duplicate_keys`.

#### GET `/posts/{id}/field-values`

Read all per-post field values for a single post. Composite display types (`rating`, `progress`) return as arrays.

**Response 200:**

```json
{
  "post_id": 42,
  "post_type": "listings",
  "field_values": {
    "price": "1250000",
    "status": "for_sale",
    "beds": "3",
    "baths": "2",
    "sqft": "1800",
    "rating": { "value": 4.7, "count": 156 }
  }
}
```

**Errors:** `404 pre_post_not_found`, `400 pre_post_type_not_managed`.

#### PUT `/posts/{id}/field-values`

Bulk update field values for a post. Partial-update semantics: fields not present in the payload are left unchanged. To clear a field, pass `null` or empty string.

**Body:**

```json
{
  "values": {
    "price": 1250000,
    "status": "for_sale",
    "rating": { "value": 4.7, "count": 156 }
  }
}
```

Composite types (`rating`, `progress`) accept the array shape `{ value, count }` or `{ value, goal }`. Both halves write to their respective post meta keys (`_pre_field_{key}`, `_pre_field_{key}_count` for rating; `_pre_field_{key}_goal` for progress). To clear a composite, send `null` for the field key (clears both halves).

**Response 200:** the full field values map after the write, so callers can diff to confirm.

**Errors:** `404`, `400`, `422 pre_unknown_field_key`, `422 pre_invalid_*` (per-display-type validation).

#### GET `/posts/{id}/field-visibility`

Read per-post visibility overrides. Fields not in the response use default visibility (visible in both contexts).

**Response 200:**

```json
{
  "post_id": 42,
  "visibility": {
    "price": { "card_hidden": true, "single_hidden": false }
  }
}
```

#### PUT `/posts/{id}/field-visibility`

Write per-post visibility overrides. **Full-replace** semantics (unlike `field-values` which is partial). Send `{ "visibility": {} }` to clear all overrides.

**Body:**

```json
{
  "visibility": {
    "price":       { "card_hidden": true,  "single_hidden": false },
    "agent_phone": { "card_hidden": true,  "single_hidden": false }
  }
}
```

Each entry's `card_hidden` and `single_hidden` are optional (default false). Position overrides are NOT supported — positions stay locked at the CPT level. This is the design coherence principle.

**Response 200:** the full visibility map after the write.

**Errors:** `404`, `422 pre_invalid_visibility_*`.

### 5.8 Introspection

These three endpoints expose the data-layer's enumerations so agents know what's valid before writing.

#### `GET /icons`

Returns the full icon library, grouped by category:

```json
{
  "icons": [
    {
      "id": "bed",
      "label": "Bed",
      "category": "Property & Real Estate",
      "tags": ["bedroom", "sleep", "real-estate", "hotel"]
    },
    ...
  ],
  "categories": ["General", "Property & Real Estate", "Business & Legal", ...]
}
```

The full SVG markup is **not** included in this response — it's only relevant at render time and can balloon payload size. Agents that need to preview an icon visually fetch the rendered post or generate a `<svg>` themselves.

#### `GET /variants`

```json
{
  "variants": [
    { "id": "compact-grid", "label": "Compact grid", "max_items_required": null, "supports_supporting_text": false },
    { "id": "card-grid", "label": "Card grid", "max_items_required": null, "supports_supporting_text": true },
    { "id": "featured-card", "label": "Featured card", "max_items_required": 1, "supports_supporting_text": true },
    { "id": "horizontal-row", "label": "Horizontal row", "max_items_required": null, "supports_supporting_text": false }
  ]
}
```

`max_items_required` of `1` means the variant requires `max_items: 1` in its grouping definition. `supports_supporting_text` flags whether the variant displays the `supporting_text` field (some variants are heading-only).

#### `GET /positions`

```json
{
  "positions": [
    { "id": "above_main", "label": "Above main content" },
    { "id": "below_main", "label": "Below main content" },
    { "id": "sidebar", "label": "Sidebar" }
  ]
}
```

---

## 6. MCP tool layer

The MCP layer is a thin wrapper around the REST endpoints. Each tool calls one REST endpoint and returns its response. Tools do not aggregate across multiple endpoints (no "register-cpt-and-define-three-groupings-in-one-call" tools) — that's the agent's job, and keeping tools 1:1 with REST endpoints means the agent's mental model matches the underlying API.

### Tool naming convention

`postruntime_*` — short, distinct from FRE's `formengine_*` and Promptless's `wordpress_*`, matches the plugin slug. Lock this prefix; renaming MCP tools after adoption breaks every saved agent session referencing them.

### Tool inventory

| MCP tool | Wraps | Purpose |
|---|---|---|
| `postruntime_preflight` | `GET /preflight` | Connector readiness check |
| `postruntime_list_cpts` | `GET /cpts` | List all registered CPTs |
| `postruntime_register_cpt` | `POST /cpts` | Register a new CPT |
| `postruntime_get_cpt` | `GET /cpts/{slug}` | Read one CPT |
| `postruntime_update_cpt` | `PUT /cpts/{slug}` | Update a CPT (with versioning) |
| `postruntime_delete_cpt` | `DELETE /cpts/{slug}` | Unregister a CPT |
| `postruntime_list_groupings` | `GET /cpts/{slug}/groupings` | List groupings for a CPT |
| `postruntime_define_grouping` | `POST /cpts/{slug}/groupings` | Define a grouping |
| `postruntime_get_grouping` | `GET /cpts/{slug}/groupings/{key}` | Read one grouping |
| `postruntime_update_grouping` | `PUT /cpts/{slug}/groupings/{key}` | Update a grouping |
| `postruntime_delete_grouping` | `DELETE /cpts/{slug}/groupings/{key}` | Remove a grouping |
| `postruntime_get_post_groupings` | `GET /posts/{id}/groupings` | Read a post's groupings |
| `postruntime_set_post_groupings` | `PUT /posts/{id}/groupings` | Replace a post's groupings |
| `postruntime_create_post` | `POST /posts` | Create a post (optionally with groupings) |
| `postruntime_preview_post` | `GET /posts/{id}/preview` | Render a post and return HTML |
| `postruntime_list_icons` | `GET /icons` | Icon catalog |
| `postruntime_list_variants` | `GET /variants` | Variant catalog |
| `postruntime_list_positions` | `GET /positions` | Position catalog |
| `postruntime_list_post_fields` | `GET /cpts/{slug}/post-fields` | List post field definitions for a CPT (v1.1) |
| `postruntime_define_post_field` | `POST /cpts/{slug}/post-fields` | Create a post field definition (v1.1) |
| `postruntime_get_post_field` | `GET /cpts/{slug}/post-fields/{key}` | Read one post field definition (v1.1) |
| `postruntime_update_post_field` | `PUT /cpts/{slug}/post-fields/{key}` | Update a post field definition (v1.1) |
| `postruntime_delete_post_field` | `DELETE /cpts/{slug}/post-fields/{key}` | Remove a post field definition (v1.1) |
| `postruntime_reorder_post_fields` | `POST /cpts/{slug}/post-fields/reorder` | Reorder the field render order (v1.1) |
| `postruntime_get_post_field_values` | `GET /posts/{id}/field-values` | Read a post's field values (v1.1) |
| `postruntime_set_post_field_values` | `PUT /posts/{id}/field-values` | Bulk update a post's field values (v1.1) |
| `postruntime_get_post_field_visibility` | `GET /posts/{id}/field-visibility` | Read a post's visibility overrides (v1.1) |
| `postruntime_set_post_field_visibility` | `PUT /posts/{id}/field-visibility` | Write a post's visibility overrides (v1.1) |

Each tool's input schema mirrors the REST endpoint's required + optional parameters. Each tool's description is tuned for AI agent discovery (clear purpose, when to use, common patterns, links to related tools).

### Tool description style

Following the pattern that worked for FRE's MCP tools:

> **Title:** what the tool does, one sentence
> **When to use:** specific signals from the user that should trigger this tool
> **Inputs:** parameter list with required/optional flags
> **Returns:** response shape outline
> **See also:** related tools for the next likely step

Example for `postruntime_register_cpt`:

> Register a new custom post type that Post Runtime Engine will manage. Use this when the user wants to set up a new content type — listings, attorneys, events, courses, team members, services, products. After registration, define the post type's groupings via `postruntime_define_grouping` before creating posts.
>
> **Inputs:** `slug` (required, snake_case, ≤20 chars), `label_singular` (required), `label_plural` (required), `supports` (optional, array of WP supports flags), `public` (default true), `has_archive` (default true), `show_in_rest` (default true), `menu_icon` (optional dashicons class), `taxonomies` (optional array of taxonomy slugs to attach)
>
> **Returns:** the registered CPT shape with `connector_version: 1`, `created_at`, `updated_at`.
>
> **See also:** `postruntime_define_grouping` (next step), `postruntime_list_cpts`, `postruntime_get_cpt`.

---

## 7. End-to-end example: building a real-estate site

This is the canonical agent workflow. It exercises every endpoint group at least once.

```
1. postruntime_preflight
   → confirm connector is live, user has manage_options

2. postruntime_list_icons
   → cache the icon catalog so we know what to use later

3. postruntime_list_variants
   → cache variant rules (which variants need max_items: 1, etc.)

4. postruntime_register_cpt
   → slug: listing, label_singular: Listing, label_plural: Listings,
     supports: [title, editor, thumbnail, excerpt],
     taxonomies: [neighborhood, property_type]
   → returns connector_version: 1

5. postruntime_define_grouping (×3 for three groupings)
   → key: quick_specs, default_variant: horizontal-row,
     default_position: above_main, default_source: manual
   → key: highlights, default_variant: card-grid,
     default_position: below_main, default_source: manual
   → key: cta, default_variant: featured-card,
     default_position: sidebar, max_items: 1, default_source: manual

6. postruntime_create_post
   → post_type: listing, post_title: Modern Family Home in Lake View,
     post_status: draft,
     groupings: [
       { grouping_key: quick_specs, items: [
           { icon_id: bed, heading: '4 Bedrooms' },
           { icon_id: bath, heading: '2 Bathrooms' },
           { icon_id: ruler, heading: '1,800 sqft' }
       ]},
       { grouping_key: highlights, items: [...] },
       { grouping_key: cta, items: [{
           icon_id: calendar,
           heading: 'Schedule a tour',
           supporting_text: 'No pressure...',
           link: '#schedule-tour'
       }]}
     ]
   → returns post_id: 1088, permalink, edit_url

7. postruntime_preview_post
   → post_id: 1088
   → returns rendered HTML for visual verification

8. (optional) Create more posts via postruntime_create_post

9. (optional) Update the listing CPT later:
   postruntime_get_cpt → modify → postruntime_update_cpt
   (with connector_version from step 4 incremented)
```

Total: ~10 MCP calls to stand up a CPT with three groupings and one populated post. Realistic builds run 30–80 calls per site (multiple posts, occasional adjustments). All within the 60-req/min rate limit.

---

## 8. Error code catalogue

Every error code an agent might encounter, sorted by source.

### Validator-sourced (422 Unprocessable Entity)

From `PRE_Validator`:

| Code | Trigger | Recovery |
|---|---|---|
| `pre_invalid_cpt` | CPT shape malformed | Re-read the CPT shape spec |
| `pre_invalid_slug` | Slug reserved or not snake_case | Pick a different slug |
| `pre_slug_too_long` | Slug > 20 chars | Shorten the slug |
| `pre_missing_label_singular` | Required field missing | Add `label_singular` |
| `pre_missing_label_plural` | Required field missing | Add `label_plural` |
| `pre_invalid_supports` | `supports` contains unknown value | Use only documented WP supports flags |
| `pre_invalid_grouping` | Grouping shape malformed | Re-read the grouping shape spec |
| `pre_invalid_grouping_key` | Key not snake_case | Use snake_case |
| `pre_duplicate_grouping_key` | Key already defined for this CPT | Pick a different key |
| `pre_invalid_variant` | Variant not in {compact-grid, card-grid, featured-card, horizontal-row, gallery} | Use `postruntime_list_variants` to verify |
| `pre_invalid_gallery_image_aspect` | `gallery_image_aspect` not in {16:9, 4:3, 1:1, 4:5} | Use one of the four shared aspect values |
| `pre_gallery_icon_rejected` | Gallery-variant item has `icon_id` | Galleries render images only — set `image_id` |
| `pre_invalid_position` | Position not in {above_main, below_main, sidebar} | Use `postruntime_list_positions` |
| `pre_invalid_source` | Source not in {manual, child_posts, taxonomy_match, meta_match} | Pick a valid source |
| `pre_featured_card_max_items` | featured-card variant requires max_items: 1 | Set max_items to 1 |
| `pre_taxonomy_match_needs_object` | taxonomy_match given as bare string | Use object form: `{type, taxonomy}` |
| `pre_invalid_source_taxonomy` | Referenced taxonomy doesn't exist | Register the taxonomy first |
| `pre_meta_match_needs_object` | meta_match given as bare string | Use object form: `{type, meta_key}` or `{type, field_key}` |
| `pre_invalid_source_meta_key` | Neither meta_key nor field_key given, or meta_key fails canonical-form check | Provide exactly one key; meta_key is lowercase alphanumeric + underscores, one leading underscore allowed |
| `pre_invalid_source_meta_key_length` | meta_key longer than 64 chars | Shorten the key — 64 char cap |
| `pre_meta_match_key_conflict` | Both meta_key and field_key given | Provide exactly one — field_key for PRE post-fields, meta_key for raw meta |
| `pre_invalid_source_field_key` | field_key fails canonical form | Lowercase alphanumeric + underscores, NO leading underscore (the storage prefix is added automatically) |
| `pre_invalid_source_field_key_length` | field_key longer than 64 chars | Shorten the key — 64 char cap |
| `pre_invalid_source_post_type` | post_type not a valid post-type key | Use the CPT slug in sanitize_key form (e.g. `listing`) |
| `pre_invalid_source_match_against` | match_against not in the closed enum | Use same_key, current_id, current_slug, or current_title |
| `pre_invalid_source_limit` | limit out of [1, 100] | Use an integer between 1 and 100 |
| `pre_invalid_source_exclude_self` | exclude_self not a boolean | Use true or false |
| `pre_invalid_item` | Item not an array | Pass an array |
| `pre_image_icon_conflict` | Both image_id and icon_id set | Pick one |
| `pre_invalid_image_id` | image_id not positive int | Fix |
| `pre_image_not_found` | image_id doesn't reference an attachment | Use a real attachment ID |
| `pre_invalid_icon_id` | icon_id not a string | Pass a string |
| `pre_unknown_icon` | icon_id not in registry | Use `postruntime_list_icons` |
| `pre_missing_image_or_icon` | icon_or_image_required is true on grouping but item has neither | Add image or icon |
| `pre_invalid_heading` | heading not a string | Pass a string |
| `pre_missing_heading` | heading_required is true but empty | Add a heading |
| `pre_heading_too_long` | heading > 200 chars | Shorten |
| `pre_invalid_supporting_text` | not string or null | Fix |
| `pre_supporting_text_too_long` | > 1000 chars | Shorten |
| `pre_missing_supporting_text` | supporting_text_required true but empty | Add supporting text |
| `pre_invalid_link` | not a string | Pass a string |
| `pre_link_too_long` | > 2048 chars | Shorten |
| `pre_unsafe_link` / `pre_invalid_anchor` | link is malformed/unsafe URL or invalid anchor | Fix or use the autocomplete-equivalent flow |
| `pre_invalid_link_post_id` | not positive int | Pass a real post ID or null |
| `pre_missing_link` | link_required true but empty | Add a link |
| `pre_unknown_grouping_key` | grouping_key not defined for the CPT | Define grouping first via `postruntime_define_grouping` |
| `pre_duplicate_post_grouping` | Same grouping_key listed twice in one PUT | Deduplicate |
| `pre_auto_source_has_items` | Auto source provided with items array | Empty the items array (auto sources don't store items) |

### Connector-sourced

| Code | Status | Trigger |
|---|---|---|
| `pre_unauthenticated` | 401 | No auth header or invalid credentials |
| `pre_forbidden` | 403 | Authenticated user lacks required capability |
| `pre_cpt_not_found` | 404 | Slug doesn't match any registered CPT |
| `pre_grouping_not_found` | 404 | Key doesn't match any grouping for the CPT |
| `pre_post_not_found` | 404 | Post ID doesn't exist or isn't a registered PRE CPT |
| `pre_unregistered_post_type` | 422 | post_type isn't managed by PRE |
| `pre_immutable_field` | 400 | Tried to change `slug` via PUT |
| `pre_version_conflict` | 409 | `connector_version` mismatch |
| `pre_rate_limited` | 429 | Per-user-per-route limit hit |
| `pre_unsupported_media_type` | 415 | Request body not application/json |
| `pre_internal_error` | 500 | Unexpected exception (logged for diagnosis) |

---

## 9. Versioning and evolution

The connector treats its REST surface as a versioned contract. Backwards-incompatible changes require a new namespace (`post-runtime/v2`); the v1 namespace remains available during a deprecation window.

**What constitutes a breaking change:**

- Removing a field from a response shape
- Renaming a field
- Changing a field's type
- Removing an endpoint
- Changing an endpoint's HTTP method
- Changing an error code's meaning
- Tightening validation in a way that previously-accepted data is now rejected

**What is NOT a breaking change** (can ship in v1):

- Adding a new field to a response shape (agents must ignore unknown fields)
- Adding a new optional field to a request shape
- Adding a new endpoint
- Adding a new error code (but using it only in cases that previously returned a different error is breaking)
- Loosening validation
- Changing error message text (only `code` is the contract)

This matches the FRE / Promptless connector versioning policies — agents can rely on these guarantees.

---

## 10. Implementation notes (non-contract)

These items are not part of the public spec but are noted here for the implementer's benefit.

- The connector's auth handler should reuse `wp_validate_application_password()` from core rather than rolling its own. Don't accept Bearer tokens; basic-auth-with-app-password is the only path.
- Rate limiting uses transients keyed `pre_rate_{user_id}_{route_hash}_{minute_window}`. Increment on each request; reject when count exceeds limit.
- The `connector_version` increment must happen inside the same `update_option` call as the data write (read-modify-write race window otherwise). Use `wp_cache_flush` defensively.
- The preview render endpoint needs `setup_postdata` + `wp_reset_postdata` to ensure the renderer's `the_content()` calls work correctly. Capture output via `ob_start`/`ob_get_clean`.
- The `POST /posts` rollback on grouping validation failure: wrap `wp_insert_post` + the grouping write in a try/catch; on failure, `wp_delete_post($id, true)` to hard-delete (bypass trash). Test this path explicitly.
- All response timestamps should use `mysql2date('c', $timestamp)` for ISO 8601 formatting.
- Don't expose `PRE_Validator` errors directly — wrap them so the response includes the `code`, `message`, and any `data` from the WP_Error. This is standard wp_send_json_error behavior, just be consistent.

---

## 11. Out of scope for v1 connector

Documented for clarity; defer to v1.1 unless real demand surfaces.

- **Bulk post creation.** Single `POST /posts` only. If an agent wants 50 posts, it issues 50 calls. Bulk endpoints land in v1.1 if real demand surfaces.
- **Webhook subscriptions.** No "notify me when a post changes" surface. Agents poll if they need to track changes.
- **CSV / export endpoints.** No bulk read-out. Use `GET /cpts` + `GET /cpts/{slug}/groupings` + `GET /posts/{id}/groupings` per post.
- **Schema introspection in JSON-Schema format.** The validator's rules aren't currently exposed as machine-readable JSON Schema. AI agents read this spec instead.
- **Field-level patches on post groupings.** `PUT /posts/{id}/groupings` replaces the whole array. Item-level patches deferred.
- **Permalink preview before save.** No "what will this post's permalink be if I save it now" endpoint. Agents save as draft, fetch permalink, decide.
- **Multilingual surface.** WPML / Polylang compatibility lands in Phase 6 alongside the production polish work.

---

## 12. Acceptance criteria for Phase 3 ship

This spec is satisfied when:

1. All 18 REST endpoints listed in section 5 are implemented and respond with the documented shapes.
2. All 18 MCP tools listed in section 6 are registered and callable from a Cowork session.
3. Authentication via Application Passwords works; capability checks fire for unauthorized users.
4. Rate limiting fires at 60 req/min/user/route with correct `Retry-After` semantics.
5. The validator's error codes flow through cleanly to REST responses; `code` field is stable and matches this spec's catalogue.
6. The end-to-end real-estate flow in section 7 runs cleanly without manual intervention from start to preview.
7. Smoke tests cover: CPT CRUD round-trip, grouping CRUD round-trip, post groupings round-trip with validation failures, preview returns HTML, version-mismatch returns 409, unauthenticated returns 401, insufficient capability returns 403.
8. The integration test from the Phase 1+2 kitchen-sink fixture also passes through the connector — i.e. an agent could rebuild the kitchen sink from scratch using only MCP tools.

When all 8 are green, Phase 3 ships and we move to Phase 4 (design-token CSS audit) or skip to Phase 5/6 if the token audit is already covered by the Phase 2 work.
