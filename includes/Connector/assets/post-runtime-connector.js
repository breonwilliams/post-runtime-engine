#!/usr/bin/env node

/**
 * Post Runtime Engine — Cowork MCP Connector
 *
 * A stdio MCP server that bridges Claude Cowork (via Claude Desktop) to
 * the Post Runtime Engine's WordPress REST API. Runs on the user's local
 * machine so it can make HTTP(S) requests to any WordPress site with
 * Application Password authentication.
 *
 * The bridge exists because the Cowork sandbox cannot make outbound HTTP
 * requests to arbitrary hosts. By running Node.js locally and speaking
 * the MCP protocol over stdio to Claude Desktop, we give Cowork full
 * REST access to the user's WordPress install without a server-side
 * agent.
 *
 * Contract: this server maps one-to-one to the REST endpoints documented
 * in docs/CONNECTOR_SPEC.md. Any change to the tool set here must also
 * be reflected in the spec — and vice versa.
 *
 * Environment variables:
 *   POST_RUNTIME_SITE_URL      WordPress site URL (https://example.com)
 *   POST_RUNTIME_USERNAME      WordPress user login
 *   POST_RUNTIME_APP_PASSWORD  WordPress Application Password (spaces OK,
 *                              stripped on use)
 *
 * Forked from the FRE / Promptless connector pattern. The MCP stdio
 * framing, Basic Auth + ModSecurity workarounds, and protocol-version
 * echo in the initialize handler are preserved verbatim because the
 * fixes there apply equally here.
 */

const http = require("http");
const https = require("https");
const { URL } = require("url");

// ---------------------------------------------------------------------------
// Tool definitions. Mirror docs/CONNECTOR_SPEC.md §6.
// ---------------------------------------------------------------------------

