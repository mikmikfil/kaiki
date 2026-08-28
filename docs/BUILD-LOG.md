# Kaiki — build log

What has actually been built, and the evidence it works. One entry per issue, newest first.

Each entry records the **verification actually run** and its **real output** — not what was supposed to happen. Where something can only be checked in CI, it says so rather than claiming a local pass. Where an issue was delivered differently from how it was written, the deviation is stated with its reason.

`CHANGELOG.md` is the narrative for a reader; this file is the audit trail.

---

## Status

| | |
|---|---|
| Milestone | **M0 — Foundation** |
| Issues closed | #1, #2, #3, #5 of 12 |
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
