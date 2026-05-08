# Hosted-Environment Validation

A focused walkthrough for validating Post Runtime Engine on a real (non-Local) hosted WordPress environment. Use this after uploading the production zip — it surfaces the deployment-specific gotchas that don't appear in dev.

About 30–45 minutes for a thorough first pass.

---

## Pre-upload checks

Before touching the hosted site, confirm you have:

- A staging or sandbox WordPress install (do NOT validate against a live client site)
- Promptless WP installed and activated on that staging site
- Admin access to the WP dashboard
- (If you'll test the connector) Claude Cowork or a compatible MCP client ready to point at the staging URL

---

## Phase 1 — Upload + activate

1. **Upload the plugin zip.** WP Admin → Plugins → Add New → Upload Plugin → choose `post-runtime-engine.zip` → Install Now → Activate.

   - **Pass:** Plugin appears in the Plugins list, no PHP fatal error, no white screen.
   - **Fail signals:** Fatal error on activation = check the host's PHP error log (most managed hosts surface this in their control panel). Most likely cause: PHP version below 7.4 (we require 7.4+).

2. **Confirm the admin menu appears.** Look for "Post Runtime" in the wp-admin sidebar.

   - **Pass:** "Post Runtime" submenu with "Post Types" and "Connector" entries.
   - **Fail signals:** No menu = check the Plugins screen for activation warnings; PRE may have activated but a hook didn't fire.

3. **Hit a non-existent CPT URL** to confirm the template router is wired without errors:

   - Navigate to `https://your-staging.com/this-cpt-does-not-exist/`. You should get a normal WP 404, not a fatal.

---

## Phase 2 — Bring up a CPT through the admin

Walk the [`SETUP.md`](SETUP.md) flow on the hosted site:

4. **Register a test CPT** (e.g. `test_listing` with labels "Test Listing" / "Test Listings"). Save.

5. **Define one grouping** for it (e.g. `quick_specs` with horizontal-row variant, manual source). Save.

6. **Create a test post.** Add 2–3 grouping items (icons + headings). Set a featured image. Add a few paragraphs of editor body content. Publish.

7. **View the post on the frontend.**

   - **Pass:** Hero with title + featured image + excerpt → grouping items render in the chosen variant → main content body shows the editor content → no console errors → no PHP notices in the page source.
   - **Fail signals:**
     - Page renders as the theme's default `single.php` rather than ours: `template_include` hook isn't firing. Check if a caching plugin or another template-routing plugin is interfering.
     - Brand styling missing despite Promptless being active: hard-refresh to bypass browser cache; if still missing, confirm Promptless is actually outputting `--aisb-*` tokens (View Source → search for `--aisb-color-`).
     - Featured image broken: confirm the attachment uploaded successfully (Media Library) and the URL in the source matches the host's media path.

---

## Phase 3 — Light/dark mode toggle

8. **Switch the theme variant.** Appearance → Customize → General Settings → Content → Content Theme → Dark → Publish.

9. **Reload the test post.**

   - **Pass:** Background dark, text cream-white, cards have visible elevation (subtle drop shadow), borders distinguishable, related-posts section visible.
   - **Fail signals:**
     - Dark mode doesn't activate: confirm the active theme is the Promptless theme (not Twenty Twenty-Five or another). The integration relies on the theme's `<main class="aisb-section--dark">` wrapper.
     - Whitespace on left/right sides: indicates the `:has()` selector isn't being applied; test in a current Chrome/Safari/Firefox (95%+ supported) — older browsers fall back to article-only background.
     - Cards invisible in dark mode: Promptless may not be emitting expected `--aisb-color-dark-*` tokens; the documented fallbacks should still render visible borders.

10. **Switch back to Light** and confirm light mode renders correctly.

---

## Phase 4 — Connector (optional but recommended)

If you'll be using Claude Cowork or another MCP client to drive future setups, validate the connector now while you have a clean context.

11. **Enable the connector.** Post Runtime → Connector → check "Enable connector" → Save.

12. **Apply the Apache header fix** (if your host uses Apache — most managed hosts do). Add to the WordPress root `.htaccess` file, *outside* the `# BEGIN WordPress` block:

    ```apache
    <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteCond %{HTTP:Authorization} ^(.*)
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
    </IfModule>
    ```

    See [`MCP_CONNECTOR_SETUP.md`](MCP_CONNECTOR_SETUP.md) for the full explanation. WP Engine, Kinsta, and most managed hosts pre-configure this; check first before editing.

13. **Generate an Application Password.** Click "Generate Application Password" — copy the credentials immediately. Store in a password manager or your MCP client config.

14. **Verify the connector responds.** From a terminal:

    ```bash
    curl -u admin:'PASTE_APP_PASSWORD_HERE' \
      https://your-staging.com/wp-json/post-runtime/v1/connector/preflight
    ```

    - **Pass:** Returns 200 with JSON containing `plugin_version`, `registered_cpts`, `user.can_manage_cpts: true`.
    - **Fail signals:**
      - 401 `rest_not_logged_in`: Apache stripped the `Authorization` header → apply the `.htaccess` fix.
      - 403 `connector_disabled`: re-check the toggle in step 11.
      - 404: REST routes not registered → check the Plugins screen for activation issues.
      - SSL/cert errors: your staging site doesn't have a valid certificate; Application Passwords require HTTPS unless `WP_ENVIRONMENT_TYPE=local` is set.

15. **Run an end-to-end agent workflow.** Connect Claude Cowork (or your chosen MCP client) to the connector and have it execute the canonical flow from [`CONNECTOR_SPEC.md §7`](CONNECTOR_SPEC.md):

    1. `postruntime_preflight`
    2. `postruntime_register_cpt` (a fresh test CPT)
    3. `postruntime_define_grouping` (×2–3 groupings)
    4. `postruntime_create_post` with grouping data inline
    5. `postruntime_preview_post` and verify the returned HTML

    - **Pass:** Each step returns the expected response shape; the post created in step 4 renders correctly when visited at its permalink.
    - **Fail signals:** Validation errors should map cleanly to the `pre_*` codes in the spec. If you get 500s or unexpected shapes, capture the full response body for debugging.

---

## Phase 5 — Caching and performance

16. **Hit your test post twice** while logged out. View page source on each:

    - **First hit:** if `WP_DEBUG` is on, you'll see `<!-- pre-render-cache: MISS -->` near the bottom.
    - **Second hit:** `<!-- pre-render-cache: HIT -->`.

    Cache lifetime is 1 hour by default. After the second hit, the rendered HTML is being served from the WP transient store — multiple subsequent loads should be very fast.

17. **Edit the post** (e.g. change the title) and view it again.

    - **Pass:** Page reflects the change immediately. Cache key includes `post_modified`, so saving the post auto-invalidates the cached version.

18. **If the host has W3 Total Cache, WP Rocket, or LiteSpeed Cache**, run a smoke pass:

    - Visit a test post.
    - Visit it again to confirm the page caches correctly through the host's caching plugin.
    - Edit the post, re-visit, confirm the change appears (the host caching plugin should invalidate on `save_post` like our render cache does).

    PRE's render cache is independent of and complementary to host-level page caches. Most issues here are between the host plugin and Promptless, not specific to PRE.

---

## Phase 6 — Real-world stress

The previous phases validate the happy path. Now exercise edge cases.

19. **Move a featured-image attachment to Trash.** Reload the post. Item should render without media (no broken image icon, no empty layout slot). Restore the attachment when done.

20. **Trash a post that's referenced by a `link_post_id` in another item.** Reload the page containing the link. The link should still render — `get_permalink()` returns false for trashed posts, so the renderer falls back to the stored URL string. The link will 404 if clicked (correct — the target is gone), but the layout doesn't break.

21. **Delete a grouping definition** that has populated post data.

    - Go to Post Runtime → Post Types → \[CPT\] → Define groupings → delete one.
    - Reload a post that had data for that grouping. The grouping is silently skipped (orphan data is preserved in post meta but ignored by the renderer).
    - Re-add the grouping definition with the same key. Reload — the orphaned data should re-appear automatically.

22. **Trigger the validator.** Edit a post, manually paste an invalid URL into a link field (e.g. `javascript:alert(1)`), and try to save.

    - **Pass:** Save fails with a typed error; admin notice appears explaining the validation failure; the field reverts.

---

## Phase 7 — Multilingual (only if applicable)

23. **If the staging site has WPML or Polylang active**, create a translated version of a test post and verify:

    - The translated post renders through PRE's template (not the theme default).
    - Grouping data isn't shared across translations (each language's post has its own grouping data).
    - The connector returns the correct content per the language context.

    Multilingual deep-compat is deferred to v1.1; the goal here is to confirm the plugin doesn't break a multilingual site, not that it has full translation support.

---

## Sign-off

Before declaring the staging validation complete, confirm all of these are true:

- [ ] Plugin activates without errors on hosted PHP/MySQL
- [ ] Admin UI works (CPT registration, grouping definitions, meta box)
- [ ] Frontend rendering looks correct in light mode
- [ ] Dark mode flips automatically when the customizer toggle changes
- [ ] Connector preflight returns 200 with valid credentials
- [ ] Agent-driven setup works end-to-end (if connector being used)
- [ ] Render cache HIT/MISS visible in source (with WP_DEBUG on)
- [ ] Edge cases (deleted attachments, trashed linked posts) degrade gracefully
- [ ] No PHP notices, errors, or warnings in `wp-content/debug.log`

Once all green, the plugin is ready for first-client production deployment.

---

## What to capture if something fails

Open an issue (or a note for yourself) with:

- WordPress version, PHP version, host name (e.g. WP Engine, Kinsta, Bluehost)
- Active theme + version
- Active plugins list (especially caching, security, multilingual)
- Exact reproduction steps
- Page source snippet showing the relevant rendered HTML or wp-admin markup
- Any entries in `wp-content/debug.log` from the failure window

That context turns most "doesn't work" reports into "I see exactly what to fix" within minutes.