const TOOLS = [
  {
    name: "postruntime_preflight",
    description:
      "Connector readiness check. MUST be called first in any session. Returns plugin_version, data_version, wp_version, the user's capability flags, the list of currently registered CPTs, and whether Promptless WP is active (so design tokens will inherit). Use the result to decide which CPTs already exist before registering new ones.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
  {
    name: "postruntime_list_icons",
    description:
      "Browse the curated 53-icon library AND learn the Iconify support contract. Returns: (1) icons[] — each entry has id, label, category (one of 13: General, Property & Real Estate, Business & Legal, Education, Communication, Location & Time, Commerce, People, Food & Hospitality, Medical & Health, Creative & Media, Fitness & Wellness, Travel), search tags, and iconify_code (the Material Design Icons equivalent in collection:name form). (2) iconify block — confirms Iconify codes are accepted ANYWHERE the curated ids are (icon_id on grouping items, default_icon on CPTs), with the format pattern, browse URL, and full legacy → Iconify mapping. Curated ids render inline SVG (fastest paint); Iconify codes render via the iconify-icon web component (any of 200,000+ icons across 100+ sets at icon-sets.iconify.design — pick mdi:hammer-wrench for plumbing, logos:wordpress for WordPress, fa6-solid:tooth for dental, etc.). Prefer Iconify codes for industry-specific glyphs the curated set doesn't cover and for parity with Promptless WP. Both formats pass through the same validator.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
  {
    name: "postruntime_list_variants",
    description:
      "Layout variant catalog with rendering hints. Returns the 4 variants: compact-grid (icon + heading, no supporting text), card-grid (icon/image + heading + supporting text), featured-card (single item, image-prominent — REQUIRES max_items=1 on the grouping definition), horizontal-row (inline chips for at-a-glance facts). Each entry's `supports_supporting_text` flag tells you whether to populate that field.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
  {
    name: "postruntime_list_positions",
    description:
      "Layout position catalog. Returns the 3 positions a grouping can occupy: above_main (above the WP editor body content), below_main (below it), sidebar (right column on desktop, stacks below on mobile).",
    inputSchema: { type: "object", properties: {}, required: [] },
  },

  {
    name: "postruntime_list_cpts",
    description:
      "List every CPT registered through Post Runtime Engine, with full definitions. Each entry includes slug, labels, supports, public/has_archive flags, taxonomies, capability_type, connector_version (bumps on every update), and timestamps. Use to discover what's already registered before creating something new.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
  {
    name: "postruntime_register_cpt",
    description:
      "Register a new custom post type. Use when the user wants a new content type (listings, attorneys, events, courses, team members, services, products). After registration, define the CPT's groupings via postruntime_define_grouping before creating posts. Slug must be snake_case ≤20 chars and not a reserved WP type (post, page, attachment, etc.). Returns the registered CPT shape with connector_version=1. " +
      "Pick hero_layout based on the content shape: 'split' (image side-by-side with title, 1:1 aspect ratio) for profile-style content like real estate listings, attorney bios, team members, instructor profiles; 'stacked' (image as a banner above the title, 16:9 aspect ratio) for editorial content like events, courses, articles. Default 'stacked'.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string", description: "snake_case, ≤20 chars, unique" },
        label_singular: { type: "string", description: "e.g. \"Listing\"" },
        label_plural: { type: "string", description: "e.g. \"Listings\"" },
        supports: {
          type: "array",
          items: { type: "string" },
          description: "WP supports flags. Common: title, editor, thumbnail, excerpt",
        },
        public: { type: "boolean", default: true },
        has_archive: { type: "boolean", default: true },
        show_in_rest: { type: "boolean", default: true },
        menu_icon: { type: "string", description: "Optional dashicons class" },
        taxonomies: {
          type: "array",
          items: { type: "string" },
          description: "Optional taxonomy slugs to attach",
        },
        rewrite: {
          type: "object",
          description: "Optional rewrite rules — { slug, with_front }",
        },
        capability_type: { type: "string", default: "post" },
        hero_layout: {
          type: "string",
          enum: ["stacked", "split", "overlay"],
          default: "stacked",
          description:
            "stacked = featured image as 16:9 banner above title (best for editorial CPTs: events, courses, articles). split = featured image side-by-side with title at 1:1 aspect ratio (best for profile-shaped CPTs: real estate listings, attorney bios, team pages). overlay = title/badges/meta rendered ON TOP of the featured image over a darkening gradient scrim (the premium treatment for image-rich CPTs — listings, events, venues, portfolios; posts without a featured image fall back to stacked automatically, so it's safe to enable before every post has imagery; pair with hero_width:'full' for the full-bleed cinematic version).",
        },
        hero_overlay_focus: {
          type: "string",
          enum: ["top", "center", "bottom"],
          default: "center",
          description:
            "Only meaningful when hero_layout is 'overlay'. Which part of the featured image stays visible when the fixed-height band crops it: 'top' keeps rooflines/skies, 'center' is the safe default, 'bottom' keeps foregrounds.",
        },
        hero_image_position: {
          type: "string",
          enum: ["left", "right"],
          default: "left",
          description:
            "Only meaningful when hero_layout is 'split'. Stacked layouts always place the image above the text.",
        },
        hero_image_aspect: {
          type: "string",
          enum: ["square", "landscape", "wide"],
          default: "square",
          description:
            "Only meaningful when hero_layout is 'split'. Pick to match the natural shape of the post's photos so they crop cleanly: 'square' (1:1) for headshots, profiles, team pages; 'landscape' (4:3) for property photos, product shots; 'wide' (16:9) for cinematic banner imagery. Stacked layouts always use a 16:9 banner regardless.",
        },
        hero_theme: {
          type: "string",
          enum: ["inherit", "light", "dark"],
          default: "inherit",
          description:
            "Hero contrast band (docs/HERO_CONTRAST_DESIGN.md). 'inherit' = hero follows the page's light/dark mode (default, pre-existing behavior). 'dark' = hero forced to a dark band regardless of page mode — the standard high-contrast detail-page treatment on light sites (title, featured image, and hero-positioned post fields render on a dark surface with WCAG-corrected token colors). 'light' = forced light (contrast band on dark sites). Applies to every post of the CPT.",
        },
        hero_width: {
          type: "string",
          enum: ["contained", "full"],
          default: "contained",
          description:
            "'contained' = hero stays inside the page content width (default). 'full' = the hero band's background bleeds to the viewport edges while hero content stays aligned with the page grid. Most striking combined with hero_theme:'dark'.",
        },
        default_icon: {
          type: "string",
          description:
            "Optional fallback icon used when a grouping item resolves to no media. Accepts EITHER a legacy curated id (e.g. 'home', 'user', 'shield' — call postruntime_list_icons to discover all 53 inline-SVG icons) OR any Iconify code in collection:name form (e.g. 'mdi:home', 'logos:wordpress', 'fa6-solid:tooth' — 200,000+ icons at icon-sets.iconify.design). Especially relevant for compact-grid and horizontal-row variants (icon-only by design). Pick a generic shape that fits the CPT (e.g. 'mdi:home' for listings, 'mdi:account' for team members). Leave empty to render iconless.",
        },
        archive_image_aspect: {
          type: "string",
          enum: ["16:9", "4:3", "1:1", "4:5"],
          default: "16:9",
          description:
            "Featured-image aspect ratio on the theme's archive cards for this CPT. " +
            "1:1 (square) or 4:5 (portrait) for people-centric CPTs (agents, team members); " +
            "4:3 for property/product photography; 16:9 (default) for editorial content. " +
            "Requires the Promptless theme; matches the PostGrid section's card_image_aspect_ratio vocabulary.",
        },
        archive_show_post_date: {
          type: "boolean",
          default: true,
          description:
            "Whether the theme archive card should render the post's create-date byline. Default true (backward compatible). Set false when the CPT already exposes a meaningful date via a post-field (e.g. an event CPT whose event_date field IS the date that matters — showing both the post create-date AND event_date on a card is duplicative). Affects only the theme-rendered archive card; the AISB PostGrid section has its own show-date toggle.",
        },
        archive_show_post_author: {
          type: "boolean",
          default: true,
          description:
            "Whether the theme archive card should render the post author byline. Default true. Set false for CPTs where the author is irrelevant or noisy (e.g. a multi-author publication where every post is by 'admin', or a directory CPT where the author identity isn't the point). Affects only the theme-rendered archive card.",
        },
      },
      required: ["slug", "label_singular", "label_plural"],
    },
  },
  {
    name: "postruntime_get_cpt",
    description:
      "Read one CPT's full definition. Returns the same shape as list_cpts entries. Capture the connector_version from the response if you intend to update it later — update_cpt requires it for concurrency safety.",
    inputSchema: {
      type: "object",
      properties: { slug: { type: "string" } },
      required: ["slug"],
    },
  },
  {
    name: "postruntime_update_cpt",
    description:
      "Update a CPT's definition. Slug is immutable (delete and re-register if you need to rename). connector_version MUST match the server's stored version — fetch via postruntime_get_cpt first if you don't have it. Mismatched versions return 409 pcptpages_version_conflict. The update bumps connector_version by 1.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        connector_version: { type: "integer", description: "Version you read; required for safe concurrent edits" },
        label_singular: { type: "string" },
        label_plural: { type: "string" },
        supports: { type: "array", items: { type: "string" } },
        public: { type: "boolean" },
        has_archive: { type: "boolean" },
        show_in_rest: { type: "boolean" },
        menu_icon: { type: "string" },
        taxonomies: { type: "array", items: { type: "string" } },
        rewrite: { type: "object" },
        hero_layout: { type: "string", enum: ["stacked", "split", "overlay"] },
        hero_image_position: { type: "string", enum: ["left", "right"] },
        hero_image_aspect: { type: "string", enum: ["square", "landscape", "wide"] },
        hero_overlay_focus: { type: "string", enum: ["top", "center", "bottom"], description: "Overlay-only: which part of the featured image survives the band's crop." },
        hero_theme: { type: "string", enum: ["inherit", "light", "dark"], description: "Hero contrast band: 'dark' forces a dark hero on any page mode, 'light' forces light, 'inherit' follows the page (default)." },
        hero_width: { type: "string", enum: ["contained", "full"], description: "'full' bleeds the hero band background viewport-wide; content stays grid-aligned. Default 'contained'." },
        default_icon: { type: "string", description: "Curated icon id (e.g. 'home') OR Iconify code in collection:name form (e.g. 'mdi:home'), or empty string to remove the fallback. See postruntime_list_icons." },
        archive_image_aspect: { type: "string", enum: ["16:9", "4:3", "1:1", "4:5"], description: "Featured-image aspect ratio on theme archive cards. 1:1/4:5 for people, 4:3 for property/product photos, 16:9 (default) for editorial." },
        archive_show_post_date: { type: "boolean", description: "Hide the theme-rendered post create-date on archive cards by setting false. Default true." },
        archive_show_post_author: { type: "boolean", description: "Hide the theme-rendered post author byline on archive cards by setting false. Default true." },
      },
      required: ["slug", "connector_version"],
    },
  },
  {
    name: "postruntime_delete_cpt",
    description:
      "Unregister a CPT and remove its grouping definitions. Per the data-protection policy, post data is preserved by default — re-registering the same slug restores access. Pass purge_data=true ONLY if you're certain you want to permanently delete every post's grouping meta for this CPT.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        purge_data: { type: "boolean", default: false },
      },
      required: ["slug"],
    },
  },

  {
    name: "postruntime_list_groupings",
    description:
      "List all groupings defined for a CPT. Each grouping is the shape that will appear on every post of the CPT — its key, label, default layout variant, default position, default source mode, max_items cap, and per-field requirement flags (heading_required, etc.). Returns connector_version per grouping for safe concurrent updates.",
    inputSchema: {
      type: "object",
      properties: { slug: { type: "string" } },
      required: ["slug"],
    },
  },
  {
    name: "postruntime_define_grouping",
    description:
      "Define a grouping (named cluster of items with shared layout) for a CPT. featured-card variant REQUIRES max_items=1 (the validator enforces this). Source modes: 'manual' (items stored explicitly per post), 'child_posts' (auto-populated from hierarchical children), {type:'taxonomy_match',taxonomy:'<slug>'} (auto-populated from posts sharing a taxonomy term — the taxonomy must already exist), or {type:'meta_match',...} (auto-populated from posts whose meta value equals a value derived from the current post). meta_match has two shapes: MIRROR (default) — {type:'meta_match',meta_key:'_agent_id'} finds same-CPT siblings sharing the current post's value ('more from this agent'); REVERSE LOOKUP — {type:'meta_match',post_type:'listing',field_key:'agent',match_against:'current_title'} pulls posts from a DIFFERENT CPT whose post-field names the current post (an Agent page pulling its Listings, a Neighborhood page pulling area Listings). Prefer field_key (a PRE post-field key — the meta this connector writes via set_post_field_values) over raw meta_key; exactly one of the two is required. match_against: same_key (default) | current_id | current_slug | current_title.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string", description: "CPT slug this grouping attaches to" },
        key: { type: "string", description: "snake_case grouping key, unique within the CPT" },
        label: { type: "string", description: "Human-readable label shown above the items" },
        description: { type: "string" },
        default_variant: {
          type: "string",
          enum: ["compact-grid", "card-grid", "featured-card", "horizontal-row"],
        },
        default_position: {
          type: "string",
          enum: ["above_main", "below_main", "sidebar"],
        },
        default_source: {
          oneOf: [
            { type: "string", enum: ["manual", "child_posts"] },
            {
              type: "object",
              properties: {
                type: { type: "string", enum: ["taxonomy_match"] },
                taxonomy: { type: "string" },
                limit: { type: "integer", minimum: 1, maximum: 100 },
                exclude_self: { type: "boolean" },
              },
              required: ["type", "taxonomy"],
            },
            {
              type: "object",
              properties: {
                type: { type: "string", enum: ["meta_match"] },
                meta_key: {
                  type: "string",
                  maxLength: 64,
                  description: "Raw post-meta key whose value identifies related posts. Lowercase alphanumeric + underscores; may start with a single underscore for private meta. EXACTLY ONE of meta_key/field_key.",
                },
                field_key: {
                  type: "string",
                  maxLength: 64,
                  description: "PRE post-field key (resolved to _pcptpages_field_{key} storage). Prefer this over meta_key — post-field values are the meta this connector writes. EXACTLY ONE of meta_key/field_key.",
                },
                post_type: {
                  type: "string",
                  description: "CPT slug to query. Defaults to the host CPT; set it to pull from a DIFFERENT CPT (cross-CPT reverse lookup).",
                },
                match_against: {
                  type: "string",
                  enum: ["same_key", "current_id", "current_slug", "current_title"],
                  description: "What the TARGET posts' meta is compared to. same_key (default) = current post's own value for the same key (mirror). current_id/current_slug/current_title = the current post itself (reverse lookup — parent pulls children).",
                },
                limit: { type: "integer", minimum: 1, maximum: 100 },
                exclude_self: { type: "boolean" },
              },
              required: ["type"],
            },
          ],
        },
        max_items: { type: "integer", default: 0, description: "0 = no cap. featured-card requires 1." },
        heading_required: { type: "boolean", default: true },
        supporting_text_required: { type: "boolean", default: false },
        link_required: { type: "boolean", default: false },
        icon_or_image_required: { type: "boolean", default: false },
      },
      required: ["slug", "key", "label", "default_variant", "default_position", "default_source"],
    },
  },
  {
    name: "postruntime_get_grouping",
    description:
      "Read one grouping's full definition. Use to fetch connector_version before calling update_grouping.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
      },
      required: ["slug", "key"],
    },
  },
  {
    name: "postruntime_update_grouping",
    description:
      "Update a grouping definition. Same versioning semantics as update_cpt — connector_version must match. Changing default_variant or default_position retroactively affects all posts of the CPT (post-level overrides still take precedence).",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
        connector_version: { type: "integer" },
        label: { type: "string" },
        description: { type: "string" },
        default_variant: { type: "string" },
        default_position: { type: "string" },
        default_source: {},
        max_items: { type: "integer" },
        heading_required: { type: "boolean" },
        supporting_text_required: { type: "boolean" },
        link_required: { type: "boolean" },
        icon_or_image_required: { type: "boolean" },
      },
      required: ["slug", "key", "connector_version"],
    },
  },
  {
    name: "postruntime_delete_grouping",
    description:
      "Remove a grouping definition from a CPT. Post data referencing the deleted key is preserved (silently skipped at render time) unless purge_data=true.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
        purge_data: { type: "boolean", default: false },
      },
      required: ["slug", "key"],
    },
  },

  {
    name: "postruntime_get_post_groupings",
    description:
      "Read a post's grouping data. Returns the post_id, post_type, and the full groupings array — one entry per grouping with grouping_key, position override (or null), variant_override (or null), source, and items array. Use as the basis for set_post_groupings updates (read → modify → put back).",
    inputSchema: {
      type: "object",
      properties: { id: { type: "integer", description: "WP post ID" } },
      required: ["id"],
    },
  },
  {
    name: "postruntime_set_post_groupings",
    description:
      "Replace ALL grouping data for a post atomically. The full groupings array you pass in REPLACES whatever's stored — to update one grouping without touching others, do GET → modify → PUT. Items follow the canonical shape: {image_id, icon_id, heading, supporting_text, link, link_post_id} (image_id and icon_id are mutually exclusive). When linking to internal posts, pass link_post_id alongside the URL — the renderer resolves through get_permalink() at render time, making links domain-portable across staging→production migrations. The data layer creates a backup before the write.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer" },
        groupings: {
          type: "array",
          description: "Array of grouping entries; each has grouping_key + items[]",
        },
      },
      required: ["id", "groupings"],
    },
  },
  {
    name: "postruntime_create_post",
    description:
      "Create a post in a registered CPT, optionally with grouping data populated in one call. post_status defaults to 'draft'. featured_image_id is optional; if it fails to set, the post is still created and the response includes a warnings array. If groupings is provided and validation fails, the post is rolled back (atomic create) — agents never have to clean up half-created posts. Returns post_id, permalink, edit_url.",
    inputSchema: {
      type: "object",
      properties: {
        post_type: { type: "string", description: "Must be a CPT registered through PRE" },
        post_title: { type: "string" },
        post_status: {
          type: "string",
          enum: ["publish", "draft", "pending", "private", "future"],
          default: "draft",
        },
        post_content: { type: "string", description: "HTML body for the WP editor area" },
        post_excerpt: { type: "string" },
        featured_image_id: { type: "integer", description: "Attachment ID for the hero image" },
        groupings: { type: "array", description: "Optional initial grouping data" },
        taxonomies: { type: "object", description: "Optional. Map of taxonomy slug → list of terms to assign, e.g. {\"category\": [\"Downtown\", \"Waterfront District\"]}. Terms may be names, slugs, or term IDs; names/slugs that don't exist yet are created. The taxonomy must be registered for the CPT (declare it in the CPT's `taxonomies` list at registration). Drives taxonomy-based archive facets and taxonomy_match groupings. Non-fatal: bad terms/taxonomies surface in the response `warnings`." },
      },
      required: ["post_type", "post_title"],
    },
  },
  {
    name: "postruntime_update_post",
    description:
      "Partially update a post created through the connector. Accepts any subset of post_title, post_content, post_excerpt, post_status, featured_image_id, groupings — omitted fields are not changed. Sending an empty post_excerpt or post_content clears that field; sending featured_image_id=0 removes the thumbnail. Sending `groupings` fully replaces all groupings on the post (same as set_post_groupings). post_content is sanitized: a leading <![CDATA[...]]> wrapper is stripped automatically and a 'post_content_cdata_stripped' warning surfaces in the response. Use this to fix authored content without losing the post ID (which would break cross-CPT references via link_post_id).",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer", description: "Post ID to update" },
        post_title: { type: "string" },
        post_content: { type: "string", description: "HTML body. NEVER wrap with <![CDATA[...]]> — see critical_rules.post_content_is_html." },
        post_excerpt: { type: "string" },
        post_status: { type: "string", enum: ["publish", "draft", "pending", "private", "future"] },
        featured_image_id: { type: "integer", description: "Attachment ID; pass 0 to remove the existing thumbnail" },
        groupings: { type: "array", description: "Optional. Full replace — same semantics as set_post_groupings." },
        taxonomies: { type: "object", description: "Optional. Map of taxonomy slug → list of terms (names, slugs, or IDs). REPLACE per taxonomy supplied — omitted taxonomies are untouched; an empty list clears that taxonomy's terms. Terms that don't exist are created. e.g. {\"category\": [\"Downtown\"]}. Non-fatal: issues surface in `warnings`." },
      },
      required: ["id"],
    },
  },
  {
    name: "postruntime_preview_post",
    description:
      "Render a post and return the article HTML for visual verification. Always renders fresh (bypasses the render cache) so you see the current state of stored data. Returns html (article body only — no <head>, no theme chrome), css_url (the plugin's stylesheet for inlining if needed), and permalink. For full-page render, fetch the permalink directly.",
    inputSchema: {
      type: "object",
      properties: { id: { type: "integer" } },
      required: ["id"],
    },
  },

  // -------------------------------------------------------------------------
  // Post fields (v1.1) — scalar values with typed display that decorate the
  // single-post hero AND cards (archive + AISB PostGrid sections). Closed
  // enums for display_type / position / color_intent / date_format /
  // supported_currencies live in preflight.post_field_enums. Authoring
  // guidance lives in preflight.critical_rules (post_fields_vs_groupings,
  // post_field_positions, post_field_display_types, post_field_value_shape,
  // post_field_count_cap, post_field_visibility_model). 12-field hard cap
  // per CPT, soft warning at 8.
  // -------------------------------------------------------------------------

  {
    name: "postruntime_list_post_fields",
    description:
      "List every post field defined on a CPT, in render order. Each entry surfaces key, label, display_type, card_position, single_position, plus the conditional fields meaningful for that display_type (color_intent + options for badge / multi_badge; icon for meta_pair; date_format / date_format_string for date; currency_code + value_suffix for currency; max + unit_label for rating / progress / number_with_label), connector_version (for optimistic concurrency on update), and timestamps. Use to discover what's defined before adding more, or to read the current order before reordering.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string", description: "CPT slug" },
      },
      required: ["slug"],
    },
  },
  {
    name: "postruntime_define_post_field",
    description:
      "Define a new post field on a CPT. Up to 12 fields per CPT enforced server-side (HARD_FIELD_COUNT_LIMIT); soft warning at 8. After definition, populate per-post values via postruntime_set_post_field_values. See preflight.field_name_hints.post_field_definition for the accepted-keys list and preflight.post_field_enums for closed enums. Examples by display_type — currency: { key:'price', label:'Price', display_type:'currency', card_position:'headline', single_position:'headline', currency_code:'USD', value_suffix:'+' }. badge: { key:'status', label:'Status', display_type:'badge', card_position:'image_overlay', single_position:'image_overlay', options:{ for_sale:{ label:'For sale', color_intent:'primary' }, sold:{ label:'Sold', color_intent:'neutral' } } }. meta_pair: { key:'beds', label:'Beds', display_type:'meta_pair', card_position:'meta_strip', single_position:'meta_strip', icon:'mdi:bed-outline' }. rating: { key:'reviews', label:'Reviews', display_type:'rating', card_position:'meta_strip', single_position:'meta_strip', max:5 }. progress: { key:'capacity', label:'Capacity', display_type:'progress', card_position:'meta_strip', single_position:'meta_strip' }. date: { key:'event_date', label:'Event date', display_type:'date', card_position:'headline', single_position:'headline', date_format:'custom', date_format_string:'F j · g:i A' }. multi_badge: { key:'topics', label:'Topics', display_type:'multi_badge', card_position:'footer_meta', single_position:'footer_meta', color_intent:'neutral' }. number_with_label: { key:'duration', label:'Duration', display_type:'number_with_label', card_position:'meta_strip', single_position:'meta_strip', unit_label:'min' }. Use card_position:'hidden' or single_position:'hidden' to opt out of one context. EVENTS ARCHIVE SETUP: to make a CPT event-shaped, define two date fields with semantic_role event_start and event_end — e.g. { key:'starts', label:'Starts', display_type:'date', semantic_role:'event_start', all_day:false, card_position:'headline', single_position:'meta_strip', date_format:'custom', date_format_string:'M j, Y · g:i A' } and the same for 'ends' with semantic_role:'event_end'; optionally add a badge event_status (options scheduled/cancelled/postponed), a text event_location, a currency event_offers, and a badge event_attendance_mode (in_person/online/mixed). Then build a Promptless WP PostGrid section over that CPT with content.event_status:'upcoming' (or happening/past) and content.event_sort:'soonest' to render the filtered, sorted archive; single pages auto-emit Schema.org Event JSON-LD.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string", description: "CPT slug the field is attached to" },
        key: { type: "string", description: "Field key (snake_case, unique per CPT)" },
        label: { type: "string", description: "Author-facing label shown in the meta box" },
        description: { type: "string" },
        display_type: {
          type: "string",
          enum: ["currency", "number_with_label", "badge", "meta_pair", "date", "text", "rating", "progress", "multi_badge"],
        },
        card_position: {
          type: "string",
          enum: ["image_overlay", "headline", "subtitle", "meta_strip", "footer_meta", "hidden"],
        },
        single_position: {
          type: "string",
          enum: ["image_overlay", "headline", "subtitle", "meta_strip", "footer_meta", "hidden"],
        },
        color_intent: {
          type: "string",
          enum: ["primary", "secondary", "neutral"],
          description: "For badge / multi_badge. Per-option color_intent in options[] takes precedence.",
        },
        icon: { type: "string", description: "For meta_pair — curated icon id or Iconify code (e.g. mdi:bed-outline)" },
        options: { description: "For badge / multi_badge — object mapping option keys to { label, color_intent }. May be sent as a JSON string; the bridge parses it." },
        required: { type: "boolean" },
        date_format: { type: "string", enum: ["absolute", "relative", "custom"], description: "For date" },
        date_format_string: { type: "string", description: "For date when date_format is custom (PHP date format, e.g. 'F j · g:i A')" },
        currency_code: { type: "string", description: "For currency — ISO 4217 from preflight.post_field_enums.supported_currencies" },
        value_suffix: { type: "string", description: "For currency — appended after the formatted amount ('+', '/mo', '/night', etc.)" },
        max: { type: "number", description: "For rating (defaults to 5), progress, or number_with_label" },
        unit_label: { type: "string", description: "For number_with_label — unit suffix (sqft, mi, hrs, min, etc.)" },
        number_grouping: { type: "boolean", description: "For number_with_label — thousands separators. Default true (3,200 sqft). Set false for identifier-like numbers that must NOT be grouped: year built (2019, not 2,019), model year, unit/lot numbers, IDs." },
        semantic_role: {
          type: "string",
          enum: ["event_start", "event_end", "event_status", "event_location", "event_offers", "event_attendance_mode"],
          description: "EVENTS: tags this field for event archive date-filtering + Schema.org Event markup. Pairs with a specific display_type — event_start/event_end require display_type:date; event_status/event_attendance_mode require badge; event_location requires text; event_offers requires currency. Each role may be mapped only once per CPT.",
        },
        all_day: { type: "boolean", description: "For date fields: true = all-day / multi-day (date-only, no time). Affects Event schema output and date-status filtering." },
        event_timezone: { type: "string", description: "For date fields: optional IANA timezone (e.g. America/New_York). Empty = site timezone." },
        filterable: { type: "boolean", description: "FILTERS: true = visitors can filter the archive by this field. The filter widget is auto-chosen from display_type (range slider for currency/number/progress, stepper for rating, single-select for badge, checkboxes for multi_badge, upcoming/past toggle for event dates, search box for text). meta_pair cannot be filterable." },
        sortable: { type: "boolean", description: "FILTERS: true = offer this field as an archive sort option (e.g. price low-to-high, rating high-to-low). meta_pair cannot be sortable." },
        filter_widget: { type: "string", enum: ["range", "stepper", "pill_select", "checkbox_group", "date_toggle", "date_range", "text_search"], description: "FILTERS (advanced, optional): override the auto-chosen widget. Must be compatible with display_type — number_with_label/rating accept range or stepper; badge accepts pill_select or checkbox_group; date accepts date_toggle or date_range. Omit to use the display_type default." },
      },
      required: ["slug", "key", "label", "display_type", "card_position", "single_position"],
    },
  },
  {
    name: "postruntime_get_post_field",
    description: "Read a single post-field definition by CPT slug + field key. Returns the full shape including connector_version for use in subsequent update calls.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
      },
      required: ["slug", "key"],
    },
  },
  {
    name: "postruntime_update_post_field",
    description: "Update an existing post-field definition. URL key is authoritative — body `key` is ignored if sent. Requires connector_version from a prior read for optimistic concurrency; mismatch returns pcptpages_stale_connector_version (HTTP 409). All other fields are partial-update: only keys present in the call are touched.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
        connector_version: { type: "integer", description: "From the field's last read — required for optimistic concurrency" },
        label: { type: "string" },
        description: { type: "string" },
        display_type: {
          type: "string",
          enum: ["currency", "number_with_label", "badge", "meta_pair", "date", "text", "rating", "progress", "multi_badge"],
        },
        card_position: {
          type: "string",
          enum: ["image_overlay", "headline", "subtitle", "meta_strip", "footer_meta", "hidden"],
        },
        single_position: {
          type: "string",
          enum: ["image_overlay", "headline", "subtitle", "meta_strip", "footer_meta", "hidden"],
        },
        color_intent: { type: "string", enum: ["primary", "secondary", "neutral"] },
        icon: { type: "string" },
        options: { description: "May be sent as a JSON string; the bridge parses it." },
        required: { type: "boolean" },
        date_format: { type: "string", enum: ["absolute", "relative", "custom"] },
        date_format_string: { type: "string" },
        currency_code: { type: "string" },
        value_suffix: { type: "string" },
        max: { type: "number" },
        unit_label: { type: "string" },
        number_grouping: { type: "boolean", description: "For number_with_label — thousands separators (default true). False = ungrouped (years/IDs)." },
        semantic_role: {
          type: "string",
          enum: ["event_start", "event_end", "event_status", "event_location", "event_offers", "event_attendance_mode"],
          description: "EVENTS role (see define_post_field). One role per CPT; pairs with its required display_type.",
        },
        all_day: { type: "boolean", description: "For date fields: all-day / multi-day (date-only)." },
        event_timezone: { type: "string", description: "For date fields: optional IANA timezone; empty = site timezone." },
        filterable: { type: "boolean", description: "FILTERS: true = visitors can filter the archive by this field (widget auto-chosen from display_type). meta_pair cannot be filterable." },
        sortable: { type: "boolean", description: "FILTERS: true = offer this field as an archive sort option. meta_pair cannot be sortable." },
        filter_widget: { type: "string", enum: ["range", "stepper", "pill_select", "checkbox_group", "date_toggle", "date_range", "text_search"], description: "FILTERS (advanced, optional): override the auto-chosen widget; must be compatible with display_type. Omit to use the default." },
      },
      required: ["slug", "key", "connector_version"],
    },
  },
  {
    name: "postruntime_delete_post_field",
    description: "Remove a post-field definition from a CPT. Per-post stored values are intentionally NOT purged — they're orphaned until the post is next saved or until a future explicit cleanup endpoint lands. Matches the grouping delete behavior so a misclick is recoverable by re-defining the same field key.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        key: { type: "string" },
      },
      required: ["slug", "key"],
    },
  },
  {
    name: "postruntime_reorder_post_fields",
    description: "Bulk reorder all post fields on a CPT. ordered_keys MUST contain exactly the set of currently-defined keys — no additions, no removals, no duplicates. Validation runs server-side and returns pcptpages_reorder_key_mismatch (HTTP 422) if the set diverges. Render order matters: fields share positions (e.g. multiple meta_pair fields in meta_strip) and render in this order within each position.",
    inputSchema: {
      type: "object",
      properties: {
        slug: { type: "string" },
        ordered_keys: {
          type: "array",
          items: { type: "string" },
          description: "Full set of currently-defined field keys in the desired order",
        },
      },
      required: ["slug", "ordered_keys"],
    },
  },
  {
    name: "postruntime_get_post_field_values",
    description: "Read all post-field values for a single post. Returns field_values keyed by field key. Composite display types surface as objects — rating: { value: 4.8, count: 1243 }, progress: { value: 320000, goal: 500000 } — so consumers can read both halves in one call. Storage and admin entries always hold raw values; only webhook payloads are subject to option-label resolution.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer", description: "Post ID (must belong to a PRE-registered CPT)" },
      },
      required: ["id"],
    },
  },
  {
    name: "postruntime_set_post_field_values",
    description: "Bulk write post-field values on a single post. Partial-update semantics: fields not present in `values` are unchanged; to clear a field, send explicit null or empty string. Composite types accept either the canonical shape ({ rating: { value: 4.8, count: 1243 } }) OR a bare scalar for the primary value with secondary defaulting to null ({ rating: 4.8 }). Per-display-type validation runs server-side — see preflight.critical_rules.post_field_value_shape for the rules. Unknown field keys are silently dropped with a warning so existing data isn't corrupted by a typo. Returns the resulting full value set so the caller can confirm what landed.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer", description: "Post ID" },
        values: {
          description: "Object mapping field keys to values. May be sent as a JSON string; the bridge parses it. Examples: { price: 1485000, status: 'for_sale', beds: 3, baths: 2, rating: { value: 4.8, count: 89 }, topics: ['React', 'Performance', 'Accessibility'] }",
        },
      },
      required: ["id", "values"],
    },
  },
  {
    name: "postruntime_get_post_field_visibility",
    description: "Read per-post visibility overrides for a single post. Returns visibility keyed by field key, with card_hidden / single_hidden booleans. Empty object means no overrides — the CPT-level card_position / single_position settings apply unmodified.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer", description: "Post ID" },
      },
      required: ["id"],
    },
  },
  {
    name: "postruntime_set_post_field_visibility",
    description: "Write per-post visibility overrides for a single post. Full-replace semantics — send the entire desired visibility map; missing field keys default to unhidden. To clear ALL overrides on a post, send an empty object. Layers on top of the CPT-level position settings: a field defined with card_position:'headline' can be hidden on a specific post's card via { price: { card_hidden: true } } without redefining the field. Positions themselves can't be overridden per post; only visibility.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "integer", description: "Post ID" },
        visibility: {
          description: "Object mapping field keys to { card_hidden: bool, single_hidden: bool }. May be sent as a JSON string; the bridge parses it.",
        },
      },
      required: ["id", "visibility"],
    },
  },
];

