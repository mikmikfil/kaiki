# ADR-0010: Custom-domain tenant resolution and TLS issuance

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Hosted pages, Infra), §7, §11 (Pro plan), §14 M3.21 of `docs/BRIEF.md`; requirements HOS-8 … HOS-18, TEN-4, SEC-14

## Context
Hosted pages live at `book.{platform-domain}/{operator-slug}` and, on the Pro plan, at an operator custom domain pointed by CNAME, with TLS issued automatically by Caddy on-demand. On-demand TLS will attempt a certificate for *any* hostname that reaches it unless an authorisation endpoint says otherwise, so an unguarded configuration is an abuse vector and a fast route to Let us Encrypt rate limits. Tenant resolution must also work for four different entry points (API key, hosted slug, custom domain, panel session) and must be unambiguous. Blocks **M3**, with the resolution middleware written in **M0**.

## Options

### Option A — Caddy on-demand TLS gated by an `/internal/tls-ask` endpoint, custom domains resolved from a `tenant_domains` table
Caddy calls `ask` for every unknown SNI; the endpoint returns 200 only if the hostname exists in `tenant_domains` with `status = verified` and the tenant subscription is active. Verification is a DNS check (CNAME to `book.{platform-domain}`, or an `_kaiki-verify` TXT record) run by a queued job before the domain is marked verified. Resolution middleware order: (1) `Authorization` API key, (2) exact host match in `tenant_domains`, (3) `book.{platform-domain}` plus first path segment as slug, (4) Filament panel session.
Pros
- Certificates are only ever issued for domains an operator has proven control of; rate-limit and abuse exposure is bounded.
- One lookup table serves both TLS authorisation and request-time tenant resolution, so they can never disagree.
- Works with Cloudflare in front for the platform domain while custom domains point straight at the origin (Cloudflare proxying a customer CNAME would break ACME HTTP-01).
- Degrading gracefully is easy: an unverified or lapsed domain simply stops resolving and Caddy stops renewing.
Cons
- The `ask` endpoint is on the certificate hot path; it must be fast, cached and highly available, and it must never require the application database to be reachable for *renewals* of already-issued certificates (Caddy handles this, but it needs testing).
- DNS verification adds an onboarding step and support load ("my CNAME is not propagating").

### Option B — Operator uploads their own certificate
Pros
- No ACME, no on-demand endpoint, no abuse vector.
Cons
- Manual renewal every 90 days for every operator; guaranteed expired-certificate incidents.
- Certificate private keys stored on the platform: worse security posture than issuing.

### Option C — Custom domains only via a CNAME to a per-tenant subdomain of the platform (`{slug}.book.kaiki.example`), with a wildcard certificate, and no operator apex domains
Pros
- One wildcard certificate; no on-demand TLS at all.
Cons
- The operator brand still shows a platform hostname in the address bar unless they proxy, which defeats the point of the Pro custom-domain feature.
- A wildcard certificate is a single high-value secret.

## Recommendation
**Option A.** It is the standard Caddy pattern, it keeps issuance authorised by data the platform already needs for tenant resolution, and it fails safe. Require DNS verification before `verified`, cache the `ask` answer in Caddy and in the application, rate-limit the endpoint, and log every issuance to the super-admin panel.

## Consequences if accepted
- `tenant_domains`: `tenant_id`, `hostname` (unique, lowercased, punycode-normalised), `status` (`pending` | `verified` | `failed` | `disabled`), `verification_token`, `verified_at`, `last_checked_at`.
- `ResolveTenant` middleware implements the four-step order above and aborts 404 (never falls back to a default tenant) — this is a tenant-isolation control.
- `GET /internal/tls-ask?domain=` is unauthenticated but IP-restricted to the Caddy container, cached, and returns 200/403 only.
- Caddyfile ships `on_demand_tls { ask ... }` plus rate limits; the Caddy configuration is production-only (ADR-0015).
- Custom domains are a Pro-plan feature gated by Pennant; losing the plan sets `status = disabled` and stops renewals.
- Hosted pages set `Content-Security-Policy`, `X-Frame-Options` and canonical URLs per resolved hostname so the slug URL and the custom domain do not compete in search.
