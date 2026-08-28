# KAIKI — Booking engine for cruises, day trips & private charters
### Build brief for Claude Code

> Working codename: **Kaiki** (καΐκι). Rename globally before committing (`kaiki` → your brand).
> Positioning: *"WebHotelier for boats"* — a hosted booking engine + operator back-office that any cruise / day-trip / private-charter operator plugs into their own website (WordPress first) or uses as a hosted booking page.

---

## 0. How to use this document

1. Create an empty repo, drop this file in as `docs/BRIEF.md`.
2. Create `CLAUDE.md` from §18 and the subagents from §15 (`.claude/agents/*.md`).
3. Paste the **kickoff prompt** from §17 into Claude Code. It reads this brief, produces `docs/spec.md`, `docs/data-model.md`, `docs/api.md` (OpenAPI), and the GitHub issues for Milestone 0–1.
4. Work one issue at a time: **plan mode → review plan → accept-edits → run tests → commit → `/clear`**. Never let a session span two issues.
5. Every issue closes with: tests green, `docs/` updated if the contract changed, a one-paragraph changelog entry in `CHANGELOG.md`.

Decisions in this document marked **FIXED** are not up for debate by agents. Anything marked **DECIDE** the architect agent must propose options for and stop for a human answer.

---

## 1. Product vision

**Customers (tenants):** boat operators in Greece first — shared daily cruises, sunset trips, private full/half-day charters with crew, small fleets (1–10 vessels). Later EU.

**End users (guests):** tourists booking from the operator's own site, a hosted Kaiki page, or (later) through resellers/OTAs.

**Business model — FIXED:** monthly subscription per operator (Stripe via Laravel Cashier), 14-day trial, plans by number of vessels / features. Kaiki never touches guest money.

