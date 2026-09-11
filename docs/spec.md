# Kaiki — Product specification

**Status:** Draft 1 — derived from `docs/BRIEF.md` (the source of truth).
**Date:** 2026-08-28
**Owner:** architect
**Companions:** `docs/data-model.md` (schema, indexes, state machines), `docs/api.md` (OpenAPI 3.1 for `/api/v1`), `docs/adr/` (open decisions), `docs/BRIEF.md` (origin document).

---

## 0. How to read this document

### 0.1 Purpose
This is the implementable contract for the whole build. `docs/BRIEF.md` is a pitch and a brief; this document restates it as numbered requirements that a developer can implement and a tester can verify. Where the brief left something ambiguous, this document resolves it. Where a decision was explicitly deferred, this document links an ADR and stops.

### 0.2 Precedence
1. Decisions marked **(FIXED)** in `docs/BRIEF.md` — never overridden by this document.
2. The **environment constraints** in §13 of this document, which explicitly override §14 and §17 of the brief (no Docker, no MySQL/Redis locally, no `make`, no `wp-env`, empty repository with no starter). Every such override is recorded in ADR-0014 and ADR-0015.
3. This specification.
4. `docs/data-model.md` and `docs/api.md` for schema-level and endpoint-level detail. Where this document and those disagree on a column name or an endpoint shape, those win; where they disagree on *behaviour*, this document wins.

### 0.3 Requirement IDs
Every requirement has a stable ID of the form `PREFIX-N`. IDs are permanent: they are referenced by GitHub issues, test names (`it('AVL-24: ...')`) and commit messages. Superseded requirements are struck through and kept, never renumbered.

| Prefix | Area |
|---|---|
| `VIS` | Product vision and positioning |
| `SCP` / `OOS` / `EXT` | In scope / out of scope / deferred but designed for |
| `ARC` | Architecture and stack |
| `CNV` | Cross-cutting code conventions |
| `TEN` | Tenancy, tenant resolution, isolation |
| `API` | Public API, keys, versioning |
| `CAT` | Catalogue (vessels, products, seasons, rate plans, extras, policies) |
| `AVL` | Availability engine |
| `PRC` | Pricing engine, vouchers, deposits |
| `CXL` | Cancellation, refunds, weather workflow |
| `BKG` | Booking lifecycle |
| `TOK` | Tokenised guest pages |
| `PAY` | Payments and gateways |
| `NTF` | Notifications (email, SMS, reminders) |
| `WGT` | Embeddable widget |
| `HOS` | Hosted pages and custom domains |
| `BRD` | Branding |
| `WPP` | WordPress plugin |
| `OPS` | Operator back-office operations |
| `CMP` | Compliance: manifest, ναυλοσύμφωνο |
| `MYD` | myDATA invoicing |
| `GDR` | GDPR, retention, data subject rights |
| `SAA` | SaaS layer: subscriptions, super-admin, onboarding, import |
| `I18N` | Internationalisation |
| `SEC` | Security |
| `NFR` | Performance, reliability, observability thresholds |
| `A11Y` | Accessibility |
| `TST` | Testing gates |
| `ENV` | Development, CI and production environments |
| `MIL` | Milestone definitions |

### 0.4 Markers
- **(FIXED)** — the brief fixes this. Not open to debate by any agent or by this document.
- **DECIDE — see ADR-000N** — **no longer used in this document.** It formerly marked a point deliberately left unresolved. All 23 ADRs in [`docs/adr/`](adr/README.md) were accepted by the product owner on **2026-08-28**, and every requirement that carried the marker now states the concrete requirement the accepted option produces, with a trailing `(per [ADR-000N](...), Option X)` citation so the decision trail survives. If a future fork appears, open a new ADR and cite it the same way; do not reintroduce the marker. The two items still open for a non-engineering reason are listed in §16.3.
- **(RESOLVED)** — the brief was ambiguous and this document has made a concrete choice that a human may veto. Every such choice is also listed in §5.11 (for the engine) or §16.2 (everywhere else) so they are easy to review in one place.

### 0.5 Conventions used in requirement text
- "MUST" / "MUST NOT" are mandatory. "SHOULD" indicates a strong default that may be overridden with a recorded reason. "MAY" is optional.
- Money is always written as integer cents (`_cents`).
- Times written as `HH:MM` are local to the tenant timezone unless suffixed `UTC`.
- Intervals are half-open: `[start, end)`.

---

## 1. Product vision

- **VIS-1 (FIXED)** Kaiki is a hosted booking engine plus operator back-office for boat operators running shared cruises, day trips and private charters, sold as a multi-tenant SaaS. Positioning: a booking engine for boats in the same shape as WebHotelier for hotels.
- **VIS-2 (FIXED)** Tenants are boat operators, Greece first, then the EU. Typical tenant: 1–10 vessels.
- **VIS-3 (FIXED)** End users are guests booking from the operator website, a hosted Kaiki page, or later from resellers and OTAs. Guests never create accounts.
- **VIS-4 (FIXED, provider withdrawn)** Business model: monthly subscription per operator, 14-day trial, plans differentiated by number of vessels and features. ~~through Stripe with Laravel Cashier~~ — **the provider was removed by ADR-0028; Viva Wallet was chosen in its place on 2026-09-08** — the same gateway an operator charges their guests through. The model is unchanged and M7 is no longer blocked on a decision, only on being built.
- **VIS-5 (FIXED)** **Kaiki never touches guest money.** Guests pay the operator directly through the operator own gateway credentials. This is an absolute constraint that shapes §PAY entirely.
- **VIS-6** The differentiators the product must actually deliver, in priority order: (a) per-seat and per-vessel booking sharing one vessel calendar; (b) post-booking passenger manifest feeding the Λιμεναρχείο export; (c) built-in Greek compliance (manifest, ναυλοσύμφωνο, myDATA); (d) one-click weather-cancellation workflow; (e) migration from WooCommerce + YITH Booking; (f) white-label widget that looks native on the operator site.
- **VIS-7** Every operator-facing and guest-facing surface is bilingual Greek and English from the first commit. Greek is the primary operator language; guest locale is per booking.

---

## 2. Scope

### 2.1 In scope for MVP

- **SCP-1 (FIXED)** Multi-tenant operator back-office as a Filament v3 panel at `/app`.
- **SCP-2 (FIXED)** Catalogue: vessels, products (shared / private / quote), seasons and pricing, extras, age bands, cancellation policies.
- **SCP-3 (FIXED)** Availability engine with vessel-level conflict resolution (§5).
- **SCP-4 (FIXED)** Guest booking flow through an embeddable JS widget and a hosted booking page: instant book, request-a-quote, ask-a-question.
- **SCP-5 (FIXED)** Payments through the operator own Viva Wallet (Smart Checkout). Full payment or deposit, with balance links. ~~or Stripe (Checkout)~~ — ADR-0028.
- **SCP-6 (FIXED)** Post-booking guest-details link, manifest export (CSV and PDF), QR e-tickets, check-in.
- **SCP-7 (FIXED)** Notifications: email in EL/EN, SMS through a pluggable provider (Apifon or Yuboto for Greece, Twilio fallback), reminders.
- **SCP-8 (FIXED)** Weather and operator cancellations with refund, voucher or rebook. Vouchers have an expiry.
- **SCP-9 (FIXED)** iCal export per vessel; iCal import for external blocks.
- **SCP-10 (FIXED)** Greek compliance: manifest, ναυλοσύμφωνο PDF, myDATA (ΑΛΠ / ΤΠΥ) issuance and retry.
- **SCP-11 (FIXED)** Per-operator branding: logo, primary/secondary/accent colours, font choice, button radius, widget mode (light/dark/auto).
- **SCP-12 (FIXED)** WordPress plugin: settings, shortcodes, Gutenberg block, Elementor widget, trip grid listing, optional SEO landing pages, WPML locale pass-through, coexisting with WooCommerce.
- **SCP-13 (FIXED)** Super-admin panel at `/admin`, subscriptions, onboarding wizard, WooCommerce/YITH importer.
- **SCP-14 (FIXED)** i18n: EL and EN everywhere, translation-ready for more locales. Currency EUR only. Timezone per operator, default Europe/Athens.

### 2.2 Out of scope for MVP
Design so they can be added later; do not build. Any pull request that implements one of these is rejected on scope grounds regardless of quality.

- **OOS-1 (FIXED)** Reseller/agent portal, net rates, commissions.
- **OOS-2 (FIXED)** OTA channel manager (Viator, GetYourGuide, Bókun).
- **OOS-3 (FIXED)** Bareboat rental, multi-day charters, damage deposits.
- **OOS-4 (FIXED)** Marketplace or aggregator site.
- **OOS-5 (FIXED)** Platform-collected payments of any kind. The platform never holds guest money.
- **OOS-6 (FIXED)** Mobile applications. The back-office MUST instead be fully usable on a phone browser.
- **OOS-7 (FIXED)** Reviews, waivers and e-signature, guest-purchased gift cards.
- **OOS-8 (FIXED)** Multi-currency. EUR only, everywhere, including the importer.
- **OOS-9** Operator-uploaded ναυλοσύμφωνο PDF templates with placeholders — designed as template v2, shipped behind a Pennant flag that is **off** (§10).
- **OOS-10** Automatic cancellation of bookings with an overdue balance — designed, flag off (ADR-0018).

### 2.3 Deferred but designed for
These are the named extension points. MVP code MUST leave them clean; MVP code MUST NOT implement them.

- **EXT-1 (FIXED)** `App\Contracts\Channel` — an abstract channel interface with exactly one implementation, `IcalChannel`. Adding Viator/GetYourGuide/Bókun later must not require changing the availability or booking domains. The interface covers: push availability, pull bookings, map external product ids, acknowledge cancellations.
- **EXT-2 (FIXED)** `laravel-pennant` feature flags for everything marked "later". MVP flags, all default off unless stated: `charter_agreement_custom_template`, `auto_cancel_overdue_balances`, `channel_manager`, `reseller_portal`, `reviews`, `hosted_page_custom_css` (on for Pro), `custom_domain` (on for Pro), `outbound_webhooks` (on for Pro).
- **EXT-3** `App\Contracts\SmsGateway` — MVP implementations `ApifonGateway`, `TwilioGateway`, `NullGateway`. Adding Yuboto later is a new class and a config entry only.
- **EXT-4** `App\Contracts\PaymentGateway` — implementation `VivaSmartCheckoutGateway`. The contract is deliberately narrow (ADR-0004) so a second gateway is additive; ADR-0028 removed Stripe and deliberately left every plural shape in place.
- **EXT-5** `App\Contracts\ImportSource` — MVP implementation `WooCommerceYithSource`. A future Bokun or CSV importer reuses the dry-run and mapping-review machinery.
- **EXT-6** Multi-currency: all money columns are integer cents and every monetary value is handled through `brick/money` with an explicit currency, so introducing a second currency is a data and UI change, never an arithmetic change. No code may assume EUR beyond formatting defaults.
- **EXT-7** Locales beyond EL and EN: no string may be hardcoded in any user-facing surface; adding `it` or `de` must be a lang-file plus widget-bundle addition only (§I18N).

---

## 3. Architecture, stack and conventions

### 3.1 Stack (FIXED unless noted)

| Layer | Choice | Requirement |
|---|---|---|
| Core | Laravel 12, MySQL 8, Redis, Horizon, Scheduler | **ARC-1 (FIXED)** |
| PHP version | **PHP 8.4 everywhere.** `composer.json` requires `"php": "^8.4"`; the CI matrix is `[8.4]` only; the production image is `php:8.4-fpm`; PHPStan `phpVersion` is `80400` and Pint targets the same. The WordPress plugin is unaffected and stays at PHP 8.1+ (ARC-9). | **ARC-2** — this **amends §3 and §18 of `docs/BRIEF.md`**, which said 8.3 (per [ADR-0014](adr/0014-php-version-target.md), Option B) |
| Tenancy | `stancl/tenancy` in **single-database mode**: one shared database, `tenant_id` on every tenant-owned table, no dynamic connection switching and no per-tenant migration run. `BelongsToTenant` is mandatory on every tenant-owned model (TEN-5) and per-model tenant-isolation tests are a required CI check (SEC-1, TST-6). | **ARC-3** (per [ADR-0001](adr/0001-tenancy-mode.md), Option A) |
| Back-office | Filament v3, operator panel `/app`, super-admin `/admin` | **ARC-4 (FIXED)** |
| Public API | REST, versioned `/api/v1`, OpenAPI 3.1 in `docs/api.md`, generated with `dedoc/scramble` | **ARC-5 (FIXED)** |
| API auth | Publishable key for public reads, secret key for writes, Sanctum sessions for the back-office | **ARC-6 (FIXED)**; scopes are an explicit array on the key in **dot form** (`products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive`) which can narrow a key but never widen it beyond its type — see SEC-5 (per [ADR-0013](adr/0013-api-key-model.md), Option A) |
| Widget | Preact + TypeScript, Vite, single IIFE `kaiki-widget.js`, Shadow DOM | **ARC-7 (FIXED)** |
| Hosted pages | Blade plus the same widget, at `book.{platform-domain}/{operator-slug}`, optional custom domain by CNAME | **ARC-8 (FIXED)** |
| WordPress plugin | PHP 8.1+, WP 6.4+, no framework, PSR-4 via Composer autoload, single `kaiki-booking/` folder in `packages/wordpress-plugin` | **ARC-9 (FIXED)** |
| PDFs | `spatie/browsershot` (Chromium) with Blade templates. dompdf is forbidden. | **ARC-10 (FIXED)** |
| Email | Postmark through Laravel Mail, per-operator from-name and reply-to, Blade plus MJML-compiled HTML, EL/EN | **ARC-11 (FIXED)** |
| SMS | `App\Contracts\SmsGateway` with `ApifonGateway`, `TwilioGateway`, `NullGateway` | **ARC-12 (FIXED)** |
| Payments | `App\Contracts\PaymentGateway` with `VivaSmartCheckoutGateway`; operator credentials encrypted at rest; webhooks verified and idempotent | **ARC-13 (FIXED)** |
| SaaS billing | **Viva Wallet** (ADR-0028 as amended) — Cashier and its columns are gone; the subscription machinery is M7's to write, since Viva supplies less of it than a subscription platform would. | **ARC-14 (superseded)** |
| Infra | Hetzner VPS, Docker Compose (app, horizon, scheduler, mysql, redis, chromium), Caddy for TLS including on-demand certificates, Cloudflare in front | **ARC-15 (FIXED for production only** — see §13 and [ADR-0015](adr/0015-local-development-stack.md); Compose is **not** the local development environment) |
| Testing | Pest, Playwright, PHPStan level 6 with Larastan, Pint | **ARC-16 (FIXED)** |
| Monitoring | Sentry, Horizon dashboard, Laravel Pulse | **ARC-17 (FIXED)** |

- **ARC-18** The repository is **empty**. There is no Laravel + `stancl/tenancy` starter, contrary to §3 and §17 of the brief. The first implementation issue MUST scaffold Laravel 12 from scratch with Pint, PHPStan level 6 (Larastan) and Pest configured, plus the CI workflow. See §13 and §14.
- **ARC-19** No package outside the table above and the approved list in §3.2 may be added without an accepted ADR. This applies to `composer require` and to runtime `npm` dependencies; build-time-only dev dependencies (Vite plugins, MJML, Playwright, ESLint) are exempt.

### 3.2 Approved package list
- **ARC-20** The packages implied by §3.1: `laravel/framework`, `stancl/tenancy`, `filament/filament`, `laravel/sanctum`, `laravel/horizon`, `laravel/pulse`, `laravel/pennant`, `dedoc/scramble`, `spatie/laravel-data`, `spatie/laravel-translatable`, `spatie/browsershot`, `brick/money`, `sentry/sentry-laravel`. (`laravel/cashier` and `stripe/stripe-php` removed by ADR-0028.)
- **ARC-21** The following packages are **approved as an amendment to the §3 stack table** and may be installed without a further ADR. Each is installed only at the moment it is first used, and the pull request that adds it MUST cite the requirement it serves. (per [ADR-0019](adr/0019-packages-beyond-section-3.md), Option A)

| Package | Capability | Justified by |
|---|---|---|
| `bacon/bacon-qr-code` | QR generation for e-tickets and the myDATA invoice QR | BKG-30, MYD-12 |
| `spatie/icalendar-generator` | iCal feed **export** | INT iCal export, HOS |
| `sabre/vobject` | iCal **parsing** of imported feeds | iCal import |
| `league/csv` | Streaming CSV export and import without memory blow-ups on large manifests | OPS exports, SAA import |
| `propaganistas/laravel-phone` | Phone-number normalisation for SMS | ARC-12, SMS delivery |
| `intervention/image` | Image resizing on logo, photo and gallery upload | BRD-4, BRD-7 |
| MJML (npm **dev** dependency) | Email HTML compilation. Build-time only; compiled HTML is committed so production never needs Node. | ARC-11 |

- **ARC-21a** `spatie/laravel-permission` is approved **conditionally**: it may be installed only if the three fixed roles in TEN-8 prove insufficient, and the pull request must state which capability the fixed roles could not express. It is not to be installed pre-emptively in M0.
- **ARC-21b** The **docs-site generator is still unapproved** and needs its own ADR before M7. Nothing in §3.2 authorises it.
- **ARC-21c** CI enforces this list: a test asserts `composer.json` requires nothing outside ARC-20 plus ARC-21. Anything not listed remains a hard stop requiring a new ADR (ARC-19).

### 3.3 Cross-cutting conventions

- **CNV-1 (FIXED)** Money is integer cents in columns named `*_cents`. Floats MUST NOT be used for money anywhere, including JSON snapshots, API payloads and CSV exports. Arithmetic goes through `brick/money`.
- **CNV-2 (FIXED)** All datetimes are stored in UTC and displayed in the tenant timezone.
- **CNV-3 (FIXED)** Departures store `local_date`, `local_time` and `starts_at_utc`. The three MUST always agree; a single resolver owns the conversion (AVL-16).
- **CNV-4** Rounding: monetary rounding is half-up to the cent, performed once at the point a derived amount is stored (deposit, refund, VAT split). Intermediate arithmetic MUST NOT round. Every rounded value is stored, never recomputed on read.
- **CNV-5 (FIXED)** Domain logic lives in `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` as invokable Action classes taking and returning `spatie/laravel-data` DTOs. Controllers, Filament resources, jobs and listeners are thin and only call Actions.
- **CNV-6 (FIXED)** Every state change emits an event (`BookingConfirmed`, `DepartureCancelled`, and so on). Listeners are queued.
- **CNV-7 (FIXED)** Feature flags via `laravel-pennant` for anything deferred (EXT-2).
- **CNV-8** Public identifiers are UUIDs (`uuid` column); integer primary keys never appear in an API response, an email, a URL or a widget payload. Exceptions: the human booking reference (BKG-3) and tokens (TOK-1).
- **CNV-9** Every tenant-owned table carries `tenant_id`, `uuid`, timestamps, and soft deletes where `docs/data-model.md` says so.
- **CNV-10** All external calls (payment gateways, myDATA, SMS, email, iCal fetch, outbound webhooks) happen inside queued jobs with an idempotency key, bounded retries with exponential backoff, and a structured log line. They MUST NOT happen inline in a web request.
- **CNV-11** Error messages that reach an operator exist in Greek and English and are drawn from lang files, never from an exception message.
- **CNV-12** Commit style `area(scope): summary`, areas `core, availability, payments, compliance, widget, wp, ops, saas, docs, infra`.
- **CNV-13** No secret (gateway credentials, AADE subscription key, SMS credentials, secret API key, webhook signing secret) may appear in a log, an exception payload sent to Sentry, an API response, the widget bundle, or a CSV export.
---

