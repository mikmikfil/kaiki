# ADR-0001: Tenancy mode — single database with `tenant_id` vs database-per-tenant

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Tenancy row), §4 (Tenancy & users), §14 M0.1 of `docs/BRIEF.md`; requirements TEN-1 … TEN-9, SEC-1 … SEC-4

## Context
`stancl/tenancy` supports both a single shared database with a `tenant_id` discriminator and a database (or schema) per tenant. §3 states single-database as the preference and asks to "confirm vs the starter's current DB-per-tenant setup before M0". There is **no starter repo** — the repository is empty apart from `docs/BRIEF.md` — so there is no legacy setup to migrate away from and this is a clean, cheap choice made once. It blocks **M0** entirely: every migration, every model trait, tenant resolution by API key / hosted slug / custom domain / panel session, and the super-admin panel depend on it. Reversing it after M1 means rewriting every migration and every query scope.

## Options

### Option A — Single database, `tenant_id` on every tenant-owned table (`stancl/tenancy` single-database mode)
Pros
- Cross-tenant reporting, the super-admin panel (§11), platform health feeds and the myDATA/gateway error feed are plain queries.
- One migration run, one schema, one backup, one restore drill. Matches the two-person team constraint in §3.
- Hosted pages and the public API resolve a tenant per request without switching connections; connection pooling stays trivial.
- Works identically on SQLite locally and MySQL in CI/production (see ADR-0015), because nothing depends on `CREATE DATABASE` privileges.
- Cashier subscriptions live on the tenant model with no cross-database joins.
Cons
- Tenant isolation is enforced by application code (global scope + `BelongsToTenant`), not by the database. One missing scope leaks data.
- Requires a hard testing discipline: an isolation test per tenant-owned model, and a security review gate (§15 `security-reviewer`).
- Noisy-neighbour risk on very large tenants; `departures` and `bookings` grow across all tenants in one table.

### Option B — Database per tenant
Pros
- Isolation enforced by the database; a missing scope cannot leak another operator's bookings.
- Per-tenant restore, per-tenant export and "delete this operator" are trivial.
- Table sizes stay small per tenant.
Cons
- Cross-tenant reporting and the super-admin panel need either a central read-model or a fan-out over N connections — direct conflict with §11.
- Migrations must run N times; a failed migration leaves tenants on mixed schema versions.
- Local development on SQLite means one file per tenant; CI must create databases dynamically; MySQL user needs `CREATE DATABASE`. Materially heavier for the environment described in ADR-0015.
- Custom-domain and API-key resolution must happen before the connection is chosen, adding a central lookup table anyway.

### Option C — Single database now, with a documented migration path to per-database for enterprise tenants later
Pros
- Ships M0 fast; keeps the door open by mandating that all tenant-owned queries go through `BelongsToTenant` and no query ever hardcodes a connection.
Cons
- The "later" migration is expensive in practice and rarely happens; the option value is mostly illusory.
- Risk of designing for a future that never arrives.

## Recommendation
**Option A.** The brief already leans this way, the super-admin and cross-tenant reporting requirements in §11 make Option B actively hostile, and with an empty repository there is no migration cost to weigh against it. Isolation risk is mitigated by making `BelongsToTenant` mandatory, adding a per-model tenant-isolation Pest test as an M0 gate, and running `security-reviewer` before each milestone close.

## Consequences if accepted
- Every tenant-owned migration gets `tenant_id` (FK to `tenants`, indexed, first column of every composite index) — locks in `docs/data-model.md`.
- `App\Models\Concerns\BelongsToTenant` adds a global scope and auto-fills `tenant_id` on create; models without it must be explicitly listed as platform-owned.
- Tenant resolution middleware: API key → hosted slug → custom domain → Filament panel session, in that order (TEN-4).
- Uniqueness constraints that are logically per-tenant (`bookings.reference`, `products.slug`, `vouchers.code`, `api_keys.prefix`) become composite unique with `tenant_id`.
- CI gains a mandatory tenant-isolation test group; `security-reviewer` is a required reviewer for any migration.
- `stancl/tenancy` is configured in single-database mode; no `tenancy:migrate` per-tenant workflow, no dynamic connection switching.
