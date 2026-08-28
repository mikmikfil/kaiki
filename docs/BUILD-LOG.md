# Kaiki — build log

What has actually been built, and the evidence it works. One entry per issue, newest first.

Each entry records the **verification actually run** and its **real output** — not what was supposed to happen. Where something can only be checked in CI, it says so rather than claiming a local pass. Where an issue was delivered differently from how it was written, the deviation is stated with its reason.

`CHANGELOG.md` is the narrative for a reader; this file is the audit trail.

---

## Status

| | |
|---|---|
| Milestone | **M0 — Foundation** |
| Issues closed | #1, #2, #3, #5, #6, #7 of 12 |
| Local stack | Laravel 12.68 · PHP 8.4.24 · SQLite · database/file drivers |
| Quality gate | Pint · PHPStan level 6 + Larastan · Pest — all green |
| Deployment | Deliberately last (#13, #14 moved to `M8 — Launch & deployment`) |

**Open, not blocking, and not mine to close:**

| What | Who | Needed before |
|---|---|---|
| VAT **rates** (mechanism settled in ADR-0002; the numbers are not) | accountant | M6 |
| Invoice-numbering **gap policy** (ADR-0022) | accountant | M6 |
| Revisit ADR-0023 against the NFR-1 p95 benchmark | benchmark result | end of M2 |

---

## #7 — Tenant resolution: four strategies, fixed order, plus read-only mode

**Files:** `app/Http/Middleware/{ResolveTenant,EnsureTenantIsWritable}.php`, `app/Domain/Tenancy/Resolvers/` (interface + four strategies), `app/Models/TenantDomain.php`, `app/Enums/DomainStatus.php`, `app/Logging/{TenantContextProcessor,AddTenantContext}.php`, one migration, factory, `config/kaiki.php`, `lang/{el,en}/{errors,domains}.php`, four test files

Order: **API key → verified custom domain → hosted slug → panel session**, stopping at the first match, 404 when none matches.

### The ordering is a security property

API key first: the caller named a tenant explicitly, and a request holding a valid key must never be reinterpreted by host. Panel session **last**: an operator signed into their own back office who opens a competitor's hosted page must see *that* operator's public page, not their own catalogue bleeding through. A session says who you are, not what the request is for.

Both precedence cases are tested directly, because a resolver that works alone and loses to the wrong neighbour is exactly how that leak happens.

### No fallback, tested as its own failure

`NoFallbackTenantTest` covers unknown host, unknown slug, no signal at all, and an invalid API key that must **not** quietly degrade into "resolve by host instead". It also covers the tempting bug: *"there is only one operator, so obviously they meant that one"* — true on day one, catastrophic the day a second signs up.

These live here rather than in #8 because they are a different failure: #8 proves the scope cannot leak *between* resolved tenants; this proves the system refuses to proceed with *no* tenant.

### Custom domains

`tenant_domains` per ADR-0010, `hostname` globally unique and normalised **on write** — lowercased, port stripped, punycode-encoded. Normalising on write rather than comparing on read is what makes the unique constraint mean anything; otherwise `Example.COM` and `example.com` are two rows answering the same question, which is the whole security property gone.

Only `status = verified` resolves. Pending and disabled both 404.

### Read-only mode

Unsafe methods only. `past_due` still writes — that is the dunning window, not the punishment; cutting an operator off the moment a card fails would break bookings over a payment that usually succeeds on retry. `read_only` and `suspended` refuse, in Greek and English.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **90 passed** (207 assertions), 32 new |

### Two test bugs of my own

1. **A catch-all route on `/` silently lost to `routes/web.php`.** Six tests failed with "Invalid JSON was returned from the route" — they were asserting against the Laravel welcome page. Test routes now use a path that does not collide.
2. **A hardcoded punycode constant was wrong, twice, in two different ways.** First I asserted an encoding I had guessed; the real one differs. Then, with the value taken from my own machine, the test passed locally and **failed in CI on both engines** — the exact punycode output depends on the ICU version the PHP build links against, and CI's differs from Windows'.

   Rewritten to derive the expected hostname through the same normalisation instead of naming it. The property worth proving was never *what the encoding string is* — it is that **a hostname stored in Unicode is matched by the request that arrives for it**, whatever form that takes. Split into three deterministic tests: ASCII case-insensitivity, the Greek round trip, and idempotence (normalising an already-normalised hostname must not yield a third value, or the unique index means nothing).

   This one is worth remembering: it is the first environment difference that had nothing to do with the database or the cache. **A library version can be a parity boundary too.**

3. **A real bug found while chasing that.** `normalise()` used `strtolower`, which is byte-based and leaves `ΑΙΓΑΙΟ.GR` untouched. IDN processing folds the case anyway, so nothing was broken today — but the lowercase step silently did nothing for exactly the hostnames this product's customers register. Now `mb_strtolower`.

---

## #6 — API keys: `api_keys`, generation and hashing, authentication middleware

**Files:** `app/Models/ApiKey.php`, `app/Enums/{ApiKeyType,ApiKeyEnvironment,ApiScope}.php`, `app/Domain/Tenancy/Actions/{GenerateApiKey,RevokeApiKey}.php`, `app/Domain/Tenancy/Data/GeneratedApiKeyData.php`, `app/Http/Middleware/{AuthenticateApiKey,RequireApiKeyCapability}.php`, `config/kaiki.php`, one migration, factory, `lang/{el,en}/api.php`, three test files

Key shape `{pk|sk}_{live|test}_{32 random}`. Type and environment are in the string, so a key is readable on sight — which is also the only reason SEC-9's CI grep for a leaked `sk_` can work.

### The three things that must not be got wrong

**The raw key exists exactly once.** Only `prefix`, `secret_hash` (SHA-256) and `last_four` are stored, so a database dump is not replayable against the live API. Verified by a test that walks *every column* of the stored row asserting the plaintext appears in none of them — not by inspecting the two columns we expect to be safe.

**Type is a ceiling; scopes only narrow it** (SEC-5). Enforced twice: at creation, so a key that could never be used cannot be stored, and again at read time, so a row widened by a bad migration or a hand edit still cannot be used. There is a test that writes `quotes.write` directly into a publishable key's row with raw SQL and asserts it is still refused.

**A secret key arriving with an `Origin` header is refused.** Browsers always send one cross-origin, so that is exactly what a secret key leaking into front-end code looks like on the wire. Loud failure beats quiet success — the quiet version means an operator ships their secret key in a page and never finds out.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | **58 passed** (149 assertions), 24 of them new |
| `php artisan migrate:fresh --seed` | 5 migrations green on SQLite |

Authentication is covered end to end through the real HTTP stack — valid, missing, malformed, unknown prefix, **right prefix with the wrong secret**, revoked, expired, publishable-on-a-secret-route, secret-with-Origin, origin allow-list hit and miss, empty allow-list, and both locales in the error body. Middleware that works in isolation but not on a route is a familiar way to ship a hole, so none of it is tested by calling the class directly.

A query-count test asserts authentication costs **exactly one** indexed `api_keys` read. It runs on every public API request, so an extra read multiplies across the whole API against NFR-1's 150 ms p95.

### Three test problems fixed at the source

1. **A DB-touching test was written under `tests/Unit`**, where `RefreshDatabase` does not apply — it failed with `no such table: tenants`. Moved to `tests/Feature`: it was never a unit test, and the issue's file list was wrong about that.
2. **The "no direct `Redis::` call" guard was grepping comments.** The docblock in `ApiKey` explaining *why* there is no Redis call contains the string `Redis::`, so the guard failed on its own prose. It now strips comments with `token_get_all` and greps code. A guard that can be defeated — or triggered — by a comment is not a guard.
3. **PHPStan cannot type `$this` inside a Pest closure.** Both API test files use Pest's function API and local variables instead. The alternative, excluding `tests/` from analysis, would take the static-analysis gate off the code most likely to lie about itself.

### The MySQL job earned its keep: a real bug, green locally, red in CI

CI run [33172994149](https://github.com/mikmikfil/kaiki/actions/runs/33172994149) failed on **Pest on MySQL 8 + Redis** while SQLite passed. One test: *"it writes again once the window has passed"* — `last_used_at` stayed at `12:00:00` instead of moving to `12:01:01`.

The cause is worth writing down, because it will recur.

The throttle used `Cache::add($key, true, 60)` and let the **cache entry's TTL** decide whether the window had passed. Laravel's `array` driver — what the suite uses locally — evaluates expiry against **Carbon**, so `Carbon::setTestNow()` moved the clock and the entry looked expired. Redis expires keys on **real wall-clock time**, and 61 simulated seconds take no real time at all, so the entry was still there and the write was correctly skipped.

So the local suite was green for a reason that does not hold in production. The test was not wrong; the code was relying on a behaviour that differs by driver.

**Fixed in the code, not the test.** The window is now decided by comparing a stored timestamp against current application time, which is identical on every driver and controllable with frozen time. The trade is that two simultaneous requests could both write — a harmless duplicate update, since the throttle is a performance measure and not a correctness guarantee — bought in exchange for behaviour that does not depend on which cache driver happens to be configured.

This is the second parity bug in two database-touching issues, and the first one CI caught rather than review. **A cache-driver difference is subtler than a SQL-dialect one**: nothing about `Cache::add(…, 60)` looks environment-specific.

### …and immediately a second one underneath it

The next run failed differently, on the neighbouring test. Root cause: **`RefreshDatabase` rolls back the database, so auto-increment ids restart at 1 in every test — but the cache is not rolled back.** With the array driver that is harmless, because the store dies with the process. In CI the cache is real Redis, shared across the whole run, so an entry keyed `apikey:1:touched` written by one test is read by the *next* test's different key that also happens to have id 1.

Fixed globally in `tests/Pest.php`: the cache is flushed before every test. Anything that caches per-id would have hit this, and the symptom — order-dependent flakiness that only appears with a real cache — is among the most expensive to diagnose once a suite is large.

### …and a third, one level deeper still

The run after that failed on the same test again. **Laravel's Redis store round-trips numeric values as strings** — `RedisStore::serialize()` leaves anything numeric raw rather than serialising it — while the array driver keeps the int. So the guard `is_int($lastTouched)` was `false` on Redis and `true` in the local suite: the throttle **silently never engaged in production** while passing every local test.

Fixed by checking `is_numeric()` and casting. Note what this one would have cost: not a failing test, but a `last_used_at` write on every single authenticated API request, discovered eventually as unexplained database load.

### What these three have in common

All three were the same shape: **code that reads correctly, passes locally, and behaves differently in production because of an implicit driver assumption.** A TTL that means Carbon time in one driver and wall-clock in another. A cache that survives a rolled-back database. A numeric type that survives one store and not another. None of them looks environment-specific at the call site, and none would have been caught by review.

This is the concrete return on ADR-0015's parity contract, three issues in — and the argument for the MySQL + Redis job being a required check on every pull request rather than a nightly.

### CI-only

`api_keys` migrating on MySQL 8 — in particular `char(64)` for the hash and the `api_keys_tenant_type_idx` composite, neither of which SQLite validates the way MySQL does.

---

## #5 — Tenancy foundation: single database, `tenants` / `users` / `role_assignments`, `BelongsToTenant`

**Files:** `config/tenancy.php`, `app/Models/{Tenant,User,RoleAssignment}.php`, `app/Models/Concerns/{BelongsToTenant,HasUuid}.php`, `app/Models/Scopes/TenantScope.php`, `app/Support/Tenancy.php`, `app/Exceptions/{TenantContextMissingException,LastOwnerException}.php`, `app/Enums/{Role,Plan,TenantStatus}.php`, two migrations, three factories, `DemoTenantSeeder`, `lang/{el,en}/*`, five test files

`stancl/tenancy` in single-database mode (ADR-0001 Option A). `tenant_id` on every tenant-owned table, mandatory `BelongsToTenant`, and an explicit platform-owned allow-list with no implicit exemption.

### Decisions worth knowing

**The scope throws when no tenant is resolved.** Spec TEN-4 has no default tenant and no fallback, so the absence of context is an error rather than a wildcard. Returning every operator's rows because nobody resolved a tenant is the worst failure mode this product has, and it is silent. Deliberate cross-tenant access goes through `Tenancy::withoutTenancy()` — verbose and greppable on purpose, so it shows up in review.

**`config/tenancy.php` was replaced, not tuned.** The published file is 200 lines of database-per-tenant machinery. None of it applies, and leaving it would tell the next reader that per-tenant databases exist somewhere in the system. `bootstrappers` is deliberately empty, with a note that an entry appearing there is a silent move to per-tenant infrastructure.

**`users` deliberately has no tenant scope.** `tenant_id` is nullable and null means platform super-admin (ADR-0020 Option C); a global scope would make super-admins invisible to their own panel. It is in the allow-list with that reason attached.

**The last-owner guard is in the application, not the database.** No portable constraint expresses "at least one row matching this predicate", and a trigger would not survive the SQLite/MySQL split. Its message is an operator-facing string, so it exists in Greek and English.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` — 11 findings fixed with real annotations, no baseline, no ignores |
| `composer test` | **30 passed** (67 assertions) |
| `php artisan migrate:fresh --seed` on SQLite | 4 migrations, 2 tenants, 6 users, 5 role assignments, uuids assigned |

**The allow-list guard was tested by breaking it.** Adding a bare `App\Models\LeakProbe` made the suite fail, naming the model and saying what to do:

```
These models are neither tenant-scoped nor listed as platform-owned:
  App\Models\LeakProbe
Tests:    1 failed, 29 passed
```

Removing it returned the suite to green. A guard that has never been seen to fail is not evidence of anything.

**The scope assertion reads compiled SQL, not row counts.** A query returning the right number of rows proves nothing if the filtering happened in PHP; the isolation guarantee rests on the `WHERE` clause reaching the database.

### One parity bug caught before CI could

That same assertion originally expected `"role_assignments"."tenant_id"` — SQLite quoting. MySQL emits backticks, so it would have passed locally and gone red in the MySQL job. It now strips quote characters and asserts the clause rather than the dialect. **This is the SQLite/MySQL split producing exactly the class of bug ADR-0015 predicted**, on the first issue that touches the database.

### CI evidence — run [33171491076](https://github.com/mikmikfil/kaiki/actions/runs/33171491076), commit `4942bd0`

All five jobs green, including **Pest on MySQL 8 + Redis**. The part local SQLite could not prove:

```
0001_01_01_000000_create_users_table ......................... 224.87ms DONE
0001_01_01_000003_create_role_assignments_table .............. 111.73ms DONE
mysql group executed for real: Tests:    1 passed (2 assertions)
```

Both migrations run on MySQL 8 — so the column types, the index names, and the `utf8mb4` key limit on `users.email` (190 chars = 760 bytes) all hold on the engine production actually uses, not just on SQLite.

---

## #3 — CI workflow: Pint, PHPStan, Pest on SQLite, plus MySQL 8 + Redis

**Commit:** `927f13e` · **Files:** `.github/workflows/ci.yml`, `tests/Feature/SmokeTest.php`, `tests/Unit/ToolchainTest.php`

Five jobs on every pull request and every push to `main`: `lint`, `static-analysis`, `test-sqlite`, `test-mysql`, and a `ci-passed` aggregate gate that exists so branch protection needs exactly one required check and cannot silently miss a job added later. PHP 8.4 is declared once, in workflow-level `env` (ADR-0014 Option B — a single version, not a matrix). Composer installs are cached by `ramsey/composer-install` keyed on `composer.lock`.

`test-mysql` runs MySQL 8 and Redis 7 service containers, migrates from scratch, and runs the suite with `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` all on Redis, so the Redis code paths are exercised somewhere (ENV-1). It is a required check on every PR, not nightly — ADR-0006 leans on it as the only place real parallelism is ever tested.

### Two problems found and fixed while building this

**1. `SmokeTest` would have failed the MySQL job for the wrong reason.** It asserted `config('database.default') === 'sqlite'`, which is false — and correctly so — inside a job whose whole purpose is running against MySQL. Rewritten to assert the *contract* in `.env.example` (that a clean checkout lands on SQLite with database/file drivers), which is what ENV-17 actually requires and which holds on both engines.

**2. The `mysql` group could have reported green without touching MySQL.** PHPUnit applies an `<env>` entry only when the variable is not already set, so the job environment should win over `phpunit.xml`'s SQLite defaults — but "should" is not evidence. The group's test now asserts `DB::connection()->getDriverName() === 'mysql'`, and CI parses the Pest summary and fails on three distinct failure shapes:

| Shape | Why it must fail |
|---|---|
| no `Tests:` summary at all | the group matched nothing |
| summary contains `skipped` | the MySQL service or `DB_*` env never reached PHPUnit |
| summary lacks `passed` | nothing actually ran green |

This matters beyond this issue: the overselling concurrency test (AVL-44, ADR-0006) joins this group in M2 and can *only* ever run here. A silent skip would mean the single most expensive bug class in the product is untested while CI shows green.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | `{"tool":"pint","result":"passed"}` |
| `composer stan` | `[OK] No errors` |
| `composer test` | **8 passed** (25 assertions) |
| `composer test:mysql` locally | **1 skipped** — `"Needs MySQL 8. Runs in CI only — local development is SQLite (ADR-0015)."` |
| workflow YAML parses | 5 jobs, services `mysql` + `redis`, `PHP_VERSION` declared once |

### CI evidence — run [33169705588](https://github.com/mikmikfil/kaiki/actions/runs/33169705588), commit `927f13e`

| Job | Result |
|---|---|
| Pint | success |
| PHPStan level 6 | success |
| Pest on SQLite | success |
| Pest on MySQL 8 + Redis | success |
| CI passed (aggregate gate) | success |

The guard's own output, which is the assertion that matters:

```
✓ it runs the mysql group against a real MySQL 8 connection            0.09s
  Tests:    1 passed (2 assertions)
mysql group executed for real: Tests:    1 passed (2 assertions)
```

`1 passed`, not `1 skipped` — the job environment does reach PHPUnit, MySQL 8 was genuinely connected, and the driver assertion held. That is the evidence M2's overselling test depends on.

**Known warning, not failing:** GitHub reports `actions/checkout@v4` and the `actions/cache` pulled in transitively by `ramsey/composer-install@v3` still target Node.js 20, which is deprecated and force-run on Node 24. Cosmetic today; worth clearing when those actions publish updates, so a real warning is not lost in the noise.

---

## #2 — Quality toolchain: Pint, PHPStan 6 + Larastan, Pest groups, Composer scripts

**Commit:** `85830f7` · **Files:** `pint.json`, `phpstan.neon`, `composer.json`, `package.json`, `tests/Unit/ToolchainTest.php`, `.claude/settings.json`

Pint on the Laravel preset plus `declare_strict_types`, `strict_comparison`, `strict_param` and sorted imports. PHPStan level 6 with Larastan at `phpVersion: 80400` over `app`, `config`, `database`, `routes`, `tests` — **no baseline**, and `packages/wordpress-plugin` excluded because the plugin targets PHP 8.1 for operator hosting (ARC-9). Applying Pint reformatted the framework skeleton, which is most of that commit's diff.

All ten ENV-15 Composer scripts and all seven ENV-16 npm script names (the latter as announced stubs until M2–M4 fill them in).

### The bug the guard test caught on its first run

**Pest ignores a comma-separated `--exclude-group`.** Written as `--exclude-group=mysql,chromium,external`, it excluded **nothing** — the MySQL-only test ran locally and passed. That is exactly the false green ENV-11 exists to prevent, and it would have quietly disabled every CI-only exclusion in the project. The flag must be repeated per group.

This is the reason `tests/Unit/ToolchainTest.php` exists at all: tests that verify the tooling, not the application. Without it the mistake would have been invisible until someone wondered why a MySQL test passed on a machine with no MySQL.

### Verification

| Check | Result |
|---|---|
| `composer lint:test` | passed |
| `composer stan` | `[OK] No errors` |
| `composer test` | 8 of 9 — `mysql`/`chromium`/`external` correctly excluded |
| `composer test:fast` | 3 passed, **0.84s** (budget: under 30s) |
| `composer test:mysql` | the 1 mysql-tagged test, run explicitly |
| `composer ci` | lint:test → analyse → test, in order |

PHPStan findings were fixed at the source, never suppressed: `$this->get()` inside a Pest closure is untypeable, so the smoke test imports the `Pest\Laravel\get` function helper. No baseline, no `@phpstan-ignore`.

### Hooks (§16)

Deferred here from #1, because a `PostToolUse` hook calling `vendor/bin/pint` cannot exist before `vendor/` does. Both live in `.claude/settings.json` — committed, so the whole team gets them:

- `PostToolUse` on Edit/Write → Pint on the touched file, only when it is `.php` and `vendor/bin/pint` exists
- `Stop` → `composer test:fast`, printing one line, e.g. `Tests: 3 passed (5 assertions)`

There is no `jq` on this machine, so both parse the hook payload with PHP, which this project guarantees. Both were pipe-tested against synthesised payloads, then **re-extracted from the stored JSON and re-run verbatim** to prove the escaping survived serialisation — a hook that works in the shell but breaks in JSON is the standard way this goes wrong.

---

## #1 — Laravel 12 skeleton, SQLite local stack, `composer setup`

**Commit:** `4f4c822` · Scaffolded from an empty repository.

Laravel 12.68 on PHP 8.4. Local runtime fixed to SQLite at `database/database.sqlite`, cache/queue/session on the `database` driver, mail on `log`, `APP_TIMEZONE=UTC`. The `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` layout with an `Actions` directory in each, plus `app/Enums`.

### Verification — all eight acceptance criteria

| Criterion | Result |
|---|---|
| `composer install` clean, Laravel 12 | `Laravel Framework 12.68.0` |
| `composer setup` from clean checkout | `.env` → key → SQLite file → migrate → seed → assets, one command |
| `.env.example` contract | `DB_CONNECTION=sqlite`, `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER=database`, `MAIL_MAILER=log`, `APP_TIMEZONE=UTC`, plus `KAIKI_WP_TEST_URL`, `KAIKI_CHROME_PATH`, Viva/Stripe sandbox placeholders |
| `.env` and SQLite file gitignored | `git status` clean of both |
| timezone | `php artisan tinker --execute="echo config('app.timezone');"` → `UTC` |
| bounded-context directories | all nine present, plus `app/Enums` |
| no `Makefile`, no root `docker-compose.yml` | asserted **by a test**, not by inspection |
| `GET /` | 200 |

### Deviations from the issue as written

1. **Pest pulled forward from #2.** The issue's test plan called for `tests/Feature/SmokeTest.php` while listing Pest as out of scope — which meant writing it in PHPUnit and rewriting it a day later. Agreed with the product owner before starting.
2. **`laravel/sail` removed** from the skeleton's dev dependencies. It is a local Docker tool and local development here is native (ENV-3, ENV-4).

### Environment change outside the repository

Nine PHP extensions were commented out in the Windows `php.ini` and are now enabled: `fileinfo`, `pdo_sqlite`, `sqlite3`, `zip`, `gd`, `intl`, `exif`, `sodium`, `pdo_mysql`. **Without `pdo_sqlite` nothing in the local stack runs at all.** Backup at `php.ini.kaiki-backup`; documented in `README.md` so the next machine does not hit the same wall.

---

## Project foundation (pre-#1)

`CLAUDE.md`, eleven subagents in `.claude/agents/`, four slash commands in `.claude/commands/`.

`docs/spec.md` (~340 numbered requirements), `docs/data-model.md` (56 tables, four state machines, migration ordering), `docs/api.md` (17 endpoints, 60 schema components, machine-verified OpenAPI 3.1), **23 ADRs, all accepted 2026-08-28**. Thirty-eight issues across M0, M1, M6 and M8.

### Three contradictions caught between the accepted ADRs and the documents

Found during the post-acceptance pass, before any of them reached code:

1. **`docs/data-model.md` modelled the *rejected* VAT option** — `products.vat_rate_bp` defaulting to `1300`. A hardcoded VAT rate in the schema, which brief §10 and `CLAUDE.md` both forbid outright. Corrected to the `vat_rates` table with `vat_rate_id` foreign keys and per-line snapshots. Issue #18 was hard-blocked on exactly this, and SQLite cannot add the column later.
2. **`seats_held` was defined two incompatible ways.** The data model had it as a subset of `seats_sold`; the spec had them disjoint. The subset reading lets an unpaid draft flip a departure to `guaranteed` — emailing guests "your trip is confirmed" — and hides it from the at-risk dashboard. The spec was right; fourteen places in the data model were corrected.
3. **`MYD-4` required strictly gapless invoice numbering**, which ADR-0022 Option A explicitly permits gaps in. Superseded, with the change called out inline rather than edited in silently.
