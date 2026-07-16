# Post Runtime Engine — Release Process

**This is the canonical release procedure.** Follow every step in order. If you're an AI assistant (Claude Code, Cowork, etc.) asked to "create a release" or "tag a new version" for this plugin, this document is your source of truth.

---

## Distribution model

PRE (plugin slug **`promptless-cpt-pages`**) ships **exclusively through the WordPress.org plugin directory**. Updates are delivered by WordPress core's built-in update system — the plugin bundles **no auto-updater of its own** (WordPress.org guideline #8 prohibits plugins from overriding the core update mechanism).

- Public page: https://wordpress.org/plugins/promptless-cpt-pages
- SVN repo: https://plugins.svn.wordpress.org/promptless-cpt-pages

> The GitHub auto-updater and the old dual GitHub/WP.org build flavors were **retired** when the plugin was accepted into the directory. Do not reintroduce an updater — `bin/build-release.sh` fails the build if a `PCPTPages_GitHub_Updater` reference reappears.

---

## Version-stamp locations

Every release must update the version number in **every** location below. Mismatches between the plugin header, the `PCPTPages_VERSION` constant, the `readme.txt` Stable tag, and the SVN tag cause confusion at install time (and Plugin Check fails if the header and Stable tag disagree).

| File | Line / location | Format |
|------|----------------|--------|
| `post-runtime-engine.php` | Header `Version:` comment (~line 6) | `Version: 0.5.4` |
| `post-runtime-engine.php` | `PCPTPages_VERSION` constant (~line 24) | `define( 'PCPTPages_VERSION', '0.5.4' );` |
| `readme.txt` | `Stable tag:` (~line 7) | `Stable tag: 0.5.4` |
| `readme.txt` | `== Upgrade Notice ==` section | Add an entry for the new version |
| `CHANGELOG.md` | Move `[Unreleased]` content under a new heading | `## [0.5.4] — 2026-06-15` |
| SVN tag | During publish | `tags/0.5.4` (no `v` prefix — WP.org convention) |

> If schema-affecting changes were made, also bump the plugin's DB schema/migration version constant so the upgrade routine runs.

---

## Pre-release checklist

- [ ] All code changes are committed and pushed to `main`
- [ ] `CHANGELOG.md` has a populated `[Unreleased]` section (move it under the new version heading during release)
- [ ] All version-stamp locations updated to the new version
- [ ] `readme.txt` `Stable tag` matches the plugin header version exactly (Plugin Check fails if not)
- [ ] **Plugin Check returns clean** against the staged build (`build/promptless-cpt-pages/`) — no errors
- [ ] No PHP errors in `debug.log` on a smoke-test install
- [ ] Spot-check at least one registered CPT renders correctly on the frontend

---

## Build + publish (copy/paste-ready)

Replace `0.5.4` with the actual version. Run from the plugin root.

```bash
# 1. Verify version-stamp consistency before publishing.
grep -E "^ \* Version:|PCPTPages_VERSION|Stable tag:" post-runtime-engine.php readme.txt

# 2. Commit the version bump on main.
git add -A
git commit -m "Release 0.5.4"
git push origin main

# 3. Build the WordPress.org-compliant ZIP + staged tree.
#    Output: build/promptless-cpt-pages.zip and build/promptless-cpt-pages/
./bin/build-release.sh

# 4. Verify the STAGED build with Plugin Check before it goes near SVN.
wp plugin check build/promptless-cpt-pages --format=table

# 5. Create git tag and GitHub release.
git tag v0.5.4
git push --tags
gh release create v0.5.4 --title "v0.5.4" --generate-notes
```

### Publish to the WordPress.org SVN repo

SVN is a *release* system, not a dev VCS — only commit ready-to-ship versions.

> **The SVN committer for this plugin is `promptlesswp`** — NOT the personal `breonwilliams` account, and not whatever SVN has cached from another repo. Committing as the wrong account fails with a confusing error that looks like a permissions problem rather than an identity one:
>
> ```
> svn: E165001: Commit blocked by pre-commit hook (exit code 1) with output:
> Access denied: user 'BreonWilliams' cannot modify:
>   /promptless-cpt-pages/tags/0.6.6
>   ... (every path in the commit)
> ```
>
> That is the ACL rejecting an account that is not on the committer list — the code, the build, and the staged working copy are all fine. Nothing is sent, so just re-run the commit with the right identity; there is no cleanup:
>
> ```bash
> svn ci --username promptlesswp -m "Release X.Y.Z"
> ```
>
> If SVN keeps offering a stale identity, clear the cached auth and retry: `rm -rf ~/.subversion/auth/svn.simple`.
>
> The committer list lives at https://wordpress.org/plugins/promptless-cpt-pages/advanced/ under Committers.

The SVN password is set separately under profiles.wordpress.org → Account & Security — it is **not** the WordPress.org login password.

