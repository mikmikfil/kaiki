# ADR-0013: API key model — publishable vs secret scopes, rotation, and what the WordPress plugin may hold

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Public API), §4 (ApiKey), §8 of `docs/BRIEF.md`; requirements API-4 … API-18, SEC-5 … SEC-10, WPP-3

## Context
§3 fixes three authentication modes: publishable key (`pk_`) for public reads, secret key (`sk_`) for writes, Sanctum sessions for the back-office. §4 adds "scoped, revocable, `last_used_at`". What is not settled is which operations count as "write" for a guest — creating a draft booking is a write, yet the widget must do it with only a publishable key, since a publishable key is visible in page source by definition. The WordPress plugin runs server-side and *could* hold a secret key, but any secret in `wp_options` on a shared host is one plugin vulnerability away from exposure. Blocks **M0** (ApiKeys resource) and defines the security posture of the whole public API.

## Options

### Option A — Two key types with fixed capability sets; guest booking actions are publishable-key operations protected by rate limits, holds and server-side pricing
`pk_` may: read branding, products, availability, price quotes; create enquiries; create draft bookings; create checkout sessions for a booking it created; read a booking only via its own tokens. `pk_` may never: list bookings, read guest personal data, mutate catalogue, read financials. `sk_` may: everything the operator can do through the API (list and manage bookings, catalogue writes, exports, webhook configuration). Keys are stored hashed; the plaintext is shown once. Scopes are an explicit array on the key in **dot form** (`products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive`) so a key can be narrower than its type, never wider.
Pros
- Matches how the widget must actually work: it has to create a draft, and it cannot hold a secret.
- Abuse is bounded by design rather than by secrecy: prices are computed server-side (§5.7), holds expire in 15 minutes (§5.4), rate limits apply per key and per IP, and no personal data is readable with a `pk_`.
- Scopes give a migration path to per-integration keys without a new key type.
Cons
- A leaked `pk_` allows an attacker to create junk drafts and enquiries. Mitigated by rate limits, hold expiry, optional origin allow-listing per key, and a "rotate publishable key" button.
- Two overlapping concepts (type and scopes) must be documented carefully so nobody grants a write scope beyond `bookings.write` to a `pk_`. Enforce with a validation rule and a test.

### Option B — Single key type, all capability expressed as scopes
Pros
- One concept; fewer branches in middleware.
Cons
- Loses the visual, self-documenting `pk_`/`sk_` distinction that makes "never paste `sk_` into a web page" obvious to operators and to support. §3 fixes the two prefixes anyway.

### Option C — Publishable key plus a short-lived session token minted per booking flow
The widget exchanges its `pk_` for a signed, 30-minute session token bound to origin and IP, and all subsequent calls use it.
Pros
- Meaningfully raises the cost of scripted abuse; drafts are attributable to a session.
Cons
- Extra round trip on first paint, against the 1 s budget; more moving parts for the same practical protection that rate limits give.
- Worth revisiting if abuse becomes real; not worth it for MVP.

## Recommendation
**Option A.** The WordPress plugin holds the **publishable key only** for everything it renders; a secret key is optional and only needed for the SEO CPT sync if that endpoint is not made publishable. Recommendation: make product listing readable with `pk_` so the standard plugin installation never stores a secret, and require `sk_` only for the outbound-webhook HMAC secret and cache-bust endpoint, which are configured once and can be stored as a separate, narrowly scoped value.

## Consequences if accepted
- `api_keys`: `tenant_id`, `type` (`publishable` | `secret`), `prefix` (first 8 chars, indexed, shown in UI), `hash`, `scopes` JSON, `name`, `allowed_origins` JSON (nullable), `last_used_at`, `expires_at`, `revoked_at`.
- Middleware resolves tenant from the key (TEN-4), asserts scope per route, and records `last_used_at` at most once per minute to avoid a write per request.
- Rotation: creating a new key never invalidates the old one; the operator revokes explicitly, and the panel warns when a key has been unused for 90 days.
- `sk_` keys are rejected on any request whose `Origin` header is present (browsers always send one on cross-origin requests), which makes accidental front-end use fail loudly.
- The widget never receives an `sk_`; a CI test greps the widget bundle and the WordPress plugin for `sk_` patterns.
- `docs/api.md` documents the capability matrix per key type and per scope.

## Amendments after acceptance
- **2026-08-28 — scope vocabulary normalised to dot form.** The illustrative examples in Option A originally used colon form (`catalog:read`, `bookings:write`), which disagreed with `docs/data-model.md` §3.12 and `docs/api.md` §2.1. Dot form wins: `products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive`. This is an editorial correction to make the three documents agree; **the decision (Option A, two key types with fixed capability sets plus narrowing scopes) is unchanged.**
- **2026-08-28 — noted tension on `GET /sync/products`.** The recommendation above prefers that a standard WordPress installation never store a secret. `docs/api.md` §2.2 and `docs/spec.md` WPP-3 instead keep `sk_` required on that endpoint and confine the key to server-side use (WP-Cron and the inbound webhook handler), with the plugin's client-rendered surface remaining `pk_`-only. This is flagged as a **product-owner veto point**, not a silent override; reversing it changes `docs/api.md` §2.2, §2.4 and `docs/spec.md` WPP-3, and does not touch this ADR's decision.
