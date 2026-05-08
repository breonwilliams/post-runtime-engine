# Post Runtime Engine — Connect Claude Desktop

The fastest way to wire Claude Cowork to a Post Runtime Engine site is through the four-step flow on the **Post Runtime → Connector** admin page. The whole setup is one click in WordPress, one paste in Terminal, and one Claude Desktop restart.

If anything goes sideways, the troubleshooting section at the bottom of this doc covers the most common deployment issues.

---

## The streamlined flow (what you should do)

After activating the plugin on your site:

### Step 1 — Enable the connector

1. Open **Post Runtime → Connector** in wp-admin.
2. Check **Allow Claude Cowork to call the connector REST API**.

The toggle saves immediately via AJAX. When it's off, every connector endpoint returns 403 — useful as a kill-switch if you ever need to lock the agent out without revoking credentials.

### Step 2 — Generate a connection

3. Click **Generate connection**.

The page generates a WordPress Application Password tied to your user, displays it once for visual confirmation, and immediately populates the bash command in Step 3 with your username, password, and site URL pre-filled. The plaintext password is never stored by this plugin — it lives only in the Application Password storage built into WordPress core, plus the bash command on the page (which you're about to paste somewhere safe).

If you regenerate later, the previous Application Password is revoked first, so each user has at most one active connector credential at a time.

### Step 3 — Connect Claude Desktop

4. Click **Copy** at the top-right of the bash command panel.
5. Open Terminal on your Mac.
6. Paste and hit Enter.

The command does four things:

- Creates `~/post-runtime-mcp/` if it doesn't exist
- Downloads the MCP server JavaScript file from your WordPress site
- Detects your Node.js installation (prefers nvm, falls back to system Node)
- Writes `~/Library/Application Support/Claude/claude_desktop_config.json` with the new MCP server entry — your credentials baked in as environment variables

The Application Password is passed to Node via an argv slot, never interpolated into the script body, so it never appears in your shell history.

You should see `Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it.` when it finishes.

### Step 4 — Restart Claude Desktop

7. Quit Claude Desktop with Cmd+Q (don't just close the window).
8. Reopen it.

The connector is now active. In your next Cowork session, the 18 `postruntime_*` tools are available — preflight, list_cpts, register_cpt, define_grouping, set_post_groupings, create_post, preview_post, and the rest.

To verify, ask Cowork: *"Run a Post Runtime preflight check on my site."* It should respond with your plugin version, registered CPTs, and capability flags.

---

## What ships in the plugin to make this work

For your reference (you don't need to touch any of this):

| Component | Where | What |
|---|---|---|
| MCP server JS | `includes/Connector/assets/post-runtime-connector.js` | A stdio MCP server (~28 KB) with all 18 tool definitions. Reads credentials from environment variables at runtime. |
| Download endpoint | `?action=pre_download_connector` (admin-ajax) | Public route — serves the MCP server JS to the bash command's curl. No auth because the file contains no secrets. |
| REST API | `/wp-json/post-runtime/v1/connector/*` | The 18 endpoints the MCP server calls. Auth via Application Password Basic auth. |
| Admin page | Post Runtime → Connector | The UX described above. |

The MCP server runs locally on your Mac (Claude Desktop spawns it as a child process), bridging between Cowork's MCP protocol and your WordPress site's REST API.

---

## Configuration values (for reference)

| Field | Value |
|---|---|
| MCP server name | `post-runtime-engine` |
| Install location | `~/post-runtime-mcp/post-runtime-connector.js` |
| Claude Desktop config key | `mcpServers.post-runtime-engine` |
| Environment variables | `POST_RUNTIME_SITE_URL`, `POST_RUNTIME_USERNAME`, `POST_RUNTIME_APP_PASSWORD` |

These are distinct from FRE's (`form-engine-mcp/`, `form-engine-wordpress`, `FORM_ENGINE_*`) and Promptless's (`promptless-mcp/`, `promptless-wordpress`, `WORDPRESS_*`) so all three connectors coexist without conflict.

---

## Troubleshooting

### "Generate connection" button does nothing

- Open browser dev tools, watch the console while clicking. Most failures are JS-level (caching plugin minifying JS unsafely, ad blocker, etc.).
- If the AJAX request returns 403, your user may have lost `manage_options` capability. Verify in **Users → Your profile**.
- If the response says "Application Passwords are not available," your site is likely on HTTP without `WP_ENVIRONMENT_TYPE=local` set. App Passwords require HTTPS in production. Add to `wp-config.php`:
  ```php
  define( 'WP_ENVIRONMENT_TYPE', 'local' );
  ```
  …or set up SSL on the site (the right answer for any production environment).

### Bash command runs but Claude Desktop doesn't show the connector

- Confirm you fully **quit** Claude Desktop (Cmd+Q on Mac), not just closed the window. New MCP servers are only loaded on app start.
- Open `~/Library/Application Support/Claude/claude_desktop_config.json` in any text editor and confirm the `post-runtime-engine` entry is present and the path inside `args` exists. If the path is wrong, the connector silently fails to load.
- Check that `~/post-runtime-mcp/post-runtime-connector.js` exists. If the curl step failed silently, the file won't be there.
- Run the JS file manually to surface errors: `node ~/post-runtime-mcp/post-runtime-connector.js` and immediately type Ctrl+C — if it loaded, you'll see no output (it's waiting on stdin); if it crashed, you'll see the error.

### Cowork tools show up but `postruntime_preflight` returns 401

This is the Apache `Authorization` header issue. Apache strips the header by default before WordPress sees it. Add to your WordPress root `.htaccess`, **outside** the `# BEGIN WordPress` block (so WP's auto-rewrite doesn't clobber it):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:Authorization} ^(.*)
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
</IfModule>
```

Most managed hosts (WP Engine, Kinsta, etc.) pre-configure this. Bare-metal Apache and many shared hosts don't. The fix is one-time, per-site.

### `postruntime_preflight` returns 403 `connector_disabled`

You toggled off Step 1, or the toggle reverted (rare — happens if the AJAX save raced with a page navigation). Re-check **Allow Claude Cowork to call the connector REST API** on the admin page.

### Cowork tools time out

- The MCP server has a 30-second per-request timeout. If your WordPress site is genuinely slow (>30s for a single REST call), the request will fail with a connection-timeout error.
- Common causes: a heavy `init` hook running on every REST request (debug by deactivating other plugins), a very large taxonomy_match source resolving thousands of posts (consider switching to `manual` source), or a host-level slow-query problem.

### I want to revoke access without uninstalling the plugin

Click **Revoke connection** on the admin page. The Application Password is deleted immediately; Cowork's next call returns 401 within seconds.

You can also revoke at the WP user level: **Users → Your profile → Application Passwords → Revoke** for the entry named "Post Runtime Engine — Claude Cowork."

### I changed my WordPress username — Cowork stopped working

The bash command bakes your username at the time of generation. Click **Regenerate connection** on the admin page, then re-paste the new bash command in Terminal. Claude Desktop config is updated; restart Claude Desktop.

### I changed my domain (staging → production)

Same fix as a username change: regenerate the connection on the new domain's admin page, paste the new bash command. The MCP server config is per-domain (the env vars include the site URL), so each site needs its own connection.

---

## Manual setup (if you can't use the streamlined flow)

If you're integrating with a non-standard MCP client or need to wire this into a CI/CD environment, the raw configuration is:

```json
{
  "mcpServers": {
    "post-runtime-engine": {
      "command": "/absolute/path/to/node",
      "args": ["/path/to/post-runtime-connector.js"],
      "env": {
        "POST_RUNTIME_SITE_URL": "https://your-site.com",
        "POST_RUNTIME_USERNAME": "your-wp-username",
        "POST_RUNTIME_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

The MCP server file at `includes/Connector/assets/post-runtime-connector.js` inside the plugin directory is the same file the bash command downloads. You can copy it directly from the plugin folder if you'd rather not curl through the download endpoint.

---

## See also

- `docs/CONNECTOR_SPEC.md` — full REST + MCP API contract, error code catalogue, end-to-end workflow examples
- `docs/SETUP.md` — human-facing CPT setup walkthrough (no Cowork required)
- `docs/HOSTED_VALIDATION.md` — post-deployment validation checklist for hosted environments
