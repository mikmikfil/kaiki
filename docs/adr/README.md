# Architecture Decision Records

Every decision marked **DECIDE** in `docs/BRIEF.md`, plus every genuinely open question found while turning the brief into `docs/spec.md`, is recorded here as a numbered ADR. Each ADR states the context, two or three options with trade-offs, a recommendation, and the consequences of accepting it.

**All 23 ADRs were accepted on 2026-08-28.** Every `**DECIDE — see ADR-000N**` marker in `docs/spec.md` has been resolved into a concrete requirement carrying a `(per ADR-000N, Option X)` citation; the marker no longer appears in any document. For any *future* fork, no agent may implement against a recommendation until the product owner marks a new ADR `Accepted` (or `Superseded`) and updates this table.

> **One caveat:** ADR-0022 (invoice numbering) is accepted as an *engineering* shape so M1 is not blocked. The gap policy itself still needs an accountant's sign-off before M6 issuance ships. ADR-0002 is the same story for the VAT *rate* — the resolution mechanism is settled, the rate is not. Both caveats are recorded in `docs/spec.md` §16.3 ("What is still open after the ADR round") and repeated inline at MYD-4, CAT-11b and MYD-6a. A third item is a *revisit trigger* rather than an open question: ADR-0023 is reopened if the NFR-1 availability benchmark misses 150 ms p95 at the close of M2.

## How to accept an ADR
1. Choose an option (it need not be the recommendation).
2. Edit the ADR: set `Status: **Accepted (Option X)**`, add `Decided: YYYY-MM-DD` and one or two sentences of rationale if it differs from the recommendation.
3. Update the Status column below.
4. Resolve the matching requirement in `docs/spec.md` (and `docs/data-model.md` / `docs/api.md` where the schema or contract moves) into a concrete, implementable statement carrying a `(per ADR-000N, Option X)` citation, and comment on any GitHub issue that named the ADR as a blocker.

## Index

