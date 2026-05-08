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
  "message": "Invalid variant 'card-fancy'. Allowed: compact-grid, card-grid, featured-card, horizontal-row.",
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

Site-readiness check. Returns version info and a list of registered CPTs with capability flags. Agents call this first to confirm the connector is live and to get the lay of the land before doing anything else.

**Response:**

```json
{
  "plugin_version": "0.2.0",
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
  "promptless_active": true
}
```

`promptless_active` reports whether Promptless WP is active so the agent knows the design tokens will inherit. When false, the agent may want to warn the operator that styling will use the documented fallbacks instead.

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
  "connector_version": 3,
  "created_at": "2026-05-08T12:00:00Z",
  "updated_at": "2026-05-08T14:30:00Z"
}
```

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

#### Get a CPT

`GET /cpts/{slug}` → `200 OK` with CPT shape, or `404` (`pre_cpt_not_found`).

#### Update a CPT

`PUT /cpts/{slug}`

Requires `connector_version` in body or `If-Match` header. Slug cannot be changed (returns `400 Bad Request` `pre_immutable_field` if attempted).

**Success:** `200 OK` with the updated CPT shape (new `connector_version`).

**Failure:** any validator code; `409 Conflict` on version mismatch.

#### Delete a CPT

`DELETE /cpts/{slug}` → `204 No Content` on success.

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

Source can be a string (`"manual"` or `"child_posts"`) or an object form for taxonomy matching:

```json
"default_source": { "type": "taxonomy_match", "taxonomy": "practice_area" }
```

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
- `pre_invalid_variant` — not in {compact-grid, card-grid, featured-card, horizontal-row}
- `pre_invalid_position` — not in {above_main, below_main, sidebar}
- `pre_invalid_source` — source not manual/child_posts/taxonomy_match
- `pre_featured_card_max_items` — featured-card requires max_items: 1
- `pre_taxonomy_match_needs_object`
- `pre_invalid_source_taxonomy` — taxonomy doesn't exist or is missing

#### Get / Update / Delete a grouping

`GET /cpts/{slug}/groupings/{key}` → `200 OK` or `404`

`PUT /cpts/{slug}/groupings/{key}` — same versioning rules as CPT update

`DELETE /cpts/{slug}/groupings/{key}` → `204 No Content`. Post data referencing this grouping_key is preserved unless `?purge_data=1` is set.

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

### 5.7 Introspection

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
| `pre_invalid_variant` | Variant not in {compact-grid, card-grid, featured-card, horizontal-row} | Use `postruntime_list_variants` to verify |
| `pre_invalid_position` | Position not in {above_main, below_main, sidebar} | Use `postruntime_list_positions` |
| `pre_invalid_source` | Source not manual/child_posts/taxonomy_match | Pick a valid source |
| `pre_featured_card_max_items` | featured-card variant requires max_items: 1 | Set max_items to 1 |
| `pre_taxonomy_match_needs_object` | taxonomy_match given as bare string | Use object form: `{type, taxonomy}` |
| `pre_invalid_source_taxonomy` | Referenced taxonomy doesn't exist | Register the taxonomy first |
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