## 4. Domain model

Column types, indexes, foreign keys, translatable columns and the full state machines live in **`docs/data-model.md`**. This section fixes the entity names, their responsibilities and the invariants the schema must support. Entity names are taken verbatim from §4 of the brief and MUST NOT be renamed.

### 4.1 Tenancy and users
- **TEN-1 (FIXED, one column amended)** **Tenant (Operator)** — name, slug, legal name, ΑΦΜ, ΔΟΥ, address, timezone, default locale, currency (EUR), plan, `trial_ends_at`, `custom_domain`, ~~`hosted_page_enabled`~~ **`hosted_site_mode` (bookings_only | full)** — per **ADR-0029**, amended 2026-09-11: an operator with their own website wants the trip pages and the search without a second marketing home page under their name. ~~off~~ was retired on 2026-09-11; the booking pages exist for every operator. Set by the platform on `/admin`, not by the operator.
- **TEN-2 (FIXED)** **User** — belongs to a tenant; roles `owner`, `manager`, `crew`. A `crew` user sees today departures and check-in only. Super-admins are platform users with no `tenant_id`.
- **TEN-3 (FIXED)** **ApiKey** — publishable (`pk_`) and secret (`sk_`) per tenant, scoped, revocable, `last_used_at`. Columns: `tenant_id`, `type` (`publishable` | `secret`), `prefix` (first 8 characters, indexed, the only part ever shown again in the UI), `hash`, `scopes` JSON, `name`, `allowed_origins` JSON (nullable), `last_used_at`, `expires_at`, `revoked_at`. The plaintext key is shown exactly once at creation and is stored only as a hash. Creating a key never invalidates an existing one; rotation is create-then-revoke, and the panel warns when a key has been unused for 90 days. `last_used_at` is written at most once per minute per key so authentication does not cost a write per request. (per [ADR-0013](adr/0013-api-key-model.md), Option A)
- **TEN-4** Tenant resolution middleware resolves exactly one tenant per request, in this order, stopping at the first match: (1) API key in the `Authorization` header; (2) exact hostname match in `tenant_domains` (custom domain); (3) `book.{platform-domain}` plus the first path segment as the operator slug; (4) authenticated Filament panel session. If none matches, the request aborts with 404. There is no default tenant and no fallback. Step (2) is an exact match on `tenant_domains.hostname`, which is stored lowercased and punycode-normalised and is globally unique; only rows with `status = verified` and an active tenant subscription resolve (per [ADR-0010](adr/0010-custom-domain-resolution-and-tls.md), Option A). This ordering is a tenant-isolation control and is covered by SEC-4.
- **TEN-5** Every tenant-owned model uses `BelongsToTenant`, which adds a global scope filtering by the resolved tenant and fills `tenant_id` on create. A model that is deliberately platform-owned MUST be listed explicitly in a `config/tenancy.php` allow-list; there is no implicit exemption.
- **TEN-6** Uniqueness that is logically per-tenant (`bookings.reference`, `products.slug`, `vouchers.code`, `vessels.name`) is enforced by composite unique indexes including `tenant_id`.
- **TEN-7** Impersonation by a super-admin sets the tenant context, is time-limited, is written to an audit log with the acting super-admin, and displays a persistent banner in the panel.
- **TEN-8** Role capabilities: `owner` — everything including billing, API keys, gateway credentials, tenant deletion. `manager` — everything except billing, API keys and gateway credentials. `crew` — read-only access to departures within a configurable window (default today and tomorrow), the pax list, check-in actions and manifest view; no pricing, no financials, no guest documents beyond what the manifest shows.
- **TEN-8a** An owner invites a colleague by name, email address and one or more roles. The account is created with no usable password and the colleague sets their own through the panel's password-reset flow; **nobody ever chooses a password on somebody else's behalf.** The email address is the login and is not editable afterwards. Removing the last owner's role is refused. *(Added 2026-09-08: TEN-8 described the roles and never said how a person acquires one, so from M0 until then no operator could hand a colleague a login through the product at all.)*
- **TEN-9** A tenant in read-only mode (lapsed subscription, SAA-7) blocks all writes in `/app` and all write endpoints in the API, but MUST NOT break existing tokenised guest pages or the widget read endpoints.

### 4.2 Branding
- **BRD-1 (FIXED)** **BrandProfile**, exactly one per tenant — logo (light and dark), favicon, colours `primary`, `secondary`, `accent`, `background`, `text`, font family (curated list plus a Google Fonts name), button radius, widget theme (`light` | `dark` | `auto`), email header image, email footer text, social links, custom CSS.
- **BRD-2 (FIXED)** Custom CSS is sanitised and applies to the hosted page only; it is never injected into the widget.
- **BRD-3** A BrandProfile is created with platform defaults when a tenant is created, so no surface ever renders unbranded.

### 4.3 Catalogue
- **CAT-1 (FIXED)** **Vessel** — name, type (`catamaran` | `sailing_yacht` | `motor` | `rib` | `traditional_kaiki`), length, `capacity_max` (legal), crew, home port, images, specs, description (translatable), status.
- **CAT-2** Vessel also carries `turnaround_buffer_minutes`, default 60 (AVL-7).
- **CAT-3 (FIXED)** **Port / MeetingPoint** — name, address, lat/lng, instructions (translatable), photo.
- **CAT-4 (FIXED)** **Product** — `vessel_id`, category (`shared_full_day` | `shared_half_day` | `private_full_day` | `private_half_day` | `sunset` | `custom`), **mode** (`per_seat` | `per_vessel` | `quote`), title, summary, description, `duration_minutes`, `default_start_time`, `flexible_start` (per-vessel only), `check_in_offset_minutes`, `meeting_point_id`, `includes[]`, `excludes[]`, `what_to_bring[]`, itinerary stops, `route_map_image`, `min_pax`, `max_pax`, age bands, `cancellation_policy_id`, `guest_details_required` plus `guest_details_deadline_hours`, status, sort order, SEO fields. Title, summary, description, includes, excludes, what-to-bring and itinerary stop text are translatable.
- **CAT-5** Product validation: `max_pax` must be less than or equal to `vessel.capacity_max`; `flexible_start` is only settable when mode is `per_vessel`; `min_pax` only applies when mode is `per_seat`; a `quote` product must not have a RatePlan requirement but may have one for internal reference.
- **CAT-6** Translatable text is stored in **JSON columns managed by `spatie/laravel-translatable`**, keyed by locale. **No query may sort or filter on a JSON path.** Any translatable field that needs ordering or searching gets a plain, indexed companion column maintained by an observer on save — a per-locale sort key (`*_sort_{locale}`) where ordering is needed, and a single per-row `search_index` text column containing all locales concatenated and accent-folded where searching is needed. Accent folding (Greek tonos and final sigma) is one shared helper used by both the observer and the query builder so MySQL and SQLite behave identically (I18N-7). A PHPStan rule or architecture test forbids `json_extract` and `->>` inside `orderBy` and `where` on translatable columns. Locale fallback is requested locale, then tenant `default_locale`, then `en` (I18N-5); missing translations surface in a Filament "incomplete translations" widget. The exact column list is in `docs/data-model.md` §1.6 and §3.14. (per [ADR-0008](adr/0008-translatable-fields-storage.md), Option A)
- **CAT-7 (FIXED)** **AgeBand** (per product) — label, `min_age`, `max_age`, `counts_toward_capacity`, and either `price_multiplier` or a fixed price.
- **CAT-8** Age band validation: bands within one product MUST NOT overlap in age range; exactly one band MUST be marked as the adult/base band used for `price_multiplier = 1.0` reference; at least one band MUST have `counts_toward_capacity = true`.
- **CAT-9 (FIXED)** **Season** — name, multiple date ranges, priority.
- **CAT-10 (FIXED)** **RatePlan** — `product_id`, `season_id` (nullable meaning default); for `per_seat` a price per age band; for `per_vessel` a price per booking plus an optional per-extra-hour price; `deposit_percent` or `deposit_fixed`; `min_lead_time_hours`; `max_advance_days`.
- **CAT-11** VAT **mechanism** (settled): a platform-owned `vat_rates` reference table holds `code`, `percent`, the myDATA `vat_category` id, `valid_from`, `valid_to` and an EL/EN description. `products.vat_rate_id` and `extras.vat_rate_id` are foreign keys to it; an Extra's rate overrides the product's for that line. The resolved `vat_percent` and `vat_category` are copied into `price_snapshot` per line when the booking is priced (PRC-14) and are never recomputed afterwards, so a later statutory change cannot rewrite an issued invoice. Super-admin maintains the rate table; the operator picks a rate per product in onboarding. (per [ADR-0002](adr/0002-vat-rate-resolution.md), Option A)
- **CAT-11a** **No VAT percentage and no percent-to-`vat_category` mapping may be hardcoded anywhere in the codebase** — not in PHP, not in a config array, not in the myDATA client (FIXED by §10 and §18). The myDATA client reads `vat_category` from the snapshot and never contains a number.
- **CAT-11b** **Which rate applies to which product remains an accountant question and is deliberately not answered here.** ADR-0002 settles only where the rate lives and how it is resolved; it does not settle the rates themselves (§10 of the brief marks those "DECIDE with accountant"). The `vat_rates` rows and the per-product assignment are **data entry during onboarding**, not an engineering change. Ship the table in M1 with `products.vat_rate_id` nullable; myDATA activation (M6) is gated on every sellable product having a rate assigned (MYD-16). See §16.3.
- **CAT-12 (FIXED)** **Extra** — product-scoped or tenant-wide; `pricing_type` (`per_booking` | `per_person` | `on_request`); `max_qty`; description; image. An `on_request` extra has no price and is displayed as "κατόπιν αιτήματος / on request".
- **CAT-13 (FIXED)** **CancellationPolicy** — name, tiers `{days_before, refund_percent}`, `weather_refund_percent` (default 100), `force_majeure_voucher_months` (default 18), `free_cancellation_hours`.
- **CAT-14 (FIXED)** **ScheduleRule** (per-seat products) — weekday mask, `start_time`, `valid_from`/`valid_to`, capacity override; generates Departures ahead of time via the scheduler. Manual one-off departures are allowed.
- **CAT-15** A product may not be published (status `active`) unless it has: a vessel, a meeting point, at least one age band (per-seat), a resolvable RatePlan, a cancellation policy, and a title in both EL and EN. This is a validation gate, surfaced as a checklist in the panel.

### 4.4 Availability entities
- **AVL-A1 (FIXED)** **Departure** (per-seat) — `product_id`, `vessel_id`, `local_date`, `start_time`, `capacity`, `seats_sold` (denormalised), status (`scheduled` | `guaranteed` | `cancelled` | `completed`), `cancel_reason` (`weather` | `operator` | `min_pax` | `vessel_booked_privately`), notes.
- **AVL-A2** Departure additionally carries `starts_at_utc`, `ends_at_utc`, `seats_held` (denormalised count of active unexpired holds), `schedule_rule_id` (nullable), `is_manual`. `docs/data-model.md` MUST include `seats_held`; the engine cannot be correct without separating committed from held seats (AVL-24).
- **AVL-A3 (FIXED)** **VesselBlock** — `vessel_id`, date range with an optional time range, reason (`private_booking` | `maintenance` | `external_ical` | `manual`), source reference.
- **AVL-A4** VesselBlock additionally carries `starts_at_utc`, `ends_at_utc`, `booking_id` (nullable, set when reason is `private_booking`) and `is_all_day`.

### 4.5 Bookings and guests
- **BKG-A1 (FIXED)** **Booking** — reference, `product_id`, `vessel_id`, `departure_id` (per-seat) or date plus start/end time (per-vessel), mode, status, source (`widget` | `hosted` | `wordpress` | `manual` | `import`), locale, lead guest (name, email, phone, nationality), pax breakdown JSON by age band, extras JSON, `subtotal_cents` / `discount_cents` / `total_cents` / `deposit_cents` / `paid_cents` / `balance_cents`, `voucher_id`, policy snapshot JSON, price snapshot JSON, `guest_details_status` (`not_required` | `pending` | `complete`), `guest_details_token`, `manage_token`, special requests, internal notes, UTM fields, IP and user agent.
- **BKG-A2** Booking additionally carries `hold_expires_at`, `balance_due_at`, `confirmed_at`, `cancelled_at`, `is_test`, customer tax fields (ΑΦΜ, ΔΟΥ, legal name, country code), and `starts_at_utc` / `ends_at_utc` denormalised for both modes so that reminders, exports and the dashboard can query one column.
- **BKG-A3 (FIXED)** **BookingGuest** — `booking_id`, full name, date of birth, nationality, document type and number (encrypted), age band, seat/ticket QR code, `checked_in_at`.
- **BKG-A4 (FIXED)** **Quote** — `booking_id`, line items, `valid_until`, message, pay link; state `sent` | `accepted` | `declined` | `expired`.
- **BKG-A5 (FIXED)** **Enquiry** — `product_id` (nullable), name, email, phone, preferred date, pax, message, status.
- **BKG-A6 (FIXED)** **Voucher** — `tenant_id`, code, `amount_cents`, `remaining_cents`, `expires_at`, `issued_for_booking_id`, reason, redemptions.
- **BKG-A7 (FIXED)** **Payment** — `booking_id`, gateway, kind (`full` | `deposit` | `balance` | `refund`), `amount_cents`, `gateway_ref`, status, raw payload, idempotency key.

### 4.6 Compliance entities
- **CMP-A1 (FIXED)** **Invoice** — `booking_id`, type (`ΑΛΠ` | `ΤΠΥ`), series, number, `issued_at`, myDATA `mark`, `uid`, `qr_url`, status (`pending` | `sent` | `failed` | `cancelled`), `last_error`, retries.
- **CMP-A2 (FIXED)** **CharterAgreement** (ναυλοσύμφωνο) — `booking_id`, `template_version`, filled-fields snapshot, PDF path, `sent_at`, `guest_accepted_at` with IP and timestamp.
- **CMP-A3 (FIXED)** **ManifestExport** — `departure_id` or `booking_id`, format, `generated_at`, file path, `generated_by`.

### 4.7 Operations and integration entities
- **OPS-A1 (FIXED)** **Notification** log — `booking_id`, channel, template, locale, recipient, status, provider reference.
- **OPS-A2 (FIXED)** **IcalFeed** — `vessel_id`, token (export URL). **IcalSource** — `vessel_id`, url, `last_synced_at`.
- **OPS-A3 (FIXED)** **WebhookEndpoint** (outbound, tenant-configured) and **WebhookDelivery** — events `booking.confirmed`, `booking.cancelled`, `departure.cancelled`, `guest_details.completed`.
- **OPS-A4 (FIXED)** **ImportJob** — source `woocommerce_yith`, status, mapping JSON, log.

---

## 5. Availability and pricing engine

This section is the heart of the product. Every requirement below is written to be directly testable. §5 of the brief is law; where it was ambiguous the resolution is marked **(RESOLVED)** and repeated in §5.11 so a human can veto it in one place.

### 5.1 Core definitions

- **AVL-1 (FIXED)** The **vessel is the resource**. Nothing is bookable without a free vessel window.
- **AVL-2** An **occupation** is a half-open UTC interval `[starts_at_utc, ends_at_utc)` attached to exactly one vessel.
- **AVL-3** The sources of occupation for a vessel are, and are only:
  1. a `VesselBlock` of any reason;
  2. a `Departure` with `status ∈ {scheduled, guaranteed}` and `seats_sold + seats_held > 0`;
  3. a `Booking` in mode `per_vessel` with `status ∈ {pending_payment, confirmed, checked_in, completed}` (represented by its `VesselBlock` with reason `private_booking`, so it is counted once, not twice);
  4. a `Booking` in mode `per_vessel` in status `draft` with an unexpired `hold_expires_at`.
- **AVL-4** A `VesselBlock` with `is_all_day = true` occupies every local calendar day in its range in full, converted to UTC through the tenant timezone (so a 25-hour day is 25 hours).
- **AVL-5** A `Departure` occupies `[starts_at_utc, starts_at_utc + product.duration_minutes)`. `check_in_offset_minutes` affects the guest-facing check-in time only; it does **not** extend the occupation. **(RESOLVED)**
- **AVL-6** A `per_vessel` booking occupies the booked window: `default_start_time` plus `duration_minutes`, or the guest-proposed window when `flexible_start` is true, or the operator-entered window for a manual booking.
- **AVL-7 (FIXED default)** Each vessel has `turnaround_buffer_minutes`, default **60**. Two occupations A and B on the same vessel **conflict** if and only if:
  `A.start < B.end + buffer` **and** `B.start < A.end + buffer`.
  Equivalently: the gap between them must be greater than or equal to `buffer`. The predicate is symmetric and the buffer is counted once, not twice. **(RESOLVED — the brief did not state whether the buffer is one-sided or two-sided.)**
- **AVL-8** The buffer is applied **at query time** from the current vessel setting; it is never baked into stored `starts_at_utc` / `ends_at_utc`. Changing a vessel buffer therefore changes future availability immediately and never rewrites existing rows.
- **AVL-9** An occupation never conflicts with itself. The `VesselBlock` created by a `per_vessel` booking and that booking are the same occupation and MUST be counted once.
- **AVL-10** A `Departure` with `seats_sold = 0` and `seats_held = 0` is **not** an occupation. Such departures may overlap each other and may overlap a proposed `per_vessel` window.
- **AVL-11** Consequently, overlapping zero-sold departures on the same vessel may coexist. The moment the first seat is committed or held on one of them, all conflicting departures become unavailable for new sales (they are not cancelled automatically; they simply stop being sellable while the conflict exists). The panel MUST warn when generating or creating a departure that conflicts with an existing one on the same vessel.
- **AVL-12 (RESOLVED)** §5.1 of the brief says "no *other-product* Departure with `seats_sold > 0`". This is interpreted as **any Departure other than the one under evaluation**, regardless of product. Two departures of the *same* product on the same vessel at overlapping times would otherwise double-book the vessel. Same-product overlap is additionally blocked by validation at creation time.

- **AVL-12a** The three occupation shapes in AVL-3 (`departures`, `vessel_blocks`, and per-vessel booking windows) **stay as three separate tables**; they are not merged into a single `bookable_windows` table. In exchange, the union across them is hidden behind **one port: `App\Domain\Availability\VesselCalendar`**, which is the **only** class in the codebase permitted to query vessel occupancy. The three queries live inside it and nowhere else; nothing outside `app/Domain/Availability` may know there are three. This is enforced by an **architecture test**, not by convention. (per [ADR-0023](adr/0023-unified-bookable-windows.md), Option C)
- **AVL-12b** Because the containment in AVL-12a is what makes the shape reversible, the NFR-1 availability benchmark is **pulled forward from M8 to the close of M2** (NFR-1). If the p95 threshold is missed there, ADR-0023 is reopened and the single-table Option B is reconsidered while the change is still cheap.

### 5.2 Time, timezone and DST

