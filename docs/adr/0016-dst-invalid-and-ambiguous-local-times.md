# ADR-0016: Handling non-existent and ambiguous local departure times across DST in Europe/Athens

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Conventions), §5.1, §5.2, §14 M1.10 of `docs/BRIEF.md`; requirements AVL-15 … AVL-20, CNV-4

## Context
Departures store `local_date` plus `local_time` plus `starts_at_utc` (§3, FIXED). Europe/Athens moves the clock forward on the last Sunday of March (03:00 becomes 04:00, so 03:00–03:59 does not exist) and back on the last Sunday of October (04:00 becomes 03:00, so 03:00–03:59 occurs twice). A `ScheduleRule` with a start time in either window produces a `local_time` that cannot be converted to a single UTC instant, and the availability engine, the iCal feed and the ticket would all disagree. Realistic exposure is low — very few boats leave at 03:30 — but sunset and night cruises, late private charters and midnight-crossing windows do land near it, and §15 explicitly requires DST-change-day tests. Blocks **M1**.

## Options

### Option A — Skip and flag non-existent times; take the first (DST) occurrence for ambiguous times
Generation refuses to create a departure whose local time does not exist on that date, records a `schedule_rule_issues` row and surfaces it in the operator panel ("Δεν υπάρχει ώρα 03:30 στις 29/03 λόγω αλλαγής ώρας"). For an ambiguous local time, choose the earlier instant (the one still in summer time, UTC+3).
Pros
- Never invents a departure at a time the operator did not choose, and never silently shifts one.
- Deterministic and easy to state as a test: two fixtures, one per transition day.
- The operator is told, so they can add a manual one-off departure at a real time.
Cons
- Requires a small operator-facing issues surface (which ADR-0009 already introduces for rule reconciliation).
- Choosing the earlier instant for ambiguous times is arbitrary; the guest sees the local time they expect either way, but the UTC instant differs by an hour from the alternative.

### Option B — Shift forward to the next valid local time (03:30 becomes 04:30); take the later occurrence when ambiguous
Pros
- A departure always exists; no gap in the schedule.
Cons
- Silently changes a published departure time once a year. Guests who booked a "03:30" trip and crew rostered for it disagree by an hour, and the ticket, the iCal feed and the manifest may be generated at different moments and disagree with each other.

### Option C — Store only `starts_at_utc` and derive local time on read
Pros
- No ambiguity ever; one source of truth.
Cons
- Contradicts the FIXED convention in §3, which requires `local_date` and `local_time` columns. Also makes "every Tuesday at 10:00 local" queries awkward across a DST boundary, which is the common case.

## Recommendation
**Option A.** It is the only option that never silently moves a departure a guest has booked. The ambiguous-time tie-break (first occurrence, summer offset) should be written down in `docs/spec.md` and asserted in a test. All duration arithmetic remains absolute (`ends_at_utc = starts_at_utc + duration_minutes`), so a trip spanning a transition is one hour longer or shorter in wall-clock terms and correct in elapsed terms — which is what the crew and the vessel schedule care about.

## Consequences if accepted
- A `LocalDateTimeResolver` in `app/Domain/Availability` owns local-to-UTC conversion and is the only place that touches timezone conversion; it returns a result object with `existent`, `ambiguous` and the chosen instant.
- `GenerateDepartures` records a `skipped_dst_nonexistent` issue instead of creating a row; the operator panel lists it.
- Availability queries for a local day resolve the day boundary through the same resolver, so a 23-hour or 25-hour day is handled once.
- Table-driven Pest tests cover: spring-forward skip, autumn ambiguity, a trip crossing each transition, and a midnight-crossing charter on a transition night.
- The vessel calendar and iCal feed render from `starts_at_utc` and format in the tenant timezone; they never re-derive from `local_time`.
