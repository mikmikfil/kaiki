# ADR-0025: Operator audit log

- Status: **Accepted (Option A)**
- Decided: 2026-09-04
- Date: 2026-09-04
- Deciders: product owner
- Related: requirements SEC-16, SEC-3, TEN-8, CXL-5, ADR-0012 (retention), ADR-0019 (package approval); issues #42 (this decision), #10 (the log line that prompted it), #27 and #28 (first blocked implementations)

## Context

SEC-16 requires that every destructive operator action is *"confirmed and audit-logged with actor, timestamp and reason where applicable"*, and enumerates five: delete vessel, delete product, cancel departure, refund, purge.

#10 shipped the first destructive-ish action — API key revocation — and satisfied it with a structured `Log::info` carrying actor, key prefix and tenant. That was defensible for one resource and was called out as temporary in its own review: there is **no audit table anywhere in `docs/data-model.md`**, and inventing one mid-form would have been exactly the drift ADR-0019 exists to prevent.

The defence has now expired. #16 shipped two resources with real delete actions, #23 a third, and #27/#28 bring departure cancellation — which is on SEC-16's own list. Whatever happens next becomes the pattern eleven more M1 resources copy, and deciding after that is a migration rather than a decision.

What a log line cannot do:

- **It is not queryable.** *"Who revoked this key, and when?"* is answered by grepping a file on a Hetzner box, if the retention window has not already rolled it away.
- **It is not tenant-visible.** An operator cannot see their own team's actions, and a leaked-key incident is precisely when they need to.
- **It has no retention story.** ADR-0012 sets a 90-day default purge for personal data. Audit lines have no stated policy at all, and some of them name users.
- **It captures no reason.** SEC-16 asks for one where applicable. "Leaked" and "rotating" are different events with different incident responses, and a log line cannot tell them apart.

## Options

### Option A — A first-class `audit_logs` table, written by listeners on existing events

Tenant-scoped (`BelongsToTenant`), appended by queued listeners on the domain events that `CLAUDE.md` already requires for every state change.

Pros
- Queryable by tenant, by actor, by subject and by date — which is what every real question about an audit trail actually is.
- Visible to the operator in `/app`, so a leaked-key incident is self-serve rather than a support ticket.
- A row is an ordinary Eloquent model, so tenancy scoping is the mechanism already proven by #8's isolation gate rather than a second one.
- Listening to events rather than instrumenting call sites means a new destructive action is audited by firing the event it should have fired anyway.

Cons
- One more table, which must land in the `docs/data-model.md` §6 order — and SQLite cannot add a foreign key to an existing table, so its columns have to be right the first time.
- Retention becomes a real question rather than an unexamined one (see below); that is a cost, but it is a cost of *having* the trail rather than of this option.

### Option B — `spatie/laravel-activitylog`

Pros
- Does most of this already, with a settled API and Filament integrations available.
- Model events are captured without writing listeners.

Cons
- **Not on the ADR-0019 shortlist**, so approving it is part of this decision rather than a given.
- Its table is shared and not tenant-scoped by default: it would need `tenant_id` added and its own global scope, and a package-owned schema is the one place a missing scope is hardest to notice. That is a direct cost against ADR-0001's isolation guarantee.
- Its automatic model-event capture logs *everything*, which is the opposite of the scoping decision below and is difficult to narrow without fighting the package.
- Retention and GDPR anonymisation would have to be implemented against a schema we do not own.

### Option C — Structured logs shipped to a queryable sink

Pros
- Cheapest today: keep `Log::info` and defer the sink to M8 observability.
- No table, no migration ordering question, no retention schema.

Cons
- The operator can never see their own team's actions, which is half of what SEC-16 is for.
- Nothing is queryable until the sink exists, and "we will ship logs somewhere in M8" is not an audit trail in M1.
- GDPR erasure against a log sink is materially harder than an `UPDATE` on a column.

## Decision

**Option A**, with four parameters settled by the product owner on 2026-09-04:

### 1. Mechanism
A first-class `audit_logs` table, tenant-scoped, appended by queued listeners on domain events. Never updated and never deleted by application code — an audit row that can be edited is not an audit row.

### 2. Scope
**SEC-16's five named actions, plus every soft delete and every operator override that carries a reason.**

Not every state change. The complete-history option was considered and rejected: most rows would never be read, the table would grow without bound, and — decisively — every extra row is another row naming a person that the retention and erasure story has to account for. A short, defensible list answers real incident questions at a fraction of the privacy surface.

Concretely: `vessel.deleted`, `product.deleted`, `departure.cancelled`, `booking.refunded`, `gdpr.purged`, any other soft delete, plus `api_key.revoked` (which is what started this) and the CXL-5 refund override.

### 3. Retention — seven years, and the actor is an id
Audit rows are kept for **seven years**, not the ADR-0012 default of 90 days.

The two requirements pull in opposite directions and both are real. Greek bookkeeping obligations want records available for years, and an audit trail that purges at 90 days cannot answer a dispute about last season — which is the dispute people actually have. Against that, ADR-0012 purges personal data at 90 days by default and a GDPR erasure request must be honourable.

They are reconciled by **storing the actor as a `user_id`, never a name or an email**. An erasure request anonymises the user row; the audit trail keeps its shape, its timestamps and its causality, and simply no longer identifies a person. The legal basis for retention is the operator's own bookkeeping and dispute-resolution obligation, not consent.

`context` is a small JSON column and **must not carry personal data** — no guest names, no passport numbers, no email addresses. A test enforces the shape.

### 4. Visibility
**Owner and manager see the trail in `/app`; crew do not.** This matches the TEN-8 matrix for everything else that is money or account-level: crew are read-only within a departure window, and an audit trail is neither. Gated on a new `ViewAuditLog` capability rather than reusing an existing one, so "who can read the trail" is one line in the matrix rather than a side effect of another permission.

Platform-side audit of super-admin impersonation (ARC-21) is **related but out of scope**: it is an M7 concern, it is not tenant-scoped, and it should not be squeezed into this table without its own thought.

## Consequences

- `docs/data-model.md` gains the `audit_logs` schema and a position in the §6 migration order **before** any further resource ships a destructive action.
- The table holds a `subject_type` / `subject_id` pair **with no foreign key**, following the `vessel_blocks.booking_id` precedent: the subject may be soft-deleted, force-deleted or from a table that does not exist yet, and an audit row must outlive its subject.
- `user_id` is `nullOnDelete` and nullable: a system action has no actor, and a force-deleted user must not take the trail with them.
- `Capability::ViewAuditLog` is added to the TEN-8 matrix, held by owner and manager.
- `config('kaiki.audit.retention_days')` defaults to 2555 (seven years); the purge command is written alongside the ADR-0012 purge rather than as a second scheduler entry.
- #10's revocation log line is replaced by an audit row when the implementation lands; until then it stays as it is.
- **This ADR is not implemented here.** Implementation is issue #53.
