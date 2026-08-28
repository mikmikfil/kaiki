# ADR-0023: One bookable-window table, or departures plus per-vessel booking windows?

- Status: **Accepted (Option C)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §4, §5 of `docs/BRIEF.md`; `docs/data-model.md` §2.4, §7.2, §8 item 7; requirements AVL-4 … AVL-12
- **Expensive to reverse after M2.**

## Context
Two things occupy a vessel's calendar: a `Departure` (a scheduled shared trip that sells seats) and a `per_vessel` booking's own window (a private charter), plus `VesselBlock` rows for maintenance and imported iCal events. Conflict detection in `docs/data-model.md` §7.2 is therefore a **three-query union in PHP** — blocks, other departures with sold or held seats, and live per-vessel bookings. A single `bookable_windows` table with a discriminator would collapse that to one indexed range query and one overlap test, which is attractive for the engine that §12 requires to answer in under 150 ms p95.

The counter-argument is conceptual: a scheduled trip and a private charter are different things to the operator. They appear differently on the calendar, in reports, in the manifest, and in the state machines — a departure is cancelled, a booking is refunded. Merging them into one table risks making every query say "…and what kind of window is this again?". This is the single most expensive schema decision in the model to reverse, because the availability engine, the calendar timeline and every report are written against whichever shape wins. Blocks nothing today; **locks in at M2** once the booking aggregate is built on it.

## Options

### Option A — Two shapes, as `docs/BRIEF.md` §4 describes (current provisional default)
`departures` for `per_seat`; `per_vessel` bookings carry `date + start_time + end_time` and write a `vessel_blocks` row on confirmation; `vessel_blocks` also covers maintenance and iCal.
Pros
- Each table means one thing; the operator's mental model and the schema agree.
- State machines stay separate and simple (§4.1 Booking, §4.2 Departure).
- Matches the brief exactly, so no re-derivation of §5 is needed.
Cons
- Three queries per conflict check, unioned in PHP — more code, and three indexes to keep correct rather than one.
- The "is this vessel free" predicate is written once but *tested* three ways; a future fourth occupier (an OTA hold, a crew-unavailability block) means a fourth query.

### Option B — One `bookable_windows` table with a `kind` discriminator
Every occupier — departure, private charter, maintenance block, iCal import — is a row with `vessel_id`, `starts_at_utc`, `ends_at_utc`, `kind`, and a nullable pointer to the owning entity. `departures` and `bookings` keep their own tables for everything else and reference their window.
Pros
- Conflict detection is one query against one composite index; the p95 target gets easier, not harder, as occupiers multiply.
- A new kind of occupier is a new enum value, not a new query.
- The calendar timeline reads one table in vessel/time order — exactly how it renders.
Cons
- Two sources of truth for a departure's time (`departures` and its window row) unless the window becomes authoritative, which then makes `departures` a satellite table and complicates generation and the DST handling in ADR-0016.
- Every write path must keep the window row in sync inside the same transaction; a missed sync is an overselling bug, which is the failure mode this product least tolerates.
- Reporting queries gain a join they did not need.

### Option C — Option A now, with the union hidden behind one `VesselCalendar` port, and Option B revisited only if the p95 target is missed
The three queries live in a single class with one public method; nothing outside `app/Domain/Availability` knows there are three.
Pros
- Keeps the honest schema and contains the cost; swapping in Option B later touches one class and its tests, not the engine's callers.
- Defers a hard-to-reverse decision until there is a benchmark to argue from.
Cons
- The benchmark that would settle it (NFR-1, 20k bookings on MySQL 8) is deferred to M8, so "revisit later" may mean "after it is expensive".

## Recommendation
**Option C.** The brief's shape is the honest one and the row counts involved are small — `docs/data-model.md` sizes a five-product tenant at roughly 900 departures a year, where three indexed range queries are not the bottleneck; API fan-out is. Containing the union behind one port costs almost nothing now and buys the option to switch. If this is accepted, the NFR-1 benchmark should be pulled forward from M8 to the end of M2, so the decision is revisited while it is still cheap rather than after launch.

## Consequences if accepted
- `docs/data-model.md` §2.4 and §7.2 stand as written.
- `App\Domain\Availability\VesselCalendar` is the only class allowed to query occupancy; the three queries live there and nowhere else, enforced by an architecture test.
- The NFR-1 availability benchmark moves to the M2 close, with a documented threshold that triggers reopening this ADR.