// ---------------------------------------------------------------------------
// HTTP client.
// ---------------------------------------------------------------------------

function getConfig() {
  const siteUrl = process.env.POST_RUNTIME_SITE_URL;
  const username = process.env.POST_RUNTIME_USERNAME;
  const appPassword = process.env.POST_RUNTIME_APP_PASSWORD;

  if (!siteUrl) {
    throw new Error(
      "POST_RUNTIME_SITE_URL is not set. Set it to your WordPress site URL (e.g. https://example.com)."
    );
  }
  if (!username || !appPassword) {
    throw new Error(
      "POST_RUNTIME_USERNAME and POST_RUNTIME_APP_PASSWORD must both be set. Generate an Application Password through Post Runtime → Connector in wp-admin."
    );
  }

  // WordPress Application Passwords display with spaces for readability,
  // but the actual credential is the space-stripped form. Strip before
  // encoding.
  const cleanPassword = appPassword.replace(/\s+/g, "");
  const auth = Buffer.from(`${username}:${cleanPassword}`).toString("base64");

  return { siteUrl: siteUrl.replace(/\/+$/, ""), auth };
}

/**
 * Build a request to the connector's REST base.
 *
 * Notable headers — all inherited from the Promptless / FRE connector's
 * hard-won experience with shared hosts:
 *   - User-Agent starts with "WordPress/" so ModSecurity WAFs don't
 *     block the request as a suspicious Node.js client.
 *   - Connection: close prevents chunked transfer encoding on the
 *     request body, which some WAFs reject for POSTs.
 *   - Content-Length is set explicitly on requests with a body for the
 *     same reason — let Node compute it, but set the header rather
 *     than relying on chunked.
 */
