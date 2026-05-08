# Post Runtime Engine

**Status:** Planning only. No runtime code yet.

A WordPress plugin that renders custom-post-type single pages with structured data display, while inheriting brand styling from Promptless WP's Global Settings. Companion plugin to Promptless WP (page builder) and Form Runtime Engine (form renderer).

## What it will do

- Register custom post types and a small set of structured "grouping" fields directly (no dependency on ACF, MetaBox, or other field plugins)
- Render single-post pages using a constrained primitive: ordered groupings of `{image-or-icon, heading, supporting text, optional link}` items
- Use the default WordPress editor for the main content area, with groupings positioned above main content, below main content, or in a sidebar
- Inherit the `--aisb-*` CSS design tokens from Promptless WP when active; degrade gracefully to documented defaults when Promptless is not installed
- Expose a connector REST API + MCP tools so Claude Cowork can register CPTs, define groupings, populate per-post values, and pick layout variants

## What it will not do

- Replace Promptless WP for landing-page authoring
- Render archive pages or filter UIs (deferred)
- Provide a binding layer over Promptless's existing section catalog (an earlier proposal that was set aside in favor of this constrained-primitive approach)
- Compete with ACF or MetaBox as a general-purpose custom-fields plugin

## Documentation

See [`CLAUDE.md`](./CLAUDE.md) for the engineering / AI front door, then the docs under [`docs/`](./docs/).

## Naming note

The folder name and slug `post-runtime-engine` are provisional. Final naming may change before any implementation begins. The directional cues are: parallel structure to `form-runtime-engine`, single English word + "runtime engine," broad enough to scale beyond the initial use cases.
