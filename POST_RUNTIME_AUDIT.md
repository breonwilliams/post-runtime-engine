# Post Runtime Engine — Architectural Audit

Prepared 2026-05. Same format as `themes/promptless-theme/THEME_AUDIT.md` and `plugins/form-runtime-engine/FORM_RUNTIME_AUDIT.md`. This is a **scaffold review** rather than a full audit, because the plugin is intentionally early-stage.

**Plugin version audited:** 0.3.0.

The bottom line up front: **the scaffolding is sound, no architectural drift from documented intent, and the plugin is further along than its own CLAUDE.md suggests.** The only material gap is the absence of automated tests — and because this is a young codebase, that gap is cheap to close *now* and will compound in cost the longer it's deferred.

Findings graded **Critical** (blocks healthy growth), **Important** (worth doing before more features land), **Nice-to-have** (polish), and **Documentation drift** (CLAUDE.md is stale and misrepresents the plugin's status — worth fixing).

---

## Documentation drift (read this first)

### D1. CLAUDE.md says "PLANNING PHASE — no runtime code yet" but the plugin is at v0.3.0 with multiple phases shipped

**Where:** `CLAUDE.md` opening line: *"Status (as of 2026-05-07, version 0.1.0): Phase 1 — data layer in place… no runtime code yet."*

**Reality on disk:**
- Plugin header reports **version 0.3.0**
- `includes/` contains four populated subdirectories: `Admin/`, `Connector/`, `Core/`, `Frontend/`
- Frontend rendering exists (renderer + variants + source resolver + template router)
- Connector REST API exists (~18 endpoints per the spec)
- Admin UI for CPT + grouping management exists
- ROADMAP confirms phases 1–6 complete with production-polish hardening

**Why it matters:** the CLAUDE.md guardrails (especially the "do not write Phase 1+ code without explicit confirmation" warning) are stale. Anyone onboarding to this plugin reads the CLAUDE.md and forms an inaccurate mental model of how complete it is. Worse, the "scope creep" warnings still phrased as planning-stage caveats might be misread as still-open design questions.

**Recommended fix:** update CLAUDE.md status block to reflect v0.3.0 + the actual feature surface. Re-frame the "guardrails" section from "do not build past Phase 1" to "post-launch maintenance constraints" — the substantive constraints (no second field type, no ACF, three layout positions, no custom DB tables) are still correct; only the pre-launch framing is wrong. ~30 min change.

---

## Critical

### C1. Zero unit test coverage

**Where:** entire plugin. Smoke tests exist (`smoke-phase1.php`, `smoke-phase3.php`) but no PHPUnit/Pest infrastructure.

**Why it's critical now (specifically: at v0.3.0, not later):**
- The CLAUDE.md target is **>80% coverage for v1.0 ship**. Approaching v1.0 with zero test infrastructure means a frantic test-writing sprint right when you're trying to harden for release.
- The classes that need testing the most (validators, registries, source resolvers) are also the ones that are most stable right now. Writing tests against a stable surface is much easier than writing them while the surface is changing.
- Compare with FRE: it shipped 1.0+ without tests and is now in a position where its largest classes can't be safely refactored. PRE has the chance to avoid that trap.

**Recommended fix:** copy FRE's PHPUnit bootstrap pattern (or model on Promptless's `tests/` infrastructure, which is more mature). Establish:
- `tests/Unit/` with cases for `PRE_Validator`, `PRE_CPT_Registry`, `PRE_Grouping_Registry`, `PRE_Post_Data`
- `tests/Integration/` with end-to-end CPT-creation + grouping-population + render tests
- `tests/fixtures/` with known-good CPT and grouping configs
- Target: 60% coverage on the data layer in the first sprint, growing to 80% before v1.0

**Effort:** ~6–8 hours to scaffold + write 10–15 example tests. Then ~30 min per future PR to keep coverage steady.

---

## Important

### I1. Renderer caching is a cross-cutting concern that warrants explicit separation