- **AVL-13** All interval comparisons are performed in UTC on `datetime` columns. Availability logic MUST NOT compare local dates or local times directly.
- **AVL-14** A "local day D" for a tenant is the UTC interval `[start_of_day(D, tz), start_of_day(D + 1 day, tz))`. This interval is 23, 24 or 25 hours long depending on DST.
- **AVL-15** An availability query for local day D returns every relevant occupation that **overlaps** that interval, not only those that start within it. A charter from 21:00 on D to 02:00 on D+1 appears on both days.
- **AVL-16** Local-to-UTC conversion is performed by exactly one class (`LocalDateTimeResolver` in `app/Domain/Availability`). No other code may call timezone conversion for departures, blocks or bookings.
- **AVL-17** Durations are **absolute elapsed minutes**: `ends_at_utc = starts_at_utc + duration_minutes`. A trip crossing a DST transition is therefore correct in elapsed time and shifts by one hour in wall-clock terms. **(RESOLVED)**
- **AVL-18** Non-existent and ambiguous local times are handled as follows (per [ADR-0016](adr/0016-dst-invalid-and-ambiguous-local-times.md), Option A):
  1. **Non-existent** (Europe/Athens spring forward, last Sunday of March, 03:00–03:59): generation MUST NOT create the departure and MUST NOT shift it to another time. It records a `schedule_rule_issues` row and surfaces it in the operator panel in Greek and English (for example "Δεν υπάρχει ώρα 03:30 στις 29/03 λόγω αλλαγής ώρας"). The operator resolves it by editing the rule or adding a manual departure.
  2. **Ambiguous** (autumn fall back, last Sunday of October, 03:00–03:59): the **first (earlier) occurrence** is taken — the one still on summer time, UTC+3.
  3. `LocalDateTimeResolver` (AVL-16) is the only class that performs the conversion and returns a result object carrying `existent`, `ambiguous` and the chosen instant. Availability queries resolve the local-day boundary (AVL-14) through the same resolver, so a 23-hour or 25-hour day is handled in exactly one place.
  4. Both branches are covered by table-driven tests (TST-5): spring-forward skip, autumn ambiguity, a trip crossing each transition, and a midnight-crossing charter on a transition night.
- **AVL-19** `min_lead_time_hours` is evaluated in absolute hours: a departure is bookable only if `starts_at_utc - now_utc >= min_lead_time_hours` hours.
- **AVL-20** `max_advance_days` is evaluated in **tenant-local calendar days**: a departure is bookable only if `local_date <= today_in_tenant_tz + max_advance_days` days. **(RESOLVED — the brief did not state whether this was absolute or calendar.)**
- **AVL-21** Tenant timezone defaults to `Europe/Athens` and is per tenant (FIXED). All operator-facing displays, all reminder scheduling and all "today/tomorrow" logic use it.

### 5.3 per-seat availability

- **AVL-22 (FIXED)** A Departure is available for a requested party if all of the following hold:
  1. its vessel window is free (no conflicting occupation per AVL-3 and AVL-7, excluding itself);
  2. `status ∈ {scheduled, guaranteed}`;
  3. `capacity - seats_sold - seats_held >= requested_counted_seats`;
  4. `min_lead_time_hours` passes (AVL-19);
  5. `max_advance_days` passes (AVL-20);
  6. the product status is `active` and the vessel status is `active`;
  7. the tenant is not in read-only mode for *new* bookings.
- **AVL-23 (FIXED)** `requested_counted_seats` counts only pax in age bands where `counts_toward_capacity = true`.
- **AVL-24** `seats_sold` counts committed pax: bookings in status `pending_payment`, `confirmed`, `checked_in` or `completed`. `seats_held` counts pax in `draft` bookings with `hold_expires_at > now()`. Both are denormalised counters maintained inside the same transaction as the state change and MUST be reconcilable from bookings by a nightly integrity check.
- **AVL-25** In addition to product capacity, the **legal capacity check** applies: `total_persons_on_board <= vessel.capacity_max`, where `total_persons_on_board` counts **every** person including age bands with `counts_toward_capacity = false` (infants), across all bookings on that departure. This is a separate, always-enforced check. **(RESOLVED — the brief distinguishes legal `capacity_max` from booking capacity but does not connect them; conflating them would let a boat sail illegally full of infants.)**
- **AVL-26** A booking MUST contain at least one pax in a counted age band. A booking of only non-counting pax is rejected with `NO_COUNTED_PAX`.
- **AVL-27** `product.max_pax` caps the party size of a single booking; `departure.capacity` caps the whole departure. Both are enforced.
- **AVL-28** A departure with `status = cancelled` is never available and never appears in availability responses, but remains visible in the operator panel and in the booking history of affected guests.
- **AVL-29** Availability responses are computed per requested date range, capped at 62 days per request, and MUST return, for each date, the departure UUID, local time, remaining counted seats, the price-from figure and the guaranteed flag.

### 5.4 per-vessel availability

- **AVL-30 (FIXED)** A `per_vessel` product is available on a date if the proposed window fits inside a free vessel window. The proposed window is the product default window, or the guest-proposed window when `flexible_start` is true.
- **AVL-31** When `flexible_start` is true the guest may propose any start time on a 15-minute grid within an operator-configured daily operating window (default 06:00–23:00 local); the duration remains `duration_minutes` unless per-extra-hour pricing is configured, in which case the guest may extend in whole hours up to a configured maximum. **(RESOLVED — the brief implies but does not define a granularity or bound.)**
- **AVL-32 (FIXED)** If a shared Departure exists on that vessel with `seats_sold = 0`, a private booking may take the window. On **confirmation** of the private booking (not on hold), that Departure is auto-cancelled with `cancel_reason = vessel_booked_privately` and the operator is notified. **(RESOLVED — the brief does not say at which point the auto-cancel happens; doing it at hold time would destroy departures for abandoned carts.)**
- **AVL-33** While a private hold is active, conflicting zero-sold departures are hidden from availability but not cancelled. If the hold expires they become available again.
- **AVL-34 (FIXED)** If a conflicting Departure has `seats_sold > 0`, the vessel window is unavailable for a private booking. There is no override in the guest flow; the operator may resolve it manually in the panel.
- **AVL-35** A `per_vessel` booking creates a `VesselBlock` with reason `private_booking` linked to the booking. Cancelling the booking removes or voids the block in the same transaction.

### 5.5 Seat holds

- **AVL-36 (FIXED)** When a guest reaches payment, seats (per-seat) or the vessel window (per-vessel) are held for **15 minutes**. Expired drafts release automatically.
- **AVL-37** Mechanism (per [ADR-0005](adr/0005-seat-hold-mechanism.md), Option A). The lock and the hold are two different things and MUST NOT be conflated:
  1. **The hold is data, in the database.** `bookings.hold_expires_at` (nullable, indexed) plus the `departures.seats_held` counter for `per_seat`, or a provisional `vessel_blocks` row for `per_vessel`, are the single source of truth. A cache flush or a Redis restart never releases a hold.
  2. **The lock is a short mutex around the write only.** `Cache::lock("kaiki:hold:departure:{id}")` or `kaiki:hold:vessel:{id}:{date}`, TTL 5 seconds, `block()` wait 3 seconds, failing with an explicit timeout error. It is held only while the critical section runs, never for the 15-minute hold.
  3. **The driver is configuration, not code.** Locally `Cache::lock` resolves to the `database` store; in CI and production it resolves to Redis. **No domain code may reference `Redis::` or a Redis store directly** — only `Cache` and `Cache::lock`. This satisfies §5.4 of the brief literally in production while remaining runnable on SQLite.
  4. Counter mutation inside that critical section also takes the row lock described in AVL-43 (`docs/data-model.md` marks these paths **[LOCK]**), so the hold write is protected by both the mutex and the transaction.
  5. `HoldSeats`, `ExtendHold` and `ReleaseHold` Actions in `app/Domain/Availability` are the only writers of `hold_expires_at` and `seats_held`.
- **AVL-38** Whatever the mechanism, these properties are mandatory: the hold is durable across an application or cache restart; expiry is enforced both by a scheduled sweeper and lazily at read time, so correctness never depends on the scheduler having run; the hold TTL is a config value; and a hold is released immediately on payment failure, on explicit abandonment, and on successful confirmation (converted to committed). The TTL is global config (`settings.booking.hold_minutes`, default 15) and is **not** per tenant in MVP. Expiry is enforced twice, on purpose: a queued `ExpireStaleHolds` sweeper runs every minute, and every availability read independently treats `hold_expires_at < now()` as released, so a backlogged queue can never cause an oversell and a fast sweeper can never release a hold a read still counts.
- **AVL-39** Re-entering checkout with an expired hold re-acquires it if capacity still allows, otherwise returns `HOLD_EXPIRED` with a fresh availability payload so the widget can re-render without losing the guest.
- **AVL-40** A hold does not reserve a price. Price is re-verified at confirmation against the stored `price_snapshot`; if the snapshot is older than the hold TTL plus a grace period, it is recomputed and the guest is shown the difference before payment. **(RESOLVED)**
- **AVL-41** Holds are never created for `quote` mode products.

### 5.6 Concurrency

- **AVL-42 (FIXED)** Confirmation happens inside a database transaction with `SELECT ... FOR UPDATE` on the departure or vessel row.
- **AVL-43** Strategy across SQLite (local) and MySQL (CI and production) (per [ADR-0006](adr/0006-overselling-concurrency-strategy.md), Option A). Shipped code is identical in all three environments; only the test coverage differs:
  1. Domain code **always** calls `->lockForUpdate()` on the vessel row and/or the departure row inside `DB::transaction()`. It is never dropped or conditionally skipped "for SQLite compatibility" — that is a review blocker. On SQLite the driver emits no lock clause and the engine's single-writer serialisation is what holds; `docs/data-model.md` flags every such path **[LOCK]**.
  2. In addition, and as the portable guard, the seat counter is written as a **conditional update** — `UPDATE departures SET seats_sold = seats_sold + :n WHERE id = :id AND capacity - seats_sold - seats_held >= :n` — and a zero-affected-rows result aborts the transaction with `CAPACITY_EXCEEDED`. This guard runs on SQLite too, so the capacity invariant is exercised locally even though true parallelism is not.
  3. The two-parallel-confirmations test (AVL-44) is tagged `@group mysql`, runs in CI against MySQL 8, and is a **required status check on every pull request**. Locally it must skip with an explicit "requires MySQL" message and never silently pass. A `composer test:mysql` script exists for anyone with a MySQL instance.
  4. CI fails the build if the `mysql` group reports zero executed tests (TST-8).
  5. Lock ordering is fixed and documented in AVL-45.
- **AVL-44 (FIXED)** A test MUST prove that two simultaneous confirmations cannot oversell the last seat. It runs in CI against MySQL 8 and is a required status check.
- **AVL-45** Lock ordering is fixed and documented: vessel row, then departure row, then booking row. Any code taking these locks in a different order is a review blocker.
- **AVL-46** Transactions that hold a row lock MUST NOT perform external calls (gateway, myDATA, email). Side effects are dispatched as queued jobs after commit.
- **AVL-47** Payment webhooks are idempotent: replaying a webhook for an already-confirmed booking is a no-op that returns 2xx.

### 5.7 Guaranteed departures and min_pax

- **AVL-48 (FIXED)** A Departure becomes `guaranteed` once `seats_sold >= product.min_pax`. The transition emits `DepartureGuaranteed`.
- **AVL-49** The transition is evaluated inside the confirmation transaction and is not reversed automatically by a cancellation dropping the count below `min_pax`; once guaranteed, a departure stays guaranteed unless the operator cancels it. **(RESOLVED — reversing it would retract a promise already made to guests by email.)**
- **AVL-50 (FIXED)** The operator dashboard lists "at risk" departures: within 48 hours of departure, `status = scheduled`, `seats_sold < min_pax`. Each row has a one-click cancel that enters the cancellation workflow (§5.10) with reason `min_pax`.
- **AVL-51** `min_pax` never blocks a booking. A guest can always book a below-threshold departure; the guest-facing copy explains the minimum in both locales.

### 5.8 Departure generation

- **AVL-52 (FIXED)** `ScheduleRule` auto-generates Departures ahead of time via the scheduler. Manual one-off departures are allowed and are marked `is_manual`.
- **AVL-53** Horizon, cadence and rule-change semantics (per [ADR-0009](adr/0009-departure-generation-horizon.md), Option A):
  1. **Horizon** — a rolling window so departures always exist up to `min(kaiki.departures.horizon_days, rule.valid_to)`. `horizon_days` is config, default **400**. A per-tenant override is reserved for later and is not built in MVP.
  2. **Cadence** — a nightly `GenerateDeparturesJob` at **03:15 in the tenant timezone** extends every active rule, plus an immediate queued run for a single rule whenever that rule is created or updated. Generation never runs inside a public read request (AVL-54).
  3. **Idempotency** — keyed on (`schedule_rule_id`, `local_date`, `start_time`). Departures carry `schedule_rule_id` (nullable) and `is_manual`.
  4. **Rule changes are additive only.** The job creates newly-matching future departures. Future departures that no longer match are **flagged in an operator reconciliation screen** where the operator chooses "cancel" or "keep as one-off"; they are never silently mutated or deleted. Deleting a rule soft-deletes it and flags its future zero-sold departures for review.
  5. **Departures with `seats_sold > 0` are never auto-deleted or auto-modified**, including by a capacity-override change; those are listed for manual review and keep their captured capacity.
  6. DST-invalid local times are skipped and flagged per AVL-18, never shifted.
- **AVL-54** Whatever is decided, these properties are mandatory: generation is idempotent (re-running creates nothing new); generation never modifies or deletes a departure with `seats_sold > 0`; generation never runs inside a public read request; and DST-invalid times are handled per AVL-18.
- **AVL-55** A generated departure takes its capacity from `schedule_rule.capacity_override` if set, otherwise from `product.max_pax`, capped at `vessel.capacity_max`.

### 5.9 Pricing

- **PRC-1 (FIXED)** Price is computed **server-side only** and stored in `price_snapshot` on the booking. The widget, the hosted page and the WordPress plugin never compute or send prices. Any price received from a client is ignored.
- **PRC-2 (FIXED)** Resolution order: RatePlan matching the highest-priority Season containing the date, else the default RatePlan; then age band multiplier or fixed price; then extras; then voucher; then total. Deposit is computed from the RatePlan.
- **PRC-3** Season matching: candidate seasons are the tenant seasons having at least one date range containing the departure `local_date`. Candidates are ordered by `priority` descending.
- **PRC-4 (RESOLVED)** Season priority ties: two seasons with the same priority whose ranges overlap are **prevented by validation** — saving such a season is rejected. As defence in depth the engine still orders deterministically: `priority DESC`, then narrowest matching range in days ascending, then `season.id` ascending. The engine must never depend on database row order.
- **PRC-5 (RESOLVED)** If the winning season has no RatePlan for the product, the engine continues down the ordered candidate list; if no seasonal RatePlan exists, it uses the product default RatePlan (`season_id IS NULL`). If neither exists, the product is not sellable for that date and availability MUST exclude it, with a panel warning rather than a guest-facing error.
- **PRC-6** Per-seat line pricing: for each age band in the party, `line_cents = band.fixed_price_cents ?? round(base_price_cents * band.price_multiplier)` multiplied by the pax count in that band. Rounding is half-up, applied per unit price, before multiplication by quantity, so that ten guests are exactly ten times the displayed unit price. **(RESOLVED)**
- **PRC-7** Age bands with `counts_toward_capacity = false` are still priced by their multiplier or fixed price, which may be zero. Not counting toward capacity does not imply free.
- **PRC-8** Per-vessel pricing: `base_cents` from the RatePlan per booking, plus `extra_hours * per_extra_hour_cents` when the guest extended a flexible window.
- **PRC-9** Extras: `per_booking` adds its price once; `per_person` multiplies by **counted** pax by default, with a per-extra flag to multiply by total pax where an infant also consumes the item. `on_request` extras add zero and are recorded in the booking as requested items requiring operator follow-up; a booking containing an `on_request` extra still confirms and pays the computed total. **(RESOLVED)**
- **PRC-10** `max_qty` on an extra is enforced server-side.
- **PRC-11** Discounts: MVP has no promo-code engine. `discount_cents` exists for operator-applied manual discounts on manual bookings and quotes only.
- **PRC-12** `subtotal_cents` = sum of pax lines plus extras, before voucher. `total_cents` = `subtotal_cents - discount_cents - voucher_applied_cents`, floored at zero.
- **PRC-13** Prices are stored and displayed VAT-inclusive. The net/VAT split is derived at invoicing time only (§10).
- **PRC-14** `price_snapshot` JSON records, per line: type, reference id and name at the time, unit price cents, quantity, line total cents, and (pending ADR-0002) `vat_percent` and `vat_category`. It also records the resolved season, rate plan, policy id, currency, and the computation timestamp. It is written once and never mutated; a repriced booking gets a new snapshot version appended, with the active version flagged.
- **PRC-15** A price quote (`POST /price-quote`) returns the same structure as a snapshot but is not persisted and carries an explicit `expires_at` equal to now plus the hold TTL.
- **PRC-16** Currency is EUR everywhere (FIXED). `brick/money` is constructed with an explicit currency in every code path.

### 5.10 Vouchers, deposits, cancellation and refunds

