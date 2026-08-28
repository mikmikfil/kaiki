# ADR-0006: Overselling concurrency strategy across SQLite (local) and MySQL (CI/production)

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §5.5, §12 (Testing gates), §14 M2.12 of `docs/BRIEF.md`; requirements AVL-41 … AVL-48, TST-6

## Context
§5.5 requires confirmation inside a transaction using `SELECT ... FOR UPDATE` on the departure or vessel row, and requires a test proving two simultaneous bookings cannot oversell. SQLite has no `FOR UPDATE` (it serialises writers with a whole-database lock instead) and cannot run two genuinely concurrent transactions in the way the test needs. Local development is SQLite-only; MySQL 8 exists only in CI and production. So the production correctness mechanism and the test that proves it cannot both run on the developer machine. Blocks **M2** and defines how every future confirmation-path change is validated.

## Options

### Option A — Pessimistic `lockForUpdate()` in domain code, plus a database-level guard, with the concurrency test gated to MySQL
Domain code always calls `->lockForUpdate()`; on SQLite the driver emits no lock clause and the engine still behaves correctly because SQLite serialises writers. A `CHECK`-style invariant is enforced in application code inside the same transaction, and a defensive unique/derived constraint prevents `seats_sold + seats_held > capacity` from ever being committed. The two-parallel-confirmations test is tagged `@group mysql` and skipped locally with a clear message; CI runs it against MySQL 8 and it is a required check.
Pros
- Production uses the exact mechanism §5.5 requires; no divergence in shipped code.
- Local test runs still cover all the deterministic logic (capacity arithmetic, held seats, age bands, expiry); only the true-parallelism assertion is CI-only, which is honest and visible.
- The developer sees an explicit "skipped: requires MySQL" line rather than a false green.
Cons
- A concurrency regression can be introduced locally and only caught in CI. Mitigated by CI being a required check on every PR, not just on main.
- Requires discipline that `lockForUpdate()` is never dropped for "SQLite compatibility".

### Option B — Optimistic concurrency: a `version` column with a compare-and-set update, retried
Confirmation reads the departure, computes, then issues `UPDATE departures SET seats_sold = ?, version = version + 1 WHERE id = ? AND version = ?`; zero affected rows means retry.
Pros
- Works identically on SQLite and MySQL, so the overselling test can run locally *and* in CI.
- No long-held row locks; better under high contention on popular departures.
Cons
- Contradicts the literal wording of §5.5, which names `SELECT ... FOR UPDATE`.
- Vessel-level conflicts (§5.1, §5.3) span multiple rows — departures, vessel blocks, other products — and a single `version` column does not protect a multi-row invariant. Would need a version on the *vessel* too, and careful ordering.
- Retry loops interact awkwardly with payment webhooks, which must be fast and idempotent.

### Option C — Serialise all confirmations for a vessel through a named lock (`Cache::lock`), no database row locking
Pros
- Portable across every environment; testable locally with the database lock store.
- Same primitive already used for holds (ADR-0005).
Cons
- Correctness then depends on the cache, not the database: a cache eviction or a second application node with a misconfigured store can oversell silently.
- A lock is not a transaction; a crash between the capacity check and the commit leaves state inconsistent.
- Explicitly weaker than §5.5.

## Recommendation
**Option A**, with the atomic-decrement guard from Option B as belt and braces: after the locked read, the seat counter update is written as a conditional `UPDATE ... WHERE capacity - seats_sold - seats_held >= :requested`, and a zero-row result aborts the transaction with `CAPACITY_EXCEEDED`. That conditional update is portable, so it *is* exercised on SQLite, while `lockForUpdate()` gives MySQL the ordering §5.5 asks for. Tag the parallel test `@group mysql`, run it in CI as a required check, and add a `composer test:mysql` script for anyone with a MySQL instance.

## Consequences if accepted
- `app/Domain/Availability` gains a `ConfirmSeats` / `ConfirmVesselWindow` action wrapping `DB::transaction` with `lockForUpdate()` and a conditional counter update.
- Pest groups: `fast`, `mysql`, `chromium`, `external`. `composer test` excludes `mysql` and `chromium` locally; CI runs everything.
- The CI MySQL job is a required status check on every pull request; a red concurrency test blocks merge.
- Skipped tests must print the reason; a CI assertion fails the build if the `mysql` group reports zero executed tests.
- Locking order is documented and fixed (vessel row, then departure row) to avoid deadlocks.
