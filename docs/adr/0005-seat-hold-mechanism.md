# ADR-0005: Seat-hold mechanism when local development has no Redis

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §5.4, §3 (Infra), §14 M2.12 of `docs/BRIEF.md`; requirements AVL-30 … AVL-40, ENV-6

## Context
§5.4 requires that reaching payment holds the seats (or the vessel window) for 15 minutes, and describes the mechanism as "Redis lock + `expires_at` on the draft booking". The development machine has **no Redis and no Docker**: local runs on SQLite with database/file cache and queue drivers; Redis exists only in CI and on the Hetzner production server. A hold mechanism that only works with Redis cannot be developed or tested locally, which would make the single most conversion-critical path in the product untestable on the machine where it is written. Blocks **M2**, and the choice determines whether `AvailabilityService` is portable.

## Options

### Option A — `Cache::lock()` for the critical section, `hold_expires_at` on the draft booking as the source of truth
The lock is a short-lived mutex (a few seconds) taken only while creating or extending a hold. The *hold itself* is a database row: `bookings.hold_expires_at` plus `departures.seats_held`. Locally `Cache::lock` resolves to the database lock store; in CI and production it resolves to Redis, with no code difference.
Pros
- Identical code in all three environments; the driver difference is configuration only, which is the parity rule in ADR-0015.
- Honours §5.4 literally in production (Redis lock plus `expires_at`) while remaining runnable on SQLite.
- Holds survive a Redis flush or restart, because the authoritative state is in the database. A cache-only hold would silently release every seat on a Redis restart.
- Expiry is enforced twice: every availability read filters `hold_expires_at > now()`, and a per-minute scheduled sweeper expires drafts and decrements counters.
Cons
- The database lock store on SQLite serialises writers; acceptable for a single developer, irrelevant in production.
- `Cache::lock` on the database store is a polled lock, so contention behaviour differs slightly from Redis. Mitigate with short lock TTL, bounded `block()` wait and an explicit timeout error.

### Option B — Redis-only lock, with holds effectively disabled locally
Pros
- Exactly the words in §5.4; no abstraction.
Cons
- The hold path cannot be exercised on the dev machine at all, so bugs surface only in CI. This is the highest-traffic, highest-revenue-impact code path in the product.
- Forces a code branch (`if (Redis available)`) which is a worse abstraction than `Cache::lock`.

### Option C — Pure database row locking, no cache lock at all
Take the hold inside the same transaction that reads capacity, using a row lock on the departure.
Pros
- One mechanism for both holds and confirmation; nothing to keep consistent.
- No cache dependency anywhere.
Cons
- SQLite cannot exercise `SELECT ... FOR UPDATE` (see ADR-0006), so the local story is not actually better.
- Holds are a long-lived (15 minute) reservation, not a critical section; representing them as transactional locks means either very long transactions or the same `expires_at` column anyway.
- Loses the cheap cross-request mutex that also protects vessel-window holds spanning multiple rows.

## Recommendation
**Option A.** It satisfies §5.4 in production, keeps a single code path across SQLite, MySQL and Redis, and makes the hold durable rather than cache-resident. The lock is a mutex around a short critical section; the hold is data. Enforce a hard rule that no domain code may reference `Redis::` or `RedisStore` directly — only `Cache::lock` and `Cache`.

## Consequences if accepted
- `bookings.hold_expires_at` (nullable, indexed) and `departures.seats_held` are required in `docs/data-model.md`.
- `HoldSeats`, `ReleaseHold`, `ExtendHold` actions in `app/Domain/Availability`, all taking `Cache::lock("kaiki:hold:departure:{id}")` or `...:vessel:{id}:{date}` with a 5-second TTL and 3-second block.
- A scheduled `ExpireStaleHolds` job runs every minute; every availability read also filters expired holds, so correctness never depends on the scheduler running.
- Hold TTL is a config value defaulting to 15 minutes (§5.4 FIXED); it is not per tenant in MVP.
- Local `.env.example` sets `CACHE_STORE=database`; CI and production set `redis`. Tests run on both stores in CI.
