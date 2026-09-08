# Kaiki — booking engine for boat operators

## What this is
Multi-tenant Laravel SaaS (operator back-office + public API) + Preact embeddable widget + WordPress plugin, for Greek cruise, day-trip and private-charter operators.

Read `docs/spec.md` before any task; it is the contract. `docs/BRIEF.md` is the original brief and is **historical** — where the two differ, the spec wins, because the spec incorporates 23 accepted ADRs and the real environment constraints. `docs/data-model.md` is authoritative on schema and column meaning. `docs/api.md` is authoritative on the public API.

All 23 ADRs in `docs/adr/` were **accepted on 2026-08-28**. Implement against the accepted option. A new **DECIDE** goes to `docs/adr/` as a numbered ADR and **stops for a human** — never decide it yourself.

## Stack
Laravel 12 / **PHP 8.4** / MySQL 8 / Redis / Horizon / Filament v3 / `stancl/tenancy` single-DB / Preact + TS widget (Shadow DOM) / WP plugin in `packages/wordpress-plugin` / Browsershot PDFs / Postmark / Viva Wallet (operator-owned).

> Viva is the **only** payment gateway and there is **no platform billing
> provider** — both removed by the product owner, ADR-0026. Brief §11's
> "Stripe via Laravel Cashier" is superseded. M7 subscriptions are blocked
> until a provider is chosen.

> PHP 8.4, not the 8.3 in brief §3 — amended by ADR-0014.

## Environment — three tiers, and they are not the same
| | Local (Windows + PowerShell) | CI (GitHub Actions) | Production (Hetzner) |
|---|---|---|---|
| DB | **SQLite** `database/database.sqlite` | MySQL 8 | MySQL 8 |
| Queue / cache | database / file drivers | database + Redis job | Redis + Horizon |
| Runner | native PHP 8.4 + Composer | ubuntu-latest | Docker Compose |

**No Docker, no Laragon, no local MySQL, no local Redis, no `make`, no bash scripts, no `wp-env`.** Entry points are `composer` and `npm` scripts only. Docker Compose exists for the Hetzner server **only**.

Things that can only be verified in CI, and must say so in their test plan: the overselling concurrency test (`SELECT … FOR UPDATE` is a no-op on SQLite), Horizon behaviour, `app/Domain` coverage (PCOV), PDF/email snapshots if no local Chrome path is configured.

Every migration must run on **both** SQLite and MySQL 8: no MySQL `ENUM`, no generated columns, no `FULLTEXT`, no JSON functional indexes. SQLite cannot add a foreign key to an existing table — get tables right the first time (see `docs/data-model.md` §6 for migration ordering).

## Conventions
- **Money:** integer cents (`*_cents`), `brick/money` for arithmetic. Never floats, never decimals.
- **Time:** stored UTC. Departures also store `local_date`, `local_time`, `starts_at_utc`, `ends_at_utc`. Timezone per tenant, default `Europe/Athens`. Non-existent local times (spring forward) are skipped and flagged; ambiguous ones take the first occurrence (ADR-0016).
- **Domain logic** lives in `app/Domain/<Context>/Actions` as invokable classes with `spatie/laravel-data` DTOs. Controllers and Filament resources only call Actions.
- **Every tenant-owned model** uses `BelongsToTenant`. Public identifiers are UUIDs — `id` and `tenant_id` are never exposed through the API.
- **Enums** are string columns backed by PHP enums in `app/Enums`, never MySQL `ENUM`.
- **All strings EL + EN** from the first commit. Translatable model fields use `spatie/laravel-translatable` JSON columns with observer-maintained sort/search companions (ADR-0008).
- **External calls** are queued, idempotent, retried with backoff, structured-logged, and surface a human-readable **Greek** error to the operator.
- **Events** for every state change; listeners are queued.
- Feature flags via `laravel-pennant` for anything the spec marks "later".
- Commit style: `area(scope): summary` — areas: `core`, `availability`, `payments`, `compliance`, `widget`, `wp`, `ops`, `saas`, `docs`, `infra`.

## Engine invariants — get these wrong and the product is broken
- `seats_sold` and `seats_held` are **disjoint and additive**: `available = capacity − seats_sold − seats_held`. `seats_sold` is committed pax (`pending_payment`, `confirmed`, `checked_in`, `completed`); `seats_held` is `draft` bookings with an unexpired hold. Pax move from held to sold at **checkout start**, not at the payment webhook.
- Confirmation runs inside `DB::transaction()` with `lockForUpdate()` **and** a portable conditional counter update. The two-parallel-confirmations test must always pass in CI (ADR-0006).
- Price is computed **server-side only** and frozen into `price_snapshot`. The widget never sends or computes a price.
- Refunds are computed from the **policy snapshot taken at booking time**, never the current policy.
- Occupancy is queried only through `App\Domain\Availability\VesselCalendar` (ADR-0023) — an architecture test enforces it.

## Approved packages
Brief §3 plus the ADR-0019 shortlist: `bacon/bacon-qr-code`, `spatie/icalendar-generator`, `sabre/vobject`, `league/csv`, `propaganistas/laravel-phone`, `intervention/image`, MJML as an npm dev dependency with compiled HTML committed. Install each only when first used, and cite the usage in the PR. **Anything else needs its own ADR** — that is a hard stop, not a judgement call.

## Commands
`composer setup` · `composer test` · `composer test:fast` · `composer stan` · `composer lint` · `composer lint:fix` · `npm -w packages/widget run build` · `npm -w packages/wordpress-plugin run test:e2e`

## Workflow
One issue per session: `/issue N` → plan → approve → implement → `/finish` → `/review` → commit → `/clear`. Never let a session span two issues. Every issue closes with tests green, `docs/` updated if a contract changed, and a `CHANGELOG.md` entry.

## Delegation
Planning and issues → `architect`. PHP → `laravel-backend`. Availability and pricing (spec §5) → `availability-engine`. Gateways, notifications, iCal → `payments-integrations`. myDATA, PDFs, manifest → `greek-compliance`. Widget → `widget-frontend`. Plugin → `wordpress-plugin`. Tests → `qa-tester` (**always** after implementation). Reviews → `security-reviewer` + `architect` (before closing a milestone). Infra → `devops`. Docs → `docs-writer`.

## Do not
- Compute prices or availability anywhere except `app/Domain` — never in the widget or the plugin.
- Hardcode VAT rates, brand colours, or gateway endpoints. VAT resolves through the `vat_rates` table (ADR-0002); the **rate itself is still an accountant's decision**.
- Touch the WooCommerce cart or checkout from the plugin, or enqueue global CSS outside a scoped `.kaiki-` namespace.
- Log or export passport/document numbers unless an explicit operator action requests it. They are `encrypted` cast, never indexed, purged after the retention window (default 90 days, ADR-0012).
- Expose a secret key (`sk_`) to a browser, or let a publishable key (`pk_`) perform a privileged write.
- Add a foreign key to a table that already exists — SQLite cannot, and local dev runs on SQLite.