**Where:** `PRE_Renderer` (per the audit, 2 responsibilities — rendering + caching). Currently acceptable, but worth flagging because the caching logic is the kind of thing that grows quietly: cache key construction, invalidation triggers, transient TTLs, cache busting on related-post changes, etc.

**Recommended fix:** when the caching logic exceeds ~150 lines, extract `PRE_Render_Cache` as a thin proxy. For now, just *document* that the renderer's caching responsibilities should split out at that threshold so the next contributor doesn't bury more logic in the renderer.

**Effort:** none today, ~4 hours when the threshold is reached.

### I2. Connector spec drift — preflight returns fields not yet documented

**Where:** `docs/CONNECTOR_SPEC.md` doesn't document `critical_rules` or `field_name_hints` returned by the preflight endpoint, even though the code emits them. ROADMAP v0.3.0 entry mentions adding them.

**Why it matters:** the connector spec is the contract for external integrations (Cowork MCP, future agents). Drift between spec and reality means LLM-driven agents may misread what the API returns and fail in non-obvious ways.

**Recommended fix:** add a `critical_rules` field section + a `field_name_hints` field section to the spec, with example payloads. ~1 hour.

### I-NEW. PRE MCP wrapper input schemas are out of sync with the REST connector contract

**Discovered:** 2026-05-10 during the connector pressure test on staging.

**Where:** the gap is in the Node.js MCP wrapper package (separate repo, not in this plugin folder). Specifically, the input schemas for at least `postruntime_register_cpt` are missing fields that the WordPress plugin's REST connector accepts and the preflight `field_name_hints.cpt_definition` advertises. Confirmed missing from the MCP wrapper's `register_cpt` schema: `description`, `show_in_menu`, `menu_position`, `hierarchical`. Likely also affected: `update_cpt` (same input shape), and `define_grouping` / `update_grouping` (parallel pattern — needs audit).

**Why it matters:** the MCP server uses JSON Schema with `additionalProperties: false`, so any property not in the wrapper's schema is silently stripped before the request reaches WordPress. Users see no error — the call returns 201, the response shows the field as empty/default, and the data they sent simply isn't persisted. This is the worst kind of bug because there's no signal: the user has to manually compare what they sent against what came back to notice.

**How discovered:** during pressure testing, I called `postruntime_register_cpt` with a `description` value. The 201 response showed `"description": ""`. Round-trip test on the WP-side registry (added in this audit cycle as `test_register_persists_description_field`) confirms the WP plugin handles description correctly. The drop happened in the MCP wrapper layer.

**Recommended fix:** locate the PRE MCP wrapper repo. For each tool, derive the input schema from the WP plugin's preflight `field_name_hints` rather than maintaining a separate hand-authored list. Concretely: read `field_name_hints.cpt_definition` for `register_cpt` / `update_cpt`, `field_name_hints.grouping_definition` for `define_grouping` / `update_grouping`, and the corresponding shapes for post / item handlers. Bump the MCP package version, redeploy via the user's Claude Desktop setup, retest description round-trip via pressure test.

**Effort:** ~2-3 hours including the parallel audit of FRE and Promptless MCP wrappers (which may have the same drift pattern given they were authored in parallel by the same approach).

**Priority:** HIGH for the next session. Silent field-stripping in customer-onboarding flows means workflows are quietly broken without a way to detect it from the user-facing output alone.

### I3. Pre-Phase-2 expectations need reconciling with v0.3.0 reality

**Where:** the CLAUDE.md "Phased build status" table claims phases 1–6 are "Not started." Per the ROADMAP and on-disk evidence, multiple phases are complete. This is the same drift as D1 but worth calling out separately because it blocks accurate future planning — a contributor planning "Phase 4" may not realize it's already shipped in part.

**Recommended fix:** update the phased build status table to reflect actual state. ~15 min change once D1 is done.

---

## Nice-to-have