```bash
# One-time: check out the SVN repo somewhere OUTSIDE this git repo.
svn co https://plugins.svn.wordpress.org/promptless-cpt-pages svn-promptless-cpt-pages
cd svn-promptless-cpt-pages

# 6. Sync the freshly staged build into trunk (mirror exactly — add new files,
#    remove deleted ones). Point rsync at the staged tree from step 3.
rsync -av --delete \
  --exclude='.svn' \
  "/path/to/post-runtime-engine/build/promptless-cpt-pages/" trunk/
svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm   # stage deletions

# 7. Tag the release by copying trunk -> tags/<version>.
svn cp trunk "tags/0.5.4"

# 8. Confirm trunk/readme.txt "Stable tag: 0.5.4" points at the new tag.

# 9. Commit trunk + tag together.
svn ci -m "Release 0.5.4"
```

> Plugin banners, icons, and screenshots live in the SVN repo's top-level `assets/` directory (a sibling of `trunk/` and `tags/`), **not** inside `trunk/`. They only need committing when the artwork changes.

---

## Post-release verification

1. Wait a few minutes, then open https://wordpress.org/plugins/promptless-cpt-pages and confirm the new version number shows.
2. On a test WordPress site already running the previous version, go to **Dashboard → Updates** and confirm the update is offered by core (no manual ZIP needed).
3. Update, activate without errors.
4. Visit **Post Runtime → CPTs** and confirm registered post types are intact.
5. Visit a single-post page for a registered CPT and confirm groupings render correctly.

---

## CHANGELOG format

Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```markdown
## [Unreleased]

## [0.5.4] — 2026-06-15

### Added
- New feature

### Changed
- Modified behavior

### Removed
- Retired capability

### Fixed
- Bug fix
```

Allowed sections: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.

---

## Version numbering

[Semantic Versioning](https://semver.org/) — `MAJOR.MINOR.PATCH`:

- **MAJOR** — breaking changes (CPT schema changes, removed hooks, grouping shape changes)
- **MINOR** — new features, backward compatible
- **PATCH** — bug fixes only, no API changes

Note: PRE is currently in the `0.x` series. Bumping to `1.0.0` is reserved for the first version considered customer-ready for general distribution.

---

## Plugin Check expectations

The Plugin Check report against the **staged build** (`build/promptless-cpt-pages/`) must return **fully clean** — WordPress.org re-scans automatically and may close the plugin if a guideline or security issue is flagged. Run it before every publish. If any error appears, fix it before tagging.

---

## Test-install policy (duplicate-plugin prevention)

Learned during the v0.6.5 release verification (2026-07-11): a test
environment showed **two copies of the plugin** after uploading the new
release ZIP, because its older copy had been installed from a source whose
folder name differed.

**The rules:**

1. **The ONLY sanctioned install artifacts are `build/promptless-cpt-pages.zip`
   (built by `bin/build-release.sh`) and the WordPress.org directory.** Both
   install into `wp-content/plugins/promptless-cpt-pages/`, so upgrades
   always trigger WordPress's "Replace current with uploaded" flow.
2. **Never install on a test site from GitHub's auto-generated "Source
   code (zip)"** (its root folder is `post-runtime-engine-{tag}/`) **or by
   copying the dev repo folder** (`post-runtime-engine/`). Either lands in
   a differently-named folder, and every future release-ZIP upload will then
   install as a duplicate instead of replacing.
3. **Verification step for every release:** install the built ZIP on a test
   site that already has the previous version (installed from the same
   channel). WordPress must show the "Replace current with uploaded"
   screen. If it offers a fresh install instead, STOP — the folder names
   diverged somewhere.

**If a duplicate already exists** (two "Promptless CPT Pages" rows):

1. Deactivate the old row, activate the new one — all data lives in the
   database and carries over instantly.
2. **Do NOT click Delete on the old row.** Deleting through the Plugins
   screen runs `uninstall.php`, which removes the shared CPT/grouping
   configuration the surviving copy depends on. (Since 2026-07-11,
   `uninstall.php` carries a duplicate-install guard that skips cleanup
   while another copy remains — but don't rely on it for copies older
   than that guard.) Instead, delete the stale plugin FOLDER from disk;
   the row disappears from the Plugins screen on refresh.

---

## Emergency rollback

WordPress.org serves whatever version `trunk/readme.txt`'s `Stable tag` points at. If a bad release ships:

1. **Roll forward, don't delete the tag.** Bump the patch version, fix the issue, and run the full release flow with the new version.
2. If you must revert immediately, point `Stable tag` in `trunk/readme.txt` back at the last-good tag and `svn ci` — the directory will serve that version again.
3. Note the regression in `CHANGELOG.md` under the new version's `Fixed` section.

---

**Last updated:** 2026-07-11