function makeRequest(method, path, body = null) {
  return new Promise((resolve, reject) => {
    const config = getConfig();
    const url = new URL(
      `${config.siteUrl}/wp-json/post-runtime/v1/connector${path}`
    );

    const isHttps = url.protocol === "https:";
    const transport = isHttps ? https : http;

    const bodyStr = body ? JSON.stringify(body) : null;

    const headers = {
      Authorization: `Basic ${config.auth}`,
      "Content-Type": "application/json",
      Accept: "application/json",
      "User-Agent":
        "WordPress/PostRuntimeEngine-Connector/1.0 (compatible; Cowork MCP)",
      Connection: "close",
    };

    if (bodyStr) {
      headers["Content-Length"] = Buffer.byteLength(bodyStr).toString();
    }

    const options = {
      hostname: url.hostname,
      port: url.port || (isHttps ? 443 : 80),
      path: url.pathname + url.search,
      method: method,
      headers: headers,
    };

    const req = transport.request(options, (res) => {
      let data = "";
      res.on("data", (chunk) => (data += chunk));
      res.on("end", () => {
        // An empty body on a 2xx (e.g. a 204 No Content, or a body
        // stripped by an upstream proxy) is a success, not a parse
        // failure. Resolve it before attempting JSON.parse("") — which
        // would throw and surface a misleading { error: true }.
        if (data.trim() === "") {
          if (res.statusCode >= 400) {
            resolve({ error: true, status: res.statusCode, message: "" });
          } else {
            resolve({ success: true, status: res.statusCode });
          }
          return;
        }
        try {
          const json = JSON.parse(data);
          if (res.statusCode >= 400) {
            resolve({
              error: true,
              status: res.statusCode,
              ...(typeof json === "object" ? json : { message: data }),
            });
          } else {
            resolve(json);
          }
        } catch {
          resolve({
            error: true,
            status: res.statusCode,
            message: data.substring(0, 500),
          });
        }
      });
    });

    req.on("error", (e) => {
      reject(
        new Error(
          `Connection failed: ${e.message}. Is the site URL correct? (${config.siteUrl})`
        )
      );
    });

    req.setTimeout(30000, () => {
      req.destroy();
      reject(new Error("Request timed out after 30 seconds"));
    });

    if (bodyStr) {
      req.write(bodyStr);
    }
    req.end();
  });
}