| # | Title | Status | Recommendation (summary) | Blocks |
|---|---|---|---|---|
| [0001](0001-tenancy-mode.md) | Tenancy mode: single database with `tenant_id` vs database-per-tenant | **Accepted (A)** | **A** — single database, mandatory `BelongsToTenant`, isolation tests as a CI gate | M0 |
| [0002](0002-vat-rate-resolution.md) | Where the VAT rate lives and how it is resolved for myDATA | **Accepted (A)** | **A** — `vat_rates` reference table, `vat_rate_id` on Product/Extra, snapshotted per line | M1 (schema), M6 (issuance) |
| [0003](0003-invoice-type-and-issuance.md) | Invoice type (ΑΛΠ vs ΤΠΥ) selection and auto vs manual issuance | **Accepted (A)** | **A** — derive from presence of validated ΑΦΜ; auto-issue on, 15-minute delay | M2 (forms), M6 |
| [0004](0004-payment-credentials-and-deposit-model.md) | Operator gateway credential storage and the deposit/balance model | **Accepted (A + D)** | **A + D** — encrypted columns per tenant/gateway/mode; two independent checkout sessions | M2 |
| [0005](0005-seat-hold-mechanism.md) | Seat-hold mechanism with no local Redis | **Accepted (A)** | **A** — `Cache::lock` mutex plus durable `hold_expires_at` and `seats_held` in the database | M2 |
| [0006](0006-overselling-concurrency-strategy.md) | Overselling concurrency across SQLite and MySQL | **Accepted (A)** | **A** — `lockForUpdate()` plus a portable conditional counter update; parallel test CI-only | M2 |
| [0007](0007-booking-reference-format.md) | Human booking reference format and collision strategy | **Accepted (A)** | **A** — `KAI-XXXXX`, 31-symbol unambiguous alphabet, unique per tenant, retry on collision | M2 |
| [0008](0008-translatable-fields-storage.md) | Translatable fields: JSON columns vs a translations table | **Accepted (A)** | **A** — `spatie/laravel-translatable` JSON plus observer-maintained sort/search columns | M1 |
| [0009](0009-departure-generation-horizon.md) | Departure generation horizon, cadence and rule-change semantics | **Accepted (A)** | **A** — rolling 400-day horizon, nightly plus on save, additive-only with reconciliation | M1 |
| [0010](0010-custom-domain-resolution-and-tls.md) | Custom-domain tenant resolution and TLS issuance | **Accepted (A)** | **A** — `tenant_domains` table gating Caddy on-demand TLS via an `ask` endpoint | M0 (middleware), M3 |
| [0011](0011-widget-distribution-and-versioning.md) | Widget distribution, versioning and cache strategy | **Accepted (A)** | **A** — `/widget/v1/` channel alias over immutable versioned builds | M3, constrains M4 |
| [0012](0012-guest-document-encryption-and-retention.md) | Guest document encryption and GDPR retention window | **Accepted (A)** | **A** — Laravel `encrypted` cast; retention configurable 30–365 days, default 90 | M2 (schema), M6 |
| [0013](0013-api-key-model.md) | API key model: publishable vs secret scopes, rotation, WordPress plugin | **Accepted (A)** | **A** — fixed capability sets plus **dot-form** scopes; the plugin holds only a publishable key for everything it renders (see the ADR's "Amendments after acceptance": vocabulary normalised to dot form, and the `sk_`-on-`/sync/products` tension flagged as a product-owner veto point) | M0 |
| [0014](0014-php-version-target.md) | PHP version target: brief says 8.3, dev machine runs 8.4 | **Accepted (B)** | **B** if the 8.3 line can move (full parity), otherwise **A** (`^8.3` with an 8.3 CI job) | M0 |
| [0015](0015-local-development-stack.md) | Local SQLite/no-Docker stack vs the CI and production MySQL+Redis stack | **Accepted (A)** | **A** — three-tier split with an explicit parity contract and tagged CI-only test groups | M0 |
| [0016](0016-dst-invalid-and-ambiguous-local-times.md) | Non-existent and ambiguous local departure times across DST | **Accepted (A)** | **A** — skip and flag non-existent times; first occurrence for ambiguous ones | M1 |
| [0017](0017-voucher-remainder-and-refund-restoration.md) | Voucher exceeding the total, and voucher restoration on cancellation | **Accepted (A + D)** | **A + D** — keep the surplus on the voucher; restore pro-rata, never convert to cash | M2 |
| [0018](0018-balance-due-policy.md) | Balance due date and unpaid-balance handling | **Accepted (A)** | **A** — due N days before departure (default 14), reminders, no automatic cancellation | M2 |
| [0019](0019-packages-beyond-section-3.md) | Policy for packages the §3 stack table does not list | **Accepted (A)** | **A** — batch-approve a named shortlist; everything else still needs its own ADR | M2 onward |
| [0020](0020-multi-tenant-user-membership.md) | Can a user belong to more than one tenant? | **Accepted (C)** | **C** — one tenant per user now, roles already in a separate table so the pivot is a later data migration | M0 |
| [0021](0021-image-and-file-storage.md) | Image and file storage strategy | **Accepted (A)** | **A** — plain path columns + an upload Action + `intervention/image`; no polymorphic media table | M1 |
| [0022](0022-invoice-numbering-scope.md) | Invoice numbering scope and gap policy | **Accepted (A + C (per-tenant external mode))** | **A** default (per tenant/series/year, allocated at send, gaps logged) **plus** C as a per-tenant `external` mode. **Needs an accountant.** | M1 (schema), M6 |
| [0023](0023-unified-bookable-windows.md) | One `bookable_windows` table vs departures + per-vessel windows | **Accepted (C)** | **C** — keep the brief's two shapes, hide the union behind one `VesselCalendar` port, pull the NFR-1 benchmark forward to the M2 close | Locks in at M2 |

## Future ADRs expected (not yet written)
| Topic | Why deferred | Needed before |
|---|---|---|
| Roles beyond `owner`/`manager`/`crew` (`spatie/laravel-permission`) | The three fixed roles in §4 are sufficient for the MVP; ADR-0019 approves the package conditionally | When a customer asks for a fourth role |
| Docs-site generator for the EL/EN operator guide (§11) | Wider blast radius than the §3 gaps; no urgency | M7 |
| OTA `Channel` implementations beyond `ical` (§2) | Explicitly out of MVP scope; only the interface is designed | Post-MVP |
| Operator-uploaded ναυλοσύμφωνο PDF templates with placeholders (§10, flag off) | Feature-flagged off for MVP | Post-MVP |
| Search backend if catalogue search outgrows `LIKE` on the denormalised index (ADR-0008) | Premature until real data volumes exist | Post-MVP |
