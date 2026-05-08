# Post Runtime Engine — MCP / Cowork Setup Guide

This guide walks through configuring an MCP client (Claude Cowork or compatible) to drive the Post Runtime Engine connector. By the end, an AI agent can register CPTs, define groupings, populate per-post values, and preview rendered output through the connector's REST API — no admin-UI clicks required.

If you only need the spec (endpoint contracts, error codes, idempotency rules), read [`docs/CONNECTOR_SPEC.md`](CONNECTOR_SPEC.md) first.

---

## Prerequisites

- WordPress 5.6 or later (Application Passwords are core since 5.6)
- Post Runtime Engine 0.2.0+ activated (the connector lives in this version)
- An admin user with `manage_options` capability on the target site
- Apache or Nginx with HTTPS (or `WP_ENVIRONMENT_TYPE=local` for dev)

---

## Step 1 — Enable the connector

The connector is **opt-in**. Until you flip the toggle, every endpoint returns `403 connector_disabled`.

1. Log in to wp-admin as an administrator.
2. Navigate to **Post Runtime → Connector**.
3. Check the **Enable connector** box.
4. Click **Save settings**.

A green "Connector enabled" notice confirms the change. If you ever need to disable the connector temporarily — e.g. during a security incident — uncheck the box and save. All in-flight requests will be rejected on their next call.

---

## Step 2 — Generate an Application Password

The connector authenticates via WordPress Application Passwords — per-user, per-app revocable credentials. Each MCP client gets its own password; revoking one doesn't affect the others.

1. From the same Post Runtime → Connector page, scroll to **Application Password**.
2. Click **Generate Application Password**.
3. Copy the displayed **Username** and **Application Password** *immediately* — WordPress only displays the password once. Store them in your MCP client config or a password manager.

The page shows a "Last configured" timestamp once you've generated a password. Clicking **Regenerate Application Password** revokes the existing one and issues a new password (so you can rotate credentials without changing your username).

To revoke without rotating, click **Revoke**.

---

## Step 3 — Fix Apache's Authorization-header stripping (one-time, per-server)

**You can skip this step if your site is on Nginx, LiteSpeed, or any non-Apache server.** Apache's default `mod_rewrite` config silently strips the `Authorization` header on incoming requests, which means WordPress never sees the App Password and returns `401 rest_not_logged_in`.

The fix is a small `.htaccess` rule that forwards the header through. Add this to the WordPress installation's root `.htaccess` file (the one that already contains the `# BEGIN WordPress` block), **outside** the WordPress block, so WordPress's auto-rewrite doesn't clobber it:

```apache
# Forward the Authorization header so the WordPress REST API can read it.
# Required for Application Passwords / Bearer tokens on Apache + mod_rewrite.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:Authorization} ^(.*)
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
</IfModule>
```

After saving, hit the site with a curl call to verify (replace the credentials):

```bash
curl -u admin:'PASTE_APP_PASSWORD_HERE' \
  https://your-site.com/wp-json/post-runtime/v1/connector/preflight
```

You should get a 200 response with site info. If you still get 401, double-check that the `.htaccess` rule is outside the `# BEGIN WordPress` block — WordPress regenerates that block on permalink saves and would erase rules placed inside.

**On Local-by-Flywheel:** the `.htaccess` lives at `app/public/.htaccess`. Same fix applies.

**On WP Engine, Kinsta, or other managed hosts:** check your control panel — most managed hosts pre-configure this header forwarding. If they don't, contact support; they can enable it at the server-config level.

---

## Step 4 — Configure your MCP client

The exact configuration steps depend on your MCP client. The values to plug in are:

| Field | Value (example) |
|---|---|
| Connector base URL | `https://your-site.com/wp-json/post-runtime/v1/connector` |
| Auth method | HTTP Basic |
| Username | Your WordPress username |
| Password | The Application Password from step 2 (`xxxx xxxx xxxx xxxx xxxx xxxx`) |

The connector's full endpoint inventory is in [CONNECTOR_SPEC.md §5](CONNECTOR_SPEC.md). The MCP tool layer (described in §6 of that doc) maps tool names like `postruntime_register_cpt` to the corresponding REST calls.

### For Claude Cowork specifically

If your Cowork environment supports adding custom MCPs through its connector marketplace or settings panel, configure a new connector with:

- **Type:** REST API with HTTP Basic auth
- **Base URL:** the connector base URL above
- **Credentials:** your username + Application Password
- **Tool prefix:** `postruntime_`

After Cowork connects, the `postruntime_*` tools (full list in CONNECTOR_SPEC §6) become available in your Cowork sessions.

---

## Step 5 — Verify the connection

Run a quick "preflight" test from your MCP client to confirm everything is wired:

```
postruntime_preflight
```

