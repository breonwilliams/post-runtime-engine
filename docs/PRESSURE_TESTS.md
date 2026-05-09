# Post Runtime Engine — Pressure Test Playbook

**What this is:** the systematized version of the discipline that produced v0.3.0. Every test in this doc maps to a real failure pattern we've either hit or anticipate. Use it before any release that touches the connector contract, before onboarding a new client industry, or whenever a new code path lands that this doc doesn't yet cover.

**What this is NOT:** a re-test of Promptless WP's render surface. Color contrast, semantic structure, WCAG compliance, schema.org output, typography — all already battle-tested at the Promptless rendering layer. PRE inherits that surface; we don't re-prove what's already proven. This doc is exclusively about what PRE adds: CPT/grouping shape, per-CPT structural decisions, the connector contract, and the cross-plugin handshake where Promptless and FRE consume PRE data.

## How to use this doc

**Modes** — each test is tagged with how it gets executed:
- `[smoke]` — extends `tests/smoke-phase1.php` or `tests/smoke-phase3.php`. Runs in seconds via the browser-based runner. Add to the suite once; re-run on every change.
- `[agent]` — agent-driven authoring through the connector, mimicking real client work. ~30-60 min per test. Run before each minor release and when adding a new feature surface.
- `[human]` — visual or experiential check that automation can't catch. Click-through testing, layout rhythm, animation timing. Run before each release.

**Severity** — each test is tagged with how blocking it is:
- `BLOCKER` — failure means do not ship. Data corruption, security regression, crash on render.
- `IMPORTANT` — failure means investigate before ship. Wrong-context behavior, broken navigation, performance regression.
- `NICE` — failure is informational. Polish issue, edge case worth tracking but not stopping a release.

**Test IDs** are stable: `PT-{tier}-{n}`. Reference them in commit messages, Slack threads, and findings reports — `"caught by PT-9-1"` is more useful than re-describing the scenario.

## Tier coverage at-a-glance

| Tier | Scope | Coverage status |
|---|---|---|
| 1 | Automated smoke | ✅ 138/138 passing |
| 2 | Industry/schema breadth | ⚠️ One industry tested (architecture); 7+ untested |
| 3 | Real-asset rendering | ⚠️ Tested without images; image flows unverified |
| 4 | PRE-side scale | ❌ Untested at scale |
| 6 | Connector permissions + multi-user | ⚠️ Solo-admin only |
| 7 | PRE migration | ⚠️ Local-only; staging→prod untested |
| 8 | Cross-plugin handshake | ⚠️ Promptless half tested; FRE + FlowMint untested |
| 9 | Edge cases / error recovery | ❌ Untested |

