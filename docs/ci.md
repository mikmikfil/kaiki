# CI — what runs, what blocks, and how to fix it

Implements ENV-10, ENV-22, ENV-23, ENV-25, TST-1, SEC-12.

`docs/spec.md` is the contract; this file is operational detail about the pipeline that enforces it. Where the two disagree, the spec wins.

---

## Branch protection

Make **one** required status check on `main`:

```
CI passed
```

That is the `ci-passed` job in `.github/workflows/ci.yml`. It succeeds only when every other job in that workflow succeeded, so adding a job later makes it required automatically — nobody has to remember to tick a new box, and nobody can forget to. `tests/Unit/CiGatesTest.php` fails the build if a job is ever defined without being aggregated, and if this document and the workflow stop agreeing.

Also enable **Require branches to be up to date before merging**. Without it, two branches that are each green can merge into a red `main` — which is precisely how a migration lands beside a snapshot that never saw it.

### The jobs it aggregates (ENV-23)

<!-- required-checks:start -->

| Job | What a red build means |
|---|---|
| `lint` | Pint would reformat something. `composer lint` fixes it. |
| `i18n` | EL and EN lang files disagree on a key, or a user-facing string is hardcoded (I18N-2, I18N-3). `composer i18n:check` reproduces it. |
| `api-docs-drift` | A route is missing from `docs/api.md` §5, or its security schemes disagree with the contract (ENV-28). `composer api:docs && composer test:api-docs` reproduces it. |
| `static-analysis` | PHPStan level 6 with Larastan found something (TST-10). |
| `test-sqlite` | Pest against the SQLite stack developers actually run on. |
| `test-mysql` | Pest against MySQL 8 and Redis, plus the `mysql` group — which must execute for real, not skip (ENV-11, TST-8). |
| `availability-timezone` | The availability group under a **third** machine timezone (ENV-14). UTC would hide an unconverted value and Europe/Athens would hide an unconverted tenant, so the runner is set to `America/Los_Angeles` — neither, and with its own transitions on different dates. |
| `tenancy-isolation` | A cross-tenant leak. The condition ADR-0001 was accepted on. |
| `coverage` | `app/Domain` coverage fell below 80% (TST-1). |
| `security-audit` | A high or critical advisory in Composer or npm dependencies (SEC-12). |
| `migrate-from-zero` | The migrations no longer produce the committed schema (ENV-10). |
| `widget-build` | The widget build or its 80 KB gzipped budget (WGT-2, NFR-3). |
| `widget-e2e` | The widget Playwright smoke run (ENV-23, TST-3). |
| `plugin-lint` | The WordPress plugin coding standard (WPP-11). |
| `pdf-chromium` | The `chromium` group — the e-ticket PDF actually rendered by Browsershot (ENV-20, BKG-13.1). Excluded from `composer test`, because a developer with no browser must not get a false green, which makes this the **only** place the PDFs are ever rendered. Added by #88. |

<!-- required-checks:end -->

`pdf-chromium` installs Puppeteer without its bundled Chromium and points it at the runner's own
Google Chrome — the browser is already on the image, and a second 150 MB download every run buys
nothing.

`widget-build`, `widget-e2e` and `plugin-lint` run stubs until M3 and M4. They exist now so those milestones replace a `package.json` script rather than invent a pipeline.

