# KAIKI — The complete project document

> **Τι είναι αυτό το αρχείο.** Είναι το ένα έγγραφο που κρατάει τα πάντα για το Kaiki:
> το τι φτιάχνουμε, γιατί, με ποιες αποφάσεις, τι έχει ήδη χτιστεί και τι μένει.
> Γράφτηκε με την υπόθεση ότι **όλα τα υπόλοιπα σβήνονται** — ο κώδικας, τα
> `docs/`, τα issues, τα ADR. Από αυτό εδώ και μόνο πρέπει να μπορεί κάποιος να
> ξαναχτίσει το προϊόν και να καταλήξει στο ίδιο πράγμα.
>
> Είναι γραμμένο στα Αγγλικά επειδή όλος ο κώδικας, τα ονόματα πινάκων και οι
> κωδικοί απαιτήσεων (`AVL-12`, `PAY-3`, `HOS-8` …) είναι στα Αγγλικά, και ένα
> έγγραφο που μεταφράζει τα μισά είναι χειρότερο από ένα που δεν μεταφράζει
> κανένα.

**Version of this document:** 2026-09-06
**Status of the build:** M0 complete · M1 complete · M2 complete · M3 in progress (hosted pages)

---

## Table of contents

1. [What Kaiki is](#1-what-kaiki-is)
2. [Scope — in and out](#2-scope--in-and-out)
3. [Stack, environment and the two-stack problem](#3-stack-environment-and-the-two-stack-problem)
4. [Conventions that are not negotiable](#4-conventions-that-are-not-negotiable)
5. [Tenancy](#5-tenancy)
6. [Users, roles and permissions](#6-users-roles-and-permissions)
7. [The data model](#7-the-data-model)
8. [Catalogue](#8-catalogue)
9. [The availability engine](#9-the-availability-engine)
10. [The pricing engine](#10-the-pricing-engine)
11. [The booking lifecycle](#11-the-booking-lifecycle)
12. [Payments](#12-payments)
13. [Cancellations, refunds and vouchers](#13-cancellations-refunds-and-vouchers)
14. [Quotes and enquiries](#14-quotes-and-enquiries)
15. [Guest surfaces — the four token pages](#15-guest-surfaces--the-four-token-pages)
16. [Notifications](#16-notifications)
17. [E-ticket and check-in](#17-e-ticket-and-check-in)
18. [Hosted pages](#18-hosted-pages)
19. [The widget](#19-the-widget)
20. [The WordPress plugin](#20-the-wordpress-plugin)
21. [Operations — the back office](#21-operations--the-back-office)
22. [Greek compliance](#22-greek-compliance)
23. [The SaaS layer](#23-the-saas-layer)
24. [The onboarding wizard](#24-the-onboarding-wizard)
25. [The public API](#25-the-public-api)
26. [Branding and the design system](#26-branding-and-the-design-system)
27. [Internationalisation](#27-internationalisation)
28. [Security](#28-security)
29. [Testing and the architecture gates](#29-testing-and-the-architecture-gates)
30. [CI](#30-ci)
31. [The decision register (26 ADRs)](#31-the-decision-register-26-adrs)
32. [Contradictions found and how they were resolved](#32-contradictions-found-and-how-they-were-resolved)
33. [Traps — the things that cost hours](#33-traps--the-things-that-cost-hours)
34. [Milestones: what is built, what is next](#34-milestones-what-is-built-what-is-next)
35. [Running it locally](#35-running-it-locally)
36. [If you had to rebuild from zero](#36-if-you-had-to-rebuild-from-zero)

---

## 1. What Kaiki is

Kaiki is a **multi-tenant booking engine for Greek boat operators** — day cruises,
scheduled trips and private charters. One installation serves many operators
("tenants"); each operator gets a back office, a public booking page, an
embeddable widget, a WordPress plugin, and Greek tax compliance.

The name is καΐκι — the traditional wooden boat.

### The three audiences, and what each one sees

| Audience | Surface | What they can do |
| --- | --- | --- |
| **Guest / tourist** | hosted page, widget, WordPress site, token pages | browse trips, check availability, book, pay, add guest details, get an e-ticket, cancel |
| **Operator** (owner, manager, crew) | Filament panel at `/app` | catalogue, calendar, bookings, manual bookings, check-in, payments, refunds, vouchers, manifests, invoices, branding, integrations |
| **Platform** (super-admin) | Filament panel at `/admin` | tenants, plans, subscriptions, impersonation, feature flags, VAT rate table, custom-domain issuance log |

### The product thesis

The Greek market is served today by generic international tools that do not issue
ΑΛΠ/ΤΠΥ through myDATA, do not know what a ναυλοσύμφωνο is, and do not speak
Greek to the guest. Kaiki is opinionated about exactly those three things, and
ordinary about the rest.

Three product commitments follow from that and shape everything below:

1. **The guest is never punished for the operator's problem.** A lapsed
   subscription closes the operator's writes; the tourist reading about a boat
   still sees the whole page (HOS-10).
2. **Money is never recomputed.** Every price the guest agreed to is snapshotted
   at the moment of agreement. A later VAT change, a later rate-plan edit, a later
   policy change cannot rewrite an issued invoice or a signed cancellation policy.
3. **A boat cannot be oversold.** Not "should not" — the capacity check is a
   locked transaction plus a conditional update, and there is a parallel test on
   MySQL in CI that proves it.

---

## 2. Scope — in and out

### In (MVP)

- Multi-tenancy, custom domains, roles
- Catalogue: vessels, ports, products (per-seat and per-vessel), age bands, extras,
  seasons, rate plans, cancellation policies
- Schedule rules → generated departures; manual departures; vessel blocks; iCal in/out
- Availability engine with buffers, DST, lead times, midnight-spanning trips
- Pricing engine with seasons, age bands, extras, vouchers, VAT snapshots
- Booking lifecycle: draft → hold → confirmed → completed / cancelled / expired
- Payments: Viva Smart Checkout, deposit + balance, refunds, webhooks
- Quotes and enquiries (the charter sales path)
- Four tokenised guest pages; e-ticket PDF; check-in
- Notifications: email (Postmark), SMS, reminders, quiet hours
- Hosted operator pages (SEO, EL/EN, no JavaScript required)
- Embeddable widget (Preact, ≤80 KB gzipped)
- WordPress plugin (shortcodes, blocks, Elementor, SEO CPT sync)
- Operations: dashboard, vessel calendar, manual bookings, weather cancellation,
  manifest exports, outbound webhooks, CSV exports
- Greek compliance: ναυλοσύμφωνο, myDATA ΑΛΠ/ΤΠΥ, cancellation invoices, invoice
  PDF with QR, GDPR purge and data-subject tooling
- SaaS: plans, trial, dunning, read-only mode, super-admin, impersonation,
  onboarding wizard, sandbox mode, WooCommerce/YITH importer

### Out (design for it, do not build it)

Loyalty programmes, multi-currency, channel-manager integrations (Viator, GYG),
dynamic/yield pricing, a native mobile app, crew scheduling and payroll,
multi-tenant user membership (one user belongs to exactly one operator — ADR-0020).

---

## 3. Stack, environment and the two-stack problem

### The stack

| Layer | Choice | Notes |
| --- | --- | --- |
| Language | **PHP 8.4** | ADR-0014. The brief said 8.3; the machine runs 8.4 and parity beat compatibility nothing needed. |
| Framework | **Laravel 12** | scaffolded from scratch, not from a starter kit |
| Admin | **Filament v3** | two panels: `/app` (operator), `/admin` (super-admin) |
| Tenancy | **`stancl/tenancy`**, single database | ADR-0001 |
| Tests | **Pest 3** | groups: `fast`, `mysql`, `chromium`, `external` |
| Static analysis | **PHPStan level 6 + Larastan** | no baseline, no `@phpstan-ignore` |
| Formatting | **Pint** | `declare_strict_types`, `strict_comparison`, sorted imports |
| Widget | **Preact + TypeScript + Vite** | ADR-0011 |
| PDF | **Chromium via Puppeteer** | e-ticket, invoice, ναυλοσύμφωνο |
| Payments | **Viva Smart Checkout** | one `PaymentGateway` contract; a second gateway is a class (ADR-0004, ADR-0028) |
| Email | **Postmark** | |
| API docs | **Scramble** + a hand-written OpenAPI 3.1 in `docs/api.md` | ADR-0026 |

### The two-stack problem (ADR-0015) — read this before debugging anything

Local development and CI/production do **not** run the same infrastructure:

| | Local | CI / production |
| --- | --- | --- |
| Database | SQLite | MySQL 8 |
| Cache / locks | database driver | Redis |
| Queue | database | Redis |
| Chromium | installed ad hoc | a dedicated CI job |

This divergence was accepted deliberately, and it is *managed*, not ignored:

1. A required CI job runs the whole suite on **MySQL 8 + Redis** on every PR.
2. The `mysql`, `chromium` and `external` groups **fail the build if they report
   zero executed tests** — a group nothing runs is worse than a group that fails.
3. **Migrations never use engine-specific SQL.** SQLite cannot add a foreign key
   after the fact, so a table that will be referenced later must land early even
   if its feature is milestones away (this is why `charter_agreements`, an M6
   table, was created in M2).
4. A CI job migrates from zero on MySQL and asserts the dump matches a committed
   schema snapshot.

**Consequence you will hit:** `composer test` locally shows one permanent failure —
`CiGatesTest > it keeps the committed schema snapshot in step with the migrations` —
because the snapshot can only be regenerated on MySQL 8, which only CI has.

### Commands

```bash
export PATH="/c/Users/Mike/php84:$PATH"   # default `php` is 8.3 and fails Composer's platform check

composer test            # full Pest suite
composer test:fast       # --group=fast
composer test:mysql      # requires a MySQL instance
vendor/bin/pint          # format
vendor/bin/phpstan analyse
php artisan migrate:fresh --seed
php artisan serve
npm run widget:build
```

---

## 4. Conventions that are not negotiable

These are the rules that a reviewer (and several automated gates) enforce. Most of
them exist because breaking them silently produces wrong money or a leaked tenant.

**Money.** Integer cents, always. No floats anywhere near a price. Column names end
in `_cents`. **The sign lives in the column's meaning, not in the value** — a
`refunded_cents` is a positive number that means money went out. A column that can
hold both directions has a `direction` beside it (`voucher_redemptions`).

**Time.** Every instant is stored in UTC (`*_at_utc`). Every *local* meaning is
stored as the trio `local_date`, `local_time`, and the UTC instant computed from
them in the tenant's timezone. There is exactly **one** authority that converts
local → UTC; nothing else may call `Carbon::parse` on an operator's wall clock.

**DST (ADR-0016).** A non-existent local time (spring forward) is refused at save
time with a named error, never silently shifted. An ambiguous local time (autumn
back) resolves to the **first occurrence, summer offset**, and the departure is
flagged `dst_ambiguous`. All duration arithmetic is absolute:
`ends_at_utc = starts_at_utc + duration_minutes`.

**Translations (ADR-0008).** JSON columns, `{"el": "...", "en": "..."}`.
**No query may sort or filter on a JSON path.** Anything sortable or searchable
gets an observer-maintained companion column: `name_sort_el`, `name_sort_en`, and a
per-row `search_index` holding all locales concatenated and accent-folded.

**Tenancy.** Every tenant-owned model uses `BelongsToTenant`. There is a Pest test
per model asserting isolation. No query anywhere may filter on `tenant_id` by hand.

**Locks (ADR-0005).** Domain code may never reference `Redis::` or `RedisStore`
directly — only `Cache::lock` and `Cache`. Enforced by `NoDirectRedisTest`.

**Lock order (AVL-45).** `vessel → departure → booking → voucher`. Always. Enforced
by `LockDisciplineTest`.

**No external call inside a locked transaction (AVL-46).** A gateway that hangs
must never hold a seat lock. Enforced by the same test.

**VAT.** No percentage may be hardcoded anywhere — not in code, not in config, not
in a seeder, not in a test fixture. Rates are rows in `vat_rates` with validity
dates. Enforced by `NoHardcodedVatRateTest`.

**Snapshots.** `price_snapshot`, `policy_snapshot`, `extras_snapshot` and
`pax_breakdown` are written once and never recomputed. What the guest agreed to is
what the invoice says, forever.

**Identifiers in public payloads.** Never an auto-increment `id`. Public payloads
carry `uuid`. This is CNV-8, and it has a scanner, because it is exactly the kind
of thing that slips into one nested array and is never noticed.

**Nothing in the URL that is not meant to be a credential.** Conversely: the four
token pages *are* credentials in a URL, and they carry `noindex`, are single-purpose
and are scoped to one booking.

---

## 5. Tenancy

### The model (ADR-0001)

**Single database, `tenant_id` on every tenant-owned table.** Not
database-per-tenant. The super-admin panel and cross-tenant reporting in the SaaS
layer make per-database actively hostile, and with an empty repo there was no
migration cost. Isolation risk is paid down with a mandatory trait, a per-model
isolation test, and a security review before every milestone close.

### Tenant resolution order (TEN-4)

A request resolves its tenant by trying, **in this order**:

1. **API key** — a `pk_`/`sk_` bearer token names its tenant.
2. **Custom domain** — a verified row in `tenant_domains`.
3. **Hosted slug** — the first path segment, **on the hosted host only**
   (`book.{platform-domain}/{operator-slug}`), and only when
   `hosted_page_enabled` is true.
4. **The signed-in user's tenant.**

The order matters and is tested by precedence, not just by happy path. The case it
exists for: *an operator signed into their own panel opens a competitor's hosted
page.* They must see that operator's public page, not their own back office
bleeding through.

The resolved strategy is recorded on the request as `tenant_resolved_by`, which is
what the resolution tests assert.

### Custom domains and TLS (ADR-0010)

Caddy's on-demand TLS with an `ask` endpoint. The endpoint answers from
`tenant_domains`, requires DNS verification before a row becomes `verified`, is
rate-limited, is cached both in Caddy and in the application, and every issuance is
logged to the super-admin panel. It fails safe: unknown host, no certificate.

### Read-only mode (SAA-7)

A tenant whose subscription has lapsed goes `status = read_only`. Writes are
refused with `403` **where the panel authorises**, not by inspecting the HTTP verb —
a `GET` that mutates and a `POST` that reads both exist, and verb-sniffing gets both
wrong. The public API's writes close; **the hosted page stays fully readable**
(HOS-10). The pressure belongs on the operator who owes money, not on a tourist.

---

## 6. Users, roles and permissions

**One user belongs to exactly one tenant** (ADR-0020, Option C). The agency and
multi-operator-skipper cases are real but speculative, and paying for them in M0
means paying in the exact place — tenant resolution — where a mistake leaks one
operator's bookings to another. Roles are kept in a shape (`role_assignments`) that
does not have to be unpicked if that changes.

| Role | Panel | Can |
| --- | --- | --- |
| `owner` | `/app` | everything, including billing, integrations, branding, deleting |
| `manager` | `/app` | catalogue, bookings, refunds, manifests — not billing or credentials |
| `crew` | `/app` | today's departures, manifests, check-in — no money, no catalogue |
| super-admin | `/admin` | tenants, plans, impersonation, flags, the VAT table |

Every Filament resource has a matching Policy, and `PolicyCoverageTest` fails the
build if a resource exists without one.

**2FA (ADR-0024):** Fortify is the chosen mechanism and the columns
(`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`) already
exist on `users`. Enrolment ships in M7 alongside impersonation, because 2FA matters
when there are real super-admin accounts with real operator data behind them.

---

## 7. The data model

44 tables. `tenant_id` is on every tenant-owned table and is not repeated in prose.

### 7.1 Platform and tenancy

**`tenants`** — `id, uuid, name, slug, legal_name, vat_number, tax_office,
gemi_number, address_line1, address_line2, city, postcode, country, phone, email,
timezone, default_locale, supported_locales, currency, plan, status, trial_ends_at,
custom_domain, custom_domain_verified_at, hosted_page_enabled, is_sandbox,
turnaround_buffer_minutes, guest_document_retention_days, auto_issue_invoice,
settings, balance_due_days_before_departure,
weather_choice_default, timestamps, deleted_at`

**`tenant_domains`** — `hostname, status, verification_token, verified_at,
last_checked_at`

**`users`** — `uuid, tenant_id, name, email, email_verified_at, password, phone,
locale, is_super_admin, last_login_at, two_factor_*, remember_token`

**`role_assignments`** — `user_id, role, granted_by_user_id`

**`api_keys`** — `name, type (publishable|secret), environment (live|test), prefix,
secret_hash, last_four, scopes, allowed_origins, last_used_at, expires_at,
revoked_at, created_by_user_id`

**`audit_logs`** — `user_id, action, subject_type, subject_id, subject_label,
reason, context, ip_address, created_at` — append-only, never updated or deleted by
application code.

**`vat_rates`** (platform-owned, **not** tenant-scoped) — `code, rate_bp,
vat_category, description, valid_from, valid_to, is_selectable`

**`brand_profiles`** — `logo_light_path, logo_dark_path, favicon_path,
email_header_image_path, color_primary, color_secondary, color_accent,
color_background, color_text, font_family, font_source, button_radius_px,
widget_theme, email_footer_text, social_links, custom_css, contrast_warnings`

**`integration_credentials`** — `provider, environment, credentials (encrypted),
public_config, external_account_id, is_default, is_active, verified_at, last_error,
webhook_secret (encrypted)` — one row per (tenant, provider, environment). Holds the
payment gateways, myDATA, SMS and Postmark.

### 7.2 Catalogue

**`ports`** — `uuid, name, address, lat, lng, instructions, photo_path, maps_url,
is_active, sort_order, search_index, name_sort_el, name_sort_en`

**`vessels`** — `uuid, name, type, registration_number, length_cm, capacity_max,
crew_count, captain_name, home_port_id, turnaround_buffer_minutes, description,
specs, images, status, sort_order`

**`products`** — `uuid, vessel_id, slug, category, mode (per_seat|per_vessel), title,
summary, description, duration_minutes, default_start_time, flexible_start,
earliest_start_time, latest_start_time, check_in_offset_minutes, meeting_point_id,
includes, excludes, what_to_bring, itinerary_stops, route_map_image_path, images,
min_pax, max_pax, min_booking_pax, cancellation_policy_id, guest_details_required,
guest_details_deadline_hours, vat_rate_id, mydata_income_class, price_from_cents,
status, sort_order, meta_title, meta_description, og_image_path, is_featured`

**`age_bands`** — `uuid, product_id, code, label, min_age, max_age,
counts_toward_capacity, pricing_mode, price_multiplier_bp, is_base, requires_adult,
sort_order`

**`seasons`** — `name, code, priority, is_active` ·
**`season_date_ranges`** — `season_id, starts_on, ends_on`

**`rate_plans`** — `product_id, season_id, name, vessel_price_cents,
extra_hour_price_cents, deposit_type, deposit_percent, deposit_fixed_cents,
min_lead_time_hours, max_advance_days, min_pax_override, is_active,
balance_due_days_before_departure` ·
**`rate_plan_prices`** — `rate_plan_id, age_band_id, price_cents`

**`extras`** — `uuid, name, description, pricing_type, price_cents, vat_rate_id,
max_qty, is_tenant_wide, is_required, counts_toward_capacity, prices_all_pax,
image_path, is_active, sort_order` ·
**`product_extra`** — `product_id, extra_id, price_cents_override, max_qty_override,
is_required_override, sort_order`

**`cancellation_policies`** — `name, summary, free_cancellation_hours,
weather_refund_percent, force_majeure_voucher_months, no_show_refund_percent,
is_default` ·
**`cancellation_policy_tiers`** — `cancellation_policy_id, days_before,
refund_percent`

### 7.3 Availability

**`schedule_rules`** — `product_id, vessel_id, weekday_mask, start_time, valid_from,
valid_until, capacity_override, generate_days_ahead, is_active, last_generated_on`

**`departures`** — `uuid, product_id, vessel_id, schedule_rule_id, local_date,
local_time, starts_at_utc, ends_at_utc, dst_ambiguous, capacity, min_pax,
seats_sold, seats_held, status, cancel_reason, cancelled_at, cancelled_by_user_id,
cancellation_note, is_blocked, notes, completed_at`

**`vessel_blocks`** — `vessel_id, starts_at_utc, ends_at_utc, local_date,
local_end_date, is_all_day, reason, booking_id, ical_source_id, external_uid, title,
notes, created_by_user_id`

**`ical_feeds`** (outbound) — `vessel_id, token, include_departures, include_blocks,
include_guest_names, is_active, last_accessed_at, access_count` ·
**`ical_sources`** (inbound) — `vessel_id, name, url, url_hash, is_active,
sync_interval_minutes, last_synced_at, last_success_at, last_error,
consecutive_failures, etag, events_imported`

### 7.4 Bookings

**`bookings`** — the aggregate root. `uuid, reference, product_id, vessel_id,
departure_id, mode, status, source, locale, local_date, local_time, starts_at_utc,
ends_at_utc, guest_name, guest_email, guest_phone, guest_nationality, guest_country,
guest_vat_number, guest_company_name, pax_total, pax_capacity_total, pax_breakdown,
extras_snapshot, policy_snapshot, price_snapshot, subtotal_cents, extras_cents,
discount_cents, total_cents, deposit_cents, paid_cents, balance_cents,
refunded_cents, vat_rate_bp, vat_category, vat_cents, voucher_id,
guest_details_status, guest_details_deadline_at, guest_details_token, manage_token,
hold_expires_at, confirmed_at, cancelled_at, cancelled_by, cancel_reason,
checked_in_at, completed_at, no_show, special_requests, internal_notes, is_test,
utm_*, referrer_url, terms_accepted_at, ip_address, user_agent, created_by_user_id,
balance_due_at, weather_choice, weather_choice_at, weather_choice_ip,
weather_choice_due_at, weather_choice_reminded_at, eticket_path, eticket_hash,
eticket_generated_at`

**`booking_guests`** — `uuid, booking_id, age_band_id, age_band_code, position,
full_name, date_of_birth, nationality, document_type (encrypted),
document_number (encrypted), document_expires_on, document_purged_at, ticket_code,
checked_in_at, checked_in_by_user_id, is_lead, notes, no_show`

**`booking_extras`** — `booking_id, extra_id, extra_name, pricing_type, qty,
unit_price_cents, total_cents, is_on_request, fulfilled_at`

**`vouchers`** — `uuid, code, amount_cents, remaining_cents, currency, status,
issued_at, expires_at, issued_for_booking_id, reason, notes, issued_by_user_id,
expiry_reminder_sent_at` ·
**`voucher_redemptions`** — `voucher_id, booking_id, amount_cents, redeemed_at,
reversed_at, reversed_amount_cents, reason`

### 7.5 Payments

**`payments`** — `uuid, booking_id, gateway, kind (deposit|balance|full|refund),
amount_cents, currency, status, gateway_ref, gateway_transaction_ref,
refunds_payment_id, idempotency_key, checkout_url, raw_payload, failure_code,
failure_message_el, failure_message_en, paid_at, refunded_at, recorded_by_user_id`

**`gateway_webhook_events`** — `provider, event_id, event_type, signature_valid,
payload, status, payment_id, error_message, processed_at, received_at` — the inbox
that makes webhook processing idempotent.

### 7.6 Sales and communication

**`quotes`** — `uuid, booking_id, version, status, quote_token, subtotal_cents,
discount_cents, total_cents, deposit_cents, vat_rate_bp, valid_until, message,
terms, sent_at, viewed_at, accepted_at, declined_at, decline_reason, expired_at,
created_by_user_id` ·
**`quote_line_items`** — `quote_id, label, description, kind, qty, unit_price_cents,
total_cents, sort_order`

**`enquiries`** — `uuid, product_id, name, email, phone, preferred_date, pax,
message, locale, status, converted_booking_id, answered_at, assigned_user_id,
source, ip_address, user_agent`

**`notification_logs`** — `booking_id, departure_id, notifiable_type, notifiable_id,
channel, template, locale, to, subject, status, provider, provider_ref,
error_message, cost_cents, sent_at, delivered_at`

### 7.7 Compliance

**`charter_agreements`** (ναυλοσύμφωνο) — `uuid, booking_id, template_key,
template_version, fields_snapshot, pdf_path, pdf_hash, generated_at, sent_at,
guest_accepted_at, guest_accepted_ip, guest_accepted_user_agent, guest_accepted_name,
operator_signed_at, operator_signed_by_user_id, status`. Unique on
`(tenant_id, booking_id, template_version)`. **The evidence columns are frozen once
accepted** — the model's `updating` guard throws `AgreementEvidenceLocked` if
anything in `FROZEN_ONCE_ACCEPTED` is touched after `guest_accepted_at` is set.

This table landed in M2 even though its feature is M6, because SQLite cannot add a
foreign key to an existing table.

---

## 8. Catalogue

### Two product modes, and why they are one table

`products.mode` is `per_seat` or `per_vessel`.

- **per_seat** — a scheduled trip. Capacity is seats; many bookings share a
  departure; the price is per age band.
- **per_vessel** — a private charter. One booking takes the whole boat; the price
  is `vessel_price_cents` plus extra hours; age bands still exist because the
  manifest still needs them, but they do not price.

They are one table (ADR-0023, Option C) because the row counts are small — a
five-product operator generates roughly 900 departures a year — and three indexed
range queries are not the bottleneck; API fan-out is. The union is contained behind
one port so it can be switched later without touching callers.

### Age bands

Per product, not global. `pricing_mode` is `own_price` (a row in
`rate_plan_prices`) or `multiplier` (`price_multiplier_bp` basis points off the base
band). `is_base` marks the band the multipliers apply to. `counts_toward_capacity`
lets an infant ride free and not take a seat. `requires_adult` refuses a party of
children alone.

### Seasons and rate plans

A season is a named set of date ranges with a `priority`. Overlapping seasons are
allowed; the highest priority wins, and **PRC-4** forbids a tie — two seasons at the
same priority covering the same date is a validation error, because otherwise the
price depends on row order.

A rate plan is `(product, season)` and carries the deposit rule
(`deposit_type` = percent | fixed | none), the booking window
(`min_lead_time_hours`, `max_advance_days`), and per-band prices.

`products.price_from_cents` is a **derived** column, recomputed by an observer when
any rate plan changes. Never write it by hand.

### Extras

Tenant-wide or per product, joined through `product_extra` which can override price,
max quantity and required-ness. `pricing_type` is per booking, per person or per
hour. An extra's `vat_rate_id` overrides the product's for that line.
`is_on_request` extras do not block confirmation; they are fulfilled later.

### Cancellation policies

A policy has a free-cancellation window in hours, a set of tiers
(`days_before` → `refund_percent`), a weather refund percent, a force-majeure
voucher validity in months, and a no-show refund percent. A policy is **snapshotted
into the booking** at confirmation; editing a policy never changes what an existing
booking is entitled to.

---

## 9. The availability engine

This is the part that has to be right. Everything else can be fixed with a
migration; a double-booked boat is a guest standing on a pier.

### The question it answers

> For product P, on date D, for a party of N (broken down by age band), is there a
> departure that can be sold, and how many seats are left?

### The inputs

1. **Departures** generated from schedule rules, plus manual departures.
2. **Vessel blocks** — maintenance, private use, imported iCal events.
3. **Other departures of the same vessel** plus each side's **turnaround buffer**.
4. **Lead time** (`min_lead_time_hours`) and **advance window** (`max_advance_days`)
   from the rate plan.
5. **Seats sold and held** on the departure row.
6. **Age-band capacity rules** — a band with `counts_toward_capacity = false` does
   not consume a seat.
7. **Minimum pax** — a departure below `min_pax` is still sellable; it is the
   operator's decision to cancel, not the engine's.

### Turnaround buffers

`vessels.turnaround_buffer_minutes` falls back to
`tenants.turnaround_buffer_minutes`. A vessel is unavailable from
`starts_at_utc - buffer` to `ends_at_utc + buffer` of every departure and block it
already has. This is why the availability query is three range queries and not one.

### Midnight and DST

A trip that ends after midnight has `ends_at_utc` on the next day and its
`local_date` is the **start** date — a sunset cruise on the 14th that lands at 00:30
is a booking for the 14th, because that is what a guest means. The DST rules of §4
apply here: the engine never invents a departure at a local time that does not
exist.

### Departure generation (ADR-0009)

- Horizon `kaiki.departures.horizon_days`, default **400**.
- Generation is **additive only**. A rule that changes never deletes a departure
  that may already hold a booking; the operator gets a reconciliation list instead.
- Runs nightly at **03:15 in the tenant's timezone**, and immediately (queued)
  whenever a rule is created or updated.
- The read path never writes.

### The hold (ADR-0005)

A hold is **data**, not cache: `departures.seats_held` plus
`bookings.hold_expires_at`. `Cache::lock` is a mutex around a short critical
section; it is not where the hold lives. This keeps one code path across SQLite,
MySQL and Redis, and makes the hold survive a cache flush.

Expiry is a scheduled job that releases holds whose `hold_expires_at` has passed and
moves the booking to `expired`.

### The oversell guard (ADR-0006)

Belt **and** braces:

```sql
-- inside a locked transaction, after lockForUpdate() on the departure
UPDATE departures
   SET seats_held = seats_held + :requested
 WHERE id = :id
   AND capacity - seats_sold - seats_held >= :requested
```

Zero rows affected → abort the transaction with `CAPACITY_EXCEEDED`. The conditional
update is portable, so **SQLite exercises it too**; `lockForUpdate()` gives MySQL the
ordering. There is a parallel test tagged `@group mysql` that is a required CI check.

`HoldSeats` also takes `$allowOvercapacity` for the operator's manual booking path,
which emits a `CapacityOverridden` event and refuses anyway if the boat would sail
**illegally** full (`wouldSailIllegallyFull()` — over the vessel's legal capacity,
not over the product's selling capacity).

---

## 10. The pricing engine

**Only one place computes a price**, and the API exposes it as `POST /price-quote`.
Nothing else — not the widget, not the WordPress plugin, not the panel — may add up
a total.

### The order of operations

1. Resolve the **season** for the departure date (highest priority; ties are a
   validation error).
2. Resolve the **rate plan** for `(product, season)`.
3. **per_seat:** for each age band, `qty × price` where price is either the band's
   own `rate_plan_prices` row or `base × price_multiplier_bp / 10000`.
   **per_vessel:** `vessel_price_cents` plus `extra_hour_price_cents × extra hours`.
4. Add **extras**, applying `product_extra` overrides, per-booking / per-person /
   per-hour as the type says.
5. Apply a **voucher**, if any (§13).
6. Resolve **VAT** per line: the extra's rate if set, otherwise the product's. Copy
   `vat_percent` and `vat_category` into the line.
7. Compute the **deposit** from the rate plan's rule.
8. Write the whole thing to `price_snapshot` — and never compute it again.

### Rounding

Round once, at the line total, half-up, to the cent. Never round an intermediate.
The sum of the lines is the subtotal by construction, so a receipt never fails to
add up.

---

## 11. The booking lifecycle

```
                    ┌──────────┐
                    │  draft   │  reference minted, seats held, hold_expires_at set
                    └────┬─────┘
           expires       │        checkout
        ┌───────────────┤────────────────┐
        ▼                │                ▼
  ┌──────────┐           │          ┌──────────┐
  │ expired  │           │          │ pending  │  gateway session open
  └──────────┘           │          └────┬─────┘
                         │               │ webhook: paid
                         │               ▼
                         │         ┌───────────┐
                         │         │ confirmed │  seats_held → seats_sold
                         │         └────┬──────┘
                         │              │
              ┌──────────┴──────┬───────┴────────┬──────────────┐
              ▼                 ▼                ▼              ▼
       ┌───────────┐     ┌────────────┐   ┌───────────┐  ┌────────────┐
       │ cancelled │     │ completed  │   │  no_show  │  │ weather-   │
       └───────────┘     └────────────┘   └───────────┘  │ cancelled  │
                                                          └────────────┘
```

### The reference (ADR-0007)

Format `KAI-XXXXX`, from an **unambiguous alphabet** — no `0`/`O`, no `1`/`I`. The
real failure mode is a guest reading a code aloud on a windy pier. Unique per tenant
by composite index, minted **at draft creation** so it is stable through the whole
lifecycle, and **never reused** from a cancelled or expired booking.

### Guest details

`products.guest_details_required` plus `guest_details_deadline_hours` produce
`bookings.guest_details_deadline_at`. Until then the booking sits at
`guest_details_status = pending` and the guest gets reminders. The manifest export
and the ναυλοσύμφωνο both need this data; the e-ticket does not.

### Balance due (ADR-0018)

`balance_due_days_before_departure` cascades tenant → rate plan. When a deposit
booking's balance is never paid, **nothing is cancelled automatically** in v1 —
automatic cancellation is a one-way door for the guest relationship. The behaviour
sits behind a Pennant flag `auto_cancel_overdue_balances`, off, so it can be turned
on per tenant once real behaviour is observed.

---

## 12. Payments

### The contract

One `PaymentGateway` interface, two implementations: **Viva Smart Checkout** and
Credentials live per operator in `integration_credentials`, encrypted,
per `environment` (`live` | `test`). There is no external secret store in MVP
(ADR-0004, Option A); the residual risk is handled by encrypting backups with a key
separate from `APP_KEY`, keeping `APP_KEY` out of the backup set, and never logging
a credential.

### The deposit / balance model (ADR-0004, Option D)

**Two separate checkout sessions.** It is the only model both gateways support
behind one abstraction, and it mirrors what Greek operators already do: deposit now,
balance before departure. `payments.kind` is `deposit`, `balance`, `full` or
`refund`; `refunds_payment_id` points a refund at the payment it reverses.

### Webhooks

Every inbound event is written to `gateway_webhook_events` **first** — provider,
`event_id`, signature validity, raw payload — and only then processed. Uniqueness on
`(provider, event_id)` is what makes redelivery harmless. A signature that does not
verify is recorded and refused, never processed.

Processing is idempotent by design: a webhook that arrives twice finds the payment
already `paid` and does nothing.

### Idempotency on the API (§3.4)

`POST /bookings`, `/checkout` and `/cancel` take an `Idempotency-Key`. The store is
cache-backed; the in-flight claim uses `Cache::add()` so two simultaneous requests
cannot both start. The body hash is computed over a **recursively sorted** copy of
the payload, so key order in JSON does not change the hash.

---

## 13. Cancellations, refunds and vouchers

### The refund calculation

From the **snapshotted** policy on the booking, never the live policy row:

1. Inside `free_cancellation_hours` → 100%.
2. Otherwise the highest tier whose `days_before` the cancellation still satisfies.
3. Weather cancellation → `weather_refund_percent`, or a voucher valid for
   `force_majeure_voucher_months`, at the **guest's** choice
   (`bookings.weather_choice`, with a deadline and a reminder).
4. No-show → `no_show_refund_percent`.

There is a **dry-run** endpoint: the guest sees exactly what they will get back
before they confirm, and the commit re-derives it rather than trusting the number
that came back.

### Vouchers (ADR-0017, Options A + D)

- **Voucher value stays voucher value; cash stays cash.** Neither converts into the
  other. A voucher larger than the booking leaves a remainder on the voucher — it is
  never paid out.
- Every movement is a row in `voucher_redemptions` with an amount, a direction and a
  reason, so `remaining_cents` is always reconstructible from the ledger.
- Cancelling a booking that used a voucher **restores** the voucher — as a reversal
  row, not by editing the original.

---

## 14. Quotes and enquiries

The charter sales path, which is not self-service:

**Enquiry** → a guest asks about a date. `POST /enquiries` is the only public write
that needs no booking. It carries an assigned user, a status and a
`converted_booking_id`.

**Quote** → the operator builds a versioned quote with free-form line items, a
`valid_until`, and a `quote_token` for the guest page. States: draft → sent → viewed
→ accepted / declined / expired. Accepting creates the booking.

**A quote does not hold the boat.** This is deliberate and it is stated to the
operator in the panel: a quote is a price, not a reservation, and the vessel remains
sellable until a booking exists. The alternative — quotes that hold — turns a sales
pipeline into a denial-of-service on the calendar.

---

## 15. Guest surfaces — the four token pages

Four pages where **the URL is the credential**. Each has its own token column, each
is single-purpose, each carries `X-Robots-Tag: noindex`, and none of them is a login.

| Page | Token | What it does |
| --- | --- | --- |
| Manage booking | `bookings.manage_token` | view, download e-ticket, start a cancellation |
| Guest details | `bookings.guest_details_token` | fill the manifest before the deadline |
| Quote | `quotes.quote_token` | view and accept or decline |
| Weather choice | `manage_token` + a scoped route | choose refund or voucher |

**Rules that make this safe:**

- A token is scoped to one booking and one purpose. The guest-details token cannot
  cancel; the manage token cannot rewrite the manifest after check-in.
- Tokens are long, random, and stored as-is (they are capabilities, not passwords —
  a hash would make the page unbuildable from the link in the email).
- The token **resolves its own tenant**, so these pages wrap their render in
  `Tenancy::forTenant()`. The hosted pages do not, because middleware already set
  the tenant.
- **The QR payload on an e-ticket is `ticket_code`** — never `manage_token`, never
  the booking `uuid`, never the reference. A photographed ticket must not become a
  cancellation link.

---

## 16. Notifications

Channels: **email** (Postmark) and **SMS**. Every send is logged in
`notification_logs` with the template, locale, provider reference, status, delivery
time and cost.

**Templates:** booking confirmed, deposit received, balance due, balance received,
guest details requested, guest details reminder, departure reminder, weather
cancellation with choice, cancellation confirmed, refund issued, voucher issued,
voucher expiring, quote sent, e-ticket ready.

**Quiet hours.** SMS is not sent between the configured quiet hours in the tenant's
timezone. A notification that falls in quiet hours is **dropped, not queued** when it
is time-sensitive-but-not-urgent (a reminder), and deferred when it is not. The drop
is logged, so "the guest never got it" has an answer.

**Locale.** Every notification is sent in `bookings.locale` — the language the guest
booked in, not the operator's default.

---

## 17. E-ticket and check-in

**The e-ticket** is a Chromium-rendered PDF, **one page per guest**, with an inline
SVG QR code and no external images (so it renders identically offline and in CI).
Stored on the private disk; `eticket_path`, `eticket_hash` and
`eticket_generated_at` are on the booking. It is served through a controller that
checks the manage token, never as a public URL.

**Timezone trap:** the e-ticket must format departure times in the **tenant's**
timezone. Passing `config('app.timezone')` (UTC) printed Greek departures three
hours early. The formatter takes `null` and lets the tenant's zone resolve.

**Check-in (BKG-22)** happens on a pier, on a phone, possibly with no signal. It has
two window edges and they are not symmetric:

- **Early** — before the window opens, check-in is refused but the refusal is
  **overridable** by the crew. A guest who arrives an hour early is still that guest.
- **Late** — after the departure has left, check-in is **not** overridable. A boat
  that has sailed cannot take a passenger, and a system that lets crew record one
  produces a manifest that does not match the people on board.

`MarkNoShow` and `CompleteDepartures` close out the rest.

---

## 18. Hosted pages

`book.{platform-domain}/{operator-slug}` — or the operator's own verified domain.

### The rules

- **HOS-4: everything renders server-side.** Blade, no build step, no hydration.
  The only thing missing without JavaScript is the date picker. A crawler runs no
  JavaScript, and neither does a visitor in a harbour on one bar of signal. This is
  asserted by the **absence of a `<script` tag**, which is the only checkable form
  of the claim.
- **HOS-5: EL and EN**, `hreflang` alternates both ways, a canonical per locale, and
  a visible language switch on every page. `?lang=` is the URL for a locale.
  `Accept-Language` is deliberately **not** consulted — a Greek operator's page
  opened by a German tourist should be the operator's default plus a switch, not a
  language the operator never wrote.
- **HOS-6: a page that is switched off returns 404** — byte-identical to an unknown
  slug. A friendly "this operator is unavailable" page tells the internet the
  operator exists, which is exactly what someone who turned their page off did not
  want.
- **HOS-9:** the operator's legal identity in the footer — legal name, address, ΑΦΜ,
  ΔΟΥ — in both locales.
- **HOS-10:** a read-only operator's page is served **in full**, with only the
  booking area replaced by a sentence naming who to contact.

### The Content-Security-Policy (HOS-8, SEC-10)

Built per response, and the useful assertions about it are about **absence**:

- no `unsafe-inline`, no `unsafe-eval` — the operator's colours reach the page
  through a `<style nonce="…">`, with the nonce minted in middleware and read back
  from the request, so the header and the element can never disagree;
- **Google Fonts appear only when the operator actually chose a Google font.** A
  policy that always allowed `fonts.gstatic.com` would make that requirement
  decorative and would be wrong for the majority who never pick one;
- **only the gateway the operator has connected** is named in `form-action`. An
  operator on Viva has no reason for a policy that admits anything else.

Plus `X-Content-Type-Options: nosniff`, a `Referrer-Policy`, `frame-ancestors 'none'`,
`object-src 'none'` — and **no `X-Robots-Tag`**, because unlike the token pages a
hosted page is exactly what the operator wants indexed.

### The trap this route created

`/{operator}` is a single path segment registered at the root. Registered on every
host it swallows `/app`, `/admin` and any probe route a test declares — it did, and
it broke eight of the tenant-resolution tests. The fix, and the rule:

```php
Route::domain((string) config('kaiki.tenancy.hosted_host'))
    ->middleware(['tenant', 'hosted.page', 'locale'])
    ->group(function () {
        // ...
    })->where('operator', '[a-z0-9][a-z0-9-]*');
```

**The host is the guard**, and there is a test asserting exactly that rather than
trusting route registration order.

### The Livewire trap

Livewire appends its script **and a CSRF token** to every HTML response from the web
middleware group once it has booted. A hosted page served by a worker that had
previously served a panel page therefore carried fifty kilobytes of JavaScript and a
CSRF token — on a page a CDN may cache. The middleware sets
`config(['livewire.inject_assets' => false])`. It only failed when the whole test
file ran, which is why the assertion lives in a full-page test rather than its own.

---

## 19. The widget

**Preact + TypeScript + Vite**, built to `public/widget/`, embedded with one script
tag and `data-` attribute mounts.

### Distribution (ADR-0011)

Versioned files behind a channel alias: `widget/v1/kaiki.js` is an alias that moves;
`widget/1.4.2/kaiki.js` is immutable. Patches propagate automatically, breakage
requires an explicit channel bump, and rollback is one alias change. Before the
alias moves, a **Playwright smoke run against the built artefact** must pass.

**Size gate: 80 KB gzipped**, enforced in CI. This is why it is Preact and not React.

### Rules

- The widget holds a **publishable key only** (`pk_`), and a `pk_` is refused
  anywhere it could write. CORS is checked against `api_keys.allowed_origins`.
- The widget **never computes a price**. Every total on screen came from
  `POST /price-quote`.
- **WGT-10:** the operator's font is loaded only when the operator configured one.
- **WGT-22:** the widget must work under a strict CSP — no inline styles, no eval.
- The widget is EL/EN with the same switch convention as the hosted pages.

### Mounts

`availability` (calendar + party picker), `booking` (the full flow), `product-list`,
`price-from`. Each is a separate mount so a WordPress page can use one without the
others.

---

## 20. The WordPress plugin

`packages/wordpress-plugin/kaiki-booking`.

- **Settings:** publishable key, optional secret key, operator slug, locale.
- **Shortcodes:** `[kaiki_booking]`, `[kaiki_availability]`, `[kaiki_products]`,
  `[kaiki_price_from]`.
- **Gutenberg blocks** and **Elementor widgets** wrapping the same shortcodes.
- **SEO CPT sync:** products are mirrored into a custom post type so the site's own
  SEO plugin can index them; the sync is the one place a secret key may be needed
  (ADR-0013), and product listing is deliberately readable with `pk_` so the standard
  installation never stores a secret.
- **Tests** run against a WordPress URL from `.env` (`KAIKI_WP_TEST_URL`), not
  `wp-env`.

---

## 21. Operations — the back office

- **Dashboard** — today's departures, seats sold, money taken, outstanding balances,
  guest-details gaps, unfulfilled on-request extras.
- **Vessel calendar** — departures, blocks, imported iCal events, conflicts.
- **Manual bookings** — the operator takes a booking by phone. Can override capacity
  (emitting `CapacityOverridden` with a reason into the audit log) but **never past
  the vessel's legal capacity**. Can apply an arbitrary adjustment line
  (`ManualBookingAdjustment`) with a reason.
- **Weather cancellation** — cancel a departure, notify every booking, and give each
  guest the refund-or-voucher choice with a deadline and a reminder.
- **Manifest exports** — per departure, PDF and CSV, with the guest details the
  ναυλοσύμφωνο and the port authority need.
- **iCal** — outbound feeds per vessel (tokenised, with a flag for whether guest
  names are included), inbound sources synced on an interval with failure counting
  and ETag support.
- **Outbound webhooks** — HMAC-signed, retried with backoff, ordered per booking,
  idempotent by delivery id.
- **CSV exports** — bookings, payments, guests.
- **Audit log (ADR-0025)** — a first-class tenant-scoped table, appended by queued
  listeners on domain events, never updated or deleted by application code. Scope is
  **the five named security actions, plus every soft delete and every operator
  override that carries a reason** — not every state change. A complete history was
  considered and rejected: most rows would never be read, and every extra row is
  another row naming a person that erasure has to account for.

---

## 22. Greek compliance

### Ναυλοσύμφωνο (charter agreement)

A versioned template rendered to PDF with a `fields_snapshot`, sent to the guest,
accepted with recorded IP, user agent, name and timestamp, then counter-signed by
the operator. **Once accepted, the evidence columns are frozen** by a model guard
that throws rather than allowing an update — an agreement whose evidence can be
edited is not evidence.

### myDATA

- **ΑΛΠ** (retail receipt) vs **ΤΠΥ** (service invoice) — ADR-0003, Option A:
  choose by whether the guest supplied a VAT number, with a light *"I need a company
  invoice"* toggle **on the confirmation page**, not in checkout. Keeping checkout
  minimal reduces the cancellation-and-reissue rate more than asking up front does.
- **Auto-issue defaults on**, with a 15-minute delay
  (`invoice_auto_issue`, `invoice_auto_issue_delay_minutes`). An operator who wants
  manual issuance turns it off and issues from the panel.
- **Invoice numbering (ADR-0022):** a per-tenant series, yearly reset, allocated
  **late** so gaps are rare, with `invoicing_mode: kaiki | external` as an escape
  hatch for an operator with an entrenched accounting workflow.
  **The gap question must be put to an accountant before M6** — this is not an
  engineering call.
- Retries with backoff; a Greek error dictionary mapping AADE codes to sentences an
  operator can act on; cancellation invoices; invoice PDF with the QR the receipt
  needs.
- **MYD-16:** myDATA activation is gated on **every sellable product having a VAT
  rate assigned.**

### VAT — what is deliberately *not* decided (ADR-0002, CAT-11b)

Passenger transport is *typically* 13% and other tourist services *typically* 24%,
and the actual figures are an accountant's answer, not a developer's. **No code,
config file, seeder default or test fixture may present a percentage as
authoritative.** Rates are seeded as `vat_rates` rows during onboarding, including
any reduced island regime. `NoHardcodedVatRateTest` enforces this.

### GDPR

- Guest **document type and number** are `encrypted`-cast (ADR-0012, Option A —
  envelope encryption was rejected as real work on the compliance-critical path with
  a catastrophic failure mode, and can be added later behind the same accessor).
- **Purge window per tenant: minimum 30 days, maximum 365, default 90** after
  departure. Only the document type and number are purged
  (`document_purged_at` records it). Name, date of birth and nationality stay,
  because accounting and dispute history need them, and that is stated separately in
  the privacy policy.
- Data export and delete per guest email.
- **Processor terms are presented and accepted during onboarding, with acceptance
  recorded** (GDR-7).
- The privacy policy, the DPA **and the onboarding wizard** must all state the
  configured window and the purge behaviour.

---

## 23. The SaaS layer

- Subscriptions, plans, trial, dunning — **blocked: no billing provider (ADR-0028)**.
- **Read-only mode** on lapse (§5).
- **Super-admin panel** at `/admin`: tenants, plans, impersonation (audited),
  feature flags via **Pennant**, the platform `vat_rates` table, and the
  custom-domain issuance log.
- **Sandbox mode per tenant (SAA-11):** gateway test keys, bookings flagged
  `is_test`, purged nightly. **It must be impossible to enable accidentally on a
  live tenant** — switching modes requires re-entering credentials and shows a
  persistent banner (PAY-11).
- **WooCommerce / YITH importer** for operators migrating off a WordPress booking
  plugin.
- A documentation site (tooling needs its own ADR first).

---

## 24. The onboarding wizard

> Το «configuration wizard» που βλέπει κάθε νέος operator την πρώτη φορά που
> μπαίνει στην πλατφόρμα. Είναι SAA-9/SAA-10 και είναι **resumable** — δεν είναι
> modal που κλειδώνει την οθόνη.

**Requirements:** SAA-9 (FIXED), SAA-10, SAA-11, GDR-7, CAT-11b, PAY-11.
**Milestone:** M7. **Panel:** `/app`, shown to any tenant whose checklist is
incomplete.

### The behaviour that matters more than the steps (SAA-10)

- **Resumable.** Progress is stored per tenant; closing the browser loses nothing.
- **Skippable step by step.** An operator who has no logo yet must still be able to
  reach step 4.
- **A persistent checklist, not a blocking modal.** A tenant who has not finished
  onboarding sees a dismissible progress card on the dashboard with the remaining
  steps. Blocking the panel until a form is complete is how an operator decides the
  software is hostile in the first five minutes.
- Every step is also reachable from its normal place in the panel afterwards — the
  wizard is a *path through* existing screens, not a parallel set of forms. This is
  the rule that stops the wizard rotting: there is no field that only the wizard can
  set.

### The eight steps

| # | Step | What it writes | Gate |
| --- | --- | --- | --- |
| 1 | **Legal details** | `tenants`: `legal_name`, `vat_number`, `tax_office`, `gemi_number`, address, `phone`, `email`, `timezone`, `default_locale`, `supported_locales`, `currency` | required before any invoice can issue |
| 2 | **Processor terms & data retention** | acceptance recorded (GDR-7); `guest_document_retention_days` (30–365, default 90) with the purge behaviour stated in plain language | **not skippable** |
| 3 | **First vessel** | `vessels`: name, type, registration number, `capacity_max`, home port, `turnaround_buffer_minutes` (+ a `ports` row if none exists) | required before a product |
| 4 | **First product** | `products`: mode (per-seat / per-vessel), title, duration, meeting point, min/max pax, cancellation policy, **`vat_rate_id`** | VAT rate required before myDATA can activate (MYD-16) |
| 5 | **Pricing** | a `season` (or the default all-year one), a `rate_plan`, `age_bands`, `rate_plan_prices`, the deposit rule | required before the product can be sold |
| 6 | **Payment gateway** | `integration_credentials` for Viva, `environment = test` first; a "verify" button that makes a real test call and sets `verified_at` | required before checkout |
| 7 | **Branding** | `brand_profiles`: logo, colours, font, button radius; live contrast warnings | skippable — the platform defaults are usable |
| 8 | **Embed & test booking** | shows the widget snippet, the hosted-page URL and the WordPress plugin download; then **walks the operator through one real booking in sandbox mode** end-to-end, flagged `is_test` | the completion event |

### Why step 8 is the last one and not a "nice to have"

The test booking is the only step that proves the previous seven are *coherent*. A
vessel with no capacity, a product with no rate plan, a gateway with the wrong
credentials — each of those passes its own form's validation and fails the moment a
guest tries to buy. Making the operator complete a sandbox booking before the
checklist clears turns seven silent misconfigurations into one visible error, in
front of the person who can fix it, before a single real guest ever sees the page.

### Completion

The checklist clears when steps 1–6 and 8 are done. Step 7 never blocks it. The
completion is an audited event, and it is what flips the operator out of the
"new tenant" dashboard state.

---

## 25. The public API

`/api/v1`, JSON, versioned in the path. The full OpenAPI 3.1 document lives in
`docs/api.md` and is drift-checked against the routes by `OpenApiDriftTest`
(ADR-0026: a PHP parser, in the same language as the tests, because the gap that
matters is required request-body fields and `$ref` resolution).

### Authentication (ADR-0013)

| Scheme | Prefix | Used by | May |
| --- | --- | --- | --- |
| Publishable | `pk_` | widget, WordPress plugin, any browser | **read only**, CORS-checked against `allowed_origins` |
| Secret | `sk_` | server-to-server | read and write |
| Guest token | — | the four token pages | act on exactly one booking |

**A `pk_` is refused on every write.** The first place this is enforced is
`AuthenticateGuestToken`, and it is tested there explicitly.

Keys carry scopes, an environment (`live`/`test`), an expiry and a revocation
timestamp. `secret_hash` is a hash; `last_four` is what the panel shows.

### Endpoints

| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| GET | `/health` | key | |
| GET | `/branding` | `pk_` | the widget's first call |
| GET | `/products` | `pk_` | list |
| GET | `/products/{uuid}` | `pk_` | |
| GET | `/availability` | `pk_` | date range + party |
| POST | `/price-quote` | `pk_` | **the only price calculator** |
| GET | `/vouchers/{code}` | `pk_` | validity check |
| POST | `/enquiries` | `pk_` | the one public write that needs no booking |
| POST | `/bookings` | `sk_` | idempotency **required** |
| GET | `/bookings/{uuid}` | `sk_` / guest token | |
| POST | `/bookings/{uuid}/checkout` | `sk_` / guest token | mints a gateway session |
| POST | `/bookings/{uuid}/cancel` | `sk_` / guest token | dry-run then commit |

### Cross-cutting

- **Locale** — `Accept-Language` or `?lang=`; an unsupported locale is
  `400 unsupported_locale`. (The hosted pages fall back silently instead: an
  integrator benefits from being told, a tourist does not.)
- **Money** — integer cents plus a currency, never a formatted string.
- **Timestamps** — ISO 8601 UTC, plus the local trio where local meaning matters.
- **Idempotency** — `Idempotency-Key` on the writes; body hash over a recursively
  sorted payload.
- **Pagination** — cursor-based.
- **Rate limits** — per key, with headers.
- **CORS** — origins from the key, not from config.
- **Errors** — one envelope, a stable machine code, an EL and an EN message.
- **Test mode** — a `test` key produces `is_test` bookings.

### Outbound webhooks

Events: booking created / confirmed / cancelled, payment succeeded / refunded,
departure cancelled, guest details completed. HMAC-signed with a per-tenant secret,
retried with backoff, idempotent by delivery id, ordered per booking.

---

## 26. Branding and the design system

### The six brand decisions settled on 2026-09-04

1. **Hull teal** — primary `#0B4F4A`, deep `#063733`, accent (rust) `#B5511F`, ink
   `#16211F`.
2. **A visible EL/EN switch on every guest surface**, with the active one marked for
   a screen reader (`aria-current="true"`).
3. **Inter only.** No serif display face.
4. **Guest surfaces are light only.** Light/dark is a dashboard concern; the
   operator picked their colours against white.
5. **Never `text-transform: uppercase` on Greek.** Greek capitals drop their accents
   and browsers disagree about the final sigma. Small labels use letter-spacing and
   weight in normal case. This is asserted by a test that lowercases the whole
   response body and looks for the word.
6. **«powered by Kaiki» on every hosted page**, custom domains included, rendered
   **from a config flag** so a white-label tier is a config change rather than a
   template edit.

**Radii:** 10px on controls, 14px on cards.

**Open item:** the hull-teal palette is settled but **not yet applied in code.**
`config('kaiki.branding.defaults')` and the `brand_profiles` column defaults must
change **together**, because `BrandProfileDefaultsTest` asserts they agree.

### Per-operator branding

`brand_profiles` carries logos, five colours, font family and source
(`system` | `google`), button radius, widget theme, email footer, social links and
custom CSS. Contrast is checked and warnings are stored, not enforced — an operator
who insists on a low-contrast brand gets told, not blocked.

Colours reach a hosted page through a nonce'd `<style>` block; two operators are
rendered from the same template with different colours, and there is a test that
asserts operator A's page contains none of operator B's hex.

---

## 27. Internationalisation

- **EL and EN**, everywhere a guest can see.
- `lang/el` and `lang/en` are kept in **exact key parity** by `LangKeyParityTest`.
  A key in one and not the other fails the build.
- **Enum labels** must exist for every case in both locales —
  `EnumLabelCoverageTest`.
- Model translations are JSON columns with sort/search companions (§4).
- Notifications go out in `bookings.locale`.
- **I18N-2** is the uppercase rule of §26.
- **I18N-5:** the guest's locale is `?lang=` then the operator's default. Never
  `Accept-Language` on a guest page.

---

## 28. Security

- **Tenant isolation** is the top risk and has a per-model test plus a security
  review before every milestone close.
- **Credentials** are `encrypted`-cast; `CredentialLeakScanner` fails the build if a
  credential-shaped value appears in a log, an exception message, an API resource or
  a Blade template.
- **Guest documents** encrypted; purged on the retention window.
- **Rate limiting** on the API, the token pages and the custom-domain `ask`
  endpoint.
- **A `pk_` can never write.**
- **Webhook signatures** verified before processing, with the verdict recorded.
- **CSP** with no `unsafe-inline` on the hosted pages, and a widget that works under
  a strict policy.
- **The QR payload is `ticket_code`**, so a photographed ticket is not a
  cancellation link.
- **The audit log** is append-only.
- `composer audit` and `npm audit` run in CI.

---

## 29. Testing and the architecture gates

**Pest 3.** Groups: `fast` (the default local run), `mysql`, `chromium`, `external`.
Excluding runs one `--exclude-group` per group.

**Coverage:** `app/Domain` at or above **80%** before a milestone closes.

### The architecture gates

These are tests that assert things about the *codebase*, not about behaviour. Each
exists because the thing it checks is invisible in review and catastrophic in
production.

| Gate | What it refuses |
| --- | --- |
| `LockDisciplineTest` | a lock taken out of the AVL-45 order; an external call inside a locked transaction (AVL-46) |
| `NoDirectRedisTest` | any `Redis::` or `RedisStore` reference in domain code |
| `NoHardcodedVatRateTest` | a VAT percentage in code, config, seeder or fixture |
| `CredentialLeakScanner` | a credential reaching a log, an exception, a resource or a view |
| `PolicyCoverageTest` | a Filament resource with no Policy |
| `LangKeyParityTest` | a translation key in one locale and not the other |
| `EnumLabelCoverageTest` | an enum case with no label in both locales |
| `OpenApiDriftTest` | a route the OpenAPI document does not describe, or vice versa |
| `CiGatesTest` | a CI workflow that no longer runs a required command; a stale schema snapshot |
| `BrandProfileDefaultsTest` | config defaults and column defaults that disagree |
| per-model isolation tests | a model that can read another tenant's rows |

**When a gate fires, satisfy it properly.** Every one of these has been hit by real
new code, and every one was answered by fixing the source — never by an allow-list
entry that hides the case it was built to catch. (Two allow-list entries do exist and
are justified in place: the literal word `email`, and vendor wordmarks.)

---

## 30. CI

Nine jobs, cut down from sixteen with nothing dropped:

| Job | Runs |
| --- | --- |
| `static-checks` | Pint (check mode), PHPStan level 6 |
| `test-suite` | the full Pest suite on SQLite |
| `test-mysql` | the full suite on **MySQL 8 + Redis**, including the `mysql` group |
| `runtime-gates` | the architecture gates and the timezone matrix |
| `pdf-chromium` | the `chromium` group — e-ticket and invoice rendering |
| `node-checks` | widget build, type-check, **the 80 KB gzipped size gate**, `npm audit` |
| `security-audit` | `composer audit`, the credential scanner |
| `migrate-from-zero` | migrate on MySQL from empty, diff against the committed schema snapshot |
| `ci-passed` | the single required check every other job reports into |

`CiGatesTest` asserts the workflow still runs the **commands** the gates need — by
command, not by job id, so renaming a job does not silently drop a check.

---

## 31. The decision register (26 ADRs)

Each line is the decision, not the debate.

| # | Question | Decision |
| --- | --- | --- |
| 0001 | Tenancy mode | **Single database with `tenant_id`.** Isolation paid down with a mandatory trait, per-model tests, and a security review per milestone. |
| 0002 | Where the VAT rate lives | **A platform `vat_rates` table** with validity dates and the AADE `vatCategory`, per-product and per-extra FKs, snapshotted per line. The rates themselves are an accountant's data entry. |
| 0003 | ΑΛΠ vs ΤΠΥ, auto vs manual | **Auto-issue on by default**, 15-minute delay, with a light "I need a company invoice" toggle on the confirmation page rather than in checkout. |
| 0004 | Gateway credentials + deposit model | **Encrypted per-operator credentials in the database** (no external secret store) + **two separate checkout sessions** for deposit and balance. |
| 0005 | Seat holds without Redis locally | **The hold is data** (`seats_held` + `hold_expires_at`); `Cache::lock` is only a mutex. No domain code touches Redis directly. |
| 0006 | Overselling | **`lockForUpdate()` + a conditional `UPDATE … WHERE capacity - sold - held >= n`.** Portable, so SQLite exercises it; a parallel `@group mysql` test is a required check. |
| 0007 | Booking reference | **`KAI-XXXXX`**, unambiguous alphabet, unique per tenant, minted at draft, never reused. |
| 0008 | Translatable fields | **JSON columns**, with a hard rule that no query sorts or filters on a JSON path; observer-maintained `*_sort_{locale}` and `search_index` companions instead. |
| 0009 | Departure horizon | **400 days**, config-driven; **additive-only** generation plus a reconciliation list; nightly at 03:15 tenant time and immediately on rule change. |
| 0010 | Custom domains and TLS | **Caddy on-demand TLS with an `ask` endpoint** answering from `tenant_domains`; DNS verification required; rate-limited; every issuance logged. |
| 0011 | Widget distribution | **Versioned files behind a channel alias**, mandatory Playwright smoke against the built artefact before the alias moves, 80 KB gzipped gate in CI. |
| 0012 | Guest documents | **`encrypted` cast**, retention per tenant 30–365 days, default 90; purge **document type and number only**. |
| 0013 | API keys | **`pk_` for everything the plugin renders**; product listing readable with `pk_` so a standard install never stores a secret; `sk_` only for webhooks and cache-bust. |
| 0014 | PHP version | **8.4** — parity with the development machine beat 8.3 compatibility that nothing needed. |
| 0015 | Local stack | **SQLite and no Docker locally**, with the divergence named and managed by a required MySQL+Redis CI job, non-empty group assertions, no engine-specific SQL, and a schema-snapshot job. |
| 0016 | DST | **Refuse a non-existent local time**; ambiguous resolves to the **first occurrence, summer offset**, flagged `dst_ambiguous`; duration arithmetic stays absolute. |
| 0017 | Voucher remainder and restoration | **Voucher value stays voucher value, cash stays cash**, plus a `voucher_redemptions` ledger so `remaining_cents` is always reconstructible. |
| 0018 | Overdue balances | **Nothing is cancelled automatically in v1**; the behaviour sits behind a Pennant flag, off. |
| 0019 | Packages beyond the fixed stack | **An approved shortlist**, each installed only when first used and cited in the PR that adds it; anything else still needs an ADR. |
| 0020 | Multi-tenant users | **No.** One user, one tenant — roles kept in a shape that does not have to be unpicked later. |
| 0021 | Image storage | **Plain path columns**, no polymorphic media table. Revisit only if gallery management becomes an operator complaint. |
| 0022 | Invoice numbering | **Per-tenant series, yearly reset, late allocation**, with `invoicing_mode: kaiki \| external` as an escape hatch. **The gap policy needs an accountant before M6.** |
| 0023 | One bookable-window table? | **No — keep departures plus per-vessel windows**, contained behind one port; pull the NFR-1 benchmark forward to the end of M2 so this is revisited while it is cheap. |
| 0024 | 2FA | **Fortify**, columns now, enrolment in M7 alongside impersonation. |
| 0025 | Audit log | **A first-class append-only table**, queued listeners on domain events; scope is the five named security actions **plus every soft delete and every reasoned override** — not every state change. |
| 0026 | OpenAPI validation | **A PHP parser in the test suite**, not Spectral — the drift that matters is required request-body fields and `$ref` resolution, and a red build should be investigated in one language. |

---

## 32. Contradictions found and how they were resolved

Four places where the source documents contradicted themselves. Each was
**reconciled in the documents**, not papered over in code.

1. **CXL-3.3 vs ADR-0017** — what happens to a voucher when the booking that used it
   is cancelled. Resolved in favour of the ledger: a reversal row, never an edit to
   the voucher's original amount.
2. **BKG-26 vs §2.5** — whether a quote holds capacity. Resolved: **it does not**,
   and the panel says so, because quotes that hold turn a sales pipeline into a
   denial-of-service on the calendar.
3. **The QR payload**, specified three different ways in three places
   (`manage_token`, booking `uuid`, reference). Resolved: **`ticket_code`**, a
   dedicated per-guest value that grants nothing but check-in.
4. **BKG-32's capacity rule contradicted itself** on whether an operator override
   could exceed the vessel's legal capacity. Resolved: an override may exceed the
   *product's selling* capacity with a reason, and may **never** exceed the vessel's
   legal capacity. Marked RESOLVED in the spec.

Two latent defects were found by the scanners rather than by review:

- the `chromium` group had **no CI job running it** — every Chromium test was
  silently skipped;
- `pax_breakdown` carried `age_band_id` — an auto-increment id — into
  `GET /bookings/{uuid}`, a CNV-8 breach. Fixed to `age_band_uuid` plus `label`,
  `min_age`, `max_age`, `unit_price_cents`, `total_cents`.

---

## 33. Traps — the things that cost hours

Written down because each of these looked like something else for a while.

**`phpunit.xml`'s `<env>` only applies when the variable is unset.** The MySQL CI
job's environment wins. A test that needs inline execution must set
`config(['queue.default' => 'sync'])` itself.

**`Event::fake()` with no arguments breaks `BelongsToTenant`.** It silences Eloquent
model events too, so `tenant_id` never gets stamped and rows land unscoped. Always
`Event::fake([SpecificEvent::class])`.

**`$this` is not available inside a Pest closure** at static-analysis time — it is a
`TestCall`. `$this->fail()` is a PHPStan `method.notFound`. Use caught-exception
assertions and static helpers that take no test case.

**`"\1"` in a double-quoted PHP string is `chr(1)`, not a regex backreference.** A
lint rule built this way matched nothing and passed silently for weeks. Use two
literal patterns in a loop instead.

**`withHeader('Host', …)` does not affect route-domain matching.** Use an absolute
URL in the test.

**A helper function declared at the top of two Pest files is a fatal redeclaration.**
Put shared test helpers in a class under `tests/Support/`.

**Livewire injects its script into every HTML response once booted** — see §18.

**`config('app.timezone')` is UTC.** Any guest-facing formatter must resolve the
*tenant's* zone, or Greek departures print three hours early.

**A single-segment catch-all route registered at the root swallows everything.** Scope
it to a host and constrain the parameter.

---

## 34. Milestones: what is built, what is next

| Milestone | Scope | State |
| --- | --- | --- |
| **M0** Foundation | tenancy, resolution, CI, Filament panels, roles, API keys, OpenAPI skeleton | ✅ complete |
| **M1** Catalogue & availability | vessels, ports, products, age bands, seasons, rate plans, extras, policies, schedule rules, departures, blocks, the availability service, the public read API | ✅ complete |
| **M2** Booking & payments | booking aggregate, holds, pricing snapshot, vouchers, gateway contract, Viva, webhooks, deposit/balance, quotes, enquiries, the four token pages, notifications, e-ticket, check-in | ✅ complete (11 issues, #79–#89) |
| **M3** Widget & hosted pages | hosted pages first, then the widget | 🔵 in progress |
| **M4** WordPress plugin | | ⬜ |
| **M5** Operations | dashboard, calendar, manual bookings, weather workflow, manifests, iCal, webhooks, exports | ⬜ |
| **M6** Greek compliance | ναυλοσύμφωνο, myDATA, invoices, GDPR tooling | ⬜ |
| **M7** SaaS | billing (**provider not chosen**), super-admin, **the onboarding wizard**, sandbox, importer, docs site | ⬜ |
| **M8** Launch hardening | load test, security review, Sentry + Pulse, backups and a restore drill, status page, legal pages | ⬜ |

**Nothing closes** until: acceptance criteria pass, `security-reviewer` has run,
`app/Domain` coverage ≥ 80%, the spec / data-model / API docs reflect any contract
change, and there is a `CHANGELOG.md` entry.

### M3, issue by issue

Order settled with the product owner: **hosted pages first, widget second.**

| # | Issue | State |
| --- | --- | --- |
| 101 | Hosted page shell — routing, CSP, locale, branding, legal page | ✅ built, 361 tests green |
| 102 | Editable operator home page (block system) | next |
| 103 | FAQ | |
| 104 | Product pages | |
| 105 | Catalogue search | |
| 106–111 | The widget: shell, availability mount, booking mount, price-from, CSP hardening, Playwright end-to-end | |

M3 also carries three scope additions agreed after the milestone was written: the
**FAQ**, the **editable operator home page**, and **catalogue search**.

### Open items to carry forward

1. **The hull-teal palette is not applied in code.** `config('kaiki.branding.defaults')`
   and the `brand_profiles` column defaults must change together
   (`BrandProfileDefaultsTest`).
2. **The VAT gap policy needs an accountant** before M6 (ADR-0022).
3. **The NFR-1 availability benchmark** should be pulled forward to the end of M2
   per ADR-0023, so the one-table-vs-two decision is revisited while it is cheap.
4. **`docs/api.md` §10.2** still says "spectral lint (or equivalent)" and should name
   the equivalent chosen in ADR-0026.
5. **GitHub Actions is blocked** on the account (failed payment / spending limit).
   Work continues locally; branches push when the limit resets.

---

## 35. Running it locally

```bash
export PATH="/c/Users/Mike/php84:$PATH"
cd /c/Users/Mike/kaiki
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve                     # http://127.0.0.1:8000
```

`.env` defaults to the local stack: `DB_CONNECTION=sqlite`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `MAIL_MAILER=log`,
`APP_TIMEZONE=UTC`, plus placeholders for `KAIKI_WP_TEST_URL` and gateway sandbox
credentials.

### The seeded demo

`DemoBookableSeeder` builds a complete bookable chain for the demo operator
**Aegean Blue**: a tiered cancellation policy, a year-spanning season, a per-seat
sunset cruise and a per-vessel charter, three age bands, a rate plan with a 30%
deposit, and a daily schedule rule that generates 401 departures. Without it the
panel looks finished and nothing can actually be booked.

### Accounts (all password `password`)

| Email | Role | Panel |
| --- | --- | --- |
| `maria@aegean-blue.example` | owner | `/app` |
| `giorgos@aegean-blue.example` | manager | `/app` |
| `nikos@aegean-blue.example` | crew | `/app` |
| `elena@ionian-sunset.example` | owner | `/app` |
| `andreas@ionian-sunset.example` | crew | `/app` |
| `admin@kaiki.example` | super-admin | `/admin` only |

### API keys (demo)

```
pk_test_4Cw0wfHovd3R4GleXSWm9HPYMl4UwOK9   # needs an Origin header
sk_test_Fz6JfyaQX82jH8V0a4DxPtINkXhLugo9
```

Example booking `KAI-HK4JW`, manage token
`5O1tklHktcv3jqiicgCqbVpZkZNWRhKD44WOIwRj`.

---

## 36. If you had to rebuild from zero

The order below is not arbitrary — each step is blocked by the one above it, mostly
because of SQLite's inability to add a foreign key after the fact.

1. **Settle the ADRs first.** §31 is the whole list; nine of them block M0 and M1
   and are cheap to accept and expensive to reverse.
2. **Scaffold Laravel 12 on PHP 8.4.** Pint, PHPStan level 6 + Larastan, Pest 3, the
   four groups, and `composer test` / `test:fast` / `test:mysql`.
3. **Tenancy before anything else.** `BelongsToTenant`, the resolution chain in the
   order of §5, the per-model isolation test, and the two Filament panels.
4. **The migrations, in the order of §7** — and land any table that will later be
   referenced by a foreign key, even if its feature is milestones away.
5. **The architecture gates of §29 next, not last.** Every one of them caught a real
   defect. A gate added after the code it governs is a gate you write around.
6. **The catalogue**, then **the availability engine**, then **pricing** — in that
   order, because pricing needs a departure and a departure needs a rule.
7. **The oversell test on MySQL before the booking flow.** If it does not fail
   before the guard exists, it is not testing the guard.
8. **The booking aggregate**, holds, expiry, then payments and webhooks.
9. **The guest surfaces**, then the hosted pages, then the widget.
10. **Compliance and SaaS last**, because both depend on a booking that already
    behaves correctly — and the onboarding wizard of §24 is genuinely last, since it
    is a guided path through screens that must already exist.

**The three things worth being stubborn about**, if everything else is negotiable:
money is integer cents and never recomputed; the oversell guard is a locked
transaction *and* a conditional update, proven by a parallel test; and every rule
that exists because of Greek law — VAT rates as data, the ναυλοσύμφωνο's frozen
evidence, myDATA's invoice numbering — is enforced by a test rather than by a
convention someone has to remember.

---

*Kaiki — booking engine for Greek boat operators. This document supersedes and
contains `docs/BRIEF.md`, `docs/spec.md`, `docs/data-model.md`, `docs/api.md`,
`docs/ci.md`, `docs/adr/*` and `docs/BUILD-LOG.md`.*
