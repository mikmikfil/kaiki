# ADR-0011: Widget distribution, versioning and cache strategy

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Widget), §7, §8, §14 M3.19 of `docs/BRIEF.md`; requirements WGT-1 … WGT-6, NFR-2

## Context
The widget is a single IIFE loaded from a platform URL by a `<script src>` tag that operators paste into their own site or that the WordPress plugin enqueues. Once that snippet is on hundreds of third-party sites it cannot be changed, so the URL shape is effectively permanent. The tension is between shipping fixes to everyone instantly (always-latest) and never breaking a live operator site with an unreviewed deploy (pinned). This also determines the caching headers, the CDN configuration in front of Cloudflare, and how the WordPress plugin decides which URL to enqueue. Blocks **M3** and constrains **M4**.

## Options

### Option A — Major-version channel URL, short cache, immutable build URLs underneath
Public snippet points at `https://cdn.{platform-domain}/widget/v1/kaiki-widget.js`. That file is served with `Cache-Control: public, max-age=300, stale-while-revalidate=86400` and is a thin, versioned redirect/alias to an immutable build artefact `.../widget/builds/1.7.3/kaiki-widget.js` served with `max-age=31536000, immutable`. Breaking changes go to `/v2/`, and `/v1/` keeps working.
Pros
- Fixes and security patches reach every operator within five minutes without anyone editing their site.
- Immutable artefacts under the alias mean the browser only re-downloads on an actual release; the 5-minute file is small.
- Semantic-versioning discipline is enforced by the URL: anything that breaks an embed requires a new channel, which is a deliberate act.
- The WordPress plugin enqueues the channel URL, so plugin releases and widget releases decouple.
Cons
- A bad `/v1/` release affects every operator at once. Requires a staged rollout or an instant rollback path (repoint the alias) and a Playwright smoke test as a deploy gate.
- Two artefacts to reason about when debugging ("which build was this operator on?"); mitigate by embedding the build version in a `data-` attribute and in `kaiki:ready` event detail.

### Option B — Pinned exact-version URL per operator (`/widget/1.7.3/kaiki-widget.js`), copy-pasted from the panel
Pros
- Absolute stability; an operator site can never change under them.
- Trivial caching: everything immutable, one year.
Cons
- Nobody upgrades. Within a year the platform supports a dozen widget versions against one evolving API.
- Security fixes require contacting every operator, or a forced-upgrade mechanism that recreates Option A badly.
- The API must stay backwards-compatible with every widget ever shipped.

### Option C — Always latest, single unversioned URL, no channels
Pros
- Simplest possible story; one artefact.
Cons
- No escape hatch for a breaking change; the first incompatible release breaks every embed simultaneously.

## Recommendation
**Option A.** It is the pattern used by every embeddable-widget vendor for good reasons: patches propagate, breakage requires an explicit channel bump, and rollback is a single alias change. Pair it with a mandatory Playwright smoke run against the built artefact before the alias is repointed, a size gate (80 KB gzipped, §7 FIXED) in CI, and a `?v=` cache-buster that the panel and the plugin never need to use.

## Consequences if accepted
- Build output goes to `public/widget/builds/{version}/kaiki-widget.js` plus an alias at `public/widget/v1/kaiki-widget.js`; both are served through Cloudflare with the headers above.
- Every build embeds `__KAIKI_WIDGET_VERSION__`; it is exposed on the `kaiki:ready` DOM event and sent as a `X-Kaiki-Widget-Version` header so the API can measure adoption and deprecate safely.
- The panel embed-code generator and the WordPress plugin both emit the channel URL only.
- CI gates: bundle size, Playwright smoke on all four mounts, and an API compatibility test of the widget version against `/api/v1`.
- A documented rollback runbook: repoint the alias to the previous build, purge the CDN path.
- CORS: the API allows any origin for publishable-key read endpoints, with per-key origin allow-listing available as a later hardening step.