A successful response looks like:

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
  "registered_cpts": ["pre_demo"],
  "promptless_active": true,
  "promptless_version": "1.3.1"
}
```

If you see this, the connector is fully wired and your agent can drive the entire CPT lifecycle.

---

## MCP tool inventory at a glance

For the full input schemas and response shapes, see [CONNECTOR_SPEC.md §6](CONNECTOR_SPEC.md). Quick reference:

| MCP tool | Purpose |
|---|---|
| `postruntime_preflight` | Connector readiness check |
| `postruntime_list_icons` | Browse the 53-icon catalog (with categories) |
| `postruntime_list_variants` | Layout variant catalog (compact-grid, card-grid, featured-card, horizontal-row) |
| `postruntime_list_positions` | Layout position catalog (above_main, below_main, sidebar) |
| `postruntime_list_cpts` | List all registered CPTs |
| `postruntime_register_cpt` | Register a new CPT |
| `postruntime_get_cpt` | Read one CPT |
| `postruntime_update_cpt` | Update a CPT (with versioning) |
| `postruntime_delete_cpt` | Unregister a CPT |
| `postruntime_list_groupings` | List groupings for a CPT |
| `postruntime_define_grouping` | Define a grouping on a CPT |
| `postruntime_get_grouping` | Read one grouping |
| `postruntime_update_grouping` | Update a grouping |
| `postruntime_delete_grouping` | Remove a grouping |
| `postruntime_get_post_groupings` | Read a post's groupings |
| `postruntime_set_post_groupings` | Replace a post's groupings (atomic full-replace) |
| `postruntime_create_post` | Create a post — optionally with groupings in one call |
| `postruntime_preview_post` | Render a post and return the HTML for visual verification |

---

## Common workflows

### Build a real-estate site from scratch

Section 7 of CONNECTOR_SPEC.md walks through this end-to-end. The 10-step flow exercises every endpoint group at least once.

### Migrate a site from staging to production

The `link_post_id` field on grouping items makes internal links domain-portable — `get_permalink()` resolves at render time, so links survive the migration without database rewrites. **No connector calls needed for migration**, just a database export/import.

### Bulk-update grouping data

The connector's `postruntime_set_post_groupings` endpoint is full-replacement (the entire groupings array is replaced atomically). For bulk updates across many posts, the agent loops:

```
for each post in target_posts:
  current = postruntime_get_post_groupings(post_id)
  modified = transform(current.groupings)
  postruntime_set_post_groupings(post_id, modified)
```

The data layer creates a backup before each write, recoverable via `PRE_Post_Data::has_backup()` from PHP. There is no connector endpoint to trigger restore — that's an admin operation, not an agent operation.

### Handle concurrent edits

CPT and grouping definitions carry a `connector_version` integer. PUT requests should include the expected version (header `If-Match: 3` or body field `connector_version: 3`). If the stored version is higher, the connector returns `409 pre_version_conflict` with both versions in the response. The agent fetches the current state, rebases its changes, retries.

This guards against two agents (or an agent + a human admin) overwriting each other's edits.

---

## Troubleshooting

**`403 connector_disabled`** — Toggle is off. Enable it under Post Runtime → Connector.

**`401 rest_not_logged_in` even with credentials** — Apache is stripping the Authorization header. Apply the `.htaccess` fix in Step 3.

**`403 rest_forbidden`** — Authenticated user lacks the required capability. Site-config endpoints need `manage_options`; per-post endpoints need `edit_post` against that specific post. Verify the user role on wp-admin → Users.

**`409 pre_version_conflict`** — Another writer (agent or human) updated the resource since you read it. Re-read, rebase, retry.

**`422 pre_*` validation errors** — Shape doesn't match the validator's contract. The `code` field tells you which rule failed; `message` describes how to fix. Full catalog in CONNECTOR_SPEC.md §8.

**`429 rate_limit_exceeded`** — You exceeded the per-route rate limit. The response includes `retry_after` (seconds). Limits are tuned per FRE pattern: 60/min for reads, 30/min for light writes, 10/min for config writes, 5/min for destructive ops. Filterable via `pre_connector_rate_limit` if your site needs higher.

**`500 pre_internal_error` or `pre_post_create_failed`** — Unexpected exception. Check the WordPress error log (`wp-content/debug.log` if `WP_DEBUG_LOG` is on) for the underlying error.

---

## Security notes

- The connector is opt-in. The `pre_connector_enabled` option defaults to `false` on activation and on every fresh install. A site administrator must explicitly enable it.
- Application Passwords are tied to specific WP users and capabilities. Revoking a user's WP account (or revoking the password) immediately invalidates all in-flight tokens.
- Capability checks fire on every request — even authenticated users only see/touch what their WP role allows.
- Per-route rate limits prevent runaway scripts from eating your database.
- The connector never logs or echoes the App Password back. The only place it appears is the one-time display on the admin page after generation.
- Validation is strict: the validator runs on every write and returns typed error codes the agent can use to self-correct. Malformed data never persists.

---

## When NOT to use the connector

- **Bulk import from another platform.** The connector is for fine-grained agent-driven work. For bulk imports (e.g. WP All Import, ACF migration), use those tools directly against the WP database, then verify via `GET /cpts` and `GET /posts/{id}/groupings`.
- **Batch publishing campaigns.** Use WordPress's standard `POST /wp/v2/{post_type}` endpoint with `meta` for raw post creation; reach for the connector when you specifically need PRE's grouping shape.
- **Real-time inline editing in the admin.** That's what the meta box is for. The connector is for headless / Cowork-driven workflows, not human-in-the-loop editing.