(Tiers 5 and 10 from the original sketch were dropped — those map to Promptless's already-tested rendering surface.)

---

## Tier 1 — Automated smoke (PRE-specific)

The codified version of every fix that's ever shipped. If a smoke test fails, the corresponding contract has regressed.

### PT-1-1: Phase 1 smoke (data layer) `[smoke]` `BLOCKER`

**Setup:** Local-by-Flywheel install with PRE active.
**Steps:** Navigate to `tests/run-via-browser.php`. Wait for completion.
**Pass:** 99+ assertions in section 1, all `PASS`. Final summary `0 failures`.
**Fail signals:** any `FAIL` line. The first failure usually points at a registry / validator / post-data regression.

### PT-1-2: Phase 3 smoke (REST connector) `[smoke]` `BLOCKER`

**Setup:** Same as PT-1-1; runs in the same browser pass.
**Steps:** Output continues after PT-1-1 in `run-via-browser.php`.
**Pass:** Phase B section reports all `PASS` for `critical_rules`, `field_name_hints`, `_site` envelope, CDATA sanitization (3 cases), `update_post` round-trip (4 cases), cross-CPT default_icon resolution.
**Fail signals:** any FAIL. Particularly watch for: missing rule keys (a rule got dropped from `get_critical_rules`), envelope absence on a route (a new route bypassed the filter), CDATA strip not firing (the helper got optimized out).

### PT-1-3: New-feature assertion lands per release `[smoke]` `IMPORTANT`

**Setup:** Reviewing a PR that adds a new code path.
**Steps:** Verify the PR includes at least one new assertion in `smoke-phase1.php` or `smoke-phase3.php`.
**Pass:** Every meaningful behavior change has an assertion. The smoke suite count strictly grows release-over-release.
**Fail signals:** PR adds code without adding tests. Block merge until a test lands.

---

## Tier 2 — CPT / grouping schema breadth across industries

The contract was tested against an architecture firm. Each industry has a different data shape. The point isn't to build 8 sites; it's to surface the cases where the 4 variants + grouping defaults don't fit cleanly.

### PT-2-1: One full agent-driven build per industry `[agent]` `IMPORTANT`

**Setup:** Clean staging site. Industry brief in hand (real or invented, doesn't matter — what matters is the data shape).
**Steps:**
1. Register the primary CPT and (where applicable) a related secondary CPT
2. Define 3-5 groupings exercising all 4 variants + bidirectional cross-CPT links
3. Create 4-6 example posts per CPT with full content
4. Verify single-page rendering for at least one of each CPT

**Pass:** All 4 grouping variants have a natural home in the content. Cross-CPT links resolve. Hero aspect picks make sense (square / landscape / wide). Default_icon falls back semantically.
**Fail signals:** A variant feels forced (e.g., compact-grid on content that needs supporting text — flag as a possible new variant request). Cross-CPT links don't naturally model the relationship (flag as missing functionality). Hero aspect options don't cover the natural photo shape.
**Industries to cover (priority order):**
1. ✅ Architecture firm (Northcraft, done)
2. Legal / professional services (attorneys + practice areas + case studies — text-heavy bios, conservative aesthetic, schema.org `LegalService`)
3. Medical / healthcare (doctors + services + locations — trust signals, regulatory copy, schema.org `MedicalBusiness`)
4. Restaurant / hospitality (locations + menu items — pricing tables, hours, multi-location, schema.org `Restaurant`)
5. Real estate (listings + agents — already partially covered in earlier sessions; finish)
6. Fitness / wellness (instructors + class schedule — calendar integration, gallery-heavy)
7. Education / courses (cohorts + instructors — testimonials-heavy, application forms via FRE)
8. Local services (plumbers / electricians / roofing — service areas, schema.org `HomeAndConstructionBusiness`)

### PT-2-2: Hero aspect coverage `[agent]` `IMPORTANT`

**Setup:** A CPT registered with `hero_layout: split`. At least three posts with featured images of different natural aspects.
**Steps:** Set the CPT's `hero_image_aspect` to each value (`square`, `landscape`, `wide`) in turn. View one of the posts after each change.
**Pass:** Image crops cleanly to the configured aspect. Title + excerpt block sits where expected. Mobile collapse to single-column works.
**Fail signals:** Image cropped through a face / focal point. Title overlapping image at any breakpoint. Aspect ratio inconsistent across cards.

### PT-2-3: Hero with no featured image `[agent]` `IMPORTANT`

**Setup:** A CPT with `hero_layout: split`, post WITHOUT a featured image.
**Pass:** Layout collapses to text-only hero. No empty image slot. Title block uses the available width.
**Fail signals:** Empty rectangle where image should be. Title block oddly narrow.

### PT-2-4: Mixed-image grouping items `[agent]` `IMPORTANT`

**Setup:** A grouping (any variant) with 4 items: 2 with `image_id`, 2 with `icon_id` only.
**Pass:** All 4 items render at a consistent visual weight. Compact-grid + horizontal-row drop the images per the variant intent rule.
**Fail signals:** Visual inconsistency between image-having and icon-having items in compact-grid / horizontal-row (image-bearing items stand out at wrong size — surfaced this in v0.2.0, fixed in v0.2.0/v0.3.0).

---

## Tier 3 — PRE singles with real images

Northcraft was authored without uploading any project photos or architect headshots. The visual system is unverified for real assets.

### PT-3-1: Featured image upload + hero render `[agent]` `BLOCKER`

**Setup:** Project / listing / similar CPT with `hero_layout: split`, `hero_image_aspect: landscape`. Real landscape photo on disk.
**Steps:**
1. `wordpress_upload_image` to upload the photo, capture the attachment_id
2. `postruntime_update_post` setting `featured_image_id`
3. View the post

**Pass:** Hero renders the photo at the configured aspect with `object-fit: cover` (no distortion). Image doesn't bleed past container. Mobile collapses cleanly.
**Fail signals:** Image stretched / distorted. Image cropped through obvious focal point (face / building entrance). Image bleeds edges of container.

### PT-3-2: Architect / team headshot rendering `[agent]` `BLOCKER`

**Setup:** Architect CPT with `hero_image_aspect: square`. Real square or near-square headshot.
**Pass:** Headshot crops to circle/square per `photo_shape`, sized correctly per `photo_size`. No awkward crops.
**Fail signals:** Crop cuts off chin / forehead. Aspect ratio breaks at certain breakpoints.

### PT-3-3: Compact-grid icon-only enforcement with images `[smoke + agent]` `IMPORTANT`

**Setup:** A compact-grid grouping with items that have `image_id` set (e.g., auto-pulled from linked posts via `taxonomy_match`).
**Pass:** Images stripped at render time per critical_rules.compact_grid_strips_image. Icons render in their place (per-item or default fallback).
**Fail signals:** Native-size image sprawls in compact-grid item — the v0.2.0 bug.

### PT-3-4: Postgrid thumbnail behavior `[agent]` `IMPORTANT`

**Setup:** A Promptless `postgrid` section pulling from a PRE CPT where some posts have featured images and some don't.
**Pass:** Posts with images render thumbnails. Posts without images render placeholder or fall back gracefully. Card heights stay consistent.
**Fail signals:** Cards collapse or stretch wildly. Missing images cause layout shift.

### PT-3-5: Trashed attachment fallback `[smoke]` `IMPORTANT`

**Setup:** Post with featured image set; trash the attachment via WP admin.
**Pass:** Renderer skips the image gracefully. Alt text fallback chain still produces meaningful text. No "broken image" icon in the rendered HTML.
**Fail signals:** PHP warning about missing attachment. Empty `<img>` tag rendered. Layout broken.

---

## Tier 4 — PRE-side scale

Until we hit real client volume, we don't know where the slow paths are.

### PT-4-1: 100+ post stress test `[agent]` `IMPORTANT`

**Setup:** Script-create 100 CPT posts (any CPT). Each post has all groupings populated.
**Steps:**
1. Run a script that creates 100 posts via the connector (`wp-cli` if needed; `postruntime_create_post` in a loop)
2. Open the WP admin list-table for that CPT
3. Open one post's single page (cold cache)
4. Open the same single page (warm cache)

**Pass:** Admin list-table loads in < 3s. Single-page cold render < 2s. Warm render < 200ms (cache hit).
**Fail signals:** Admin list-table N+1 query. Single page > 5s render. Cache key collisions.

### PT-4-2: Postgrid pagination `[agent]` `IMPORTANT`

**Setup:** Promptless postgrid section with `posts_per_page: 12` against a CPT with 50+ posts. `enable_pagination: true`.
**Pass:** Page 1 renders, page links work, page 5 renders. Each page render is fast.
**Fail signals:** Pagination breaks. URLs malformed. Cache poisoning between pages.

### PT-4-3: Cache invalidation correctness `[smoke]` `IMPORTANT`

**Setup:** A rendered post with cached output. Then update the post or one of its referenced posts.
**Steps:**
1. Render the post (warm the cache)
2. Update the post's title via `update_post`
3. Re-render

**Pass:** Updated title shows immediately on second render. Cache invalidated correctly.
**Fail signals:** Stale title rendered. User has to manually flush cache.

### PT-4-4: Many groupings per CPT `[agent]` `NICE`

**Setup:** Register 6 groupings on one CPT (the documented max we'd want). Populate all on one post.
**Pass:** Single page renders all 6 groupings cleanly with reasonable layout. Sidebar groupings stack correctly.
**Fail signals:** Visual rhythm breaks. Groupings overflow container. Sidebar overstuffed.

---

## Tier 6 — Connector permissions + multi-user

Currently every test runs as admin. Real agencies have non-admin contributors.

### PT-6-1: Non-admin role `[smoke]` `IMPORTANT`

**Setup:** Create a user with `editor` role (no `manage_options`). Issue an Application Password for them.
**Steps:** Connector tries to register a CPT (admin-cap action) — should fail. Connector tries to create/update a post in an existing CPT (post-level cap) — should succeed.
**Pass:** Capability check returns 403 for CPT registration; allows post-level operations.
**Fail signals:** Editor can register CPTs (privilege escalation). Editor can't create posts (overly strict).

### PT-6-2: Concurrent writes `[agent]` `IMPORTANT`

**Setup:** Two browser tabs, both authenticated. Both attempt to update the same post simultaneously.
**Pass:** Both updates land. No data corruption. Race conditions (if any) handled cleanly via `connector_version` for CPT/grouping definitions.
**Fail signals:** One write silently overwrites the other without warning. Stale data persists.

### PT-6-3: Application Password rotation `[smoke]` `IMPORTANT`

**Setup:** Active connector with valid App Password. Revoke and reissue mid-session.
**Pass:** Old password starts returning 401 immediately. New password works after reconnect.
**Fail signals:** Old password keeps working (auth caching gone wrong). New password rejected.

### PT-6-4: Rate limit boundary `[smoke]` `NICE`

**Setup:** Hit a route's rate limit (e.g., `define_grouping` at 11 calls/min).
**Pass:** 11th call returns `429 rate_limit_exceeded` with `retry_after` field. Other routes' buckets unaffected.
**Fail signals:** Rate limit applied across routes (false positive). Rate limit not applied (no protection).

### PT-6-5: Connector revocation safety `[agent]` `BLOCKER`

**Setup:** Active session writing to staging site. Operator revokes the App Password from production by mistake (the 725 Print Lab class of mistake).
**Pass:** Calls to wrong site return 401. The `_site` envelope on every response makes the target identifiable before destructive operations.
**Fail signals:** Calls succeed against wrong site without identification. (This is the failure mode v0.3.0 specifically prevents.)

---

## Tier 7 — Migration

Once a real client moves staging → production, link portability matters.

### PT-7-1: Domain swap via link_post_id `[smoke]` `BLOCKER`

**Setup:** Create posts on `staging.example.com`. Update WP `siteurl` to `example.com`.
**Pass:** Internal links rendered via `link_post_id` resolve to the new domain. No hardcoded staging URLs in stored data.
**Fail signals:** Links still point at staging.

### PT-7-2: Plugin upgrade chain `[smoke]` `BLOCKER`

**Setup:** Fresh v0.1.0 install with sample data. Upgrade to v0.2.0. Upgrade to v0.3.0.
**Pass:** Existing CPT data renders correctly after each upgrade. New fields default sensibly. No data loss.
**Fail signals:** CPT settings reset to defaults. Stored data corrupted. Rendered output regresses.

### PT-7-3: Plugin uninstall + reinstall `[smoke]` `IMPORTANT`

**Setup:** Plugin active with sample data. Uninstall via WP admin (does NOT trigger `uninstall.php`'s `?purge_data=1` path). Reinstall the same version.
**Pass:** Per data-protection rules, post data + grouping definitions preserved. Re-registering CPTs restores access.
**Fail signals:** Data wiped on uninstall (regression). Stored shape no longer compatible with reinstalled version.

### PT-7-4: WP core upgrade `[human]` `NICE`

**Setup:** Plugin running on supported WP version. Upgrade WP core (e.g., 6.x → 7.x when it exists).
**Pass:** No PHP warnings/notices in `debug.log`. Frontend renders correctly. Connector still works.
**Fail signals:** Deprecated function calls. Block editor incompatibility. REST namespace conflicts.

---

## Tier 8 — Cross-plugin handshake

Where Promptless / FRE / FlowMint consume PRE data. The new surface; least-tested.

### PT-8-1: Promptless postgrid pulling from PRE CPT `[agent]` `BLOCKER`

**Setup:** Promptless page with `postgrid` section pulling from a PRE CPT.
**Pass:** All posts render as cards. Click-through to PRE single resolves. Excerpt renders. Cards layout correctly at all breakpoints.
**Fail signals:** Cards missing. Click target wrong. Layout collapses. (Verified ✅ in v0.3.0 hosted re-test.)

### PT-8-2: Promptless team member linking to PRE single `[agent]` `BLOCKER`

**Setup:** Promptless team section with members' `link` fields pointing at PRE singles.
**Pass:** Read-full-bio link resolves correctly. Destination renders the architect / team-member CPT single.
**Fail signals:** 404 on click. Wrong destination. (Verified ✅ in v0.3.0 hosted re-test.)

### PT-8-3: FRE form embedded on PRE single `[agent]` `IMPORTANT`

**Setup:** Create an FRE form via `formengine_create_form`. Embed via shortcode in a PRE single's body content (or as a registered grouping that takes shortcode content — TBD if PRE supports this).
**Pass:** Form renders inside PRE single. Submission creates an entry. PRE template doesn't break form's CSS or JS.
**Fail signals:** Form CSS conflicts with PRE template. Submission silently fails. Honeypot stripped wrongly.

### PT-8-4: FlowMint workflow triggered by PRE post creation `[agent]` `IMPORTANT`

**Setup:** FlowMint workflow defined to run on `wp_insert_post` (or whatever event matches PRE post creation). Workflow has a Drive upload + email step.
**Pass:** Creating a PRE post triggers the workflow. Workflow runs to completion. Side effects (file in Drive, email sent) verifiable.
**Fail signals:** Workflow doesn't trigger. Trigger fires but data passed is malformed. Post rolled back if workflow fails (atomic).

### PT-8-5: All four plugins active simultaneously `[human]` `BLOCKER`

**Setup:** Promptless WP, PRE, FRE, FlowMint all active on same install.
**Pass:** No JS console errors. No PHP warnings in `debug.log`. Each plugin's admin pages load. Each plugin's REST routes accessible.
**Fail signals:** JS error on any admin page. Conflicting hooks (one plugin breaks another's UI). Memory exhaustion.

---

## Tier 9 — Edge cases / error recovery

The "what if" cases. Most won't fire often but need to fail cleanly when they do.

### PT-9-1: Trashed post referenced via link_post_id `[smoke]` `IMPORTANT`

**Setup:** Project A's `lead_architect` grouping points at Architect Maren via `link_post_id`. Trash Maren's post.
**Pass:** Project A renders. Lead Architect featured-card either falls back to literal `link` URL (404 if visited) or skips rendering. No PHP error.
**Fail signals:** Project A render crashes. Project A renders with broken URL.

### PT-9-2: Deleted attachment as featured image `[smoke]` `IMPORTANT`

**Setup:** Post has `_thumbnail_id` pointing at attachment 999. Delete attachment 999.
**Pass:** Hero renders text-only (no broken `<img>` tag). Alt-text fallback chain produces meaningful text.
**Fail signals:** Broken image icon. PHP warning. Layout breaks.

### PT-9-3: Renamed grouping key `[smoke]` `IMPORTANT`

**Setup:** Grouping `at_a_glance` exists with stored data on N posts. Author renames the grouping key to `quick_specs` via `update_grouping`.
**Pass:** Existing post data either migrates to the new key OR the renamer warns about orphan data. No silent data loss.
**Fail signals:** Old key's data orphaned with no warning. Rendered posts lose their content.

### PT-9-4: Reordered groupings change display order `[agent]` `NICE`

**Setup:** A CPT's groupings are reordered via the admin UI.
**Pass:** Rendered posts reflect the new display order. Cache invalidation fires.
**Fail signals:** Stale order persists. Sidebar groupings show in unexpected position.

### PT-9-5: Network failure mid-deploy `[agent]` `IMPORTANT`

**Setup:** Disconnect network mid-`update_post` call.
**Pass:** Atomic rollback — post in stable state (either old data or new data, not partial). Backup record exists.
**Fail signals:** Post in partial-update state. Data corruption.

---

## How to add new tests

1. Pick the tier the new test belongs to.
2. Use the next sequential ID (e.g., `PT-3-6` if Tier 3 already has 5 tests).
3. Follow the same structure: setup / steps / pass / fail signals.
4. Tag with mode + severity.
5. Update the "Tier coverage at-a-glance" table at the top.
6. If the test is `[smoke]`, add the corresponding assertion to `smoke-phase1.php` or `smoke-phase3.php` in the same PR.

## When to retire a test

When a test has been green for 3 consecutive releases AND the underlying code path hasn't changed, downgrade severity (BLOCKER → IMPORTANT, IMPORTANT → NICE). Don't delete — even rare-firing tests document what we used to worry about.

## Findings format

When a test fails, file as `PT-{id}: {one-line summary}` and note:
- Which release surfaced it
- Whether it's a regression or a new gap
- Proposed fix scope (smoke addition, code change, both)

This format is what we used during the v0.3.0 pressure test (the CDATA leak, the cross-CPT icon resolution, the malformed-parameter bug). All three were findable, fixable, and shippable within one session because the discipline was already in place — this doc just makes it portable across releases.
