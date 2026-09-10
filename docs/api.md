# Kaiki — Public API v1

> Status: **draft v1** · Owner: `architect` · Sources of truth: `docs/BRIEF.md` §3, §4, §5, §6, §7, §8, §12 and `docs/data-model.md`.
> This document is the **hand-written contract**. `dedoc/scramble` generates OpenAPI from the routes; CI diffs the generated document against the `openapi` block below and fails on drift. If the code and this file disagree, one of them is a bug — decide which in the PR, never silently.

**Contents**

1. [Overview](#1-overview)
2. [Authentication](#2-authentication)
3. [Cross-cutting behaviour](#3-cross-cutting-behaviour)
4. [Errors](#4-errors)
5. [OpenAPI 3.1 document](#5-openapi-31-document)
6. [Worked examples](#6-worked-examples)
7. [Inbound webhooks (not public API)](#7-inbound-webhooks-not-public-api)
8. [Outbound webhooks (not public API)](#8-outbound-webhooks-not-public-api)
9. [Open decisions](#9-open-decisions)
10. [Conformance & CI](#10-conformance--ci)

---

## 1. Overview

### 1.1 What this API is

The public API is the **only** interface the Preact widget (`packages/widget`), the hosted Blade pages (`book.{platform-domain}/{slug}` and custom domains) and the WordPress plugin (`packages/wordpress-plugin/kaiki-booking`) use. There is no second, private path: if the hosted page can do it, the widget can do it, and vice versa.

Three hard rules follow from `docs/BRIEF.md` §5.7 and §12 and are not negotiable:

- **The client never computes a price.** Every amount a guest sees comes from `POST /api/v1/price-quote` or from a `Booking` payload. The widget has no pricing code at all.
- **The client never computes availability.** `GET /api/v1/availability` returns bookable options; the client renders them.
- **The client never sees another tenant's data, another guest's data, or any internal identifier.** Public identifiers are `uuid` (or a token, or a human `reference`). The integer `id` and `tenant_id` columns of `docs/data-model.md` never appear in a payload.

### 1.2 Base URL and environments

| Environment | Base URL | Keys accepted |
|---|---|---|
| Production | `https://api.kaiki.app` | `pk_live_…`, `sk_live_…`, `pk_test_…`, `sk_test_…` |
| Staging | `https://api.staging.kaiki.app` | `pk_test_…`, `sk_test_…` only |
| Local | `http://kaiki.test` | `pk_test_…`, `sk_test_…` only |

All paths in this document are relative to the base URL and already include the version segment, e.g. `https://api.kaiki.app/api/v1/products`.

**OPEN — is the API served from a dedicated `api.` host, from the hosted-page host, or from both, and what is the platform domain?** No accepted ADR settles this. ADR-0010 (Option A) settles how a custom **hostname resolves to a tenant** and how its certificate is issued, but not whether that hostname must also serve `/api/v1`. The answer changes the widget's build-time default, the CORS surface (§3.7) and whether Caddy must proxy the API path on every operator domain. **Provisional default for this contract, in force until someone says otherwise: a dedicated `https://api.kaiki.app`, with hosted pages and custom domains calling that same absolute origin.** Building against the provisional default is safe — it is a configuration value, not a schema commitment — but the domain name itself is a product-owner decision. Tracked in [§9](#9-open-decisions) item 1.

### 1.3 Versioning policy

- The version lives in the path: `/api/v1`. There is exactly one live version during MVP.
- **Additive changes are not breaking** and ship without notice: a new optional request field, a new response field, a new enum value in a field documented as open, a new endpoint, a new error `code` in a documented HTTP status.
- **Breaking changes** — removing or renaming a field, narrowing a type, changing an HTTP status for an existing condition, making an optional request field required, removing an enum value — require `/api/v2`. `v1` then enters a **12-month** support window announced in `CHANGELOG.md` and via a `Deprecation` / `Sunset` response header pair (RFC 9745 / RFC 8594).
- Clients **must** ignore unknown response fields and unknown enum values (fall back to a neutral rendering) rather than throwing. The widget and the WordPress plugin have a test each proving this.
- Every response carries `X-Kaiki-Api-Version: 1` and `X-Request-Id`. Quote the `X-Request-Id` in support tickets.

### 1.4 Request and response conventions

- Requests and responses are `application/json; charset=utf-8`. `PUT`/`POST` bodies must send `Content-Type: application/json`; a form-encoded body is rejected with `415`.
- Every successful response is an object with a top-level `data` key. Collections add `pagination`. There is no bare top-level array anywhere, ever — that is what makes adding `pagination` or `meta` later non-breaking.
- Every error response is an object with a top-level `error` key. See [§4](#4-errors).
- Unknown query parameters are ignored. Unknown **body** fields are rejected with `422 validation_failed`, because silently ignoring a misspelled `pax_breakdwn` is how a guest gets charged the wrong amount.
- `HEAD` and `OPTIONS` are supported on every `GET`. `OPTIONS` is the CORS preflight; see [§3.7](#37-cors).

---

## 2. Authentication

Four schemes exist. Three are in scope for this document.

| Scheme | Credential | Transport | Who holds it |
|---|---|---|---|
| **PublishableKey** | `pk_live_…` / `pk_test_…` | `X-Kaiki-Key: pk_live_…` | Widget (browser), hosted page, WordPress plugin front-end |
| **SecretKey** | `sk_live_…` / `sk_test_…` | `Authorization: Bearer sk_live_…` | Server-to-server only: WordPress plugin backend, operator integrations |
| **GuestToken** | `manage_token`, `guest_details_token`, `quote_token`, voucher `code` | Path segment, or `X-Kaiki-Guest-Token` header | A single guest, for a single booking |
| **Sanctum session** | Laravel session cookie | Cookie | Back-office `/app` and `/admin` — **out of scope for this document** |

**Sanctum is explicitly out of scope.** The Filament panels at `/app` (operator) and `/admin` (super-admin) authenticate with Sanctum sessions against routes under `/app` and `/admin`, not under `/api/v1`. No route in this document accepts a session cookie, and no route in this document is reachable from a browser that is merely logged into the panel. Panel-only capabilities — creating products, issuing vouchers, building quotes, running manifest exports, configuring webhook endpoints — are deliberately absent here.

The API key also **resolves the tenant**. There is no `tenant` parameter anywhere: one key belongs to exactly one tenant (`api_keys.tenant_id`), and every query is scoped by `BelongsToTenant` off the resolved tenant. A request without a key and without a guest token cannot resolve a tenant and is rejected `401`.

### 2.1 Publishable keys (`pk_`)

Format: `pk_` + environment (`live` | `test`) + `_` + 32 random base62 characters, e.g. `pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6`.

Storage (`docs/data-model.md` §2.1 `api_keys`): only `prefix` (`pk_live_a1b2c3`, indexed, the lookup handle), `secret_hash` (SHA-256 hex of the full key) and `last_four` are persisted. The plaintext is shown **once** at creation and is never recoverable.

**A publishable key is safe to put in HTML.** It is in page source by design — in the widget script tag as `data-key`, and in the WordPress plugin's rendered markup. Everything that follows exists to make that safe:

- It grants **read on public catalog data only**, plus the ability to create a `draft` booking, create an enquiry, and start a checkout for a booking created in the same flow. It can never list bookings, read another guest's data, read financials, or mutate the catalogue.
- Prices are computed server-side (`BRIEF.md` §5.7), so a forged request cannot invent a price.
- Holds expire in 15 minutes (`tenants.settings.booking.hold_minutes`, `BRIEF.md` §5.4), so junk drafts self-clean.
- It is **origin-restricted**: `api_keys.allowed_origins` is a per-key allow-list. See [§3.7](#37-cors).
- It is rate-limited per key **and** per IP. See [§3.6](#36-rate-limits).

Scopes a `pk_` may hold (`api_keys.scopes`, `docs/data-model.md` §3.12): `branding.read`, `products.read`, `availability.read`, `bookings.write`, `quotes.write`. Any attempt to save a `pk_` with a scope outside that set is rejected at the model layer and covered by a test. A `pk_` is always narrower than or equal to its type's capability set, never wider.

**Settled: the scope vocabulary is dot form.** `products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive` — lowercase, singular resource noun, `.` separator, `read` or `write` verb. This is the only accepted form; colon form (`catalog:read`) appears nowhere and any key saved with one is rejected by the validation rule. `docs/spec.md` (ARC-6, SEC-5), `docs/data-model.md` §3.12 and [ADR-0013](adr/0013-api-key-model.md) all use this vocabulary. (per [ADR-0013](adr/0013-api-key-model.md), Option A — the ADR's illustrative colon-form examples were corrected to dot form on 2026-08-28; the decision itself is unchanged.)

### 2.2 Secret keys (`sk_`)

Format: `sk_` + environment + `_` + 32 random base62 characters. Same hashed storage.

**Server-to-server only. Never in a browser, never in a mobile app, never in a widget bundle, never in a WordPress template.** Two mechanical defences back that sentence up:

1. **Any request presenting an `sk_` with an `Origin` header is rejected `403 secret_key_in_browser`,** whatever the origin is. Browsers always send `Origin` on cross-origin requests and on all non-`GET` requests, so accidental front-end use fails loudly on the first call instead of silently working until it leaks.
2. **CI greps the built widget bundle and the packaged WordPress plugin zip for `sk_(live|test)_` and fails the build on a hit.**

An `sk_` may hold any scope, including `products.read` for the SEO sync endpoint. In v1 the only endpoint that *requires* an `sk_` is `GET /api/v1/sync/products`.

**Settled, with a noted tension: `GET /api/v1/sync/products` requires an `sk_`.** The payload is the full catalogue including `draft` and `inactive` products and internal SEO fields — it is the one endpoint an unfriendly party would scrape — so it is not made publishable-readable.

This is in tension with [ADR-0013](adr/0013-api-key-model.md) Option A, which states that the WordPress plugin holds **only** a `pk_`. The tension is resolved by *where* the key lives, not by weakening either rule:

- The plugin's **SEO CPT sync runs server-side only** — in WP-Cron and in the inbound cache-bust webhook handler. Its `sk_` is stored in WordPress options and is **never** enqueued, printed into markup, exposed through a WP REST route, or otherwise sent to a browser.
- **Everything the plugin renders client-side uses `pk_` and nothing else** — shortcodes, blocks, Elementor widgets and the widget mount. With the SEO-pages toggle off, the plugin is fully functional and stores no secret at all.
- The mechanical defences above still apply: an `sk_` request carrying an `Origin` header is rejected, and CI greps the packaged plugin zip for `sk_(live|test)_`. A plugin that leaked the key into a page would fail both.

**This is a product-owner veto point.** ADR-0013's recommendation was the stricter reading — that a standard WordPress installation should never have to store a secret at all, which would mean making `sync/products` publishable-readable. If the product owner prefers that, the change is to this section, §2.4, `docs/spec.md` WPP-3, and the endpoint's `security` block; the key model itself does not change. See [§9](#9-open-decisions) item 3 and `docs/spec.md` WPP-3.5.

### 2.3 Guest tokens

Tokenised access authenticates **one booking**, with no key and no account (`BRIEF.md` §6). Tokens are 40-character URL-safe strings, **globally unique** (the URL carries no tenant), minted at the moment the corresponding page becomes meaningful and never reused.

| Token | Column | Guest page | Grants |
|---|---|---|---|
| `manage_token` | `bookings.manage_token` | `/b/{manage_token}` | Read the booking, start checkout for a deposit or balance, cancel within policy |
| `guest_details_token` | `bookings.guest_details_token` | `/g/{guest_details_token}` | Read and write the passenger manifest, accept the ναυλοσύμφωνο |
| `quote_token` | `quotes.quote_token` | `/q/{quote_token}` | Read the quote, accept it, decline it |
| voucher `code` | `vouchers.code` | `/v/{code}` | Read the balance of that voucher and nothing else |

Two transports, because the endpoints differ in shape:

- **Token in the path** — `GET /api/v1/quotes/{token}`, `GET|PUT /api/v1/guest-details/{token}`, `GET /api/v1/vouchers/{code}`. The path segment *is* the credential. OpenAPI cannot express a path-borne credential in `securitySchemes`, so those operations declare the `GuestToken` scheme, mark the path parameter `x-kaiki-credential: true`, and additionally accept the credential in the `X-Kaiki-Guest-Token` header.
- **Token in the header** — `GET /api/v1/bookings/{uuid}`, `POST /api/v1/bookings/{uuid}/checkout`, `POST /api/v1/bookings/{uuid}/cancel`. The path carries the booking `uuid`; the `manage_token` goes in `X-Kaiki-Guest-Token`. A `uuid` alone never authorises anything.

Rules:

- Tokens are **not** bearer tokens for the tenant. A `manage_token` reads exactly one `bookings` row and the rows it owns.
- Tokens do not expire on a clock, but they stop working when the underlying object does: a `guest_details_token` returns `409 guest_details_deadline_passed` after `guest_details_deadline_at` (`GET` still works so the guest sees why), and a `quote_token` returns the quote in `expired` / `superseded` state rather than `404`, so `/q/{token}` can say "this quote was replaced".
- Tokens are excluded from logs, from Sentry breadcrumbs and from `Referer` (the guest pages set `Referrer-Policy: no-referrer`).
- A token-authenticated request **may** additionally carry `X-Kaiki-Key`; when both are present the guest token decides authorisation and the key is used only for rate-limit accounting. When a `pk_` is present and its `allowed_origins` does not match, the request is rejected before the token is looked at.
- Voucher lookup by `code` is the one guest credential that is short and human-typed. It therefore requires a `pk_` **in addition** to the code, and is rate-limited hard (see [§3.6](#36-rate-limits)).

### 2.4 Operation → scheme matrix

`✓` = accepted. `—` = rejected. Where two schemes are accepted, either is sufficient.

| Operation | `pk_` | `sk_` | Guest token | Required scope |
|---|---|---|---|---|
| `GET /api/v1/branding` | ✓ | ✓ | — | `branding.read` |
| `GET /api/v1/products` | ✓ | ✓ | — | `products.read` |
| `GET /api/v1/products/{uuid}` | ✓ | ✓ | — | `products.read` |
| `GET /api/v1/availability` | ✓ | ✓ | — | `availability.read` |
| `POST /api/v1/price-quote` | ✓ | ✓ | — | `availability.read` |
| `GET /api/v1/vouchers/{code}` | ✓ | ✓ | — | `products.read` |
| `POST /api/v1/bookings` | ✓ | ✓ | — | `bookings.write` |
| `GET /api/v1/bookings/{uuid}` | — | ✓ | ✓ `manage_token` | `bookings.write` (for `sk_`) |
| `POST /api/v1/bookings/{uuid}/checkout` | ✓ ¹ | ✓ | ✓ `manage_token` | `bookings.write` |
| `POST /api/v1/bookings/{uuid}/cancel` | — | ✓ | ✓ `manage_token` | `bookings.write` (for `sk_`) |
| `POST /api/v1/enquiries` | ✓ | ✓ | — | `bookings.write` |
| `GET /api/v1/quotes/{token}` | — | — | ✓ `quote_token` | — |
| `POST /api/v1/quotes/{token}/accept` | — | — | ✓ `quote_token` | — |
| `POST /api/v1/quotes/{token}/decline` | — | — | ✓ `quote_token` | — |
| `GET /api/v1/guest-details/{token}` | — | — | ✓ `guest_details_token` | — |
| `PUT /api/v1/guest-details/{token}` | — | — | ✓ `guest_details_token` | — |
| `GET /api/v1/sync/products` | — | ✓ | — | `products.read` |

¹ `POST /bookings/{uuid}/checkout` accepts a `pk_` **only** while the booking is still `draft` and its hold is unexpired — that is the widget completing the flow it started in the same session. Once the booking is `confirmed` (paying a balance) the `pk_` is rejected and a `manage_token` is required, because at that point the caller is claiming to be a specific guest, not a specific website.

Rejections are never ambiguous: a missing credential is `401`, a present-but-wrong credential is `401`, and a valid credential without the capability is `403`. See the code table in [§4.2](#42-error-codes).

### 2.5 Rotation, revocation and key hygiene

- **Creation.** An operator creates keys in `/app → Settings → API keys`. A tenant may hold any number of keys; the onboarding wizard creates one `pk_live_`, one `pk_test_` and no secret key.
- **Rotation is create-then-revoke, never in-place.** Creating a new key does not invalidate any existing key. The operator deploys the new key (new `data-key` on the script tag, new value in WordPress settings), watches `last_used_at` on the old key stop advancing, then revokes it. There is no "rotate" verb that swaps the secret behind a fixed identifier — that would break every embed simultaneously.
- **`last_used_at`** is stamped at most once per minute per key (throttled through a cache key, `docs/data-model.md` §2.1), so a busy widget does not turn every read into a write. The panel surfaces it as "last used 4 minutes ago" and warns when a key has been unused for 90 days.
- **Revocation is a column** (`api_keys.revoked_at`), never a delete, so the audit trail and `last_used_at` survive. Revocation takes effect within the key-resolution cache TTL, which is **60 seconds**; the panel says so explicitly next to the confirm button.
- **Expiry** (`api_keys.expires_at`) is optional and enforced identically to revocation.
- **A revoked key returns `401` with code `api_key_revoked`**, not the generic `invalid_api_key`, and the response `details` carries `revoked_at`. This is deliberate: a distinguishable code lets an operator's integration alert on "someone revoked my key" instead of retrying a bad credential forever. Similarly an expired key returns `401 api_key_expired` with `expired_at`. Neither response reveals the tenant.
- **A key of the wrong environment** is not a special case: a `pk_test_` key resolves the same tenant and works, but every booking it creates is flagged `is_test` and every checkout uses the operator's gateway **test** credentials. See [§3.9](#39-test-mode).
- **Compromise.** Revoke immediately; drafts created by the leaked key expire on their own within `hold_minutes`. Confirmed bookings are unaffected because confirmation only ever happens through a verified gateway webhook, never through the public API.

---

## 3. Cross-cutting behaviour

### 3.1 Locale negotiation

Every guest-facing string in a response is resolved to **one** string in **one** locale. The raw `{"el": "…", "en": "…"}` shape of the translatable JSON columns (`docs/data-model.md` §1.6) is an implementation detail and never crosses the API boundary.

Resolution order, highest priority first:

1. `?locale=el` / `?locale=en` query parameter — the explicit override, used by the WordPress plugin when WPML or Polylang has already decided the page language.
2. `Accept-Language` header, parsed for quality values, matched against the supported set (`el`, `en`). `Accept-Language: el-GR,el;q=0.9,en;q=0.8` resolves to `el`.
3. The tenant's `tenants.default_locale`.

Rules:

- Supported locales in v1 are exactly `el` and `en`. An unsupported explicit `?locale=fr` is `400 unsupported_locale` — a typo must not silently serve Greek to a French page. An unsupported `Accept-Language` is **not** an error; it falls through to the tenant default.
- Every response carries `Content-Language: el` (or `en`) and includes `Vary: Accept-Language, Origin, X-Kaiki-Key` so shared caches and CDNs never serve the wrong language to the wrong key.
- Fallback within a locale: if a translatable field is missing `en`, the `el` value is returned rather than `null`. The reverse also holds. `docs/data-model.md` §1.6 requires both keys on write, so this is a safety net, not a routine path.
- Locale is **frozen onto a booking** at creation (`bookings.locale`) and drives every later email, SMS, PDF and token page for that booking, regardless of what locale the guest's browser sends later. `GET /api/v1/bookings/{uuid}` therefore returns the booking's own locale by default; passing `?locale=` overrides the *rendering* of that response only and does not change `bookings.locale`.
- Free guest text (`enquiries.message`, `bookings.special_requests`, `quotes.decline_reason`) is stored and returned exactly as written and is never translated.

### 3.2 Money

Every monetary value is an **integer of euro cents** in a field named `*_cents`, accompanied by a `currency` field on the enclosing object. Never a float. Never a formatted string in a `*_cents` field. Never a string containing a decimal point.

```json
{ "total_cents": 14250, "currency": "EUR" }
```

- `currency` is always `"EUR"` in v1 (`BRIEF.md` §2 — multi-currency is out of scope). It is present anyway so that adding a currency later is additive rather than breaking.
- Percentages are integers 0–100 (`refund_percent`, `deposit.percent`). VAT rates are **basis points** (`rate_bp: 1300` = 13.00%), matching `docs/data-model.md` §1.4.
- Amounts that are conceptually negative — discounts, refunds — are transmitted **positive**, with the sign carried by the sibling `kind` (`"kind": "discount"`) or by the field name (`refunded_cents`). This mirrors the schema and makes a sign bug unrepresentable.
- A `*_formatted` sibling is provided **only** on the three fields a client would otherwise be tempted to format itself, because getting Greek number formatting wrong is worse than sending eight extra bytes:
  - `ProductSummary.from_price_formatted`
  - `PriceQuote.total_formatted` and `PriceQuote.deposit.amount_formatted`
  - `Voucher.remaining_formatted`
  Formatting uses **the resolved response locale**, `el` → `65,00 €` (comma decimal separator, non-breaking space, trailing symbol), `en` → `€65.00`. It is produced by `brick/money` with the ICU locale, never by `number_format()`. A client that needs a different format must use the `*_cents` field and format it itself; `*_formatted` is a convenience, never the source of truth.
- Arithmetic invariant, asserted server-side on every pricing response: `subtotal_cents + extras_cents − discount_cents = total_cents`, and when `vat.included` is true, `vat.net_cents + vat.vat_cents = total_cents`.

### 3.3 Timestamps and timezone

- Every absolute timestamp is **ISO-8601 in UTC with a literal `Z`**: `2026-07-14T06:30:00Z`. No offsets, no local times, no epoch integers. Field names have no suffix (`created_at`, `confirmed_at`, `hold_expires_at`, `valid_until`).
- **The client never converts a timezone.** Anything that has a "when" the guest reads — a departure, a booking window, a check-in time — additionally exposes the pre-computed local values, exactly as they are stored (`docs/data-model.md` §1.5):

  | Field | Type | Meaning |
  |---|---|---|
  | `local_date` | `YYYY-MM-DD` | The calendar date in the **tenant's** timezone. This is what "the date" means to the operator, the guest and the calendar UI. |
  | `local_time` | `HH:MM` | Start time of day in the tenant's timezone. Seconds are always `00` and are omitted. |
  | `starts_at` | ISO-8601 `Z` | The same instant in UTC. |
  | `ends_at` | ISO-8601 `Z` | End of the window in UTC. |
  | `timezone` | IANA name | e.g. `Europe/Athens`. Present on every object that carries `local_date`. |

  A client renders `local_date` + `local_time` verbatim and uses `starts_at` only for countdowns and sorting. This is what makes a Berlin-based tourist see `09:00` for a 09:00 Athens departure instead of `08:00`.
- Date-only fields that are not windows — `date_of_birth`, `document_expires_on`, `preferred_date`, availability `from`/`to` — are `YYYY-MM-DD` with no timezone semantics.
- DST is handled server-side (`docs/data-model.md` §1.5, ADR-0016). A departure inside the missing March hour does not exist and is simply absent from `GET /availability`; a departure inside the repeated October hour resolves to the first occurrence and carries `"dst_ambiguous": true` so a client can show a clarifying note.

### 3.4 Idempotency

`Idempotency-Key` is a client-generated **UUIDv4** sent as a request header. It is **required** on every POST that creates a booking or moves money, and **accepted** (recommended) on the rest:

| Operation | `Idempotency-Key` |
|---|---|
| `POST /api/v1/bookings` | **required** |
| `POST /api/v1/bookings/{uuid}/checkout` | **required** |
| `POST /api/v1/bookings/{uuid}/cancel` | **required** |
| `POST /api/v1/quotes/{token}/accept` | **required** |
| `POST /api/v1/quotes/{token}/decline` | optional |
| `POST /api/v1/enquiries` | optional |
| `PUT /api/v1/guest-details/{token}` | optional (`PUT` is naturally idempotent) |
| `POST /api/v1/price-quote` | ignored if sent (pure computation, no side effects) |

Omitting a required key is `422 validation_failed` with `details.idempotency_key`.

**Replay semantics.** The key is scoped to `(tenant, endpoint, key)` and stored with a SHA-256 hash of the canonicalised request body.

1. **First request** — processed normally. The full response (status, body, and the `Location`/`Idempotency-Replayed` headers) is recorded before the response is returned.
2. **Replay with the same key and the same body** — the recorded response is returned **verbatim**, including its original HTTP status (a replayed create still returns `201`, not `200`), plus `Idempotency-Replayed: true`. No side effect runs a second time: no second hold, no second gateway session, no second refund.
3. **Replay with the same key and a *different* body** — `409 idempotency_key_reuse`. The client has a bug; failing loudly is the only safe answer when money is involved.
4. **Replay while the first request is still in flight** — `409 idempotency_in_progress` with `Retry-After: 1`. The client should retry after a short backoff rather than assume failure.
5. A request that failed with a **5xx** or a network error is **not** recorded as a final response; retrying the same key re-executes it. A request that failed with a **4xx** *is* recorded — a validation error is a deterministic answer.

**Retention: 24 hours.** After that the key is forgotten and a replay is treated as a new request. Twenty-four hours comfortably covers a mobile client retrying through a tunnel outage and is far longer than the 15-minute hold that bounds the blast radius of a duplicate draft. Clients must not reuse a key across days.

The gateway-facing idempotency key (`payments.idempotency_key`, `docs/data-model.md` §2.5) is **minted by Kaiki**, not taken from this header. The two are independent: the client's key deduplicates the API call, ours deduplicates the charge.

### 3.5 Pagination

Cursor-based, never offset-based — an offset paginator over a catalogue that is being edited returns duplicates and skips rows, and cursor pagination is what keeps the WordPress SEO sync correct.

Request: `?cursor=<opaque>&per_page=<n>`.

| Parameter | Default | Max | Notes |
|---|---|---|---|
| `per_page` | `24` (`/products`), `100` (`/sync/products`) | `100` | Values above the max are clamped, not rejected. |
| `cursor` | — | — | Opaque, base64url. Copy it back verbatim; never construct, parse or store one beyond the current traversal. A malformed or stale cursor is `400 invalid_cursor`. |

Response envelope:

```json
{
  "data": [ /* … */ ],
  "pagination": {
    "per_page": 24,
    "has_more": true,
    "next_cursor": "eyJpZCI6MTQ0LCJzIjoxMH0",
    "prev_cursor": null,
    "next_url": "https://api.kaiki.app/api/v1/products?per_page=24&cursor=eyJpZCI6MTQ0LCJzIjoxMH0"
  }
}
```

- There is **no total count**. Counting is a second query on every page for a number nobody renders; if a client needs "24 trips", it has already loaded them.
- `has_more` is authoritative. `next_cursor` is `null` exactly when `has_more` is `false`.
- Ordering is stable and endpoint-defined (`/products`: `is_featured DESC, sort_order ASC, uuid ASC`; `/sync/products`: `updated_at ASC, uuid ASC`). The cursor encodes the sort key and the tiebreaker `uuid`, so equal sort values cannot loop.
- `GET /availability` is **not** paginated: it is bounded by a maximum 62-day `from`/`to` range instead, because a client that receives half a calendar cannot render a month.

### 3.6 Rate limits

Limits are applied **per key and per IP simultaneously**; the stricter one wins. Guest-token requests are limited per token and per IP. Every response — success or failure — carries:

| Header | Meaning |
|---|---|
| `X-RateLimit-Limit` | Requests permitted in the current window for the bucket that is closest to exhaustion |
| `X-RateLimit-Remaining` | Requests left in that bucket |
| `X-RateLimit-Reset` | Unix epoch seconds at which that bucket refills |
| `Retry-After` | Seconds to wait. Present **only** on `429`. |

Classes, per 60-second sliding window:

| Class | Endpoints | Per key | Per IP |
|---|---|---|---|
| **A — catalog reads** | `GET /branding`, `GET /products`, `GET /products/{uuid}` | 600 | 120 |
| **B — availability** | `GET /availability`, `GET /search` | 1200 | 240 |
| **C — pricing** | `POST /price-quote` | 600 | 120 |
| **D — booking writes** | `POST /bookings`, `POST /bookings/{uuid}/checkout`, `POST /bookings/{uuid}/cancel`, `POST /quotes/{token}/accept`, `POST /quotes/{token}/decline` | 60 | 20 |
| **E — guest reads/writes** | `GET /bookings/{uuid}`, `GET|PUT /guest-details/{token}`, `GET /quotes/{token}` | 120 | 60 |
| **F — enquiries** | `POST /enquiries` | 120 | **5** |
| **G — voucher lookup** | `GET /vouchers/{code}` | 120 | **10** |
| **H — sync** | `GET /sync/products` | 120 | 120 |

Classes **B** and **C** are the hot path — a calendar mount fires one availability call per month view and one price quote per pax change — and are sized so that a busy operator's homepage cannot be throttled by legitimate traffic. Class **F** is the most spam-exposed endpoint in the system (`docs/data-model.md` §2.5) and is deliberately punitive per IP, backed by a honeypot field that is never persisted. Class **G** is low per IP because a voucher `code` is short and guessable in bulk; sustained failures from one IP also trip a 15-minute block.

`429` bodies use the standard error envelope with code `rate_limited` and `details.retry_after_seconds`.

Availability and product reads are additionally **cached** — `Cache-Control: public, max-age=60` on `/branding` and `/products`, `max-age=30` on `/availability` and `/search`, `no-store` on everything guest-specific. A cache hit does not consume rate-limit quota at the edge, but the origin limit still applies.

### 3.7 CORS

Publishable keys carry `api_keys.allowed_origins`, a per-key allow-list of scheme + host + optional port (`https://aegeancruises.gr`, `https://www.aegeancruises.gr`). Wildcards are permitted only in the leftmost label (`https://*.aegeancruises.gr`) and never as a bare `*` for a live key.

Preflight (`OPTIONS`), for any request the browser considers non-simple — which is all of them, because `X-Kaiki-Key` is a custom header:

```http
OPTIONS /api/v1/price-quote HTTP/1.1
Origin: https://aegeancruises.gr
Access-Control-Request-Method: POST
Access-Control-Request-Headers: content-type,x-kaiki-key,idempotency-key
```

Preflight is answered **without** authenticating the key (a preflight carries no headers to authenticate with) but **with** the origin checked against every non-revoked publishable key of every tenant that lists it. A matching origin gets:

```http
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://aegeancruises.gr
Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Accept-Language, If-None-Match, Idempotency-Key, X-Kaiki-Key, X-Kaiki-Guest-Token
Access-Control-Expose-Headers: X-Request-Id, X-Kaiki-Api-Version, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Idempotency-Replayed, Content-Language
Access-Control-Max-Age: 600
Vary: Origin
```

`Access-Control-Allow-Credentials` is **never** sent; this API does not use cookies.

**Failure mode when an origin is not allowed.** This is the single most common integration problem, so it is designed to be diagnosable:

- The **preflight** is answered `204` **without** any `Access-Control-Allow-*` headers. The browser then blocks the real request and logs its own CORS error. There is nothing the server can put in that response that the page's JavaScript will ever see.
- The **actual request**, if it reaches the server (a non-browser client, or a browser that skipped preflight), is rejected `403 origin_not_allowed`, with `details.origin` echoing what was sent and `details.hint` naming the setting to fix. This is what makes the failure debuggable from `curl` and from the server logs even though the browser could not show it.
- Both cases increment a per-key counter surfaced in `/app → Settings → API keys` as "N requests blocked by origin rules in the last 24 h", with the offending origins listed. An operator who pastes their key on a staging domain sees why within one page load.
- A key with an **empty** `allowed_origins` accepts any origin, and the panel shows a persistent warning badge. This is the default for `pk_test_` keys and is discouraged for `pk_live_`.
- The hosted page (`book.{platform-domain}/{slug}` and verified custom domains) is always allowed, implicitly, without appearing in `allowed_origins`.

`sk_` requests are never subject to CORS, because a request carrying an `Origin` header with an `sk_` is rejected outright (`403 secret_key_in_browser`, [§2.2](#22-secret-keys-sk)).

### 3.8 Conditional requests and caching

`GET /branding`, `GET /products`, `GET /products/{uuid}` and `GET /sync/products` return a strong `ETag` and `Last-Modified`. Clients — especially the WordPress plugin, which caches in transients — should send `If-None-Match` and handle `304 Not Modified`. A `304` carries no body, does consume rate-limit quota, and is the cheapest correct way to poll.

### 3.9 Test mode

A tenant in sandbox mode (`BRIEF.md` §11) and any request authenticated with a `pk_test_` / `sk_test_` key operate in **test mode**:

- Bookings created are flagged `is_test: true` (`bookings.is_test`) and are **purged nightly**.
- Checkout uses the operator's gateway **test** credentials; no real money moves.
- Test bookings are excluded from every operator report, from myDATA issuance and from counter reconciliation.
- Test and live data share the same catalogue: a `pk_test_` key reads the same products, the same availability and the same prices as its `pk_live_` sibling. Only the *bookings* differ. This is what makes "do a test booking" in the onboarding wizard meaningful.
- Every response to a test-mode request carries `X-Kaiki-Test-Mode: true`, and every object that can be test-flagged exposes `"is_test": true`. The widget renders a "TEST" ribbon when it sees it, so nobody demos a sandbox booking to a customer by accident.

---

## 4. Errors

### 4.1 Envelope

One shape, every failure, every endpoint:

```json
{
  "error": {
    "code": "insufficient_capacity",
    "message": "Only 2 seats remain on this departure.",
    "message_el": "Απομένουν μόνο 2 θέσεις σε αυτή την αναχώρηση.",
    "details": {
      "departure_uuid": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f",
      "seats_requested": 4,
      "seats_available": 2
    }
  }
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `code` | string, `snake_case` | yes | The **stable** contract. Clients branch on this and never on `message`. New codes may appear within a documented status; unknown codes must be handled as a generic failure of that status. |
| `message` | string | yes | English, plain, guest-safe. Never contains a class name, a SQL fragment, a stack frame or an internal id. |
| `message_el` | string | yes | Greek, same meaning, same register. |
| `details` | object | no | Machine-readable context. Shape varies by `code` and is documented per code below. Absent rather than `null` when empty. |

**Why both languages in every error.** The guest-facing clients are a Shadow-DOM widget, a Blade page and a WordPress theme, each with its own locale resolution, and a booking can fail at the exact moment the widget is mid-locale-switch. Shipping both strings costs a few dozen bytes and removes an entire class of "the error was in the wrong language" bug. **The client picks**: it renders `message_el` when its active locale is `el`, `message` otherwise. It never renders `code` to a guest.

Rules:

- `message` and `message_el` are always populated, always safe to show a tourist, and never leak whether a resource exists in another tenant.
- Validation errors put the per-field detail in `details` keyed by request field path, each with its own bilingual pair — see `validation_failed` below.
- `500` responses carry a generic message and `details.request_id`; the real exception goes to Sentry with the tenant tag, never to the client.
- The HTTP status is authoritative for the *class* of failure; the `code` is authoritative for the *reason*.

### 4.2 Error codes

Every code this API can emit. Messages below are the canonical strings; interpolated values appear as `{placeholder}`.

**Authentication and authorisation**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `unauthenticated` | 401 | API key or guest token required. | Απαιτείται κλειδί API ή σύνδεσμος πρόσβασης. | — |
| `invalid_api_key` | 401 | The API key is not valid. | Το κλειδί API δεν είναι έγκυρο. | — |
| `api_key_revoked` | 401 | This API key has been revoked. | Αυτό το κλειδί API έχει ανακληθεί. | `revoked_at` |
| `api_key_expired` | 401 | This API key has expired. | Αυτό το κλειδί API έχει λήξει. | `expired_at` |
| `insufficient_scope` | 403 | This key is not allowed to perform this action. | Αυτό το κλειδί δεν επιτρέπεται να εκτελέσει αυτή την ενέργεια. | `required_scope`, `key_type` |
| `secret_key_in_browser` | 403 | A secret key cannot be used from a browser. Use a publishable key. | Το μυστικό κλειδί δεν μπορεί να χρησιμοποιηθεί από φυλλομετρητή. Χρησιμοποιήστε δημόσιο κλειδί. | `origin` |
| `secret_key_required` | 403 | This endpoint requires a secret key. | Αυτό το endpoint απαιτεί μυστικό κλειδί. | `key_type` |
| `origin_not_allowed` | 403 | This website is not authorised to use this key. | Αυτός ο ιστότοπος δεν έχει εξουσιοδότηση για αυτό το κλειδί. | `origin`, `hint` |
| `invalid_guest_token` | 401 | This link is not valid. | Αυτός ο σύνδεσμος δεν είναι έγκυρος. | — |
| `guest_token_expired` | 410 | This link has expired. | Αυτός ο σύνδεσμος έχει λήξει. | `expired_at` |
| `tenant_suspended` | 403 | This operator's account is temporarily inactive. | Ο λογαριασμός του διοργανωτή είναι προσωρινά ανενεργός. | — |
| `tenant_read_only` | 403 | Online booking is temporarily closed. Please contact the operator. | Οι online κρατήσεις είναι προσωρινά κλειστές. Επικοινωνήστε με τον διοργανωτή. | `contact_email`, `contact_phone` |

**Request shape**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `validation_failed` | 422 | Please check the details you entered. | Ελέγξτε τα στοιχεία που συμπληρώσατε. | `fields{}` — see below |
| `unsupported_locale` | 400 | That language is not supported. | Αυτή η γλώσσα δεν υποστηρίζεται. | `requested`, `supported` |
| `invalid_updated_since` | 400 | The `updated_since` value is not a valid date and time. | Η τιμή `updated_since` δεν είναι έγκυρη ημερομηνία και ώρα. | `updated_since` |
| `invalid_cursor` | 400 | The pagination cursor is not valid. | Ο δείκτης σελιδοποίησης δεν είναι έγκυρος. | — |
| `invalid_date_range` | 422 | The date range is not valid. | Το εύρος ημερομηνιών δεν είναι έγκυρο. | `from`, `to`, `max_days` |
| `unsupported_media_type` | 415 | Requests must be sent as JSON. | Τα αιτήματα πρέπει να αποστέλλονται σε μορφή JSON. | `received` |
| `not_found` | 404 | Not found. | Δεν βρέθηκε. | — |
| `idempotency_key_reuse` | 409 | This request key was already used with different data. | Αυτό το κλειδί αιτήματος χρησιμοποιήθηκε ήδη με διαφορετικά δεδομένα. | `idempotency_key` |
| `idempotency_in_progress` | 409 | An identical request is still being processed. | Ένα ίδιο αίτημα βρίσκεται ακόμη σε εξέλιξη. | `retry_after_seconds` |
| `rate_limited` | 429 | Too many requests. Please try again shortly. | Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε λίγο. | `retry_after_seconds`, `limit`, `scope` |

`validation_failed.details.fields` is keyed by request field path, values are objects with `code`, `message`, `message_el`:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please check the details you entered.",
    "message_el": "Ελέγξτε τα στοιχεία που συμπληρώσατε.",
    "details": {
      "fields": {
        "guest.email": { "code": "email", "message": "Enter a valid email address.", "message_el": "Δώστε μια έγκυρη διεύθυνση email." },
        "pax[1].qty": { "code": "min", "message": "Must be 0 or more.", "message_el": "Πρέπει να είναι 0 ή μεγαλύτερο." }
      }
    }
  }
}
```

**Catalogue and availability**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `product_not_bookable` | 409 | This trip is not available for booking. | Αυτή η εκδρομή δεν είναι διαθέσιμη για κράτηση. | `product_uuid`, `reason` |
| `product_is_quote_only` | 409 | This trip is booked by request. Send an enquiry instead. | Αυτή η εκδρομή γίνεται κατόπιν αιτήματος. Στείλτε μας ένα μήνυμα. | `product_uuid` |
| `departure_unavailable` | 409 | That departure is no longer available. | Αυτή η αναχώρηση δεν είναι πλέον διαθέσιμη. | `departure_uuid`, `reason` |
| `insufficient_capacity` | 409 | Only {n} seats remain on this departure. | Απομένουν μόνο {n} θέσεις σε αυτή την αναχώρηση. | `departure_uuid`, `seats_requested`, `seats_available` |
| `vessel_window_unavailable` | 409 | The boat is not available at that time. | Το σκάφος δεν είναι διαθέσιμο εκείνη την ώρα. | `vessel_uuid`, `starts_at`, `ends_at`, `conflict` |
| `lead_time_violation` | 422 | Bookings must be made at least {hours} hours before departure. | Οι κρατήσεις γίνονται τουλάχιστον {hours} ώρες πριν την αναχώρηση. | `min_lead_time_hours`, `starts_at` |
| `advance_window_violation` | 422 | That date is too far ahead to book. | Αυτή η ημερομηνία είναι πολύ μακριά για κράτηση. | `max_advance_days`, `local_date` |
| `pax_below_minimum` | 422 | This trip takes a minimum of {n} people. | Αυτή η εκδρομή γίνεται από {n} άτομα και άνω. | `min_booking_pax`, `requested` |
| `pax_above_maximum` | 422 | This trip takes a maximum of {n} people. | Αυτή η εκδρομή δέχεται έως {n} άτομα. | `max_pax`, `requested` |
| `age_band_requires_adult` | 422 | At least one adult must travel with children. | Απαιτείται τουλάχιστον ένας ενήλικας για να ταξιδέψουν παιδιά. | `age_band_code` |
| `age_band_unknown` | 422 | One of the passenger types is not valid for this trip. | Μία από τις κατηγορίες επιβατών δεν ισχύει για αυτή την εκδρομή. | `age_band_uuid` |
| `extra_unknown` | 422 | One of the selected extras is not available for this trip. | Ένα από τα πρόσθετα δεν είναι διαθέσιμο για αυτή την εκδρομή. | `extra_uuid` |
| `extra_qty_exceeded` | 422 | You can add at most {n} of "{name}". | Μπορείτε να προσθέσετε έως {n} "{name}". | `extra_uuid`, `max_qty` |
| `flexible_start_not_allowed` | 422 | This trip has a fixed departure time. | Αυτή η εκδρομή έχει σταθερή ώρα αναχώρησης. | `product_uuid`, `default_start_time` |

**Booking lifecycle**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `hold_expired` | 409 | Your reservation hold expired. Please start again. | Ο χρόνος κράτησης έληξε. Ξεκινήστε ξανά. | `booking_uuid`, `hold_expires_at` |
| `price_changed` | 409 | The price has changed. Please review the new price. | Η τιμή άλλαξε. Ελέγξτε τη νέα τιμή. | `previous_total_cents`, `total_cents`, `currency` |
| `booking_state_invalid` | 409 | This booking cannot be changed in its current state. | Η κράτηση δεν μπορεί να τροποποιηθεί στην τρέχουσα κατάστασή της. | `status`, `attempted` |
| `booking_not_cancellable` | 409 | This booking cannot be cancelled online. Please contact the operator. | Η κράτηση δεν μπορεί να ακυρωθεί online. Επικοινωνήστε με τον διοργανωτή. | `reason`, `contact_email`, `contact_phone` |
| `cancellation_window_closed` | 409 | The cancellation deadline for this booking has passed. | Η προθεσμία ακύρωσης για αυτή την κράτηση έχει παρέλθει. | `deadline_at`, `hours_before_departure` |
| `guest_details_not_required` | 409 | Passenger details are not required for this booking. | Δεν απαιτούνται στοιχεία επιβατών για αυτή την κράτηση. | `booking_uuid` |
| `guest_details_deadline_passed` | 409 | The deadline for submitting passenger details has passed. | Η προθεσμία υποβολής στοιχείων επιβατών έχει παρέλθει. | `deadline_at`, `contact_email` |
| `guest_count_mismatch` | 422 | The number of passengers does not match the booking. | Ο αριθμός επιβατών δεν συμφωνεί με την κράτηση. | `expected`, `received` |
| `charter_agreement_required` | 422 | You must accept the charter agreement (ναυλοσύμφωνο) to continue. | Πρέπει να αποδεχτείτε το ναυλοσύμφωνο για να συνεχίσετε. | `agreement_version` |
| `enquiry_rejected` | 422 | Your message could not be sent. Please try again. | Το μήνυμά σας δεν στάλθηκε. Δοκιμάστε ξανά. | — |

**Quotes and vouchers**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `quote_expired` | 410 | This quote has expired. | Αυτή η προσφορά έχει λήξει. | `valid_until` |
| `quote_superseded` | 409 | This quote was replaced by a newer one. | Αυτή η προσφορά αντικαταστάθηκε από νεότερη. | `superseded_by_version` |
| `quote_already_decided` | 409 | This quote has already been answered. | Αυτή η προσφορά έχει ήδη απαντηθεί. | `status`, `decided_at` |
| `voucher_not_found` | 404 | This voucher code was not found. | Ο κωδικός κουπονιού δεν βρέθηκε. | — |
| `voucher_expired` | 422 | This voucher has expired. | Αυτό το κουπόνι έχει λήξει. | `expires_at` |
| `voucher_depleted` | 422 | This voucher has no remaining balance. | Αυτό το κουπόνι δεν έχει υπόλοιπο. | `remaining_cents` |
| `voucher_not_applicable` | 422 | This voucher cannot be used for this booking. | Αυτό το κουπόνι δεν μπορεί να χρησιμοποιηθεί σε αυτή την κράτηση. | `reason` |

**Payments and platform**

| Code | HTTP | `message` (en) | `message_el` (el) | `details` |
|---|---|---|---|---|
| `gateway_not_configured` | 409 | The operator has not set up online payments yet. | Ο διοργανωτής δεν έχει ρυθμίσει ακόμη online πληρωμές. | `contact_email`, `contact_phone` |
| `gateway_unavailable` | 502 | The payment system is unavailable right now. Please try again shortly. | Το σύστημα πληρωμών δεν είναι διαθέσιμο αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο. | `gateway` |
| `payment_already_settled` | 409 | This booking is already paid in full. | Αυτή η κράτηση έχει ήδη εξοφληθεί. | `paid_cents`, `total_cents` |
| `deposit_not_available` | 422 | A deposit is not available for this booking. | Η προκαταβολή δεν είναι διαθέσιμη για αυτή την κράτηση. | `booking_uuid` |
| `lead_guest_required` | 422 | Fill in your details first to go on to payment. | Συμπληρώστε πρώτα τα στοιχεία σας για να προχωρήσετε στην πληρωμή. | `booking_uuid` |
| `balance_not_due` | 409 | There is no outstanding balance on this booking. | Δεν υπάρχει υπόλοιπο προς πληρωμή σε αυτή την κράτηση. | `balance_cents` |
| `server_error` | 500 | Something went wrong. Please try again. | Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά. | `request_id` |
| `service_unavailable` | 503 | The service is temporarily unavailable. | Η υπηρεσία είναι προσωρινά μη διαθέσιμη. | `retry_after_seconds` |

Forty-seven codes. Lang files `resources/lang/{el,en}/api-errors.php` are the single source for the strings; this table is generated from them by a Pest test that fails if the two drift.

---

## 5. OpenAPI 3.1 document

```yaml
openapi: 3.1.1
jsonSchemaDialect: https://spec.openapis.org/oas/3.1/dialect/base

info:
  title: Kaiki Public API
  version: "1.0.0"
  summary: Booking engine for cruises, day trips and private charters.
  description: |
    The public API consumed by the Kaiki Preact widget, the hosted Blade booking pages
    and the `kaiki-booking` WordPress plugin.

    Authentication is by publishable key (`X-Kaiki-Key: pk_…`), secret key
    (`Authorization: Bearer sk_…`) or a per-booking guest token. Back-office
    (Sanctum session) routes under `/app` and `/admin` are NOT part of this document.

    All money is integer euro cents. All timestamps are ISO-8601 UTC with `Z`;
    anything a guest reads additionally carries `local_date`, `local_time` and `timezone`.
    Prices and availability are computed server-side only.
  termsOfService: https://kaiki.app/legal/terms
  contact:
    name: Kaiki API support
    email: api@kaiki.app
    url: https://kaiki.app/docs/api
  license:
    name: Proprietary
    identifier: LicenseRef-Kaiki-Proprietary

servers:
  - url: https://api.kaiki.app
    description: Production
  - url: https://api.staging.kaiki.app
    description: Staging (test keys only)
  - url: http://kaiki.test
    description: Local development

tags:
  - name: Branding
    description: White-label appearance for the widget and hosted pages.
  - name: Catalog
    description: Products, age bands, extras, meeting points, policies.
  - name: Availability
    description: Bookable departures (per_seat) and free vessel windows (per_vessel).
  - name: Pricing
    description: Server-side price calculation. The client never computes a price.
  - name: Bookings
    description: Draft creation, checkout, retrieval and guest cancellation.
  - name: Quotes
    description: Operator-built quotes viewed and decided by the guest.
  - name: Guest details
    description: Passenger manifest and charter-agreement acceptance.
  - name: Enquiries
    description: Guest enquiries from the "ask a question" form.
  - name: Vouchers
    description: Voucher balance lookup.
  - name: Sync
    description: Server-to-server catalogue sync for the WordPress SEO CPT.
  - name: Meta
    description: Version and key-validity checks. Carries no tenant data.

security:
  - PublishableKey: []

paths:

  /api/v1/health:
    get:
      operationId: getHealth
      summary: Confirm the API version and that a key is accepted
      description: |
        A versioning smoke endpoint for integrators. It answers a narrower question
        than "is the server up" — *is this key accepted, and which API version is
        answering it* — which is what someone debugging a `401` actually needs.

        Deliberately authenticated. An unauthenticated route here would be the only
        endpoint in this document with no tenant, and the one route a later change
        could quietly hang something else off. Load-balancer liveness is `/up`, which
        is outside `/api/v1` and not part of this contract.

        Returns no tenant identifier: a key already implies its tenant, and echoing a
        slug or uuid would make this the cheapest tenant-enumeration oracle in the
        product.
      tags: [Meta]
      security:
        - PublishableKey: []
        - SecretKey: []
      responses:
        '200':
          description: The API is reachable and the presented key is valid.
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data:
                    type: object
                    required: [status, version]
                    properties:
                      status:
                        type: string
                        const: ok
                      version:
                        type: string
                        description: The API major version answering this request.
                        examples: [v1]
        '401': { $ref: '#/components/responses/Unauthorized' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/branding:
    get:
      operationId: getBranding
      summary: Get the operator's brand profile
      description: |
        Returns the tenant's `BrandProfile` resolved for the widget: colours, logo URLs,
        font, radius and theme, plus a ready-made `css_variables` map the widget writes
        straight onto its Shadow DOM root. This is the widget's first call on every page load.

        `custom_css` is returned only for the hosted page and verified custom domains;
        it is always `null` for a third-party embed, because injecting operator CSS into
        someone else's site is not ours to do.
      tags: [Branding]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
        - $ref: '#/components/parameters/IfNoneMatchHeader'
      responses:
        '200':
          description: The brand profile.
          headers:
            ETag: { $ref: '#/components/headers/ETag' }
            Cache-Control: { $ref: '#/components/headers/CacheControl' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
            X-RateLimit-Limit: { $ref: '#/components/headers/RateLimitLimit' }
            X-RateLimit-Remaining: { $ref: '#/components/headers/RateLimitRemaining' }
            X-RateLimit-Reset: { $ref: '#/components/headers/RateLimitReset' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Branding' }
              examples:
                default:
                  $ref: '#/components/examples/BrandingResponse'
        '304': { $ref: '#/components/responses/NotModified' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/products:
    get:
      operationId: listProducts
      summary: List bookable products
      description: |
        Cursor-paginated list of the tenant's `active` products, ordered
        `is_featured DESC, sort_order ASC, uuid ASC`. Powers the widget's `list` mount,
        the WordPress `[kaiki_list]` shortcode and the hosted landing page.

        Every item carries `from_price_cents` — the cheapest capacity-counting adult price
        across the product's active rate plans (`products.price_from_cents`). It is `null`
        for `mode: quote` products, which never show a price.
      tags: [Catalog]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - name: category
          in: query
          description: Filter by product category. Repeat the parameter to pass several.
          required: false
          schema:
            type: array
            items:
              type: string
              enum: [shared_full_day, shared_half_day, private_full_day, private_half_day, sunset, custom]
          style: form
          explode: true
          example: [shared_full_day, sunset]
        - name: mode
          in: query
          description: Filter by booking mode.
          required: false
          schema:
            type: string
            enum: [per_seat, per_vessel, quote]
          example: per_seat
        - name: vessel
          in: query
          description: Filter by vessel UUID.
          required: false
          schema: { type: string, format: uuid }
          example: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
        - $ref: '#/components/parameters/CursorQuery'
        - $ref: '#/components/parameters/PerPageQuery'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
        - $ref: '#/components/parameters/IfNoneMatchHeader'
      responses:
        '200':
          description: A page of product summaries.
          headers:
            ETag: { $ref: '#/components/headers/ETag' }
            Cache-Control: { $ref: '#/components/headers/CacheControl' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
            X-RateLimit-Remaining: { $ref: '#/components/headers/RateLimitRemaining' }
          content:
            application/json:
              schema:
                type: object
                required: [data, pagination]
                properties:
                  data:
                    type: array
                    items: { $ref: '#/components/schemas/ProductSummary' }
                  pagination: { $ref: '#/components/schemas/Pagination' }
              examples:
                default:
                  $ref: '#/components/examples/ProductListResponse'
        '304': { $ref: '#/components/responses/NotModified' }
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/products/{uuid}:
    get:
      operationId: getProduct
      summary: Get one product in full
      description: |
        The complete product: description, includes/excludes/what-to-bring, itinerary stops
        with coordinates, meeting point, age bands, extras, cancellation policy summary and
        SEO fields — all translatable fields already resolved to the negotiated locale.

        Also accepts the product `slug` in place of the UUID, which is what the hosted page
        and the WordPress permalink resolve with.
      tags: [Catalog]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/ProductIdentifierPath'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
        - $ref: '#/components/parameters/IfNoneMatchHeader'
      responses:
        '200':
          description: The product.
          headers:
            ETag: { $ref: '#/components/headers/ETag' }
            Cache-Control: { $ref: '#/components/headers/CacheControl' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Product' }
              examples:
                greek:
                  $ref: '#/components/examples/ProductDetailGreekResponse'
        '304': { $ref: '#/components/responses/NotModified' }
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/availability:
    get:
      operationId: getAvailability
      summary: Get bookable availability for a product over a date range
      description: |
        Per-date availability for one product, covering both booking modes.

        - `mode: per_seat` — each day lists `departures[]`: the departures whose vessel
          window is free, whose status is `scheduled` or `guaranteed`, that are not blocked,
          that pass lead-time and advance rules, and that have
          `capacity − seats_sold >= pax` (when `pax` is supplied).
        - `mode: per_vessel` — each day lists `windows[]`: the free vessel windows the
          product's charter can fit into, honouring the turnaround buffer.
        - `mode: quote` — every day is `status: on_request`; there is nothing to sell directly.

        Holds count against capacity (`BRIEF.md` §5.4) and expired holds are treated as
        released on read even if the sweeper has not run yet, so this endpoint can never
        under-report availability because a queue is backlogged.

        **This is the p95 < 150 ms endpoint** (`BRIEF.md` §12). It is not paginated; the
        range is capped at 62 days instead.
      tags: [Availability]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - name: product
          in: query
          description: Product UUID.
          required: true
          schema: { type: string, format: uuid }
          example: 7c9e6679-7425-40de-944b-e07fc1f90ae7
        - name: from
          in: query
          description: First local date to report, inclusive, in the tenant's timezone.
          required: true
          schema: { type: string, format: date }
          example: "2026-07-01"
        - name: to
          in: query
          description: Last local date to report, inclusive. Must be >= `from` and at most 62 days after it.
          required: true
          schema: { type: string, format: date }
          example: "2026-07-31"
        - name: pax
          in: query
          description: |
            Number of capacity-counting passengers the guest intends to book. When supplied,
            days and departures that cannot seat them are returned with
            `status: sold_out` rather than omitted, so the calendar can grey them out.
          required: false
          schema: { type: integer, minimum: 1, maximum: 500 }
          example: 4
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: One entry per calendar date in the requested range.
          headers:
            Cache-Control: { $ref: '#/components/headers/CacheControl' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
            X-RateLimit-Remaining: { $ref: '#/components/headers/RateLimitRemaining' }
          content:
            application/json:
              schema:
                type: object
                required: [data, meta]
                properties:
                  data:
                    type: array
                    items: { $ref: '#/components/schemas/AvailabilityDay' }
                  meta:
                    type: object
                    required: [product_uuid, mode, timezone, currency, from, to]
                    properties:
                      product_uuid: { type: string, format: uuid }
                      mode: { type: string, enum: [per_seat, per_vessel, quote] }
                      timezone: { type: string, example: Europe/Athens }
                      currency: { type: string, example: EUR }
                      from: { type: string, format: date }
                      to: { type: string, format: date }
                      pax: { type: [integer, "null"] }
                      min_lead_time_hours: { type: [integer, "null"] }
                      max_advance_days: { type: [integer, "null"] }
              examples:
                perSeat:
                  $ref: '#/components/examples/AvailabilityPerSeatResponse'
                perVessel:
                  $ref: '#/components/examples/AvailabilityPerVesselResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/search:
    get:
      operationId: searchCatalogue
      summary: Search one operator's catalogue for a date and a party
      description: |
        The answer to *"what can I do on Saturday, for four people, leaving from Piraeus"*.

        `GET /availability` answers for **one** product, which is right for a product page
        and useless for a catalogue. This is the other question: which of an operator's
        trips can take this party on this date, what does it cost **them**, and when does
        the next one leave.

        **The price is for the party asked about, not a from-price.** `party_price_cents`
        is `pax` guests priced against the product's base age band on the resolved rate
        plan for that date. A grid showing "from €65" that becomes €162.50 at checkout is
        the search experience guests telephone to avoid. A party with children gets an
        exact figure from `POST /price-quote`, which prices every band.

        **A `quote` product is listed with no price at all** (BKG-24), as
        `availability: on_request`. A `per_vessel` charter carries the whole-boat price
        for the day.

        **Filters the operator has switched off are ignored, not honoured.** Which filters
        an operator exposes is their choice (`tenants.settings.search.filters`); a crafted
        query string cannot re-enable one, because a hidden filter that still works is a
        setting that only appears to exist. `meta.filters_enabled` says which were applied.

        This endpoint is **advisory**, exactly like `GET /availability` (ADR-0006): seats
        reported here may be stale by the time a guest posts, and the authoritative check
        is the locked write path.
      tags: [Availability]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - name: date
          in: query
          description: The local date to search, in the tenant's timezone.
          required: true
          schema: { type: string, format: date }
          example: "2026-07-18"
        - name: pax
          in: query
          description: |
            Party size, counted as capacity-taking guests. Priced against the product's
            base age band; omit for a party of one.
          required: false
          schema: { type: integer, minimum: 1, maximum: 500, default: 1 }
          example: 4
        - name: port
          in: query
          description: Meeting-point UUID. Ignored when the operator has switched the port filter off.
          required: false
          schema: { type: string, format: uuid }
        - name: type
          in: query
          description: Product category. Ignored when the operator has switched the type filter off.
          required: false
          schema:
            type: string
            enum: [shared_full_day, shared_half_day, private_full_day, private_half_day, sunset, custom]
        - name: duration_max
          in: query
          description: Longest acceptable trip, in minutes. Off by default; ignored unless the operator enabled it.
          required: false
          schema: { type: integer, minimum: 1, maximum: 10080 }
        - name: price_max
          in: query
          description: |
            Highest acceptable **party** price, in integer cents — compared against
            `party_price_cents`, not against a per-person figure. Off by default.
          required: false
          schema: { type: integer, minimum: 0 }
        - name: vessel
          in: query
          description: Vessel UUID. Off by default; ignored unless the operator enabled it.
          required: false
          schema: { type: string, format: uuid }
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: The trips that can take this party on this date, cheapest party price first.
          headers:
            Cache-Control: { $ref: '#/components/headers/CacheControl' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
            X-RateLimit-Remaining: { $ref: '#/components/headers/RateLimitRemaining' }
          content:
            application/json:
              schema:
                type: object
                required: [data, meta]
                properties:
                  data:
                    type: array
                    items: { $ref: '#/components/schemas/SearchResult' }
                  meta:
                    type: object
                    required: [date, pax, currency, timezone, filters_enabled, applied]
                    properties:
                      date: { type: string, format: date }
                      pax: { type: integer, example: 4 }
                      currency: { type: string, example: EUR }
                      timezone: { type: string, example: Europe/Athens }
                      filters_enabled:
                        type: array
                        description: The filters this operator exposes. Anything else in the query string was ignored.
                        items: { type: string, enum: [date, port, party, type, duration, price, vessel] }
                        example: [date, port, party, type]
                      applied:
                        type: object
                        description: The filters that actually shaped this result set, after the operator's settings were applied.
                        additionalProperties: true
                        example: { port: "7c9e6679-7425-40de-944b-e07fc1f90ae7" }
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/price-quote:
    post:
      operationId: createPriceQuote
      summary: Calculate a price server-side
      description: |
        The only way a client learns a price. Resolves the rate plan for the date
        (highest-priority season containing it, else the default plan), applies age-band
        multipliers or fixed prices, adds extras, applies a voucher, computes VAT and the
        deposit, and returns the full derivation as `lines[]`.

        This endpoint has **no side effects**: it does not hold a seat, does not create a
        booking and does not redeem a voucher. It is safe to call on every pax change.

        The returned `price_token` is a short-lived signed handle over the exact inputs and
        the resulting total. Passing it to `POST /api/v1/bookings` lets the server confirm
        the guest is booking the price they were shown; if the recomputed total differs the
        booking is rejected with `409 price_changed` instead of silently charging more.
      tags: [Pricing]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/PriceQuoteRequest' }
            examples:
              perSeat:
                $ref: '#/components/examples/PriceQuoteRequestPerSeat'
              perVessel:
                $ref: '#/components/examples/PriceQuoteRequestPerVessel'
      responses:
        '200':
          description: The computed price.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/PriceQuote' }
              examples:
                default:
                  $ref: '#/components/examples/PriceQuoteResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '415': { $ref: '#/components/responses/UnsupportedMediaType' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/vouchers/{code}:
    get:
      operationId: getVoucher
      summary: Check a voucher balance
      description: |
        Balance check for `/v/{code}` and for the widget's "have a voucher?" field.
        Returns the remaining balance and whether the voucher is currently redeemable.
        It never reveals which booking issued the voucher or who holds it.

        Rate-limited hard per IP (10/min) because a voucher code is short and human-typed;
        sustained failures from one address trip a 15-minute block.
      tags: [Vouchers]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - name: code
          in: path
          required: true
          description: The voucher code as printed, case-insensitive.
          schema: { type: string, minLength: 4, maxLength: 24, pattern: '^[A-Za-z0-9-]{4,24}$' }
          example: KAI-VOUCH-4F7K
          x-kaiki-credential: true
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: The voucher.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Voucher' }
              examples:
                default:
                  $ref: '#/components/examples/VoucherResponse'
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/bookings:
    post:
      operationId: createBooking
      summary: Create a draft booking and place a hold
      description: |
        Creates a `draft` booking, recomputes the price server-side, and places a hold:
        for `per_seat` it increments `departures.seats_sold` inside a locked transaction;
        for `per_vessel` it writes a provisional vessel block. The hold lasts
        `tenants.settings.booking.hold_minutes` (default 15) and the deadline is returned
        as `hold_expires_at`.

        For a `mode: quote` product this creates a `quote_requested` booking instead —
        no hold, no price, and the operator is notified to build a quote.

        The response includes the `manage_token`; the client must keep it (the widget stores
        it in `sessionStorage`, the hosted page in a signed cookie) because every later call
        about this booking needs it.

        Concurrency: two simultaneous requests for the last seat cannot both succeed
        (`BRIEF.md` §5.5). The loser receives `409 insufficient_capacity`.
      tags: [Bookings]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/IdempotencyKeyRequired'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/BookingCreateRequest' }
            examples:
              perSeat:
                $ref: '#/components/examples/BookingCreateRequestPerSeat'
      responses:
        '201':
          description: The draft booking, with the hold deadline and the manage token.
          headers:
            Location: { $ref: '#/components/headers/Location' }
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Idempotency-Replayed: { $ref: '#/components/headers/IdempotencyReplayed' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Booking' }
              examples:
                default:
                  $ref: '#/components/examples/BookingDraftResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '415': { $ref: '#/components/responses/UnsupportedMediaType' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/bookings/{uuid}:
    get:
      operationId: getBooking
      summary: Get a booking
      description: |
        Requires the booking's `manage_token` in `X-Kaiki-Guest-Token`. A publishable key
        alone is never sufficient — the UUID is an identifier, not a credential.

        Powers `/b/{manage_token}`: what was booked, what was paid, what is still owed,
        whether the guest may cancel and what refund the **policy snapshot** would produce
        if they cancelled right now, plus the ticket, guest-details and iCal links.
      tags: [Bookings]
      security:
        - GuestToken: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/BookingUuidPath'
        - $ref: '#/components/parameters/GuestTokenHeader'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: The booking.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Booking' }
              examples:
                confirmed:
                  $ref: '#/components/examples/BookingConfirmedResponse'
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '410': { $ref: '#/components/responses/Gone' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/bookings/{uuid}/checkout:
    post:
      operationId: startCheckout
      summary: Start checkout and get a gateway redirect URL
      description: |
        Creates a `payments` row and a gateway session with the **operator's own**
        credentials — Viva Smart Checkout — and returns the URL to
        redirect the guest to. Kaiki never handles card data and never touches guest money.

        `kind` selects what is being paid:
        - `full` — the whole `total_cents`.
        - `deposit` — `deposit_cents` from the price snapshot. Rejected with
          `422 deposit_not_available` when the rate plan defines no deposit, and
          `422 lead_guest_required` when the booking still has no lead guest or no recorded
          consent (ADR-0030) — send the guest to the booking's `checkout_url`, which is the
          form that asks.
        - `balance` — `balance_cents`. Requires the booking to be `confirmed` and a
          `manage_token`; a publishable key is rejected.

        Called on a `draft`, this transitions it to `pending_payment` and **re-arms the hold**
        for another `hold_minutes`, because the guest is now on the gateway's page and a
        redirect can legitimately take minutes.

        Confirmation happens **only** through the verified gateway webhook
        (`POST /webhooks/viva`, §7). No public endpoint can
        confirm a booking.
      tags: [Bookings]
      security:
        - GuestToken: []
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/BookingUuidPath'
        - $ref: '#/components/parameters/GuestTokenHeaderOptional'
        - $ref: '#/components/parameters/IdempotencyKeyRequired'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/CheckoutRequest' }
            examples:
              deposit:
                $ref: '#/components/examples/CheckoutRequestDeposit'
      responses:
        '201':
          description: A gateway checkout session. Redirect the guest to `redirect_url`.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Idempotency-Replayed: { $ref: '#/components/headers/IdempotencyReplayed' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/CheckoutSession' }
              examples:
                default:
                  $ref: '#/components/examples/CheckoutSessionResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '502': { $ref: '#/components/responses/BadGateway' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/bookings/{uuid}/cancel:
    post:
      operationId: cancelBooking
      summary: Cancel a booking within policy
      description: |
        Guest-initiated cancellation from `/b/{manage_token}`. The refund is computed from
        the booking's **`policy_snapshot`** — the policy as it stood when the guest paid —
        and never from the operator's current policy (`BRIEF.md` §5.9).

        Send `dry_run: true` first to show the guest exactly what they will get back before
        they confirm. A dry run has no side effects and returns the same
        `CancellationResult` shape with `"performed": false`.

        Availability is released immediately; the money refund is queued to the gateway and
        settles asynchronously, so `refund.status` is normally `pending` in this response
        and the booking sits in `cancelled` until the refund webhook moves it to `refunded`.

        Rejected with `409 booking_not_cancellable` when the operator has disabled guest
        cancellation, and with `409 cancellation_window_closed` when the deadline has passed.
      tags: [Bookings]
      security:
        - GuestToken: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/BookingUuidPath'
        - $ref: '#/components/parameters/GuestTokenHeader'
        - $ref: '#/components/parameters/IdempotencyKeyRequired'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/CancelRequest' }
            examples:
              dryRun:
                $ref: '#/components/examples/CancelRequestDryRun'
      responses:
        '200':
          description: The computed refund, and the cancelled booking when not a dry run.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Idempotency-Replayed: { $ref: '#/components/headers/IdempotencyReplayed' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/CancellationResult' }
              examples:
                default:
                  $ref: '#/components/examples/CancellationResultResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/enquiries:
    post:
      operationId: createEnquiry
      summary: Send an enquiry ("ask a question")
      description: |
        Creates an `enquiries` row and notifies the operator. Used by the widget's `enquiry`
        mount, the `[kaiki_enquiry]` shortcode, and as the call-to-action on `mode: quote`
        products where no price is shown.

        The most spam-exposed endpoint in the system: 5 requests per minute per IP, plus a
        honeypot field (`company_website`) that is never persisted — a request that fills it
        is answered `422 enquiry_rejected` with no row created and no notification sent.

        The response is deliberately thin. An enquiry is not a booking and has no guest
        token; the guest hears back by email.
      tags: [Enquiries]
      security:
        - PublishableKey: []
        - SecretKey: []
      parameters:
        - $ref: '#/components/parameters/IdempotencyKeyOptional'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/EnquiryCreateRequest' }
            examples:
              greek:
                $ref: '#/components/examples/EnquiryCreateRequestGreek'
      responses:
        '201':
          description: The enquiry was received.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Enquiry' }
              examples:
                default:
                  $ref: '#/components/examples/EnquiryResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '415': { $ref: '#/components/responses/UnsupportedMediaType' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/quotes/{token}:
    get:
      operationId: getQuote
      summary: View a quote
      description: |
        Powers `/q/{quote_token}`. The path segment is the credential.

        A quote is **not a hold** (`docs/data-model.md` §4.4): the boat is not reserved while
        the guest thinks about it. `can_accept` tells the client whether acceptance would
        currently succeed, and `accept_blocked_reason` says why not — expired, superseded,
        already decided, or the vessel window is no longer free.

        Expired and superseded quotes return `200` with the corresponding `status`, not an
        error, so the page can render "this quote was replaced by a newer one" instead of a
        dead end. The first successful view stamps `viewed_at`.
      tags: [Quotes]
      security:
        - GuestToken: []
      parameters:
        - $ref: '#/components/parameters/QuoteTokenPath'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: The quote, in any status.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Quote' }
              examples:
                sent:
                  $ref: '#/components/examples/QuoteResponse'
        '401': { $ref: '#/components/responses/Unauthorized' }
        '404': { $ref: '#/components/responses/NotFound' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/quotes/{token}/accept:
    post:
      operationId: acceptQuote
      summary: Accept a quote and start payment
      description: |
        Re-checks the vessel window under a lock, copies the quote totals onto the booking,
        freezes `price_snapshot` with `"source": "quote"`, arms a fresh hold, moves the
        booking to `pending_payment` and creates a gateway session.

        Acceptance can legitimately fail: because a quote holds nothing, the boat may have
        been sold in the meantime. That is `409 vessel_window_unavailable`, the quote stays
        `sent`, and the operator can re-quote another date.
      tags: [Quotes]
      security:
        - GuestToken: []
      parameters:
        - $ref: '#/components/parameters/QuoteTokenPath'
        - $ref: '#/components/parameters/IdempotencyKeyRequired'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: false
        content:
          application/json:
            schema: { $ref: '#/components/schemas/QuoteAcceptRequest' }
            examples:
              default:
                $ref: '#/components/examples/QuoteAcceptRequestExample'
      responses:
        '200':
          description: The quote was accepted; redirect the guest to `checkout.redirect_url`.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Idempotency-Replayed: { $ref: '#/components/headers/IdempotencyReplayed' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/QuoteAcceptResult' }
              examples:
                default:
                  $ref: '#/components/examples/QuoteAcceptResultResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '410': { $ref: '#/components/responses/Gone' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '502': { $ref: '#/components/responses/BadGateway' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/quotes/{token}/decline:
    post:
      operationId: declineQuote
      summary: Decline a quote
      description: |
        Marks the quote `declined`, cancels the parent booking and notifies the operator.
        The optional `reason` is stored verbatim as guest-written text and is never
        translated. Present so `/q/{token}` has a truthful "no thanks" button rather than
        leaving the operator to guess from silence.

        **OPEN — does declining belong in the v1 public API?** No accepted ADR covers it;
        this is a scope question for the product owner, not an architecture fork. It is in
        the `Quote` state machine (`docs/data-model.md` §4.4) as a guest-initiated
        transition, but the brief's endpoint list names only accept and view. Provisional
        default, in force: included, because the alternative is operators manually expiring
        dead quotes. Removing it later is a breaking change, so decide before M2 closes.
      tags: [Quotes]
      security:
        - GuestToken: []
      parameters:
        - $ref: '#/components/parameters/QuoteTokenPath'
        - $ref: '#/components/parameters/IdempotencyKeyOptional'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: false
        content:
          application/json:
            schema: { $ref: '#/components/schemas/QuoteDeclineRequest' }
            examples:
              default:
                $ref: '#/components/examples/QuoteDeclineRequestExample'
      responses:
        '200':
          description: The quote is now declined.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/Quote' }
              examples:
                default:
                  $ref: '#/components/examples/QuoteDeclinedResponse'
        '401': { $ref: '#/components/responses/Unauthorized' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '410': { $ref: '#/components/responses/Gone' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/guest-details/{token}:
    get:
      operationId: getGuestDetails
      summary: Get the passenger manifest form state
      description: |
        Powers `/g/{guest_details_token}`. Returns one row per passenger — including
        infants, who still need a manifest line — with whatever has already been filled in,
        plus which fields the operator requires, the deadline, and the charter agreement
        (ναυλοσύμφωνο) to be accepted for `per_vessel` bookings.

        `document_number` is **write-only**: it is stored encrypted and never returned.
        `document_number_masked` is returned instead so the guest can see that something is
        on file without the API becoming a way to read passport numbers back out.

        `GET` keeps working after the deadline so the guest can see *why* the form is closed;
        only `PUT` is refused.
      tags: [Guest details]
      security:
        - GuestToken: []
      parameters:
        - $ref: '#/components/parameters/GuestDetailsTokenPath'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      responses:
        '200':
          description: The manifest form state.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
            Content-Language: { $ref: '#/components/headers/ContentLanguage' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/GuestDetailsPage' }
              examples:
                default:
                  $ref: '#/components/examples/GuestDetailsPageResponse'
        '401': { $ref: '#/components/responses/Unauthorized' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }
    put:
      operationId: updateGuestDetails
      summary: Submit or update the passenger manifest
      description: |
        Full replacement of the manifest, one entry per passenger, matched by `position`.
        Partial saves are allowed — the guest can fill in three of five passengers, come back
        later and finish — so fields may be `null` until the final submit.

        `guest_details_status` becomes `complete` only when every passenger has the fields
        the operator requires; until then it stays `pending` and reminders keep going out at
        deadline −48 h and −24 h.

        For `per_vessel` bookings the charter agreement must be accepted before the manifest
        can complete. Acceptance is a checkbox plus the **server-captured** IP address and
        timestamp (`charter_agreements.guest_accepted_at`, `BRIEF.md` §10) — the client sends
        only `accepted: true` and the agreement `version`; it cannot supply its own IP or time.

        Refused with `409 guest_details_deadline_passed` after the deadline.
      tags: [Guest details]
      security:
        - GuestToken: []
      parameters:
        - $ref: '#/components/parameters/GuestDetailsTokenPath'
        - $ref: '#/components/parameters/IdempotencyKeyOptional'
        - $ref: '#/components/parameters/LocaleQuery'
        - $ref: '#/components/parameters/AcceptLanguageHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/GuestDetailsUpdateRequest' }
            examples:
              greek:
                $ref: '#/components/examples/GuestDetailsUpdateRequestGreek'
      responses:
        '200':
          description: The updated manifest form state.
          headers:
            Cache-Control: { $ref: '#/components/headers/NoStore' }
          content:
            application/json:
              schema:
                type: object
                required: [data]
                properties:
                  data: { $ref: '#/components/schemas/GuestDetailsPage' }
              examples:
                default:
                  $ref: '#/components/examples/GuestDetailsCompleteResponse'
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '404': { $ref: '#/components/responses/NotFound' }
        '409': { $ref: '#/components/responses/Conflict' }
        '415': { $ref: '#/components/responses/UnsupportedMediaType' }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

  /api/v1/sync/products:
    get:
      operationId: syncProducts
      summary: Incremental catalogue sync (server-to-server)
      description: |
        Secret-key-only feed for the WordPress plugin's `kaiki_trip` CPT sync
        (`BRIEF.md` §8). Returns the full product payload — including `draft`, `inactive`
        and `archived` products and soft-delete **tombstones** — ordered by
        `updated_at ASC, uuid ASC`, so a client can resume from a cursor.

        Incremental usage: store `meta.sync_cursor` from the last page and pass it as
        `updated_since` next time. Rows whose `deleted_at` is set carry
        `"tombstone": true` and only the fields needed to unpublish the CPT entry;
        the plugin must handle them or it will keep orphan pages indexed.

        Unlike `GET /products`, translatable fields are returned **unresolved**, as
        `{"el": …, "en": …}` objects, because the plugin creates one CPT entry per language
        for WPML/Polylang and needs both.

        `product` is always filled on a live row — the schema permits null, and the example
        below shows one, but a mirror needs the language-free facts (duration, capacity,
        images, the boat, the cancellation tiers) as well as the prose, and `translations`
        carries only the prose. It is absent on a tombstone.

        A publishable key is refused `403 secret_key_required` before the catalogue is
        queried, and an `updated_since` that cannot be parsed is `400 invalid_updated_since`
        rather than a silent full or empty sync.
      tags: [Sync]
      security:
        - SecretKey: []
      parameters:
        - name: updated_since
          in: query
          required: false
          description: Return only products modified at or after this instant. Use `meta.sync_cursor` from the previous run.
          schema: { type: string, format: date-time }
          example: "2026-08-20T11:04:00Z"
        - name: include_inactive
          in: query
          required: false
          description: Include `draft`, `inactive` and `archived` products. Defaults to true for this endpoint.
          schema: { type: boolean, default: true }
        - $ref: '#/components/parameters/CursorQuery'
        - $ref: '#/components/parameters/PerPageQuery'
        - $ref: '#/components/parameters/IfNoneMatchHeader'
      responses:
        '200':
          description: A page of full product payloads plus tombstones.
          headers:
            ETag: { $ref: '#/components/headers/ETag' }
            Cache-Control: { $ref: '#/components/headers/NoStore' }
          content:
            application/json:
              schema:
                type: object
                required: [data, pagination, meta]
                properties:
                  data:
                    type: array
                    items: { $ref: '#/components/schemas/ProductSyncItem' }
                  pagination: { $ref: '#/components/schemas/Pagination' }
                  meta:
                    type: object
                    required: [sync_cursor, timezone, default_locale, locales]
                    properties:
                      sync_cursor:
                        type: string
                        format: date-time
                        description: Pass as `updated_since` on the next run. Reflects the newest `updated_at` in this page.
                      timezone: { type: string, example: Europe/Athens }
                      default_locale: { type: string, enum: [el, en] }
                      locales:
                        type: array
                        items: { type: string, enum: [el, en] }
              examples:
                default:
                  $ref: '#/components/examples/SyncProductsResponse'
        '304': { $ref: '#/components/responses/NotModified' }
        '400': { $ref: '#/components/responses/BadRequest' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/TooManyRequests' }
        '500': { $ref: '#/components/responses/ServerError' }

components:

  securitySchemes:

    PublishableKey:
      type: apiKey
      in: header
      name: X-Kaiki-Key
      description: |
        Publishable key, `pk_live_…` or `pk_test_…`. Safe to embed in HTML; this is the value
        of `data-key` on the widget script tag and of the "Publishable key" field in the
        WordPress plugin settings.

        Grants read access to public catalogue data plus the ability to create a draft
        booking, create an enquiry and start checkout for a draft it created. It can never
        list bookings, read guest personal data, read financials or mutate the catalogue.

        Origin-restricted per key (`api_keys.allowed_origins`); a request from an unlisted
        origin is rejected `403 origin_not_allowed` and its CORS preflight is answered
        without `Access-Control-Allow-*` headers.

        Revoked -> `401 api_key_revoked`. Expired -> `401 api_key_expired`.
        Missing scope -> `403 insufficient_scope`.

    SecretKey:
      type: http
      scheme: bearer
      bearerFormat: sk_live_xxx
      description: |
        Secret key, `sk_live_…` or `sk_test_…`, sent as `Authorization: Bearer sk_live_…`.
        **Server-to-server only.** Any request presenting a secret key together with an
        `Origin` header is rejected `403 secret_key_in_browser`, so accidental browser use
        fails on the first call. CI greps the widget bundle and the WordPress plugin zip for
        `sk_(live|test)_` and fails the build on a hit.

        In v1 the only endpoint that requires a secret key is `GET /api/v1/sync/products`.

    GuestToken:
      type: apiKey
      in: header
      name: X-Kaiki-Guest-Token
      description: |
        Per-booking guest credential: `bookings.manage_token`, `bookings.guest_details_token`
        or `quotes.quote_token`. Forty URL-safe characters, globally unique, tied to exactly
        one booking. No account, no password, no tenant-wide access.

        Two transports. For `/api/v1/bookings/{uuid}/…` the token goes in this header and the
        path carries the booking UUID. For `/api/v1/quotes/{token}`,
        `/api/v1/guest-details/{token}` and `/api/v1/vouchers/{code}` the **path segment is
        the credential** — OpenAPI cannot declare a path-borne credential, so those path
        parameters are marked `x-kaiki-credential: true` and this header is accepted as an
        equivalent alternative.

        Invalid -> `401 invalid_guest_token`. No longer usable -> `410 guest_token_expired`.

  parameters:

    LocaleQuery:
      name: locale
      in: query
      required: false
      description: |
        Explicit locale override. Beats `Accept-Language` and the tenant default.
        An unsupported value is `400 unsupported_locale` — a typo must not silently serve
        the wrong language.
      schema: { type: string, enum: [el, en] }
      example: el

    AcceptLanguageHeader:
      name: Accept-Language
      in: header
      required: false
      description: |
        Standard language negotiation, parsed with quality values and matched against
        `el` and `en`. Used when `?locale=` is absent. An unmatched value is not an error;
        it falls through to the tenant's `default_locale`.
      schema: { type: string }
      example: el-GR,el;q=0.9,en;q=0.8

    IfNoneMatchHeader:
      name: If-None-Match
      in: header
      required: false
      description: Conditional request. Returns `304 Not Modified` when the `ETag` matches.
      schema: { type: string }
      example: '"a1b2c3d4e5f6"'

    IdempotencyKeyRequired:
      name: Idempotency-Key
      in: header
      required: true
      description: |
        Client-generated UUIDv4. Required on this operation because it creates a booking or
        moves money. Replay with the same key and the same body returns the recorded response
        verbatim with `Idempotency-Replayed: true`; the same key with a different body is
        `409 idempotency_key_reuse`. Keys are retained 24 hours.
      schema: { type: string, format: uuid }
      example: 8f14e45f-ceea-4e0b-9c1a-4f8b2d3e5a90

    IdempotencyKeyOptional:
      name: Idempotency-Key
      in: header
      required: false
      description: Optional but recommended. Same replay semantics as the required form.
      schema: { type: string, format: uuid }
      example: 8f14e45f-ceea-4e0b-9c1a-4f8b2d3e5a90

    CursorQuery:
      name: cursor
      in: query
      required: false
      description: |
        Opaque pagination cursor from `pagination.next_cursor`. Copy it back verbatim; never
        construct, parse or persist one beyond the current traversal. Malformed or stale ->
        `400 invalid_cursor`.
      schema: { type: string, maxLength: 512 }
      example: eyJpZCI6MTQ0LCJzIjoxMH0

    PerPageQuery:
      name: per_page
      in: query
      required: false
      description: Items per page. Values above the maximum are clamped, not rejected.
      schema: { type: integer, minimum: 1, maximum: 100, default: 24 }
      example: 24

    ProductIdentifierPath:
      name: uuid
      in: path
      required: true
      description: The product `uuid`, or its tenant-unique `slug`.
      schema: { type: string, maxLength: 120 }
      example: 7c9e6679-7425-40de-944b-e07fc1f90ae7

    BookingUuidPath:
      name: uuid
      in: path
      required: true
      description: The booking `uuid`. An identifier, never a credential — the `manage_token` authorises.
      schema: { type: string, format: uuid }
      example: 1b4e28ba-2fa1-11d2-883f-0016d3cca427

    GuestTokenHeader:
      name: X-Kaiki-Guest-Token
      in: header
      required: true
      description: The booking's `manage_token`.
      schema: { type: string, minLength: 40, maxLength: 40 }
      example: 9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9

    GuestTokenHeaderOptional:
      name: X-Kaiki-Guest-Token
      in: header
      required: false
      description: |
        The booking's `manage_token`. Optional only while the booking is still `draft` with a
        live hold and the caller presents the publishable key that created it. Required for
        `kind: balance` and for any `confirmed` booking.
      schema: { type: string, minLength: 40, maxLength: 40 }
      example: 9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9

    QuoteTokenPath:
      name: token
      in: path
      required: true
      description: The `quotes.quote_token` from the `/q/{token}` link. **This path segment is the credential.**
      schema: { type: string, minLength: 40, maxLength: 40 }
      example: 4c8a1f9e2b7d0356ea41c9f8b2d5e70a3c6b1d94
      x-kaiki-credential: true

    GuestDetailsTokenPath:
      name: token
      in: path
      required: true
      description: The `bookings.guest_details_token` from the `/g/{token}` link. **This path segment is the credential.**
      schema: { type: string, minLength: 40, maxLength: 40 }
      example: b7e3d1a9c5f204867e1b3d5a9c7f0e2b4d6a8c10
      x-kaiki-credential: true

  headers:

    ETag:
      description: Strong entity tag for conditional requests.
      schema: { type: string }
      example: '"a1b2c3d4e5f6"'

    CacheControl:
      description: Cacheability of this response. Catalogue reads are publicly cacheable for 30-60 s.
      schema: { type: string }
      example: public, max-age=60

    NoStore:
      description: Always `no-store` on guest-specific and money-moving responses.
      schema: { type: string }
      example: no-store

    ContentLanguage:
      description: The locale every translatable field in this response was resolved to.
      schema: { type: string, enum: [el, en] }
      example: el

    Location:
      description: Canonical URL of the created resource.
      schema: { type: string, format: uri }
      example: https://api.kaiki.app/api/v1/bookings/1b4e28ba-2fa1-11d2-883f-0016d3cca427

    IdempotencyReplayed:
      description: Present and `true` when this response was replayed from the idempotency record rather than freshly computed.
      schema: { type: boolean }
      example: true

    RateLimitLimit:
      description: Requests permitted per window in the bucket closest to exhaustion.
      schema: { type: integer }
      example: 1200

    RateLimitRemaining:
      description: Requests left in that bucket.
      schema: { type: integer }
      example: 1187

    RateLimitReset:
      description: Unix epoch seconds at which that bucket refills.
      schema: { type: integer }
      example: 1785312060

    RetryAfter:
      description: Seconds to wait before retrying. Sent on `429` and on `409 idempotency_in_progress`.
      schema: { type: integer }
      example: 12

    RequestId:
      description: Correlation id. Quote it in support tickets; it is the Sentry tag too.
      schema: { type: string }
      example: 01JZ8Q3M7K5V2N9X4T6B8W1Y0R

  responses:

    NotModified:
      description: The representation has not changed since the supplied `ETag`.
      headers:
        ETag: { $ref: '#/components/headers/ETag' }
        Cache-Control: { $ref: '#/components/headers/CacheControl' }

    BadRequest:
      description: Malformed request — unsupported locale, invalid cursor, unparseable JSON.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            unsupportedLocale:
              summary: unsupported_locale
              value:
                error:
                  code: unsupported_locale
                  message: That language is not supported.
                  message_el: Αυτή η γλώσσα δεν υποστηρίζεται.
                  details:
                    requested: fr
                    supported: [el, en]

    Unauthorized:
      description: Missing or invalid credential.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            missing:
              summary: unauthenticated
              value:
                error:
                  code: unauthenticated
                  message: API key or guest token required.
                  message_el: Απαιτείται κλειδί API ή σύνδεσμος πρόσβασης.
            revoked:
              summary: api_key_revoked
              value:
                error:
                  code: api_key_revoked
                  message: This API key has been revoked.
                  message_el: Αυτό το κλειδί API έχει ανακληθεί.
                  details:
                    revoked_at: "2026-08-14T09:12:00Z"
            badToken:
              summary: invalid_guest_token
              value:
                error:
                  code: invalid_guest_token
                  message: This link is not valid.
                  message_el: Αυτός ο σύνδεσμος δεν είναι έγκυρος.

    Forbidden:
      description: Valid credential without the capability, or a disallowed origin.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            origin:
              summary: origin_not_allowed
              value:
                error:
                  code: origin_not_allowed
                  message: This website is not authorised to use this key.
                  message_el: Αυτός ο ιστότοπος δεν έχει εξουσιοδότηση για αυτό το κλειδί.
                  details:
                    origin: https://staging.aegeancruises.gr
                    hint: Add this origin under Settings > API keys > Allowed origins.
            scope:
              summary: insufficient_scope
              value:
                error:
                  code: insufficient_scope
                  message: This key is not allowed to perform this action.
                  message_el: Αυτό το κλειδί δεν επιτρέπεται να εκτελέσει αυτή την ενέργεια.
                  details:
                    required_scope: bookings.write
                    key_type: publishable
            secretInBrowser:
              summary: secret_key_in_browser
              value:
                error:
                  code: secret_key_in_browser
                  message: A secret key cannot be used from a browser. Use a publishable key.
                  message_el: Το μυστικό κλειδί δεν μπορεί να χρησιμοποιηθεί από φυλλομετρητή. Χρησιμοποιήστε δημόσιο κλειδί.
                  details:
                    origin: https://aegeancruises.gr

    NotFound:
      description: No such resource for this tenant. Never discloses existence in another tenant.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            default:
              summary: not_found
              value:
                error:
                  code: not_found
                  message: Not found.
                  message_el: Δεν βρέθηκε.

    Conflict:
      description: The request is well formed but conflicts with current state.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
        Retry-After: { $ref: '#/components/headers/RetryAfter' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            capacity:
              summary: insufficient_capacity
              value:
                error:
                  code: insufficient_capacity
                  message: Only 2 seats remain on this departure.
                  message_el: Απομένουν μόνο 2 θέσεις σε αυτή την αναχώρηση.
                  details:
                    departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
                    seats_requested: 4
                    seats_available: 2
            holdExpired:
              summary: hold_expired
              value:
                error:
                  code: hold_expired
                  message: Your reservation hold expired. Please start again.
                  message_el: Ο χρόνος κράτησης έληξε. Ξεκινήστε ξανά.
                  details:
                    booking_uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
                    hold_expires_at: "2026-07-14T05:52:11Z"
            priceChanged:
              summary: price_changed
              value:
                error:
                  code: price_changed
                  message: The price has changed. Please review the new price.
                  message_el: Η τιμή άλλαξε. Ελέγξτε τη νέα τιμή.
                  details:
                    previous_total_cents: 14250
                    total_cents: 15100
                    currency: EUR

    Gone:
      description: The resource existed and is permanently no longer usable.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            quoteExpired:
              summary: quote_expired
              value:
                error:
                  code: quote_expired
                  message: This quote has expired.
                  message_el: Αυτή η προσφορά έχει λήξει.
                  details:
                    valid_until: "2026-07-01T20:59:59Z"

    UnsupportedMediaType:
      description: The body was not JSON.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            default:
              summary: unsupported_media_type
              value:
                error:
                  code: unsupported_media_type
                  message: Requests must be sent as JSON.
                  message_el: Τα αιτήματα πρέπει να αποστέλλονται σε μορφή JSON.
                  details:
                    received: application/x-www-form-urlencoded

    UnprocessableEntity:
      description: Semantically invalid — failed validation or a business rule.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            validation:
              summary: validation_failed
              value:
                error:
                  code: validation_failed
                  message: Please check the details you entered.
                  message_el: Ελέγξτε τα στοιχεία που συμπληρώσατε.
                  details:
                    fields:
                      guest.email:
                        code: email
                        message: Enter a valid email address.
                        message_el: Δώστε μια έγκυρη διεύθυνση email.
            leadTime:
              summary: lead_time_violation
              value:
                error:
                  code: lead_time_violation
                  message: Bookings must be made at least 12 hours before departure.
                  message_el: Οι κρατήσεις γίνονται τουλάχιστον 12 ώρες πριν την αναχώρηση.
                  details:
                    min_lead_time_hours: 12
                    starts_at: "2026-07-14T06:30:00Z"

    TooManyRequests:
      description: Rate limit exceeded for this key, token or IP.
      headers:
        Retry-After: { $ref: '#/components/headers/RetryAfter' }
        X-RateLimit-Limit: { $ref: '#/components/headers/RateLimitLimit' }
        X-RateLimit-Remaining: { $ref: '#/components/headers/RateLimitRemaining' }
        X-RateLimit-Reset: { $ref: '#/components/headers/RateLimitReset' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            default:
              summary: rate_limited
              value:
                error:
                  code: rate_limited
                  message: Too many requests. Please try again shortly.
                  message_el: Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε λίγο.
                  details:
                    retry_after_seconds: 12
                    limit: 5
                    scope: ip

    BadGateway:
      description: The operator's payment gateway is unreachable or errored.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            default:
              summary: gateway_unavailable
              value:
                error:
                  code: gateway_unavailable
                  message: The payment system is unavailable right now. Please try again shortly.
                  message_el: Το σύστημα πληρωμών δεν είναι διαθέσιμο αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο.
                  details:
                    gateway: viva

    ServerError:
      description: Unexpected failure. The exception is in Sentry, tagged with the tenant; the client gets a request id.
      headers:
        X-Request-Id: { $ref: '#/components/headers/RequestId' }
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
          examples:
            default:
              summary: server_error
              value:
                error:
                  code: server_error
                  message: Something went wrong. Please try again.
                  message_el: Παρουσιάστηκε σφάλμα. Δοκιμάστε ξανά.
                  details:
                    request_id: 01JZ8Q3M7K5V2N9X4T6B8W1Y0R

  schemas:

    # ---------- Common ----------

    ErrorObject:
      type: object
      description: The body of an error. `code` is the stable contract; branch on it, never on `message`.
      required: [code, message, message_el]
      properties:
        code:
          type: string
          pattern: '^[a-z][a-z0-9_]*$'
          description: Stable machine-readable reason. See the code table in this document.
          example: insufficient_capacity
        message:
          type: string
          description: English, plain, guest-safe. Never contains internals.
          example: Only 2 seats remain on this departure.
        message_el:
          type: string
          description: Greek, same meaning and register. The client picks which to render.
          example: Απομένουν μόνο 2 θέσεις σε αυτή την αναχώρηση.
        details:
          type: object
          additionalProperties: true
          description: Machine-readable context; shape varies by `code`. Absent rather than null when empty.
      additionalProperties: false

    Error:
      type: object
      description: The single error envelope used by every failing response on every endpoint.
      required: [error]
      properties:
        error: { $ref: '#/components/schemas/ErrorObject' }
      additionalProperties: false

    Pagination:
      type: object
      description: Cursor pagination metadata. There is deliberately no total count.
      required: [per_page, has_more, next_cursor, prev_cursor]
      properties:
        per_page: { type: integer, minimum: 1, maximum: 100, example: 24 }
        has_more:
          type: boolean
          description: Authoritative. `next_cursor` is null exactly when this is false.
          example: true
        next_cursor: { type: [string, "null"], example: eyJpZCI6MTQ0LCJzIjoxMH0 }
        prev_cursor: { type: [string, "null"], example: null }
        next_url: { type: [string, "null"], format: uri }
      additionalProperties: false

    TenantSummary:
      type: object
      description: The operator, as much of it as a guest may see. No `tenant_id`, no legal or fiscal data.
      required: [uuid, name, slug, timezone, default_locale, currency]
      properties:
        uuid: { type: string, format: uuid }
        name: { type: string, example: Aegean Cruises }
        slug: { type: string, example: aegean-cruises }
        timezone: { type: string, example: Europe/Athens }
        default_locale: { type: string, enum: [el, en] }
        currency: { type: string, enum: [EUR] }
        hosted_page_url: { type: [string, "null"], format: uri, example: 'https://book.kaiki.app/aegean-cruises' }
        support_email: { type: [string, "null"], format: email }
        support_phone: { type: [string, "null"] }
      additionalProperties: false

    # ---------- Branding ----------

    BrandingLogo:
      type: object
      required: [light_url, dark_url, favicon_url]
      properties:
        light_url: { type: [string, "null"], format: uri }
        dark_url: { type: [string, "null"], format: uri }
        favicon_url: { type: [string, "null"], format: uri }
      additionalProperties: false

    BrandingColors:
      type: object
      description: Hex `#RRGGBB`, validated server-side. Rendered by the widget as CSS custom properties.
      required: [primary, secondary, accent, background, text]
      properties:
        primary: { type: string, pattern: '^#[0-9A-Fa-f]{6}$', example: '#0B4F4A' }
        secondary: { type: string, pattern: '^#[0-9A-Fa-f]{6}$', example: '#063733' }
        accent: { type: string, pattern: '^#[0-9A-Fa-f]{6}$', example: '#B5511F' }
        background: { type: string, pattern: '^#[0-9A-Fa-f]{6}$', example: '#FFFFFF' }
        text: { type: string, pattern: '^#[0-9A-Fa-f]{6}$', example: '#16211F' }
      additionalProperties: false

    BrandingFont:
      type: object
      required: [family, source, css_url]
      properties:
        family: { type: string, example: Inter }
        source:
          type: string
          enum: [system, google]
          description: The widget loads a Google Fonts stylesheet only when this is `google`.
        css_url:
          type: [string, "null"]
          format: uri
          description: Non-null only when `source` is `google`.
      additionalProperties: false

    Branding:
      type: object
      description: The tenant's `BrandProfile`, shaped for direct application as CSS custom properties.
      required: [tenant, logo, colors, font, button_radius_px, widget_theme, css_variables, is_test]
      properties:
        tenant: { $ref: '#/components/schemas/TenantSummary' }
        logo: { $ref: '#/components/schemas/BrandingLogo' }
        colors: { $ref: '#/components/schemas/BrandingColors' }
        font: { $ref: '#/components/schemas/BrandingFont' }
        button_radius_px: { type: integer, minimum: 0, maximum: 32, example: 8 }
        widget_theme: { type: string, enum: [light, dark, auto] }
        css_variables:
          type: object
          description: |
            Ready-to-apply custom properties. The widget writes these onto its Shadow DOM
            root and never hardcodes a colour. Keys are stable; new keys may be added.
          additionalProperties: { type: string }
          example:
            --kaiki-primary: '#0B4F4A'
            --kaiki-secondary: '#063733'
            --kaiki-accent: '#B5511F'
            --kaiki-background: '#FFFFFF'
            --kaiki-text: '#16211F'
            --kaiki-radius: 8px
            --kaiki-font-family: "Inter, system-ui, sans-serif"
        social_links:
          type: object
          additionalProperties: { type: string, format: uri }
          example: { instagram: 'https://instagram.com/aegeancruises' }
        email_footer_text:
          type: [string, "null"]
          description: Resolved to the negotiated locale.
        custom_css:
          type: [string, "null"]
          description: |
            Sanitised operator CSS. Returned **only** for the hosted page and verified custom
            domains; always `null` for a third-party embed, because injecting operator CSS
            into someone else's site is not ours to do.
        terms_url: { type: [string, "null"], format: uri }
        privacy_url: { type: [string, "null"], format: uri }
        is_test:
          type: boolean
          description: True when the request was authenticated with a test key or the tenant is in sandbox mode.
      additionalProperties: false

    # ---------- Catalog ----------

    VesselSummary:
      type: object
      description: The boat, as a guest sees it. Never exposes the internal id or the home-port address.
      required: [uuid, name, type, capacity_max]
      properties:
        uuid: { type: string, format: uuid }
        name: { type: string, example: Meltemi }
        type:
          type: string
          enum: [catamaran, sailing_yacht, motor, rib, traditional_kaiki]
          example: traditional_kaiki
        capacity_max: { type: integer, minimum: 1, example: 32 }
        length_m: { type: [number, "null"], example: 18.5 }
        crew_count: { type: [integer, "null"], example: 3 }
        description: { type: [string, "null"], description: Resolved to the negotiated locale. }
        images:
          type: array
          items: { $ref: '#/components/schemas/ProductImage' }
        specs:
          type: object
          additionalProperties: true
          description: Free-form operator-entered specifications; render as a definition list.
      additionalProperties: false

    ProductImage:
      type: object
      required: [url, alt]
      properties:
        url: { type: string, format: uri }
        alt: { type: [string, "null"], description: Resolved to the negotiated locale. }
        width: { type: [integer, "null"] }
        height: { type: [integer, "null"] }
      additionalProperties: false

    MeetingPoint:
      type: object
      description: A `ports` row used as a product's meeting point.
      required: [uuid, name]
      properties:
        uuid: { type: string, format: uuid }
        name: { type: string, example: Μαρίνα Ζέας }
        address: { type: [string, "null"], example: 'Ακτή Θεμιστοκλέους, Πειραιάς 185 36' }
        lat: { type: [number, "null"], example: 37.9339 }
        lng: { type: [number, "null"], example: 23.6512 }
        instructions:
          type: [string, "null"]
          description: Resolved to the negotiated locale, e.g. "meet at the blue kiosk".
        photo_url: { type: [string, "null"], format: uri }
        maps_url:
          type: [string, "null"]
          format: uri
          description: Operator override, else a generated link from `lat`/`lng` or `address`.
      additionalProperties: false

    ItineraryStop:
      type: object
      description: |
        One stop on the route. Labels come from the translatable array; coordinates come from
        the locale-independent `_geo` sidecar, so the Greek and English versions of a stop can
        never disagree about where it is.
      required: [key, name]
      properties:
        key: { type: string, maxLength: 8, example: s2 }
        name: { type: string, example: Όρμος Βλυχάδα }
        description: { type: [string, "null"], example: Κολύμπι και σνόρκελ. }
        duration_minutes: { type: [integer, "null"], example: 60 }
        lat: { type: [number, "null"], example: 37.6721 }
        lng: { type: [number, "null"], example: 23.4410 }
      additionalProperties: false

    AgeBand:
      type: object
      description: |
        A passenger category for one product. `counts_toward_capacity` is the field that
        decides whether a passenger occupies a seat — infants typically do not.
        `from_price_cents` is **advisory** display pricing only; the binding price always
        comes from `POST /price-quote`.
      required: [uuid, code, label, min_age, max_age, counts_toward_capacity, requires_adult, is_base, sort_order]
      properties:
        uuid: { type: string, format: uuid }
        code:
          type: string
          maxLength: 24
          description: Stable machine key (`adult`, `child`, `infant`). Snapshots group by this.
          example: child
        label: { type: string, description: Resolved to the negotiated locale., example: Παιδί }
        min_age: { type: integer, minimum: 0, example: 3 }
        max_age: { type: [integer, "null"], example: 11 }
        counts_toward_capacity: { type: boolean, example: true }
        requires_adult:
          type: boolean
          description: Children and infants cannot travel alone.
          example: true
        is_base:
          type: boolean
          description: Exactly one band per product is the base (adult) band that prices anchor to.
          example: false
        sort_order: { type: integer, example: 20 }
        from_price_cents:
          type: [integer, "null"]
          description: "Advisory lowest price for this band across active rate plans. Null for `mode: quote`."
          example: 3250
        currency: { type: string, enum: [EUR] }
      additionalProperties: false

    Extra:
      type: object
      description: |
        An add-on. `pricing_type: on_request` carries no price, never enters a total, and is
        surfaced to the operator as a to-do on the booking.
      required: [uuid, name, pricing_type, is_required, sort_order]
      properties:
        uuid: { type: string, format: uuid }
        name: { type: string, example: Μεταφορά από ξενοδοχείο }
        description: { type: [string, "null"] }
        pricing_type: { type: string, enum: [per_booking, per_person, on_request] }
        price_cents:
          type: [integer, "null"]
          description: Null when `pricing_type` is `on_request`. Reflects the per-product override when one exists.
          example: 1000
        currency: { type: string, enum: [EUR] }
        max_qty: { type: [integer, "null"], description: Null means unlimited., example: 8 }
        is_required: { type: boolean, example: false }
        image_url: { type: [string, "null"], format: uri }
        sort_order: { type: integer, example: 10 }
      additionalProperties: false

    PolicyTier:
      type: object
      required: [days_before, refund_percent]
      properties:
        days_before: { type: integer, minimum: 0, example: 7 }
        refund_percent: { type: integer, minimum: 0, maximum: 100, example: 50 }
      additionalProperties: false

    CancellationPolicySummary:
      type: object
      description: |
        The guest-facing cancellation terms. On a `Booking` this is rendered from the
        booking's frozen `policy_snapshot`, never from the operator's current policy.
        Evaluation: `free_cancellation_hours` wins outright if it applies; otherwise take the
        tier with the largest `days_before` that is <= the days remaining; if none matches,
        the refund is 0%.
      required: [name, tiers, weather_refund_percent, force_majeure_voucher_months, no_show_refund_percent]
      properties:
        name: { type: string, example: Ευέλικτη }
        summary: { type: [string, "null"], example: Δωρεάν ακύρωση έως 48 ώρες πριν. }
        free_cancellation_hours: { type: [integer, "null"], example: 48 }
        tiers:
          type: array
          description: Sorted by `days_before` descending.
          items: { $ref: '#/components/schemas/PolicyTier' }
        weather_refund_percent: { type: integer, minimum: 0, maximum: 100, example: 100 }
        force_majeure_voucher_months: { type: integer, example: 18 }
        no_show_refund_percent: { type: integer, minimum: 0, maximum: 100, example: 0 }
        captured_at:
          type: [string, "null"]
          format: date-time
          description: Present only on a booking's snapshot; null on the live catalogue policy.
      additionalProperties: false

    BookingWindow:
      type: object
      description: |
        When this product may be booked, projected from its active rate plans.
        **OPEN — how a seasonal pair is projected to one product-level pair.** No accepted
        ADR covers it; it is a presentation choice, not an architecture fork.
        `min_lead_time_hours` and `max_advance_days` live on `rate_plans`, which vary by
        season, so a single product-level pair is necessarily a projection. Provisional
        default, in force: the **strictest** value across active plans (largest lead time,
        smallest advance window), so a client never offers a date the booking endpoint
        rejects. The authoritative per-date answer is always `GET /availability`.
      required: [min_lead_time_hours, max_advance_days]
      properties:
        min_lead_time_hours: { type: [integer, "null"], example: 12 }
        max_advance_days: { type: [integer, "null"], example: 365 }
      additionalProperties: false

    ProductSeo:
      type: object
      required: [meta_title, meta_description, og_image_url, canonical_url]
      properties:
        meta_title: { type: [string, "null"] }
        meta_description: { type: [string, "null"] }
        og_image_url: { type: [string, "null"], format: uri }
        canonical_url: { type: [string, "null"], format: uri }
      additionalProperties: false

    ProductSummary:
      type: object
      description: |
        List-shaped product for the `list` mount, `[kaiki_list]` and the hosted landing page.
        `from_price_cents` is the cheapest capacity-counting adult price across active rate
        plans; it is null for `mode: quote`, which never shows a price.
      required: [uuid, slug, title, category, mode, duration_minutes, currency, from_price_cents, is_featured, sort_order, updated_at]
      properties:
        uuid: { type: string, format: uuid }
        slug: { type: string, example: sunset-cruise-aegina }
        title: { type: string, example: Ηλιοβασίλεμα στην Αίγινα }
        summary: { type: [string, "null"], example: Τρίωρη κρουαζιέρα με παραδοσιακό καΐκι. }
        category:
          type: string
          enum: [shared_full_day, shared_half_day, private_full_day, private_half_day, sunset, custom]
        mode: { type: string, enum: [per_seat, per_vessel, quote] }
        duration_minutes: { type: integer, example: 180 }
        default_start_time: { type: [string, "null"], pattern: '^[0-2][0-9]:[0-5][0-9]$', example: "18:30" }
        flexible_start: { type: boolean, default: false }
        from_price_cents: { type: [integer, "null"], example: 6500 }
        from_price_formatted:
          type: [string, "null"]
          description: Formatted in the negotiated locale. A convenience, never the source of truth.
          example: 65,00 €
        currency: { type: string, enum: [EUR] }
        hero_image_url: { type: [string, "null"], format: uri }
        vessel:
          oneOf:
            - $ref: '#/components/schemas/VesselSummary'
            - type: "null"
        meeting_point:
          oneOf:
            - $ref: '#/components/schemas/MeetingPoint'
            - type: "null"
        min_booking_pax: { type: integer, example: 1 }
        max_pax: { type: integer, example: 24 }
        is_featured: { type: boolean }
        sort_order: { type: integer }
        booking_url:
          type: [string, "null"]
          format: uri
          description: The hosted page for this product, when the tenant has hosted pages enabled.
        updated_at: { type: string, format: date-time }
      additionalProperties: false

    Product:
      allOf:
        - $ref: '#/components/schemas/ProductSummary'
        - type: object
          description: The full product. Every translatable field is already resolved to one locale.
          required: [description, includes, excludes, what_to_bring, itinerary_stops, images, age_bands, extras, cancellation_policy, check_in_offset_minutes, guest_details_required, booking_window, seo]
          properties:
            description:
              type: [string, "null"]
              description: Sanitised HTML. Render with the tenant's content, never with `innerHTML` of untrusted input.
            includes:
              type: array
              items: { type: string }
              example: [Γεύμα και ποτά, Εξοπλισμός κολύμβησης, Ασφάλεια επιβατών]
            excludes:
              type: array
              items: { type: string }
            what_to_bring:
              type: array
              items: { type: string }
            itinerary_stops:
              type: array
              items: { $ref: '#/components/schemas/ItineraryStop' }
            route_map_image_url: { type: [string, "null"], format: uri }
            images:
              type: array
              items: { $ref: '#/components/schemas/ProductImage' }
            age_bands:
              type: array
              items: { $ref: '#/components/schemas/AgeBand' }
            extras:
              type: array
              items: { $ref: '#/components/schemas/Extra' }
            cancellation_policy:
              oneOf:
                - $ref: '#/components/schemas/CancellationPolicySummary'
                - type: "null"
            check_in_offset_minutes:
              type: integer
              description: Check-in time is the departure start minus this many minutes.
              example: 30
            earliest_start_time: { type: [string, "null"], example: "09:00" }
            latest_start_time: { type: [string, "null"], example: "14:00" }
            min_pax:
              type: integer
              description: Guaranteed-departure threshold for `per_seat`. Zero means always guaranteed.
              example: 6
            guest_details_required: { type: boolean }
            guest_details_deadline_hours: { type: integer, example: 48 }
            booking_window: { $ref: '#/components/schemas/BookingWindow' }
            seo: { $ref: '#/components/schemas/ProductSeo' }
            timezone: { type: string, example: Europe/Athens }

    ProductSyncItem:
      type: object
      description: |
        Server-to-server product payload for the WordPress SEO CPT sync. Differs from
        `Product` in three ways: translatable fields are **unresolved** (`{"el":…,"en":…}`),
        non-`active` statuses are included, and soft-deleted products appear as tombstones.
      required: [uuid, slug, status, updated_at, tombstone]
      properties:
        uuid: { type: string, format: uuid }
        slug: { type: string }
        status: { type: string, enum: [draft, active, inactive, archived] }
        mode: { type: string, enum: [per_seat, per_vessel, quote] }
        category: { type: string }
        tombstone:
          type: boolean
          description: True when the product was soft-deleted. Unpublish the CPT entry; other fields may be absent.
        deleted_at: { type: [string, "null"], format: date-time }
        updated_at: { type: string, format: date-time }
        content_hash:
          type: [string, "null"]
          description: Stable hash of the translatable content; skip the CPT write when unchanged.
        translations:
          type: object
          description: Unresolved translatable fields, keyed by locale.
          additionalProperties:
            type: object
            properties:
              title: { type: string }
              summary: { type: [string, "null"] }
              description: { type: [string, "null"] }
              includes: { type: array, items: { type: string } }
              excludes: { type: array, items: { type: string } }
              what_to_bring: { type: array, items: { type: string } }
              meta_title: { type: [string, "null"] }
              meta_description: { type: [string, "null"] }
        product:
          description: The same shape as `Product`, with translatable fields resolved to the tenant default locale, for clients that do not need both languages.
          oneOf:
            - $ref: '#/components/schemas/Product'
            - type: "null"
      additionalProperties: false

    # ---------- Availability ----------

    LocalWindow:
      type: object
      description: |
        A time window, given both ways so the client never converts a timezone.
        Render `local_date` and `local_time` verbatim; use `starts_at` only for countdowns
        and sorting.
      required: [local_date, local_time, starts_at, ends_at, timezone]
      properties:
        local_date: { type: string, format: date, example: "2026-07-14" }
        local_time: { type: string, pattern: '^[0-2][0-9]:[0-5][0-9]$', example: "09:30" }
        starts_at: { type: string, format: date-time, example: "2026-07-14T06:30:00Z" }
        ends_at: { type: string, format: date-time, example: "2026-07-14T14:30:00Z" }
        timezone: { type: string, example: Europe/Athens }
        dst_ambiguous:
          type: boolean
          default: false
          description: |
            True on the October DST repeat, where the local time exists twice and the first
            (summer-time) occurrence was chosen. Show a clarifying note.
      additionalProperties: false

    DepartureOption:
      type: object
      description: |
        One bookable `per_seat` departure. Only departures whose vessel window is free, whose
        status is `scheduled` or `guaranteed`, that are not blocked and that pass lead-time
        and advance rules appear here. `seats_available` already accounts for unexpired holds.
      required: [uuid, window, status, capacity, seats_available, is_guaranteed, from_price_cents, currency]
      properties:
        uuid: { type: string, format: uuid }
        window: { $ref: '#/components/schemas/LocalWindow' }
        check_in_local_time:
          type: [string, "null"]
          description: Departure start minus the product's `check_in_offset_minutes`, in local time.
          example: "09:00"
        status: { type: string, enum: [scheduled, guaranteed] }
        capacity: { type: integer, example: 24 }
        seats_available:
          type: integer
          minimum: 0
          description: '`capacity − seats_sold`, with expired holds treated as already released.'
          example: 11
        is_guaranteed:
          type: boolean
          description: True once `seats_sold >= min_pax`. A non-guaranteed departure may still be cancelled for low numbers.
          example: false
        seats_to_guarantee:
          type: [integer, "null"]
          description: How many more capacity-counting passengers would guarantee this departure. Null when already guaranteed or when `min_pax` is 0.
          example: 2
        from_price_cents: { type: [integer, "null"], example: 6500 }
        currency: { type: string, enum: [EUR] }
        vessel:
          oneOf:
            - $ref: '#/components/schemas/VesselSummary'
            - type: "null"
      additionalProperties: false

    VesselWindowOption:
      type: object
      description: |
        A free window a `per_vessel` charter can be placed in. For a fixed-start product there
        is exactly one option per available day; for `flexible_start` the guest may propose any
        start between `earliest_start_local_time` and `latest_start_local_time` whose full
        duration still fits inside `window`, after the vessel's turnaround buffer is applied.
      required: [window, duration_minutes, flexible_start, is_available]
      properties:
        window: { $ref: '#/components/schemas/LocalWindow' }
        duration_minutes: { type: integer, example: 480 }
        flexible_start: { type: boolean, example: true }
        earliest_start_local_time: { type: [string, "null"], example: "09:00" }
        latest_start_local_time: { type: [string, "null"], example: "12:00" }
        is_available: { type: boolean, example: true }
        unavailable_reason:
          type: [string, "null"]
          enum: [vessel_blocked, seats_sold_on_departure, maintenance, external_calendar, lead_time, advance_window, null]
          description: |
            Why this day cannot be chartered. Never names the conflicting booking or guest —
            a competitor must not be able to read the operator's calendar in detail.
          example: null
        from_price_cents: { type: [integer, "null"], example: 95000 }
        currency: { type: string, enum: [EUR] }
        vessel:
          oneOf:
            - $ref: '#/components/schemas/VesselSummary'
            - type: "null"
      additionalProperties: false

    SearchResult:
      type: object
      description: |
        One trip that can take the searched party on the searched date.

        A `ProductSummary` plus the two things a from-price grid cannot give: what this
        party pays, and when the next sailing leaves. `party_price_cents` is null only for
        `availability: on_request` — a `quote` product, which never shows a price (BKG-24).
      required: [product, availability, party_price_cents, party_price_formatted, next_departure]
      properties:
        product: { $ref: '#/components/schemas/ProductSummary' }
        availability:
          type: string
          enum: [available, on_request]
          description: |
            `available` — a sailing this party can still book on that date.
            `on_request` — a `quote` product, listed without a price; the operator answers.
        party_price_cents:
          type: [integer, "null"]
          description: What the searched party pays, VAT included (PRC-13). Null for `on_request`.
          example: 18000
        party_price_formatted:
          type: [string, "null"]
          description: The same amount rendered in the request locale. A convenience; the cents are the truth.
          example: "180,00 €"
        next_departure:
          oneOf:
            - $ref: '#/components/schemas/SearchDeparture'
            - type: "null"
          description: |
            The soonest sailing on the searched date this party fits into. Null for a
            `per_vessel` charter, whose day is a window rather than a departure, and for
            an `on_request` product.

    SearchDeparture:
      type: object
      required: [uuid, starts_at, local_date, local_time, seats_available]
      properties:
        uuid: { type: string, format: uuid }
        starts_at: { type: string, format: date-time, description: The instant, in the tenant's timezone with its offset. }
        local_date: { type: string, format: date }
        local_time: { type: string, pattern: '^\d{2}:\d{2}$', example: "18:30" }
        seats_available:
          type: integer
          description: |
            Advisory, exactly as on `GET /availability` (ADR-0006). Holds count against it
            and expired holds are treated as released on read.
          example: 6
      additionalProperties: false

    AvailabilityDay:
      type: object
      description: |
        One calendar date in the tenant's timezone. Every date in the requested range is
        present, including unavailable ones, so the calendar can grey them out rather than
        guess. `departures` is populated for `per_seat`, `windows` for `per_vessel`; for
        `mode: quote` both are empty and `status` is `on_request`.
      required: [local_date, status, departures, windows]
      properties:
        local_date: { type: string, format: date, example: "2026-07-14" }
        status:
          type: string
          enum: [available, sold_out, unavailable, not_operating, on_request, past]
          description: |
            `available` — something is bookable.
            `sold_out` — departures exist but cannot seat the requested `pax`.
            `unavailable` — a vessel block or conflict makes the day unsellable.
            `not_operating` — no schedule rule produces a departure on this date.
            `on_request` — `mode: quote`; send an enquiry.
            `past` — the date is before the tenant's today, or inside the lead-time window.
          example: available
        from_price_cents: { type: [integer, "null"], example: 6500 }
        currency: { type: string, enum: [EUR] }
        departures:
          type: array
          description: Populated for `per_seat` only.
          items: { $ref: '#/components/schemas/DepartureOption' }
        windows:
          type: array
          description: Populated for `per_vessel` only.
          items: { $ref: '#/components/schemas/VesselWindowOption' }
      additionalProperties: false

    # ---------- Pricing ----------

    PaxSelection:
      type: object
      description: One age band and how many of them. Bands not listed count as zero.
      required: [age_band_uuid, qty]
      properties:
        age_band_uuid: { type: string, format: uuid }
        qty: { type: integer, minimum: 0, maximum: 500, example: 2 }
      additionalProperties: false

    ExtraSelection:
      type: object
      required: [extra_uuid, qty]
      properties:
        extra_uuid: { type: string, format: uuid }
        qty: { type: integer, minimum: 1, maximum: 500, example: 3 }
      additionalProperties: false

    PriceQuoteRequest:
      type: object
      description: |
        Inputs to the pricing engine. Exactly one of `departure_uuid` (per_seat) or
        `window` (per_vessel) must be supplied. **No price, no total and no discount may
        appear in this body** — anything money-shaped is rejected as an unknown field.
      required: [product_uuid, pax]
      properties:
        product_uuid: { type: string, format: uuid }
        departure_uuid:
          type: [string, "null"]
          format: uuid
          description: "Required for `mode: per_seat`."
        window:
          description: "Required for `mode: per_vessel`. For a fixed-start product, `local_time` must equal the product's `default_start_time`."
          oneOf:
            - type: object
              required: [local_date]
              properties:
                local_date: { type: string, format: date, example: "2026-07-20" }
                local_time: { type: [string, "null"], pattern: '^[0-2][0-9]:[0-5][0-9]$', example: "10:00" }
                duration_minutes:
                  type: [integer, "null"]
                  description: Only for `flexible_start` products that price extra hours. Defaults to the product duration.
                  example: 480
              additionalProperties: false
            - type: "null"
        pax:
          type: array
          minItems: 1
          items: { $ref: '#/components/schemas/PaxSelection' }
        extras:
          type: array
          items: { $ref: '#/components/schemas/ExtraSelection' }
        voucher_code:
          type: [string, "null"]
          maxLength: 24
          description: Applied as a discount if valid. An invalid code is an error, not a silent no-op.
          example: KAI-VOUCH-4F7K
      additionalProperties: false

    PriceLine:
      type: object
      description: |
        One line of the server-side derivation, mirroring `bookings.price_snapshot.lines`.
        Discount amounts are **positive**; `kind` carries the sign.
      required: [kind, ref, label, qty, unit_price_cents, total_cents]
      properties:
        kind: { type: string, enum: [pax, extra, fee, discount] }
        ref:
          type: string
          description: An age-band `code`, an extra `uuid`, or `voucher:{CODE}`.
          example: adult
        label: { type: string, example: Ενήλικας }
        qty: { type: integer, example: 2 }
        unit_price_cents: { type: integer, example: 6500 }
        total_cents: { type: integer, example: 13000 }
        multiplier_bp:
          type: [integer, "null"]
          description: Basis points of the base band price, when this line was derived rather than fixed. `5000` = 50%.
          example: null
      additionalProperties: false

    VatBreakdown:
      type: object
      description: |
        VAT is **never hardcoded** (`BRIEF.md` §10); the rate comes from the product's
        configuration. Greek passenger-transport prices are VAT-inclusive, so `included` is
        normally true and `net_cents + vat_cents = total_cents`.
      required: [rate_bp, included, net_cents, vat_cents]
      properties:
        rate_bp: { type: integer, description: Basis points. `1300` = 13.00%., example: 1300 }
        vat_category:
          type: [string, "null"]
          description: |
            The AADE classification the rate carries (ADR-0002, Option A), from `vat_rates`.
            Added in #37 at the product owner's request; a myDATA-aware client needs it to
            reconcile a quote against the invoice that will be issued for it, and deriving it
            from `rate_bp` client-side would be a second mapping that can disagree with the
            table. Never the `vat_rates` row id, which stays internal (CNV-8).
          example: "1"
        included: { type: boolean, example: true }
        net_cents: { type: integer, example: 12611 }
        vat_cents: { type: integer, example: 1639 }
      additionalProperties: false

    Deposit:
      type: object
      description: Computed from the resolved rate plan, rounded HALF_UP once on the final total.
      required: [type, amount_cents, balance_cents]
      properties:
        type: { type: string, enum: [none, percent, fixed] }
        percent: { type: [integer, "null"], minimum: 1, maximum: 100, example: 30 }
        amount_cents:
          type: integer
          description: "What the guest pays now when choosing `kind: deposit`. Zero when `type` is `none`."
          example: 4275
        amount_formatted: { type: [string, "null"], example: 42,75 € }
        balance_cents: { type: integer, description: What remains due after the deposit., example: 9975 }
        balance_due_at:
          type: [string, "null"]
          format: date-time
          description: |
            When the balance is due, per the tenant's balance policy (default: 14 days before
            departure). Null when the deposit model does not apply.
      additionalProperties: false

    VoucherApplication:
      type: object
      description: How a voucher was applied to this calculation. Surplus stays on the voucher; it is never converted to cash.
      required: [code, applied_cents, remaining_cents_after]
      properties:
        code: { type: string, example: KAI-VOUCH-4F7K }
        applied_cents: { type: integer, example: 5000 }
        remaining_cents_after: { type: integer, example: 0 }
        currency: { type: string, enum: [EUR] }
      additionalProperties: false

    OnRequestItem:
      type: object
      description: |
        An extra with `pricing_type: on_request`. It carries no price, is excluded from every
        total, and becomes an operator to-do on the booking. Show it to the guest as
        "price on request" so nobody expects it to be included.
      required: [extra_uuid, name, qty]
      properties:
        extra_uuid: { type: string, format: uuid }
        name: { type: string, example: Φωτογράφος επί του σκάφους }
        qty: { type: integer, example: 1 }
      additionalProperties: false

    PriceQuote:
      type: object
      description: |
        The complete server-side price derivation. Sufficient to explain the total to a guest
        a year later without touching another endpoint. Has no side effects and reserves
        nothing.
      required: [product_uuid, mode, currency, lines, subtotal_cents, extras_cents, discount_cents, total_cents, vat, deposit, computed_at]
      properties:
        product_uuid: { type: string, format: uuid }
        mode: { type: string, enum: [per_seat, per_vessel] }
        departure_uuid: { type: [string, "null"], format: uuid }
        window:
          oneOf:
            - $ref: '#/components/schemas/LocalWindow'
            - type: "null"
        currency: { type: string, enum: [EUR] }
        pax_total: { type: integer, description: "All passengers, including those that do not occupy a seat.", example: 3 }
        pax_capacity_total: { type: integer, description: Passengers that occupy capacity., example: 2 }
        lines:
          type: array
          items: { $ref: '#/components/schemas/PriceLine' }
        subtotal_cents: { type: integer, example: 16250 }
        extras_cents: { type: integer, example: 3000 }
        discount_cents: { type: integer, description: Positive. The sign is carried by the name., example: 5000 }
        total_cents:
          type: integer
          description: 'Invariant: `subtotal_cents + extras_cents − discount_cents = total_cents`.'
          example: 14250
        total_formatted: { type: string, example: 142,50 € }
        vat: { $ref: '#/components/schemas/VatBreakdown' }
        deposit: { $ref: '#/components/schemas/Deposit' }
        voucher:
          oneOf:
            - $ref: '#/components/schemas/VoucherApplication'
            - type: "null"
        on_request_items:
          type: array
          items: { $ref: '#/components/schemas/OnRequestItem' }
        cancellation_policy:
          oneOf:
            - $ref: '#/components/schemas/CancellationPolicySummary'
            - type: "null"
        rate_plan:
          type: object
          description: Provenance for "why is it this price" support questions. Contains no internal ids beyond the season name.
          properties:
            season_name: { type: [string, "null"], example: Υψηλή }
            source: { type: string, enum: [rate_plan, quote, manual, import] }
          additionalProperties: false
        price_token:
          type: string
          description: |
            Short-lived signed handle over these inputs and this total. Pass it to
            `POST /api/v1/bookings`; the server recomputes and rejects with
            `409 price_changed` if the total moved. Opaque; do not parse.
          example: pt_2f9c1e.eyJ0b3RhbCI6MTQyNTB9.9a3f
        expires_at:
          type: string
          format: date-time
          description: After this instant the `price_token` is no longer accepted; recompute.
          example: "2026-07-01T09:29:22Z"
        computed_at: { type: string, format: date-time, example: "2026-07-01T09:14:22Z" }
        rounding: { type: string, enum: [HALF_UP] }
      additionalProperties: false

    # ---------- Bookings ----------

    LeadGuest:
      type: object
      description: The person who made the booking. Not a manifest row — those are `BookingGuest`.
      required: [name, email]
      properties:
        name: { type: string, maxLength: 120, example: Μαρία Παπαδοπούλου }
        email: { type: string, format: email, maxLength: 190, example: maria@example.gr }
        phone:
          type: [string, "null"]
          maxLength: 32
          description: E.164 preferred. Required when the tenant sets `checkout.require_phone`; SMS needs it.
          example: '+306941234567'
        nationality: { type: [string, "null"], pattern: '^[A-Z]{2}$', example: GR }
        country: { type: [string, "null"], pattern: '^[A-Z]{2}$', description: "Billing country, used for myDATA." }
        vat_number:
          type: [string, "null"]
          maxLength: 20
          description: Supplying a ΑΦΜ makes the invoice a ΤΠΥ instead of an ΑΛΠ.
          example: EL123456789
        company_name: { type: [string, "null"], maxLength: 180 }
      additionalProperties: false

    BookingCreateRequest:
      type: object
      description: |
        Creates a draft and places a hold. Contains **no prices**: the server recomputes from
        the same inputs it would have used for the price quote. `price_token` is the guest's
        proof of what they were shown.

        `guest` and `terms_accepted` are **optional** since ADR-0030 (2026-09-09). A draft is a
        hold on seats: the Kaiki widget takes a date and a party and hands the guest to the
        hosted checkout page, where the lead guest and the consent are entered. Both are still
        required before money moves — `POST /bookings/{uuid}/checkout` answers
        `422 lead_guest_required` for a booking that has neither — so an integrator who does not
        use the hosted checkout must send them here, exactly as before.
      required: [product_uuid, pax]
      properties:
        product_uuid: { type: string, format: uuid }
        departure_uuid: { type: [string, "null"], format: uuid, description: "Required for `mode: per_seat`." }
        window:
          description: "Required for `mode: per_vessel`. Same shape as in `PriceQuoteRequest`."
          oneOf:
            - type: object
              required: [local_date]
              properties:
                local_date: { type: string, format: date }
                local_time: { type: [string, "null"], pattern: '^[0-2][0-9]:[0-5][0-9]$' }
                duration_minutes: { type: [integer, "null"] }
              additionalProperties: false
            - type: "null"
        pax:
          type: array
          minItems: 1
          items: { $ref: '#/components/schemas/PaxSelection' }
        extras:
          type: array
          items: { $ref: '#/components/schemas/ExtraSelection' }
        voucher_code: { type: [string, "null"], maxLength: 24 }
        guest:
          description: |
            Optional since ADR-0030. Omitted, the booking is a hold with nobody's name on it and
            must be completed on the checkout page before it can be paid for.
          oneOf:
            - $ref: '#/components/schemas/LeadGuest'
            - type: "null"
        special_requests: { type: [string, "null"], maxLength: 2000, description: Stored and shown verbatim; never translated. }
        locale:
          type: [string, "null"]
          enum: [el, en, null]
          description: Frozen onto the booking and used for every later email, SMS and PDF. Defaults to the negotiated locale.
        price_token:
          type: [string, "null"]
          description: From `POST /price-quote`. When supplied, a recomputed total that differs is `409 price_changed`.
        terms_accepted:
          type: [boolean, "null"]
          description: |
            Must be true **when sent**, and may be omitted (ADR-0030). It records that the guest
            saw the cancellation policy and terms before paying, so the hosted checkout page
            records it there, beside the payment it authorises. `false` is refused rather than
            ignored: a client that sends the field is making a claim about a box, and an unticked
            box is not consent.
        source:
          type: [string, "null"]
          enum: [widget, hosted, wordpress, null]
          description: Where the booking came from. `manual` and `import` are back-office only and are rejected here.
        utm:
          type: object
          description: Attribution, stored verbatim.
          properties:
            source: { type: [string, "null"], maxLength: 120 }
            medium: { type: [string, "null"], maxLength: 120 }
            campaign: { type: [string, "null"], maxLength: 120 }
            term: { type: [string, "null"], maxLength: 120 }
            content: { type: [string, "null"], maxLength: 120 }
          additionalProperties: false
      additionalProperties: false

    PaxBreakdownLine:
      type: object
      description: A frozen line of `bookings.pax_breakdown`. The labels are the ones shown at booking time, forever.
      required: [code, label, qty, counts_toward_capacity, unit_price_cents, total_cents]
      properties:
        age_band_uuid: { type: [string, "null"], format: uuid }
        code: { type: string, example: adult }
        label: { type: string, example: Ενήλικας }
        qty: { type: integer, example: 2 }
        counts_toward_capacity: { type: boolean, example: true }
        unit_price_cents: { type: integer, example: 6500 }
        total_cents: { type: integer, example: 13000 }
      additionalProperties: false

    ExtraLine:
      type: object
      description: A frozen line of `bookings.extras_snapshot`.
      required: [extra_uuid, name, pricing_type, qty, unit_price_cents, total_cents, is_on_request]
      properties:
        extra_uuid: { type: string, format: uuid }
        name: { type: string }
        pricing_type: { type: string, enum: [per_booking, per_person, on_request] }
        qty: { type: integer }
        unit_price_cents: { type: integer }
        total_cents: { type: integer }
        is_on_request: { type: boolean }
      additionalProperties: false

    BookingMoney:
      type: object
      required: [currency, subtotal_cents, extras_cents, discount_cents, total_cents, deposit_cents, paid_cents, balance_cents, refunded_cents]
      properties:
        currency: { type: string, enum: [EUR] }
        subtotal_cents: { type: integer }
        extras_cents: { type: integer }
        discount_cents: { type: integer }
        total_cents: { type: integer }
        total_formatted: { type: string }
        deposit_cents: { type: integer, description: Zero means pay in full. }
        paid_cents: { type: integer }
        balance_cents: { type: integer, description: '`total_cents − paid_cents`.' }
        refunded_cents: { type: integer }
        balance_due_at: { type: [string, "null"], format: date-time }
        vat: { $ref: '#/components/schemas/VatBreakdown' }
        lines:
          type: array
          description: The frozen `price_snapshot` derivation.
          items: { $ref: '#/components/schemas/PriceLine' }
      additionalProperties: false

    BookingCancellability:
      type: object
      description: |
        What would happen if the guest cancelled right now, computed from the booking's
        **`policy_snapshot`**. Recomputed on every read, because it changes with the clock.
      required: [can_cancel, refund_cents, refund_percent, currency]
      properties:
        can_cancel: { type: boolean }
        reason:
          type: [string, "null"]
          enum: [window_closed, not_confirmed, already_cancelled, disabled_by_operator, departure_started, null]
          description: Why not, when `can_cancel` is false.
        refund_cents: { type: integer, description: Zero is a valid answer and is not an error. }
        refund_percent: { type: integer, minimum: 0, maximum: 100 }
        refund_formatted: { type: [string, "null"] }
        currency: { type: string, enum: [EUR] }
        free_cancellation_until: { type: [string, "null"], format: date-time }
        deadline_at:
          type: [string, "null"]
          format: date-time
          description: After this instant the guest can no longer cancel online at all.
        applied_tier:
          oneOf:
            - $ref: '#/components/schemas/PolicyTier'
            - type: "null"
      additionalProperties: false

    BookingGuestDetailsSummary:
      type: object
      required: [status, required, completed_count, total_count]
      properties:
        status: { type: string, enum: [not_required, pending, complete] }
        required: { type: boolean }
        deadline_at: { type: [string, "null"], format: date-time }
        completed_count: { type: integer, example: 2 }
        total_count: { type: integer, example: 3 }
        url:
          type: [string, "null"]
          format: uri
          description: The `/g/{token}` link. Present only when details are required and the token has been minted.
        charter_agreement_required: { type: boolean, description: True for `per_vessel` bookings that need ναυλοσύμφωνο acceptance. }
        charter_agreement_accepted_at: { type: [string, "null"], format: date-time }
      additionalProperties: false

    BookingLinks:
      type: object
      description: Everything the guest can open. All tokenised; none require an account.
      properties:
        manage_url: { type: [string, "null"], format: uri, example: 'https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9' }
        guest_details_url: { type: [string, "null"], format: uri }
        ticket_pdf_url: { type: [string, "null"], format: uri, description: E-ticket with QR. Present once confirmed. }
        invoice_pdf_url: { type: [string, "null"], format: uri, description: Present once a myDATA invoice has been issued. }
        charter_agreement_pdf_url: { type: [string, "null"], format: uri }
        ical_url: { type: [string, "null"], format: uri, description: Single-event calendar file for this booking. }
        receipt_url: { type: [string, "null"], format: uri }
      additionalProperties: false

    Booking:
      type: object
      description: |
        A booking as its own guest sees it. Never contains internal ids, operator notes, other
        guests' data, gateway payloads or passport numbers.
      required: [uuid, reference, status, mode, locale, is_test, product, window, guest, pax, extras, money, policy, cancellation, guest_details, links, created_at]
      properties:
        uuid: { type: string, format: uuid }
        reference:
          type: string
          description: The human reference the operator quotes on the phone. Unique per tenant.
          example: KAI-7F3K2
        status:
          type: string
          enum: [draft, pending_payment, quote_requested, quote_sent, confirmed, checked_in, completed, cancelled, refunded, expired]
        mode: { type: string, enum: [per_seat, per_vessel, quote] }
        locale: { type: string, enum: [el, en] }
        is_test: { type: boolean, description: Sandbox booking. Purged nightly; excluded from every report. }
        manage_token:
          type: [string, "null"]
          description: |
            Returned **only** in the `201` response to `POST /api/v1/bookings` — this is the
            one moment the client can capture it. Always null on subsequent reads; the caller
            already holds it.
          example: 9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9
        product: { $ref: '#/components/schemas/ProductSummary' }
        vessel:
          oneOf:
            - $ref: '#/components/schemas/VesselSummary'
            - type: "null"
        departure_uuid: { type: [string, "null"], format: uuid }
        window: { $ref: '#/components/schemas/LocalWindow' }
        check_in_local_time: { type: [string, "null"], example: "09:00" }
        meeting_point:
          oneOf:
            - $ref: '#/components/schemas/MeetingPoint'
            - type: "null"
        guest: { $ref: '#/components/schemas/LeadGuest' }
        pax:
          type: array
          items: { $ref: '#/components/schemas/PaxBreakdownLine' }
        pax_total: { type: integer }
        pax_capacity_total: { type: integer }
        extras:
          type: array
          items: { $ref: '#/components/schemas/ExtraLine' }
        money: { $ref: '#/components/schemas/BookingMoney' }
        policy:
          description: Rendered from the frozen `policy_snapshot`, never from the operator's current policy.
          oneOf:
            - $ref: '#/components/schemas/CancellationPolicySummary'
            - type: "null"
        cancellation: { $ref: '#/components/schemas/BookingCancellability' }
        guest_details: { $ref: '#/components/schemas/BookingGuestDetailsSummary' }
        guests:
          type: array
          description: Manifest rows. Present once the booking is confirmed; empty before.
          items: { $ref: '#/components/schemas/BookingGuest' }
        links: { $ref: '#/components/schemas/BookingLinks' }
        hold_expires_at:
          type: [string, "null"]
          format: date-time
          description: Non-null only while `draft` or `pending_payment`. The client should show a countdown and stop the flow at zero.
        special_requests: { type: [string, "null"] }
        payment_required:
          type: boolean
          description: True while money is still owed to complete or confirm this booking.
        created_at: { type: string, format: date-time }
        confirmed_at: { type: [string, "null"], format: date-time }
        cancelled_at: { type: [string, "null"], format: date-time }
        completed_at: { type: [string, "null"], format: date-time }
      additionalProperties: false

    BookingGuest:
      type: object
      description: |
        One manifest row. `document_number` is **write-only**: stored encrypted, never returned.
        `document_number_masked` exists so the guest can see something is on file without this
        API becoming a way to read passport numbers back out.
      required: [uuid, position, age_band_code, is_lead]
      properties:
        uuid: { type: string, format: uuid }
        position: { type: integer, minimum: 1, description: 1-based ordinal within the booking., example: 1 }
        age_band_code: { type: string, example: adult }
        age_band_label: { type: string, example: Ενήλικας }
        full_name: { type: [string, "null"], maxLength: 180, example: Μαρία Παπαδοπούλου }
        date_of_birth: { type: [string, "null"], format: date, example: "1989-04-12" }
        nationality: { type: [string, "null"], pattern: '^[A-Z]{2}$', example: GR }
        document_type: { type: [string, "null"], enum: [passport, id_card, other, null] }
        document_number_masked: { type: [string, "null"], example: '••••4821' }
        document_expires_on: { type: [string, "null"], format: date }
        is_lead: { type: boolean }
        notes: { type: [string, "null"], maxLength: 255, description: Dietary or mobility notes. }
        ticket_code: { type: [string, "null"], description: QR payload. Present once confirmed. }
        checked_in_at: { type: [string, "null"], format: date-time }
      additionalProperties: false

    CheckoutRequest:
      type: object
      required: [kind, return_url]
      properties:
        kind:
          type: string
          enum: [full, deposit, balance]
          description: '`balance` requires a `manage_token` and a `confirmed` booking.'
        gateway:
          type: [string, "null"]
          enum: [viva, null]
          description: Which of the operator's configured gateways to use. Null picks the operator's default.
        return_url:
          type: string
          format: uri
          description: Where the gateway sends the guest after a successful payment. Must be an allowed origin for the key, or a hosted-page URL.
          example: 'https://aegeancruises.gr/booking/thank-you'
        cancel_url:
          type: [string, "null"]
          format: uri
          description: Where the gateway sends the guest if they abandon. Defaults to `return_url`.
      additionalProperties: false

    CheckoutSession:
      type: object
      description: |
        A gateway session created with the **operator's own** credentials. Kaiki never sees
        card data and never holds guest money. Confirmation happens only via the verified
        gateway webhook, never through this API.
      required: [payment_uuid, gateway, kind, amount_cents, currency, redirect_url, expires_at, booking_uuid]
      properties:
        payment_uuid: { type: string, format: uuid }
        booking_uuid: { type: string, format: uuid }
        gateway: { type: string, enum: [viva] }
        kind: { type: string, enum: [full, deposit, balance] }
        amount_cents: { type: integer, example: 4275 }
        amount_formatted: { type: string, example: 42,75 € }
        currency: { type: string, enum: [EUR] }
        redirect_url:
          type: string
          format: uri
          description: Send the guest here. Short-lived; do not cache or email it.
          example: 'https://www.vivapayments.com/web/checkout?ref=1234567890123456'
        expires_at: { type: string, format: date-time }
        hold_expires_at:
          type: [string, "null"]
          format: date-time
          description: The hold, re-armed for another `hold_minutes` because the guest is now on the gateway's page.
        is_test: { type: boolean }
      additionalProperties: false

    CancelRequest:
      type: object
      properties:
        dry_run:
          type: boolean
          default: false
          description: |
            When true, computes and returns the refund without cancelling anything. Call this
            first so the guest confirms against a real number, not a guess.
        reason:
          type: [string, "null"]
          maxLength: 500
          description: Guest-written, optional, stored verbatim and never translated.
        refund_preference:
          type: [string, "null"]
          enum: [refund, voucher, null]
          description: |
            Offered only where the policy snapshot permits a voucher alternative (weather and
            force-majeure cases). Null means the policy's default.
      additionalProperties: false

    CancellationResult:
      type: object
      description: |
        The outcome, computed from the **policy snapshot**. Availability is released
        immediately; the money refund is queued to the gateway, so `refund.status` is normally
        `pending` here and the booking moves `cancelled → refunded` only when the refund
        webhook settles.
      required: [performed, booking_uuid, status, refund, policy]
      properties:
        performed: { type: boolean, description: False for a dry run. }
        booking_uuid: { type: string, format: uuid }
        status: { type: string, enum: [confirmed, cancelled, refunded], description: The booking status after this call. }
        refund:
          type: object
          required: [amount_cents, percent, currency, method, status]
          properties:
            amount_cents: { type: integer, example: 7125 }
            amount_formatted: { type: string, example: 71,25 € }
            percent: { type: integer, example: 50 }
            currency: { type: string, enum: [EUR] }
            method: { type: string, enum: [gateway, voucher, none] }
            status: { type: string, enum: [not_applicable, pending, processing, succeeded, failed] }
            voucher_code: { type: [string, "null"], description: Present when `method` is `voucher`. }
            voucher_expires_at: { type: [string, "null"], format: date-time }
            expected_settlement_days: { type: [integer, "null"], example: 5 }
          additionalProperties: false
        policy: { $ref: '#/components/schemas/CancellationPolicySummary' }
        applied_tier:
          oneOf:
            - $ref: '#/components/schemas/PolicyTier'
            - type: "null"
        hours_before_departure: { type: integer, example: 190 }
      additionalProperties: false

    # ---------- Enquiries ----------

    EnquiryCreateRequest:
      type: object
      required: [name, email, message]
      properties:
        product_uuid: { type: [string, "null"], format: uuid, description: Null for a general enquiry. }
        name: { type: string, maxLength: 120, example: Γιώργος Νικολάου }
        email: { type: string, format: email, maxLength: 190 }
        phone: { type: [string, "null"], maxLength: 32 }
        preferred_date: { type: [string, "null"], format: date, description: "Local date, no timezone semantics." }
        pax: { type: [integer, "null"], minimum: 1, maximum: 500 }
        message: { type: string, minLength: 5, maxLength: 4000, description: Stored and shown to the operator verbatim; never translated. }
        locale: { type: [string, "null"], enum: [el, en, null] }
        company_website:
          type: [string, "null"]
          description: |
            **Honeypot.** Must be empty. Never persisted. A filled value is answered
            `422 enquiry_rejected` with no row created and no notification sent.
        form_rendered_at:
          type: [string, "null"]
          format: date-time
          description: |
            **Timing check (BKG-29), added by #85.** When the client rendered the form. A
            submission less than **3 seconds** after it is answered `422 enquiry_rejected`.

            Bounded at one end only, deliberately: a guest who opens the form, is interrupted
            and submits forty minutes later is not a bot, and an "implausibly slow" rejection
            would refuse exactly the enquiries an operator most wants. A missing, unparseable
            or future value is accepted — a client that sends none is an older integration,
            and a future one is a clock-skewed browser.

            Like the honeypot, this is a cheap filter and not a defence. A client chooses this
            value, so anybody trying can send whatever they like; what it stops is the scripts
            that POST to every form they find. The **5 per IP** limit is the number that
            matters.
        consent:
          type: boolean
          description: The guest agreed to be contacted about this enquiry. Required by the tenant's privacy notice.
      additionalProperties: false

    Enquiry:
      type: object
      description: Deliberately thin. An enquiry is not a booking, has no guest token, and is answered by email.
      required: [uuid, status, created_at]
      properties:
        uuid: { type: string, format: uuid }
        status: { type: string, enum: [new] }
        product_uuid: { type: [string, "null"], format: uuid }
        locale: { type: string, enum: [el, en] }
        created_at: { type: string, format: date-time }
      additionalProperties: false

    # ---------- Quotes ----------

    QuoteLineItem:
      type: object
      description: Discount amounts are positive; `kind` carries the sign.
      required: [label, kind, qty, unit_price_cents, total_cents, sort_order]
      properties:
        label: { type: string, example: Ιδιωτική ναύλωση ολοήμερη }
        description: { type: [string, "null"] }
        kind: { type: string, enum: [charter, extra, fee, discount] }
        qty: { type: integer, example: 1 }
        unit_price_cents: { type: integer, example: 95000 }
        total_cents: { type: integer, example: 95000 }
        sort_order: { type: integer }
      additionalProperties: false

    Quote:
      type: object
      description: |
        An operator-built quote as the guest sees it at `/q/{token}`. Expired and superseded
        quotes are returned with `200` and the corresponding `status`, so the page can say
        "this quote was replaced" instead of showing a dead end.

        **A quote is not a hold**: the boat is not reserved while the guest thinks about it.
      required: [uuid, version, status, currency, line_items, subtotal_cents, discount_cents, total_cents, deposit_cents, valid_until, booking, can_accept]
      properties:
        uuid: { type: string, format: uuid }
        version: { type: integer, example: 2 }
        status: { type: string, enum: [sent, accepted, declined, expired] }
        currency: { type: string, enum: [EUR] }
        message: { type: [string, "null"], description: "The operator's covering note, in the booking's locale." }
        terms: { type: [string, "null"] }
        line_items:
          type: array
          items: { $ref: '#/components/schemas/QuoteLineItem' }
        subtotal_cents: { type: integer }
        discount_cents: { type: integer }
        total_cents: { type: integer }
        total_formatted: { type: string }
        deposit_cents: { type: integer }
        vat: { $ref: '#/components/schemas/VatBreakdown' }
        valid_until: { type: string, format: date-time }
        booking:
          type: object
          required: [uuid, reference, status, window]
          properties:
            uuid: { type: string, format: uuid }
            reference: { type: string }
            status: { type: string }
            product: { $ref: '#/components/schemas/ProductSummary' }
            window: { $ref: '#/components/schemas/LocalWindow' }
            pax_total: { type: integer }
          additionalProperties: false
        policy:
          oneOf:
            - $ref: '#/components/schemas/CancellationPolicySummary'
            - type: "null"
        can_accept:
          type: boolean
          description: Whether acceptance would currently succeed. Re-evaluated on every read.
        accept_blocked_reason:
          type: [string, "null"]
          enum: [expired, superseded, already_decided, vessel_unavailable, null]
        superseded_by_version: { type: [integer, "null"] }
        viewed_at: { type: [string, "null"], format: date-time }
        accepted_at: { type: [string, "null"], format: date-time }
        declined_at: { type: [string, "null"], format: date-time }
      additionalProperties: false

    QuoteAcceptRequest:
      type: object
      properties:
        return_url: { type: [string, "null"], format: uri, description: Overrides the hosted-page default the operator's quote email points at. }
        cancel_url: { type: [string, "null"], format: uri }
        gateway: { type: [string, "null"], enum: [viva, null] }
        kind: { type: string, enum: [full, deposit], default: deposit, description: Whether the guest pays the deposit or the whole amount now. }
        terms_accepted: { type: boolean }
      additionalProperties: false

    QuoteAcceptResult:
      type: object
      required: [quote, booking_uuid, checkout]
      properties:
        quote: { $ref: '#/components/schemas/Quote' }
        booking_uuid: { type: string, format: uuid }
        manage_token:
          type: [string, "null"]
          description: Returned once, here, so the guest can manage the booking after paying.
        checkout: { $ref: '#/components/schemas/CheckoutSession' }
      additionalProperties: false

    QuoteDeclineRequest:
      type: object
      properties:
        reason: { type: [string, "null"], maxLength: 500, description: "Guest-written, stored verbatim, never translated." }
      additionalProperties: false

    # ---------- Guest details ----------

    CharterAgreementAcceptance:
      type: object
      description: |
        Ναυλοσύμφωνο acceptance. The client sends only the flag and the version it displayed;
        **IP address and timestamp are captured server-side** and cannot be supplied by the
        client, because they are the legal evidence.
      required: [accepted, version]
      properties:
        accepted: { type: boolean }
        version: { type: string, description: The template version the guest was shown. A mismatch with the current version is `422`., example: "v1" }
      additionalProperties: false

    GuestDetailsGuestInput:
      type: object
      description: One manifest row to write, matched to an existing row by `position`.
      required: [position]
      properties:
        position: { type: integer, minimum: 1 }
        full_name: { type: [string, "null"], maxLength: 180 }
        date_of_birth: { type: [string, "null"], format: date }
        nationality: { type: [string, "null"], pattern: '^[A-Z]{2}$' }
        document_type: { type: [string, "null"], enum: [passport, id_card, other, null] }
        document_number:
          type: [string, "null"]
          maxLength: 40
          description: '**Write-only.** Stored encrypted, never returned, never logged, purged after the retention window.'
        document_expires_on: { type: [string, "null"], format: date }
        notes: { type: [string, "null"], maxLength: 255 }
      additionalProperties: false

    GuestDetailsUpdateRequest:
      type: object
      description: Full replacement of the manifest. Partial saves are allowed; fields may stay null until the final submit.
      required: [guests]
      properties:
        guests:
          type: array
          minItems: 1
          items: { $ref: '#/components/schemas/GuestDetailsGuestInput' }
        charter_agreement:
          oneOf:
            - $ref: '#/components/schemas/CharterAgreementAcceptance'
            - type: "null"
        submit:
          type: boolean
          default: false
          description: |
            True means "this is final". The server then enforces every required field and the
            charter agreement, and returns `422` if anything is missing. False saves progress
            and leaves the status `pending`.
      additionalProperties: false

    GuestDetailsPage:
      type: object
      description: Everything `/g/{token}` needs to render the manifest form, including why it might be closed.
      required: [booking_uuid, reference, status, deadline_at, is_editable, required_fields, guests]
      properties:
        booking_uuid: { type: string, format: uuid }
        reference: { type: string, example: KAI-7F3K2 }
        status: { type: string, enum: [not_required, pending, complete] }
        locale: { type: string, enum: [el, en] }
        product_title: { type: string }
        window: { $ref: '#/components/schemas/LocalWindow' }
        meeting_point:
          oneOf:
            - $ref: '#/components/schemas/MeetingPoint'
            - type: "null"
        deadline_at: { type: [string, "null"], format: date-time }
        is_editable:
          type: boolean
          description: False after the deadline. `GET` still works so the guest can see why; `PUT` is refused.
        required_fields:
          type: array
          description: Which manifest fields this operator requires. Drives the form's validation, so it never disagrees with the server.
          items: { type: string, enum: [full_name, date_of_birth, nationality, document_type, document_number, document_expires_on] }
          example: [full_name, date_of_birth, nationality, document_number]
        guests:
          type: array
          items: { $ref: '#/components/schemas/BookingGuest' }
        charter_agreement:
          type: [object, "null"]
          description: Present for `per_vessel` bookings requiring ναυλοσύμφωνο acceptance.
          properties:
            required: { type: boolean }
            version: { type: string, example: "v1" }
            pdf_url: { type: [string, "null"], format: uri }
            summary_html: { type: [string, "null"], description: "Sanitised HTML of the terms, in the booking's locale." }
            accepted: { type: boolean }
            accepted_at: { type: [string, "null"], format: date-time }
          additionalProperties: false
        completed_count: { type: integer }
        total_count: { type: integer }
      additionalProperties: false

    # ---------- Vouchers ----------

    Voucher:
      type: object
      description: |
        Balance only. Never reveals which booking issued the voucher, who holds it, or where
        it has been spent.
      required: [code, status, amount_cents, remaining_cents, currency, is_redeemable]
      properties:
        code: { type: string, example: KAI-VOUCH-4F7K }
        status: { type: string, enum: [active, redeemed, expired, cancelled] }
        amount_cents: { type: integer, example: 15000 }
        remaining_cents: { type: integer, example: 5000 }
        remaining_formatted: { type: string, example: 50,00 € }
        currency: { type: string, enum: [EUR] }
        expires_at: { type: [string, "null"], format: date-time }
        is_redeemable:
          type: boolean
          description: '`status` is active, `remaining_cents` > 0, and not past `expires_at`.'
        not_redeemable_reason:
          type: [string, "null"]
          enum: [expired, depleted, cancelled, null]
        issued_at: { type: string, format: date-time }
      additionalProperties: false

  examples:

    BrandingResponse:
      summary: Branding for a Greek operator
      value:
        data:
          tenant:
            uuid: 2c4a6e80-1b3d-4f5a-8c7e-9d0b1a2f3e4c
            name: Aegean Cruises
            slug: aegean-cruises
            timezone: Europe/Athens
            default_locale: el
            currency: EUR
            hosted_page_url: 'https://book.kaiki.app/aegean-cruises'
            support_email: info@aegeancruises.gr
            support_phone: '+302109876543'
          logo:
            light_url: 'https://cdn.kaiki.app/t/2c4a6e80/logo-light.svg'
            dark_url: 'https://cdn.kaiki.app/t/2c4a6e80/logo-dark.svg'
            favicon_url: 'https://cdn.kaiki.app/t/2c4a6e80/favicon.png'
          colors:
            primary: '#0B4F4A'
            secondary: '#063733'
            accent: '#B5511F'
            background: '#FFFFFF'
            text: '#16211F'
          font:
            family: Inter
            source: google
            css_url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap'
          button_radius_px: 8
          widget_theme: auto
          css_variables:
            --kaiki-primary: '#0B4F4A'
            --kaiki-secondary: '#063733'
            --kaiki-accent: '#B5511F'
            --kaiki-background: '#FFFFFF'
            --kaiki-text: '#16211F'
            --kaiki-radius: 8px
            --kaiki-font-family: "Inter, system-ui, sans-serif"
          social_links:
            instagram: 'https://instagram.com/aegeancruises'
          email_footer_text: Σας ευχαριστούμε που ταξιδεύετε μαζί μας.
          custom_css: null
          terms_url: 'https://aegeancruises.gr/oroi'
          privacy_url: 'https://aegeancruises.gr/aporrito'
          is_test: false

    ProductListResponse:
      summary: Two products, Greek locale
      value:
        data:
          - uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
            slug: kroyaziera-aigina-agkistri
            title: Κρουαζιέρα Αίγινα & Αγκίστρι
            summary: Ολοήμερη κρουαζιέρα με παραδοσιακό καΐκι, γεύμα και δύο στάσεις για κολύμπι.
            category: shared_full_day
            mode: per_seat
            duration_minutes: 480
            default_start_time: "09:30"
            flexible_start: false
            from_price_cents: 6500
            from_price_formatted: 65,00 €
            currency: EUR
            hero_image_url: 'https://cdn.kaiki.app/t/2c4a6e80/p/aigina-1.jpg'
            vessel:
              uuid: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
              name: Meltemi
              type: traditional_kaiki
              capacity_max: 32
            meeting_point:
              uuid: 5e7c9a1b-3d5f-4a7c-9e1b-3d5f7a9c1e3b
              name: Μαρίνα Ζέας
            min_booking_pax: 1
            max_pax: 24
            is_featured: true
            sort_order: 10
            booking_url: 'https://book.kaiki.app/aegean-cruises/kroyaziera-aigina-agkistri'
            updated_at: "2026-06-18T08:12:00Z"
          - uuid: b2d4f6a8-1c3e-4058-9a7b-2c4e6f8a0b1d
            slug: idiotiki-naylosi-olimeri
            title: Ιδιωτική ναύλωση — ολοήμερη
            summary: Το σκάφος αποκλειστικά για την παρέα σας, με πλήρωμα.
            category: private_full_day
            mode: per_vessel
            duration_minutes: 480
            default_start_time: "10:00"
            flexible_start: true
            from_price_cents: 95000
            from_price_formatted: 950,00 €
            currency: EUR
            hero_image_url: 'https://cdn.kaiki.app/t/2c4a6e80/p/private-1.jpg'
            vessel:
              uuid: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
              name: Meltemi
              type: traditional_kaiki
              capacity_max: 32
            meeting_point:
              uuid: 5e7c9a1b-3d5f-4a7c-9e1b-3d5f7a9c1e3b
              name: Μαρίνα Ζέας
            min_booking_pax: 2
            max_pax: 20
            is_featured: false
            sort_order: 20
            booking_url: 'https://book.kaiki.app/aegean-cruises/idiotiki-naylosi-olimeri'
            updated_at: "2026-06-02T14:41:00Z"
        pagination:
          per_page: 24
          has_more: false
          next_cursor: null
          prev_cursor: null
          next_url: null

    ProductDetailGreekResponse:
      summary: Full product, Accept-Language el
      value:
        data:
          uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
          slug: kroyaziera-aigina-agkistri
          title: Κρουαζιέρα Αίγινα & Αγκίστρι
          summary: Ολοήμερη κρουαζιέρα με παραδοσιακό καΐκι, γεύμα και δύο στάσεις για κολύμπι.
          description: "<p>Αναχωρούμε από τη Μαρίνα Ζέας και πλέουμε προς την Αίγινα. Μετά από ελεύθερο χρόνο στο λιμάνι, συνεχίζουμε στο Αγκίστρι για κολύμπι σε καταγάλανα νερά.</p>"
          category: shared_full_day
          mode: per_seat
          duration_minutes: 480
          default_start_time: "09:30"
          flexible_start: false
          earliest_start_time: null
          latest_start_time: null
          from_price_cents: 6500
          from_price_formatted: 65,00 €
          currency: EUR
          hero_image_url: 'https://cdn.kaiki.app/t/2c4a6e80/p/aigina-1.jpg'
          images:
            - url: 'https://cdn.kaiki.app/t/2c4a6e80/p/aigina-1.jpg'
              alt: Το καΐκι Meltemi στο λιμάνι της Αίγινας
              width: 1600
              height: 1067
          vessel:
            uuid: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
            name: Meltemi
            type: traditional_kaiki
            capacity_max: 32
            length_m: 18.5
            crew_count: 3
            description: Παραδοσιακό ξύλινο καΐκι του 1968, πλήρως ανακαινισμένο.
            images: []
            specs: { year: 1968, engine: Volvo Penta }
          meeting_point:
            uuid: 5e7c9a1b-3d5f-4a7c-9e1b-3d5f7a9c1e3b
            name: Μαρίνα Ζέας
            address: 'Ακτή Θεμιστοκλέους, Πειραιάς 185 36'
            lat: 37.9339
            lng: 23.6512
            instructions: Συνάντηση στο μπλε περίπτερο, δίπλα στην προβλήτα Δ.
            photo_url: 'https://cdn.kaiki.app/t/2c4a6e80/ports/zea.jpg'
            maps_url: 'https://maps.app.goo.gl/example'
          includes: [Γεύμα και ποτά, Εξοπλισμός κολύμβησης, Ασφάλεια επιβατών]
          excludes: [Μεταφορά από/προς ξενοδοχείο, Φιλοδωρήματα]
          what_to_bring: [Αντηλιακό, Πετσέτα, Καπέλο]
          itinerary_stops:
            - key: s1
              name: Αναχώρηση — Μαρίνα Ζέας
              description: Επιβίβαση 30 λεπτά πριν την αναχώρηση.
              duration_minutes: 0
              lat: 37.9339
              lng: 23.6512
            - key: s2
              name: Όρμος Βλυχάδα, Αγκίστρι
              description: Κολύμπι και σνόρκελ.
              duration_minutes: 60
              lat: 37.6721
              lng: 23.4410
          route_map_image_url: 'https://cdn.kaiki.app/t/2c4a6e80/p/aigina-route.png'
          age_bands:
            - uuid: 0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10
              code: adult
              label: Ενήλικας
              min_age: 12
              max_age: null
              counts_toward_capacity: true
              requires_adult: false
              is_base: true
              sort_order: 10
              from_price_cents: 6500
              currency: EUR
            - uuid: 9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d
              code: child
              label: Παιδί
              min_age: 3
              max_age: 11
              counts_toward_capacity: true
              requires_adult: true
              is_base: false
              sort_order: 20
              from_price_cents: 3250
              currency: EUR
            - uuid: 5a9d8c77-1e42-4f0b-b3aa-90c4e1d27f33
              code: infant
              label: Βρέφος
              min_age: 0
              max_age: 2
              counts_toward_capacity: false
              requires_adult: true
              is_base: false
              sort_order: 30
              from_price_cents: 0
              currency: EUR
          extras:
            - uuid: a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d
              name: Μεταφορά από ξενοδοχείο
              description: Παραλαβή από ξενοδοχεία στο κέντρο της Αθήνας.
              pricing_type: per_person
              price_cents: 1000
              currency: EUR
              max_qty: 8
              is_required: false
              image_url: null
              sort_order: 10
            - uuid: b7c2f4a1-88de-4f13-a0b2-1d3e5f7a9c00
              name: Φωτογράφος επί του σκάφους
              description: Κατόπιν διαθεσιμότητας.
              pricing_type: on_request
              price_cents: null
              currency: EUR
              max_qty: 1
              is_required: false
              image_url: null
              sort_order: 20
          cancellation_policy:
            name: Ευέλικτη
            summary: Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.
            free_cancellation_hours: 48
            tiers:
              - { days_before: 15, refund_percent: 100 }
              - { days_before: 7, refund_percent: 50 }
              - { days_before: 2, refund_percent: 0 }
            weather_refund_percent: 100
            force_majeure_voucher_months: 18
            no_show_refund_percent: 0
            captured_at: null
          check_in_offset_minutes: 30
          min_pax: 6
          min_booking_pax: 1
          max_pax: 24
          guest_details_required: true
          guest_details_deadline_hours: 48
          booking_window:
            min_lead_time_hours: 12
            max_advance_days: 365
          seo:
            meta_title: Κρουαζιέρα Αίγινα & Αγκίστρι από Πειραιά | Aegean Cruises
            meta_description: Ολοήμερη κρουαζιέρα με παραδοσιακό καΐκι σε Αίγινα και Αγκίστρι. Γεύμα, κολύμπι, αναχώρηση από Μαρίνα Ζέας.
            og_image_url: 'https://cdn.kaiki.app/t/2c4a6e80/p/aigina-og.jpg'
            canonical_url: 'https://book.kaiki.app/aegean-cruises/kroyaziera-aigina-agkistri'
          timezone: Europe/Athens
          is_featured: true
          sort_order: 10
          booking_url: 'https://book.kaiki.app/aegean-cruises/kroyaziera-aigina-agkistri'
          updated_at: "2026-06-18T08:12:00Z"

    AvailabilityPerSeatResponse:
      summary: per_seat, three days, pax=4
      value:
        data:
          - local_date: "2026-07-14"
            status: available
            from_price_cents: 6500
            currency: EUR
            departures:
              - uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
                window:
                  local_date: "2026-07-14"
                  local_time: "09:30"
                  starts_at: "2026-07-14T06:30:00Z"
                  ends_at: "2026-07-14T14:30:00Z"
                  timezone: Europe/Athens
                  dst_ambiguous: false
                check_in_local_time: "09:00"
                status: guaranteed
                capacity: 24
                seats_available: 11
                is_guaranteed: true
                seats_to_guarantee: null
                from_price_cents: 6500
                currency: EUR
                vessel:
                  uuid: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
                  name: Meltemi
                  type: traditional_kaiki
                  capacity_max: 32
            windows: []
          - local_date: "2026-07-15"
            status: sold_out
            from_price_cents: 6500
            currency: EUR
            departures:
              - uuid: 1a2b3c4d-5e6f-4708-9a0b-1c2d3e4f5a6b
                window:
                  local_date: "2026-07-15"
                  local_time: "09:30"
                  starts_at: "2026-07-15T06:30:00Z"
                  ends_at: "2026-07-15T14:30:00Z"
                  timezone: Europe/Athens
                  dst_ambiguous: false
                check_in_local_time: "09:00"
                status: guaranteed
                capacity: 24
                seats_available: 2
                is_guaranteed: true
                seats_to_guarantee: null
                from_price_cents: 6500
                currency: EUR
                vessel: null
            windows: []
          - local_date: "2026-07-16"
            status: not_operating
            from_price_cents: null
            currency: EUR
            departures: []
            windows: []
        meta:
          product_uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
          mode: per_seat
          timezone: Europe/Athens
          currency: EUR
          from: "2026-07-14"
          to: "2026-07-16"
          pax: 4
          min_lead_time_hours: 12
          max_advance_days: 365

    AvailabilityPerVesselResponse:
      summary: per_vessel with a flexible start and one blocked day
      value:
        data:
          - local_date: "2026-07-20"
            status: available
            from_price_cents: 95000
            currency: EUR
            departures: []
            windows:
              - window:
                  local_date: "2026-07-20"
                  local_time: "09:00"
                  starts_at: "2026-07-20T06:00:00Z"
                  ends_at: "2026-07-20T18:00:00Z"
                  timezone: Europe/Athens
                  dst_ambiguous: false
                duration_minutes: 480
                flexible_start: true
                earliest_start_local_time: "09:00"
                latest_start_local_time: "12:00"
                is_available: true
                unavailable_reason: null
                from_price_cents: 95000
                currency: EUR
                vessel:
                  uuid: 3f2a1b0c-9d8e-4f7a-b6c5-d4e3f2a1b0c9
                  name: Meltemi
                  type: traditional_kaiki
                  capacity_max: 32
          - local_date: "2026-07-21"
            status: unavailable
            from_price_cents: null
            currency: EUR
            departures: []
            windows:
              - window:
                  local_date: "2026-07-21"
                  local_time: "09:00"
                  starts_at: "2026-07-21T06:00:00Z"
                  ends_at: "2026-07-21T18:00:00Z"
                  timezone: Europe/Athens
                  dst_ambiguous: false
                duration_minutes: 480
                flexible_start: true
                earliest_start_local_time: "09:00"
                latest_start_local_time: "12:00"
                is_available: false
                unavailable_reason: seats_sold_on_departure
                from_price_cents: null
                currency: EUR
                vessel: null
        meta:
          product_uuid: b2d4f6a8-1c3e-4058-9a7b-2c4e6f8a0b1d
          mode: per_vessel
          timezone: Europe/Athens
          currency: EUR
          from: "2026-07-20"
          to: "2026-07-21"
          pax: null
          min_lead_time_hours: 48
          max_advance_days: 365

    PriceQuoteRequestPerSeat:
      summary: Two adults, one child, one infant, a transfer and a voucher
      value:
        product_uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
        departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
        pax:
          - { age_band_uuid: 0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10, qty: 2 }
          - { age_band_uuid: 9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d, qty: 1 }
          - { age_band_uuid: 5a9d8c77-1e42-4f0b-b3aa-90c4e1d27f33, qty: 1 }
        extras:
          - { extra_uuid: a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d, qty: 3 }
        voucher_code: KAI-VOUCH-4F7K

    PriceQuoteRequestPerVessel:
      summary: Private charter with a proposed flexible start
      value:
        product_uuid: b2d4f6a8-1c3e-4058-9a7b-2c4e6f8a0b1d
        window:
          local_date: "2026-07-20"
          local_time: "10:00"
          duration_minutes: 480
        pax:
          - { age_band_uuid: 0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10, qty: 8 }
        extras: []
        voucher_code: null

    PriceQuoteResponse:
      summary: The derivation for the per_seat request above
      value:
        data:
          product_uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
          mode: per_seat
          departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
          window:
            local_date: "2026-07-14"
            local_time: "09:30"
            starts_at: "2026-07-14T06:30:00Z"
            ends_at: "2026-07-14T14:30:00Z"
            timezone: Europe/Athens
            dst_ambiguous: false
          currency: EUR
          pax_total: 4
          pax_capacity_total: 3
          lines:
            - { kind: pax, ref: adult, label: Ενήλικας, qty: 2, unit_price_cents: 6500, total_cents: 13000, multiplier_bp: null }
            - { kind: pax, ref: child, label: Παιδί, qty: 1, unit_price_cents: 3250, total_cents: 3250, multiplier_bp: 5000 }
            - { kind: pax, ref: infant, label: Βρέφος, qty: 1, unit_price_cents: 0, total_cents: 0, multiplier_bp: 0 }
            - { kind: extra, ref: a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d, label: Μεταφορά από ξενοδοχείο, qty: 3, unit_price_cents: 1000, total_cents: 3000, multiplier_bp: null }
            - { kind: discount, ref: 'voucher:KAI-VOUCH-4F7K', label: Κουπόνι, qty: 1, unit_price_cents: 5000, total_cents: 5000, multiplier_bp: null }
          subtotal_cents: 16250
          extras_cents: 3000
          discount_cents: 5000
          total_cents: 14250
          total_formatted: 142,50 €
          vat: { rate_bp: 1300, included: true, net_cents: 12611, vat_cents: 1639 }
          deposit:
            type: percent
            percent: 30
            amount_cents: 4275
            amount_formatted: 42,75 €
            balance_cents: 9975
            balance_due_at: "2026-06-30T21:00:00Z"
          voucher: { code: KAI-VOUCH-4F7K, applied_cents: 5000, remaining_cents_after: 0, currency: EUR }
          on_request_items: []
          cancellation_policy:
            name: Ευέλικτη
            summary: Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.
            free_cancellation_hours: 48
            tiers:
              - { days_before: 15, refund_percent: 100 }
              - { days_before: 7, refund_percent: 50 }
              - { days_before: 2, refund_percent: 0 }
            weather_refund_percent: 100
            force_majeure_voucher_months: 18
            no_show_refund_percent: 0
            captured_at: null
          rate_plan: { season_name: Υψηλή, source: rate_plan }
          price_token: pt_2f9c1e.eyJ0b3RhbCI6MTQyNTB9.9a3f
          expires_at: "2026-07-01T09:29:22Z"
          computed_at: "2026-07-01T09:14:22Z"
          rounding: HALF_UP

    VoucherResponse:
      summary: A partly spent, still redeemable voucher
      value:
        data:
          code: KAI-VOUCH-4F7K
          status: active
          amount_cents: 15000
          remaining_cents: 5000
          remaining_formatted: 50,00 €
          currency: EUR
          expires_at: "2027-09-30T20:59:59Z"
          is_redeemable: true
          not_redeemable_reason: null
          issued_at: "2026-03-30T09:00:00Z"

    BookingCreateRequestPerSeat:
      summary: Create a draft from the widget
      value:
        product_uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
        departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
        pax:
          - { age_band_uuid: 0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10, qty: 2 }
          - { age_band_uuid: 9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d, qty: 1 }
          - { age_band_uuid: 5a9d8c77-1e42-4f0b-b3aa-90c4e1d27f33, qty: 1 }
        extras:
          - { extra_uuid: a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d, qty: 3 }
        voucher_code: KAI-VOUCH-4F7K
        guest:
          name: Μαρία Παπαδοπούλου
          email: maria@example.gr
          phone: '+306941234567'
          nationality: GR
        special_requests: Ένα από τα παιδιά έχει αλλεργία στους ξηρούς καρπούς.
        locale: el
        price_token: pt_2f9c1e.eyJ0b3RhbCI6MTQyNTB9.9a3f
        terms_accepted: true
        source: widget
        utm: { source: google, medium: cpc, campaign: aigina-summer }

    BookingDraftResponse:
      summary: The draft, with the hold deadline and the one-time manage token
      value:
        data:
          uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          reference: KAI-7F3K2
          status: draft
          mode: per_seat
          locale: el
          is_test: false
          manage_token: 9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9
          departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
          window:
            local_date: "2026-07-14"
            local_time: "09:30"
            starts_at: "2026-07-14T06:30:00Z"
            ends_at: "2026-07-14T14:30:00Z"
            timezone: Europe/Athens
            dst_ambiguous: false
          check_in_local_time: "09:00"
          guest:
            name: Μαρία Παπαδοπούλου
            email: maria@example.gr
            phone: '+306941234567'
            nationality: GR
          pax:
            - { code: adult, label: Ενήλικας, qty: 2, counts_toward_capacity: true, unit_price_cents: 6500, total_cents: 13000 }
            - { code: child, label: Παιδί, qty: 1, counts_toward_capacity: true, unit_price_cents: 3250, total_cents: 3250 }
            - { code: infant, label: Βρέφος, qty: 1, counts_toward_capacity: false, unit_price_cents: 0, total_cents: 0 }
          pax_total: 4
          pax_capacity_total: 3
          extras:
            - extra_uuid: a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d
              name: Μεταφορά από ξενοδοχείο
              pricing_type: per_person
              qty: 3
              unit_price_cents: 1000
              total_cents: 3000
              is_on_request: false
          money:
            currency: EUR
            subtotal_cents: 16250
            extras_cents: 3000
            discount_cents: 5000
            total_cents: 14250
            total_formatted: 142,50 €
            deposit_cents: 4275
            paid_cents: 0
            balance_cents: 14250
            refunded_cents: 0
            balance_due_at: "2026-06-30T21:00:00Z"
            vat: { rate_bp: 1300, included: true, net_cents: 12611, vat_cents: 1639 }
            lines: []
          cancellation:
            can_cancel: false
            reason: not_confirmed
            refund_cents: 0
            refund_percent: 0
            currency: EUR
            free_cancellation_until: null
            deadline_at: null
            applied_tier: null
          guest_details:
            status: not_required
            required: true
            deadline_at: null
            completed_count: 0
            total_count: 0
            url: null
            charter_agreement_required: false
            charter_agreement_accepted_at: null
          guests: []
          links:
            manage_url: 'https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9'
          hold_expires_at: "2026-07-01T09:29:31Z"
          payment_required: true
          created_at: "2026-07-01T09:14:31Z"
          product:
            uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
            slug: kroyaziera-aigina-agkistri
            title: Κρουαζιέρα Αίγινα & Αγκίστρι
            category: shared_full_day
            mode: per_seat
            duration_minutes: 480
            currency: EUR
            from_price_cents: 6500
            is_featured: true
            sort_order: 10
            updated_at: "2026-06-18T08:12:00Z"

    BookingConfirmedResponse:
      summary: A confirmed booking read from /b/{manage_token}
      value:
        data:
          uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          reference: KAI-7F3K2
          status: confirmed
          mode: per_seat
          locale: el
          is_test: false
          manage_token: null
          departure_uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f
          window:
            local_date: "2026-07-14"
            local_time: "09:30"
            starts_at: "2026-07-14T06:30:00Z"
            ends_at: "2026-07-14T14:30:00Z"
            timezone: Europe/Athens
            dst_ambiguous: false
          check_in_local_time: "09:00"
          guest:
            name: Μαρία Παπαδοπούλου
            email: maria@example.gr
            phone: '+306941234567'
            nationality: GR
          pax:
            - { code: adult, label: Ενήλικας, qty: 2, counts_toward_capacity: true, unit_price_cents: 6500, total_cents: 13000 }
            - { code: child, label: Παιδί, qty: 1, counts_toward_capacity: true, unit_price_cents: 3250, total_cents: 3250 }
            - { code: infant, label: Βρέφος, qty: 1, counts_toward_capacity: false, unit_price_cents: 0, total_cents: 0 }
          pax_total: 4
          pax_capacity_total: 3
          extras: []
          money:
            currency: EUR
            subtotal_cents: 16250
            extras_cents: 3000
            discount_cents: 5000
            total_cents: 14250
            total_formatted: 142,50 €
            deposit_cents: 4275
            paid_cents: 4275
            balance_cents: 9975
            refunded_cents: 0
            balance_due_at: "2026-06-30T21:00:00Z"
            vat: { rate_bp: 1300, included: true, net_cents: 12611, vat_cents: 1639 }
            lines:
              - { kind: pax, ref: adult, label: Ενήλικας, qty: 2, unit_price_cents: 6500, total_cents: 13000 }
              - { kind: discount, ref: 'voucher:KAI-VOUCH-4F7K', label: Κουπόνι, qty: 1, unit_price_cents: 5000, total_cents: 5000 }
          policy:
            name: Ευέλικτη
            summary: Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.
            free_cancellation_hours: 48
            tiers:
              - { days_before: 15, refund_percent: 100 }
              - { days_before: 7, refund_percent: 50 }
              - { days_before: 2, refund_percent: 0 }
            weather_refund_percent: 100
            force_majeure_voucher_months: 18
            no_show_refund_percent: 0
            captured_at: "2026-07-01T09:16:02Z"
          cancellation:
            can_cancel: true
            reason: null
            refund_cents: 4275
            refund_percent: 100
            refund_formatted: 42,75 €
            currency: EUR
            free_cancellation_until: "2026-07-12T06:30:00Z"
            deadline_at: "2026-07-12T06:30:00Z"
            applied_tier: { days_before: 15, refund_percent: 100 }
          guest_details:
            status: pending
            required: true
            deadline_at: "2026-07-12T06:30:00Z"
            completed_count: 0
            total_count: 4
            url: 'https://book.kaiki.app/g/b7e3d1a9c5f204867e1b3d5a9c7f0e2b4d6a8c10'
            charter_agreement_required: false
            charter_agreement_accepted_at: null
          guests:
            - uuid: c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f
              position: 1
              age_band_code: adult
              age_band_label: Ενήλικας
              full_name: null
              date_of_birth: null
              nationality: null
              document_type: null
              document_number_masked: null
              document_expires_on: null
              is_lead: true
              notes: null
              ticket_code: TKT7F3K2A1B2C3D4E5F6G7H8
              checked_in_at: null
          links:
            manage_url: 'https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9'
            guest_details_url: 'https://book.kaiki.app/g/b7e3d1a9c5f204867e1b3d5a9c7f0e2b4d6a8c10'
            ticket_pdf_url: 'https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9/ticket.pdf'
            invoice_pdf_url: null
            ical_url: 'https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9/booking.ics'
          hold_expires_at: null
          special_requests: Ένα από τα παιδιά έχει αλλεργία στους ξηρούς καρπούς.
          payment_required: true
          created_at: "2026-07-01T09:14:31Z"
          confirmed_at: "2026-07-01T09:16:02Z"
          cancelled_at: null
          completed_at: null
          product:
            uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
            slug: kroyaziera-aigina-agkistri
            title: Κρουαζιέρα Αίγινα & Αγκίστρι
            category: shared_full_day
            mode: per_seat
            duration_minutes: 480
            currency: EUR
            from_price_cents: 6500
            is_featured: true
            sort_order: 10
            updated_at: "2026-06-18T08:12:00Z"

    CheckoutRequestDeposit:
      summary: Pay the 30% deposit through the operator's Viva account
      value:
        kind: deposit
        gateway: viva
        return_url: 'https://aegeancruises.gr/booking/thank-you'
        cancel_url: 'https://aegeancruises.gr/booking/cancelled'

    CheckoutSessionResponse:
      summary: Redirect the guest to redirect_url
      value:
        data:
          payment_uuid: e5f6a7b8-c9d0-4e1f-a2b3-c4d5e6f7a8b9
          booking_uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          gateway: viva
          kind: deposit
          amount_cents: 4275
          amount_formatted: 42,75 €
          currency: EUR
          redirect_url: 'https://www.vivapayments.com/web/checkout?ref=1234567890123456'
          expires_at: "2026-07-01T09:44:31Z"
          hold_expires_at: "2026-07-01T09:29:31Z"
          is_test: false

    CancelRequestDryRun:
      summary: Show the guest the refund before they commit
      value:
        dry_run: true

    CancellationResultResponse:
      summary: Dry run — 100% back under the flexible policy
      value:
        data:
          performed: false
          booking_uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          status: confirmed
          refund:
            amount_cents: 4275
            amount_formatted: 42,75 €
            percent: 100
            currency: EUR
            method: gateway
            status: not_applicable
            voucher_code: null
            voucher_expires_at: null
            expected_settlement_days: 5
          policy:
            name: Ευέλικτη
            summary: Δωρεάν ακύρωση έως 48 ώρες πριν την αναχώρηση.
            free_cancellation_hours: 48
            tiers:
              - { days_before: 15, refund_percent: 100 }
              - { days_before: 7, refund_percent: 50 }
              - { days_before: 2, refund_percent: 0 }
            weather_refund_percent: 100
            force_majeure_voucher_months: 18
            no_show_refund_percent: 0
            captured_at: "2026-07-01T09:16:02Z"
          applied_tier: { days_before: 15, refund_percent: 100 }
          hours_before_departure: 310

    EnquiryCreateRequestGreek:
      summary: A Greek enquiry about a private charter
      value:
        product_uuid: b2d4f6a8-1c3e-4058-9a7b-2c4e6f8a0b1d
        name: Γιώργος Νικολάου
        email: g.nikolaou@example.gr
        phone: '+306971112233'
        preferred_date: "2026-08-12"
        pax: 12
        message: Καλησπέρα σας. Ενδιαφερόμαστε για ιδιωτική ναύλωση ολοήμερη στις 12 Αυγούστου για 12 άτομα. Θα θέλαμε να μάθουμε αν υπάρχει διαθεσιμότητα και τι περιλαμβάνει η τιμή.
        locale: el
        company_website: null
        consent: true

    EnquiryResponse:
      summary: Received
      value:
        data:
          uuid: d4e5f6a7-b8c9-4d0e-9f1a-2b3c4d5e6f70
          status: new
          product_uuid: b2d4f6a8-1c3e-4058-9a7b-2c4e6f8a0b1d
          locale: el
          created_at: "2026-07-02T11:05:44Z"

    QuoteResponse:
      summary: A sent quote the guest may still accept
      value:
        data:
          uuid: f1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b
          version: 2
          status: sent
          currency: EUR
          message: Καλησπέρα σας, σας στέλνουμε την προσφορά για την ιδιωτική ναύλωση στις 12 Αυγούστου.
          terms: Η προκαταβολή είναι 30% και επιστρέφεται σύμφωνα με την πολιτική ακύρωσης.
          line_items:
            - { label: Ιδιωτική ναύλωση ολοήμερη, description: "09:00–17:00, Meltemi", kind: charter, qty: 1, unit_price_cents: 95000, total_cents: 95000, sort_order: 10 }
            - { label: Γεύμα επί του σκάφους, description: null, kind: extra, qty: 12, unit_price_cents: 1800, total_cents: 21600, sort_order: 20 }
            - { label: Έκπτωση πιστού πελάτη, description: null, kind: discount, qty: 1, unit_price_cents: 6600, total_cents: 6600, sort_order: 30 }
          subtotal_cents: 116600
          discount_cents: 6600
          total_cents: 110000
          total_formatted: 1.100,00 €
          deposit_cents: 33000
          vat: { rate_bp: 1300, included: true, net_cents: 97345, vat_cents: 12655 }
          valid_until: "2026-07-16T20:59:59Z"
          booking:
            uuid: 0a1b2c3d-4e5f-4061-8273-849506172839
            reference: KAI-9M2QX
            status: quote_sent
            window:
              local_date: "2026-08-12"
              local_time: "09:00"
              starts_at: "2026-08-12T06:00:00Z"
              ends_at: "2026-08-12T14:00:00Z"
              timezone: Europe/Athens
              dst_ambiguous: false
            pax_total: 12
          can_accept: true
          accept_blocked_reason: null
          superseded_by_version: null
          viewed_at: "2026-07-09T18:22:10Z"
          accepted_at: null
          declined_at: null

    QuoteAcceptRequestExample:
      summary: Accept and pay the deposit
      value:
        kind: deposit
        gateway: viva
        return_url: 'https://book.kaiki.app/q/4c8a1f9e2b7d0356ea41c9f8b2d5e70a3c6b1d94/thank-you'
        terms_accepted: true

    QuoteAcceptResultResponse:
      summary: Accepted; redirect to the gateway
      value:
        data:
          booking_uuid: 0a1b2c3d-4e5f-4061-8273-849506172839
          manage_token: 6a4c2e0f8b1d3579ace1f3b5d7092468ac0e2f46
          quote:
            uuid: f1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b
            version: 2
            status: accepted
            currency: EUR
            line_items: []
            subtotal_cents: 116600
            discount_cents: 6600
            total_cents: 110000
            deposit_cents: 33000
            valid_until: "2026-07-16T20:59:59Z"
            booking:
              uuid: 0a1b2c3d-4e5f-4061-8273-849506172839
              reference: KAI-9M2QX
              status: pending_payment
              window:
                local_date: "2026-08-12"
                local_time: "09:00"
                starts_at: "2026-08-12T06:00:00Z"
                ends_at: "2026-08-12T14:00:00Z"
                timezone: Europe/Athens
                dst_ambiguous: false
            can_accept: false
            accept_blocked_reason: already_decided
            accepted_at: "2026-07-10T07:41:03Z"
          checkout:
            payment_uuid: 7b8c9d0e-1f2a-4b3c-8d4e-5f6a7b8c9d0e
            booking_uuid: 0a1b2c3d-4e5f-4061-8273-849506172839
            gateway: viva
            kind: deposit
            amount_cents: 33000
            amount_formatted: 330,00 €
            currency: EUR
            redirect_url: 'https://www.vivapayments.com/web/checkout?ref=1234567890123456'
            expires_at: "2026-07-10T08:11:03Z"
            hold_expires_at: "2026-07-10T07:56:03Z"
            is_test: false

    QuoteDeclineRequestExample:
      summary: Decline with a reason
      value:
        reason: Βρήκαμε άλλη ημερομηνία, ευχαριστούμε πολύ.

    QuoteDeclinedResponse:
      summary: The quote after declining
      value:
        data:
          uuid: f1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b
          version: 2
          status: declined
          currency: EUR
          line_items: []
          subtotal_cents: 116600
          discount_cents: 6600
          total_cents: 110000
          deposit_cents: 33000
          valid_until: "2026-07-16T20:59:59Z"
          booking:
            uuid: 0a1b2c3d-4e5f-4061-8273-849506172839
            reference: KAI-9M2QX
            status: cancelled
            window:
              local_date: "2026-08-12"
              local_time: "09:00"
              starts_at: "2026-08-12T06:00:00Z"
              ends_at: "2026-08-12T14:00:00Z"
              timezone: Europe/Athens
              dst_ambiguous: false
          can_accept: false
          accept_blocked_reason: already_decided
          declined_at: "2026-07-10T07:44:19Z"

    GuestDetailsPageResponse:
      summary: Manifest form, nothing filled in yet
      value:
        data:
          booking_uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          reference: KAI-7F3K2
          status: pending
          locale: el
          product_title: Κρουαζιέρα Αίγινα & Αγκίστρι
          window:
            local_date: "2026-07-14"
            local_time: "09:30"
            starts_at: "2026-07-14T06:30:00Z"
            ends_at: "2026-07-14T14:30:00Z"
            timezone: Europe/Athens
            dst_ambiguous: false
          meeting_point:
            uuid: 5e7c9a1b-3d5f-4a7c-9e1b-3d5f7a9c1e3b
            name: Μαρίνα Ζέας
            address: 'Ακτή Θεμιστοκλέους, Πειραιάς 185 36'
            instructions: Συνάντηση στο μπλε περίπτερο, δίπλα στην προβλήτα Δ.
          deadline_at: "2026-07-12T06:30:00Z"
          is_editable: true
          required_fields: [full_name, date_of_birth, nationality, document_number]
          guests:
            - { uuid: c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f, position: 1, age_band_code: adult, age_band_label: Ενήλικας, full_name: null, is_lead: true }
            - { uuid: d2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f60, position: 2, age_band_code: adult, age_band_label: Ενήλικας, full_name: null, is_lead: false }
            - { uuid: e3f4a5b6-c7d8-4e9f-a0b1-2c3d4e5f6071, position: 3, age_band_code: child, age_band_label: Παιδί, full_name: null, is_lead: false }
            - { uuid: f4a5b6c7-d8e9-4f0a-b1c2-3d4e5f607182, position: 4, age_band_code: infant, age_band_label: Βρέφος, full_name: null, is_lead: false }
          charter_agreement: null
          completed_count: 0
          total_count: 4

    GuestDetailsUpdateRequestGreek:
      summary: Final submit of a Greek manifest
      value:
        submit: true
        guests:
          - position: 1
            full_name: Μαρία Παπαδοπούλου
            date_of_birth: "1989-04-12"
            nationality: GR
            document_type: id_card
            document_number: ΑΚ482100
          - position: 2
            full_name: Δημήτρης Παπαδόπουλος
            date_of_birth: "1986-11-03"
            nationality: GR
            document_type: passport
            document_number: AZ1234567
            document_expires_on: "2031-05-20"
          - position: 3
            full_name: Ελένη Παπαδοπούλου
            date_of_birth: "2017-08-30"
            nationality: GR
            document_type: passport
            document_number: AZ7654321
            notes: Αλλεργία στους ξηρούς καρπούς.
          - position: 4
            full_name: Νίκος Παπαδόπουλος
            date_of_birth: "2025-02-14"
            nationality: GR
            document_type: passport
            document_number: AZ1122334
        charter_agreement: null

    GuestDetailsCompleteResponse:
      summary: Manifest complete; document numbers returned only masked
      value:
        data:
          booking_uuid: 1b4e28ba-2fa1-11d2-883f-0016d3cca427
          reference: KAI-7F3K2
          status: complete
          locale: el
          product_title: Κρουαζιέρα Αίγινα & Αγκίστρι
          window:
            local_date: "2026-07-14"
            local_time: "09:30"
            starts_at: "2026-07-14T06:30:00Z"
            ends_at: "2026-07-14T14:30:00Z"
            timezone: Europe/Athens
            dst_ambiguous: false
          deadline_at: "2026-07-12T06:30:00Z"
          is_editable: true
          required_fields: [full_name, date_of_birth, nationality, document_number]
          guests:
            - uuid: c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f
              position: 1
              age_band_code: adult
              age_band_label: Ενήλικας
              full_name: Μαρία Παπαδοπούλου
              date_of_birth: "1989-04-12"
              nationality: GR
              document_type: id_card
              document_number_masked: '••••2100'
              is_lead: true
              ticket_code: TKT7F3K2A1B2C3D4E5F6G7H8
              checked_in_at: null
          charter_agreement: null
          completed_count: 4
          total_count: 4

    SyncProductsResponse:
      summary: One live product and one tombstone
      value:
        data:
          - uuid: 7c9e6679-7425-40de-944b-e07fc1f90ae7
            slug: kroyaziera-aigina-agkistri
            status: active
            mode: per_seat
            category: shared_full_day
            tombstone: false
            deleted_at: null
            updated_at: "2026-06-18T08:12:00Z"
            content_hash: 9f2c1a7e4b8d0356
            translations:
              el:
                title: Κρουαζιέρα Αίγινα & Αγκίστρι
                summary: Ολοήμερη κρουαζιέρα με παραδοσιακό καΐκι.
                description: "<p>Αναχωρούμε από τη Μαρίνα Ζέας…</p>"
                includes: [Γεύμα και ποτά, Εξοπλισμός κολύμβησης]
                excludes: [Μεταφορά από/προς ξενοδοχείο]
                what_to_bring: [Αντηλιακό, Πετσέτα]
                meta_title: Κρουαζιέρα Αίγινα & Αγκίστρι από Πειραιά
                meta_description: Ολοήμερη κρουαζιέρα με παραδοσιακό καΐκι σε Αίγινα και Αγκίστρι.
              en:
                title: Aegina & Agistri Cruise
                summary: Full-day cruise on a traditional kaiki.
                description: "<p>We depart from Zea Marina…</p>"
                includes: [Lunch and drinks, Snorkelling gear]
                excludes: [Hotel transfers]
                what_to_bring: [Sunscreen, Towel]
                meta_title: Aegina & Agistri Cruise from Piraeus
                meta_description: Full-day traditional kaiki cruise to Aegina and Agistri.
            product: null
          - uuid: aa11bb22-cc33-4d44-8e55-ff6677889900
            slug: palia-ekdromi--del91
            status: archived
            tombstone: true
            deleted_at: "2026-08-01T10:00:00Z"
            updated_at: "2026-08-01T10:00:00Z"
        pagination:
          per_page: 100
          has_more: false
          next_cursor: null
          prev_cursor: null
          next_url: null
        meta:
          sync_cursor: "2026-08-01T10:00:00Z"
          timezone: Europe/Athens
          default_locale: el
          locales: [el, en]
```

---

## 6. Worked examples

A complete `per_seat` booking as the widget performs it, in Greek, followed by the flows that branch off it. Bodies are elided with `…` only where the OpenAPI examples above show them in full.

### 6.1 Branding — the widget's first call

```http
GET /api/v1/branding HTTP/1.1
Host: api.kaiki.app
X-Kaiki-Key: pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Accept-Language: el-GR,el;q=0.9,en;q=0.8
Origin: https://aegeancruises.gr
```

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8
Content-Language: el
Cache-Control: public, max-age=60
ETag: "b7d41f0a9c2e"
Vary: Accept-Language, Origin, X-Kaiki-Key
Access-Control-Allow-Origin: https://aegeancruises.gr
X-Kaiki-Api-Version: 1
X-Request-Id: 01JZ8Q3M7K5V2N9X4T6B8W1Y0R
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 599
X-RateLimit-Reset: 1785312060
```

```json
{
  "data": {
    "tenant": { "uuid": "2c4a6e80-1b3d-4f5a-8c7e-9d0b1a2f3e4c", "name": "Aegean Cruises", "slug": "aegean-cruises", "timezone": "Europe/Athens", "default_locale": "el", "currency": "EUR" },
    "colors": { "primary": "#0B4F4A", "secondary": "#063733", "accent": "#B5511F", "background": "#FFFFFF", "text": "#16211F" },
    "font": { "family": "Inter", "source": "google", "css_url": "https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" },
    "button_radius_px": 8,
    "widget_theme": "auto",
    "css_variables": {
      "--kaiki-primary": "#0B4F4A",
      "--kaiki-accent": "#B5511F",
      "--kaiki-radius": "8px",
      "--kaiki-font-family": "Inter, system-ui, sans-serif"
    },
    "custom_css": null,
    "is_test": false
  }
}
```

The widget writes `css_variables` onto its Shadow DOM root and never hardcodes a colour.

### 6.2 Availability for July, four passengers

```http
GET /api/v1/availability?product=7c9e6679-7425-40de-944b-e07fc1f90ae7&from=2026-07-14&to=2026-07-16&pax=4 HTTP/1.1
Host: api.kaiki.app
X-Kaiki-Key: pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Accept-Language: el
```

```json
{
  "data": [
    {
      "local_date": "2026-07-14",
      "status": "available",
      "from_price_cents": 6500,
      "currency": "EUR",
      "departures": [
        {
          "uuid": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f",
          "window": {
            "local_date": "2026-07-14",
            "local_time": "09:30",
            "starts_at": "2026-07-14T06:30:00Z",
            "ends_at": "2026-07-14T14:30:00Z",
            "timezone": "Europe/Athens",
            "dst_ambiguous": false
          },
          "check_in_local_time": "09:00",
          "status": "guaranteed",
          "capacity": 24,
          "seats_available": 11,
          "is_guaranteed": true,
          "from_price_cents": 6500,
          "currency": "EUR"
        }
      ],
      "windows": []
    },
    { "local_date": "2026-07-15", "status": "sold_out", "departures": [], "windows": [] },
    { "local_date": "2026-07-16", "status": "not_operating", "departures": [], "windows": [] }
  ],
  "meta": { "product_uuid": "7c9e6679-7425-40de-944b-e07fc1f90ae7", "mode": "per_seat", "timezone": "Europe/Athens", "currency": "EUR", "from": "2026-07-14", "to": "2026-07-16", "pax": 4 }
}
```

On `2026-07-15` the departure exists with `seats_available: 2`, so the **day** is `sold_out` for a party of four while the calendar can still show "only 2 left". The client renders `local_time` verbatim: a tourist in Berlin sees `09:30`, not `08:30`.

### 6.3 Price quote — the only place a price is computed

```http
POST /api/v1/price-quote HTTP/1.1
Host: api.kaiki.app
Content-Type: application/json
X-Kaiki-Key: pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Accept-Language: el
```

```json
{
  "product_uuid": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "departure_uuid": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f",
  "pax": [
    { "age_band_uuid": "0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10", "qty": 2 },
    { "age_band_uuid": "9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d", "qty": 1 },
    { "age_band_uuid": "5a9d8c77-1e42-4f0b-b3aa-90c4e1d27f33", "qty": 1 }
  ],
  "extras": [{ "extra_uuid": "a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d", "qty": 3 }],
  "voucher_code": "KAI-VOUCH-4F7K"
}
```

```json
{
  "data": {
    "currency": "EUR",
    "pax_total": 4,
    "pax_capacity_total": 3,
    "lines": [
      { "kind": "pax", "ref": "adult", "label": "Ενήλικας", "qty": 2, "unit_price_cents": 6500, "total_cents": 13000 },
      { "kind": "pax", "ref": "child", "label": "Παιδί", "qty": 1, "unit_price_cents": 3250, "total_cents": 3250, "multiplier_bp": 5000 },
      { "kind": "pax", "ref": "infant", "label": "Βρέφος", "qty": 1, "unit_price_cents": 0, "total_cents": 0 },
      { "kind": "extra", "ref": "a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d", "label": "Μεταφορά από ξενοδοχείο", "qty": 3, "unit_price_cents": 1000, "total_cents": 3000 },
      { "kind": "discount", "ref": "voucher:KAI-VOUCH-4F7K", "label": "Κουπόνι", "qty": 1, "unit_price_cents": 5000, "total_cents": 5000 }
    ],
    "subtotal_cents": 16250,
    "extras_cents": 3000,
    "discount_cents": 5000,
    "total_cents": 14250,
    "total_formatted": "142,50 €",
    "vat": { "rate_bp": 1300, "included": true, "net_cents": 12611, "vat_cents": 1639 },
    "deposit": { "type": "percent", "percent": 30, "amount_cents": 4275, "amount_formatted": "42,75 €", "balance_cents": 9975, "balance_due_at": "2026-06-30T21:00:00Z" },
    "voucher": { "code": "KAI-VOUCH-4F7K", "applied_cents": 5000, "remaining_cents_after": 0, "currency": "EUR" },
    "on_request_items": [],
    "price_token": "pt_2f9c1e.eyJ0b3RhbCI6MTQyNTB9.9a3f",
    "expires_at": "2026-07-01T09:29:22Z",
    "computed_at": "2026-07-01T09:14:22Z",
    "rounding": "HALF_UP"
  }
}
```

The infant does not occupy a seat (`counts_toward_capacity: false`), so `pax_capacity_total` is 3 while `pax_total` is 4. That difference is what the availability engine decrements.

### 6.4 Create the draft and take the hold

```http
POST /api/v1/bookings HTTP/1.1
Host: api.kaiki.app
Content-Type: application/json
X-Kaiki-Key: pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Idempotency-Key: 8f14e45f-ceea-4e0b-9c1a-4f8b2d3e5a90
Accept-Language: el
Origin: https://aegeancruises.gr
```

```json
{
  "product_uuid": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "departure_uuid": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f",
  "pax": [
    { "age_band_uuid": "0c2f1b3a-7d64-4a8e-9c11-2b7f4a5d6e10", "qty": 2 },
    { "age_band_uuid": "9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d", "qty": 1 },
    { "age_band_uuid": "5a9d8c77-1e42-4f0b-b3aa-90c4e1d27f33", "qty": 1 }
  ],
  "extras": [{ "extra_uuid": "a3d1e5f7-2b98-4c60-8d31-7e5f9a0b1c2d", "qty": 3 }],
  "voucher_code": "KAI-VOUCH-4F7K",
  "guest": {
    "name": "Μαρία Παπαδοπούλου",
    "email": "maria@example.gr",
    "phone": "+306941234567",
    "nationality": "GR"
  },
  "special_requests": "Ένα από τα παιδιά έχει αλλεργία στους ξηρούς καρπούς.",
  "locale": "el",
  "price_token": "pt_2f9c1e.eyJ0b3RhbCI6MTQyNTB9.9a3f",
  "terms_accepted": true,
  "source": "widget"
}
```

```http
HTTP/1.1 201 Created
Location: https://api.kaiki.app/api/v1/bookings/1b4e28ba-2fa1-11d2-883f-0016d3cca427
Cache-Control: no-store
Content-Language: el
```

```json
{
  "data": {
    "uuid": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    "reference": "KAI-7F3K2",
    "status": "draft",
    "mode": "per_seat",
    "locale": "el",
    "is_test": false,
    "manage_token": "9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9",
    "hold_expires_at": "2026-07-01T09:29:31Z",
    "money": { "currency": "EUR", "total_cents": 14250, "total_formatted": "142,50 €", "deposit_cents": 4275, "paid_cents": 0, "balance_cents": 14250 },
    "links": { "manage_url": "https://book.kaiki.app/b/9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9" }
  }
}
```

**`manage_token` is returned exactly once, here.** Capture it now; no endpoint hands it out again.

Losing the race for the last seats looks like this:

```http
HTTP/1.1 409 Conflict
Content-Type: application/json; charset=utf-8
```

```json
{
  "error": {
    "code": "insufficient_capacity",
    "message": "Only 2 seats remain on this departure.",
    "message_el": "Απομένουν μόνο 2 θέσεις σε αυτή την αναχώρηση.",
    "details": { "departure_uuid": "9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f", "seats_requested": 3, "seats_available": 2 }
  }
}
```

### 6.5 Checkout

```http
POST /api/v1/bookings/1b4e28ba-2fa1-11d2-883f-0016d3cca427/checkout HTTP/1.1
Host: api.kaiki.app
Content-Type: application/json
X-Kaiki-Key: pk_live_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
Idempotency-Key: 2b7f4a5d-6e10-4c2f-9b3a-7d644a8e9c11
```

```json
{ "kind": "deposit", "gateway": "viva", "return_url": "https://aegeancruises.gr/booking/thank-you" }
```

```json
{
  "data": {
    "payment_uuid": "e5f6a7b8-c9d0-4e1f-a2b3-c4d5e6f7a8b9",
    "booking_uuid": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    "gateway": "viva",
    "kind": "deposit",
    "amount_cents": 4275,
    "amount_formatted": "42,75 €",
    "currency": "EUR",
    "redirect_url": "https://www.vivapayments.com/web/checkout?ref=1234567890123456",
    "expires_at": "2026-07-01T09:44:31Z",
    "hold_expires_at": "2026-07-01T09:29:31Z",
    "is_test": false
  }
}
```

The widget redirects to `redirect_url`. **Nothing in the public API confirms a booking** — the gateway webhook does (§7). The guest lands on `return_url`, where the page polls `GET /api/v1/bookings/{uuid}` with the `manage_token` until `status` is `confirmed`.

Replaying the same `Idempotency-Key` returns the identical body with `Idempotency-Replayed: true` and does not create a second gateway session.

### 6.6 Guest details — the manifest, in Greek

```http
PUT /api/v1/guest-details/b7e3d1a9c5f204867e1b3d5a9c7f0e2b4d6a8c10 HTTP/1.1
Host: api.kaiki.app
Content-Type: application/json
Accept-Language: el
```

```json
{
  "submit": true,
  "guests": [
    { "position": 1, "full_name": "Μαρία Παπαδοπούλου", "date_of_birth": "1989-04-12", "nationality": "GR", "document_type": "id_card", "document_number": "ΑΚ482100" },
    { "position": 2, "full_name": "Δημήτρης Παπαδόπουλος", "date_of_birth": "1986-11-03", "nationality": "GR", "document_type": "passport", "document_number": "AZ1234567", "document_expires_on": "2031-05-20" },
    { "position": 3, "full_name": "Ελένη Παπαδοπούλου", "date_of_birth": "2017-08-30", "nationality": "GR", "document_type": "passport", "document_number": "AZ7654321", "notes": "Αλλεργία στους ξηρούς καρπούς." },
    { "position": 4, "full_name": "Νίκος Παπαδόπουλος", "date_of_birth": "2025-02-14", "nationality": "GR", "document_type": "passport", "document_number": "AZ1122334" }
  ]
}
```

```json
{
  "data": {
    "booking_uuid": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    "reference": "KAI-7F3K2",
    "status": "complete",
    "is_editable": true,
    "required_fields": ["full_name", "date_of_birth", "nationality", "document_number"],
    "guests": [
      {
        "uuid": "c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f",
        "position": 1,
        "age_band_code": "adult",
        "age_band_label": "Ενήλικας",
        "full_name": "Μαρία Παπαδοπούλου",
        "date_of_birth": "1989-04-12",
        "nationality": "GR",
        "document_type": "id_card",
        "document_number_masked": "••••2100",
        "is_lead": true,
        "ticket_code": "TKT7F3K2A1B2C3D4E5F6G7H8"
      }
    ],
    "completed_count": 4,
    "total_count": 4
  }
}
```

`document_number` goes in and never comes back. It is stored encrypted, excluded from every log and cache, decrypted only inside the manifest export job, and nulled by the retention purge N days after departure.

A `per_vessel` booking sends the ναυλοσύμφωνο acceptance in the same call. The client supplies only the flag and the version it displayed; the IP and timestamp — the legal evidence — are captured server-side:

```json
{
  "submit": true,
  "guests": [],
  "charter_agreement": { "accepted": true, "version": "v1" }
}
```

Omitting it when it is required:

```json
{
  "error": {
    "code": "charter_agreement_required",
    "message": "You must accept the charter agreement (ναυλοσύμφωνο) to continue.",
    "message_el": "Πρέπει να αποδεχτείτε το ναυλοσύμφωνο για να συνεχίσετε.",
    "details": { "agreement_version": "v1" }
  }
}
```

### 6.7 Guest cancellation — dry run, then commit

```http
POST /api/v1/bookings/1b4e28ba-2fa1-11d2-883f-0016d3cca427/cancel HTTP/1.1
Host: api.kaiki.app
Content-Type: application/json
X-Kaiki-Guest-Token: 9d2f6b1c4a7e0538bd91c6e2f4a80b37c5d1e6a9
Idempotency-Key: 4a5d6e10-0c2f-4b3a-9d64-4a8e9c112b7f
```

```json
{ "dry_run": true }
```

```json
{
  "data": {
    "performed": false,
    "status": "confirmed",
    "refund": { "amount_cents": 4275, "amount_formatted": "42,75 €", "percent": 100, "currency": "EUR", "method": "gateway", "status": "not_applicable", "expected_settlement_days": 5 },
    "applied_tier": { "days_before": 15, "refund_percent": 100 },
    "hours_before_departure": 310
  }
}
```

The guest sees `42,75 €` — the deposit they actually paid, at 100%, under the tier that applied **when they booked**, not under whatever the operator's policy says today. Re-sending with `"dry_run": false` and a fresh `Idempotency-Key` performs the cancellation, releases the seats immediately and queues the gateway refund.

Past the deadline:

```json
{
  "error": {
    "code": "cancellation_window_closed",
    "message": "The cancellation deadline for this booking has passed.",
    "message_el": "Η προθεσμία ακύρωσης για αυτή την κράτηση έχει παρέλθει.",
    "details": { "deadline_at": "2026-07-12T06:30:00Z", "hours_before_departure": 20 }
  }
}
```

### 6.8 Server-to-server catalogue sync (WordPress)

```http
GET /api/v1/sync/products?updated_since=2026-08-20T11:04:00Z&per_page=100 HTTP/1.1
Host: api.kaiki.app
Authorization: Bearer sk_live_z9y8x7w6v5u4t3s2r1q0p9o8n7m6l5k4
If-None-Match: "f0a91c2e7b4d"
```

Nothing changed since the last poll:

```http
HTTP/1.1 304 Not Modified
ETag: "f0a91c2e7b4d"
```

When there are changes the body is the `SyncProductsResponse` example above: unresolved `translations` keyed by locale, so the plugin can create one CPT entry per WPML/Polylang language, plus tombstones it must unpublish or leave orphan pages indexed.

The same key used from a browser fails on the first call, which is the point:

```http
GET /api/v1/sync/products HTTP/1.1
Authorization: Bearer sk_live_z9y8x7w6v5u4t3s2r1q0p9o8n7m6l5k4
Origin: https://aegeancruises.gr
```

```json
{
  "error": {
    "code": "secret_key_in_browser",
    "message": "A secret key cannot be used from a browser. Use a publishable key.",
    "message_el": "Το μυστικό κλειδί δεν μπορεί να χρησιμοποιηθεί από φυλλομετρητή. Χρησιμοποιήστε δημόσιο κλειδί.",
    "details": { "origin": "https://aegeancruises.gr" }
  }
}
```

---

## 7. Inbound webhooks (not public API)

> **These endpoints are deliberately excluded from the OpenAPI `paths` above.** They are not versioned, not documented for third parties, not rate-limited the same way, and not callable with any Kaiki credential. They exist only so a payment gateway can tell us money moved. They are described here because they are the **only** way a booking becomes `confirmed`, and no reader of this document should have to guess that.

| Endpoint | Caller | Purpose |
|---|---|---|
| `POST /webhooks/viva` | Viva Wallet | Payment created / refunded / failed |

### 7.1 Signature verification

Both endpoints reject anything they cannot cryptographically attribute to the gateway, **before** parsing the body:

- **Viva** — Viva Smart Checkout webhooks are verified by matching the delivered payload against a fresh, authenticated lookup of the order/transaction using the operator's own Viva credentials, plus the `Authorization` verification-key handshake Viva requires at endpoint registration. **OPEN — the exact Viva verification mechanism.** This is a factual question about a third party, not an architecture fork, so it gets no ADR: ADR-0004 (Option A + D) settles credential storage and the two-session deposit model, and PAY-5 fixes that webhooks are verified and idempotent, but neither states *how* Viva's handshake works. Viva's scheme has changed across API generations and MUST be confirmed against current official documentation before implementation — the `payments-integrations` agent is required to `WebFetch` the live docs rather than rely on memory. Provisional default, in force: verification-key handshake **plus** a mandatory server-side re-fetch of the transaction under the operator's own credentials, treating the webhook purely as a "something changed" signal and never as the source of the amount. The re-fetch requirement stands regardless of what the handshake turns out to be.
- **Never** trust an amount, a currency, a booking reference or a status from the webhook body alone. Every one of them is re-read from the gateway, or from our own `payments` row, before any money logic runs.
- Credentials live in `integration_credentials.credentials` (`encrypted:array`) per tenant, per gateway, per environment. Test-mode events verify against test credentials only.

### 7.2 Processing contract

1. **Record first, process later.** The raw event is written to `gateway_webhook_events` (payload `encrypted:array`) inside its own transaction. The endpoint then returns `2xx` immediately — within 200 ms, before any domain work.
2. **Respond fast, work in a job.** All domain work happens in a queued job. A gateway that times out retries, and a retry storm during a deploy must not double-confirm anything.
3. **Idempotent by construction.** Deduplication is on the gateway's own event id, unique in `gateway_webhook_events`. A duplicate delivery is a no-op *before* any money logic runs. The payment is then matched by `(gateway, gateway_ref)` — the one index in the schema that deliberately does not lead with `tenant_id`, because tenancy is not yet resolved when the request arrives.
4. **Tenancy is resolved from the payment**, not from the request. `gateway_webhook_events.tenant_id` is nullable and backfilled once matched. An event that matches nothing is retained, alerted on, and never guessed at.
5. **Late arrivals are expected, not exceptional.** A `payment_succeeded` that lands after the hold expired re-checks availability: if seats are still free the booking confirms; if not, the booking stays `expired`, an operator alert is raised, and the operator refunds or rebooks. This path has its own test.
6. **Always `2xx` on a well-formed, verified event**, even one we intend to ignore. `4xx` is reserved for signature failures and malformed bodies; `5xx` is reserved for our own faults, where we *want* the gateway to retry.
7. Signature secrets, card data and gateway payloads never enter a log line, a Sentry breadcrumb or a queue payload in plaintext.

---

## 8. Outbound webhooks (not public API)

> Also **excluded from the OpenAPI `paths`**: these are requests Kaiki *makes*, to a URL the operator configures in `/app → Settings → Webhooks`. Endpoint management is a back-office concern and is **out of scope for public API v1** — there is no `POST /api/v1/webhook-endpoints`. An operator configures endpoints in the panel; an integrator cannot create one through this API. That is a deliberate v1 limitation, not an oversight.

### 8.1 Events

Exactly four in v1, matching `webhook_endpoints.events` (`docs/data-model.md` §3.13):

| Event | Fires when |
|---|---|
| `booking.confirmed` | A booking reaches `confirmed` (gateway webhook, or an operator marking a manual booking paid) |
| `booking.cancelled` | A booking reaches `cancelled`, by guest, operator or a cascade from a cancelled departure |
| `departure.cancelled` | A departure is cancelled — weather, operator, `min_pax`, or a private charter taking the vessel |
| `guest_details.completed` | Every passenger on a booking has the fields the operator requires |

Unknown event names are rejected at save time against `app/Domain/Webhooks/EventRegistry.php`, so a typo cannot silently disable a subscription.

### 8.2 Delivery envelope

```http
POST /kaiki-webhook HTTP/1.1
Host: aegeancruises.gr
Content-Type: application/json; charset=utf-8
User-Agent: Kaiki-Webhooks/1
Kaiki-Event: booking.confirmed
Kaiki-Delivery-Id: 01JZ8Q7V2M4N6P8R0T2W4Y6A8C
Kaiki-Timestamp: 1785312062
Kaiki-Signature: v1=6f3b9c1e8a2d4f70b5c6e7a8d9f0123456789abcdef0123456789abcdef012345
Kaiki-Attempt: 1
```

```json
{
  "id": "01JZ8Q7V2M4N6P8R0T2W4Y6A8C",
  "event": "booking.confirmed",
  "api_version": "1",
  "created_at": "2026-07-01T09:16:03Z",
  "tenant": { "uuid": "2c4a6e80-1b3d-4f5a-8c7e-9d0b1a2f3e4c", "slug": "aegean-cruises" },
  "is_test": false,
  "data": {
    "booking": {
      "uuid": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
      "reference": "KAI-7F3K2",
      "status": "confirmed",
      "mode": "per_seat",
      "locale": "el",
      "product": { "uuid": "7c9e6679-7425-40de-944b-e07fc1f90ae7", "title": "Κρουαζιέρα Αίγινα & Αγκίστρι" },
      "window": {
        "local_date": "2026-07-14",
        "local_time": "09:30",
        "starts_at": "2026-07-14T06:30:00Z",
        "ends_at": "2026-07-14T14:30:00Z",
        "timezone": "Europe/Athens"
      },
      "guest": { "name": "Μαρία Παπαδοπούλου", "email": "maria@example.gr" },
      "pax_total": 4,
      "pax_capacity_total": 3,
      "money": { "currency": "EUR", "total_cents": 14250, "paid_cents": 4275, "balance_cents": 9975 }
    }
  }
}
```

Payload rules:

- `data` uses the **same schemas** as this API. A `Booking` in a webhook is the `Booking` schema, minus `manage_token` and `links`, which are the guest's to hold and not the integrator's.
- **No personal document data, ever.** `guest_details.completed` reports *that* the manifest is complete and how many rows; it never carries `document_number`, masked or otherwise. An integrator who needs the manifest uses the operator's own export, which is an audited action in the panel.
- `is_test` is present on every delivery. A sandbox tenant's events go to the same endpoint, flagged. Consumers must branch on it or they will pollute production systems with test bookings.
- The envelope is additive-only. New top-level keys and new `data` fields may appear at any time; consumers must ignore what they do not recognise.

### 8.3 Signature

`Kaiki-Signature: v1=<hex>` where `<hex>` is `HMAC-SHA256(secret, "{Kaiki-Timestamp}.{raw request body}")`, and `secret` is `webhook_endpoints.signing_secret` — shown once at creation, thereafter write-only.

Consumers must:

1. Read the **raw** body, before any JSON parsing or re-serialisation. Re-encoding changes the bytes and breaks the signature.
2. Recompute the HMAC and compare with a **constant-time** comparison (`hash_equals`).
3. Reject deliveries whose `Kaiki-Timestamp` is more than **5 minutes** from their own clock, to bound replay.
4. Support **multiple valid signatures** in the header, comma-separated, during a secret rotation: `v1=<new>,v1=<old>`. Rotation publishes both for 24 hours, so an operator can roll a secret without dropping a delivery.

The WordPress plugin's cache-bust endpoint uses this same scheme, so there is one verification routine to review rather than two.

### 8.4 Retries, ordering and idempotency

- **Success** is any `2xx` returned within **10 seconds**. Redirects are not followed. A `3xx`, a timeout or any non-`2xx` is a failure.
- **Retry schedule** — 8 attempts over roughly 24 hours, with jitter: `10 s, 30 s, 2 min, 10 min, 30 min, 2 h, 6 h, 12 h`. `Kaiki-Attempt` counts from 1.
- After the final failure the delivery is marked `failed` in `webhook_deliveries`, surfaced in the panel with the response status and body excerpt, and **manually re-sendable**. Twenty consecutive failures disable the endpoint and email the operator; nobody's queue should burn for a week on a dead URL.
- **`Kaiki-Delivery-Id` is stable across retries.** It is the consumer's idempotency key: store it and ignore a repeat. Retries are guaranteed; exactly-once delivery is not, and any consumer that assumes it will double-book something.
- **Ordering is not guaranteed.** A `booking.cancelled` can arrive before its `booking.confirmed` under retry. Consumers reconcile on `data.booking.status` and `created_at`, never on arrival order.
- Deliveries are queued through Horizon like every other external call — never sent inline from the request that triggered them.
- Endpoint URLs must be HTTPS. Requests to private and link-local address ranges are refused at the HTTP client, so a configured webhook cannot be turned into an SSRF probe of the platform's own network.

---

## 9. Open decisions

**All 23 ADRs in `docs/adr/` were accepted by the product owner on 2026-08-28.** The two questions in this list that an ADR actually settled are now closed, below. The rest were never architecture forks — they are contract and product questions that no ADR was ever going to answer — so they stay `OPEN` with a **provisional default that is in force**. Building against a provisional default is safe; changing one after v1 ships is a breaking change under §1.3, which is the real deadline.

### 9.1 Closed by an accepted ADR

| # | Question | Resolution | Source |
|---|---|---|---|
| 2 | **Scope vocabulary** — dot form vs colon form. | **Dot form.** `products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive`. `docs/api.md` §2.1, `docs/data-model.md` §3.12 and `docs/adr/0013-api-key-model.md` now all use it; ADR-0013's illustrative colon-form examples were corrected on 2026-08-28 without changing the decision. | [ADR-0013](adr/0013-api-key-model.md), Option A |
| 3 | **Does `GET /sync/products` accept a publishable key?** | **No — `sk_` required.** The tension with ADR-0013's "the plugin holds only `pk_`" is resolved by location: the SEO CPT sync runs **server-side only** (WP-Cron and the inbound webhook handler) and its `sk_` never reaches a browser, while everything the plugin renders client-side is `pk_`-only. With SEO pages off, the plugin stores no secret. **This remains a product-owner veto point** — see §2.2. | [ADR-0013](adr/0013-api-key-model.md), Option A + `docs/spec.md` WPP-3 |

Other accepted ADRs that this document depends on but that raised no question here: **ADR-0004** (deposit and balance as two independent checkout sessions — `Deposit`, `POST /bookings/{uuid}/checkout-session`), **ADR-0018** (balance due policy — `Deposit.balance_due_at`, `balance_not_due`), **ADR-0007** (booking reference format — `BookingReference` pattern), **ADR-0017** (voucher surplus stays on the voucher — `GET /vouchers/{code}`), **ADR-0006** (the oversell concurrency test in §10).

### 9.2 Still open

None of these blocks a milestone. Each provisional default is **in force** and may be built against.

| # | **OPEN — question** | Provisional default, in force | Why no ADR | Decide by |
|---|---|---|---|---|
| 1 | **OPEN — is the API served from a dedicated `api.` host, the hosted-page host, or both, and what is the platform domain?** Must an operator's custom domain also proxy `/api/v1`? | Dedicated `https://api.kaiki.app`; hosted pages and custom domains call that same absolute origin. | Product-owner naming plus an ops choice. ADR-0010 settled hostname→tenant resolution and TLS issuance, not API routing. | Before the widget's build-time default is baked in (M3). |
| 4 | **OPEN — keep `price_token`?** The signed price handle between `/price-quote` and `/bookings`, or rely on server recomputation alone? | Keep it; a total that moved returns `409 price_changed` rather than silently charging more. | Contract ergonomics; server-side pricing (PRC-1) is authoritative either way. | Before M2 closes — removing it later is breaking. |
| 5 | **OPEN — how is a seasonal booking window projected to one product-level pair?** | The **strictest** value across active plans (largest lead time, smallest advance window). `GET /availability` remains the authoritative per-date answer. | Presentation choice. | Before M2 closes. |
| 6 | **OPEN — expose advisory `from_price_cents` per age band on `GET /products/{uuid}`?** Useful for "παιδιά από 32,50 €", or a second pricing surface that can disagree with `/price-quote`? | Exposed, explicitly labelled advisory; only `/price-quote` binds. | Product choice. | Before M3 (widget list mount). |
| 7 | **OPEN — idempotency-key retention window, and does a replay return the original status or `200`?** | 24 hours; a replay returns the **original** status verbatim (`201` stays `201`). | Ops sizing question — retention is a storage-cost and support-window trade-off, not an architecture fork. Nothing in ADR-0004 or ADR-0006 constrains it. | Before M2 closes; the store's TTL is one config value. |
| 8 | **OPEN — guest cancellation when the operator has disabled it: endpoint absent, `403`, or `409` with a reason?** | `409 booking_not_cancellable` carrying `contact_email` / `contact_phone`, so the page offers a next step instead of a dead end. | UX choice. | Before M2 closes. |
| 9 | **OPEN — how does `GET /branding` detect it is serving the hosted page rather than a third-party embed, for `custom_css`?** | Server-side context only (hosted-page route or verified custom domain); never a client-supplied parameter. | Implementation detail with a security edge; the safe default is already the strict one. | M3. |
| 10 | **OPEN — is rate limiting enough to stop voucher-code enumeration on `GET /vouchers/{code}` with only a `pk_`, or is a Turnstile/captcha token needed on `/v/{code}`?** | Rate limits (10/min/IP, §3.6 class G) plus a 15-minute block on sustained failures; **no captcha in v1**. | Security-hardening judgement that depends on observed abuse, which does not exist yet. Adding a captcha later is additive and non-breaking. | Revisit on evidence, or at the M8 security review. |
| 11 | **OPEN — does `POST /quotes/{token}/decline` belong in the v1 public API?** It is in the `Quote` state machine but absent from the brief's endpoint list. | Included; the alternative is operators manually expiring dead quotes. | Scope question for the product owner. | Before M2 closes — removing it later is breaking. |
| 12 | **OPEN — the exact Viva webhook verification mechanism.** Viva's scheme has changed across API generations. | Verification-key handshake **plus** a mandatory server-side re-fetch of the transaction; the webhook body is never trusted for amounts. The re-fetch stands whatever the handshake turns out to be. | A factual question about a third party, not a fork. It is answered by reading Viva's current documentation, not by choosing. | At implementation time in M2 — confirm against live official docs, never from memory. |

---

## 10. Conformance & CI

This file is the contract; `dedoc/scramble` generates the implementation's view of it. CI reconciles the two.

1. **Extract.** A CI step extracts the single fenced `yaml` block from §5 into `build/openapi.contract.yaml`. There is exactly one fenced YAML block in this document, by design — a second one would make the extraction ambiguous, so do not add another.
2. **Validate.** `spectral lint` (or equivalent) validates the contract against OpenAPI 3.1 and fails on: an unresolved `$ref`, an operation without `operationId`, `summary`, `security` or a `4xx` response, a duplicated `operationId`, or a schema property that is neither `required` nor nullable.
3. **Generate.** `php artisan scramble:export` produces `build/openapi.generated.yaml` from the routes.
4. **Diff.** A structural diff compares the two on: the set of paths and methods, `operationId`s, the `security` requirement of every operation, required request-body fields, response status codes, and `$ref` targets. Prose, `description`, `example` and ordering are ignored — this document is allowed to explain more than Scramble can infer.
5. **Fail loudly.** Any structural difference fails the build. The fix is one of two things, decided in the PR: change the code, or change this file **and** say why in `CHANGELOG.md`. Never regenerate this file from the code — the direction of authority runs the other way, or the contract stops being a contract.

Additional gates that keep the promises in this document true rather than merely written down:

| Gate | What it proves |
|---|---|
| Error-string parity test | Every code in §4.2 exists in `resources/lang/{el,en}/api-errors.php` with both languages, and no orphan strings exist in either direction. |
| Key-leak grep | The built widget bundle and the packaged WordPress plugin zip contain no `sk_(live\|test)_`. |
| Publishable-write test | Every route in the §2.4 matrix rejects a `pk_` where the matrix says `—`, with the documented status and code. |
| Tenant-isolation test | Every endpoint returns `404`, never `403` and never data, for a UUID belonging to another tenant. |
| Availability p95 test | `GET /availability` stays under 150 ms p95 with a year of departures seeded (`BRIEF.md` §12). |
| Locale test | Every endpoint returns `Content-Language` matching the negotiated locale, and no response contains a raw `{"el":…,"en":…}` object outside `/sync/products`. |
| Money-shape test | No response contains a float in a `*_cents` field, and every object carrying a `*_cents` field also carries `currency`. |
| Oversell concurrency test | Two simultaneous `POST /bookings` for the last seat produce one `201` and one `409 insufficient_capacity` (MySQL-only; skips loudly on SQLite). |

