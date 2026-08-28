# Changelog

## M0 — Foundation

### #5 - Tenancy foundation: single database, tenants/users/role_assignments, BelongsToTenant

Installed `stancl/tenancy` in single-database mode (ADR-0001 Option A) and landed the M0 tables from `docs/data-model.md` §6.

`App\Models\Tenant` implements stancl's `Tenant` contract directly rather than extending its model. That model is built around a string key and a `data` JSON blob, whereas every column here is one we filter, sort or index on - `slug` on every hosted-page request, `custom_domain` on every TLS handshake, `status` on the read-only middleware. Implementing the contract costs three small methods and keeps the schema honest. The Cashier columns ship now, unused until M7, because SQLite cannot add them to an existing table.

`BelongsToTenant` and `TenantScope` are ours rather than the package's, because the behaviour spec TEN-4 requires is not the package default: with **no tenant resolved the scope throws** instead of returning every operator's rows. Silently answering a question with the wrong tenant's data is the worst failure this product has. Deliberate cross-tenant access goes through `Tenancy::withoutTenancy()`, which is verbose and greppable on purpose.

`config/tenancy.php` was replaced rather than tuned. The published file is 200 lines of database-per-tenant machinery - connection managers, per-tenant filesystem roots, Redis prefixing, tenant migration paths - none of which applies here, and leaving it would tell the next reader that per-tenant databases exist somewhere. What replaces it is the single-database config plus the `platform_owned_models` allow-list TEN-5 requires, with `bootstrappers` deliberately empty and a note saying why an entry appearing there would be a silent move to per-tenant infrastructure.

The allow-list is enforced, not documented: a test walks `app/Models` and fails naming any model that neither uses the trait nor appears in the list. Verified by adding an unscoped model and watching it fail. This is the cheap structural half of what #8 completes.

`users` deliberately does **not** use the trait. `tenant_id` is nullable there and null means platform super-admin (ADR-0020 Option C), so a global scope would make super-admins invisible to their own panel. It is named in the allow-list with that reason.

Two demo operators seed deterministically: a Greek-first fleet operator on an active plan and an English-first single-vessel operator still trialing, so anything that only works for the first breaks visibly on the second. Isolation cannot be demonstrated with one tenant. `DatabaseSeeder` does not use `WithoutModelEvents` - model events are how `uuid` and `tenant_id` are assigned, and muting them would seed rows with a null public identifier.

One parity bug was caught before CI could: the scope test asserted `"role_assignments"."tenant_id"`, which is SQLite quoting. MySQL emits backticks, so it would have passed locally and failed in the MySQL job. It now strips quote characters and asserts the clause rather than the dialect.

The eleven PHPStan findings were fixed with real property and generic annotations - no baseline, no ignores. One was substantive: `Tenant::allowsWrites()` called an enum method on what PHPStan saw as a string, because the cast is invisible to it without the `@property` block.

### #3 - CI workflow: Pint, PHPStan, Pest on SQLite plus MySQL 8 and Redis

Five jobs on every pull request and every push to `main`: `lint`, `static-analysis`, `test-sqlite`, `test-mysql`, and a `ci-passed` aggregate so branch protection needs one required check and cannot silently miss a job added later. PHP 8.4 declared once in workflow-level `env` (ADR-0014 Option B). Composer installs cached on `composer.lock`.

`test-mysql` runs MySQL 8 and Redis 7 service containers, migrates from scratch, and runs the suite with cache, queue and session all on Redis so those code paths are exercised somewhere (ENV-1). Required on every PR, not nightly - ADR-0006 leans on it as the only place real parallelism is tested.

Two problems were found and fixed while building it. `SmokeTest` asserted `config('database.default') === 'sqlite'`, which would have failed the MySQL job for entirely the wrong reason; it now asserts the `.env.example` contract instead, which is what ENV-17 actually requires and holds on both engines. And the `mysql` group could have reported green without ever touching MySQL - PHPUnit *should* let the job environment win over `phpunit.xml`, but "should" is not evidence, so the group's test now asserts the driver really is `mysql` and CI fails on three separate shapes: no summary, a summary containing `skipped`, or a summary without `passed`. This matters well beyond this issue, because the overselling concurrency test joins that group in M2 and can only ever run there.

Also adds `docs/BUILD-LOG.md` - what has been built and the evidence it works, with the real command output rather than the intended output.