**Why we win:**
- Two booking modes done properly: **per-seat** (shared trips) and **per-vessel** (private charters) sharing one vessel calendar.
- **Post-booking passenger manifest** collection (like WebHotelier's guest details) that feeds the Λιμεναρχείο export.
- **Greek compliance built in:** manifest export, ναυλοσύμφωνο PDF, myDATA invoicing via the operator's own AADE credentials.
- **Weather-cancellation workflow** in one click (rebook / voucher / refund).
- **Migration from WooCommerce + YITH Booking** (what most Greek operators run today).
- White-label widget: operator branding (logo, colours, fonts) so it looks native on their site.

---

## 2. Scope

### MVP — in
- Multi-tenant operator back-office (Filament v3 panel).
- Vessels, products (shared / private / quote), seasons & pricing, extras, age bands, cancellation policies.
- Availability engine with vessel-level conflict resolution.
- Guest booking flow: embeddable JS widget + hosted booking page. Instant book, request-a-quote, ask-a-question.
- Payments through **operator's own** Viva Wallet (Smart Checkout) or Stripe (Checkout). Full or deposit; balance links.
- Post-booking guest details link, manifest export (CSV/PDF), QR e-tickets, check-in.
- Notifications: email (EL/EN), SMS (provider-pluggable; Apifon/Yuboto for GR, Twilio fallback), reminders.
- Weather / operator cancellations with refund, voucher, or rebook. Vouchers with expiry.
- iCal export per vessel; iCal import for external blocks.
- Greek compliance: manifest, ναυλοσύμφωνο PDF, myDATA (ΑΛΠ / ΤΠΥ) issuance and retry.
- Branding per operator: logo, primary/secondary/accent colours, font choice, button radius, widget mode (light/dark/auto).
- WordPress plugin: settings + shortcodes + Gutenberg block + Elementor widget + trip grid listing + SEO landing pages (optional SSR) + WPML locale pass-through. Coexists with WooCommerce.
- Super-admin panel, subscriptions, onboarding wizard, WooCommerce/YITH importer.
- i18n: EL + EN everywhere, translation-ready for more. Currency EUR only. Timezone per operator (default Europe/Athens).

### MVP — out (design so they can be added, do not build)
- Reseller/agent portal, net rates, commissions.
- OTA channel manager (Viator, GetYourGuide, Bókun). Keep an abstract `Channel` interface with only `ical` implemented.
- Bareboat rental, multi-day charters, damage deposits.
- Marketplace / aggregator site.
- Platform-collected payments (Stripe Connect).
- Mobile apps (the back-office must be fully usable on a phone in the browser instead).
- Reviews, waivers/e-signature, gift cards purchased by guests.
- Multi-currency.

---

## 3. Architecture & stack — FIXED

| Layer | Choice | Notes |
|---|---|---|
| Core | **Laravel 12, PHP 8.3**, MySQL 8, Redis, Horizon queues, Scheduler | Reuse the existing Laravel + `stancl/tenancy` starter repo (scaffold + CI already done). |
| Tenancy | `stancl/tenancy` **single-database** mode, `tenant_id` on every tenant-owned table, global scope + `BelongsToTenant` trait | Single DB keeps cross-tenant reporting, super-admin, and hosted pages simple. **DECIDE**: confirm vs the starter's current DB-per-tenant setup before M0. |
| Back-office | **Filament v3** (operator panel at `/app`, super-admin at `/admin`) | Fastest path for a two-person team; mobile-usable out of the box. |
| Public API | REST, versioned `/api/v1`, OpenAPI 3.1 spec kept in `docs/api.md` and generated with `dedoc/scramble` | Widget + WP plugin + future clients all speak this API. Public endpoints authenticate with a **publishable key**; write endpoints with a **secret key**; back-office with Sanctum sessions. |
| Widget | **Preact + TypeScript**, built with Vite into a single IIFE (`kaiki-widget.js`), rendered in **Shadow DOM** so host CSS never leaks | Loaded via `<script src=".../kaiki-widget.js" data-key="pk_…">`. Branding injected as CSS custom properties from the API. |
| Hosted pages | Blade + the same widget at `book.{platform-domain}/{operator-slug}` and optional custom domain (CNAME) | Operator can use this with no website at all. |
| WordPress plugin | PHP 8.1+, WP 6.4+, no framework, PSR-4 via Composer autoload, single `kaiki-booking/` folder | Thin client. Talks only to the public API. Ships in `packages/wordpress-plugin`. |
| PDFs | `spatie/browsershot` (Chromium) for ναυλοσύμφωνο, tickets, manifest PDF; Blade templates | dompdf is unacceptable for Greek fonts + layout. |
| Email | Postmark (transactional) via Laravel Mail; per-operator "from" name and reply-to | Templates in Blade + MJML-compiled HTML, EL/EN. |
| SMS | `App\Contracts\SmsGateway` with `ApifonGateway`, `TwilioGateway`, `NullGateway` | Operator chooses; platform-level fallback. |
| Payments | `App\Contracts\PaymentGateway` with `VivaSmartCheckoutGateway`, `StripeCheckoutGateway` | Operator credentials encrypted at rest (`encrypted` cast). Webhooks verified, idempotent. |
| SaaS billing | Laravel Cashier (Stripe) on the tenant model | Platform's own Stripe account, unrelated to guest payments. |
| Infra | Hetzner VPS (Docker Compose: app, horizon, scheduler, mysql, redis, chromium), Caddy for TLS incl. on-demand certs for custom domains, Cloudflare in front | GitHub Actions CI already exists in the starter; add deploy job. |
| Testing | Pest (unit/feature), Playwright for widget + WP plugin e2e, PHPStan level 6, Pint, Larastan | CI must run all of it. |
| Monitoring | Sentry, Horizon dashboard, Laravel Pulse | |

**Conventions**
- Money is integer cents (`price_cents`), never floats. Use `brick/money` for arithmetic.
- All datetimes stored UTC, displayed in operator timezone. Departures store `local_date` + `local_time` + `starts_at_utc`.
- Domain logic lives in `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` as Actions (`spatie/laravel-data` DTOs, invokable Action classes). Controllers and Filament resources are thin.
- Events for every state change (`BookingConfirmed`, `DepartureCancelled`, …); listeners are queued.
- Feature flags via `laravel-pennant` for anything marked "later".

---

## 4. Domain model

Every tenant-owned table has `tenant_id`, `uuid` (public identifier), timestamps, soft deletes where noted.

### Tenancy & users
- **Tenant (Operator)** — name, slug, legal name, ΑΦΜ, ΔΟΥ, address, timezone, default locale, currency (EUR), plan, trial_ends_at, custom_domain, hosted_page_enabled.
- **User** — belongs to tenant; roles: `owner`, `manager`, `crew` (crew sees today's departures + check-in only). Super-admins are platform users with no tenant.
- **ApiKey** — publishable (`pk_`) and secret (`sk_`) per tenant, scoped, revocable, last_used_at.

### Branding
- **BrandProfile** (1 per tenant) — logo (light/dark), favicon, `primary`, `secondary`, `accent`, `background`, `text` colours, font family (curated list + Google Fonts name), button radius, widget theme (light/dark/auto), email header image, email footer text, social links, custom CSS (sanitised, hosted-page only).

### Catalog
- **Vessel** — name, type (catamaran / sailing yacht / motor / RIB / traditional καΐκι), length, capacity_max (legal), crew, home port, images, specs, description (translatable), status.
- **Port / MeetingPoint** — name, address, lat/lng, instructions (translatable), photo.
- **Product** (translatable fields) — vessel_id, category (shared_full_day / shared_half_day / private_full_day / private_half_day / sunset / custom), **mode**: `per_seat` | `per_vessel` | `quote`, title, summary, description, duration_minutes, default_start_time, flexible_start (bool, per_vessel only), check_in_offset_minutes, meeting_point_id, includes[], excludes[], what_to_bring[], route/itinerary stops[] (name, description, lat/lng optional), route_map_image, min_pax (guaranteed-departure threshold, per_seat), max_pax (≤ vessel capacity), age_bands, cancellation_policy_id, guest_details_required (bool) + guest_details_deadline_hours, status, sort order, SEO fields.
- **AgeBand** (per product) — label, min_age, max_age, counts_toward_capacity, price_multiplier or fixed price.
- **Season** — name, date ranges (multiple), priority.
- **RatePlan** — product_id, season_id (nullable = default), for per_seat: price per age band; for per_vessel: price per booking, optional per-extra-hour price; deposit_percent or deposit_fixed; min_lead_time_hours, max_advance_days.
- **Extra** — product-scoped or tenant-wide; pricing_type: `per_booking` | `per_person` | `on_request` (no price, flagged "upon request"); max_qty; description; image.
- **CancellationPolicy** — name, tiers[] `{days_before: 15, refund_percent: 50}`, weather_refund_percent (100), force_majeure_voucher_months (18), free_cancellation_hours.
- **ScheduleRule** (per_seat products) — weekday mask, start_time, valid_from/to, capacity override, auto-generates Departures N days ahead (scheduler). Manual one-off departures allowed.

### Availability
- **Departure** (per_seat) — product_id, vessel_id, local_date, start_time, capacity, seats_sold (denormalised), status: `scheduled` | `guaranteed` | `cancelled` | `completed`, cancel_reason (weather / operator / min_pax), notes.
- **VesselBlock** — vessel_id, date range (+ optional time range), reason: `private_booking` | `maintenance` | `external_ical` | `manual`, source ref.
- Rule: a `per_vessel` booking creates a VesselBlock; a VesselBlock overlapping a Departure marks that Departure unavailable (and blocks new seat sales, alerts operator if seats already sold). A Departure with seats sold blocks per_vessel bookings for that vessel/time window. See §5.

### Bookings & guests
- **Booking** — reference (human, e.g. `KAI-7F3K2`), product_id, vessel_id, departure_id (per_seat) or date+start_time+end_time (per_vessel), mode, status: `draft` | `pending_payment` | `quote_requested` | `quote_sent` | `confirmed` | `checked_in` | `completed` | `cancelled` | `refunded` | `expired`, source: `widget` | `hosted` | `wordpress` | `manual` | `import`, locale, lead guest (name, email, phone, nationality), pax breakdown JSON (by age band), extras JSON, subtotal/discount/total/deposit/paid/balance cents, voucher_id, policy snapshot JSON, price snapshot JSON, guest_details_status: `not_required` | `pending` | `complete`, guest_details_token, manage_token, special_requests, internal notes, utm fields, ip/user-agent.
- **BookingGuest** — booking_id, full name, date of birth, nationality, document type + number (encrypted), age band, seat/ticket QR code, checked_in_at.
- **Quote** — booking_id, line items, valid_until, message, pay link; state sent/accepted/declined/expired.
- **Enquiry** — product_id nullable, name, email, phone, preferred date, pax, message, status. ("Ask a question".)
- **Voucher** — tenant_id, code, amount_cents, remaining_cents, expires_at, issued_for_booking_id, reason, redeemed in bookings[].
- **Payment** — booking_id, gateway, kind: `full` | `deposit` | `balance` | `refund`, amount_cents, gateway_ref, status, raw payload, idempotency key.

### Compliance
- **Invoice** — booking_id, type: `ΑΛΠ` (retail receipt) | `ΤΠΥ` (services invoice, needs customer ΑΦΜ), series, number, issued_at, myDATA `mark`, `uid`, `qr_url`, status: `pending` | `sent` | `failed` | `cancelled`, last_error, retries. Auto-issue on confirmation (setting) or manual.
- **CharterAgreement** (ναυλοσύμφωνο) — booking_id, template_version, filled fields snapshot, PDF path, sent_at, guest_accepted_at (checkbox acceptance in the guest-details flow, IP + timestamp).
- **ManifestExport** — departure_id or booking_id, format, generated_at, file path, generated_by.

### Ops & integrations
- **Notification** log — booking_id, channel, template, locale, to, status, provider ref.
- **IcalFeed** — vessel_id, token (export URL); **IcalSource** — vessel_id, url, last_synced_at.
- **WebhookEndpoint** (outbound, tenant-configured) + **WebhookDelivery** — events `booking.confirmed`, `booking.cancelled`, `departure.cancelled`, `guest_details.completed`.
- **ImportJob** — source `woocommerce_yith`, status, mapping JSON, log.

---

## 5. Availability & pricing rules (the engine — get these right)

1. **Vessel is the resource.** Nothing is bookable without a free vessel window. A vessel window is free if no `VesselBlock` and no other-product `Departure` with `seats_sold > 0` overlaps it (turnaround buffer configurable per vessel, default 60 min).
2. **per_seat availability** for a date = Departures for that product whose vessel window is free, `status ∈ {scheduled, guaranteed}`, `capacity − seats_sold ≥ requested seats` (only age bands with `counts_toward_capacity`), and lead-time/advance rules pass.
3. **per_vessel availability** for a date = the product's default window (or the guest-proposed window if `flexible_start`) fits in a free vessel window. If a shared Departure exists on that vessel with `seats_sold = 0`, a private booking may take it and the Departure is auto-cancelled with reason `vessel_booked_privately` (operator notified). If `seats_sold > 0` → unavailable.
4. **Seat holds:** when a guest reaches payment, hold seats / block the window for 15 minutes (Redis lock + `expires_at` on the draft booking). Expired drafts release automatically.
5. **Concurrency:** confirm inside a DB transaction with `SELECT … FOR UPDATE` on the departure or vessel row. Tests must prove two simultaneous bookings cannot oversell.
6. **min_pax / guaranteed departure:** a Departure becomes `guaranteed` once seats_sold ≥ min_pax. Operator dashboard shows "at risk" departures within 48h below min_pax with one-click cancel (weather/min_pax workflow).
7. **Pricing snapshot:** price is computed server-side only, stored in `price_snapshot` on the booking. Widget never sends prices. Resolution order: RatePlan matching the highest-priority Season containing the date → default RatePlan. Age band multiplier/fixed → extras → voucher → total. Deposit computed from RatePlan.
8. **Quote mode:** no price shown; guest submits date/pax/requests → operator builds a Quote in the panel → guest gets a pay link → on payment the Booking behaves like `per_vessel`.
9. **Cancellation:** refund amount computed from the **policy snapshot** at booking time, never the current policy. Operator can override (refund %, voucher instead, or waive). Weather cancel = policy's weather_refund_percent applied to all bookings on the departure, with per-booking choice email (refund / voucher / rebook link).

---

## 6. Booking lifecycle

```
widget/hosted/WP
  → GET availability → POST /bookings (draft, hold)
  → POST /bookings/{id}/checkout → gateway redirect
  → gateway webhook → confirmed → BookingConfirmed event
      → e-ticket PDF + QR, confirmation email/SMS (EL/EN)
      → myDATA issue (if auto) → Invoice
      → guest-details email with link (if required), reminders at deadline−48h and −24h
      → ναυλοσύμφωνο generated (per_vessel), acceptance requested in guest-details flow
  → T−24h reminder: meeting point, check-in time, what to bring, weather note
  → day of: crew check-in (QR scan page in Filament, works on phone), manifest export
  → after: status completed; (later) review request
```

Guest-facing pages, all tokenised, no accounts: `/b/{manage_token}` (manage booking, download ticket, cancel per policy, pay balance), `/g/{guest_details_token}` (fill passenger details, accept ναυλοσύμφωνο), `/q/{quote_token}` (view/accept/pay quote), `/v/{voucher}` (check balance).

---

## 7. Widget, hosted pages & branding

**Widget** (`packages/widget`): Preact + TS, Shadow DOM, ~<80 KB gz. Mounts: `booking` (single product), `list` (trip grid with "from €X", category tabs), `calendar` (availability only), `enquiry`. Attributes: `data-key`, `data-product`, `data-locale`, `data-theme`, `data-category`. Emits DOM events (`kaiki:booking-confirmed`) for GTM/GA4/Meta Pixel hooks. Reads `GET /api/v1/branding` and applies CSS custom properties (`--kaiki-primary`, …). Full keyboard/a11y, WCAG 2.1 AA. Loads the operator's font via Google Fonts only if configured.

**Hosted page**: `book.{platform-domain}/{slug}` — branded landing with product list, product pages (SEO meta, JSON-LD `Product`/`Event`), the widget, operator contact, policies, legal. Custom domain via CNAME with automatic TLS.

**Branding admin** (Filament): live preview of widget + email while editing colours; contrast check warning; logo upload with auto-resize; "reset to defaults".

---

## 8. WordPress plugin (`packages/wordpress-plugin/kaiki-booking`)

- Settings page: API keys, default locale mapping (WPML/Polylang → widget locale), cache TTL, "SEO pages" toggle.
- Shortcodes: `[kaiki_booking product="uuid"]`, `[kaiki_list category="shared"]`, `[kaiki_calendar product="…"]`, `[kaiki_enquiry]`.
- Gutenberg blocks (server-rendered wrappers around shortcodes) and **Elementor widgets** with the same controls, since Greek agencies live in Elementor.
- Optional **SEO pages**: a `kaiki_trip` CPT synced from the API (cron + webhook), one page per product with server-rendered content + widget mount, so trips are indexable; permalink base configurable (`/tours/`). Template overrides in the theme (`kaiki/single-trip.php`).
- Transient caching of API reads; cache bust via inbound webhook endpoint with HMAC.
- Coexists with WooCommerce (never touches Woo cart/checkout). Styles scoped, tested against Woodmart, Astra, Hello Elementor.
- Ships as a zip via GitHub release; auto-updates via a lightweight update endpoint on the platform.
- Plugin has its own Playwright suite against a `wp-env` instance.

---

## 9. Operations features

- **Dashboard**: today/tomorrow departures with pax, at-risk departures, pending guest details, pending quotes, unpaid balances, revenue this week.
- **Calendar**: per-vessel timeline (shared departures + private blocks + external blocks), drag to create block, click departure → pax list.
- **Manual bookings** (phone/walk-in) with "mark as paid cash / bank".
- **Weather cancel**: select departure(s) → choose refund / voucher / rebook offering → preview affected guests → send.
- **Manifest**: per departure or per private booking, CSV + PDF; columns configurable but default: full name, DOB, nationality, document number, vessel, date, port, captain. Also "print for the harbour" layout.
- **Check-in**: QR scan page, manual toggle, no-show marking.
- **iCal**: export URL per vessel (blocks + departures with sold seats); import URLs polled every 15 min → VesselBlocks with reason `external_ical`.
- **Vouchers**: issue, list, redeem (widget accepts codes), expiry reminders.
- **Exports**: bookings CSV (accounting), guests CSV.
- **Outbound webhooks** for the operator's other tools.

---

## 10. Greek compliance

- **Manifest** — §9. Legal-required fields are the default; operator can add columns.
- **Ναυλοσύμφωνο** — Blade template with operator legal data, vessel, charter window, port, pax, price, terms; versioned template; generated PDF stored; guest acceptance captured (checkbox + timestamp + IP) in the guest-details flow; both parties emailed. Operator can upload their own PDF template with placeholders as v2 (**flag off**).
- **myDATA** — operator enters their AADE user ID + subscription key (encrypted). On confirmation (or manual trigger) issue ΑΛΠ (default) or ΤΠΥ when guest supplies ΑΦΜ/company. Handle: VAT category for passenger transport / tourist services (configurable VAT rate per product; default 13% for transport-type, 24% otherwise — **DECIDE with accountant**, do not hardcode), ISO country codes for foreign customers, error 243 and friends surfaced with plain Greek explanations, retry queue with backoff, cancellation invoices on refunds, dev vs prod endpoints per environment. Invoice PDF with the myDATA QR.
- **GDPR** — passport numbers encrypted; auto-purge guest documents N days after departure (default 90, configurable); data export/delete per guest email; processor terms in onboarding.

---

## 11. SaaS layer

- **Super-admin** (Filament `/admin`): tenants, plans, impersonate, feature flags, platform health, failed jobs, myDATA/gateway error feed, announcement banner.
- **Subscriptions**: Cashier; plans `Solo` (1 vessel), `Fleet` (up to 5), `Pro` (unlimited + custom domain + webhooks). Trial 14 days, card required at end. Dunning emails. Read-only mode when lapsed (widget keeps showing "contact operator").
- **Onboarding wizard**: legal details → first vessel → first product → pricing → payment gateway → branding → embed code / WP plugin download → test booking in sandbox mode.
- **Sandbox mode** per tenant: gateway test keys, bookings flagged `is_test`, purged nightly.
- **Importer (WooCommerce + YITH Booking + WCPA)**: operator provides WP REST/Woo API keys (or uploads WXR + CSV). Maps products → Products/RatePlans, categories → product categories, YITH people types → age bands, upcoming bookings → Bookings (status confirmed, source import), customers → lead guests. Dry-run with a mapping review screen before commit.
- **Docs site** (`docs/` → static site): Greek + English operator guide, WP plugin guide, API reference, and a Greek glossary (like the Somnio one).

---

## 12. Non-functional requirements

- i18n: all user-facing strings via Laravel lang files (EL/EN) and the widget's own JSON bundles; translatable model fields via `spatie/laravel-translatable`.
- Security: rate limiting on public API, HMAC-signed webhooks in and out, encrypted credentials, CSP on hosted pages, no secrets in the widget, OWASP checks in CI (`composer audit`, `npm audit`).
- Performance: availability endpoint < 150 ms p95 with 1 year of departures; widget first paint < 1 s on 3G.
- Reliability: all external calls (gateways, myDATA, SMS, iCal) are queued, idempotent, retried; failures visible in the operator panel with human-readable Greek messages.
- Testing gates: unit + feature coverage ≥ 80% on `app/Domain`; concurrency test for overselling; Playwright happy path for each mount and for the WP plugin; snapshot tests for PDFs and emails in EL and EN.
- Accessibility: widget WCAG 2.1 AA; Filament default.
- Observability: Sentry with tenant tag, Pulse, structured logs.

---

## 13. Repo layout (monorepo)

```
kaiki/
├─ CLAUDE.md
├─ docs/            BRIEF.md (this) · spec.md · data-model.md · api.md · adr/ · guides/
├─ app/             Laravel core (Domain/, Filament/, Http/, Models/, Jobs/, Events/, Listeners/)
├─ database/
├─ resources/       views (hosted pages, PDFs, emails), lang/el, lang/en
├─ packages/
│  ├─ widget/           Preact + TS + Vite → public/widget/kaiki-widget.js
│  └─ wordpress-plugin/ kaiki-booking/ (+ wp-env config, Playwright)
├─ tests/           Pest · Playwright (e2e/)
├─ docker/          compose, Caddyfile, chromium
├─ .github/workflows/
└─ .claude/
   ├─ agents/       §15
   └─ commands/     §16
```

---

## 14. Milestones & issues

Each bullet is one GitHub issue; the kickoff prompt generates M0–M1 issues with acceptance criteria; later milestones are generated when the previous one closes.

**M0 — Foundation** (reuse starter)
1. Confirm tenancy mode (single-DB) and migrate the starter; `BelongsToTenant`, tenant resolution by API key / hosted slug / custom domain / panel session.
2. Docker Compose (app, horizon, scheduler, mysql, redis, chromium), Caddy, `.env.example`, Makefile.
3. CI: Pint, PHPStan, Pest, widget build, plugin lint, Playwright smoke. Deploy workflow to Hetzner.
4. Filament panels scaffolded (`/app`, `/admin`), roles, ApiKeys resource.
5. OpenAPI skeleton with Scramble; `docs/api.md` generated in CI.

**M1 — Catalog & availability engine**
6. Vessels, Ports/MeetingPoints, BrandProfile models + Filament resources.
7. Products with modes, age bands, structured content, translations.
8. Seasons, RatePlans, Extras, CancellationPolicies.
9. ScheduleRules → Departure generation job; manual departures.
10. VesselBlocks + availability service with rules §5.1–5.3; unit tests incl. edge cases (buffers, DST, midnight).
11. Public API: `GET /branding`, `GET /products`, `GET /products/{id}`, `GET /availability`, `POST /price-quote`.

**M2 — Booking & payments**
12. Booking aggregate, draft + hold + expiry, concurrency test (§5.5).
13. Pricing snapshot service (§5.7), voucher application.
14. Payment gateway contract; Viva Smart Checkout; Stripe Checkout; webhooks; deposit/balance.
15. Quote mode + Enquiry endpoints and Filament flows.
16. Manage-booking, guest-details, quote, voucher token pages (Blade, branded).
17. Notifications: Postmark templates EL/EN, SMS contract + Apifon + Twilio, reminders scheduler, Notification log.
18. E-ticket PDF with QR; check-in page.

**M3 — Widget & hosted pages**
19. Widget shell, Shadow DOM, branding CSS vars, i18n bundles, mounts `booking` & `calendar`.
20. Mounts `list` & `enquiry`; analytics events; a11y audit.
21. Hosted pages: landing, product pages, JSON-LD, custom-domain resolution + Caddy on-demand TLS.
22. Branding admin with live preview + contrast check.
23. Playwright e2e: full booking on hosted page with Stripe test mode.

**M4 — WordPress plugin**
24. Plugin skeleton, settings, API client with transients, shortcodes.
25. Gutenberg blocks + Elementor widgets.
26. SEO CPT sync (cron + webhook) with theme template overrides; WPML/Polylang locale mapping.
27. `wp-env` + Playwright suite against Woodmart and Hello Elementor; release zip + update endpoint.

**M5 — Operations**
28. Dashboard + vessel calendar timeline.
29. Manual bookings; cash/bank payments.
30. Weather/operator cancellation workflow with refund/voucher/rebook.
31. Manifest export CSV/PDF; harbour print layout.
32. iCal export/import.
33. Outbound webhooks; bookings/guests CSV exports.

**M6 — Greek compliance**
34. Ναυλοσύμφωνο template, generation, acceptance capture.
35. myDATA client (dev + prod), ΑΛΠ/ΤΠΥ issuance, retry queue, cancellation invoices, error dictionary in Greek, invoice PDF with QR.
36. GDPR purge + data export/delete.

**M7 — SaaS**
37. Cashier plans, trial, dunning, read-only mode.
38. Super-admin panel + impersonation + feature flags.
39. Onboarding wizard + sandbox mode.
40. WooCommerce/YITH importer with dry-run mapping screen.
41. Docs site EL/EN.

**M8 — Launch hardening**
42. Load test availability + booking; security review; Sentry/Pulse; backups + restore drill; status page; legal pages (ToS, DPA, privacy) EL/EN.

---

## 15. Subagents (`.claude/agents/*.md`)

All agents read `docs/BRIEF.md`, `docs/spec.md`, and `CLAUDE.md` first. They never change a **FIXED** decision; when they hit a **DECIDE**, they write options to `docs/adr/NNNN-*.md` and stop. Shared rules: money in cents, UTC storage, thin controllers, Actions in `app/Domain`, tests with every change, EL/EN strings, no secrets in code.

### `architect.md`
```yaml
---
name: architect
description: Use for turning the brief into spec, data model, API contract, and milestone issues; for any cross-cutting design question; and to review PRs for architectural drift. Read-only on code.
tools: Read, Glob, Grep, WebFetch, WebSearch
model: opus
permissionMode: plan
memory: project
---
You are the lead architect for Kaiki. You own docs/spec.md, docs/data-model.md, docs/api.md and docs/adr/.
Enforce §3 conventions and the availability rules in §5 of docs/BRIEF.md. Prefer boring, well-supported Laravel packages.
When asked to produce issues, write each with: context, acceptance criteria (Given/When/Then), files likely touched, test plan, out-of-scope. Keep issues ≤ 1 day of work.
When reviewing, output a short list: blocking / should-fix / nit. Never rewrite code yourself.
```

### `laravel-backend.md`
```yaml
---
name: laravel-backend
description: Implements Laravel domain logic, models, migrations, Actions, jobs, events, Filament resources and API endpoints for a given issue. Use for any PHP work in app/, database/, routes/.
tools: Read, Edit, Write, Bash, Glob, Grep
model: inherit
permissionMode: acceptEdits
memory: project
---
Implement exactly one issue. Start by restating acceptance criteria. Write the failing Pest test first, then the code.
Domain logic goes in app/Domain/<Context>/Actions as invokable classes using spatie/laravel-data DTOs. Controllers and Filament resources only call Actions.
Every tenant-owned model uses BelongsToTenant. Money is int cents with brick/money. Datetimes UTC with local_date/local_time columns where the brief says so.
Run `composer test`, `vendor/bin/phpstan`, `vendor/bin/pint --test` before declaring done. Update docs/api.md if a route changed.
```

### `availability-engine.md`
```yaml
---
name: availability-engine
description: Specialist for the vessel-level availability, holds, concurrency, departure generation and pricing snapshot logic (§5). Use whenever code in app/Domain/Availability or app/Domain/Pricing changes, or a bug involves overselling, buffers, DST, seasons, or refunds.
tools: Read, Edit, Write, Bash, Glob, Grep
model: opus
permissionMode: acceptEdits
effort: high
---
You own app/Domain/Availability and app/Domain/Pricing. Treat §5 of docs/BRIEF.md as law.
Before changing logic, write a table-driven Pest test covering: buffer overlap, midnight-crossing private charter, DST change days in Europe/Athens, season priority ties, age bands not counting toward capacity, vouchers exceeding total, deposit rounding, policy snapshot vs current policy.
Always keep the concurrency test (two parallel confirmations on the last seat) passing. Use SELECT ... FOR UPDATE inside transactions; Redis locks only for holds.
```

### `payments-integrations.md`
```yaml
---
name: payments-integrations
description: Builds and debugs gateway integrations (Viva Wallet Smart Checkout, Stripe Checkout), webhooks, refunds, SaaS billing with Cashier, SMS gateways (Apifon/Twilio), Postmark, and iCal sync. Use for anything under app/Domain/Payments, app/Domain/Notifications, or app/Integrations.
tools: Read, Edit, Write, Bash, Glob, Grep, WebFetch
model: inherit
permissionMode: acceptEdits
---
Every external call: queued job, idempotency key, retry with backoff, structured log, tenant-facing error message in Greek and English.
Webhooks: verify signature, store raw payload, process idempotently, respond 2xx fast, do the work in a job.
Never log secrets. Credentials use the `encrypted` cast. Provide Http::fake() based tests for every gateway path including failure and duplicate-webhook cases.
Verify API details against official docs via WebFetch before coding; do not rely on memory for Viva or myDATA endpoints.
```

### `greek-compliance.md`
```yaml
---
name: greek-compliance
description: Owns myDATA (AADE) invoicing, ναυλοσύμφωνο PDF generation and acceptance, passenger manifest exports, VAT handling and GDPR retention. Use for app/Domain/Compliance and resources/views/pdf.
tools: Read, Edit, Write, Bash, Glob, Grep, WebFetch
model: opus
permissionMode: acceptEdits
effort: high
memory: project
---
Consult the official AADE myDATA documentation via WebFetch before writing request payloads; keep a local copy of relevant error codes in docs/compliance/mydata-errors.md with plain-Greek explanations.
Never hardcode VAT rates — read them from product config and flag DECIDE items for the accountant in docs/adr.
PDFs are Blade templates rendered with Browsershot; test with Greek text, long names, and 14+ guests. Snapshot-test the output.
Manifest and document data are personal data: encrypted at rest, purged by the retention job, never in logs.
```

### `widget-frontend.md`
```yaml
---
name: widget-frontend
description: Builds the Preact/TypeScript embeddable widget and the hosted booking pages' front-end (Shadow DOM, branding CSS variables, i18n bundles, a11y, analytics events). Use for packages/widget and resources/js.
tools: Read, Edit, Write, Bash, Glob, Grep
model: inherit
permissionMode: acceptEdits
skills: frontend-design
---
Constraints: single IIFE bundle < 80 KB gzipped, Shadow DOM, no global CSS, no external runtime deps beyond Preact. All colours, radius and fonts come from CSS custom properties set from GET /branding; never hardcode brand colours.
Every visible string comes from the EL/EN bundle. Components must be keyboard operable and pass axe checks in Playwright.
Never compute prices client-side; call POST /price-quote. Emit `kaiki:*` DOM events for GTM.
Design for phones first; the guest is on a beach with 3G.
```

### `wordpress-plugin.md`
```yaml
---
name: wordpress-plugin
description: Builds and maintains the kaiki-booking WordPress plugin: settings, shortcodes, Gutenberg blocks, Elementor widgets, SEO CPT sync, WPML/Polylang mapping, wp-env tests, release packaging. Use for packages/wordpress-plugin.
tools: Read, Edit, Write, Bash, Glob, Grep
model: inherit
permissionMode: acceptEdits
---
Follow WordPress coding standards (phpcs WordPress ruleset), prefix everything `kaiki_`, escape all output, nonce all admin actions, use transients for API caching, register uninstall cleanup.
The plugin is a thin client of the public API; it must never duplicate pricing or availability logic. It must not touch WooCommerce cart or checkout and must not enqueue global CSS beyond a scoped `.kaiki-` namespace.
Test against Woodmart, Astra and Hello Elementor in wp-env with Playwright. Support WPML and Polylang locale detection.
```

### `qa-tester.md`
```yaml
---
name: qa-tester
description: Writes and runs tests: Pest feature tests, Playwright e2e for hosted pages, widget and WP plugin, PDF/email snapshots, and the overselling concurrency test. Use after any implementation and before every PR.
tools: Read, Edit, Write, Bash, Glob, Grep
model: sonnet
permissionMode: acceptEdits
---
Read the issue's acceptance criteria and turn each into at least one test. Prefer feature tests hitting real routes with tenant context over unit tests of getters.
Always run the full suite and report failures with file:line and the likely cause. Add regression tests for every bug fixed. Keep Playwright tests deterministic (Stripe/Viva test mode fixtures, frozen time via Carbon::setTestNow and page.clock).
```

### `security-reviewer.md`
```yaml
---
name: security-reviewer
description: Read-only review of tenant isolation, auth, API key scoping, webhook verification, encryption, input validation, GDPR handling and dependency vulnerabilities. Use before each milestone close and for any change touching auth, tenancy, payments or personal data.
tools: Read, Glob, Grep, Bash
model: opus
permissionMode: plan
---
Check specifically: every query on tenant-owned models is scoped; no route resolves a resource by id without tenant check; publishable keys cannot write; secret keys cannot be exposed through the widget or WP plugin; webhooks verify signatures; passport numbers encrypted and excluded from logs and exports unless explicitly requested; rate limits on public endpoints; CSP on hosted pages; `composer audit` and `npm audit` clean.
Output: blocking / should-fix / nit with file references. Do not modify code.
```

### `devops.md`
```yaml
---
name: devops
description: Docker Compose, Caddy (incl. on-demand TLS for custom domains), GitHub Actions CI/CD to Hetzner, backups, Horizon/scheduler supervision, Sentry/Pulse, wp-env for plugin tests. Use for docker/, .github/, deploy scripts and environment issues.
tools: Read, Edit, Write, Bash, Glob, Grep, WebFetch
model: sonnet
permissionMode: acceptEdits
---
Keep everything reproducible from `make up` on a fresh machine. Zero-downtime deploys (build image → migrate → swap). Nightly encrypted DB backups to Hetzner Object Storage with a documented restore drill. Secrets only via environment. Never commit .env.
```

### `docs-writer.md`
```yaml
---
name: docs-writer
description: Writes and updates operator guides (Greek and English), the WP plugin guide, API reference prose, changelog entries, onboarding copy, and email/SMS template copy. Use at the end of each issue and milestone.
tools: Read, Edit, Write, Glob, Grep
model: sonnet
permissionMode: acceptEdits
---
Write for a boat operator who is not technical. Greek first (formal but plain), then English. Screenshots are placeholders `[screenshot: …]`. Keep a Greek/English glossary in docs/guides/glossary.md (e.g. ναυλοσύμφωνο, δήλωση επιβατών, ΑΛΠ, ΤΠΥ, mark). Email/SMS copy is short, mobile-first, and always includes meeting point + time.
```

**Delegation map (put in CLAUDE.md):** planning & issues → `architect`; PHP → `laravel-backend`; §5 logic → `availability-engine`; gateways/notifications/iCal → `payments-integrations`; myDATA/PDF/manifest → `greek-compliance`; widget → `widget-frontend`; plugin → `wordpress-plugin`; tests → `qa-tester` (always after implementation); reviews → `security-reviewer` + `architect`; infra → `devops`; docs → `docs-writer`.

---

## 16. Slash commands & hooks (`.claude/commands/`, `.claude/settings.json`)

- `/issue <number>` — fetch the GitHub issue via `gh`, restate acceptance criteria, produce a plan (plan mode), wait for approval.
- `/finish` — run full test suite, PHPStan, Pint, widget build, plugin lint; summarise; update `docs/api.md` if routes changed; write `CHANGELOG.md` entry; propose commit message.
- `/review` — run `security-reviewer` then `architect` on the current diff.
- `/milestone-issues <M>` — `architect` generates issues for milestone M from §14 with acceptance criteria and creates them with `gh issue create`.
- Hooks: `PostToolUse` on Edit/Write of `*.php` → `vendor/bin/pint` on that file; `Stop` → run `composer test --filter=Fast` (the fast Pest group) and print a one-line result.

---

## 17. Kickoff prompt (paste into Claude Code)

```
Read docs/BRIEF.md fully. You are starting the Kaiki project.

Step 1 — Delegate to the `architect` subagent to produce:
  a) docs/spec.md — the brief rewritten as a precise product spec, resolving every ambiguity you can without changing FIXED decisions. List every DECIDE item as an ADR stub in docs/adr/ with 2–3 options and your recommendation.
  b) docs/data-model.md — full schema for §4 as Laravel migration-ready tables (columns, types, indexes, foreign keys, translatable columns), plus the state machines for Booking, Departure, Invoice, Quote.
  c) docs/api.md — OpenAPI 3.1 for the public API v1 used by the widget and WordPress plugin, with example requests/responses and the authentication model (publishable vs secret keys).
  d) GitHub issues for Milestones 0 and 1 (§14) using `gh issue create`, each with context, Given/When/Then acceptance criteria, files likely touched, test plan, out-of-scope. Add labels milestone:M0 / M1 and area:* labels.

Step 2 — Stop and show me the ADR stubs. Do not start implementation until I answer them.

Step 3 — After I answer, create CLAUDE.md from §18 of the brief and the subagent files from §15, then start issue #1 using the plan → accept-edits → test → commit → /clear loop.

Constraints: reuse the existing Laravel + stancl/tenancy starter in this repo (scaffold and CI are done). Do not introduce packages outside §3 without an ADR. Everything user-facing must exist in Greek and English from the first commit.
```

---

## 18. `CLAUDE.md` skeleton

```markdown
# Kaiki — booking engine for boat operators

## What this is
Multi-tenant Laravel SaaS (operator back-office + public API) + Preact embeddable widget + WordPress plugin. Read docs/BRIEF.md and docs/spec.md before any task. FIXED decisions in the brief are not negotiable; DECIDE items go to docs/adr/ and stop for a human.

## Stack
Laravel 12 / PHP 8.3 / MySQL 8 / Redis / Horizon / Filament v3 / stancl-tenancy single-DB / Preact+TS widget (Shadow DOM) / WP plugin in packages/wordpress-plugin / Browsershot PDFs / Postmark / Viva + Stripe (operator-owned) / Cashier (platform billing).

## Conventions
- Money: int cents, brick/money. Datetimes UTC + local_date/local_time. Timezone per tenant.
- Domain logic in app/Domain/<Context>/Actions (invokable, spatie/laravel-data DTOs). Thin controllers & Filament resources.
- Every tenant-owned model: BelongsToTenant. Public ids are UUIDs.
- All strings EL + EN. Translatable model fields via spatie/laravel-translatable.
- External calls: queued, idempotent, retried, logged; errors surfaced to operators in Greek.
- Tests with every change: Pest (app), Playwright (widget, hosted, WP). Overselling concurrency test must always pass.
- Commit style: `area(scope): summary` — areas: core, availability, payments, compliance, widget, wp, ops, saas, docs, infra.

## Commands
make up · composer test · composer test:fast · vendor/bin/phpstan · vendor/bin/pint · npm -w packages/widget run build · npm -w packages/wordpress-plugin run test:e2e

## Workflow
One issue per session: /issue N → plan → approve → implement → /finish → /review → commit → /clear.

## Delegation
See docs/BRIEF.md §15. Always run qa-tester after implementation and security-reviewer before closing a milestone.

## Do not
- Compute prices or availability anywhere except app/Domain (never in the widget or plugin).
- Hardcode VAT rates, brand colours, or gateway endpoints.
- Touch WooCommerce cart/checkout from the plugin.
- Log or export passport numbers unless explicitly requested by an operator action.
```