- **PRC-17 (FIXED)** Vouchers have `amount_cents`, `remaining_cents`, `expires_at`, and are tenant-scoped.
- **PRC-18** Voucher application happens after extras and before deposit calculation. `applied_cents = min(voucher.remaining_cents, subtotal_cents - discount_cents)`. The total can never be negative.
- **PRC-19** Voucher surplus and restoration (per [ADR-0017](adr/0017-voucher-remainder-and-refund-restoration.md), Options A and D). **Voucher value stays voucher value and cash stays cash; neither is ever converted into the other.**
  1. **Surplus** — `applied_cents = min(voucher.remaining_cents, total_after_extras_cents)` (PRC-18). Any unused remainder **stays on the voucher** for a later booking. It is never forfeited and never paid out in cash, and the booking total can never go below zero.
  2. **Restoration on cancellation** — the refund entitlement from the policy snapshot is split **pro-rata across the voucher and cash portions actually used**. Example: a €200 booking paid with a €120 voucher and €80 cash, cancelled under a 50% policy, gives a €100 entitlement — €60 restored to the voucher and €40 refunded in cash. Cash refunded never exceeds the cash actually paid.
  3. **Expired voucher** — if the original voucher has already expired at restoration time, the restored amount is issued as a **new voucher** with `force_majeure_voucher_months` validity, linked back to the original. Restoring to a live voucher does not change its expiry.
  4. **Ledger** — every movement is recorded in `voucher_redemptions` (voucher, booking, amount, reason, timestamp, and the reversal that undoes it). `vouchers.remaining_cents` is a denormalised value that MUST always be reconstructible from the ledger; a reconciliation test asserts it. Cancellation records a reversal and **never deletes a redemption row**. *(Reconciled by #81: this said "a reversal **row**", which `docs/data-model.md` §2.5 makes impossible — `voucher_redemptions_v_b_uq` is unique on (`tenant_id`, `voucher_id`, `booking_id`) and therefore permits exactly one row per voucher per booking. The index is the more valuable of the two, because it is what stops one voucher being applied twice to one booking, which is a real double-spend; so a reversal is `reversed_at` and `reversed_amount_cents` **on the row being reversed**, and the ledger's arithmetic is `Σ(amount_cents − reversed_amount_cents)`. Nothing is lost — the movement, its amount, its direction and its time are all still recorded per booking and still never deleted. `reason` was added to §2.7 in the same commit, because PRC-19.4 lists it and §2.5 omitted it.)*
  5. The operator may override any of this per booking under §5.9 with a mandatory recorded reason.
- **PRC-20** A voucher is validated at application time and **re-validated inside the confirmation transaction** (existence, tenant match, not expired, sufficient remaining amount) with a row lock, so two concurrent bookings cannot spend the same voucher twice.
- **PRC-21** Voucher expiry is evaluated at end of day in the tenant timezone.
- **PRC-22** If the voucher covers the entire total, no gateway session is created and the booking transitions directly to `confirmed` (BKG-19).
- **PRC-23 (FIXED)** Deposit is computed from the RatePlan: `deposit_fixed_cents` when set, otherwise `deposit_percent`.
- **PRC-24** Deposit rounding and clamping: `deposit_cents = round_half_up(total_cents * deposit_percent / 100)`, then clamped to `[1, total_cents]`. If `deposit_cents >= total_cents` the booking is treated as full payment and `balance_cents = 0`. If `total_cents = 0` (fully covered by voucher) no deposit applies.
- **PRC-25** Deposit is calculated **after** voucher application, so a voucher reduces both the total and the deposit. **(RESOLVED — the alternative, applying the voucher to the balance only, is defensible but leaves the guest paying a deposit on money they already hold.)**
- **PRC-26** Invariant, asserted by tests and by a nightly integrity check: `paid_cents + balance_cents = total_cents` for every booking in `confirmed`, `checked_in` or `completed`.
- **PRC-27** Balance due date and unpaid-balance handling (per [ADR-0018](adr/0018-balance-due-policy.md), Option A):
  1. **Due date** — `tenants.balance_due_days_before_departure`, default **14**, with an optional per-RatePlan override (`rate_plans.balance_due_days_before_departure`) taking precedence. The due instant is `starts_at_utc` minus N **local calendar days**, floored to **09:00 in the tenant timezone**. *(All three columns — these two and `bookings.balance_due_at` — were added by #83; `docs/data-model.md` §2 predates ADR-0018 and never defined them. The "local calendar days" wording is also #83's: `subDays()` on a UTC instant gives nine o'clock **UTC**, which is midday in Athens in summer and shifts by an hour across each DST boundary — the same distinction AVL-19 and AVL-20 already draw, and what `LocalDateTimeResolver` exists for.)*
  2. **Stored, not computed on read** — `bookings.balance_due_at` is computed and written at confirmation so reminders and dashboard sorting are index-friendly.
  3. **Late confirmations** — if `starts_at_utc` minus N days is already in the past at confirmation, `balance_due_at` is set to confirmation time plus 24 hours, capped at 2 hours before departure.
  4. **Reminders** — at due date minus 7 days and minus 1 day, plus an overdue notice. Email always, SMS optionally. Each is logged in the notification log and is idempotent per booking per reminder type, and each is subject to the night-time deferral in BKG-18.
  5. **No automatic cancellation in MVP.** Overdue bookings appear in an "Υπόλοιπα / Balances due" dashboard bucket with counts for due-soon and overdue, and one-click operator actions: send reminder, cancel per policy, convert to full refund, mark as paid in cash. Automatic cancellation is designed but **not built**, sitting behind the Pennant flag `auto_cancel_overdue_balances`, default off.
  6. The confirmation email and `/b/{manage_token}` both state the due date in the guest locale.
- **CXL-1 (FIXED)** Refunds are computed from the **policy snapshot taken at booking time**, never from the current policy. Editing a `CancellationPolicy` MUST NOT affect any existing booking.
- **CXL-2 (RESOLVED)** The policy snapshot is written when the Booking row is first persisted with a resolved price — that is, at draft creation for guest bookings, at creation for manual bookings, and at quote acceptance for quote bookings — and is immutable thereafter.
- **CXL-3** Refund computation for a guest-initiated cancellation at instant T:
  1. If `free_cancellation_hours` is set and `(starts_at_utc - T)` in hours is greater than or equal to it, refund is 100%.
  2. Otherwise let `days_before = (starts_at_utc - T)` expressed in whole days, rounded **down**. The applicable tier is the tier with the largest `days_before` that is less than or equal to the actual `days_before`. If no tier qualifies, the refund is 0%.
  3. `refund_cents = round_half_up(base * refund_percent / 100)`, where the base is **the value the guest actually surrendered**: cash paid plus voucher value redeemed against the booking, from the `voucher_redemptions` ledger. Never the booking total. **(RECONCILED by #84.)** This clause originally read *"the amount actually paid in cash"*, which for a voucher-paid booking contradicts [ADR-0017](adr/0017-voucher-restoration-on-cancellation.md)'s worked example — €200 paid with a €120 voucher and €80 cash under a 50% policy gives **€60 to the voucher and €40 in cash**, a €100 entitlement rather than €40. What the clause is contrasting `paid_cents` *with* is the **price**: it exists to stop a guest who paid a 30% deposit being refunded half of a trip they have not paid for. A voucher is not an unpaid balance; it is consideration the guest handed over. For a booking with no voucher the two readings are identical, which is every booking this clause was written about. The split is then PRC-19.2's, and cash refunded is clamped at cash received. `bookings.discount_cents` is deliberately **not** the source, because §2.5 defines it as "voucher + manual discount" and a manual discount is a price reduction rather than money anybody paid.
- **CXL-4** Cancelling after `starts_at_utc` is not possible from the guest page; the operator may still record a refund manually.
- **CXL-5 (FIXED)** The operator may override any refund: change the percentage, issue a voucher instead of cash, or waive. An override requires a reason, which is stored and shown in the booking timeline.
- **CXL-6 (FIXED)** Weather cancellation applies `weather_refund_percent` from the policy snapshot to all bookings on the departure, and sends each guest a per-booking choice email offering refund, voucher, or a rebook link.
- **CXL-7** The guest choice email links to `/b/{manage_token}`, where the three options are presented; the choice is recorded with timestamp and IP, and a reminder is sent after 72 hours if no choice is made. If no choice is made within 14 days, the operator default (a per-tenant setting, default `refund`) is applied automatically and the guest is notified. **(RESOLVED — the brief leaves the no-response case undefined and it must not strand money indefinitely.)**
- **CXL-8** A voucher issued for a force-majeure cancellation expires `force_majeure_voucher_months` (default **18**) after issue, from the policy snapshot. The expiry is set at issue and **never extended**: restoring value to a live voucher leaves its expiry alone, and an expired one is replaced rather than revived (PRC-19.3). `App\Domain\Pricing\Actions\IssueVoucher` is the single writer of that date — the 18 was repeated in four places until #84, and the fourth said 12.
- **CXL-9** Cancelling a booking releases capacity in the same transaction: `seats_sold` decremented, or the `VesselBlock` voided, and `DepartureCancelled` / `BookingCancelled` emitted.
- **CXL-10** Refunds are executed through the gateway asynchronously, are idempotent per `Payment` row, and a failed refund surfaces in the operator error feed in Greek and English without silently marking the booking refunded.
- **CXL-11** Refunding a booking with an issued invoice triggers a myDATA cancellation invoice (MYD-13).

### 5.11 Interpretation register for §5

These are the points where §5 of the brief was ambiguous or silent and this specification chose an answer. A human may veto any of them; each becomes an ADR if vetoed.

| ID | Ambiguity | Resolution chosen |
|---|---|---|
| AVL-5 | Does check-in offset extend the vessel occupation? | No. Guest-facing only. |
| AVL-7 | One-sided or two-sided turnaround buffer? | Symmetric predicate, buffer counted once; required gap equals the buffer. |
| AVL-12 | "other-product Departure" in §5.1 | Read as "any departure other than the one under evaluation". |
| AVL-17 | Duration across a DST transition | Absolute elapsed minutes. |
| AVL-20 | `max_advance_days` absolute or calendar | Tenant-local calendar days. |
| AVL-25 | Legal `capacity_max` vs booking capacity | Separate always-on check counting every person including non-counting bands. |
| AVL-31 | Flexible start granularity and bounds | 15-minute grid inside a configurable daily operating window. |
| AVL-32 | When a zero-sold departure is auto-cancelled by a private booking | At confirmation, not at hold. |
| AVL-40 | Does a hold freeze the price? | No; price re-verified at confirmation, difference shown before payment. |
| AVL-49 | Does `guaranteed` revert below `min_pax`? | No. |
| PRC-4 | Season priority ties | Prevented by validation; deterministic ordering as defence in depth. |
| PRC-5 | Winning season has no RatePlan | Walk down candidates, then default RatePlan, then not sellable. |
| PRC-6 | Rounding order for age-band multipliers | Round the unit price, then multiply by quantity. |
| PRC-9 | `per_person` extras and non-counting pax | Counted pax by default, per-extra flag for total pax. |
| PRC-25 | Deposit before or after voucher | After. |
| CXL-2 | When the policy snapshot is taken | At first persistence of a priced booking; immutable. |
| CXL-3 | Tier selection and refund base | Largest qualifying tier; base is the value the guest surrendered — cash paid **plus** voucher redeemed (#84, reconciling CXL-3.3 with ADR-0017). Identical to "cash paid" on any booking without a voucher. |
| CXL-6 | Which policy a weather cancellation applies | **Each booking's own snapshot**, never the departure's product's current policy — two guests on one sailing can be owed different proportions. |
| CXL-7 | Guest never answers the weather-choice email | Reminder at 72 hours, operator default applied at 14 days. |
---

## 6. Booking lifecycle

The canonical state machine (allowed transitions, guards, columns) lives in `docs/data-model.md`. This section specifies the behaviour: what triggers each transition, what side effects fire, and with what timing.

### 6.1 Statuses

- **BKG-1 (FIXED)** Booking statuses: `draft`, `pending_payment`, `quote_requested`, `quote_sent`, `confirmed`, `checked_in`, `completed`, `cancelled`, `refunded`, `expired`.
- **BKG-2 (FIXED)** Booking sources: `widget`, `hosted`, `wordpress`, `manual`, `import`.
- **BKG-3 (FIXED)** Every booking carries a human reference (format `KAI-7F3K2`). Format and collision strategy (per [ADR-0007](adr/0007-booking-reference-format.md), Option A):
  1. **Shape** — `KAI-XXXXX`: a configurable brand prefix (config value, default `KAI`) plus a hyphen plus **5 characters**, stored as `varchar(16)`. *(Corrected by #80: ADR-0007 said `char(9)`, which cannot hold the six-character form item 4 below widens to after five collisions, nor a prefix of any other length. `docs/data-model.md` §2.5 already said `varchar(16)` and is authoritative on schema.)*
  2. **Alphabet** — **30** unambiguous symbols: the digits and uppercase letters minus `0`, `O`, `I`, `1`, `L` and `U`. That is **24.3 million** combinations at five characters. Stored uppercase; compared case-insensitively; guest input normalised by uppercasing and stripping non-alphanumerics before lookup. *(Corrected by #80: this said 31 symbols and ~28.6 million, which are the figures for a 31-symbol alphabet — eight digits plus twenty-two letters is thirty, and 30^5 is 24.3 million. The **rule** is the specification and is unchanged; the count and the total were arithmetic that did not follow from it. `BookingReferenceTest` now asserts the count, so the two cannot drift apart again.)*
  3. **Uniqueness** — per tenant, enforced by a composite unique index on (`tenant_id`, `reference`). Any cross-tenant surface (super-admin search, platform support) MUST always display the operator alongside the reference, because the reference alone is not globally unique.
  4. **Collision** — generate randomly and retry on unique-constraint violation up to 5 times, then widen to 6 characters. No central counter, no coordination.
  5. **Ownership** — a `BookingReference` value object owns the alphabet, generation, normalisation and validation, plus a validation rule object that reports errors in EL and EN. Vouchers, quotes and tokens use their own formats and do not share this alphabet space.
- **BKG-4** The reference is assigned at first persistence (draft creation) and is immutable for the life of the booking, including through cancellation and expiry. References are never reused.

### 6.2 Instant-book flow (per-seat and per-vessel)

- **BKG-5 (FIXED)** The flow is: availability read → `POST /bookings` creating a draft with a hold → `POST /bookings/{id}/checkout` returning a gateway redirect → gateway webhook → `confirmed` → `BookingConfirmed` event.
- **BKG-6** `POST /bookings` validates the party against §5, computes the price server-side, writes `price_snapshot` and the policy snapshot, acquires the hold, sets `hold_expires_at`, generates the reference and the tokens, and returns the booking UUID plus the computed price breakdown. Status is `draft`.
- **BKG-7** Required fields at draft creation: product, date, start time (per-vessel with flexible start), pax breakdown by age band, extras with quantities, and locale; optional voucher code and optional special requests. *(Amended 2026-09-09 by **ADR-0030**: the lead guest name, email and phone and the explicit consent were required here and are now required **before payment** instead — `StartCheckout` refuses a booking that has neither. A draft is a hold on seats; the guest identifies themselves on the checkout page at `/c/{manage_token}`, where they are also shown the price. The consent is still stored as a timestamp with an IP address, recorded beside the payment it authorises.)*
- **BKG-8** Lead guest email is validated syntactically and by MX lookup where available; phone is normalised to E.164 using the guest-selected country or the tenant country as default. A malformed phone blocks SMS but MUST NOT block the booking.
- **BKG-9** `POST /bookings/{id}/checkout` transitions `draft` to `pending_payment`, re-verifies availability and the hold, re-verifies the voucher, creates a `Payment` row in `pending` with an idempotency key, creates the gateway session, and returns the redirect target. Seats move from `seats_held` to `seats_sold` at this point. **(RESOLVED — committing at redirect rather than at webhook prevents the gap where a guest is on the gateway page while another guest takes the last seat.)**
- **BKG-10** If the guest abandons the gateway page, `pending_payment` reverts to `expired` after the gateway session lifetime plus a grace period (default 60 minutes total), releasing capacity. The revert is performed by a scheduled job and is idempotent.
- **BKG-11** The verified gateway webhook is the only authority for a successful payment. On success the booking transitions `pending_payment` to `confirmed`, `paid_cents` is increased, `balance_cents` recomputed, `confirmed_at` set, `hold_expires_at` cleared, and `BookingConfirmed` is emitted.
- **BKG-12** On webhook failure or explicit cancellation at the gateway, the booking returns to `draft` with a fresh 15-minute hold if capacity still allows, otherwise to `expired` with a guest-facing explanation.
- **BKG-13** All of the following are queued listeners on `BookingConfirmed`, each independently retryable and individually visible in the operator panel if it fails:
  1. generate the e-ticket PDF with QR codes and store it;
  2. send the confirmation email in the booking locale, with the ticket attached or linked;
  3. send the confirmation SMS if the tenant has SMS enabled and the phone is valid;
  4. issue the myDATA invoice, subject to ADR-0003;
  5. generate the ναυλοσύμφωνο for `per_vessel` bookings and request acceptance;
  6. send the guest-details email if `guest_details_required`;
  7. schedule the reminder jobs (BKG-16);
  8. dispatch the `booking.confirmed` outbound webhook if configured;
  9. update the departure `guaranteed` state if `min_pax` is now met (AVL-48).
- **BKG-14** A failure in any listener MUST NOT roll back the confirmation or block the others. Failed listeners appear in the operator panel with a plain-Greek explanation and a retry button.

### 6.3 Guest details, reminders and the day of departure

- **BKG-15 (FIXED)** When `guest_details_required` is set, the guest receives a link to `/g/{guest_details_token}` on confirmation, with reminders at the deadline minus 48 hours and minus 24 hours. The deadline is `starts_at_utc - guest_details_deadline_hours`.
- **BKG-16** Reminder schedule for every confirmed booking, all idempotent and all logged in the `Notification` log:
  | Reminder | Timing | Channels | Suppressed when |
  |---|---|---|---|
  | Guest details | deadline −48h, −24h | email, SMS at −24h | `guest_details_status = complete` or not required |
  | Balance due | per ADR-0018 | email, SMS | balance is zero or paid |
  | Pre-departure | `starts_at_utc` −24h | email, SMS | booking cancelled |
  | Charter agreement acceptance | −72h, −24h | email | already accepted or not per-vessel |
  | Voucher expiry | −30 days, −7 days from voucher expiry | email | fully redeemed |
- **BKG-17 (FIXED)** The pre-departure reminder contains the meeting point (with map link), check-in time (`start_time - check_in_offset_minutes`), what to bring, and a weather note.
- **BKG-18** Reminder jobs are scheduled with the tenant timezone and are not sent between 21:00 and 08:00 local; a reminder that would fall in that window is delivered at 08:00, unless doing so would place it after the event it warns about, in which case it is dropped and logged. **(RESOLVED — SMS at 03:00 is a support incident.)**
- **BKG-19** A booking whose total is zero (fully covered by a voucher, or a free product) skips the gateway entirely and transitions `draft` to `confirmed` directly, still emitting `BookingConfirmed` and all of BKG-13.
- **BKG-20 (FIXED)** On the day of departure, crew check in guests through a QR scan page in Filament that works on a phone; the manifest can be exported at any time.
  - *Amended 2026-09-11 by the product owner:* QR boarding is **optional per operator**, switched by the platform on `/admin` (`tenants.qr_check_in_enabled`, default on, audited per SEC-16). Off, tickets carry no QR, and both boarding pages lose their scan box and ignore `?ticket=`: the check-in page is the day's passenger list with a tap per name (and early boarding with a reason, per BKG-22), and the offline page (OPS-12) is the same list with a button per name, queued without a signal exactly as a scan is. Check-in itself (BKG-21 … BKG-23) is unchanged: only the scan is optional.
- **BKG-21** `confirmed` transitions to `checked_in` when at least one guest on the booking is checked in. A scheduled job transitions `checked_in` and `confirmed` bookings to `completed` at `ends_at_utc` plus 3 hours, and transitions the departure to `completed`.
- **BKG-22** Check-in is possible from `check_in_offset_minutes` before departure until `ends_at_utc`; earlier check-in requires an explicit operator override which is logged.
- **BKG-23** No-show marking is available per guest and per booking, is reversible, and does not itself trigger any refund logic.

### 6.4 Quote and enquiry flows

- **BKG-24 (FIXED)** `quote` mode shows no price. The guest submits date, pax and requests; the booking is created in `quote_requested`; the operator builds a `Quote` in the panel; the guest receives a pay link; on payment the booking behaves like `per_vessel`.
- **BKG-25** A `quote_requested` booking holds nothing. The vessel window is only held once the operator sends the quote **and** the operator explicitly opts to hold the window, in which case a `VesselBlock` with reason `manual` is created with a visible expiry equal to `Quote.valid_until`. **(RESOLVED — otherwise a quote request would block a vessel indefinitely.)**
- **BKG-26** Sending a quote transitions the booking to `quote_sent` and emails a link to `/q/{quote_token}`. Accepting the quote creates the price and policy snapshots, transitions to `pending_payment` and creates the gateway session. Declining transitions to `cancelled` with reason `quote_declined`. Passing `valid_until` transitions to `expired` by a scheduled job.
- **BKG-27** Quote line items are operator-authored and may include free-text lines; the total is still stored as integer cents and still produces a `price_snapshot`.
- **BKG-28 (FIXED)** `Enquiry` ("ask a question") is a lightweight record with product (optional), name, email, phone, preferred date, pax, message and status. It is not a booking and never touches availability.
- **BKG-29** Enquiries are rate-limited and spam-protected (honeypot plus timing check, no third-party CAPTCHA in MVP), and notify the operator by email immediately.

### 6.5 Manual and imported bookings

- **BKG-30 (FIXED)** The operator can create manual bookings (phone, walk-in) and mark them paid by cash or bank transfer.
- **BKG-31** A manual booking goes through the same availability and pricing engine; the operator may apply a `discount_cents` with a reason and may override the total, both recorded in the price snapshot as operator adjustments.
- **BKG-32 (RESOLVED by #89)** A manual booking may exceed `min_lead_time_hours` and `max_advance_days` restrictions but MUST NOT exceed capacity or the legal `capacity_max` (AVL-25). Capacity override requires an explicit confirmation and is logged. **The two halves contradict each other read plainly** — "must not exceed capacity" and "capacity override requires confirmation" — and there is exactly one reading in which both are true: the **legal `capacity_max`** is a certificate rather than a commercial decision and has **no override at all**; the **departure's own `capacity`** is a number the operator chose and may be exceeded with an explicit reason, which is written to the trail as an `override.applied` row. An operator may squeeze one more person onto a boat they under-sold; they may not sail illegally full. Note also that AVL-25 counts **every** person including non-capacity-counting bands, so the infants a commercial capacity ignores are exactly the ones the legal ceiling does not.
- **BKG-33** Cash and bank payments create `Payment` rows with `gateway = manual` and `kind` as appropriate; they never call a gateway and are excluded from gateway reconciliation.
- **BKG-34** Imported bookings (source `import`) are created in `confirmed` with a synthetic price snapshot derived from the source data, are flagged as imported in the panel, and MUST NOT trigger confirmation notifications, invoices or webhooks.

### 6.6 Tokenised guest pages

- **TOK-1 (FIXED)** Four tokenised, account-free guest pages: `/b/{manage_token}`, `/g/{guest_details_token}`, `/q/{quote_token}`, `/v/{voucher}`.
- **TOK-2** Tokens are 40 characters of cryptographically secure URL-safe random text, unique per purpose per booking, stored in an indexed column, and never derived from any other identifier. `/v/{voucher}` uses the voucher code itself, which is therefore also generated with sufficient entropy and is rate-limited harder.
- **TOK-3** Token pages are served with `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, and a `Referrer-Policy: no-referrer` header so tokens never leak through referrers to analytics or map providers.
- **TOK-4** Token lookups are rate-limited to 30 requests per minute per IP and 10 failed lookups per minute per IP; failures return a generic branded "link not valid" page in the guest locale, never a distinction between "not found" and "expired".
- **TOK-5** Token pages are fully branded (BRD-1), mobile-first and available in EL and EN, with the locale taken from the booking and overridable by a `?lang=` parameter.
- **TOK-6** `/b/{manage_token}` — manage booking. Shows the booking summary, meeting point and map, check-in time, price breakdown, payment status, downloadable e-ticket and invoice when issued. Actions: download ticket, pay balance (mints a fresh gateway session, ADR-0004), cancel per policy showing the exact refund amount computed from the policy snapshot before confirming, choose refund/voucher/rebook after a weather cancellation (CXL-7), and edit lead-guest contact details.
- **TOK-7** The cancel action on `/b/{manage_token}` is disabled after `starts_at_utc`, for bookings already cancelled or refunded, and where the policy snapshot yields 0% with a `free_cancellation_hours` of zero — in that last case it is shown but clearly states that no refund is due, and still allows the guest to release the seat.
- **TOK-8** `/g/{guest_details_token}` — passenger details. Collects, per guest, full name, date of birth, nationality and document type plus number, with the number of guest rows fixed by the pax breakdown. For per-vessel bookings it also presents the ναυλοσύμφωνο for acceptance (a checkbox, capturing timestamp and IP). Partial saves are allowed; the status becomes `complete` only when every required field for every guest is present.
- **TOK-9** The guest-details form explains in plain Greek and English why document numbers are needed (Λιμεναρχείο manifest), how long they are kept (GDR-4) and who sees them.
- **TOK-10** `/g/{guest_details_token}` remains accessible after the deadline and after departure, read-only, until the document purge (GDR-4) removes the document fields; after purge the page shows the booking summary without documents.
- **TOK-11** `/q/{quote_token}` — view, accept, decline or pay a quote. Shows line items, total, validity, the operator message and the cancellation policy. Expired quotes render read-only with a "request a new quote" action that creates an Enquiry.
- **TOK-12** `/v/{voucher}` — voucher balance. Shows the code, original amount, remaining amount, expiry, and the operator products it can be used against. It MUST NOT reveal the booking or guest it was issued for.
- **TOK-13** Any state-changing action on a token page is a POST with CSRF protection and is idempotent; a double submit never double-cancels or double-charges.

### 6.7 Payments

- **PAY-1 (FIXED)** Guests pay the operator through the operator own Viva Wallet Smart Checkout credentials. The platform never holds guest money and never collects on an operator behalf.
- **PAY-2 (FIXED)** Gateway integrations sit behind `App\Contracts\PaymentGateway` with implementation `VivaSmartCheckoutGateway`. A second is a class, not a refactor (ADR-0004, ADR-0028).
- **PAY-3 (FIXED)** Operator credentials are encrypted at rest with the `encrypted` cast.
- **PAY-4** Credential storage shape and the deposit/balance model (per [ADR-0004](adr/0004-payment-credentials-and-deposit-model.md), Options A and D):
  1. **Credential storage** — an `integration_credentials` table, one row per (`tenant_id`, `provider`, `environment`) where `environment` is `live` | `test`, holding an `encrypted`-cast credential JSON blob plus `public_config`, `is_default`, `is_active`, `verified_at`, `last_error` and an `encrypted` `webhook_secret`. This is the §3 mandate (PAY-3) with no new infrastructure; there is no external secret store and no per-tenant key separation in MVP. Residual risk is handled by encrypting backups with a key separate from `APP_KEY`, keeping `APP_KEY` out of the backup set, and never logging credentials (MYD-15, SEC-9). **Reconciled with `docs/data-model.md` §2.7 by #79** — ADR-0004 Option A illustrated the table as `payment_gateway_accounts` with a `gateway` discriminator and a `mode` of `live` | `sandbox`; the data-model table is a strict superset that also holds the myDATA, SMS and Postmark credentials, whose alternative is three more tables or a column group on `tenants` (a rebuild on SQLite, `docs/data-model.md` §0). The ADR's *decision* is unchanged. `sandbox` became `test` because `api_keys.environment` was already `live` | `test` and two vocabularies for one concept is how a query eventually asks the wrong one; PAY-11 and SAA-9 keep saying "sandbox mode" in prose, which is a mode and not a column value. The reason is recorded in `CHANGELOG.md` per `docs/api.md` §10 item 5.
  2. **Gateway contract** — `App\Contracts\PaymentGateway` needs exactly four methods: `createCheckoutSession(Booking, PaymentKind, Money): RedirectTarget`, `verifyWebhook(Request): bool`, `refund(Payment, Money): RefundResult`, `describeError(string): TranslatableMessage`. No card-on-file, no stored mandate, no off-session charging — neither gateway supports that shape behind one abstraction.
  3. **Deposit and balance are two independent checkout sessions.** The booking confirms on a successful `deposit` payment. The balance is a **second** session minted on demand from `/b/{manage_token}`, priced from `booking.balance_cents` at the moment the guest opens the page, so a legitimately changed balance (extras added, pax changed) is charged correctly. Emailed balance links point at that page, **never** at a gateway URL that can go stale.
  4. Each session produces its own `Payment` row (`kind` per PAY-8) created in `pending` before redirect with an idempotency key; only a verified webhook moves it to `succeeded`. Two gateway fees instead of one is an accepted cost.
  5. Because the guest must actively pay the balance, the reminder and dashboard machinery in PRC-27 is mandatory, not optional.
- **PAY-5 (FIXED)** Webhooks are signature-verified and processed idempotently.
- **PAY-6** Webhook handling: verify signature before any parsing; store the raw payload; respond 2xx within 5 seconds; do all work in a queued job keyed by the gateway event id so replays are no-ops.
- **PAY-7** An unverified webhook is rejected with 400, logged with the source IP, and rate-limited. A verified webhook for an unknown booking is stored and surfaced in the super-admin gateway error feed rather than discarded.
- **PAY-8** `Payment.kind` is one of `full`, `deposit`, `balance`, `refund` (FIXED). Payment rows are created before redirect in `pending` and only a verified webhook moves them to `succeeded` or `failed`.
- **PAY-9** Every payment carries an idempotency key that is sent to the gateway where supported and enforced by a unique index locally.
- **PAY-10** `paid_cents` is the sum of succeeded non-refund payments minus succeeded refunds; it is recomputed from `Payment` rows inside the same transaction, never incremented blindly.
- **PAY-11** Sandbox mode (SAA-9) uses the tenant test credentials, flags bookings `is_test`, and MUST be impossible to enable accidentally on a live tenant: switching modes requires re-entering credentials and shows a persistent banner.
- **PAY-12** Gateway errors are mapped through a per-gateway dictionary into plain Greek and English messages for both the guest (generic and reassuring) and the operator (specific and actionable). Raw gateway error text is never shown to a guest.
- **PAY-13** Refunds are initiated only from the operator panel or by the automated cancellation workflow, never by a guest action directly; the guest action requests, the system executes.
- **PAY-14** A reconciliation report lists, per day, gateway payments without a matching booking and bookings marked paid without a succeeded payment.

### 6.8 Notifications

- **NTF-1 (FIXED)** Email is sent through Postmark using Laravel Mail with a per-operator from-name and reply-to. Templates are Blade plus MJML-compiled HTML in EL and EN.
- **NTF-2 (FIXED)** SMS goes through `App\Contracts\SmsGateway` with `ApifonGateway`, `TwilioGateway` and `NullGateway`; the operator chooses and the platform provides a fallback.
- **NTF-3** Every send is recorded in the `Notification` log with booking, channel, template, locale, recipient, status and provider reference, and is visible on the booking timeline.
- **NTF-4** Notification locale is the booking locale, falling back to the tenant default locale, falling back to `en`.
- **NTF-5** SMS bodies are at most 160 GSM-7 characters where possible; Greek text falls back to UCS-2 and the composer MUST warn the operator about segment count. Every SMS includes the meeting point and time (FIXED by §15 docs-writer rules) and a short link.
- **NTF-6** Transactional emails MUST NOT require images to be readable, MUST render acceptably in Gmail, Outlook and Apple Mail, and MUST include a plain-text alternative.
- **NTF-7** Guests receive no marketing email from the platform. Operator marketing is out of scope.
- **NTF-8** Bounce and complaint webhooks from Postmark update the `Notification` log and flag the booking so the operator can call the guest.
- **NTF-9** Email and SMS templates are snapshot-tested in both locales (TST-5).
---

## 7. Widget, hosted pages and branding

### 7.1 Widget

- **WGT-1 (FIXED)** The widget lives in `packages/widget`, is written in Preact and TypeScript, is built by Vite into a single IIFE named `kaiki-widget.js`, and renders inside a Shadow DOM so host CSS can never leak in or out.
- **WGT-2 (FIXED)** Bundle budget: 80 KB gzipped or less for the main bundle. This is a hard CI gate; a build that exceeds it fails.
- **WGT-3 (FIXED)** Loaded as `<script src="https://.../kaiki-widget.js" data-key="pk_..."></script>`.
- **WGT-4** Distribution, versioning and cache headers (per [ADR-0011](adr/0011-widget-distribution-and-versioning.md), Option A):
  1. **Public URL is a major-version channel alias**: `https://cdn.{platform-domain}/widget/v1/kaiki-widget.js`, served `Cache-Control: public, max-age=300, stale-while-revalidate=86400`. This is the only URL the panel's embed-code generator and the WordPress plugin ever emit; operators never paste a pinned version.
  2. **Underneath it, builds are immutable**: `/widget/builds/{version}/kaiki-widget.js`, served `Cache-Control: public, max-age=31536000, immutable`. Both paths go through Cloudflare.
  3. **Breaking changes require an explicit channel bump** to `/widget/v2/`; `/v1/` keeps working. Patches propagate automatically within a channel.
  4. **Rollback is repointing the alias** to the previous build and purging that CDN path. A runbook for this is a deliverable.
  5. Every build embeds `__KAIKI_WIDGET_VERSION__`, exposes it on the `kaiki:ready` DOM event, and sends it as an `X-Kaiki-Widget-Version` request header so the API can measure adoption and deprecate safely.
  6. **Release gates before the alias is repointed**: the WGT-2 bundle-size gate, a Playwright smoke run on all four mounts against the built artefact, and an API compatibility test of that widget version against `/api/v1`.
  7. A `?v=` cache-buster is supported for support purposes but is never needed by the panel or the plugin.
- **WGT-5 (FIXED)** Mounts: `booking` (single product), `list` (trip grid with a from-price and category tabs), `calendar` (availability only; amended 2026-09-11 — with `data-link="trip"` an open day links to the trip's hosted page with `?date=`, and the booking walk there opens on the party step with that day chosen), `enquiry`.
- **WGT-6 (FIXED)** Attributes: `data-key`, `data-product`, `data-locale`, `data-theme`, `data-category`.
- **WGT-7** Additional attributes: `data-mount` (one of the four mounts, defaulting to `booking` when `data-product` is present and `list` otherwise), `data-analytics` (on by default), and `data-target` (a CSS selector for the mount node, defaulting to the script tag position). Added 2026-09-11: `data-link` (`trip` makes a calendar's open days links to the trip page; off by default) and `data-date` (a `YYYY-MM-DD` the booking walk opens on; the hosted trip page passes its `?date=` through it). Also 2026-09-11: the appearance attributes `data-primary`, `data-on-primary`, `data-text`, `data-background` (`#rrggbb`), `data-font` (`inherit` or a family name) and `data-radius` (0–30), applied over the branding value by value — written by the WordPress plugin's «Appearance» settings. A tag without `src` is an embed when it carries a `pk_` key (the plugin loads the bundle on the first shortcode only).
- **WGT-8** Multiple widget instances on one page MUST work: the loader is idempotent, shares one API client and one branding fetch, and namespaces its Shadow roots.
- **WGT-9 (FIXED)** The widget reads `GET /api/v1/branding` and applies branding as CSS custom properties (`--kaiki-primary`, `--kaiki-secondary`, `--kaiki-accent`, `--kaiki-background`, `--kaiki-text`, `--kaiki-radius`, `--kaiki-font`). No brand colour, radius or font is ever hardcoded in the widget source.
- **WGT-10 (FIXED)** The operator font is loaded from Google Fonts only when configured; otherwise the widget uses a system font stack and issues no third-party request.
- **WGT-11 (FIXED)** The widget emits DOM events for analytics: at minimum `kaiki:ready`, `kaiki:product-viewed`, `kaiki:availability-loaded`, `kaiki:booking-started`, `kaiki:checkout-started`, `kaiki:booking-confirmed`, `kaiki:enquiry-submitted`, `kaiki:error`. Event detail objects carry non-personal data only (product uuid, date, pax counts, value in cents, currency, widget version). No guest name, email, phone or token may appear in an event detail.
- **WGT-12** The widget MUST NOT set cookies or write to `localStorage` for tracking. It MAY use `sessionStorage` for the in-progress draft booking uuid, cleared on completion.
- **WGT-13 (FIXED)** The widget never computes prices. Every displayed amount comes from `POST /api/v1/price-quote` or from the draft booking response.
- **WGT-14** All visible strings come from EL and EN JSON bundles compiled into the build. A missing key renders the key in development, falls back to English in production, and fails the build in CI.
- **WGT-15** Locale resolution order: `data-locale`, then the host page `<html lang>`, then the tenant default locale, then `en`.
- **WGT-16** Network behaviour: read requests retry twice with exponential backoff on 5xx and network errors; write requests never retry automatically. Every request has a 10-second timeout. Every failure renders an inline localised error state with a retry action, never a blank widget.
- **WGT-17** Availability responses are cached in memory for 60 seconds per product and date range, and invalidated on any booking action.
- **WGT-18** The booking mount walks: date selection on a month grid that marks the sold-out days, party composition by age band, then extras — and hands the guest to the hosted checkout page with the hold already running. Back navigation between steps never loses entered data. *(Amended 2026-09-09 by **ADR-0030**: the contact and review steps are gone. They asked for a name and an email inside a 380-pixel embed before any price had been shown, and the review step took a `quote` prop nothing supplied — so it rendered «Υπολογίζουμε την τιμή σας…» permanently and a guest pressed pay having never seen a total. The price, the details, the passenger manifest and the consent are all on the checkout page.)*
- **WGT-19** The widget shows the hold countdown once a draft exists, warns at 2 minutes remaining, and on expiry re-fetches availability and explains what happened in the guest locale.
- **WGT-20** After the gateway redirect returns, the confirmation state is driven by the booking status endpoint, polling for up to 60 seconds while the webhook lands, then falling back to a message promising an email once payment is confirmed. *(Since ADR-0030 the gateway returns to the hosted checkout rather than to the embed, so the poll runs in the widget only for a guest who comes back to the operator's page with a payment already under way. The widget asks the booking's status once before polling: a guest who merely pressed Back on the checkout form is offered the page they left, not sixty seconds of spinner and a promise of an email that is not coming.)*
- **WGT-21 (FIXED)** Full keyboard operability and WCAG 2.1 AA (see A11Y).
- **WGT-22** The widget must render correctly inside hosts with aggressive global CSS, inside iframe-heavy page builders, and on pages with a strict CSP. It MUST NOT require `unsafe-inline`; styles are injected into the Shadow root by script, and a documented CSP snippet for operators is published in the guide.
- **WGT-23** Without JavaScript the widget renders nothing. Crawlable content is the responsibility of the hosted pages and the WordPress SEO pages instead.

### 7.2 Hosted pages

- **HOS-1 (FIXED)** Hosted pages live at `book.{platform-domain}/{operator-slug}`: a branded landing page with the product list, product pages, the widget, operator contact details, policies and legal pages.
- **HOS-2 (FIXED)** Product pages carry SEO metadata and JSON-LD (`Product` and `Event`).
- **HOS-3 (FIXED)** Optional custom domain by CNAME with automatic TLS. Resolution and issuance (per [ADR-0010](adr/0010-custom-domain-resolution-and-tls.md), Option A):
  1. **`tenant_domains` table** — `tenant_id`, `hostname` (globally unique, stored lowercased and punycode-normalised), `status` (`pending` | `verified` | `failed` | `disabled`), `verification_token`, `verified_at`, `last_checked_at`.
  2. **Verification before trust** — a queued job checks either a CNAME to `book.{platform-domain}` or a `_kaiki-verify` TXT record; only then does the row move to `verified`. Unverified domains never resolve and never get a certificate.
  3. **TLS by Caddy on-demand issuance, gated by the application.** The Caddyfile ships `on_demand_tls { ask ... }` pointing at `GET /internal/tls-ask?domain=`, which returns **200 only** when the hostname exists in `tenant_domains` with `status = verified` **and** the tenant subscription is active, and 403 otherwise. The endpoint is unauthenticated but IP-restricted to the Caddy container, cached in both Caddy and the application, and rate-limited. It fails closed.
  4. **Every certificate issuance is logged** and visible in the super-admin panel.
  5. **Plan gating** — custom domains are a Pro-plan feature behind a Pennant flag. Losing the plan sets `status = disabled`, which stops renewals and makes `tls-ask` return 403.
  6. Caddy configuration is a **production-only artefact** and is never invoked locally (§13, ADR-0015).
  7. Tenant resolution by hostname is step (2) of TEN-4; canonical URLs and the slug-to-domain redirect are HOS-7.
- **HOS-4** Hosted pages are server-rendered Blade, work without JavaScript for all content, and mount the widget only for the booking interaction.
- **HOS-5** Hosted pages are available in EL and EN with `hreflang` alternates, a language switcher, and a canonical URL per locale.
- **HOS-6 (amended by ADR-0029, twice)** A tenant on **bookings_only** serves its product pages, search and legal pages and returns 404 for the marketing home page alone; a tenant on **full** serves all of them. ~~A tenant that is **off** returns 404 on every URL~~ — retired 2026-09-11: an operator's checkout asks the guest to accept terms that live on the legal page, so the booking pages exist for every operator. The question belongs to `HostedPageController::index` and to `RootController`, which delegates to it on a custom domain.
- **HOS-7** When a custom domain is active, the `book.{platform-domain}/{slug}` URL issues a 301 redirect to the custom domain so the two never compete in search.
- **HOS-8 (FIXED)** Hosted pages carry a Content-Security-Policy allowing the platform API origin, the widget origin, Google Fonts when configured, and the gateway redirect origins, and nothing else. Operator custom CSS is sanitised (BRD-2) and can never become a script vector.
- **HOS-9** Hosted pages display the operator legal identity (legal name, ΑΦΜ, ΔΟΥ, address), the cancellation policy, terms and a privacy notice, in both locales.
- **HOS-10 (FIXED)** A read-only tenant (lapsed subscription) still serves its hosted pages, replacing the booking widget with a localised message directing the visitor to contact the operator.

### 7.3 Branding

- **BRD-4 (FIXED)** The branding admin in Filament offers a live preview of the widget and of an email while colours are edited, a contrast check warning, logo upload with automatic resize, and a reset to defaults.
- **BRD-5** The contrast check evaluates body text on background and button text on the primary colour, warning below WCAG AA (4.5:1 for body text, 3:1 for large text and UI components). It warns; it does not block.
- **BRD-6** `GET /api/v1/branding` returns the full brand payload with a strong ETag and `Cache-Control: public, max-age=300`, so widget first paint is never gated on a cold API.
- **BRD-7** Logo upload accepts SVG, PNG and WebP up to 2 MB, generates resized variants, strips EXIF metadata, and sanitises SVG (no scripts, no external references).
- **BRD-8** Branding changes take effect within the 5-minute branding cache TTL, with no widget rebuild.

---

## 8. WordPress plugin

- **WPP-1 (FIXED)** The plugin lives at `packages/wordpress-plugin/kaiki-booking`, targets PHP 8.1+ and WordPress 6.4+, uses no framework, uses PSR-4 through Composer autoload, and is a thin client of the public API only.
- **WPP-2 (FIXED)** It MUST NOT duplicate pricing or availability logic, MUST NOT touch the WooCommerce cart or checkout, and MUST NOT enqueue global CSS beyond a scoped `.kaiki-` namespace.
- **WPP-3 (FIXED)** Settings page: API keys, default locale mapping (WPML and Polylang to widget locale), cache TTL, SEO pages toggle. Which key types the plugin may hold (per [ADR-0013](adr/0013-api-key-model.md), Option A):
  1. **A standard installation stores a `pk_` and nothing else.** Everything the plugin renders — shortcodes, blocks, Elementor widgets, the widget mount, availability and price display — uses the publishable key only. The plugin is fully functional with `pk_` alone.
  2. **The optional SEO-pages feature (WPP-6) is the single exception.** `GET /api/v1/sync/products` returns the full catalogue including `draft` and `inactive` products and internal SEO fields, so it requires an `sk_`. That sync runs **server-side in WP-Cron and in the inbound webhook handler only**; the key is stored in WordPress options and is **never** enqueued, never printed into markup, never exposed to a REST route and never sent to the browser. Turning the SEO-pages toggle off means no `sk_` is needed at all.
  3. **Nothing the plugin renders client-side may carry an `sk_`.** SEC-9's CI grep covers the plugin as well as the widget bundle, and the API rejects any `sk_` request that carries an `Origin` header, so an accidental front-end use fails loudly rather than silently working.
  4. The settings page MUST state, in both locales, that the secret key is optional, what it unlocks, and that it must never be pasted into a page or a theme template.
  5. **OPEN — product owner veto point.** ADR-0013's recommendation was that a standard WordPress installation should never store a secret at all, which would mean making `sync/products` publishable-readable. The requirement above keeps `sk_` on that endpoint and confines it to server-side use instead. If the product owner prefers the ADR's recommendation, the change is to `docs/api.md` §9 and this requirement, not to the key model.
- **WPP-4 (FIXED, amended 2026-09-11)** Shortcodes: `[kaiki_booking product="uuid"]`, `[kaiki_list category="shared"]`, `[kaiki_enquiry]`. ~~`[kaiki_calendar product="..."]`~~ — removed by the product owner on 2026-09-11, with its block and Elementor widget: the booking form already opens on a month of availability. The widget's `calendar` mount (WGT-5) remains.
- **WPP-5 (FIXED)** Gutenberg blocks that are server-rendered wrappers around the shortcodes, plus Elementor widgets with the same controls.
- **WPP-6 (FIXED)** Optional SEO pages: a `kaiki_trip` custom post type synced from the API by cron and by inbound webhook, one post per product with server-rendered content plus a widget mount, a configurable permalink base (default `/tours/`), and theme template overrides at `kaiki/single-trip.php`.
- **WPP-7 (FIXED)** API reads are cached in transients; the cache is busted through an inbound webhook endpoint authenticated with HMAC.
- **WPP-8** The inbound webhook endpoint verifies an HMAC-SHA256 signature over the raw body using a shared secret plus a timestamp within 5 minutes, rejects replays, and returns 2xx quickly.
- **WPP-9 (FIXED)** Coexists with WooCommerce. Styles are scoped and tested against Woodmart, Astra and Hello Elementor.
- **WPP-10 (FIXED)** Ships as a zip through a GitHub release, with auto-updates served by a lightweight update endpoint on the platform.
- **WPP-11 (FIXED)** WordPress coding standards (phpcs WordPress ruleset), everything prefixed `kaiki_`, all output escaped, all admin actions nonced, uninstall cleanup registered.
- **WPP-12** The plugin stores no guest personal data in WordPress. Bookings live on the platform; the plugin holds no booking records.
- **WPP-13** Locale detection order: WPML, then Polylang, then the WordPress site locale, mapped to `el` or `en`; unmapped locales fall back to `en`.
- **WPP-14** The plugin surfaces API errors as a localised admin notice for editors and a neutral message for visitors. A platform outage MUST NOT produce a PHP fatal error or a blank page.
- **WPP-15** Plugin end-to-end tests run with Playwright against a WordPress site whose URL comes from `.env` (`KAIKI_WP_TEST_URL`). `wp-env` is **not** used — see §13 and [ADR-0015](adr/0015-local-development-stack.md). This overrides §8 and §14 M4.27 of the brief.

---

## 9. Operations (operator back-office)

- **OPS-1 (FIXED)** Dashboard: today and tomorrow departures with pax, at-risk departures, pending guest details, pending quotes, unpaid balances, revenue this week.
- **OPS-2** Each dashboard figure has a precise definition. "Revenue this week" is the sum of succeeded non-refund payments minus succeeded refunds, within the tenant-timezone week starting Monday, for non-test bookings only.
- **OPS-3 (FIXED)** Calendar: a per-vessel timeline showing shared departures, private blocks and external blocks; drag to create a block; click a departure to see the pax list.
- **OPS-4** The calendar renders the turnaround buffer as a visually distinct margin on each occupation, so conflicts are explicable to the operator without reading documentation.
- **OPS-5 (FIXED)** Manual bookings with mark-as-paid cash or bank (BKG-30 to BKG-33).
- **OPS-6 (FIXED)** Weather cancellation: select one or more departures, choose refund, voucher or a rebook offering, preview the affected guests, then send.
- **OPS-7** The weather-cancel preview lists, per booking, the guest name, pax, amount paid, the refund computed from the policy snapshot, and the chosen outcome, before anything is sent. Sending is one confirmed action that dispatches queued jobs and is idempotent.
- **OPS-8 (FIXED)** Manifest per departure or per private booking, in CSV and PDF, with configurable columns. Defaults: full name, date of birth, nationality, document number, vessel, date, port, captain. A separate print-for-the-harbour layout exists.
- **OPS-9** The manifest includes every person on board, including age bands that do not count toward capacity, and states the total head count against `vessel.capacity_max`.
- **OPS-10** Generating a manifest containing document numbers is an explicit, logged operator action (GDR-6). The standard bookings and guests CSV exports exclude document numbers.
- **OPS-11 (FIXED)** Check-in: QR scan page, manual toggle, no-show marking; works on a phone.
- **OPS-12** The check-in page tolerates an intermittent connection: scans queue locally and sync when connectivity returns, and scanning an already-checked-in ticket reports that clearly instead of failing.
- **OPS-13 (FIXED)** iCal export URL per vessel containing blocks and departures with sold seats; iCal import URLs polled every 15 minutes creating `VesselBlock` rows with reason `external_ical`.
- **OPS-14** iCal export feeds are tokenised, unguessable, revocable and rate-limited. They expose no guest personal data, only busy periods with a neutral summary.
- **OPS-15** iCal import is idempotent by external UID. Removing an event at the source removes the corresponding block on the next sync unless that block has been converted into a booking. Failures retry with backoff and are surfaced to the operator after three consecutive failures.
- **OPS-16 (FIXED)** Vouchers: issue, list, redeem (the widget accepts codes), expiry reminders.
- **OPS-17 (FIXED)** Exports: bookings CSV for accounting and guests CSV.
- **OPS-18** Exports run as queued jobs, are streamed to avoid memory pressure, are delivered as a download link that expires after 24 hours, and are logged.
- **OPS-19 (FIXED)** Outbound webhooks for `booking.confirmed`, `booking.cancelled`, `departure.cancelled`, `guest_details.completed`.
- **OPS-20** Outbound webhooks are HMAC-signed with a per-endpoint secret, carry a timestamp and an event id, retry with exponential backoff for up to 24 hours, and expose their delivery history in the panel. Guest document numbers never appear in a webhook payload.
- **OPS-21** Every operator-visible failure (payment, myDATA, SMS, iCal, webhook, PDF) appears in one consolidated error feed with a plain-Greek explanation, the affected booking, and a retry action.
- **OPS-22** The back-office is fully usable on a phone browser (FIXED, OOS-6), verified by a Playwright run at a 390 by 844 viewport covering the dashboard, today departures, check-in and manual booking.
---

## 10. Greek compliance

### 10.1 Passenger manifest (δήλωση επιβατών)

- **CMP-1 (FIXED)** The legally required fields are the default manifest columns; the operator may add columns but MUST NOT remove a legally required one.
- **CMP-2** The manifest is generated per departure (per-seat) or per booking (per-vessel), in CSV and PDF, plus a print-for-the-harbour layout with larger type and the vessel and captain details in the header.
- **CMP-3** Manifest PDFs render Greek text correctly, handle long names without clipping, and are tested with at least 14 guests on one page and with a multi-page case.
- **CMP-4** A manifest is only generated from guest records that exist; missing guest details are shown explicitly as gaps rather than silently omitted, so the operator can chase them.
- **CMP-5** Every manifest generation writes a `ManifestExport` row recording who generated it, when, in what format, and for which departure or booking.

### 10.2 Ναυλοσύμφωνο (charter agreement)

- **CMP-6 (FIXED)** A Blade template renders the ναυλοσύμφωνο with the operator legal data, vessel, charter window, port, pax, price and terms. The template is versioned; the generated PDF is stored; guest acceptance is captured as a checkbox with timestamp and IP in the guest-details flow; both parties are emailed.
- **CMP-7** The template version and the filled-field snapshot are stored on the `CharterAgreement` so a regenerated PDF is always byte-comparable in content to the one the guest accepted.
- **CMP-8** Changing the template never alters an already-accepted agreement. A new version applies to new bookings only.
- **CMP-9** Acceptance is required for per-vessel bookings but MUST NOT block the booking itself; it is chased by reminders (BKG-16) and shown as outstanding in the dashboard.
- **CMP-10 (FIXED, flag off)** Operator-uploaded PDF templates with placeholders are template v2, behind the Pennant flag `charter_agreement_custom_template`, default off, not built in MVP (OOS-9).

### 10.3 myDATA

- **MYD-1 (FIXED)** The operator enters their own AADE user id and subscription key, stored encrypted. Kaiki issues under the operator credentials; the platform is never the issuer.
- **MYD-2 (FIXED)** On confirmation, or by manual trigger, the system issues an ΑΛΠ (default) or a ΤΠΥ when the guest supplies an ΑΦΜ or company details.
- **MYD-3** Type selection, timing and the auto-issue default (per [ADR-0003](adr/0003-invoice-type-and-issuance.md), Option A):
  1. **Type is derived from data, not chosen by the guest in checkout.** At issuance time, if the booking carries a **validated** customer ΑΦΜ plus a legal name, issue **ΤΠΥ**; otherwise issue **ΑΛΠ**. This is a pure function of booking data, unit-testable, and explainable in the operator error feed.
  2. **Auto-issue defaults on.** Per-tenant settings `invoice_auto_issue` (default **on**) and `invoice_auto_issue_delay_minutes` (default **15**). Both appear in the onboarding wizard. An operator who wants fully manual issuance turns `invoice_auto_issue` off and issues from the panel.
  3. **The issuance job runs `invoice_auto_issue_delay_minutes` after `BookingConfirmed`,** not immediately, so the guest has a window to supply tax details.
  4. **Checkout stays minimal.** The "Χρειάζομαι τιμολόγιο / I need a company invoice" toggle and its conditional fields (ΑΦΜ, ΔΟΥ, legal name, country code) live on the **confirmation page** and in the guest-details flow, never as a mandatory step in checkout. Those fields are stored on the Booking.
  5. **ΑΦΜ validation is a precondition.** A 9-digit modulus-11 checksum for Greek numbers and EU VAT prefix handling for foreign customers, implemented in `app/Domain/Compliance`. An ΑΦΜ that fails validation does not produce a ΤΠΥ; it produces an ΑΛΠ and an operator-visible warning.
  6. **`IssueInvoice` is idempotent on (`booking_id`, `type`, `series`)** (MYD-14).
  7. Tax details supplied **after** issuance trigger the cancellation-invoice path (MYD-13) plus a re-issue; both appear in the operator error feed.
- **MYD-4** Invoice numbering (per [ADR-0022](adr/0022-invoice-numbering-scope.md), Options A and C):
  1. **Scope** — numbering is per (`tenant_id`, `series`, `year`), resetting each calendar year. `invoices` keeps `unique(tenant_id, series, year, number)` and `number` is nullable until allocated.
  2. **Late allocation** — the number is allocated at the **send attempt** (the `pending → sent` transition), never at row creation, so a payload that is never submitted never burns a number.
  3. **Allocation is serialised** by locking a `series_counters` row for that (`tenant`, `series`, `year`) inside the transaction, using the same portable pattern as AVL-43 (`lockForUpdate()` plus a conditional update); the unique index is the real guarantee and the allocator retries on violation.
  4. **Gaps are permitted and MUST be logged.** A hard failure after allocation writes an `invoice_number_gaps` audit row carrying the number, the reason and the timestamp, surfaced in the operator panel in Greek. Gaps are rare by construction, not impossible.
  5. **Per-tenant escape hatch** — `tenants.invoicing_mode` is `kaiki` (default) or `external`. In `external` mode Kaiki issues nothing: issuance is disabled and the panel exposes a "record external invoice" form storing the operator-supplied number and `mark` for reconciliation.
  6. **⚠ This requirement needs an accountant's sign-off before M6.** ADR-0022 was accepted on engineering grounds; whether *any* gap is acceptable in a Greek invoicing series is not an engineering question. If the accountant rules gaps out, the change is to (2) and (4) — allocate only after AADE returns a `mark` — and the schema in (1) and (3) still stands. Do not treat the gap policy as settled. See §16.3. **This supersedes the previous "strictly sequential with no gaps" wording, which was an interpretation, not a brief requirement.**
- **MYD-5** An `Invoice` moves `pending` to `sent` only on an AADE response containing a `mark`. Any other outcome sets `failed` with `last_error` and increments `retries`.
- **MYD-6 (FIXED)** VAT categories for passenger transport and tourist services are configurable per product and MUST NOT be hardcoded. The **mechanism** is settled (per [ADR-0002](adr/0002-vat-rate-resolution.md), Option A): the `vat_rates` reference table holds the percent and the AADE `vat_category` id together with validity dates; `products.vat_rate_id` and `extras.vat_rate_id` resolve one per line; the resolved pair is snapshotted into `price_snapshot` at pricing time and the myDATA client reads `vat_category` from that snapshot. **The myDATA client contains no numeric rate and no percent-to-category mapping** — the mapping lives in the table row, so an AADE category change is data, not a deploy. Full mechanism in CAT-11.
- **MYD-6a** **The rates themselves remain an accountant question and are deliberately unanswered.** §10 of the brief says passenger transport is *typically* 13% and other tourist services *typically* 24%, and marks the actual figures "DECIDE with accountant". ADR-0002 does not settle them and neither does this document. They are seeded as `vat_rates` rows during onboarding, alongside any reduced island-rate regime that applies to the operator. No code, config file, seeder default or test fixture may present a percentage as authoritative. See CAT-11b and §16.3.
- **MYD-7** Prices are stored VAT-inclusive (PRC-13). The net amount and VAT amount per line are derived at issuance with half-up rounding, and the sum of line nets plus the sum of line VAT amounts MUST equal the gross total exactly; any rounding residue is applied to the largest line.
- **MYD-8 (FIXED)** ISO country codes are used for foreign customers.
- **MYD-9 (FIXED)** AADE error codes, starting with error 243 and its neighbours, are surfaced with plain-Greek explanations. The dictionary lives in `docs/compliance/mydata-errors.md` and is loaded from lang files at runtime.
- **MYD-10 (FIXED)** Issuance runs through a retry queue with exponential backoff. Retries are bounded (default 8 attempts over 24 hours); after exhaustion the invoice sits in `failed` and appears in the operator error feed with a manual retry action.
- **MYD-11 (FIXED)** Development and production AADE endpoints are selected per environment, never per request, and the current endpoint is displayed in the panel so an operator can see they are in test mode.
- **MYD-12 (FIXED)** The invoice PDF carries the myDATA QR code and is downloadable by the guest from `/b/{manage_token}` once issued.
- **MYD-13 (FIXED)** A refund triggers a cancellation invoice (or a credit note as appropriate), issued through the same retry machinery and linked to the original invoice.
- **MYD-14** All myDATA calls are queued, idempotent per booking and invoice type, and never made inline in a web request.
- **MYD-15** myDATA payloads and credentials are never logged. Request and response bodies are stored on the `Invoice` in a redacted form sufficient for support.
- **MYD-16** myDATA activation for a tenant is gated on: credentials verified against the AADE test endpoint, a VAT rate assigned to every sellable product, and complete legal identity (legal name, ΑΦΜ, ΔΟΥ, address).

### 10.4 GDPR and retention

- **GDR-1 (FIXED)** Passport and identity document numbers are encrypted at rest.
- **GDR-2 (FIXED)** Guest documents are auto-purged N days after departure, default 90, configurable.
- **GDR-3** Encryption approach and retention bounds (per [ADR-0012](adr/0012-guest-document-encryption-and-retention.md), Option A):
  1. **Encryption** — `booking_guests.document_number` and `document_type` use the Laravel `encrypted` cast (AES-256-GCM under `APP_KEY`). There is no envelope encryption and no per-tenant data key in MVP; both can be added later behind the same accessor without a schema change if a customer demands it. There is no blind index, because no requirement searches by document number.
  2. **Retention window** — configurable per tenant as `tenants.document_retention_days`, **minimum 30, maximum 365, default 90**, counted from the departure date. Values outside the bounds are rejected at save.
  3. **Purge scope is document fields only.** `PurgeGuestDocuments` clears `document_number` and `document_type`. Guest **name, date of birth and nationality are not purged by this job** — they are needed for accounting and dispute history and follow the general booking retention policy, which the privacy notice states separately.
  4. The daily purge job is idempotent and writes an audit row (tenant, count, run time) containing **no** personal data (GDR-4).
  5. Both fields are excluded from `toArray()`, from Sentry payloads, from structured logs, from outbound webhooks and from the standard CSV exports; they appear only in the manifest export, which is an explicit logged operator action (GDR-6). A test asserts they never appear in a log line, an exception trace or any API response body.
  6. The privacy policy, the DPA and the onboarding wizard MUST state the configured window and the purge behaviour (GDR-7, GDR-8).
- **GDR-4** The purge job runs daily, is idempotent, and writes an audit record containing counts and timestamps but no personal data.
- **GDR-5 (FIXED)** Per-guest data export and deletion by email address, available to the operator and executable on request.
- **GDR-6** Document numbers are excluded from model serialisation, from logs, from Sentry payloads, from outbound webhooks and from the default CSV exports. They appear only in the manifest export, which is an explicit logged action.
- **GDR-7 (FIXED)** Processor terms are presented and accepted during onboarding, with acceptance recorded.
- **GDR-8** The tenant is the data controller and Kaiki is the processor. The privacy notice on hosted pages and in the guest-details flow states this in both locales.
- **GDR-9** Guest consent to the operator terms and privacy policy is captured at draft creation with a timestamp and IP (BKG-7) and stored on the booking.
- **GDR-10** Deleting a tenant purges or anonymises all tenant-owned personal data within 30 days, retaining only what accounting and tax law require (invoices, payment records), which are documented in the DPA.
- **GDR-11** A data export produces machine-readable JSON plus a human-readable CSV, covering bookings, guests, notifications and payments associated with the email address.
- **GDR-12** Analytics events emitted by the widget carry no personal data (WGT-11), and the platform sets no tracking cookie on guest-facing pages.

---

## 11. SaaS layer

- **SAA-1 (FIXED)** Super-admin panel at `/admin`: tenants, plans, impersonation, feature flags, platform health, failed jobs, myDATA and gateway error feed, announcement banner.
- **SAA-2** Impersonation is time-limited (default 60 minutes), audited (who, which tenant, when, why), and shows a persistent banner in the operator panel (TEN-7).
- **SAA-3 (FIXED, mechanism withdrawn)** Subscriptions. Plans: `Solo` (1 vessel), `Fleet` (up to 5 vessels), `Pro` (unlimited vessels plus custom domain plus webhooks). ~~through Cashier~~ — ADR-0028 removed the provider; the plans are unchanged, and **what an operator gets is unrelated to how they are charged for it**.
- **SAA-4 (FIXED)** 14-day trial, card required at the end of the trial.
- **SAA-5 (FIXED)** Dunning emails on failed payment.
- **SAA-6 (FIXED)** Read-only mode when a subscription lapses: the widget keeps showing a contact-the-operator message.
- **SAA-7** Read-only mode blocks all writes in `/app` and all API write endpoints, but MUST NOT break tokenised guest pages, existing bookings, e-tickets, manifest access or myDATA retries for already-confirmed bookings. Guests already holding a booking are never punished for an operator billing failure.
- **SAA-8** Plan limits are enforced at the point of creation with a clear upgrade path: creating a sixth vessel on `Fleet` is blocked with a localised message and an upgrade link, never silently truncated.
- **SAA-9 (FIXED)** Onboarding wizard: legal details, first vessel, first product, pricing, payment gateway, branding, embed code and plugin download, then a test booking in sandbox mode.
- **SAA-10** The onboarding wizard is resumable, shows progress, and can be skipped step by step; a tenant that has not completed it sees a persistent checklist rather than a blocking modal.
- **SAA-11 (FIXED)** Sandbox mode per tenant: gateway test keys, bookings flagged `is_test`, purged nightly.
- **SAA-12** Test bookings are excluded from every dashboard figure, every export, every myDATA issuance and every outbound webhook, and are visually distinct in the panel.
- **SAA-13 (FIXED)** Importer for WooCommerce plus YITH Booking plus WCPA: the operator supplies WP REST or Woo API keys, or uploads a WXR export plus CSV. Mapping: products to Products and RatePlans, categories to product categories, YITH people types to age bands, upcoming bookings to Bookings with status `confirmed` and source `import`, customers to lead guests.
- **SAA-14 (FIXED)** The importer runs a dry run first and presents a mapping review screen before anything is committed.
- **SAA-15** The importer is idempotent by source identifier, resumable after failure, and produces a per-row log with reasons for every skipped record. Imported bookings never trigger notifications, invoices or webhooks (BKG-34).
- **SAA-16 (FIXED)** A docs site generated from `docs/` provides a Greek and English operator guide, the WordPress plugin guide, an API reference and a Greek glossary. The static-site tooling requires an ADR before M7 (see `docs/adr/README.md`, future ADRs).
- **SAA-17** Platform health in `/admin` shows queue depth and oldest job age, failed job counts by class, myDATA failure counts per tenant, gateway webhook failure counts, and iCal sync failures.

---

## 12. Non-functional requirements

Every threshold below is an acceptance criterion with a stated measurement method. A requirement without a measurement method is not a requirement.

### 12.1 Internationalisation

- **I18N-1 (FIXED)** All user-facing strings come from Laravel lang files (EL and EN) and from the widget JSON bundles. No user-facing literal may appear in PHP, Blade, TypeScript or the WordPress plugin.
- **I18N-2** Enforced in CI by a lint rule that fails on a literal string inside a Blade `{{ }}`, a Filament label, or a widget component render, excluding an allow-list.
- **I18N-3** EL and EN lang files MUST have identical key sets. CI fails on a missing or orphaned key in either direction.
- **I18N-4 (FIXED)** Translatable model fields use `spatie/laravel-translatable`, stored as per-locale JSON columns. **No `orderBy` and no `where` may target a JSON path**; sorting and searching go through the plain indexed companion columns maintained by an observer on save, per CAT-6. A `HasTranslatableSearch` concern rebuilds the `search_index` column, and a backfill Artisan command exists for imports and for adding a locale. (per [ADR-0008](adr/0008-translatable-fields-storage.md), Option A)
- **I18N-5** Locale fallback order everywhere: requested locale, then tenant `default_locale`, then `en`.
- **I18N-6** Dates, times and numbers are formatted per locale; Greek uses 24-hour time and `DD/MM/YYYY`.
- **I18N-7** Greek text sorting and searching is accent-insensitive and handles final sigma; the implementation is a shared helper used identically by MySQL and SQLite code paths (ADR-0008).
- **I18N-8 (FIXED)** Currency is EUR only, formatted per locale.
- **I18N-9** Emails, SMS, PDFs (ticket, manifest, ναυλοσύμφωνο, invoice) and all four token pages exist and are tested in both locales.

### 12.2 Security

- **SEC-1** Every query on a tenant-owned model is tenant-scoped. A per-model isolation test asserts that a request in tenant A can never read or write a row belonging to tenant B; this test set is a required CI check.
- **SEC-2** No route resolves a resource by database id. Public identifiers are UUIDs or tokens (CNV-8).
- **SEC-3** Filament policies exist for every resource and enforce the role matrix in TEN-8.
- **SEC-4** Tenant resolution never falls back to a default tenant (TEN-4).
- **SEC-5 (FIXED)** Publishable keys cannot perform privileged writes; secret keys can never be exposed through the widget or the WordPress plugin. Capability matrix (per [ADR-0013](adr/0013-api-key-model.md), Option A):

| | `pk_` (publishable) | `sk_` (secret) |
|---|---|---|
| Read branding, products, availability | yes | yes |
| Request a price quote | yes | yes |
| Create an enquiry | yes | yes |
| Create a **draft** booking and its checkout session | yes, **only** for a booking it created | yes |
| Read a booking | **only** via that booking's own token | yes |
| List bookings, read guest personal data | **never** | yes |
| Catalogue writes, exports, webhook configuration | **never** | yes |
| Read financials | **never** | yes |

  1. **Type is the ceiling; scopes only narrow it.** Scopes are an explicit dot-form array on the key (`products.read`, `availability.read`, `branding.read`, `bookings.write`, `quotes.write`, `webhooks.receive`). A `pk_` may hold only `*.read` scopes plus `bookings.write` (draft creation). Granting a wider scope to a `pk_` is rejected by a validation rule at save and asserted by a test; middleware checks the scope per route in addition to the type.
  2. **Abuse of a leaked `pk_` is bounded by design, not by secrecy** — prices are computed server-side (PRC-1), holds expire (AVL-36), rate limits apply per key and per IP (SEC-6), no personal data is readable, and per-key `allowed_origins` allow-listing plus a "rotate publishable key" action are available.
  3. **An `sk_` request carrying an `Origin` header is rejected outright**, because browsers always send one cross-origin. Accidental front-end use therefore fails loudly instead of quietly working.
  4. Keys are stored hashed; the plaintext is shown once (TEN-3). SEC-9's CI grep for `sk_` covers both the built widget bundle and the WordPress plugin.
  5. Short-lived per-session tokens (ADR-0013 Option C) were considered and rejected for MVP; revisit only if scripted abuse becomes real.
- **SEC-6 (FIXED)** Rate limiting on the public API. Defaults: 120 requests per minute per publishable key and IP for reads; 20 per minute for booking writes; 10 per minute for enquiry submission; 30 per minute per IP on token pages; 5 failed token lookups per minute before a temporary block.
- **SEC-7 (FIXED)** Webhooks are HMAC-signed inbound and outbound, with timestamp checks and replay rejection.
- **SEC-8 (FIXED)** All operator credentials (gateway, AADE, SMS) are encrypted at rest.
- **SEC-9** No secret ever reaches a browser. A CI check greps the built widget bundle and the WordPress plugin for `sk_`, AADE key patterns and gateway secret patterns.
- **SEC-10 (FIXED)** Hosted pages carry a Content-Security-Policy (HOS-8). The Filament panels carry CSP, `X-Content-Type-Options`, `Referrer-Policy` and `Strict-Transport-Security`.
- **SEC-11** Personal data handling per §10.4; document numbers encrypted and excluded from logs, exports and error reporting.
- **SEC-12 (FIXED)** `composer audit` and `npm audit` run in CI. A high or critical advisory fails the build; moderate advisories open an issue.
- **SEC-13** All uploads are validated by content type and magic bytes, stored outside the web root, and served through a signed URL. SVG is sanitised (BRD-7).
- **SEC-14** File and PDF generation with Browsershot runs with a locked-down Chromium (no local file access, no arbitrary URL navigation) because templates can contain operator-supplied text.
- **SEC-15** Passwords use the framework defaults; operator accounts support two-factor authentication; super-admin accounts require it.
- **SEC-16** Every destructive operator action (delete vessel, delete product, cancel departure, refund, purge) is confirmed and audit-logged with actor, timestamp and reason where applicable.

### 12.3 Performance

- **NFR-1 (FIXED)** The availability endpoint responds in under **150 ms at p95**. Measurement: a CI performance test on MySQL 8 with a seeded dataset of one tenant, 10 vessels, 5 products, 400 days of departures (roughly 7,000 rows), 20,000 bookings and 2,000 vessel blocks; 200 sequential requests for a 14-day window; server-side timing only, excluding network and TLS; warm opcache, cold application cache. **This benchmark runs first at the close of M2, not at M8** (AVL-12b, per [ADR-0023](adr/0023-unified-bookable-windows.md), Option C), and thereafter nightly (ENV-25). Missing the 150 ms p95 at the M2 close is the documented trigger to reopen ADR-0023.
- **NFR-2 (FIXED)** Widget first paint in under **1 s on 3G**. Measurement: Playwright with network throttling at 400 kbps down, 400 ms RTT and 4x CPU throttle, measuring from script tag evaluation to the first meaningful widget render inside the Shadow root, median of 5 runs.
- **NFR-3 (FIXED)** Widget bundle 80 KB gzipped or less, enforced in CI (WGT-2).
- **NFR-4** Product list and hosted landing page render server-side in under 300 ms p95 with 40 products.
- **NFR-5** The Filament dashboard loads in under 1.5 s p95 for a tenant with 20,000 bookings.
- **NFR-6** No endpoint may issue an unbounded number of queries. An N+1 detector runs in the test suite and fails on regression.
- **NFR-7** Availability queries for a 14-day window use at most 5 database queries regardless of the number of departures returned.

### 12.4 Reliability

- **NFR-8 (FIXED)** All external calls are queued, idempotent and retried, and failures are visible in the operator panel with human-readable Greek messages (CNV-10, OPS-21).
- **NFR-9** Queue latency: jobs on the default queue start within 5 seconds at p95; webhook processing completes within 30 seconds at p95. Measured by Horizon metrics in production.
- **NFR-10** No job may run longer than 120 seconds; longer work is chunked. Exports and imports are chunked by design.
- **NFR-11** Every scheduled job is idempotent and safe to run twice; every scheduled job records its last successful run, and a missing run for more than twice its interval raises an alert.
- **NFR-12** Nightly encrypted database backups to object storage, with a documented and rehearsed restore drill (M8). Restore target: under 2 hours to a working system.
- **NFR-13** Deploys are zero-downtime: build image, run migrations, swap. Migrations must be backwards-compatible with the previous release for the duration of the swap.
- **NFR-14** Availability target for the public API and hosted pages: 99.5% monthly, measured by an external uptime check. This is an internal target for MVP, not a contractual SLA.

### 12.5 Observability

- **OBS-1 (FIXED)** Sentry with a tenant tag on every event, Laravel Pulse, and structured logs.
- **OBS-2** Every log line carries `tenant_id`, `request_id` and, where relevant, `booking_reference`. Logs are JSON in production.
- **OBS-3** Secrets and personal data never appear in logs or Sentry payloads; a `before_send` scrubber enforces this and is unit-tested.
- **OBS-4** Horizon dashboard is exposed to super-admins only.
- **OBS-5** Business events worth alerting on: overselling detected by the integrity check, invoice failures above a threshold per hour, gateway webhook signature failures, iCal sync failures, and the departure generation job failing.

### 12.6 Accessibility

- **A11Y-1 (FIXED)** The widget meets WCAG 2.1 AA. Measured by axe-core in Playwright with zero critical or serious violations on every mount, plus a manual keyboard pass documented per milestone.
- **A11Y-2** All interactive elements are reachable and operable by keyboard, with a visible focus indicator that survives the operator brand colours.
- **A11Y-3** Form errors are announced to assistive technology and are associated with their inputs.
- **A11Y-4** Colour is never the only carrier of meaning (availability, guaranteed status, errors).
- **A11Y-5** The Filament panels use Filament defaults; no custom component may regress below them.
- **A11Y-6** Hosted pages meet the same AA bar as the widget.

### 12.7 Testing gates

- **TST-1 (FIXED)** Unit and feature coverage of at least **80% on `app/Domain`**. Enforced in CI; the gate applies to `app/Domain` specifically, not to the whole application.
- **TST-2 (FIXED)** A concurrency test proves two parallel confirmations cannot oversell (AVL-44). Environment split (per [ADR-0006](adr/0006-overselling-concurrency-strategy.md), Option A): the true-parallelism assertion is tagged `@group mysql` and runs **only in CI against MySQL 8**, where it is a required status check on every pull request — not only on `main`. Locally it skips with an explicit "requires MySQL" message; a silent pass is a defect. Everything deterministic around it — capacity arithmetic, held versus sold seats, age bands that do not count, expiry, the conditional-update guard returning `CAPACITY_EXCEEDED` — runs locally on SQLite in the `fast` group. The same MySQL-only file covers the voucher double-spend race (PRC-20) and invoice-number allocation (MYD-4), which are the same class of bug. CI fails if the `mysql` group reports zero executed tests (TST-8).
- **TST-3 (FIXED)** Playwright happy-path coverage for each widget mount and for the WordPress plugin.
- **TST-4 (FIXED)** Snapshot tests for PDFs and emails in EL and EN.
- **TST-5** The availability and pricing engine has table-driven tests covering, at minimum: turnaround buffer overlap at the boundary and one minute either side; a midnight-crossing private charter; both DST transition days in Europe/Athens; season priority ties; age bands that do not count toward capacity; a voucher exceeding the total; deposit rounding at fractional cents; and the policy snapshot differing from the current policy.
- **TST-6** Tenant isolation tests per tenant-owned model (SEC-1).
- **TST-7** Every bug fix ships with a regression test.
- **TST-8** Pest groups: `fast` (default local), `mysql`, `chromium`, `external`. CI runs all groups and fails if any group reports zero executed tests.
- **TST-9** Tests are deterministic: time is frozen with `Carbon::setTestNow` and Playwright `page.clock`; gateways use test-mode fixtures and `Http::fake`; no test depends on the current date, the current locale of the machine, or network access.
- **TST-10** PHPStan level 6 with Larastan and Pint pass with zero errors on every commit (ARC-16).
---

## 13. Development, CI and production environments

This section **overrides §14 M0.2, §17 and the Commands block of §18 of `docs/BRIEF.md`**. The overrides are recorded in [ADR-0014](adr/0014-php-version-target.md) and [ADR-0015](adr/0015-local-development-stack.md). No agent may "restore" the Docker-based local environment or a Makefile.

### 13.1 The three-way split

- **ENV-1** There are exactly three environments, and they are deliberately not identical:

| Concern | Local (development machine) | CI (GitHub Actions) | Production (Hetzner) |
|---|---|---|---|
| OS / shell | Windows 11, PowerShell | Ubuntu, bash | Linux, Docker Compose |
| PHP | **8.4** native (8.4.24 on the dev machine) | **8.4** — single-version matrix, no 8.3 job | **8.4** — `php:8.4-fpm` image |
| Node / npm | Node 24, npm 12 | Node 24 | build-time only |
| Database | **SQLite** at `database/database.sqlite` | **MySQL 8** service container | **MySQL 8** container |
| Cache and locks | `database` store | `database` and `redis` | `redis` |
| Queue | `database` driver, `queue:work` | `database` and `redis` | `redis` with Horizon |
| Scheduler | `schedule:work` when needed | not run | supervised container |
| Mail | `log` driver | `array` driver | Postmark |
| PDFs | local Chrome, opt-in group | Chromium in the runner | chromium container |
| Web server | `php artisan serve` | none | Caddy plus php-fpm |
| Reverse proxy / TLS | none | none | Caddy, on-demand TLS, Cloudflare in front |
| Task runner | Composer and npm scripts | workflow steps | Compose |

- **ENV-2** There is **no Docker, no Laragon, no local MySQL and no local Redis** on the development machine, and this will not change. Anything that genuinely requires MySQL or Redis runs in CI only.
- **ENV-3** There is **no `make`, no bash script and no `wp-env`**. All developer entry points are `composer` and `npm` scripts so they run identically in PowerShell and in CI.
- **ENV-4** Docker Compose, the Caddyfile and the chromium image live in `docker/` and are **production artefacts only**. They are never invoked locally.
- **ENV-5** The repository starts empty. The first implementation issue scaffolds Laravel 12 from scratch with Pint, PHPStan level 6 (Larastan), Pest and the CI workflow. There is no starter repository, contrary to §3 and §17 of the brief (ARC-18).

### 13.2 Parity rules
These rules exist because local and production databases differ. They are review blockers, not suggestions.

- **ENV-6** No engine-specific SQL outside a single guarded helper. Migrations use the Laravel schema builder only.
- **ENV-7** No direct `Redis::` calls in domain code. Locks go through `Cache::lock`, queues through the queue abstraction (ADR-0005).
- **ENV-8** No ordering or filtering on a JSON path (ADR-0008). Sortable and searchable text lives in plain indexed columns.
- **ENV-9** `SELECT ... FOR UPDATE` is used through one helper so its SQLite behaviour is explicit and testable (ADR-0006).
- **ENV-10** Migrations MUST run green from scratch on both SQLite and MySQL 8 in CI. A nightly CI job migrates from zero on MySQL and compares a schema dump against a committed snapshot; a difference fails the build.
- **ENV-11** Every test that requires MySQL, Redis, Chromium or a network service is in a named Pest group and prints a clear skip reason locally. CI fails if any such group reports zero executed tests (TST-8).
- **ENV-12** No code may branch on the database driver to change business behaviour. Driver-dependent code is limited to the helpers named in ENV-6, ENV-7 and ENV-9.
- **ENV-13** Seeders and factories are deterministic and locale-independent, and produce Greek and English content so that both locales are exercised by default.
- **ENV-14** Timezone bugs are prevented by running the whole test suite with the application timezone fixed to UTC and the test tenant timezone set to `Europe/Athens`, and by a CI job that runs the availability group with the machine timezone set to something else entirely.

### 13.3 Developer entry points

- **ENV-15** Composer scripts, which are the only supported way to run anything:
  | Script | Does |
  |---|---|
  | `composer setup` | install dependencies, copy `.env`, generate key, create the SQLite file, migrate, seed |
  | `composer dev` | run `artisan serve`, `queue:work` and the Vite dev server together |
  | `composer test` | Pest, excluding the `mysql`, `chromium` and `external` groups |
  | `composer test:fast` | the `fast` group only, used by the Stop hook |
  | `composer test:mysql` | the `mysql` group, for anyone who has a MySQL instance available |
  | `composer lint` | Pint in fix mode |
  | `composer lint:test` | Pint in check mode (CI) |
  | `composer analyse` | PHPStan level 6 with Larastan |
  | `composer ci` | lint check, analyse, test, in that order |
- **ENV-16** npm scripts: `npm run widget:dev`, `npm run widget:build`, `npm run widget:size`, `npm run e2e`, `npm run e2e:wp`, `npm run plugin:lint`, `npm run mjml:build`.
- **ENV-17** `.env.example` defaults to the local stack: `DB_CONNECTION=sqlite`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `MAIL_MAILER=log`, `APP_TIMEZONE=UTC`, plus placeholders for `KAIKI_WP_TEST_URL` and gateway sandbox credentials.
- **ENV-18** `database/database.sqlite` is gitignored; `composer setup` creates it if missing.
- **ENV-19** Horizon is installed but only enabled when the queue connection is Redis; the panel route is registered conditionally so a local run never 500s on a missing Redis.
- **ENV-20** Browsershot requires Chromium. Locally it points at an installed Chrome through an `.env` path; PDF tests are in the `chromium` group and are skipped when the path is absent. CI always runs them.
- **ENV-21** MJML compilation is a build step (`npm run mjml:build`); the compiled email HTML is committed so production never needs Node at runtime.
- **ENV-22** Coverage (TST-1) requires a coverage driver; CI installs PCOV. Locally, `composer test` runs without coverage and the coverage gate is CI-only.

### 13.4 CI pipeline

- **ENV-23** Required checks on every pull request: Pint check, PHPStan level 6, Pest on SQLite, Pest on MySQL 8 plus Redis including the `mysql` group, coverage gate on `app/Domain`, widget build plus size gate, widget Playwright smoke, WordPress plugin phpcs, `composer audit`, `npm audit`, tenant isolation group, and the migration-from-zero job.
- **ENV-24** The overselling concurrency test (AVL-44) is a required check and a red result blocks merge.
- **ENV-25** Nightly: full Playwright suite including the WordPress site run, migration schema-drift check, dependency audit, and the availability performance test (NFR-1).
- **ENV-26** Deployment to Hetzner is a manual-approval workflow on `main`: build image, push, run migrations, swap containers, run smoke checks, and roll back automatically on smoke failure.
- **ENV-27** Secrets live only in GitHub Actions secrets and in the production environment. `.env` is never committed.
- **ENV-28** `docs/api.md` is regenerated by Scramble in CI; a difference between the generated output and the committed file fails the build, so the API contract can never drift silently.

---

## 14. Repository layout and milestones

### 14.1 Layout

- **MIL-0 (FIXED)** The monorepo layout of §13 of the brief is adopted verbatim, with one amendment: `docker/` is production-only (ENV-4) and there is no `Makefile`.

```
kaiki/
├─ CLAUDE.md
├─ CHANGELOG.md
├─ docs/            BRIEF.md · spec.md · data-model.md · api.md · adr/ · guides/ · compliance/
├─ app/             Laravel core (Domain/, Filament/, Http/, Models/, Jobs/, Events/, Listeners/)
├─ database/
├─ resources/       views (hosted pages, PDFs, emails), lang/el, lang/en
├─ packages/
│  ├─ widget/           Preact + TS + Vite → public/widget/...
│  └─ wordpress-plugin/ kaiki-booking/ (+ Playwright)
├─ tests/           Pest · Playwright (e2e/)
├─ docker/          production Compose, Caddyfile, chromium
├─ .github/workflows/
└─ .claude/         agents/ · commands/ · agent-memory/
```

### 14.2 Milestones
Milestone content is fixed by §14 of the brief. The amendments below are forced by §13 of this document. Detailed GitHub issues are generated per milestone by the architect.

- **MIL-1 (FIXED)** **M0 Foundation** — tenancy mode and `BelongsToTenant`, tenant resolution, CI, Filament panels and roles, ApiKeys resource, OpenAPI skeleton with Scramble.
  **Amendments:** M0.1 also scaffolds Laravel 12 from scratch (ARC-18); M0.2 becomes "production Docker Compose and Caddyfile, `.env.example` for the local SQLite stack, Composer and npm scripts instead of a Makefile" (ENV-3, ENV-4). Blocked by ADR-0001, ADR-0013, ADR-0014, ADR-0015; ADR-0010 is needed for the resolution middleware.
- **MIL-2 (FIXED)** **M1 Catalogue and availability engine** — vessels, ports, brand profile; products with modes, age bands, translations; seasons, rate plans, extras, cancellation policies; schedule rules and departure generation; vessel blocks and the availability service with §5.1–5.3 including buffers, DST and midnight edge cases; public read API. Blocked by ADR-0002 (schema only), ADR-0008, ADR-0009, ADR-0016.
- **MIL-3 (FIXED)** **M2 Booking and payments** — booking aggregate with draft, hold and expiry plus the concurrency test; pricing snapshot and vouchers; payment gateway contract, Viva, webhooks, deposit and balance; quote and enquiry; the four token pages; notifications; e-ticket PDF and check-in. Blocked by ADR-0003 (form fields), ADR-0004, ADR-0005, ADR-0006, ADR-0007, ADR-0012 (schema), ADR-0017, ADR-0018, ADR-0019.
- **MIL-4 (FIXED)** **M3 Widget and hosted pages** — widget shell and mounts, hosted pages, custom domains and TLS, branding admin, Playwright end-to-end booking. Blocked by ADR-0010, ADR-0011.
- **MIL-5 (FIXED)** **M4 WordPress plugin** — skeleton, settings, API client, shortcodes, blocks, Elementor widgets, SEO CPT sync, tests and release. **Amendment:** tests run against a WordPress URL from `.env`, not `wp-env` (WPP-15).
- **MIL-6 (FIXED)** **M5 Operations** — dashboard and vessel calendar, manual bookings, weather cancellation workflow, manifest exports, iCal, outbound webhooks and CSV exports.
- **MIL-7 (FIXED)** **M6 Greek compliance** — ναυλοσύμφωνο, myDATA client with ΑΛΠ/ΤΠΥ issuance, retries, cancellation invoices, the Greek error dictionary, invoice PDF with QR, and GDPR purge and data-subject tooling. Blocked by ADR-0002, ADR-0003, ADR-0012.
- **MIL-8 (FIXED)** **M7 SaaS** — subscription plans, trial, dunning, read-only mode (**unblocked 2026-09-08: Viva Wallet takes the subscriptions too, ADR-0028 as amended**); super-admin panel with impersonation and flags; onboarding wizard and sandbox mode; WooCommerce/YITH importer; docs site. Docs-site tooling needs its own ADR first.
- **MIL-9 (FIXED)** **M8 Launch hardening** — load test, security review, Sentry and Pulse, backups and a restore drill, status page, and legal pages (ToS, DPA, privacy) in EL and EN.
- **MIL-10** No milestone closes until: all its acceptance criteria pass, `security-reviewer` has run, coverage on `app/Domain` is at or above 80%, `docs/spec.md`, `docs/data-model.md` and `docs/api.md` reflect any contract change, and `CHANGELOG.md` has an entry.

---

## 15. Glossary (EL / EN)

Terms are used in this exact form throughout the codebase, the operator guides and the panel. The Greek term is authoritative for operator-facing copy; the English is used in code identifiers.

| Greek | English | Meaning in Kaiki |
|---|---|---|
| **ναυλοσύμφωνο** | charter agreement / charter party | The contract between operator and charterer for a private charter. Generated as a versioned PDF for every `per_vessel` booking, accepted by the guest with a checkbox capturing timestamp and IP, emailed to both parties. Entity: `CharterAgreement`. |
| **δήλωση επιβατών** | passenger declaration / **manifest** | The list of everyone on board with the details the port authority requires. Generated per departure or per private booking as CSV and PDF, plus a print-for-the-harbour layout. Entity: `ManifestExport`. |
| **ΑΛΠ** (Απόδειξη Λιανικής Πώλησης) | retail sales receipt | The default myDATA document type for a retail guest with no tax number. Requires no customer ΑΦΜ. `Invoice.type = ΑΛΠ`. |
| **ΤΠΥ** (Τιμολόγιο Παροχής Υπηρεσιών) | services invoice | The myDATA document type issued when the customer is a business and supplies an ΑΦΜ. `Invoice.type = ΤΠΥ`. |
| **mark** (ΜΑΡΚ) | myDATA unique registration number | The unique identifier AADE returns when a document is accepted. An invoice is only truly issued once it has a `mark`. Stored on `Invoice.mark`. |
| **ΑΦΜ** (Αριθμός Φορολογικού Μητρώου) | tax identification number / VAT number | Nine digits with a modulus-11 checksum. Held for the operator (`Tenant.afm`) and optionally for a business customer, where it triggers ΤΠΥ instead of ΑΛΠ. |
| **ΔΟΥ** (Δημόσια Οικονομική Υπηρεσία) | tax office | The local tax office an ΑΦΜ is registered with. Printed on invoices and on the ναυλοσύμφωνο. `Tenant.doy`. |
| **Λιμεναρχείο** | port authority / harbour master | The authority that receives the passenger manifest before departure. The reason document numbers are collected in the guest-details flow. |
| **καΐκι** | kaiki (traditional Greek wooden boat) | A traditional wooden fishing or passenger boat. One of the `Vessel.type` values (`traditional_kaiki`) and the working codename of the product. |
| **αναχώρηση** | departure | A single scheduled sailing of a `per_seat` product on a specific date and time, with capacity and sold seats. Entity: `Departure`. Not to be confused with the booking. |
| **ναύλωση** | charter | Hiring the whole vessel rather than buying seats on it. Corresponds to `Product.mode = per_vessel` and produces a `VesselBlock` plus a ναυλοσύμφωνο. |

Supporting terms used in the same documents:

| Greek | English | Meaning |
|---|---|---|
| θέση | seat | One unit of capacity on a `per_seat` departure, consumed only by age bands where `counts_toward_capacity` is true. |
| σκάφος | vessel | The bookable resource. Entity: `Vessel`. |
| σημείο συνάντησης | meeting point | Where guests meet before departure. Entity: `MeetingPoint`. |
| προκαταβολή | deposit | The part of the total paid at booking time. `Booking.deposit_cents`. |
| υπόλοιπο | balance | The remainder due before departure. `Booking.balance_cents`. |
| κουπόνι | voucher | Credit issued instead of a cash refund, with an expiry. Entity: `Voucher`. |
| ακύρωση | cancellation | Ending a booking or a departure, with refund consequences from the policy snapshot. |
| επιστροφή χρημάτων | refund | A cash return through the original gateway. `Payment.kind = refund`. |
| ηλικιακή κατηγορία | age band | A priced passenger category with an age range. Entity: `AgeBand`. |
| πολιτική ακύρωσης | cancellation policy | Tiered refund percentages by days before departure. Entity: `CancellationPolicy`. |
| επιβίβαση / check-in | check-in | Marking a guest present, usually by scanning the ticket QR code. |
| διαθεσιμότητα | availability | Whether a product can be booked for a given date and party (§5). |

---

## 16. Open decisions and interpretation register

### 16.1 Decision register — all ADRs accepted
**All 23 ADRs in [`docs/adr/`](adr/README.md) were accepted by the product owner on 2026-08-28.** Nothing in this section blocks implementation any more. The table records which ADR each requirement descends from, so a future change knows what it is reopening; the accepted option per ADR is in [`docs/adr/README.md`](adr/README.md).

| ADR | Subject | Accepted | Requirements it now defines | Milestone |
|---|---|---|---|---|
| 0001 | Tenancy mode | A | ARC-3, TEN-1 to TEN-9, SEC-1 | M0 |
| 0002 | VAT rate storage and resolution | A | CAT-11, CAT-11a, CAT-11b, PRC-14, MYD-6, MYD-6a | M1 schema, M6 |
| 0003 | Invoice type and issuance timing | A | MYD-2, MYD-3, BKG-13.4 | M2 forms, M6 |
| 0004 | Gateway credentials and deposit model | A + D | PAY-4, PAY-8, TOK-6 | M2 |
| 0005 | Seat-hold mechanism | A | AVL-37, AVL-38 | M2 |
| 0006 | Overselling concurrency | A | AVL-43, AVL-45, TST-2, ENV-9 | M2 |
| 0007 | Booking reference format | A | BKG-3, BKG-4 | M2 |
| 0008 | Translatable field storage | A | CAT-6, I18N-4, I18N-7, ENV-8 | M1 |
| 0009 | Departure generation horizon | A | AVL-53, AVL-54, AVL-55 | M1 |
| 0010 | Custom domains and TLS | A | HOS-3, TEN-4, SEC-4 | M0 middleware, M3 |
| 0011 | Widget distribution and versioning | A | WGT-4 | M3 |
| 0012 | Guest document encryption and retention | A | GDR-2, GDR-3, GDR-4 | M2 schema, M6 |
| 0013 | API key model | A | ARC-6, TEN-3, SEC-5, WPP-3 | M0 |
| 0014 | PHP version target | **B** | ARC-2, ENV-1 — **amends brief §3 and §18 from 8.3 to 8.4** | M0 |
| 0015 | Local development stack | A | ENV-1 to ENV-28 | M0 |
| 0016 | DST-invalid and ambiguous times | A | AVL-18 | M1 |
| 0017 | Voucher remainder and restoration | A + D | PRC-18, PRC-19 | M2 |
| 0018 | Balance due policy | A | PRC-27 | M2 |
| 0019 | Packages beyond §3 | A | ARC-21, ARC-21a, ARC-21b, ARC-21c | M2 onward |
| 0020 | Multi-tenant user membership | **C** | TEN-2 — `users.tenant_id`, globally unique email, separate `role_assignments` table, no tenant switcher in M0–M7 | M0 |
| 0021 | Image and file storage | A | BRD-1, BRD-4, BRD-7 — plain path columns plus a `StoreUploadedImage` Action and `intervention/image`; **no polymorphic media table** | M1 |
| 0022 | Invoice numbering scope | A + C | MYD-4 — **still needs accountant sign-off, see §16.3** | M6 |
| 0023 | Unified bookable windows | **C** | AVL-1 to AVL-12, AVL-30 to AVL-35 — the three shapes stay, hidden behind `App\Domain\Availability\VesselCalendar`, enforced by an architecture test | M2 |

### 16.2 Interpretations outside §5
§5 interpretations are listed in §5.11. Elsewhere, this document resolved the following ambiguities and a human may veto any of them:

| ID | Ambiguity | Resolution |
|---|---|---|
| BKG-9 | When seats become committed rather than held | At checkout redirect, not at webhook. |
| BKG-10 | Fate of an abandoned `pending_payment` booking | Expires after 60 minutes total, releasing capacity. |
| BKG-18 | Reminders landing at night | Deferred to 08:00 local; dropped if that would be after the event. |
| BKG-25 | Does a quote request hold the vessel | No; the operator may opt to hold when sending the quote, expiring with the quote. |
| BKG-32 | Can a manual booking exceed capacity | It may bypass lead time and advance limits, never legal capacity. |
| TOK-2 | Token format | 40 characters of URL-safe cryptographic randomness, per purpose. |
| TOK-10 | Guest-details page after departure | Read-only until the document purge, then summary only. |
| MYD-4 | Invoice numbering | **Superseded by ADR-0022 (A + C).** Per (tenant, series, year) with a yearly reset, allocated under a row lock at the send attempt, **gaps permitted and logged**, plus a per-tenant `invoicing_mode`. The earlier "strictly sequential with no gaps" reading no longer stands. The gap policy is still pending an accountant, per §16.3. |
| MYD-7 | VAT rounding residue | Applied to the largest line so the gross always reconciles. |
| SAA-7 | Read-only mode and existing guests | Guest-facing tokenised pages keep working. |
| NFR-1 | What "1 year of departures" means for the benchmark | The seeded dataset defined in NFR-1. |
| NFR-2 | What "3G" means for first paint | 400 kbps, 400 ms RTT, 4x CPU throttle, median of 5 runs. |

### 16.3 What is still open after the ADR round

All 23 ADRs were **accepted on 2026-08-28**, and the `DECIDE` marker no longer appears in this document (§0.4). Every architectural fork is closed. Three things remain, and none of them is an engineering decision:

**Open for a non-engineering reason — do not close these by engineering judgement.**

| # | Open item | Owner | Why it is still open | Deadline | What is *not* blocked |
|---|---|---|---|---|---|
| 1 | **The VAT rates themselves** — which percent and which AADE `vat_category` applies to passenger transport, to other tourist services, and under any reduced island-rate regime, per operator. | Operator's accountant | ADR-0002 settled only *where* the rate lives and *how* it is resolved. §10 of the brief marks the figures "DECIDE with accountant" and they have not been given. | Before myDATA activation in **M6**. | Nothing structural. The `vat_rates` table, `products.vat_rate_id`, `extras.vat_rate_id` and the per-line `price_snapshot` fields are settled and ship in **M1**. The answer is data entry, not code. See CAT-11, CAT-11a, CAT-11b, MYD-6, MYD-6a. |
| 2 | **The invoice-numbering gap policy** — whether a Greek invoicing series may contain a logged gap at all, or whether a number may only be allocated once AADE has returned a `mark`. | Operator's accountant | ADR-0022 was accepted on engineering grounds and says so explicitly: it "needs an accountant's sign-off, not just the product owner's". Whether an auditor accepts a gap is a compliance question. | Before **M6**. | The schema is settled either way: `unique(tenant_id, series, year, number)`, nullable `number`, a locked `series_counters` row and the `invoice_number_gaps` audit table. Only the allocation *instant* changes if the answer is "no gaps". See MYD-4. |

**Revisit trigger — a decision that is accepted now but has a scheduled re-examination.**

| # | Trigger | Decision at risk | When |
|---|---|---|---|
| 3 | The **NFR-1 availability benchmark** missing its 150 ms p95 on the seeded dataset. | ADR-0023 (Option C): three separate occupation tables behind the `VesselCalendar` port. Missing the threshold reopens it in favour of Option B, a single `bookable_windows` table. | At the **close of M2**, pulled forward from M8. See AVL-12a, AVL-12b, NFR-1. |

Two further items are open in [`docs/api.md`](api.md) §9 and are contract questions rather than architecture: the API host shape, idempotency-key retention, and voucher-code enumeration hardening. They are marked `**OPEN**` there with a provisional default and do not block any milestone.

---

## 17. Change control

- **ARC-22** This document changes only through a pull request that also updates any affected requirement IDs. Requirement IDs are never reused or renumbered.
- **ARC-23** A change to a **(FIXED)** requirement requires the product owner to amend `docs/BRIEF.md` first; agents may not do it.
- **ARC-24** A change to a **(RESOLVED)** interpretation requires an ADR if any code already depends on it, and a note in §5.11 or §16.2 otherwise.
- **ARC-25** Every closed issue that changes a contract updates `docs/spec.md`, `docs/data-model.md` or `docs/api.md` in the same pull request, plus a `CHANGELOG.md` entry (FIXED by §0 of the brief).
