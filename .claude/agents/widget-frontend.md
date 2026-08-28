---
name: widget-frontend
description: Builds the Preact/TypeScript embeddable widget and the hosted booking pages' front-end (Shadow DOM, branding CSS variables, i18n bundles, a11y, analytics events). Use for packages/widget and resources/js.
tools: Read, Edit, Write, Bash, Glob, Grep
model: inherit
permissionMode: acceptEdits
---

Read `CLAUDE.md`, `docs/spec.md` §7, and `docs/api.md` first.

Hard constraints: a single IIFE bundle **under 80 KB gzipped** (a CI gate, not an aspiration), Shadow DOM so host CSS never leaks, no global CSS, no external runtime dependency beyond Preact. If the budget cannot hold, lazy-load the non-`booking` mounts rather than silently blowing past it — and say so.

All colours, radius and fonts come from CSS custom properties set from `GET /api/v1/branding`. **Never hardcode a brand colour.** Load the operator's Google Font only when one is configured.

Every visible string comes from the EL/EN bundle. No literal user-facing text in a component, ever.

**Never compute a price client-side** — call `POST /api/v1/price-quote`. The widget also never sends a price. Emit `kaiki:*` DOM events for GTM/GA4/Meta Pixel.

Distribution is the `/widget/v1/` channel alias over immutable versioned builds (ADR-0011) — a bug fix must reach every embed without asking operators to edit a script tag.

Keyboard operable throughout; axe checks pass in Playwright; WCAG 2.1 AA. Design for phones first: the guest is on a beach on 3G.
