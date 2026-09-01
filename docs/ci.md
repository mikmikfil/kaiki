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
| `static-analysis` | PHPStan level 6 with Larastan found something (TST-10). |
| `test-sqlite` | Pest against the SQLite stack developers actually run on. |
| `test-mysql` | Pest against MySQL 8 and Redis, plus the `mysql` group — which must execute for real, not skip (ENV-11, TST-8). |
| `tenancy-isolation` | A cross-tenant leak. The condition ADR-0001 was accepted on. |
| `coverage` | `app/Domain` coverage fell below 80% (TST-1). |
| `security-audit` | A high or critical advisory in Composer or npm dependencies (SEC-12). |
| `migrate-from-zero` | The migrations no longer produce the committed schema (ENV-10). |
| `widget-build` | The widget build or its 80 KB gzipped budget (WGT-2, NFR-3). |
| `plugin-lint` | The WordPress plugin coding standard (WPP-11). |

<!-- required-checks:end -->

`widget-build` and `plugin-lint` run stubs until M3 and M4. They exist now so those milestones replace a `package.json` script rather than invent a pipeline, and so the required-check list on `main` never has to change again.

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

One command — but it needs a MySQL 8 connection, and the local stack is SQLite (ADR-0015), so in practice:

1. Push the branch. `migrate-from-zero` goes red and says the snapshot is stale.
2. Download the **`mysql-schema-snapshot`** artifact from that run — it is uploaded on every run, red or green, and it is the output of exactly that command.
3. Commit the file. The job goes green.

`tests/Unit/CiGatesTest.php` also checks a fingerprint of the migrations recorded in the snapshot's header, so "changed a migration, forgot to refresh" fails locally in seconds instead of after a MySQL round-trip.

### Why the file is not called `mysql-schema.sql`

Because Laravel would load it. `MigrateCommand::prepareDatabase()` runs a dump found at `database/schema/{connection}-schema.sql` **instead of** the migrations whenever no migration has run yet. Committing the snapshot there would mean `migrate-from-zero` compared the snapshot with itself, and `test-mysql`'s `migrate:fresh` stopped exercising the migrations — and neither would have gone red to say so. `.gitignore` therefore ignores that path, the job asserts the migrate output never says `Loading stored database schemas`, and a test asserts no such file exists.

---

## The dependency audit (SEC-12)

`composer audit` and `npm audit` both run. **High and critical fail the build**; medium, moderate and low are printed as a notice with the package and title, for a human to open an issue about. Both tools exit non-zero on any finding, so the exit code is discarded and the severity is read from the JSON — and a run that produced no parseable JSON fails rather than being read as "no advisories".

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
| Widget and plugin stubs | `npm run widget:build`, `npm run widget:size`, `npm run plugin:lint` | Stubs until M3/M4. |
| Coverage | — | CI only; no PCOV locally (ENV-22). |
| MySQL suite, schema snapshot, audits | — | CI only; no local MySQL (ADR-0015). |