// ---------------------------------------------------------------------------
// MCP framework workaround: JSON pre-parse for object-typed params.
//
// Several tool params declare a JSON `oneOf` schema that mixes string and
// object types (e.g. `default_source` — either "manual" / "child_posts" or
// {type:"taxonomy_match",...} / {type:"meta_match",...}). The MCP framework
// surfaces these unions as the looser "any" type (`{}`) when forwarding the
// tool definition to the model, and string values arrive verbatim — even
// when the model passes JSON that's intended to be an object.
//
// Without this normalization, calling postruntime_define_grouping with
// `default_source` set to {"type":"meta_match","meta_key":"_agent_id"}
// arrives at PRE's REST validator as the literal string
// '{"type":"meta_match","meta_key":"_agent_id"}', which is rejected with
// pcptpages_invalid_source_string. Pre-parsing here makes the MCP path symmetric
// with the direct REST path so AI agents can use both interchangeably.
//
// Safe to call on any value: returns the original value when not a JSON
// string, returns the parsed object when it is, and never throws.
function maybeParseJsonObjectString(value) {
  if (typeof value !== "string") return value;
  const trimmed = value.trim();
  if (!trimmed.startsWith("{") && !trimmed.startsWith("[")) return value;
  try {
    return JSON.parse(trimmed);
  } catch (e) {
    // Not valid JSON — leave the validator to reject it with a useful
    // error message rather than swallowing the data here.
    return value;
  }
}

