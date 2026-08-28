# ADR-0020: Can a user belong to more than one tenant?

- Status: **Accepted (Option C)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §4 (Tenancy & users) of `docs/BRIEF.md`; `docs/data-model.md` §2.1 (`users`, `role_assignments`); requirement TEN-4

## Context
§4 says "User — belongs to tenant" (singular) and "Super-admins are platform users with no tenant". Nothing states whether one person may hold accounts at two operators. This matters in practice in the Greek market: a skipper often crews for two operators in the same season, and an agency managing several operators' listings is a plausible early customer. The choice fixes whether `users` carries a `tenant_id` column or whether membership lives in a pivot, and that shape is visible in every Filament policy, the panel login flow and the Sanctum session's tenant resolution. `docs/data-model.md` §2.1 records a provisional default so M0 is not blocked. Changing it after M0 means rewriting authentication, not just a migration. Blocks **M0** (issue #5).

## Options

### Option A — One tenant per user, globally unique email, membership implied by `users.tenant_id`
A person working for two operators has two accounts with two different email addresses.
Pros
- Simplest possible authentication: the session's tenant is a column read, never a choice.
- `BelongsToTenant` applies to `users` like every other table; one uniform isolation story, which is exactly what ADR-0001's isolation CI gate wants to assert.
- No tenant-switcher UI, no "which operator am I acting as" ambiguity in audit logs.
Cons
- The two-operators skipper needs two logins and two inboxes; realistically they will reuse one address and be blocked by the global unique constraint.
- Forecloses the agency use case without a migration.

### Option B — Many-to-many from the start: `tenant_user` pivot carrying the role, plus a tenant switcher in `/app`
Pros
- Handles the skipper and the agency natively; one identity, many operators.
- Roles become per-membership, which is more accurate anyway (owner at one operator, crew at another).
Cons
- Every policy, every panel bootstrap and every API-key-free session path must resolve "current tenant" from session state rather than from the user row — more surface for a tenant-isolation bug, which is the single most expensive class of bug in this product.
- Adds a switcher to the onboarding and login flows in M0, before there is any evidence a customer needs it.

### Option C — Option A now, with `role_assignments` modelled separately so the pivot is a data migration later
`users.tenant_id` plus a separate `role_assignments` table keyed on `(tenant_id, user_id, role)`. Membership is still single-tenant, but the role already lives where a pivot would.
Pros
- Ships the simple model, and the expensive part of the future change (roles) is already in the right shape.
- The later migration is "drop `users.tenant_id`, backfill memberships from `role_assignments`" — mechanical, not a redesign.
Cons
- Carries a table that looks over-engineered until the day it is not.
- Still requires the authentication rewrite when it flips; it only removes the schema half of the cost.

## Recommendation
**Option C.** The agency and multi-operator-skipper cases are speculative, and paying for them in M0 means paying in the exact place — tenant resolution — where a mistake leaks one operator's bookings to another. Option C ships the safe single-tenant model while keeping roles in a shape that does not have to be unpicked. This is what `docs/data-model.md` §2.1 already assumes, so accepting it requires no document changes.

## Consequences if accepted
- `users.tenant_id` is non-null for operator users, null for super-admins; email is globally unique.
- `role_assignments (tenant_id, user_id, role)` exists from M0 with a unique constraint on the triple.
- Panel tenant resolution reads `auth()->user()->tenant_id`; no switcher UI in M0–M7.
- A future ADR covers the pivot migration if a real customer needs it.