The **branch-protection** list never has to change again — it is one entry, `CI passed`. The job list behind it will still grow, and two are known to be owed: the ENV-28 Scramble docs-drift check (issue #11) and the M3 replacement of the Playwright stub with a real run. Each becomes required the moment it joins `ci-passed`'s `needs:`, with nothing to configure. The third, ENV-14's alternate-timezone run, arrived with #32.

---

## The coverage gate (TST-1, ENV-22)

- Scoped to **`app/Domain` only**, via `phpunit.coverage.xml`. A whole-application threshold rewards writing tests for Filament resources instead of for the engine, which is the one part of this system that must not be wrong.
- The threshold lives in **one place**: the `--min=80` in `composer test:coverage`. Raising it is a one-line change, and the workflow never repeats the number.
- Needs PCOV, which the local Windows stack does not have. **Coverage is a CI-only figure** — `composer test` deliberately runs without it.

---

## The schema snapshot (ENV-10)

`migrate-from-zero` migrates an **empty** MySQL 8 database and compares the resulting schema against `database/schema/mysql-schema.snapshot.sql`. It is the only rehearsal of the path a brand-new production database takes exactly once.

### Refreshing it after a migration change

```
composer schema:snapshot
```

> **Point it at a throwaway database only.** Laravel's dumper passes the password on the `mysqldump` command line, so it is visible in the process list for the life of the dump. That is fine for the ephemeral root/root container in CI; it is not fine for staging.

One command — but it needs a MySQL 8 connection, and the local stack is SQLite (ADR-0015), so in practice:

1. Push the branch. `migrate-from-zero` goes red and says the snapshot is stale.
2. Download the **`mysql-schema-snapshot`** artifact from that run — it is uploaded on every run, red or green, and it is the output of exactly that command.
3. Commit the file. The job goes green.

`tests/Unit/CiGatesTest.php` also checks a fingerprint of the migrations recorded in the snapshot's header, so "changed a migration, forgot to refresh" fails locally in seconds instead of after a MySQL round-trip.

The MySQL service image for this job is pinned to a **patch** version, unlike the floating `mysql:8.0` in `test-mysql`. The comparison is byte-for-byte, and the server's rendering of display widths and default collations moves between patches. **Bumping that image means regenerating the snapshot** in the same commit.

### Why the file is not called `mysql-schema.sql`

Because Laravel would load it. `MigrateCommand::prepareDatabase()` runs a dump found at `database/schema/{connection}-schema.dump` or `{connection}-schema.sql` **instead of** the migrations whenever no migration has run yet. Committing the snapshot there would mean `migrate-from-zero` compared the snapshot with itself, and `test-mysql`'s `migrate:fresh` stopped exercising the migrations — and neither would have gone red to say so. Laravel checks `database/schema/{connection}-schema.dump` **first** and `{connection}-schema.sql` second, and the connection name is part of the filename — so a local `php artisan schema:dump` on the SQLite stack writes `sqlite-schema.sql` and silently neuters that developer's own `migrate`. `.gitignore` therefore ignores both extensions for every connection, the job asserts the migrate output never says `Loading stored database schemas`, and a test asserts no such file exists.

---

## The dependency audit (SEC-12)

`composer audit` and `npm audit` both run. **High, critical and unclassified fail the build**; medium, moderate, low and info are printed as a notice with the package and title, for a human to open an issue about.

Both tools exit non-zero on any finding, so the exit code is discarded and the severity read from the JSON. Everything about that reading **fails closed**: Composer's severity is nullable, so an advisory with no severity blocks rather than sorting into the harmless pile; output that is not a JSON object, or that lacks the key the counts read, fails the job rather than counting zero; and an advisory suppressed through `config.advisories.ignore` fails too, because muting one is a decision that needs a human rather than a quietly green build.

The audit and schema jobs live in `.github/workflows/dependency-audit.yml` and `.github/workflows/schema-drift.yml` and are called by both `ci.yml` and `nightly.yml`. Extracted rather than copied: two divergent copies of a severity threshold is how one of them quietly stops blocking anything.

---

## Nightly (ENV-25)

`.github/workflows/nightly.yml`, 02:00 UTC, plus manual dispatch. Runs the dependency audit and the schema-drift check — the checks that can fail without anyone pushing anything.

Two of the four things ENV-25 lists do not exist yet and are recorded as comments in that file rather than omitted:

- the **full Playwright suite** including the WordPress site run and the axe-core pass (TST-3, A11Y-1) — arrives with the widget in **M3**;
- the **NFR-1 availability benchmark**, 150 ms at p95 — first runs at the **close of M2**, pulled forward from M8 by AVL-12b, because missing it is the documented trigger to reopen ADR-0023 and that is only affordable while the shape is still cheap to change.

---

## Running the gates locally

| Gate | Local | Notes |
|---|---|---|
| Pint, PHPStan, Pest | `composer ci` | The SQLite stack. |
| i18n parity and lint | `composer i18n:check` | Runs fully locally; no database needed. |
| OpenAPI contract drift | `composer api:docs && composer test:api-docs` | Runs fully locally. `build/` is gitignored. |
| Widget and plugin stubs | `npm run widget:build`, `npm run widget:size`, `npm run e2e`, `npm run plugin:lint` | Stubs until M3/M4. |
| Coverage | — | CI only; no PCOV locally (ENV-22). |
| MySQL suite, schema snapshot, audits | — | CI only; no local MySQL (ADR-0015). |