// Walk a groupings array (per-post entries) and pre-parse the `source` field
// on each entry — same MCP framework gap as above, but applied to nested
// items. Items array values stay as-is; only `source` is touched.
function normalizeGroupingsArray(groupings) {
  if (!Array.isArray(groupings)) return groupings;
  return groupings.map((entry) => {
    if (!entry || typeof entry !== "object") return entry;
    if ("source" in entry) {
      return { ...entry, source: maybeParseJsonObjectString(entry.source) };
    }
    return entry;
  });
}

// ---------------------------------------------------------------------------
// Tool → REST route mapping.
// ---------------------------------------------------------------------------

async function handleTool(name, args) {
  switch (name) {
    case "postruntime_preflight":
      return await makeRequest("GET", "/preflight");

    case "postruntime_list_icons":
      return await makeRequest("GET", "/icons");

    case "postruntime_list_variants":
      return await makeRequest("GET", "/variants");

    case "postruntime_list_positions":
      return await makeRequest("GET", "/positions");

    case "postruntime_list_cpts":
      return await makeRequest("GET", "/cpts");

    case "postruntime_register_cpt": {
      const payload = { slug: args.slug };
      [
        "label_singular",
        "label_plural",
        "supports",
        "public",
        "has_archive",
        "show_in_rest",
        "menu_icon",
        "taxonomies",
        "rewrite",
        "capability_type",
        "hero_layout",
        "hero_image_position",
        "hero_image_aspect",
        "hero_overlay_focus",
        "hero_theme",
        "hero_width",
        "default_icon",
        "archive_show_post_date",
        "archive_show_post_author",
        "archive_image_aspect",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      return await makeRequest("POST", "/cpts", payload);
    }

    case "postruntime_get_cpt":
      return await makeRequest(
        "GET",
        `/cpts/${encodeURIComponent(args.slug)}`
      );

    case "postruntime_update_cpt": {
      const payload = { connector_version: args.connector_version };
      [
        "label_singular",
        "label_plural",
        "supports",
        "public",
        "has_archive",
        "show_in_rest",
        "menu_icon",
        "taxonomies",
        "rewrite",
        "hero_layout",
        "hero_image_position",
        "hero_image_aspect",
        "hero_overlay_focus",
        "hero_theme",
        "hero_width",
        "default_icon",
        "archive_show_post_date",
        "archive_show_post_author",
        "archive_image_aspect",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      return await makeRequest(
        "PUT",
        `/cpts/${encodeURIComponent(args.slug)}`,
        payload
      );
    }

    case "postruntime_delete_cpt": {
      const qs = args.purge_data ? "?purge_data=1" : "";
      return await makeRequest(
        "DELETE",
        `/cpts/${encodeURIComponent(args.slug)}${qs}`
      );
    }

    case "postruntime_list_groupings":
      return await makeRequest(
        "GET",
        `/cpts/${encodeURIComponent(args.slug)}/groupings`
      );

    case "postruntime_define_grouping": {
      const payload = {};
      [
        "key",
        "label",
        "description",
        "default_variant",
        "default_position",
        "default_source",
        "max_items",
        "heading_required",
        "supporting_text_required",
        "link_required",
        "icon_or_image_required",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      // MCP framework workaround — see maybeParseJsonObjectString header.
      payload.default_source = maybeParseJsonObjectString(payload.default_source);
      return await makeRequest(
        "POST",
        `/cpts/${encodeURIComponent(args.slug)}/groupings`,
        payload
      );
    }

    case "postruntime_get_grouping":
      return await makeRequest(
        "GET",
        `/cpts/${encodeURIComponent(args.slug)}/groupings/${encodeURIComponent(args.key)}`
      );

    case "postruntime_update_grouping": {
      const payload = { connector_version: args.connector_version };
      [
        "label",
        "description",
        "default_variant",
        "default_position",
        "default_source",
        "max_items",
        "heading_required",
        "supporting_text_required",
        "link_required",
        "icon_or_image_required",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      // MCP framework workaround — see maybeParseJsonObjectString header.
      if ("default_source" in payload) {
        payload.default_source = maybeParseJsonObjectString(payload.default_source);
      }
      return await makeRequest(
        "PUT",
        `/cpts/${encodeURIComponent(args.slug)}/groupings/${encodeURIComponent(args.key)}`,
        payload
      );
    }

    case "postruntime_delete_grouping": {
      const qs = args.purge_data ? "?purge_data=1" : "";
      return await makeRequest(
        "DELETE",
        `/cpts/${encodeURIComponent(args.slug)}/groupings/${encodeURIComponent(args.key)}${qs}`
      );
    }

    case "postruntime_get_post_groupings":
      return await makeRequest(
        "GET",
        `/posts/${encodeURIComponent(args.id)}/groupings`
      );

    case "postruntime_set_post_groupings":
      return await makeRequest(
        "PUT",
        `/posts/${encodeURIComponent(args.id)}/groupings`,
        // Pre-parse any per-entry `source` field that arrived as a JSON string.
        { groupings: normalizeGroupingsArray(args.groupings) }
      );

    case "postruntime_create_post": {
      const payload = {};
      [
        "post_type",
        "post_title",
        "post_status",
        "post_content",
        "post_excerpt",
        "featured_image_id",
        "groupings",
        "taxonomies",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      // MCP framework workaround — see normalizeGroupingsArray header.
      if ("groupings" in payload) {
        payload.groupings = normalizeGroupingsArray(payload.groupings);
      }
      // taxonomies may arrive as a JSON string when the model emits a nested
      // object; pre-parse so the REST handler sees a real map.
      if ("taxonomies" in payload) {
        payload.taxonomies = maybeParseJsonObjectString(payload.taxonomies);
      }
      return await makeRequest("POST", "/posts", payload);
    }

    case "postruntime_update_post": {
      const payload = {};
      [
        "post_title",
        "post_content",
        "post_excerpt",
        "post_status",
        "featured_image_id",
        "groupings",
        "taxonomies",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      // MCP framework workaround — see normalizeGroupingsArray header.
      if ("groupings" in payload) {
        payload.groupings = normalizeGroupingsArray(payload.groupings);
      }
      if ("taxonomies" in payload) {
        payload.taxonomies = maybeParseJsonObjectString(payload.taxonomies);
      }
      return await makeRequest(
        "PUT",
        `/posts/${encodeURIComponent(args.id)}`,
        payload
      );
    }

    case "postruntime_preview_post":
      return await makeRequest(
        "GET",
        `/posts/${encodeURIComponent(args.id)}/preview`
      );

    // -----------------------------------------------------------------
    // Post fields (v1.1)
    // -----------------------------------------------------------------

    case "postruntime_list_post_fields":
      return await makeRequest(
        "GET",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields`
      );

    case "postruntime_define_post_field": {
      const payload = {};
      [
        "key",
        "label",
        "description",
        "display_type",
        "card_position",
        "single_position",
        "color_intent",
        "icon",
        "options",
        "required",
        "date_format",
        "date_format_string",
        "currency_code",
        "value_suffix",
        "max",
        "unit_label",
        "number_grouping",
        "semantic_role",
        "all_day",
        "event_timezone",
        "filterable",
        "sortable",
        "filter_widget",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      // MCP framework workaround — options arrives as a JSON string when the
      // model emits it as a nested object, since the schema declares it as a
      // looser type. Pre-parse so the REST validator sees the canonical shape.
      if ("options" in payload) {
        payload.options = maybeParseJsonObjectString(payload.options);
      }
      return await makeRequest(
        "POST",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields`,
        payload
      );
    }

    case "postruntime_get_post_field":
      return await makeRequest(
        "GET",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields/${encodeURIComponent(args.key)}`
      );

    case "postruntime_update_post_field": {
      const payload = { connector_version: args.connector_version };
      [
        "label",
        "description",
        "display_type",
        "card_position",
        "single_position",
        "color_intent",
        "icon",
        "options",
        "required",
        "date_format",
        "date_format_string",
        "currency_code",
        "value_suffix",
        "max",
        "unit_label",
        "number_grouping",
        "semantic_role",
        "all_day",
        "event_timezone",
        "filterable",
        "sortable",
        "filter_widget",
      ].forEach((k) => {
        if (args[k] !== undefined) payload[k] = args[k];
      });
      if ("options" in payload) {
        payload.options = maybeParseJsonObjectString(payload.options);
      }
      return await makeRequest(
        "PUT",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields/${encodeURIComponent(args.key)}`,
        payload
      );
    }

    case "postruntime_delete_post_field":
      return await makeRequest(
        "DELETE",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields/${encodeURIComponent(args.key)}`
      );

    case "postruntime_reorder_post_fields":
      return await makeRequest(
        "POST",
        `/cpts/${encodeURIComponent(args.slug)}/post-fields/reorder`,
        { ordered_keys: args.ordered_keys }
      );

    case "postruntime_get_post_field_values":
      return await makeRequest(
        "GET",
        `/posts/${encodeURIComponent(args.id)}/field-values`
      );

    case "postruntime_set_post_field_values": {
      // values may arrive as a JSON string when emitted as a nested object;
      // the bridge canonicalizes it.
      const values = maybeParseJsonObjectString(args.values);
      return await makeRequest(
        "PUT",
        `/posts/${encodeURIComponent(args.id)}/field-values`,
        { values }
      );
    }

    case "postruntime_get_post_field_visibility":
      return await makeRequest(
        "GET",
        `/posts/${encodeURIComponent(args.id)}/field-visibility`
      );

    case "postruntime_set_post_field_visibility": {
      const visibility = maybeParseJsonObjectString(args.visibility);
      return await makeRequest(
        "PUT",
        `/posts/${encodeURIComponent(args.id)}/field-visibility`,
        { visibility }
      );
    }

    default:
      throw new Error(`Unknown tool: ${name}`);
  }
}

// ---------------------------------------------------------------------------
// MCP stdio transport — auto-detects Content-Length or newline-delimited
// framing.
//
// Ported verbatim from the FRE / Promptless connector. Claude Desktop
// historically shipped versions that used different framing modes; auto-
// detection is necessary for cross-version compatibility. Don't simplify
// without also updating the parent connectors.
// ---------------------------------------------------------------------------

let buffer = Buffer.alloc(0);
let detectedMode = null; // "content-length" or "newline"

process.stdin.on("data", (chunk) => {
  buffer = Buffer.concat([buffer, chunk]);
  processBuffer();
});

function processBuffer() {
  if (detectedMode === null && buffer.length > 0) {
    const peek = buffer.toString("utf8", 0, Math.min(buffer.length, 20));
    if (peek.startsWith("Content-Length:")) {
      detectedMode = "content-length";
    } else {
      detectedMode = "newline";
    }
  }

  if (detectedMode === "content-length") {
    processContentLength();
  } else if (detectedMode === "newline") {
    processNewline();
  }
}

let contentLength = -1;

function processContentLength() {
  while (true) {
    if (contentLength === -1) {
      const headerEnd = buffer.indexOf("\r\n\r\n");
      if (headerEnd === -1) return;

      const header = buffer.slice(0, headerEnd).toString("utf8");
      const match = header.match(/Content-Length:\s*(\d+)/i);
      if (!match) {
        buffer = buffer.slice(headerEnd + 4);
        continue;
      }

      contentLength = parseInt(match[1], 10);
      buffer = buffer.slice(headerEnd + 4);
    }

    if (buffer.length < contentLength) return;

    const messageBytes = buffer.slice(0, contentLength);
    buffer = buffer.slice(contentLength);
    contentLength = -1;

    parseAndHandle(messageBytes.toString("utf8"));
  }
}

function processNewline() {
  const str = buffer.toString("utf8");
  let newlineIndex;
  while ((newlineIndex = str.indexOf("\n")) !== -1) {
    const line = str.slice(0, newlineIndex).trim();
    buffer = Buffer.from(str.slice(newlineIndex + 1), "utf8");

    if (line.length === 0) {
      return processNewline();
    }
    parseAndHandle(line);
    return processNewline();
  }
}

function parseAndHandle(text) {
  try {
    const message = JSON.parse(text);
    handleMessage(message);
  } catch (e) {
    sendError(null, -32700, "Parse error: " + e.message);
  }
}

function send(obj) {
  const body = JSON.stringify(obj);
  if (detectedMode === "content-length") {
    const header = `Content-Length: ${Buffer.byteLength(body)}\r\n\r\n`;
    process.stdout.write(header + body);
  } else {
    process.stdout.write(body + "\n");
  }
}

function sendResult(id, result) {
  send({ jsonrpc: "2.0", id, result });
}

function sendError(id, code, message) {
  send({ jsonrpc: "2.0", id, error: { code, message } });
}

async function handleMessage(msg) {
  const { id, method, params } = msg;

  switch (method) {
    case "initialize": {
      // Echo the client's protocol version back verbatim. Claude Desktop
      // expects this; hardcoding a different version causes connection
      // negotiation to fail silently.
      const clientVersion =
        (params && params.protocolVersion) || "2024-11-05";
      sendResult(id, {
        protocolVersion: clientVersion,
        capabilities: { tools: {} },
        serverInfo: {
          name: "post-runtime-engine-connector",
          version: "1.0.0",
        },
      });
      break;
    }

    case "notifications/initialized":
      // No response required.
      break;

    case "tools/list":
      sendResult(id, { tools: TOOLS });
      break;

    case "tools/call": {
      const toolName = params?.name;
      const toolArgs = params?.arguments || {};

      try {
        const result = await handleTool(toolName, toolArgs);
        sendResult(id, {
          content: [
            { type: "text", text: JSON.stringify(result, null, 2) },
          ],
        });
      } catch (e) {
        sendResult(id, {
          content: [
            { type: "text", text: JSON.stringify({ error: true, message: e.message }) },
          ],
          isError: true,
        });
      }
      break;
    }

    default:
      if (id !== undefined) {
        sendError(id, -32601, `Method not found: ${method}`);
      }
  }
}

process.on("SIGINT", () => process.exit(0));
process.on("SIGTERM", () => process.exit(0));
process.stdin.on("end", () => process.exit(0));