- **N1.** `PRE_Admin` instantiates sub-pages inline at three different points. Could become a single factory method. Non-blocking.
- **N2.** No PSR-4 / Composer (matches FRE convention). Standardizing across all three plugins (Promptless, FRE, PRE) would reduce cognitive load for cross-plugin contributors but isn't urgent.
- **N3.** Consider a `tests/scripts/` directory for the existing smoke tests so they're discoverable next to the future PHPUnit suite. Not architectural, just discoverability.

---

## Non-issues (verified as correct choices, not bugs)

- **NI1. No "field-type handler class" pattern.** FRE has 14 field types, each in its own handler class behind an interface. PRE has ONE primitive (the grouping). The audit agent flagged this as "deviation from FRE pattern" but the right call is exactly what PRE did: don't introduce extensibility infrastructure until you have a second primitive justifying it. If/when v1.1 adds a second primitive (gallery repeater, testimonial repeater, etc.), revisit and consider FRE's pattern.
- **NI2. wp_options + post_meta only, no custom DB tables.** Honored cleanly. Stays portable across managed hosts.
- **NI3. CSS-only coupling to Promptless.** Verified — no `class_exists('AISB_Plugin')` checks gating functionality. Tokens consumed via documented `--aisb-*` contract with fallbacks.
- **NI4. Three layout positions, four variants — both hardcoded in `PRE_Validator`.** Per the architectural decisions, these are intentional constraints, not arbitrary limits. The hardcoding is correct (acts as the enforcement mechanism for the constraint).

---

## Comparison with FRE and Promptless (post-refactor)

| Aspect | PRE (v0.3.0) | FRE (v1.6.1) | Promptless (post-Phase-3) | Verdict |
|---|---|---|---|---|
| Bootstrap | Singleton on `plugins_loaded` | Same | Same | Identical pattern |
| Autoloader | Static class-map | Static class-map | Composer + PSR-4 | PRE matches FRE; doesn't match Promptless. Acceptable inconsistency. |
| Registries | `PRE_CPT_Registry` + `PRE_Grouping_Registry` (data-only) | `FRE_Registry` + interface-based field handlers | `SectionRegistry` (per-type renderer classes) | PRE simpler, intentionally so |
| Validation | `PRE_Validator` (rule methods, static) | `FRE_Validator` (rule methods, static) | `SchemaValidator` + per-type validators | PRE matches FRE |
| Connector | `PRE_Connector_API` (single class, all routes) | `FRE_Connector_API` (single class, all routes — 1159 lines, flagged as god object) | n/a | PRE follows FRE's pattern. **Heads up:** if PRE's connector grows to ~20 routes, the same god-object risk applies. Consider splitting earlier. |
| Tests | None | None (FRE's biggest gap) | Snapshot suite + unit tests | PRE has time to do this right; FRE has missed the easy window |
| Storage | wp_options + post_meta | wp_posts + wp_postmeta | wp_options + post_meta | All three use similar patterns |

---

## Recommended sequencing

PRE is in good shape. The work to do is small and clearly scoped:

**This sprint (~1 week):**
1. **D1 + I3 + I2** — documentation refresh. Update CLAUDE.md status, refresh phased build status table, document the preflight response fields. ~2 hours total.
2. **C1 Phase 0a** — PHPUnit scaffold + 10–15 unit tests for the data-layer registries and validator. ~6–8 hours.

**Next sprint (~1 week):**
1. **C1 Phase 0b** — extend coverage to the renderer + source resolver + connector endpoints. Aim for 60% by end of sprint.

**Before v1.0 ship:**
1. Coverage to 80% per the documented target.
2. Audit `PRE_Connector_API` size against FRE's god-object precedent. If it's approaching ~600 lines and still single-class, plan a split before v1.0 to avoid inheriting FRE's debt pattern.
3. Audit `PRE_Renderer` for the caching extraction trigger (I1).

**Architectural opinion:** PRE has the cleanest scaffolding of the three plugins because it was built with the lessons from Promptless and FRE in hand. The biggest risk to this codebase isn't bad architecture — it's *waiting too long to add tests* and then having the same "can't refactor safely" problem FRE has now. Doing C1 in this sprint protects the next 12 months of velocity for relatively trivial up-front cost.
