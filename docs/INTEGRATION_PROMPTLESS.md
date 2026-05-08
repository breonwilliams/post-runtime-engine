# Post Runtime Engine ↔ Promptless WP — Integration Boundary

This is the documented boundary between Post Runtime Engine and Promptless WP. Both plugins ship and version independently; this contract defines what they're allowed to assume about each other.

This is the third such boundary doc in the FlowMint stack. The other two:
- `form-runtime-engine` ↔ Promptless WP (also CSS-token-only, also one-way)
- `flowmint-workflows` ↔ Form Runtime Engine (PHP hooks + classes, also one-way)

This boundary mirrors FRE's pattern most closely: a CSS-only contract, no PHP coupling, full standalone operation possible.

## Direction

**One-way dependency at the CSS level only:** Post Runtime Engine reads `--aisb-*` CSS custom properties emitted by Promptless WP. Promptless WP has zero knowledge of Post Runtime Engine.

If Promptless WP is deactivated or not installed:
- Post Runtime Engine continues operating fully — CPT registration, grouping editing, frontend rendering, connector, MCP all work unchanged
- The `--aisb-*` tokens fall back to the documented values in `docs/AISB_TOKEN_CONTRACT.md`
- Visual quality is "minimum viable" rather than "fully branded" — same degradation behavior FRE has
- No admin notice about a missing dependency, because Promptless is not actually a dependency

If Post Runtime Engine is deactivated:
- Promptless WP continues operating as if PRE never existed
- Pages, sections, the editor, the connector, the AI generation all work unchanged
- Any CPTs that PRE registered remain registered ONLY for the duration of PRE's activation; on deactivation, those CPT registrations are removed (so post types disappear from the admin UI)
- **Post data is preserved.** The `_pre_groupings` post meta stays in the database. If PRE is re-activated, all data resumes correctly.

## What Post Runtime Engine reads from Promptless WP

### CSS design tokens

Listed exhaustively in `docs/AISB_TOKEN_CONTRACT.md`. This is the only coupling.

### Nothing else

PRE does NOT:
- Call any PHP class from Promptless WP (no `class_exists('AISB_Plugin')` checks gating functionality)
- Hook into any `aisb_*` action or filter
- Read any `aisb_*` option or `_aisb_*` post meta
- Make REST calls to Promptless's `/promptless/v1/*` endpoints
- Use Promptless's `SectionRenderer` to render anything
- Depend on Promptless's editor, AI provider system, or connector

This is intentional and load-bearing. The architectural simplification of "CSS-only coupling" is what lets PRE ship and version independently of Promptless.

## What Post Runtime Engine writes to Promptless WP

### Nothing

PRE does not modify any Promptless-owned data. The two plugins coexist on the same WordPress install without writing to each other's namespaces.

## What if both plugins are activated on the same post type?

This is a real scenario: a user could register a `listing` CPT in Post Runtime Engine, then go into Promptless WP's settings and enable Promptless on the `listing` post type, then build a hand-authored Promptless page for one specific listing.

The behavior is well-defined:

1. **Per-post precedence: Promptless wins.** If a post has `_aisb_enabled = 1` (Promptless's flag), Promptless's template renders for that post. PRE's template is NOT used.
2. **For all other posts in the same CPT, PRE renders.** A single CPT can have a mix: most posts use PRE's template (driven by groupings), one flagship listing uses a hand-built Promptless page.
3. **No conflict on data.** The two plugins use different post meta keys (`_aisb_*` vs `_pre_*`).

This is actually a useful pattern. Most listings render through the templating system; the marquee listing gets a hand-crafted page. Both work.

The template router for PRE explicitly checks `! get_post_meta( $post_id, '_aisb_enabled', true )` before taking over. If Promptless flagged the post, PRE steps aside.

## What if Promptless WP is not installed but the Promptless theme is?

The Promptless theme has fallback CSS that emits a minimal `--aisb-*` token set when the Promptless plugin is not active (see `themes/promptless-theme/inc/class-promptless-integration.php::add_inline_css_variables`). PRE will inherit those fallback tokens.

So the visual quality progression is:

| Setup | Visual quality |
|---|---|
| Vanilla WordPress + PRE | Minimum-viable (PRE's documented fallbacks) |
| Promptless theme + PRE (no Promptless plugin) | Better (theme's fallback `--aisb-*` tokens) |
| Promptless plugin (any theme) + PRE | Full brand (50+ calculated tokens) |
| Promptless plugin + Promptless theme + PRE | Full brand + cohesive chrome |

## Hooks Post Runtime Engine offers (other plugins / themes can listen)

These are emitted by PRE for downstream consumers. Promptless WP does NOT listen to any of these — they exist for theme code and future integrations.

| Hook | Signature | When |
|---|---|---|
| `pre_cpt_registered` | `($cpt_slug, $definition)` | A new CPT is registered through PRE |
| `pre_cpt_unregistered` | `($cpt_slug)` | A CPT registration is removed |
| `pre_grouping_defined` | `($cpt_slug, $grouping_key, $definition)` | A grouping definition is created or updated |
| `pre_post_groupings_saved` | `($post_id, $groupings)` | Per-post grouping values are saved |
| `pre_template_rendered` | `($post_id, $cpt_slug, $rendered_html)` | A single-post template finishes rendering |
| `pre_grouping_rendered` | `($grouping, $variant, $html)` | An individual grouping finishes rendering |

Use cases:
- A theme listens to `pre_template_rendered` to inject schema markup
- A custom integration listens to `pre_post_groupings_saved` to sync to a CRM
- An analytics plugin listens to `pre_grouping_rendered` to track engagement per grouping type

## Filters Post Runtime Engine offers

| Filter | Signature | Purpose |
|---|---|---|
| `pre_cpt_definition` | `($definition, $cpt_slug)` | Modify a CPT's `register_post_type()` args before registration |
| `pre_grouping_definition` | `($definition, $cpt_slug, $grouping_key)` | Modify a grouping definition at runtime (e.g., conditional fields based on user role) |
| `pre_post_groupings` | `($groupings, $post_id)` | Modify the resolved groupings for a post before rendering |
| `pre_layout_variants` | `($variants)` | Add a custom layout variant (for power users / themes) |
| `pre_icon_library` | `($icons)` | Extend the available icon set |
| `pre_template_path` | `($template_path, $cpt_slug)` | Override the template file used for a specific CPT |

These extension points let theme developers customize PRE's behavior without forking. Adding a custom layout variant via filter is particularly powerful — it lets advanced themes ship their own grouping variant alongside the four built-in ones, with full design-token integration.

## Version compatibility

Post Runtime Engine declares no plugin dependency on Promptless WP — there is no `Requires Plugins` header, no admin notice if Promptless is missing, no soft dependency check.

The `--aisb-*` token contract version is documented in `docs/AISB_TOKEN_CONTRACT.md`. Promptless WP `1.3.0+` is the recommended producer version for full token coverage; older Promptless versions emit a subset of the tokens but PRE still works (fallbacks fill the gaps).

When Promptless WP releases a new version, PRE's CI test (Phase 4+) verifies that all consumed tokens are still emitted. If a token is removed without proper deprecation, the test fails and PRE's contract maintainers coordinate with Promptless's maintainers.

## Data ownership

| Resource | Owner |
|---|---|
| `--aisb-*` CSS custom properties | Promptless WP (producer) |
| Section definitions, page meta `_aisb_*`, sections meta `_aisb_sections` | Promptless WP |
| Promptless's `aisb_*` options (global settings, AI keys, etc.) | Promptless WP |
| CPT registry (`pre_cpts` option) | Post Runtime Engine |
| Grouping definitions (`pre_groupings_*` options) | Post Runtime Engine |
| Per-post grouping values (`_pre_groupings` post meta) | Post Runtime Engine |
| PRE settings, audit trails | Post Runtime Engine |

When PRE is uninstalled (`uninstall.php`), only PRE-owned options are dropped. Post meta is preserved by default (mirrors Promptless's data-protection pattern). Promptless data is left untouched.

When Promptless is uninstalled, PRE continues operating with fallback design tokens. PRE's data is unaffected.

## What this means for the connector / Cowork integration

A Cowork session orchestrating across both plugins (and possibly FRE and FlowMint) would call:

- `wordpress_*` tools (Promptless WP connector) to create pages, deploy sections, manage menus
- `post-runtime-*` or `pre_*` tools (PRE connector — names TBD) to register CPTs, define groupings, populate per-post values
- `formengine_*` tools (FRE connector) to define forms whose shortcodes get embedded in PRE groupings or Promptless sections
- (Eventually) `workflow_*` tools (FlowMint Workflows MCP) to wire post-submission workflows

Each connector authenticates separately, rate-limits separately, and operates on its own data. Cowork's value is in orchestrating across them; the plugins themselves stay decoupled.

The `WORKFLOW_PROMPTLESS_INTEGRATION.md` doc in form-runtime-engine documents the existing Promptless + FRE pipeline; a parallel doc will be added in this plugin (Phase 5) to document the Promptless + PRE + FRE pipeline.

## Why this boundary is shaped this way

The reasoning behind CSS-token-only coupling (instead of, say, calling `\AISB\Modern\Core\SectionRenderer::render_section()` from PRE):

1. **Independent shipping.** PRE can release a v1.5 patch without coordinating with Promptless's release cycle.
2. **Independent installation.** A site can run PRE alone if they want CPT singles styled in the design system but use a different page builder (Elementor, Bricks, FSE) for landing pages.
3. **Cleaner mental model for users.** "Promptless does pages, PRE does CPT singles, FRE does forms" — three plugins with three jobs, each with its own brand styling that matches via the token contract.
4. **No tight-coupling drift.** Calling Promptless's PHP classes from PRE would create surface area that's easy to break across versions. CSS tokens are stable, declarative, and observable.
5. **Mirrors FRE's pattern.** The FlowMint stack already validated this pattern with FRE; reusing it for PRE preserves consistency.

The cost is duplication of some CSS — both PRE and FRE define their own card / button / surface styles using the tokens, rather than sharing a base stylesheet. That duplication is small (a few hundred lines per plugin) and worth the independence.

## When this boundary changes

If a future feature genuinely requires PRE to call into Promptless's PHP layer (e.g., "render a PRE grouping inside a Promptless section"), that's a real architectural conversation that requires:

1. Updating this doc with the new coupling
2. Updating Promptless WP's relevant doc to declare PRE as a known consumer
3. Pinning a minimum compatible Promptless version
4. Adding a graceful degradation path if Promptless isn't installed
5. CI test coverage for the new contract

Until that conversation happens, the boundary is CSS-only.
