# ADR-0009: Departure generation horizon, job cadence and rule-change semantics

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §4 (ScheduleRule, Departure), §5.2, §14 M1.9 of `docs/BRIEF.md`; requirements AVL-49 … AVL-60

## Context
A `ScheduleRule` (weekday mask, start time, validity window, capacity override) auto-generates `Departure` rows N days ahead via the scheduler. The brief does not fix N, the cadence, or what happens when a rule is edited or deleted after departures already exist and have seats sold. This has direct cost consequences: departures are the largest table, the availability endpoint has a 150 ms p95 budget "with 1 year of departures" (§12), `RatePlan.max_advance_days` caps how far ahead a guest can book anyway, and an over-eager regeneration could delete a departure with paying guests on it. Blocks **M1**.

## Options

### Option A — Rolling 400-day horizon, generated nightly plus on demand, additive-only with explicit reconciliation
A nightly job extends every active rule so that departures exist up to `min(400 days, rule.valid_to)`. Saving a rule triggers the same job immediately for that rule. Generation is idempotent on (`schedule_rule_id`, `local_date`, `start_time`). Editing a rule never silently mutates existing departures: the job creates newly-matching future departures, and *flags* now-unmatched future departures in a reconciliation screen where the operator chooses "cancel" or "keep as one-off". Departures with `seats_sold > 0` are never auto-deleted or auto-modified.
Pros
- 400 days covers a full season plus the "book next summer" case, and comfortably exceeds any realistic `max_advance_days`.
- Additive-only generation makes the job safe to re-run, which is the property that matters most for a scheduled job.
- The operator is never surprised by a rule edit silently cancelling a departure someone paid for.
- Nightly cadence keeps the write volume trivial (a handful of rows per rule per night).
Cons
- Storage: 10 vessels x 2 daily departures x 400 days is about 8,000 rows per tenant per year. Negligible, but the availability query must be indexed on (`tenant_id`, `product_id`, `local_date`).
- The reconciliation screen is extra M1 UI.

### Option B — Short horizon (90 days), generated on demand at read time
Generate lazily when an availability query asks for a date beyond the materialised horizon.
Pros
- Minimal storage; no scheduler dependency.
Cons
- Writes inside a read path that has a 150 ms p95 budget; the first visitor of the day pays for generation.
- Public, unauthenticated endpoints would trigger row creation — an easy denial-of-service and a tenant-isolation hazard.
- The vessel calendar (§9) and iCal export need materialised rows anyway.

### Option C — No materialised departures; compute occurrences from rules at query time
Pros
- No generation job at all; rule edits apply instantly everywhere.
Cons
- `seats_sold`, `status` (`guaranteed` / `cancelled`), capacity overrides, notes and manifest links all attach to a *departure*, so a virtual occurrence has nowhere to live until it is materialised — meaning a hybrid model with two code paths.
- Vessel-conflict resolution (§5.1) becomes a rule-expansion problem on every availability call.

## Recommendation
**Option A**, with the horizon as a config value (`kaiki.departures.horizon_days`, default 400) and a per-tenant override reserved for later. Additive-only generation plus an operator-facing reconciliation list is the safest reading of "what happens when a rule changes", and it keeps the read path free of writes. Run the job nightly at 03:15 in the tenant timezone, and immediately (queued) whenever a rule is created or updated.

## Consequences if accepted
- `GenerateDepartures` action plus `GenerateDeparturesJob`, idempotent on (`schedule_rule_id`, `local_date`, `start_time`); departures carry `schedule_rule_id` and `is_manual`.
- Departures generated for a local time that does not exist on a DST spring-forward date are handled per ADR-0016.
- Rule edits enqueue generation for that rule only; deletions soft-delete the rule and flag future zero-sold departures for review, never for silent deletion.
- Capacity override changes apply to future departures with `seats_sold = 0`; departures with sales keep their captured capacity and are listed for manual review.
- `departures` indexes: (`tenant_id`, `product_id`, `local_date`), (`tenant_id`, `vessel_id`, `starts_at_utc`), (`tenant_id`, `status`, `starts_at_utc`).
- The performance test (NFR-1) seeds one year of departures against these indexes.