### #2 — Quality toolchain: Pint, PHPStan 6 with Larastan, Pest groups and the Composer scripts

Wired the full quality gate. `pint.json` uses the Laravel preset plus `declare_strict_types`, `strict_comparison`, `strict_param` and sorted imports; `phpstan.neon` runs level 6 with Larastan at `phpVersion: 80400` over `app`, `config`, `database`, `routes` and `tests`, with **no baseline** and `packages/wordpress-plugin` excluded because the plugin targets PHP 8.1 for operator hosting (ARC-9). Applying Pint reformatted the framework skeleton, which is the bulk of this diff.

The ENV-15 Composer scripts are in place: `setup`, `dev`, `test`, `test:fast`, `test:mysql`, `lint`, `lint:test`, `analyse`, `stan` and `ci`, each with a `scripts-descriptions` entry. `package.json` carries the seven ENV-16 npm script names as announced stubs so #3 and #4 have something to call before M3 and M4 fill them in.

`tests/Unit/ToolchainTest.php` guards the tooling itself: that the `fast` group runs, that a `mysql`-tagged test is excluded from `composer test`, that `Carbon::setTestNow` freezes time (TST-9), and that the suite runs in UTC.

**A real bug the guard test caught immediately:** Pest ignores a comma-separated `--exclude-group`. `--exclude-group=mysql,chromium,external` silently excluded nothing, so the MySQL-only test ran and passed locally — exactly the false green that ENV-11 exists to prevent. The flag is now repeated per group, and `composer test` correctly runs 8 of 9 tests while `composer test:mysql` runs the 1.

Two PHPStan findings were fixed at the source rather than suppressed: `$this->get()` inside a Pest closure is untypeable, so the smoke test now uses the `Pest\Laravel\get` function helper.

The §16 hooks deferred from #1 now live in `.claude/settings.json`: `PostToolUse` on Edit/Write runs Pint on the touched file when it is PHP and `vendor/bin/pint` exists, and `Stop` runs `composer test:fast` and prints a one-line result. There is no `jq` on this machine, so both parse the hook payload with PHP, which the project guarantees. Both were pipe-tested against synthesised payloads and re-verified verbatim from the stored JSON.

### #1 — Laravel 12 skeleton, SQLite local stack, `composer setup`

Scaffolded the application from an empty repository: Laravel 12.68 on PHP 8.4, with the local runtime contract fixed to SQLite at `database/database.sqlite` and cache, queue and session on the `database` driver, mail on `log`, and `APP_TIMEZONE=UTC` (storage is always UTC; display timezone is per tenant). `composer setup` takes a clean checkout to a migrated, seeded, asset-built application in one command with no manual step, and there is deliberately no `Makefile` and no root `docker-compose.yml`.

Added the `app/Domain/{Catalog,Availability,Pricing,Booking,Payments,Compliance,Notifications,Branding,Import}` layout with an `Actions` directory in each, plus `app/Enums`. `tests/Feature/SmokeTest.php` asserts the app boots, `GET /` is 200, the timezone is UTC, the database is SQLite, every bounded context exists, and no local Docker or make entry point has crept in.

Two deviations from the issue as written, both deliberate: **Pest was pulled forward from #2** so the smoke test is not written in PHPUnit and rewritten a day later — #2 keeps Pint, PHPStan and Larastan. **`laravel/sail` was removed** from the skeleton's dev dependencies, since it is a local Docker tool and local development here is native (ENV-3, ENV-4).

Also needed, outside the repository: nine PHP extensions were commented out in the Windows `php.ini` — `fileinfo`, `pdo_sqlite`, `sqlite3`, `zip`, `gd`, `intl`, `exif`, `sodium`, `pdo_mysql`. Without `pdo_sqlite` the local stack cannot run at all. Documented in `README.md`.

### Project foundation

`CLAUDE.md`, eleven subagent definitions in `.claude/agents/`, and four slash commands in `.claude/commands/`. The §16 hooks are deferred to #2, where the toolchain they invoke actually exists.

`docs/spec.md` (~340 numbered requirements), `docs/data-model.md` (56 tables, four state machines), `docs/api.md` (17 endpoints, 60 schema components), and 23 accepted ADRs in `docs/adr/`. Thirty-seven issues for M0 and M1.
