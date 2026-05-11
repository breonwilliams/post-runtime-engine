# Post Runtime Engine — Release Process

**This is the canonical release procedure.** Follow every step in order. If you're an AI assistant (Claude Code, Cowork, etc.) asked to "create a release" or "tag a new version" for this plugin, this document is your source of truth.

---

## Distribution model

PRE ships via **GitHub Releases** (not the WordPress.org plugin directory). Unlike its sister plugin FRE, PRE does **not** currently bundle a GitHub auto-updater — users install and update manually by downloading the ZIP from the GitHub release page and uploading via WP Admin → Plugins → Add New → Upload Plugin.

If you add an auto-updater in a future release, copy FRE's `FRE_GitHub_Updater` pattern and update this section.

---

## Version-stamp locations

Every release must update the version number in **every** location below. Mismatches between the plugin header, the `PRE_VERSION` constant, the `readme.txt` Stable tag, and the GitHub tag cause confusion at install time.

| File | Line / location | Format |
|------|----------------|--------|
| `post-runtime-engine.php` | Header `Version:` comment (~line 6) | `Version: 0.4.0` |
| `post-runtime-engine.php` | `PRE_VERSION` constant (~line 24) | `define( 'PRE_VERSION', '0.4.0' );` |
| `readme.txt` | `Stable tag:` (~line 7) | `Stable tag: 0.4.0` |
| `readme.txt` | `== Upgrade Notice ==` section | Add an entry for the new version |
| `CHANGELOG.md` | Move `[Unreleased]` content under a new heading | `## [0.4.0] — 2026-05-11` |
| Git tag | After commit | `v0.4.0` (with `v` prefix) |

> If schema-affecting changes were made: also bump the database schema version constant in `class-fmw-schema.php` equivalent for PRE (whichever migration version PRE uses).

---

## Pre-release checklist

- [ ] All code changes are committed and pushed to `main`
- [ ] `CHANGELOG.md` has a populated `[Unreleased]` section (move it under the new version heading during release)
- [ ] All five version-stamp locations updated to the new version
- [ ] `readme.txt` `Stable tag` matches the plugin header version exactly (Plugin Check will fail if not)
- [ ] Plugin Check run locally returns clean (no errors)
- [ ] No PHP errors in `debug.log` on a smoke-test install
- [ ] Spot-check at least one registered CPT renders correctly on the frontend

---

## Release commands (copy/paste-ready)

Replace `0.4.0` with the actual version. Run from the plugin root.

```bash
# 1. Verify version-stamp consistency before tagging.
grep -E "^ \* Version:|PRE_VERSION|Stable tag:" post-runtime-engine.php readme.txt

# 2. Commit the version bump.
git add -A
git commit -m "Release v0.4.0"

# 3. Tag with v prefix.
git tag v0.4.0

# 4. Push branch and tag to GitHub.
git push origin main --tags

# 5. Build the release ZIP.
./bin/build-release.sh

# 6. Create the GitHub Release and attach the ZIP.
gh release create v0.4.0 build/post-runtime-engine.zip \
    --title "v0.4.0" \
    --notes-file CHANGELOG.md
```

Alternative `--notes` form (focused summary instead of dumping the full CHANGELOG):

```bash
gh release create v0.4.0 build/post-runtime-engine.zip \
    --title "v0.4.0" \
    --notes "Connector hardening + Plugin Check compliance. See CHANGELOG.md for details."
```

**Critical:** Always attach `build/post-runtime-engine.zip` (the build script's output). The build also produces a versioned copy at `build/post-runtime-engine-v0.4.0.zip` — both contain the same contents but the unversioned name is what WordPress's update flow expects.

---

## Post-release verification

1. Open the GitHub release page. Confirm the ZIP asset is attached.
2. On a test WordPress site, install the new ZIP via **Plugins → Add New → Upload Plugin → Replace current with uploaded** (since there's no auto-updater yet).
3. Activate without errors.
4. Visit **Post Runtime → CPTs** and confirm registered post types are intact.
5. Visit a single-post page for a registered CPT and confirm groupings render correctly.

---

## CHANGELOG format

Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```markdown
## [Unreleased]

## [0.4.0] — 2026-05-11

### Added
- New feature

### Changed
- Modified behavior

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

Note: PRE is currently in the `0.x` series. Bumping to `1.0.0` is reserved for the first version Breon considers customer-ready for general distribution.

---

## Plugin Check expectations

The current Plugin Check report should return **fully clean** — PRE has no known false-positives or accepted warnings. If any error appears, fix it before tagging.

---

## Emergency rollback

If a bad release ships:

1. **Immediately tag a fix**: bump the patch version, fix the issue, and follow the full release flow above with the new version.
2. **Don't delete the bad tag** — manual installers may already have the bad version, but forcing them backwards is harder than rolling forward.
3. Note the regression in `CHANGELOG.md` under the new version's `Fixed` section.

---

**Last updated:** 2026-05-11
